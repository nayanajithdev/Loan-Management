<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('loans.create', 'pages/loans.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loan_legacy_create.php');

$loanNumberInput = trim((string) ($_POST['loan_number'] ?? ''));
$loanNumber = normalize_loan_number_input($loanNumberInput);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$principal = (float) ($_POST['principal_amount'] ?? 0);
$interestRate = (float) ($_POST['interest_rate'] ?? 0);
$interestRateType = normalize_interest_rate_type(trim((string) ($_POST['interest_rate_type'] ?? 'amount_based')));
$interestRateMonths = normalize_interest_rate_months((int) ($_POST['interest_rate_months'] ?? 1));
$frequency = trim((string) ($_POST['installment_frequency'] ?? 'daily'));
$timeframeValue = (int) ($_POST['timeframe_value'] ?? 0);
$timeframeUnit = trim((string) ($_POST['timeframe_unit'] ?? 'days'));
$issuedDate = trim((string) ($_POST['issued_date'] ?? ''));
$collectedAmount = round((float) ($_POST['collected_amount'] ?? 0), 2);
$collectedIncludingToday = (int) ($_POST['collected_including_today'] ?? 0) === 1;
$canAssignLoan = can('loans.assign');
$assignedUserId = $canAssignLoan
    ? assignable_collector_id_or_default($pdo, (int) ($_POST['assigned_user_id'] ?? 0))
    : default_loan_collector_id($pdo);
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($loanNumber === '' || (int) ltrim($loanNumber, '0') <= 0) {
    set_flash('error', 'Loan No must be a positive number.');
    redirect('pages/loan_legacy_create.php');
}

if (loan_number_exists($pdo, $loanNumber)) {
    set_flash('error', 'Loan No already exists. Please use another number.');
    redirect('pages/loan_legacy_create.php');
}

if ($customerId <= 0 || $principal <= 0 || $timeframeValue <= 0 || $issuedDate === '') {
    set_flash('error', 'Please fill all required old loan fields correctly.');
    redirect('pages/loan_legacy_create.php');
}

if ($assignedUserId <= 0) {
    set_flash('error', 'Owner account is required before adding old loans.');
    redirect('pages/loan_legacy_create.php');
}

if ($collectedAmount < 0) {
    set_flash('error', 'Collected amount cannot be negative.');
    redirect('pages/loan_legacy_create.php');
}

if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
    set_flash('error', 'Invalid installment frequency.');
    redirect('pages/loan_legacy_create.php');
}

if (!in_array($timeframeUnit, ['days', 'months'], true)) {
    set_flash('error', 'Invalid timeframe unit.');
    redirect('pages/loan_legacy_create.php');
}

$issuedDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $issuedDate);
if (!$issuedDateObj || $issuedDateObj->format('Y-m-d') !== $issuedDate) {
    set_flash('error', 'Invalid loan issued date.');
    redirect('pages/loan_legacy_create.php');
}

if ($issuedDate > today()) {
    set_flash('error', 'Loan issued date cannot be in the future.');
    redirect('pages/loan_legacy_create.php');
}

$customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
$customerStmt->execute(['id' => $customerId]);
if (!$customerStmt->fetch()) {
    set_flash('error', 'Customer not found.');
    redirect('pages/loan_legacy_create.php');
}

$originalInstallmentCount = installment_count_from_timeframe($frequency, $timeframeValue, $timeframeUnit);
$totalAmount = loan_total_amount($principal, $interestRate, $interestRateType, $interestRateMonths);

if ($collectedAmount > $totalAmount) {
    set_flash('error', 'Collected amount cannot be greater than total repayable amount.');
    redirect('pages/loan_legacy_create.php');
}

$remainingAmount = round($totalAmount - $collectedAmount, 2);
$originalInstallmentAmount = round($totalAmount / max($originalInstallmentCount, 1), 2);
$remainingInstallmentCount = 0;
$status = 'closed';
$installmentAmount = 0.0;

if ($remainingAmount > 0) {
    $remainingInstallmentCount = max((int) ceil($remainingAmount / max($originalInstallmentAmount, 0.01)), 1);
    $installmentAmount = round($remainingAmount / $remainingInstallmentCount, 2);
    $status = 'active';
}

