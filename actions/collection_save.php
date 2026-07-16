<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('collections.record', 'pages/today_collections.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/today_collections.php');
}
require_csrf('pages/today_collections.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
$preferredInstallmentId = isset($_POST['installment_id']) ? (int) $_POST['installment_id'] : 0;
$amount = round((float) ($_POST['amount'] ?? 0), 2);
$collectedOn = trim((string) ($_POST['collected_on'] ?? ''));
$method = trim((string) ($_POST['method'] ?? 'cash'));
$note = trim((string) ($_POST['note'] ?? ''));
$backdatedEntry = (int) ($_POST['backdated_entry'] ?? 0) === 1;
$paidOnDateInput = trim((string) ($_POST['paid_on_date'] ?? ''));
$scheduleNextPayment = (int) ($_POST['schedule_next_payment'] ?? 0) === 1;
$nextPaymentDateInput = trim((string) ($_POST['next_payment_date'] ?? ''));
$collectedByUserId = (int) (current_user()['id'] ?? 0);
try {
    $paymentRef = 'PAY-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
} catch (Throwable) {
    $paymentRef = 'PAY-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
}
$returnToRaw = trim((string) ($_POST['return_to'] ?? 'pages/today_collections.php'));

$returnTo = 'pages/today_collections.php';
$parsedReturn = parse_url($returnToRaw);
if (is_array($parsedReturn) && isset($parsedReturn['path']) && preg_match('/^(index\.php|pages\/[a-z_]+\.php)$/', $parsedReturn['path'])) {
    $returnTo = $parsedReturn['path'];

    $allowedQuery = [];
    if (isset($parsedReturn['query'])) {
        parse_str($parsedReturn['query'], $queryValues);
        if (!empty($queryValues['date'])) {
            $allowedQuery['date'] = (string) $queryValues['date'];
        }
        if (!empty($queryValues['date_mode']) && in_array($queryValues['date_mode'], ['today', 'tomorrow', 'day_after_tomorrow', 'custom'], true)) {
            $allowedQuery['date_mode'] = (string) $queryValues['date_mode'];
        }
        if (!empty($queryValues['q'])) {
            $allowedQuery['q'] = (string) $queryValues['q'];
        }
        if (!empty($queryValues['collection_status']) && in_array($queryValues['collection_status'], ['pending', 'collected'], true)) {
            $allowedQuery['collection_status'] = (string) $queryValues['collection_status'];
        }
        if (!empty($queryValues['selected_installment'])) {
            $allowedQuery['selected_installment'] = (int) $queryValues['selected_installment'];
        }
        if ($returnTo === 'pages/loan_edit.php' && !empty($queryValues['loan_id'])) {
            $loanIdQuery = (int) $queryValues['loan_id'];
            if ($loanIdQuery > 0) {
                $allowedQuery['loan_id'] = $loanIdQuery;
            }
        }
    }

    if (!empty($allowedQuery)) {
        $returnTo .= '?' . http_build_query($allowedQuery);
    }

    if ($returnTo === 'pages/loan_edit.php' || str_starts_with($returnTo, 'pages/loan_edit.php?')) {
        $fragment = (string) ($parsedReturn['fragment'] ?? '');
        if ($fragment === 'collections') {
            $returnTo .= '#collections';
        }
    }
}
$allowNextPendingCollection = str_starts_with($returnTo, 'pages/loan_edit.php?loan_id=');

if ($loanId <= 0 || $amount <= 0 || $collectedOn === '') {
    set_flash('error', 'Loan, amount and collection date are required.');
    redirect($returnTo);
}

if ($collectedOn > today()) {
    set_flash('error', 'Cannot save collection for a future date.');
    redirect($returnTo);
}

$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$canBackdatePaid = can('collections.backdate');
$canScheduleNextPayment = can('collections.schedule');
$paidOnDate = $collectedOn;
if ($backdatedEntry) {
    if (!$canBackdatePaid) {
        set_flash('error', 'You do not have permission to use backdated entry.');
        redirect($returnTo);
    }

    $paidDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $paidOnDateInput);
    if (!$paidDateObj || $paidDateObj->format('Y-m-d') !== $paidOnDateInput) {
        set_flash('error', 'Invalid paid date.');
        redirect($returnTo);
    }

    if ($paidOnDateInput > $collectedOn) {
        set_flash('error', 'Paid date cannot be later than recorded date.');
        redirect($returnTo);
    }

    if ($paidOnDateInput > today()) {
        set_flash('error', 'Paid date cannot be in the future.');
        redirect($returnTo);
    }

    $paidOnDate = $paidOnDateInput;
}

if ($scheduleNextPayment && !$canScheduleNextPayment) {
    set_flash('error', 'You do not have permission to schedule the next payment.');
    redirect($returnTo);
}

if (!in_array($method, ['cash', 'bank', 'online'], true)) {
    $method = 'cash';
}

if ($scheduleNextPayment) {
    $nextDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $nextPaymentDateInput);
    if (!$nextDateObj || $nextDateObj->format('Y-m-d') !== $nextPaymentDateInput) {
        set_flash('error', 'Invalid next payment date.');
        redirect($returnTo);
    }

    if ($nextPaymentDateInput <= today()) {
        set_flash('error', 'Next payment date must be after today.');
        redirect($returnTo);
    }
}

