<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector'], 'pages/today_collections.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/collections.php');
}
require_csrf('pages/today_collections.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
$preferredInstallmentId = isset($_POST['installment_id']) ? (int) $_POST['installment_id'] : 0;
$amount = round((float) ($_POST['amount'] ?? 0), 2);
$collectedOn = trim((string) ($_POST['collected_on'] ?? ''));
$method = trim((string) ($_POST['method'] ?? 'cash'));
$note = trim((string) ($_POST['note'] ?? ''));
$collectedByUserId = (int) (current_user()['id'] ?? 0);
try {
    $paymentRef = 'PAY-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
} catch (Throwable) {
    $paymentRef = 'PAY-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
}
$returnToRaw = trim((string) ($_POST['return_to'] ?? 'pages/collections.php'));

$returnTo = 'pages/collections.php';
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
        if (!empty($queryValues['selected_installment'])) {
            $allowedQuery['selected_installment'] = (int) $queryValues['selected_installment'];
        }
    }

    if (!empty($allowedQuery)) {
        $returnTo .= '?' . http_build_query($allowedQuery);
    }
}

if ($loanId <= 0 || $amount <= 0 || $collectedOn === '') {
    set_flash('error', 'Loan, amount and collection date are required.');
    redirect($returnTo);
}

if ($collectedOn > today()) {
    set_flash('error', 'Cannot save collection for a future date.');
    redirect($returnTo);
}

if (!in_array($method, ['cash', 'bank', 'online'], true)) {
    $method = 'cash';
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

    $current = current_user();
    $currentRole = (string) ($current['role'] ?? '');
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
        throw new RuntimeException('You can only collect payments for loans assigned to you (or unassigned loans).');
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
        throw new RuntimeException('This loan has no pending installments to collect.');
    }

    if (!$allowOverpayment && $amount > $outstanding) {
        throw new RuntimeException('Overpayment is disabled. Maximum allowed amount is ' . money_label($pdo, $outstanding) . '.');
    }

    $remaining = $amount;

    $installments = [];

    if ($preferredInstallmentId > 0) {
        $prefStmt = $pdo->prepare(
            "SELECT * FROM loan_installments
             WHERE id = :id AND loan_id = :loan_id
             FOR UPDATE"
        );
        $prefStmt->execute([
            'id' => $preferredInstallmentId,
            'loan_id' => $loanId,
        ]);
        $preferred = $prefStmt->fetch();

        if ($preferred && in_array($preferred['status'], ['pending', 'partial', 'overdue'], true)) {
            $installments[] = $preferred;
        }
    }

    $instStmt = $pdo->prepare(
        "SELECT * FROM loan_installments
         WHERE loan_id = :loan_id AND status IN ('pending', 'partial', 'overdue')
         ORDER BY due_date ASC, installment_no ASC
         FOR UPDATE"
    );
    $instStmt->execute(['loan_id' => $loanId]);
    $remainingInstallments = $instStmt->fetchAll();

    foreach ($remainingInstallments as $item) {
        if ($preferredInstallmentId > 0 && (int) $item['id'] === $preferredInstallmentId) {
            continue;
        }
        $installments[] = $item;
    }

    $updateInstallment = $pdo->prepare(
        "UPDATE loan_installments
         SET paid_amount = :paid_amount, paid_on = :paid_on, status = :status
         WHERE id = :id"
    );

    $insertCollection = $pdo->prepare(
        'INSERT INTO collections (loan_id, installment_id, amount, collected_on, method, note, collected_by_user_id, payment_ref)
         VALUES (:loan_id, :installment_id, :amount, :collected_on, :method, :note, :collected_by_user_id, :payment_ref)'
    );

    foreach ($installments as $inst) {
        if ($remaining <= 0) {
            break;
        }

        $balance = round((float) $inst['due_amount'] - (float) $inst['paid_amount'], 2);
        if ($balance <= 0) {
            continue;
        }

        $pay = min($remaining, $balance);
        $newPaid = round((float) $inst['paid_amount'] + $pay, 2);

        if ($newPaid >= (float) $inst['due_amount']) {
            $status = 'paid';
        } elseif ($newPaid > 0) {
            $status = 'partial';
        } else {
            $status = 'pending';
        }

        $updateInstallment->execute([
            'paid_amount' => $newPaid,
            'paid_on' => $status === 'paid' ? $collectedOn : null,
            'status' => $status,
            'id' => $inst['id'],
        ]);

        $insertCollection->execute([
            'loan_id' => $loanId,
            'installment_id' => $inst['id'],
            'amount' => $pay,
            'collected_on' => $collectedOn,
            'method' => $method,
            'note' => $note === '' ? null : $note,
            'collected_by_user_id' => $collectedByUserId > 0 ? $collectedByUserId : null,
            'payment_ref' => $paymentRef,
        ]);

        $remaining = round($remaining - $pay, 2);
    }

    if ($remaining > 0) {
        if (!$allowOverpayment) {
            throw new RuntimeException('Overpayment is disabled for this system.');
        }

        $insertCollection->execute([
            'loan_id' => $loanId,
            'installment_id' => null,
            'amount' => $remaining,
            'collected_on' => $collectedOn,
            'method' => $method,
            'note' => trim(($note === '' ? 'Advance payment' : $note . ' | Advance payment')),
            'collected_by_user_id' => $collectedByUserId > 0 ? $collectedByUserId : null,
            'payment_ref' => $paymentRef,
        ]);
    }

    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM loan_installments
         WHERE loan_id = :loan_id AND status IN ('pending', 'partial', 'overdue')"
    );
    $check->execute(['loan_id' => $loanId]);
    $pendingCount = (int) $check->fetchColumn();

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
        $currentRole === 'admin'
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
        'assigned_user' => $assignedUser !== null ? (string) ($assignedUser['full_name'] ?? '') : 'Unassigned',
    ]);

    set_flash('success', 'Collection recorded successfully.');
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
