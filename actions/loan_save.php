<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l2', 'collector'], 'pages/loans.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}

$customerId = (int) ($_POST['customer_id'] ?? 0);
$principal = (float) ($_POST['principal_amount'] ?? 0);
$interestRate = (float) ($_POST['interest_rate'] ?? 0);
$frequency = trim((string) ($_POST['installment_frequency'] ?? 'daily'));
$timeframeValue = (int) ($_POST['timeframe_value'] ?? 0);
$timeframeUnit = trim((string) ($_POST['timeframe_unit'] ?? 'days'));
$firstDueDate = trim((string) ($_POST['first_due_date'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($customerId <= 0 || $principal <= 0 || $timeframeValue <= 0 || $firstDueDate === '') {
    set_flash('error', 'Please fill all required loan fields correctly.');
    redirect('pages/loans.php');
}

if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
    set_flash('error', 'Invalid installment frequency.');
    redirect('pages/loans.php');
}

if (!in_array($timeframeUnit, ['days', 'months'], true)) {
    set_flash('error', 'Invalid timeframe unit.');
    redirect('pages/loans.php');
}

$minimumFirstDueDate = (new DateTimeImmutable(today()))->add(new DateInterval('P1D'))->format('Y-m-d');
if ($firstDueDate < $minimumFirstDueDate) {
    set_flash('error', 'First due date must be tomorrow or later.');
    redirect('pages/loan_create.php');
}

$installmentCount = installment_count_from_timeframe($frequency, $timeframeValue, $timeframeUnit);

$customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
$customerStmt->execute(['id' => $customerId]);
if (!$customerStmt->fetch()) {
    set_flash('error', 'Customer not found.');
    redirect('pages/loans.php');
}

$totalAmount = round($principal + (($principal * $interestRate) / 100), 2);
$installmentAmount = round($totalAmount / $installmentCount, 2);

$loanNumber = next_loan_number($pdo);
$startDate = today();

try {
    $pdo->beginTransaction();

    $insertLoan = $pdo->prepare(
        'INSERT INTO loans (
            loan_number,
            customer_id,
            principal_amount,
            interest_rate,
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
            :principal_amount,
            :interest_rate,
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
        'principal_amount' => $principal,
        'interest_rate' => $interestRate,
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

    $pdo->commit();
    log_activity($pdo, 'loan.created', 'Loan created: ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'customer_id' => $customerId,
        'principal_amount' => $principal,
        'total_amount' => $totalAmount,
        'installment_count' => $installmentCount,
        'installment_frequency' => $frequency,
    ]);
    set_flash('success', 'Loan created and installment schedule generated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Failed to create loan: ' . $e->getMessage());
}

redirect('pages/loans.php');
