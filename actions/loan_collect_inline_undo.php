<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('loans.collect_inline', 'pages/loans.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loans.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $assignedUserId = isset($loan['assigned_user_id']) ? (int) $loan['assigned_user_id'] : 0;
    if (is_collector_role($currentRole) && $assignedUserId > 0 && $assignedUserId !== $currentUserId) {
        throw new RuntimeException('You can only undo collections for loans assigned to you (or unassigned loans).');
    }

    $latestStmt = $pdo->prepare(
        'SELECT id, payment_ref, installment_id
         FROM collections
         WHERE loan_id = :loan_id
         ORDER BY created_at DESC, id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $latestStmt->execute(['loan_id' => $loanId]);
    $latest = $latestStmt->fetch();

    if (!$latest) {
        throw new RuntimeException('No collection found to undo.');
    }

    $paymentRef = trim((string) ($latest['payment_ref'] ?? ''));

    $affectedInstallmentIds = [];
    $latestInstallmentId = (int) ($latest['installment_id'] ?? 0);
    if ($latestInstallmentId > 0) {
        $affectedInstallmentIds[$latestInstallmentId] = true;
    }

    $deleteStmt = $pdo->prepare('DELETE FROM collections WHERE id = :id');
    $deleteStmt->execute(['id' => (int) $latest['id']]);

    if ($affectedInstallmentIds !== []) {
        $recalcStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(amount), 0) AS paid_total,
                MAX(collected_on) AS last_paid_on
             FROM collections
             WHERE installment_id = :installment_id"
        );
        $instFetchStmt = $pdo->prepare(
            'SELECT id, due_date, due_amount
             FROM loan_installments
             WHERE id = :id AND loan_id = :loan_id
             FOR UPDATE'
        );
        $updateInstStmt = $pdo->prepare(
            'UPDATE loan_installments
             SET paid_amount = :paid_amount, paid_on = :paid_on, status = :status
             WHERE id = :id'
        );

        foreach (array_keys($affectedInstallmentIds) as $instId) {
            $instFetchStmt->execute([
                'id' => $instId,
                'loan_id' => $loanId,
            ]);
            $inst = $instFetchStmt->fetch();
            if (!$inst) {
                continue;
            }

            $recalcStmt->execute(['installment_id' => $instId]);
            $recalc = $recalcStmt->fetch();
            $paidTotal = round((float) ($recalc['paid_total'] ?? 0), 2);
            $dueAmount = round((float) $inst['due_amount'], 2);
            $paidTotal = min($paidTotal, $dueAmount);

            $status = 'pending';
            $paidOn = null;

            if ($paidTotal >= $dueAmount && $dueAmount > 0) {
                $status = 'paid';
                $paidOn = (string) ($recalc['last_paid_on'] ?? null);
            } elseif ($paidTotal > 0) {
                $status = 'partial';
            } elseif ((string) $inst['due_date'] < today()) {
                $status = 'overdue';
            }

            $updateInstStmt->execute([
                'paid_amount' => $paidTotal,
                'paid_on' => $paidOn,
                'status' => $status,
                'id' => $instId,
            ]);
        }
    }

    $refreshStmt = $pdo->prepare(
        "UPDATE loan_installments
         SET status = 'overdue'
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial')
           AND due_date < CURDATE()
           AND paid_amount < due_amount"
    );
    $refreshStmt->execute(['loan_id' => $loanId]);

    $pendingCountStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM loan_installments
         WHERE loan_id = :loan_id
           AND status IN ('pending', 'partial', 'overdue')"
    );
    $pendingCountStmt->execute(['loan_id' => $loanId]);
    $pendingCount = (int) $pendingCountStmt->fetchColumn();

    if ($pendingCount === 0) {
        $statusStmt = $pdo->prepare("UPDATE loans SET status = 'closed' WHERE id = :loan_id");
        $statusStmt->execute(['loan_id' => $loanId]);
    } else {
        $statusStmt = $pdo->prepare("UPDATE loans SET status = 'active' WHERE id = :loan_id AND status <> 'defaulted'");
        $statusStmt->execute(['loan_id' => $loanId]);
    }

    $pdo->commit();

    $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
    log_activity($pdo, 'loan.inline_collection_undo', 'Latest inline collection undone for loan ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'payment_ref' => $paymentRef,
        'collection_rows' => 1,
    ]);
    set_flash('success', 'Latest collection undone.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity($pdo, 'loan.inline_collection_undo_failed', 'Undo inline collection failed: ' . $e->getMessage(), [
        'loan_id' => $loanId,
    ]);
    $error = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Failed to undo latest collection.';
    set_flash('error', $error);
}

redirect('pages/loan_edit.php?loan_id=' . $loanId . '#installment-cards');
