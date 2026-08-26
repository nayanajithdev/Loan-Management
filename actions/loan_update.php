<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loans.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$issuedDate = trim((string) ($_POST['issued_date'] ?? today()));
$principal = (float) ($_POST['principal_amount'] ?? 0);
$interestRate = (float) ($_POST['interest_rate'] ?? 0);
$interestRateType = normalize_interest_rate_type(trim((string) ($_POST['interest_rate_type'] ?? 'amount_based')));
$interestRateMonths = normalize_interest_rate_months((int) ($_POST['interest_rate_months'] ?? 1));
$frequency = trim((string) ($_POST['installment_frequency'] ?? 'daily'));
$timeframeValue = (int) ($_POST['timeframe_value'] ?? 0);
$timeframeUnit = trim((string) ($_POST['timeframe_unit'] ?? 'days'));
$status = trim((string) ($_POST['status'] ?? 'active'));
$notes = trim((string) ($_POST['notes'] ?? ''));
$scheduleNextPayment = (int) ($_POST['schedule_next_payment'] ?? 0) === 1;
$nextPaymentDateInput = trim((string) ($_POST['next_payment_date'] ?? ''));
$roundedInstallmentAmount = round((float) ($_POST['rounded_installment_amount'] ?? 0), 2);
$useRoundedInstallment = ((int) ($_POST['use_rounded_installment'] ?? 0) === 1) || $roundedInstallmentAmount > 0;
$canEditLoan = can('loans.edit');
$canEditAssignment = can('loans.assign');
$canScheduleNextPayment = can('collections.schedule');
$canExtendLoan = can('loans.extend');
$postedAssignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
$assignedUserId = $canEditAssignment
    ? ($postedAssignedUserId > 0 ? assignable_collector_id_or_default($pdo, $postedAssignedUserId) : null)
    : 0;

if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

