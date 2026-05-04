<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}

require_roles(['superadmin', 'admin'], 'pages/loans.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$principal = (float) ($_POST['principal_amount'] ?? 0);
$interestRate = (float) ($_POST['interest_rate'] ?? 0);
$frequency = trim((string) ($_POST['installment_frequency'] ?? 'daily'));
$timeframeValue = (int) ($_POST['timeframe_value'] ?? 0);
$timeframeUnit = trim((string) ($_POST['timeframe_unit'] ?? 'days'));
$firstDueDate = trim((string) ($_POST['first_due_date'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'active'));
$notes = trim((string) ($_POST['notes'] ?? ''));
$assignedUserIdRaw = trim((string) ($_POST['assigned_user_id'] ?? ''));
$assignedUserId = $assignedUserIdRaw === '' ? null : (int) $assignedUserIdRaw;

if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

if ($customerId <= 0 || $principal <= 0 || $timeframeValue <= 0 || $firstDueDate === '') {
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

if ($assignedUserId !== null && $assignedUserId > 0) {
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $assignedUserId]);
    if (!$userStmt->fetch()) {
        set_flash('error', 'Selected assigned user not found.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }
} else {
    $assignedUserId = null;
}

$installmentCount = installment_count_from_timeframe($frequency, $timeframeValue, $timeframeUnit);
$totalAmount = round($principal + (($principal * $interestRate) / 100), 2);
$installmentAmount = round($totalAmount / $installmentCount, 2);

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE loan_id = :loan_id');
    $countStmt->execute(['loan_id' => $loanId]);
    $collectionsCount = (int) $countStmt->fetchColumn();

    if ($collectionsCount > 0) {
        $updateLocked = $pdo->prepare(
            'UPDATE loans SET
                status = :status,
                notes = :notes,
                assigned_user_id = :assigned_user_id
             WHERE id = :id'
        );
        $updateLocked->bindValue(':status', $status, PDO::PARAM_STR);
        $updateLocked->bindValue(':notes', $notes === '' ? null : $notes, $notes === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $updateLocked->bindValue(':assigned_user_id', $assignedUserId, $assignedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $updateLocked->bindValue(':id', $loanId, PDO::PARAM_INT);
        $updateLocked->execute();
    } else {
        $updateLoan = $pdo->prepare(
            'UPDATE loans SET
                customer_id = :customer_id,
                assigned_user_id = :assigned_user_id,
                principal_amount = :principal_amount,
                interest_rate = :interest_rate,
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
        $updateLoan->bindValue(':assigned_user_id', $assignedUserId, $assignedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $updateLoan->bindValue(':principal_amount', $principal);
        $updateLoan->bindValue(':interest_rate', $interestRate);
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

    $pdo->commit();
    set_flash('success', $collectionsCount > 0 ? 'Loan updated (repayment structure locked because collections exist).' : 'Loan updated successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Failed to update loan: ' . $e->getMessage());
}

redirect('pages/loan_edit.php?loan_id=' . $loanId);

