<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('loans.create', 'pages/loans.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loan_create.php');

$loanNumberInput = trim((string) ($_POST['loan_number'] ?? ''));
$loanNumber = normalize_loan_number_input($loanNumberInput);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$issuedDate = trim((string) ($_POST['issued_date'] ?? today()));
$principal = (float) ($_POST['principal_amount'] ?? 0);
$interestRate = (float) ($_POST['interest_rate'] ?? 0);
$interestRateType = normalize_interest_rate_type(trim((string) ($_POST['interest_rate_type'] ?? 'amount_based')));
$interestRateMonths = normalize_interest_rate_months((int) ($_POST['interest_rate_months'] ?? 1));
$frequency = trim((string) ($_POST['installment_frequency'] ?? 'daily'));
$timeframeValue = (int) ($_POST['timeframe_value'] ?? 0);
$timeframeUnit = trim((string) ($_POST['timeframe_unit'] ?? 'days'));
$useRoundedInstallment = (int) ($_POST['use_rounded_installment'] ?? 0) === 1;
$roundedInstallmentAmount = round((float) ($_POST['rounded_installment_amount'] ?? 0), 2);
$canAssignLoan = can('loans.assign');
$assignedUserId = $canAssignLoan
    ? assignable_collector_id_or_default($pdo, (int) ($_POST['assigned_user_id'] ?? 0))
    : default_loan_collector_id($pdo);
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($loanNumber === '' || (int) ltrim($loanNumber, '0') <= 0) {
    set_flash('error', 'Loan No must be a positive number.');
    redirect('pages/loan_create.php');
}

if (loan_number_exists($pdo, $loanNumber)) {
    set_flash('error', 'Loan No already exists. Please use another number.');
    redirect('pages/loan_create.php');
}

if ($customerId <= 0 || $principal <= 0 || $timeframeValue <= 0) {
    set_flash('error', 'Please fill all required loan fields correctly.');
    redirect('pages/loan_create.php');
}

$issuedDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $issuedDate);
if (!$issuedDateObj || $issuedDateObj->format('Y-m-d') !== $issuedDate) {
    set_flash('error', 'Invalid loan issued date.');
    redirect('pages/loan_create.php');
}
$issuedDate = $issuedDateObj->format('Y-m-d');

if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
    set_flash('error', 'Invalid installment frequency.');
    redirect('pages/loan_create.php');
}

if (!in_array($timeframeUnit, ['days', 'months'], true)) {
    set_flash('error', 'Invalid timeframe unit.');
    redirect('pages/loan_create.php');
}

$installmentCount = installment_count_from_timeframe($frequency, $timeframeValue, $timeframeUnit);

$customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
$customerStmt->execute(['id' => $customerId]);
if (!$customerStmt->fetch()) {
    set_flash('error', 'Customer not found.');
    redirect('pages/loan_create.php');
}

if ($assignedUserId <= 0) {
    set_flash('error', 'Owner account is required before creating loans.');
    redirect('pages/loan_create.php');
}

$totalAmount = loan_total_amount($principal, $interestRate, $interestRateType, $interestRateMonths);
if ($useRoundedInstallment) {
    if ($roundedInstallmentAmount <= 0) {
        set_flash('error', 'Rounded installment amount must be greater than zero.');
        redirect('pages/loan_create.php');
    }

    if ($roundedInstallmentAmount > $totalAmount) {
        set_flash('error', 'Rounded installment amount cannot be greater than total repayable amount.');
        redirect('pages/loan_create.php');
    }

    $installmentCount = max((int) ceil($totalAmount / $roundedInstallmentAmount), 1);
    $installmentAmount = $roundedInstallmentAmount;
} else {
    $installmentAmount = round($totalAmount / $installmentCount, 2);
}

$startDate = $issuedDate;
$firstDueDate = next_collectible_date($pdo, $issuedDateObj->add(new DateInterval('P1D'))->format('Y-m-d'));