// For imported old loans, keep installment numbering aligned to the original
// schedule position instead of restarting from #1.
$startingInstallmentNo = max(1, $originalInstallmentCount - $remainingInstallmentCount + 1);

$startDate = $issuedDate;
$firstDueDate = $collectedIncludingToday
    ? (new DateTimeImmutable(today()))->add(new DateInterval('P1D'))->format('Y-m-d')
    : today();
$firstDueDate = next_collectible_date($pdo, $firstDueDate);

try {
    $pdo->beginTransaction();

    $insertLoan = $pdo->prepare(
        'INSERT INTO loans (
            loan_number,
            customer_id,
            assigned_user_id,
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
            status,
            notes
        ) VALUES (
            :loan_number,
            :customer_id,
            :assigned_user_id,
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
            :status,
            :notes
        )'
    );

    $insertLoan->execute([
        'loan_number' => $loanNumber,
        'customer_id' => $customerId,
        'assigned_user_id' => $assignedUserId,
        'principal_amount' => $principal,
        'interest_rate' => $interestRate,
        'interest_rate_type' => $interestRateType,
        'interest_rate_months' => $interestRateType === 'monthly' ? $interestRateMonths : 1,
        'total_amount' => $totalAmount,
        'installment_frequency' => $frequency,
        'installment_count' => $remainingInstallmentCount,
        'installment_amount' => $installmentAmount,
        'start_date' => $startDate,
        'first_due_date' => $firstDueDate,
        'status' => $status,
        'notes' => $notes === '' ? null : $notes,
    ]);

    $loanId = (int) $pdo->lastInsertId();

    if ($remainingInstallmentCount > 0) {
        $dueDate = new DateTimeImmutable($firstDueDate);
        $interval = frequency_interval($frequency);
        $insertInstallment = $pdo->prepare(
            'INSERT INTO loan_installments (loan_id, installment_no, due_date, due_amount)
             VALUES (:loan_id, :installment_no, :due_date, :due_amount)'
        );

        $allocated = 0.0;
        for ($i = 1; $i <= $remainingInstallmentCount; $i++) {
            $currentDueDate = next_collectible_date($pdo, $dueDate->format('Y-m-d'));
            $installmentNo = $startingInstallmentNo + ($i - 1);
            $amount = $installmentAmount;
            if ($i === $remainingInstallmentCount) {
                $amount = round($remainingAmount - $allocated, 2);
            } elseif ($allocated + $amount > $remainingAmount) {
                $amount = round($remainingAmount - $allocated, 2);
            }

            $insertInstallment->execute([
                'loan_id' => $loanId,
                'installment_no' => $installmentNo,
                'due_date' => $currentDueDate,
                'due_amount' => $amount,
            ]);

            $allocated = round($allocated + $amount, 2);
            $dueDate = (new DateTimeImmutable($currentDueDate))->add($interval);
        }
    }

    $pdo->commit();

    log_activity($pdo, 'loan.imported_old', 'Old loan imported: ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'customer_id' => $customerId,
        'assigned_user_id' => $assignedUserId,
        'principal_amount' => $principal,
        'interest_rate_type' => $interestRateType,
        'interest_rate_months' => $interestRateType === 'monthly' ? $interestRateMonths : 1,
        'original_installment_count' => $originalInstallmentCount,
        'remaining_installment_count' => $remainingInstallmentCount,
        'total_amount' => $totalAmount,
        'collected_amount' => $collectedAmount,
        'remaining_amount' => $remainingAmount,
        'issued_date' => $issuedDate,
        'collected_including_today' => $collectedIncludingToday ? 1 : 0,
        'first_due_date' => $firstDueDate,
    ]);

    if ($remainingAmount <= 0) {
        set_flash('success', 'Old loan added as fully collected (closed).');
    } else {
        $nextStartText = $collectedIncludingToday ? 'tomorrow' : 'today';
        set_flash('success', 'Old loan added. Remaining schedule starts from ' . $nextStartText . '.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_activity($pdo, 'loan.import_old_failed', 'Old loan import failed.', [
        'customer_id' => $customerId,
        'principal_amount' => $principal,
        'reason' => $e->getMessage(),
    ]);

    set_flash('error', 'Failed to add old loan. Please try again.');
}

redirect('pages/loans.php');