if (!$canEditLoan) {
    if (!$scheduleNextPayment || !$canScheduleNextPayment) {
        set_flash('error', 'You do not have permission to edit loans.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    $nextDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $nextPaymentDateInput);
    if (!$nextDateObj || $nextDateObj->format('Y-m-d') !== $nextPaymentDateInput) {
        set_flash('error', 'Invalid next payment date.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    if ($nextPaymentDateInput <= today()) {
        set_flash('error', 'Next payment date must be after today.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    try {
        $pdo->beginTransaction();

        $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
        $loanStmt->execute(['id' => $loanId]);
        $loan = $loanStmt->fetch();
        if (!$loan) {
            throw new RuntimeException('Loan not found.');
        }

        $pendingScheduleStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM loan_installments
             WHERE loan_id = :loan_id
               AND status IN ('pending', 'partial', 'overdue')"
        );
        $pendingScheduleStmt->execute(['loan_id' => $loanId]);
        $pendingScheduleCount = (int) $pendingScheduleStmt->fetchColumn();
        $scheduledInstallment = null;
        $scheduleSkippedNoPending = false;

        if ($pendingScheduleCount > 0) {
            $scheduledInstallment = schedule_next_installment_date($pdo, $loanId, $nextPaymentDateInput);
        } else {
            $scheduleSkippedNoPending = true;
        }

        $pdo->commit();
        $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
        log_activity($pdo, 'loan.next_payment_scheduled', 'Next payment scheduled for loan: ' . $loanNumber . '.', [
            'loan_id' => $loanId,
            'customer_id' => (int) ($loan['customer_id'] ?? 0),
            'scheduled_installment_id' => (int) ($scheduledInstallment['installment_id'] ?? 0),
            'scheduled_to_date' => (string) ($scheduledInstallment['to_due_date'] ?? ''),
            'schedule_skipped_no_pending' => $scheduleSkippedNoPending ? 1 : 0,
        ]);

        if ($scheduledInstallment !== null && (bool) ($scheduledInstallment['changed'] ?? false)) {
            set_flash('success', 'Next payment scheduled for ' . display_date((string) $scheduledInstallment['to_due_date']) . '.');
        } elseif ($scheduleSkippedNoPending) {
            set_flash('success', 'No pending installments available to schedule.');
        } else {
            set_flash('success', 'Next payment date already matches the selected date.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_activity($pdo, 'loan.schedule_next_payment_failed', 'Next payment schedule failed.', [
            'loan_id' => $loanId,
            'reason' => $e->getMessage(),
        ]);
        set_flash('error', loan_update_public_error_message($e));
    }

    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if ($customerId <= 0 || $principal <= 0 || $timeframeValue <= 0) {
    set_flash('error', 'Please fill all required loan fields correctly.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

$issuedDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $issuedDate);
if (!$issuedDateObj || $issuedDateObj->format('Y-m-d') !== $issuedDate) {
    set_flash('error', 'Invalid loan issued date.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}
$issuedDate = $issuedDateObj->format('Y-m-d');

if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
    set_flash('error', 'Invalid installment frequency.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if (!in_array($timeframeUnit, ['days', 'months'], true)) {
    set_flash('error', 'Invalid timeframe unit.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if (!in_array($status, ['active', 'closed'], true)) {
    set_flash('error', 'Invalid loan status.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if ($scheduleNextPayment) {
    if (!$canScheduleNextPayment) {
        set_flash('error', 'You do not have permission to schedule the next payment.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    $nextDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $nextPaymentDateInput);
    if (!$nextDateObj || $nextDateObj->format('Y-m-d') !== $nextPaymentDateInput) {
        set_flash('error', 'Invalid next payment date.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    if ($nextPaymentDateInput <= today()) {
        set_flash('error', 'Next payment date must be after today.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }
}

$customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
$customerStmt->execute(['id' => $customerId]);
if (!$customerStmt->fetch()) {
    set_flash('error', 'Customer not found.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

if ($canEditAssignment && $assignedUserId !== null && $assignedUserId <= 0) {
    set_flash('error', 'Owner account is required before assigning loans.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

$installmentCount = installment_count_from_timeframe($frequency, $timeframeValue, $timeframeUnit);
$totalAmount = loan_total_amount($principal, $interestRate, $interestRateType, $interestRateMonths);
if ($useRoundedInstallment) {
    if ($roundedInstallmentAmount <= 0) {
        set_flash('error', 'Change installment amount must be greater than zero.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    if ($roundedInstallmentAmount > $totalAmount) {
        set_flash('error', 'Change installment amount cannot be greater than total repayable amount.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    $installmentCount = max((int) ceil($totalAmount / $roundedInstallmentAmount), 1);
    $installmentAmount = $roundedInstallmentAmount;
} else {
    $installmentAmount = round($totalAmount / $installmentCount, 2);
}

function loan_update_collection_link_count(PDO $pdo, int $installmentId): int
{
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE installment_id = :installment_id');
    }

    $stmt->execute(['installment_id' => $installmentId]);
    return (int) $stmt->fetchColumn();
}

function loan_update_rebuild_unpaid_schedule(
    PDO $pdo,
    int $loanId,
    array $loan,
    float $newTotalAmount,
    int $requestedInstallmentCount,
    string $frequency,
    bool $useRoundedInstallment,
    float $roundedInstallmentAmount,
    bool $rescheduleFromTomorrow = false
): array {
    $collectionTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE loan_id = :loan_id');
    $collectionTotalStmt->execute(['loan_id' => $loanId]);
    $collectedTotal = round((float) $collectionTotalStmt->fetchColumn(), 2);

    if ($newTotalAmount + 0.009 < $collectedTotal) {
        throw new RuntimeException('Total repayable cannot be less than the amount already collected.');
    }

    $rowsStmt = $pdo->prepare(
        "SELECT *
         FROM loan_installments
         WHERE loan_id = :loan_id
         ORDER BY installment_no ASC, due_date ASC, id ASC
         FOR UPDATE"
    );
    $rowsStmt->execute(['loan_id' => $loanId]);
    $rows = $rowsStmt->fetchAll();

    $unpaidRows = [];
    $paidOrLinkedCount = 0;
    $maxInstallmentNo = 0;
    $lastDueDate = (string) (($loan['end_date'] ?? '') ?: ($loan['first_due_date'] ?? ''));
    foreach ($rows as $row) {
        $maxInstallmentNo = max($maxInstallmentNo, (int) ($row['installment_no'] ?? 0));
        $rowDueDate = (string) ($row['due_date'] ?? '');
        if ($rowDueDate !== '' && ($lastDueDate === '' || $rowDueDate > $lastDueDate)) {
            $lastDueDate = $rowDueDate;
        }

        $paidAmount = round((float) ($row['paid_amount'] ?? 0), 2);
        $dueAmount = round((float) ($row['due_amount'] ?? 0), 2);
        if ($dueAmount > $paidAmount + 0.009) {
            $unpaidRows[] = $row;
        } elseif ($paidAmount > 0 || loan_update_collection_link_count($pdo, (int) $row['id']) > 0) {
            $paidOrLinkedCount++;
        }
    }

    if ($lastDueDate === '') {
        $issuedDate = (string) (($loan['issued_date'] ?? '') ?: ($loan['start_date'] ?? today()));
        $lastDueDate = next_collectible_date($pdo, (new DateTimeImmutable($issuedDate))->add(new DateInterval('P1D'))->format('Y-m-d'));
    }

    $newOutstanding = round($newTotalAmount - $collectedTotal, 2);
    $deletedExisting = 0;
    $insertedNew = 0;
    $updatedExisting = 0;

    if ($newOutstanding <= 0.009) {
        $deleteStmt = $pdo->prepare('DELETE FROM loan_installments WHERE id = :id AND loan_id = :loan_id');
        $settleStmt = $pdo->prepare(
            "UPDATE loan_installments
             SET due_amount = :due_amount,
                 status = 'paid'
             WHERE id = :id
               AND loan_id = :loan_id"
        );

        foreach ($unpaidRows as $row) {
            $rowId = (int) $row['id'];
            $paidAmount = round((float) ($row['paid_amount'] ?? 0), 2);
            if ($paidAmount <= 0 && loan_update_collection_link_count($pdo, $rowId) === 0) {
                $deleteStmt->execute(['id' => $rowId, 'loan_id' => $loanId]);
                $deletedExisting += $deleteStmt->rowCount();
                continue;
            }

            $settleStmt->execute([
                'due_amount' => $paidAmount,
                'id' => $rowId,
                'loan_id' => $loanId,
            ]);
            $updatedExisting++;
        }

        $remainingEndDateStmt = $pdo->prepare(
            "SELECT COALESCE(
                (SELECT MAX(due_date) FROM loan_installments WHERE loan_id = :installment_loan_id),
                (SELECT MAX(collected_on) FROM collections WHERE loan_id = :collection_loan_id),
                :fallback_end_date
            )"
        );
        $remainingEndDateStmt->execute([
            'installment_loan_id' => $loanId,
            'collection_loan_id' => $loanId,
            'fallback_end_date' => today(),
        ]);
        $settledEndDate = (string) ($remainingEndDateStmt->fetchColumn() ?: today());

        return [
            'new_outstanding' => 0.0,
            'installment_count' => max($paidOrLinkedCount + $updatedExisting, 0),
            'installment_amount' => 0.0,
            'end_date' => $settledEndDate,
            'updated_existing' => $updatedExisting,
            'inserted_new' => 0,
            'deleted_existing' => $deletedExisting,
            'collected_total' => $collectedTotal,
        ];
    }

    if ($useRoundedInstallment) {
        if ($roundedInstallmentAmount > $newOutstanding) {
            throw new RuntimeException('Change installment amount cannot be greater than the unpaid balance.');
        }
        $targetUnpaidCount = max((int) ceil($newOutstanding / $roundedInstallmentAmount), 1);
        $standardInstallmentAmount = $roundedInstallmentAmount;
    } else {
        $targetUnpaidCount = max($requestedInstallmentCount - $paidOrLinkedCount, 1);
        $standardInstallmentAmount = round($newOutstanding / $targetUnpaidCount, 2);
    }

    $protectedUnpaidCount = 0;
    foreach ($unpaidRows as $row) {
        if (round((float) ($row['paid_amount'] ?? 0), 2) > 0 || loan_update_collection_link_count($pdo, (int) $row['id']) > 0) {
            $protectedUnpaidCount++;
        }
    }
    $targetUnpaidCount = max($targetUnpaidCount, $protectedUnpaidCount, 1);
    if ($targetUnpaidCount > 2000) {
        throw new RuntimeException('Generated installment schedule is too long. Increase the changed installment amount or reduce the timeframe.');
    }
    if ($useRoundedInstallment && $targetUnpaidCount > 1 && round($roundedInstallmentAmount * ($targetUnpaidCount - 1), 2) >= $newOutstanding) {
        throw new RuntimeException('Change installment amount is too high for the remaining protected schedule.');
    }
    if (!$useRoundedInstallment) {
        $standardInstallmentAmount = round($newOutstanding / $targetUnpaidCount, 2);
    }

    $slots = array_map(static function (array $row): array {
        return [
            'kind' => 'existing',
            'id' => (int) $row['id'],
            'installment_no' => (int) $row['installment_no'],
            'due_date' => (string) $row['due_date'],
            'paid_amount' => round((float) ($row['paid_amount'] ?? 0), 2),
        ];
    }, $unpaidRows);

    if ($targetUnpaidCount < count($slots)) {
        $removeSlots = array_slice($slots, $targetUnpaidCount);
        $slots = array_slice($slots, 0, $targetUnpaidCount);
        $deleteStmt = $pdo->prepare('DELETE FROM loan_installments WHERE id = :id AND loan_id = :loan_id');
        foreach ($removeSlots as $slot) {
            if ((float) $slot['paid_amount'] > 0 || loan_update_collection_link_count($pdo, (int) $slot['id']) > 0) {
                throw new RuntimeException('Cannot shorten the schedule because a removed installment already has payment history.');
            }
            $deleteStmt->execute(['id' => (int) $slot['id'], 'loan_id' => $loanId]);
            $deletedExisting += $deleteStmt->rowCount();
        }
    }

    if ($targetUnpaidCount > count($slots)) {
        $interval = frequency_interval($frequency);
        $cursor = new DateTimeImmutable($lastDueDate);
        while (count($slots) < $targetUnpaidCount) {
            $candidate = next_collectible_date($pdo, $cursor->add($interval)->format('Y-m-d'));
            $maxInstallmentNo++;
            $slots[] = [
                'kind' => 'new',
                'id' => 0,
                'installment_no' => $maxInstallmentNo,
                'due_date' => $candidate,
                'paid_amount' => 0.0,
            ];
            $cursor = new DateTimeImmutable($candidate);
        }
    }

    if ($rescheduleFromTomorrow) {
        $interval = frequency_interval($frequency);
        $cursor = new DateTimeImmutable(next_collectible_date(
            $pdo,
            (new DateTimeImmutable(today()))->add(new DateInterval('P1D'))->format('Y-m-d')
        ));
        foreach ($slots as $index => $slot) {
            $candidate = $index === 0
                ? $cursor->format('Y-m-d')
                : next_collectible_date($pdo, $cursor->add($interval)->format('Y-m-d'));
            $slots[$index]['due_date'] = $candidate;
            $cursor = new DateTimeImmutable($candidate);
        }
    }

    $updateStmt = $pdo->prepare(
        'UPDATE loan_installments
         SET due_amount = :due_amount,
             status = :status
         WHERE id = :id
           AND loan_id = :loan_id'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO loan_installments
            (loan_id, installment_no, due_date, due_amount, paid_amount, status, is_flexible_adjustment, source_payment_ref)
         VALUES
            (:loan_id, :installment_no, :due_date, :due_amount, 0, :status, 0, :source_payment_ref)'
    );

    $remainingToAllocate = $newOutstanding;
    $slotCount = count($slots);
    $lastDueDate = '';
    foreach ($slots as $index => $slot) {
        $isLast = $index === ($slotCount - 1);
        $balanceForSlot = $isLast ? round($remainingToAllocate, 2) : round($standardInstallmentAmount, 2);
        if ($balanceForSlot <= 0) {
            throw new RuntimeException('Edited schedule created an invalid installment amount.');
        }

        $dueDate = (string) $slot['due_date'];
        $paidAmount = round((float) $slot['paid_amount'], 2);
        $dueAmount = round($paidAmount + $balanceForSlot, 2);
        $statusForInstallment = installment_status_for_due_date($dueDate, $dueAmount, $paidAmount);

        if ((string) $slot['kind'] === 'existing') {
            $updateStmt->execute([
                'due_amount' => $dueAmount,
                'status' => $statusForInstallment,
                'id' => (int) $slot['id'],
                'loan_id' => $loanId,
            ]);
            $updatedExisting++;
        } else {
            $insertStmt->execute([
                'loan_id' => $loanId,
                'installment_no' => (int) $slot['installment_no'],
                'due_date' => $dueDate,
                'due_amount' => $dueAmount,
                'status' => $statusForInstallment,
                'source_payment_ref' => 'loan_edit',
            ]);
            $insertedNew++;
        }

        if ($lastDueDate === '' || $dueDate > $lastDueDate) {
            $lastDueDate = $dueDate;
        }
        $remainingToAllocate = round($remainingToAllocate - $balanceForSlot, 2);
    }

    return [
        'new_outstanding' => $newOutstanding,
        'installment_count' => $paidOrLinkedCount + $slotCount,
        'installment_amount' => $standardInstallmentAmount,
        'end_date' => $lastDueDate,
        'updated_existing' => $updatedExisting,
        'inserted_new' => $insertedNew,
        'deleted_existing' => $deletedExisting,
        'collected_total' => $collectedTotal,
    ];
}

function loan_update_public_error_message(Throwable $e): string
{
    $message = trim($e->getMessage());
    $publicMessages = [
        'Total repayable cannot be less than the amount already collected.',
        'Change installment amount cannot be greater than the unpaid balance.',
        'Generated installment schedule is too long. Increase the changed installment amount or reduce the timeframe.',
        'Change installment amount is too high for the remaining protected schedule.',
        'Cannot shorten the schedule because a removed installment already has payment history.',
        'Edited schedule created an invalid installment amount.',
        'Loan not found.',
        'You do not have permission to extend collected loans.',
        'Invalid loan for scheduling.',
        'Invalid next payment date.',
        'Next payment date must be after today.',
        'No pending installment available to schedule.',
        'Invalid installment due date.',
        'Could not find the next available collection date.',
    ];

    return in_array($message, $publicMessages, true)
        ? $message
        : 'Failed to update loan. Please try again.';
}

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
        $storedIssuedDate = (string) ($loan['issued_date'] ?? '');
        $fallbackStartDate = $storedIssuedDate !== '' ? $storedIssuedDate : (string) ($loan['start_date'] ?? today());
        $firstDueDate = (new DateTimeImmutable($fallbackStartDate))->add(new DateInterval('P1D'))->format('Y-m-d');
    }
    $firstDueDate = next_collectible_date($pdo, $firstDueDate);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE loan_id = :loan_id');
    $countStmt->execute(['loan_id' => $loanId]);
    $collectionsCount = (int) $countStmt->fetchColumn();

    $outstandingStmt = $pdo->prepare('SELECT COALESCE(SUM(due_amount - paid_amount), 0) FROM loan_installments WHERE loan_id = :loan_id');
    $outstandingStmt->execute(['loan_id' => $loanId]);
    $currentOutstanding = (float) $outstandingStmt->fetchColumn();
    $hasLegacyPreCollected = $collectionsCount === 0 && ($currentOutstanding + 0.009) < (float) $loan['total_amount'];
    $repaymentLocked = $collectionsCount > 0 || $hasLegacyPreCollected;

    $extendsCollectedLoan = $repaymentLocked && (
        $principal > (float) $loan['principal_amount'] + 0.009
        || $totalAmount > (float) $loan['total_amount'] + 0.009
    );
    if ($extendsCollectedLoan && !$canExtendLoan) {
        throw new RuntimeException('You do not have permission to extend collected loans.');
    }

    $loanInterestRateType = normalize_interest_rate_type((string) ($loan['interest_rate_type'] ?? 'amount_based'));
    $loanInterestRateMonths = normalize_interest_rate_months((int) ($loan['interest_rate_months'] ?? 1));
    $loanTimeframeValue = match ((string) $loan['installment_frequency']) {
        'weekly' => (int) $loan['installment_count'] * 7,
        'monthly' => (int) $loan['installment_count'],
        default => (int) $loan['installment_count'],
    };
    $loanTimeframeUnit = (string) $loan['installment_frequency'] === 'monthly' ? 'months' : 'days';
    $loanIssuedDate = (string) ($loan['issued_date'] ?? '');
    if ($loanIssuedDate === '') {
        $loanIssuedDate = (string) ($loan['start_date'] ?? '');
    }
    $structureUnchanged =
        abs((float) $loan['principal_amount'] - $principal) < 0.005
        && abs((float) $loan['interest_rate'] - $interestRate) < 0.005
        && $loanIssuedDate === $issuedDate
        && $loanInterestRateType === $interestRateType
        && ($loanInterestRateType !== 'monthly' || $loanInterestRateMonths === $interestRateMonths)
        && (string) $loan['installment_frequency'] === $frequency
        && $loanTimeframeValue === $timeframeValue
        && $loanTimeframeUnit === $timeframeUnit
        && !$useRoundedInstallment;

    if (!$repaymentLocked && $structureUnchanged) {
        $totalAmount = (float) $loan['total_amount'];
        $installmentCount = (int) $loan['installment_count'];
        $installmentAmount = (float) $loan['installment_amount'];
    }

    if ($repaymentLocked) {
        $shouldRescheduleReopenedLoan = (string) ($loan['status'] ?? 'active') === 'closed'
            && ($status === 'active' || $extendsCollectedLoan);

        $scheduleUpdate = loan_update_rebuild_unpaid_schedule(
            $pdo,
            $loanId,
            $loan,
            $totalAmount,
            $installmentCount,
            $frequency,
            $useRoundedInstallment,
            $roundedInstallmentAmount,
            $shouldRescheduleReopenedLoan
        );
        if ($shouldRescheduleReopenedLoan && (float) ($scheduleUpdate['new_outstanding'] ?? 0) > 0.009) {
            $status = 'active';
        }

        $updateLocked = $pdo->prepare(
            'UPDATE loans SET
                issued_date = :issued_date,
                principal_amount = :principal_amount,
                interest_rate = :interest_rate,
                interest_rate_type = :interest_rate_type,
                interest_rate_months = :interest_rate_months,
                total_amount = :total_amount,
                installment_frequency = :installment_frequency,
                installment_count = :installment_count,
                installment_amount = :installment_amount,
                end_date = :end_date,
                status = :status,
                notes = :notes
             WHERE id = :id'
        );
        $updateLocked->bindValue(':issued_date', $issuedDate, PDO::PARAM_STR);
        $updateLocked->bindValue(':principal_amount', $principal);
        $updateLocked->bindValue(':interest_rate', $interestRate);
        $updateLocked->bindValue(':interest_rate_type', $interestRateType, PDO::PARAM_STR);
        $updateLocked->bindValue(':interest_rate_months', $interestRateType === 'monthly' ? $interestRateMonths : 1, PDO::PARAM_INT);
        $updateLocked->bindValue(':total_amount', $totalAmount);
        $updateLocked->bindValue(':installment_frequency', $frequency, PDO::PARAM_STR);
        $updateLocked->bindValue(':installment_count', (int) $scheduleUpdate['installment_count'], PDO::PARAM_INT);
        $updateLocked->bindValue(':installment_amount', (float) $scheduleUpdate['installment_amount']);
        $updateLocked->bindValue(':end_date', (string) $scheduleUpdate['end_date'], PDO::PARAM_STR);
        $updateLocked->bindValue(':status', $status, PDO::PARAM_STR);
        $updateLocked->bindValue(':notes', $notes === '' ? null : $notes, $notes === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $updateLocked->bindValue(':id', $loanId, PDO::PARAM_INT);
        $updateLocked->execute();
    } else {
        $firstDueDate = next_collectible_date($pdo, $issuedDateObj->add(new DateInterval('P1D'))->format('Y-m-d'));

        $updateLoan = $pdo->prepare(
            'UPDATE loans SET
                customer_id = :customer_id,
                issued_date = :issued_date,
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
        $updateLoan->bindValue(':issued_date', $issuedDate, PDO::PARAM_STR);
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
    }

    if ($canEditAssignment) {
        $assignStmt = $pdo->prepare('UPDATE loans SET assigned_user_id = :assigned_user_id WHERE id = :loan_id');
        if ($assignedUserId === null) {
            $assignStmt->bindValue(':assigned_user_id', null, PDO::PARAM_NULL);
        } else {
            $assignStmt->bindValue(':assigned_user_id', $assignedUserId, PDO::PARAM_INT);
        }
        $assignStmt->bindValue(':loan_id', $loanId, PDO::PARAM_INT);
        $assignStmt->execute();
    }

    $scheduledInstallment = null;
    $scheduleSkippedNoPending = false;
    if ($scheduleNextPayment) {
        $pendingScheduleStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM loan_installments
             WHERE loan_id = :loan_id
               AND status IN ('pending', 'partial', 'overdue')"
        );
        $pendingScheduleStmt->execute(['loan_id' => $loanId]);
        $pendingScheduleCount = (int) $pendingScheduleStmt->fetchColumn();

        if ($pendingScheduleCount > 0) {
            $scheduledInstallment = schedule_next_installment_date($pdo, $loanId, $nextPaymentDateInput);
        } else {
            $scheduleSkippedNoPending = true;
        }
    }

    $pdo->commit();
    $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
    log_activity($pdo, 'loan.updated', 'Loan updated: ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'customer_id' => $customerId,
        'issued_date' => $issuedDate,
        'assigned_user_id' => $canEditAssignment ? $assignedUserId : (int) ($loan['assigned_user_id'] ?? 0),
        'interest_rate_type' => $interestRateType,
        'interest_rate_months' => $interestRateType === 'monthly' ? $interestRateMonths : 1,
        'status' => $status,
        'repayment_locked' => $repaymentLocked ? 1 : 0,
        'legacy_pre_collected' => $hasLegacyPreCollected ? 1 : 0,
        'schedule_next_payment' => $scheduleNextPayment ? 1 : 0,
        'scheduled_installment_id' => (int) ($scheduledInstallment['installment_id'] ?? 0),
        'scheduled_to_date' => (string) ($scheduledInstallment['to_due_date'] ?? ''),
        'schedule_skipped_no_pending' => $scheduleSkippedNoPending ? 1 : 0,
    ]);
    if ($scheduleNextPayment && $scheduledInstallment !== null && (bool) ($scheduledInstallment['changed'] ?? false)) {
        set_flash('success', 'Loan updated. Next payment scheduled for ' . display_date((string) $scheduledInstallment['to_due_date']) . '.');
    } elseif ($scheduleNextPayment && $scheduleSkippedNoPending) {
        set_flash('success', 'Loan updated. No pending installments available to schedule.');
    } elseif ($repaymentLocked) {
        set_flash('success', 'Loan updated. Existing collections were preserved and the unpaid balance was recalculated.');
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
    set_flash('error', loan_update_public_error_message($e));
}

redirect('pages/loan_edit.php?loan_id=' . $loanId);