$allowOverpayment = system_setting($pdo, 'allow_overpayment', '1') !== '0';

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $currentUserId = (int) ($current['id'] ?? 0);
    $currentUserName = (string) ($current['full_name'] ?? 'Unknown');
    $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));

    $assignedUserId = isset($loan['assigned_user_id']) ? (int) $loan['assigned_user_id'] : 0;
    $assignedUser = null;
    if ($assignedUserId > 0) {
        $assignedUserStmt = $pdo->prepare('SELECT id, full_name, role FROM users WHERE id = :id LIMIT 1');
        $assignedUserStmt->execute(['id' => $assignedUserId]);
        $assignedUser = $assignedUserStmt->fetch() ?: null;
    }

    if (is_collector_role($currentRole) && $assignedUserId > 0 && $assignedUserId !== $currentUserId) {
        throw new RuntimeException('You can only collect payments for loans assigned to you.');
    }
    $oldestCollectibleSql = "SELECT *
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial', 'overdue')
           AND due_amount > paid_amount";
    $oldestCollectibleParams = ['loan_id' => $loanId];
    if (!$allowNextPendingCollection) {
        $oldestCollectibleSql .= ' AND due_date <= :today_date';
        $oldestCollectibleParams['today_date'] = today();
    }
    $oldestCollectibleSql .= '
         ORDER BY due_date ASC, installment_no ASC
         LIMIT 1
         FOR UPDATE';
    $oldestCollectibleStmt = $pdo->prepare($oldestCollectibleSql);
    $oldestCollectibleStmt->execute($oldestCollectibleParams);
    $oldestCollectible = $oldestCollectibleStmt->fetch();

    if (!$oldestCollectible) {
        throw new RuntimeException($allowNextPendingCollection
            ? 'No pending installments available for this loan.'
            : 'No due installments available for collection today.'
        );
    }

    $oldestCollectibleId = (int) $oldestCollectible['id'];
    if ($preferredInstallmentId > 0 && $preferredInstallmentId !== $oldestCollectibleId) {
        throw new RuntimeException('Only the oldest due installment can be collected.');
    }

    if (!$allowNextPendingCollection && (string) $oldestCollectible['due_date'] > today()) {
        throw new RuntimeException('Cannot collect a future installment from this panel.');
    }

    if ($backdatedEntry && (string) $oldestCollectible['due_date'] > $collectedOn) {
        throw new RuntimeException('Backdated entry is not allowed when collecting a future installment.');
    }

    $collectionResult = record_loan_collection_payment(
        $pdo,
        $loan,
        $oldestCollectibleId,
        $amount,
        $collectedOn,
        $paidOnDate,
        $method,
        $note === '' ? null : $note,
        $collectedByUserId > 0 ? $collectedByUserId : null,
        $paymentRef,
        $allowOverpayment
    );
    $pendingCount = (int) ($collectionResult['pending_count'] ?? 0);

    $scheduledInstallment = null;
    $scheduleSkippedNoPending = false;
    if ($scheduleNextPayment) {
        if ($pendingCount > 0) {
            $scheduledInstallment = schedule_next_installment_date($pdo, $loanId, $nextPaymentDateInput);
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
        } else {
            $scheduleSkippedNoPending = true;
        }
    }

    if ($pendingCount === 0) {
        $closeLoan = $pdo->prepare("UPDATE loans SET status = 'closed' WHERE id = :id");
        $closeLoan->execute(['id' => $loanId]);
    } else {
        $reopenLoan = $pdo->prepare("UPDATE loans SET status = 'active' WHERE id = :id AND status <> 'defaulted'");
        $reopenLoan->execute(['id' => $loanId]);
    }

    $pdo->commit();

    $activityDescription = $currentUserName . ' recorded collection for loan ' . $loanNumber . '.';
    if (
        !is_collector_role($currentRole)
        && $assignedUser !== null
        && is_collector_role((string) ($assignedUser['role'] ?? ''))
        && (int) ($assignedUser['id'] ?? 0) !== $currentUserId
    ) {
        $activityDescription = $currentUserName . ' collected ' . (string) $assignedUser['full_name'] . "'s collection.";
    }

    log_activity($pdo, 'collection.recorded', $activityDescription, [
        'loan_id' => $loanId,
        'loan_number' => $loanNumber,
        'amount' => $amount,
        'collected_on' => $collectedOn,
        'method' => $method,
        'payment_ref' => $paymentRef,
        'backdated_entry' => $backdatedEntry ? 1 : 0,
        'paid_on_date' => $paidOnDate,
        'schedule_next_payment' => $scheduleNextPayment ? 1 : 0,
        'scheduled_installment_id' => (int) ($scheduledInstallment['installment_id'] ?? 0),
        'scheduled_to_date' => (string) ($scheduledInstallment['to_due_date'] ?? ''),
        'schedule_skipped_no_pending' => $scheduleSkippedNoPending ? 1 : 0,
        'assigned_user' => $assignedUser !== null ? (string) ($assignedUser['full_name'] ?? '') : 'Owner',
    ]);

    if ($scheduleNextPayment && $scheduledInstallment !== null && (bool) ($scheduledInstallment['changed'] ?? false)) {
        set_flash('success', 'Collection recorded. Next payment scheduled for ' . display_date((string) $scheduledInstallment['to_due_date']) . '.');
    } elseif ($scheduleNextPayment && $scheduleSkippedNoPending) {
        set_flash('success', 'Collection recorded. Loan has no pending installments to schedule.');
    } else {
        set_flash('success', 'Collection recorded successfully.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity($pdo, 'collection.failed', 'Collection failed: ' . $e->getMessage(), [
        'loan_id' => $loanId,
        'amount' => $amount,
        'collected_on' => $collectedOn,
    ]);
    $userError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Failed to record collection. Please try again.';
    set_flash('error', $userError);
}

redirect($returnTo);
