<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loans.php');

require_roles(['superadmin', 'admin', 'collector_l2', 'collector'], 'pages/loans.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$principal = (float) ($_POST['principal_amount'] ?? 0);
$interestRate = (float) ($_POST['interest_rate'] ?? 0);
$interestRateType = normalize_interest_rate_type(trim((string) ($_POST['interest_rate_type'] ?? 'amount_based')));
$interestRateMonths = normalize_interest_rate_months((int) ($_POST['interest_rate_months'] ?? 1));
$frequency = trim((string) ($_POST['installment_frequency'] ?? 'daily'));
$timeframeValue = (int) ($_POST['timeframe_value'] ?? 0);
$timeframeUnit = trim((string) ($_POST['timeframe_unit'] ?? 'days'));
$status = trim((string) ($_POST['status'] ?? 'active'));
$notes = trim((string) ($_POST['notes'] ?? ''));
$assignedUserIdRaw = trim((string) ($_POST['assigned_user_id'] ?? ''));
$assignedUserId = $assignedUserIdRaw === '' ? null : (int) $assignedUserIdRaw;
$canEditAssignment = has_role(['superadmin', 'admin']);

if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

if ($customerId <= 0 || $principal <= 0 || $timeframeValue <= 0) {
    set_flash('error', 'Please fill all required loan fields correctly.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
    set_flash('error', 'Invalid installment frequency.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if (!in_array($timeframeUnit, ['days', 'months'], true)) {
    set_flash('error', 'Invalid timeframe unit.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if (!in_array($status, ['active', 'closed', 'defaulted'], true)) {
    set_flash('error', 'Invalid loan status.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

$customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
$customerStmt->execute(['id' => $customerId]);
if (!$customerStmt->fetch()) {
    set_flash('error', 'Customer not found.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if ($canEditAssignment && $assignedUserId !== null && $assignedUserId > 0) {
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $assignedUserId]);
    if (!$userStmt->fetch()) {
        set_flash('error', 'Selected assigned user not found.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }
} elseif ($canEditAssignment) {
    $assignedUserId = null;
}

$installmentCount = installment_count_from_timeframe($frequency, $timeframeValue, $timeframeUnit);
$totalAmount = loan_total_amount($principal, $interestRate, $interestRateType, $interestRateMonths);
$installmentAmount = round($totalAmount / $installmentCount, 2);

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $firstDueDate = (string) ($loan['first_due_date'] ?? '');
    if ($firstDueDate === '') {
        $firstDueDate = (new DateTimeImmutable(today()))->add(new DateInterval('P1D'))->format('Y-m-d');
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE loan_id = :loan_id');
    $countStmt->execute(['loan_id' => $loanId]);
    $collectionsCount = (int) $countStmt->fetchColumn();

    $outstandingStmt = $pdo->prepare('SELECT COALESCE(SUM(due_amount - paid_amount), 0) FROM loan_installments WHERE loan_id = :loan_id');
    $outstandingStmt->execute(['loan_id' => $loanId]);
    $currentOutstanding = (float) $outstandingStmt->fetchColumn();
    $hasLegacyPreCollected = $collectionsCount === 0 && ($currentOutstanding + 0.009) < (float) $loan['total_amount'];
    $repaymentLocked = $collectionsCount > 0 || $hasLegacyPreCollected;

    if ($repaymentLocked) {
        $updateLocked = $pdo->prepare(
            'UPDATE loans SET
                status = :status,
                notes = :notes
             WHERE id = :id'
        );
        $updateLocked->bindValue(':status', $status, PDO::PARAM_STR);
        $updateLocked->bindValue(':notes', $notes === '' ? null : $notes, $notes === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $updateLocked->bindValue(':id', $loanId, PDO::PARAM_INT);
        $updateLocked->execute();
    } else {
        $updateLoan = $pdo->prepare(
            'UPDATE loans SET
                customer_id = :customer_id,
                principal_amount = :principal_amount,
                interest_rate = :interest_rate,
                interest_rate_type = :interest_rate_type,
                interest_rate_months = :interest_rate_months,
                total_amount = :total_amount,
                installment_frequency = :installment_frequency,
                installment_count = :installment_count,
                installment_amount = :installment_amount,
                first_due_date = :first_due_date,
                status = :status,
                notes = :notes
             WHERE id = :id'
        );
        $updateLoan->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
        $updateLoan->bindValue(':principal_amount', $principal);
        $updateLoan->bindValue(':interest_rate', $interestRate);
        $updateLoan->bindValue(':interest_rate_type', $interestRateType, PDO::PARAM_STR);
        $updateLoan->bindValue(':interest_rate_months', $interestRateType === 'monthly' ? $interestRateMonths : 1, PDO::PARAM_INT);
        $updateLoan->bindValue(':total_amount', $totalAmount);
        $updateLoan->bindValue(':installment_frequency', $frequency, PDO::PARAM_STR);
        $updateLoan->bindValue(':installment_count', $installmentCount, PDO::PARAM_INT);
        $updateLoan->bindValue(':installment_amount', $installmentAmount);
        $updateLoan->bindValue(':first_due_date', $firstDueDate, PDO::PARAM_STR);
        $updateLoan->bindValue(':status', $status, PDO::PARAM_STR);
        $updateLoan->bindValue(':notes', $notes === '' ? null : $notes, $notes === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $updateLoan->bindValue(':id', $loanId, PDO::PARAM_INT);
        $updateLoan->execute();

        $deleteInstallments = $pdo->prepare('DELETE FROM loan_installments WHERE loan_id = :loan_id');
        $deleteInstallments->execute(['loan_id' => $loanId]);

        $dueDate = new DateTimeImmutable($firstDueDate);
        $interval = frequency_interval($frequency);
        $insertInstallment = $pdo->prepare(
            'INSERT INTO loan_installments (loan_id, installment_no, due_date, due_amount)
             VALUES (:loan_id, :installment_no, :due_date, :due_amount)'
        );

        $allocated = 0.0;
        for ($i = 1; $i <= $installmentCount; $i++) {
            $amount = $installmentAmount;
            if ($i === $installmentCount) {
                $amount = round($totalAmount - $allocated, 2);
            }

            $insertInstallment->execute([
                'loan_id' => $loanId,
                'installment_no' => $i,
                'due_date' => $dueDate->format('Y-m-d'),
                'due_amount' => $amount,
            ]);

            $allocated += $amount;
            $dueDate = $dueDate->add($interval);
        }
    }

    if ($canEditAssignment) {
        $assignStmt = $pdo->prepare('UPDATE loans SET assigned_user_id = :assigned_user_id WHERE id = :loan_id');
        $assignStmt->bindValue(':assigned_user_id', $assignedUserId, $assignedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $assignStmt->bindValue(':loan_id', $loanId, PDO::PARAM_INT);
        $assignStmt->execute();
    }

    $pdo->commit();
    $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
    log_activity($pdo, 'loan.updated', 'Loan updated: ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'customer_id' => $customerId,
        'assigned_user_id' => $assignedUserId,
        'interest_rate_type' => $interestRateType,
        'interest_rate_months' => $interestRateType === 'monthly' ? $interestRateMonths : 1,
        'status' => $status,
        'repayment_locked' => $repaymentLocked ? 1 : 0,
        'legacy_pre_collected' => $hasLegacyPreCollected ? 1 : 0,
    ]);
    if ($repaymentLocked) {
        $lockedReason = $hasLegacyPreCollected
            ? 'Loan updated (repayment structure locked because this old loan has pre-collected value).'
            : 'Loan updated (repayment structure locked because collections exist).';
        set_flash('success', $lockedReason);
    } else {
        set_flash('success', 'Loan updated successfully.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity($pdo, 'loan.update_failed', 'Loan update failed.', [
        'loan_id' => $loanId,
        'reason' => $e->getMessage(),
    ]);
    set_flash('error', 'Failed to update loan. Please try again.');
}

redirect('pages/loan_edit.php?loan_id=' . $loanId);
