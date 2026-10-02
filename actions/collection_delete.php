<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('collections.loan_records_delete', 'pages/loans.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loans.php');

function collection_delete_snapshot_row(array $snapshot): array
{
    return isset($snapshot['row']) && is_array($snapshot['row'])
        ? $snapshot['row']
        : (isset($snapshot['data']) && is_array($snapshot['data']) ? $snapshot['data'] : $snapshot);
}

function collection_delete_delete_generated_installments(PDO $pdo, int $loanId, array $groups): void
{
    $collectionLinkStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE installment_id = :installment_id');
    $deleteInstallmentStmt = $pdo->prepare('DELETE FROM loan_installments WHERE id = :id AND loan_id = :loan_id');
    $seen = [];

    foreach ($groups as $group) {
        $meta = is_array($group['meta'] ?? null) ? $group['meta'] : [];
        $snapshots = is_array($meta['installment_snapshots'] ?? null) ? $meta['installment_snapshots'] : [];
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot) || (bool) ($snapshot['exists_before'] ?? true)) {
                continue;
            }

            $row = collection_delete_snapshot_row($snapshot);
            $installmentId = (int) ($row['id'] ?? $snapshot['id'] ?? 0);
            if ($installmentId <= 0 || isset($seen[$installmentId])) {
                continue;
            }

            $collectionLinkStmt->execute(['installment_id' => $installmentId]);
            if ((int) $collectionLinkStmt->fetchColumn() > 0) {
                throw new RuntimeException('Cannot delete because a generated installment is still linked to a collection.');
            }

            $deleteInstallmentStmt->execute([
                'id' => $installmentId,
                'loan_id' => $loanId,
            ]);
            $seen[$installmentId] = true;
        }
    }
}

