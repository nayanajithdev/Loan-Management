<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l2', 'collector'], 'pages/loans.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loans.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
$installmentId = (int) ($_POST['installment_id'] ?? 0);
$amount = round((float) ($_POST['amount'] ?? 0), 2);
$method = trim((string) ($_POST['method'] ?? 'cash'));
$note = trim((string) ($_POST['note'] ?? ''));

if ($loanId <= 0 || $installmentId <= 0 || $amount <= 0) {
    set_flash('error', 'Loan, installment, and amount are required.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if (!in_array($method, ['cash', 'bank', 'online'], true)) {
    $method = 'cash';
}

$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);
$collectedOn = today();

try {
    $paymentRef = 'LNC-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
} catch (Throwable) {
    $paymentRef = 'LNC-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
}

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    if ((string) $loan['status'] === 'closed') {
        throw new RuntimeException('This loan is already closed.');
    }

    $assignedUserId = isset($loan['assigned_user_id']) ? (int) $loan['assigned_user_id'] : 0;
    if (is_collector_role($currentRole) && $assignedUserId > 0 && $assignedUserId !== $currentUserId) {
        throw new RuntimeException('You can only collect loans assigned to you (or unassigned loans).');
    }

    $outstandingStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(due_amount - paid_amount), 0)
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial', 'overdue')"
    );
    $outstandingStmt->execute(['loan_id' => $loanId]);
    $outstanding = round((float) $outstandingStmt->fetchColumn(), 2);

    if ($outstanding <= 0) {
        throw new RuntimeException('No pending installments to collect.');
    }

    if ($amount > $outstanding) {
        throw new RuntimeException('Amount cannot exceed outstanding balance (' . money_label($pdo, $outstanding) . ').');
    }

    $pendingAscStmt = $pdo->prepare(
        "SELECT *
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial', 'overdue')
         ORDER BY due_date ASC, installment_no ASC
         FOR UPDATE"
    );
    $pendingAscStmt->execute(['loan_id' => $loanId]);
    $pendingAsc = $pendingAscStmt->fetchAll();

    if (!$pendingAsc) {
        throw new RuntimeException('No pending installments to collect.');
    }

    $currentInstallment = null;
    foreach ($pendingAsc as $row) {
        if ((int) $row['id'] === $installmentId) {
            $currentInstallment = $row;
            break;
        }
    }

    if ($currentInstallment === null) {
        throw new RuntimeException('Selected installment is not pending.');
    }

    $firstPendingId = (int) $pendingAsc[0]['id'];
    if ($firstPendingId !== $installmentId) {
        throw new RuntimeException('Only the current installment can be collected from this panel.');
    }

    $updateInstallment = $pdo->prepare(
        'UPDATE loan_installments
         SET paid_amount = :paid_amount, paid_on = :paid_on, status = :status
         WHERE id = :id'
    );
    $insertCollection = $pdo->prepare(
        'INSERT INTO collections (loan_id, installment_id, amount, collected_on, method, note, collected_by_user_id, payment_ref)
         VALUES (:loan_id, :installment_id, :amount, :collected_on, :method, :note, :collected_by_user_id, :payment_ref)'
    );

    $remaining = $amount;
    foreach ($pendingAsc as $inst) {
        if ($remaining <= 0.009) {
            break;
        }

        $balance = round((float) $inst['due_amount'] - (float) $inst['paid_amount'], 2);
        if ($balance <= 0.009) {
            continue;
        }

        $pay = min($remaining, $balance);
        $newPaid = round((float) $inst['paid_amount'] + $pay, 2);

        if ($newPaid + 0.009 >= (float) $inst['due_amount']) {
            $status = 'paid';
            $paidOnValue = $collectedOn;
        } elseif ($newPaid > 0.009) {
            $status = 'partial';
            $paidOnValue = null;
        } else {
            $status = (string) $inst['status'];
            $paidOnValue = (string) ($inst['paid_on'] ?? '') !== '' ? (string) $inst['paid_on'] : null;
        }

        $updateInstallment->execute([
            'paid_amount' => $newPaid,
            'paid_on' => $paidOnValue,
            'status' => $status,
            'id' => $inst['id'],
        ]);

        $insertCollection->execute([
            'loan_id' => $loanId,
            'installment_id' => (int) $inst['id'],
            'amount' => $pay,
            'collected_on' => $collectedOn,
            'method' => $method,
            'note' => $note === '' ? null : $note,
            'collected_by_user_id' => $currentUserId > 0 ? $currentUserId : null,
            'payment_ref' => $paymentRef,
        ]);

        $remaining = round($remaining - $pay, 2);
    }

    if ($remaining > 0.009) {
        throw new RuntimeException('Collection amount exceeds outstanding balance.');
    }

    $pendingCountStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial', 'overdue')"
    );
    $pendingCountStmt->execute(['loan_id' => $loanId]);
    $pendingCount = (int) $pendingCountStmt->fetchColumn();

    // Keep "today collection" and "tomorrow collection" aligned when multiple
    // installments are collected early from inline cards.
    if ($pendingCount > 0) {
        $nextPendingDueStmt = $pdo->prepare(
            "SELECT due_date
             FROM loan_installments
             WHERE loan_id = :loan_id
               AND status IN ('pending', 'partial', 'overdue')
             ORDER BY due_date ASC, installment_no ASC
             LIMIT 1
             FOR UPDATE"
        );
        $nextPendingDueStmt->execute(['loan_id' => $loanId]);
        $nextPendingDue = (string) $nextPendingDueStmt->fetchColumn();

        if ($nextPendingDue !== '') {
            $frequency = (string) ($loan['installment_frequency'] ?? 'daily');
            $expectedNextDue = (new DateTimeImmutable($collectedOn))
                ->add(frequency_interval($frequency))
                ->format('Y-m-d');

            if ($nextPendingDue > $expectedNextDue) {
                schedule_next_installment_date($pdo, $loanId, $expectedNextDue);
            }
        }
    }

    if ($pendingCount === 0) {
        $statusStmt = $pdo->prepare("UPDATE loans SET status = 'closed' WHERE id = :loan_id");
        $statusStmt->execute(['loan_id' => $loanId]);
    } else {
        $statusStmt = $pdo->prepare("UPDATE loans SET status = 'active' WHERE id = :loan_id AND status <> 'defaulted'");
        $statusStmt->execute(['loan_id' => $loanId]);
    }

    $pdo->commit();

    $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
    log_activity($pdo, 'loan.inline_collection', 'Inline collection recorded for loan ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'installment_id' => $installmentId,
        'amount' => $amount,
        'method' => $method,
        'payment_ref' => $paymentRef,
    ]);
    set_flash('success', 'Collection recorded.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity($pdo, 'loan.inline_collection_failed', 'Inline collection failed: ' . $e->getMessage(), [
        'loan_id' => $loanId,
        'installment_id' => $installmentId,
        'amount' => $amount,
    ]);
    $error = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Failed to record collection.';
    set_flash('error', $error);
}

redirect('pages/loan_edit.php?loan_id=' . $loanId . '#installment-cards');