try {
    $pdo->beginTransaction();

    $insertLoan = $pdo->prepare(
        'INSERT INTO loans (
            loan_number,
            customer_id,
            assigned_user_id,
            issued_date,
            principal_amount,
            interest_rate,
            interest_rate_type,
            interest_rate_months,
            total_amount,
            installment_frequency,
            installment_count,
            installment_amount,
            start_date,
            first_due_date,
            notes
        ) VALUES (
            :loan_number,
            :customer_id,
            :assigned_user_id,
            :issued_date,
            :principal_amount,
            :interest_rate,
            :interest_rate_type,
            :interest_rate_months,
            :total_amount,
            :installment_frequency,
            :installment_count,
            :installment_amount,
            :start_date,
            :first_due_date,
            :notes
        )'
    );

    $insertLoan->execute([
        'loan_number' => $loanNumber,
        'customer_id' => $customerId,
        'assigned_user_id' => $assignedUserId,
        'issued_date' => $issuedDate,
        'principal_amount' => $principal,
        'interest_rate' => $interestRate,
        'interest_rate_type' => $interestRateType,
        'interest_rate_months' => $interestRateType === 'monthly' ? $interestRateMonths : 1,
        'total_amount' => $totalAmount,
        'installment_frequency' => $frequency,
        'installment_count' => $installmentCount,
        'installment_amount' => $installmentAmount,
        'start_date' => $startDate,
        'first_due_date' => $firstDueDate,
        'notes' => $notes === '' ? null : $notes,
    ]);

    $loanId = (int) $pdo->lastInsertId();

    $dueDate = new DateTimeImmutable($firstDueDate);
    $interval = frequency_interval($frequency);

    $insertInstallment = $pdo->prepare(
        'INSERT INTO loan_installments (loan_id, installment_no, due_date, due_amount)
         VALUES (:loan_id, :installment_no, :due_date, :due_amount)'
    );

    $allocated = 0.0;
    $loanEndDate = $firstDueDate;
    for ($i = 1; $i <= $installmentCount; $i++) {
        $currentDueDate = next_collectible_date($pdo, $dueDate->format('Y-m-d'));
        $loanEndDate = $currentDueDate;
        $amount = $installmentAmount;
        if ($i === $installmentCount) {
            $amount = round($totalAmount - $allocated, 2);
        }

        $insertInstallment->execute([
            'loan_id' => $loanId,
            'installment_no' => $i,
            'due_date' => $currentDueDate,
            'due_amount' => $amount,
        ]);

        $allocated += $amount;
        $dueDate = (new DateTimeImmutable($currentDueDate))->add($interval);
    }

    $updateEndDate = $pdo->prepare('UPDATE loans SET end_date = :end_date WHERE id = :loan_id');
    $updateEndDate->execute([
        'end_date' => $loanEndDate,
        'loan_id' => $loanId,
    ]);

    $pdo->commit();
    log_activity($pdo, 'loan.created', 'Loan created: ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'customer_id' => $customerId,
        'assigned_user_id' => $assignedUserId,
        'issued_date' => $issuedDate,
        'principal_amount' => $principal,
        'interest_rate_type' => $interestRateType,
        'interest_rate_months' => $interestRateType === 'monthly' ? $interestRateMonths : 1,
        'total_amount' => $totalAmount,
        'installment_count' => $installmentCount,
        'end_date' => $loanEndDate,
        'use_rounded_installment' => $useRoundedInstallment ? 1 : 0,
        'rounded_installment_amount' => $useRoundedInstallment ? $roundedInstallmentAmount : null,
        'installment_frequency' => $frequency,
    ]);
    set_flash('success', 'Loan created and installment schedule generated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity($pdo, 'loan.create_failed', 'Loan creation failed.', [
        'customer_id' => $customerId,
        'principal_amount' => $principal,
        'reason' => $e->getMessage(),
    ]);
    set_flash('error', 'Failed to create loan. Please try again.');
}

redirect('pages/loans.php');