function collection_delete_restore_snapshots(PDO $pdo, int $loanId, array $snapshots): void
{
    if ($snapshots === []) {
        throw new RuntimeException('This collection cannot be deleted because its installment snapshots are missing.');
    }

    $collectionLinkStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE installment_id = :installment_id');
    $deleteInstallmentStmt = $pdo->prepare('DELETE FROM loan_installments WHERE id = :id AND loan_id = :loan_id');
    $existsInstallmentStmt = $pdo->prepare('SELECT COUNT(*) FROM loan_installments WHERE id = :id');
    $updateInstallmentStmt = $pdo->prepare(
        'UPDATE loan_installments
         SET loan_id = :loan_id,
             installment_no = :installment_no,
             due_date = :due_date,
             due_amount = :due_amount,
             paid_amount = :paid_amount,
             paid_on = :paid_on,
             status = :status,
             is_flexible_adjustment = :is_flexible_adjustment,
             source_payment_ref = :source_payment_ref
         WHERE id = :id'
    );
    $insertInstallmentStmt = $pdo->prepare(
        'INSERT INTO loan_installments
            (id, loan_id, installment_no, due_date, due_amount, paid_amount, paid_on, status, is_flexible_adjustment, source_payment_ref)
         VALUES
            (:id, :loan_id, :installment_no, :due_date, :due_amount, :paid_amount, :paid_on, :status, :is_flexible_adjustment, :source_payment_ref)'
    );

    foreach ($snapshots as $snapshot) {
        if (!is_array($snapshot)) {
            continue;
        }

        $existsBefore = (bool) ($snapshot['exists_before'] ?? true);
        $row = collection_delete_snapshot_row($snapshot);
        $installmentId = (int) ($row['id'] ?? $snapshot['id'] ?? 0);
        if ($installmentId <= 0) {
            continue;
        }

        if (!$existsBefore) {
            $collectionLinkStmt->execute(['installment_id' => $installmentId]);
            if ((int) $collectionLinkStmt->fetchColumn() > 0) {
                throw new RuntimeException('Cannot delete because a generated installment has collection links.');
            }
            $deleteInstallmentStmt->execute([
                'id' => $installmentId,
                'loan_id' => $loanId,
            ]);
            continue;
        }

        if ((int) ($row['loan_id'] ?? 0) !== $loanId) {
            throw new RuntimeException('Cannot delete because an installment snapshot belongs to a different loan.');
        }

        $status = (string) ($row['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'partial', 'paid', 'overdue'], true)) {
            $status = 'pending';
        }

        $params = [
            'id' => $installmentId,
            'loan_id' => $loanId,
            'installment_no' => (int) ($row['installment_no'] ?? 0),
            'due_date' => (string) ($row['due_date'] ?? today()),
            'due_amount' => round((float) ($row['due_amount'] ?? 0), 2),
            'paid_amount' => round((float) ($row['paid_amount'] ?? 0), 2),
            'paid_on' => isset($row['paid_on']) && (string) $row['paid_on'] !== '' ? (string) $row['paid_on'] : null,
            'status' => $status,
            'is_flexible_adjustment' => (int) ($row['is_flexible_adjustment'] ?? 0),
            'source_payment_ref' => isset($row['source_payment_ref']) && (string) $row['source_payment_ref'] !== '' ? (string) $row['source_payment_ref'] : null,
        ];

        if ($params['installment_no'] <= 0 || $params['due_amount'] < 0 || $params['paid_amount'] < 0) {
            throw new RuntimeException('Cannot delete because an installment snapshot is invalid.');
        }

        $existsInstallmentStmt->execute(['id' => $installmentId]);
        if ((int) $existsInstallmentStmt->fetchColumn() > 0) {
            $updateInstallmentStmt->execute($params);
        } else {
            $insertInstallmentStmt->execute($params);
        }
    }
}

function collection_delete_next_installment_id(PDO $pdo, int $loanId): int
{
    $stmt = $pdo->prepare(
        "SELECT id
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial', 'overdue')
           AND due_amount > paid_amount
         ORDER BY due_date ASC, installment_no ASC
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute(['loan_id' => $loanId]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

$loanId = (int) ($_POST['loan_id'] ?? 0);
$collectionId = (int) ($_POST['collection_id'] ?? 0);
$returnTo = $loanId > 0
    ? 'pages/loan_edit.php?loan_id=' . $loanId . '#collections'
    : 'pages/loans.php';

if ($loanId <= 0 || $collectionId <= 0) {
    set_flash('error', 'Collection is required.');
    redirect($returnTo);
}

$current = current_user();
$currentUserId = (int) ($current['id'] ?? 0);
$currentRole = (string) ($current['role'] ?? '');
$currentUserName = (string) ($current['full_name'] ?? 'Unknown');
$allowOverpayment = system_setting($pdo, 'allow_overpayment', '1') !== '0';

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare(
        "SELECT l.*, c.full_name AS customer_name
         FROM loans l
         JOIN customers c ON c.id = l.customer_id
         WHERE l.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();
    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $assignedUserId = (int) ($loan['assigned_user_id'] ?? 0);
    if (is_collector_role($currentRole) && $assignedUserId > 0 && $assignedUserId !== $currentUserId) {
        throw new RuntimeException('You can only delete collections for loans assigned to you.');
    }

    $allRowsStmt = $pdo->prepare(
        'SELECT *
         FROM collections
         WHERE loan_id = :loan_id
         ORDER BY id ASC
         FOR UPDATE'
    );
    $allRowsStmt->execute(['loan_id' => $loanId]);
    $allRows = $allRowsStmt->fetchAll();

    $groupsByKey = [];
    $selectedKey = '';
    foreach ($allRows as $row) {
        $rowId = (int) $row['id'];
        $paymentRef = trim((string) ($row['payment_ref'] ?? ''));
        $key = $paymentRef !== '' ? $paymentRef : 'legacy-' . $rowId;

        if (!isset($groupsByKey[$key])) {
            $groupsByKey[$key] = [
                'key' => $key,
                'payment_ref' => $paymentRef,
                'first_id' => $rowId,
                'latest_id' => $rowId,
                'amount' => 0.0,
                'collected_on' => (string) $row['collected_on'],
                'method' => (string) ($row['method'] ?? 'cash'),
                'note' => (string) ($row['note'] ?? ''),
                'collector_id' => isset($row['collected_by_user_id']) ? (int) $row['collected_by_user_id'] : 0,
                'meta' => null,
            ];
        }

        $groupsByKey[$key]['latest_id'] = max((int) $groupsByKey[$key]['latest_id'], $rowId);
        $groupsByKey[$key]['amount'] = round((float) $groupsByKey[$key]['amount'] + (float) $row['amount'], 2);
        $groupsByKey[$key]['collected_on'] = (string) $row['collected_on'];
        $groupsByKey[$key]['method'] = (string) ($row['method'] ?? 'cash');
        $groupsByKey[$key]['note'] = (string) ($row['note'] ?? '');
        if ((int) ($row['collected_by_user_id'] ?? 0) > 0) {
            $groupsByKey[$key]['collector_id'] = (int) $row['collected_by_user_id'];
        }
        if ($groupsByKey[$key]['meta'] === null && trim((string) ($row['meta_json'] ?? '')) !== '') {
            $decodedMeta = json_decode((string) $row['meta_json'], true);
            $groupsByKey[$key]['meta'] = is_array($decodedMeta) ? $decodedMeta : null;
        }

        if ($rowId === $collectionId) {
            $selectedKey = $key;
        }
    }

    if ($selectedKey === '' || !isset($groupsByKey[$selectedKey])) {
        throw new RuntimeException('Collection not found.');
    }

    $selectedGroup = $groupsByKey[$selectedKey];
    if ((string) $selectedGroup['payment_ref'] === '') {
        throw new RuntimeException('Legacy collection rows cannot be deleted safely.');
    }

    $selectedMeta = is_array($selectedGroup['meta'] ?? null) ? $selectedGroup['meta'] : [];
    $selectedSnapshots = is_array($selectedMeta['installment_snapshots'] ?? null) ? $selectedMeta['installment_snapshots'] : [];
    if ($selectedSnapshots === []) {
        throw new RuntimeException('This collection cannot be deleted because its installment snapshots are missing.');
    }

    $groupsToReplay = array_values(array_filter(
        $groupsByKey,
        static fn (array $group): bool => (int) $group['first_id'] >= (int) $selectedGroup['first_id']
    ));
    usort($groupsToReplay, static fn (array $a, array $b): int => ((int) $a['first_id']) <=> ((int) $b['first_id']));

    foreach ($groupsToReplay as $group) {
        $groupMeta = is_array($group['meta'] ?? null) ? $group['meta'] : [];
        $groupSnapshots = is_array($groupMeta['installment_snapshots'] ?? null) ? $groupMeta['installment_snapshots'] : [];
        if ((string) ($group['payment_ref'] ?? '') === '' || $groupSnapshots === []) {
            throw new RuntimeException('This collection cannot be deleted because it or a later collection is missing safe replay data.');
        }
    }

    $deleteCollectionStmt = $pdo->prepare(
        'DELETE FROM collections
         WHERE loan_id = :loan_id
           AND payment_ref = :payment_ref'
    );
    foreach ($groupsToReplay as $group) {
        $paymentRef = trim((string) $group['payment_ref']);
        if ($paymentRef === '') {
            throw new RuntimeException('Cannot delete because a later collection has no payment reference.');
        }
        $deleteCollectionStmt->execute([
            'loan_id' => $loanId,
            'payment_ref' => $paymentRef,
        ]);
    }

    collection_delete_delete_generated_installments($pdo, $loanId, $groupsToReplay);
    collection_delete_restore_snapshots($pdo, $loanId, $selectedSnapshots);

    foreach ($groupsToReplay as $group) {
        if ((string) $group['key'] === $selectedKey) {
            continue;
        }

        $nextInstallmentId = collection_delete_next_installment_id($pdo, $loanId);
        if ($nextInstallmentId <= 0) {
            throw new RuntimeException('No pending installment is available while replaying collections.');
        }

        $groupMethod = (string) $group['method'];
        if (!payment_method_selection_enabled($pdo) || !in_array($groupMethod, ['cash', 'bank', 'online'], true)) {
            $groupMethod = 'cash';
        }

        $paymentRef = (string) $group['payment_ref'];
        $groupCollectedOn = (string) $group['collected_on'];
        $collectorId = (int) ($group['collector_id'] ?? 0);
        $result = record_loan_collection_payment(
            $pdo,
            $loan,
            $nextInstallmentId,
            round((float) $group['amount'], 2),
            $groupCollectedOn,
            $groupCollectedOn,
            $groupMethod,
            trim((string) ($group['note'] ?? '')) !== '' ? trim((string) $group['note']) : null,
            $collectorId > 0 ? $collectorId : $currentUserId,
            $paymentRef,
            $allowOverpayment
        );

        $meta = is_array($group['meta'] ?? null) ? $group['meta'] : [];
        $shouldSchedule = (int) ($meta['schedule_next_payment'] ?? 0) === 1;
        $scheduledTo = trim((string) ($meta['scheduled_to_due_date'] ?? ''));
        if ($shouldSchedule && $scheduledTo !== '' && (int) ($result['pending_count'] ?? 0) > 0) {
            $scheduleDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $scheduledTo);
            if ($scheduleDateObj && $scheduleDateObj->format('Y-m-d') === $scheduledTo && $scheduledTo > today()) {
                $scheduledInstallment = schedule_next_installment_date($pdo, $loanId, $scheduledTo);
                append_collection_payment_snapshots(
                    $pdo,
                    $loanId,
                    $paymentRef,
                    (array) ($scheduledInstallment['installment_snapshots'] ?? []),
                    [
                        'schedule_next_payment' => 1,
                        'scheduled_installment_id' => (int) ($scheduledInstallment['installment_id'] ?? 0),
                        'scheduled_from_due_date' => (string) ($scheduledInstallment['from_due_date'] ?? ''),
                        'scheduled_to_due_date' => (string) ($scheduledInstallment['to_due_date'] ?? ''),
                        'scheduled_shifted_count' => (int) ($scheduledInstallment['shifted_count'] ?? 0),
                    ]
                );
            }
        }
    }

    $pendingCountStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial', 'overdue')
           AND due_amount > paid_amount"
    );
    $pendingCountStmt->execute(['loan_id' => $loanId]);
    $pendingCount = (int) $pendingCountStmt->fetchColumn();
    if ($pendingCount === 0) {
        $pdo->prepare("UPDATE loans SET status = 'closed' WHERE id = :id")->execute(['id' => $loanId]);
    } else {
        $pdo->prepare("UPDATE loans SET status = 'active' WHERE id = :id")->execute(['id' => $loanId]);
    }

    $pdo->commit();

    log_activity($pdo, 'collection.deleted', $currentUserName . ' deleted collection for loan ' . (string) ($loan['loan_number'] ?? ('#' . $loanId)) . '.', [
        'loan_id' => $loanId,
        'collection_id' => $collectionId,
        'payment_ref' => (string) $selectedGroup['payment_ref'],
        'amount' => (float) $selectedGroup['amount'],
        'collected_on' => (string) $selectedGroup['collected_on'],
        'replayed_group_count' => max(count($groupsToReplay) - 1, 0),
    ]);

    set_flash('success', 'Collection deleted successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_activity($pdo, 'collection.delete_failed', 'Collection delete failed: ' . $e->getMessage(), [
        'loan_id' => $loanId,
        'collection_id' => $collectionId,
    ]);

    $userError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Failed to delete collection. Please try again.';
    set_flash('error', $userError);
}

redirect($returnTo);
