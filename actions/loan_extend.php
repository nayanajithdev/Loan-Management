<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loan_extend.php');
}
require_csrf('pages/loan_extend.php');

require_permission('loans.edit', 'pages/loans.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
$extendType = trim((string) ($_POST['extend_type'] ?? 'amount'));
$extendAmount = round((float) ($_POST['extend_amount'] ?? 0), 2);
$extendEndDate = $extendType === 'amount_date';
$newEndDateInput = trim((string) ($_POST['new_end_date'] ?? ''));
$useRoundedInstallment = (int) ($_POST['use_rounded_installment'] ?? 0) === 1;
$roundedInstallmentAmount = round((float) ($_POST['rounded_installment_amount'] ?? 0), 2);
$note = trim((string) ($_POST['note'] ?? ''));

$returnPath = 'pages/loan_extend.php' . ($loanId > 0 ? '?loan_id=' . $loanId : '');

if ($loanId <= 0) {
    set_flash('error', 'Please select a loan to extend.');
    redirect('pages/loan_extend.php');
}

if (!in_array($extendType, ['amount', 'amount_date'], true)) {
    set_flash('error', 'Invalid extend type.');
    redirect($returnPath);
}

if ($extendAmount <= 0) {
    set_flash('error', 'Extend amount must be greater than zero.');
    redirect($returnPath);
}

if ($useRoundedInstallment && $roundedInstallmentAmount <= 0) {
    set_flash('error', 'Rounded installment amount must be greater than zero.');
    redirect($returnPath);
}

$newEndDate = '';
if ($extendEndDate && !$useRoundedInstallment) {
    $newEndDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $newEndDateInput);
    if (!$newEndDateObj || $newEndDateObj->format('Y-m-d') !== $newEndDateInput) {
        set_flash('error', 'Invalid extend date.');
        redirect($returnPath);
    }
    $newEndDate = next_collectible_date($pdo, $newEndDateObj->format('Y-m-d'));
}

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare(
        "SELECT l.*, c.full_name
         FROM loans l
         JOIN customers c ON c.id = l.customer_id
         WHERE l.id = :id
         FOR UPDATE"
    );
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    if ((string) ($loan['status'] ?? '') === 'closed') {
        throw new RuntimeException('Closed loans cannot be extended.');
    }

    $pendingStmt = $pdo->prepare(
        "SELECT *
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND due_amount > paid_amount
           AND status IN ('pending', 'partial', 'overdue')
         ORDER BY due_date ASC, installment_no ASC
         FOR UPDATE"
    );
    $pendingStmt->execute(['loan_id' => $loanId]);
    $pendingRows = $pendingStmt->fetchAll();

    if (!$pendingRows) {
        throw new RuntimeException('This loan has no unpaid installments to extend.');
    }

    $scheduleMetaStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS installment_count,
            COALESCE(MAX(installment_no), 0) AS max_installment_no,
            MAX(due_date) AS max_due_date
         FROM loan_installments
         WHERE loan_id = :loan_id'
    );
    $scheduleMetaStmt->execute(['loan_id' => $loanId]);
    $scheduleMeta = $scheduleMetaStmt->fetch() ?: [];

    $currentInstallmentCount = (int) ($scheduleMeta['installment_count'] ?? 0);
    $maxInstallmentNo = (int) ($scheduleMeta['max_installment_no'] ?? 0);
    $currentEndDate = (string) (($loan['end_date'] ?? '') ?: ($scheduleMeta['max_due_date'] ?? ''));
    if ($currentEndDate === '') {
        throw new RuntimeException('Could not read the current loan end date.');
    }

    $currentOutstanding = 0.0;
    foreach ($pendingRows as $row) {
        $currentOutstanding += max(0.0, round((float) $row['due_amount'] - (float) $row['paid_amount'], 2));
    }
    $currentOutstanding = round($currentOutstanding, 2);

    $interestRateType = normalize_interest_rate_type((string) ($loan['interest_rate_type'] ?? 'amount_based'));
    $interestRateMonths = normalize_interest_rate_months((int) ($loan['interest_rate_months'] ?? 1));
    $additionalTotal = loan_total_amount(
        $extendAmount,
        (float) ($loan['interest_rate'] ?? 0),
        $interestRateType,
        $interestRateMonths
    );
    $newOutstanding = round($currentOutstanding + $additionalTotal, 2);

    $slots = [];
    foreach ($pendingRows as $row) {
        $slots[] = [
            'kind' => 'existing',
            'id' => (int) $row['id'],
            'installment_no' => (int) $row['installment_no'],
            'due_date' => (string) $row['due_date'],
            'paid_amount' => round((float) $row['paid_amount'], 2),
        ];
    }

    $removedSlots = [];
    $addedDates = [];
    $generatedEndDateFromRoundedAmount = false;

    if ($useRoundedInstallment) {
        $targetSlotCount = max((int) ceil($newOutstanding / $roundedInstallmentAmount), 1);
        if ($targetSlotCount > 2000) {
            throw new RuntimeException('Generated installment schedule is too long. Increase the rounded installment amount.');
        }
        $generatedEndDateFromRoundedAmount = true;

        if ($targetSlotCount < count($slots)) {
            $removedSlots = array_slice($slots, $targetSlotCount);
            $slots = array_slice($slots, 0, $targetSlotCount);
        } elseif ($targetSlotCount > count($slots)) {
            $interval = frequency_interval((string) ($loan['installment_frequency'] ?? 'daily'));
            $cursor = new DateTimeImmutable($currentEndDate);
            while (count($slots) < $targetSlotCount) {
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
    } elseif ($extendEndDate) {
        if ($newEndDate <= $currentEndDate) {
            throw new RuntimeException('Extend date must be after the current loan end date.');
        }

        $interval = frequency_interval((string) ($loan['installment_frequency'] ?? 'daily'));
        $cursor = new DateTimeImmutable($currentEndDate);
        for ($guard = 0; $guard < 2000; $guard++) {
            $candidate = next_collectible_date($pdo, $cursor->add($interval)->format('Y-m-d'));
            if ($candidate > $newEndDate) {
                break;
            }
            $addedDates[] = $candidate;
            $cursor = new DateTimeImmutable($candidate);
        }

        if ($addedDates === [] || end($addedDates) !== $newEndDate) {
            $addedDates[] = $newEndDate;
        }

        $addedDates = array_values(array_unique($addedDates));
        sort($addedDates);
        foreach ($addedDates as $date) {
            $maxInstallmentNo++;
            $slots[] = [
                'kind' => 'new',
                'id' => 0,
                'installment_no' => $maxInstallmentNo,
                'due_date' => $date,
                'paid_amount' => 0.0,
            ];
        }
    }

    $slotCount = count($slots);
    if ($slotCount <= 0) {
        throw new RuntimeException('No installment slots available for extension.');
    }

    if ($useRoundedInstallment) {
        if ($roundedInstallmentAmount > $newOutstanding) {
            throw new RuntimeException('Rounded installment amount cannot be greater than the new unpaid balance.');
        }
        if ($slotCount > 1 && round($roundedInstallmentAmount * ($slotCount - 1), 2) >= $newOutstanding) {
            throw new RuntimeException('Rounded installment amount is too high for the selected schedule.');
        }
        $standardInstallmentAmount = $roundedInstallmentAmount;
    } else {
        $standardInstallmentAmount = round($newOutstanding / $slotCount, 2);
    }

    $updateInstallmentStmt = $pdo->prepare(
        'UPDATE loan_installments
         SET due_amount = :due_amount,
             status = :status
         WHERE id = :id
           AND loan_id = :loan_id'
    );
    $insertInstallmentStmt = $pdo->prepare(
        'INSERT INTO loan_installments
            (loan_id, installment_no, due_date, due_amount, paid_amount, status, is_flexible_adjustment, source_payment_ref)
         VALUES
            (:loan_id, :installment_no, :due_date, :due_amount, 0, :status, 0, :source_payment_ref)'
    );

    $remainingToAllocate = $newOutstanding;
    $updatedExisting = 0;
    $insertedNew = 0;
    $deletedExisting = 0;
    $lastDueDate = '';

    if ($removedSlots !== []) {
        $collectionLinkStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE installment_id = :installment_id');
        $deleteInstallmentStmt = $pdo->prepare('DELETE FROM loan_installments WHERE id = :id AND loan_id = :loan_id');
        foreach ($removedSlots as $slot) {
            $paidAmount = round((float) ($slot['paid_amount'] ?? 0), 2);
            if ($paidAmount > 0) {
                throw new RuntimeException('Cannot shorten the generated schedule because a later installment already has a payment.');
            }

            $collectionLinkStmt->execute(['installment_id' => (int) $slot['id']]);
            if ((int) $collectionLinkStmt->fetchColumn() > 0) {
                throw new RuntimeException('Cannot shorten the generated schedule because a later installment has a collection record.');
            }

            $deleteInstallmentStmt->execute([
                'id' => (int) $slot['id'],
                'loan_id' => $loanId,
            ]);
            $deletedExisting += $deleteInstallmentStmt->rowCount();
        }
    }

    foreach ($slots as $index => $slot) {
        $isLast = $index === ($slotCount - 1);
        $balanceForSlot = $isLast
            ? round($remainingToAllocate, 2)
            : round($standardInstallmentAmount, 2);

        if ($balanceForSlot <= 0) {
            throw new RuntimeException('Extension schedule created an invalid installment amount.');
        }

        $dueDate = (string) $slot['due_date'];
        $paidAmount = round((float) $slot['paid_amount'], 2);
        $dueAmount = round($paidAmount + $balanceForSlot, 2);
        $status = installment_status_for_due_date($dueDate, $dueAmount, $paidAmount);

        if ((string) $slot['kind'] === 'existing') {
            $updateInstallmentStmt->execute([
                'due_amount' => $dueAmount,
                'status' => $status,
                'id' => (int) $slot['id'],
                'loan_id' => $loanId,
            ]);
            $updatedExisting++;
        } else {
            $insertInstallmentStmt->execute([
                'loan_id' => $loanId,
                'installment_no' => (int) $slot['installment_no'],
                'due_date' => $dueDate,
                'due_amount' => $dueAmount,
                'status' => $status,
                'source_payment_ref' => 'loan_extend',
            ]);
            $insertedNew++;
        }

        if ($lastDueDate === '' || $dueDate > $lastDueDate) {
            $lastDueDate = $dueDate;
        }
        $remainingToAllocate = round($remainingToAllocate - $balanceForSlot, 2);
    }

    $newPrincipal = round((float) $loan['principal_amount'] + $extendAmount, 2);
    $newTotalAmount = round((float) $loan['total_amount'] + $additionalTotal, 2);
    $newInstallmentCount = $currentInstallmentCount - $deletedExisting + $insertedNew;
    $newEndDateValue = ($useRoundedInstallment || $extendEndDate || $generatedEndDateFromRoundedAmount) ? $lastDueDate : $currentEndDate;

    $updateLoanStmt = $pdo->prepare(
        "UPDATE loans
         SET principal_amount = :principal_amount,
             total_amount = :total_amount,
             installment_count = :installment_count,
             installment_amount = :installment_amount,
             end_date = :end_date,
             status = 'active'
         WHERE id = :loan_id"
    );
    $updateLoanStmt->execute([
        'principal_amount' => $newPrincipal,
        'total_amount' => $newTotalAmount,
        'installment_count' => $newInstallmentCount,
        'installment_amount' => $standardInstallmentAmount,
        'end_date' => $newEndDateValue,
        'loan_id' => $loanId,
    ]);

    $pdo->commit();

    log_activity($pdo, 'loan.extended', 'Loan extended: ' . (string) ($loan['loan_number'] ?? ('#' . $loanId)) . '.', [
        'loan_id' => $loanId,
        'extend_amount' => $extendAmount,
        'additional_total' => $additionalTotal,
        'old_principal' => round((float) $loan['principal_amount'], 2),
        'new_principal' => $newPrincipal,
        'old_total_amount' => round((float) $loan['total_amount'], 2),
        'new_total_amount' => $newTotalAmount,
        'old_outstanding' => $currentOutstanding,
        'new_outstanding' => $newOutstanding,
        'extended_end_date' => $extendEndDate ? 1 : 0,
        'extend_type' => $extendType,
        'extend_date_overridden_by_rounded_amount' => ($useRoundedInstallment && $extendEndDate) ? 1 : 0,
        'old_end_date' => $currentEndDate,
        'new_end_date' => $newEndDateValue,
        'updated_installments' => $updatedExisting,
        'inserted_installments' => $insertedNew,
        'deleted_installments' => $deletedExisting,
        'generated_end_date_from_rounded_amount' => $generatedEndDateFromRoundedAmount ? 1 : 0,
        'rounded_installment' => $useRoundedInstallment ? 1 : 0,
        'rounded_installment_amount' => $useRoundedInstallment ? $roundedInstallmentAmount : null,
        'note' => $note === '' ? null : $note,
    ]);

    set_flash('success', 'Loan extended successfully.');
    redirect('pages/loan_extend.php?loan_id=' . $loanId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_activity($pdo, 'loan.extend_failed', 'Loan extension failed.', [
        'loan_id' => $loanId,
        'extend_amount' => $extendAmount,
        'reason' => $e->getMessage(),
    ]);
    set_flash('error', $e->getMessage());
    redirect($returnPath);
}
