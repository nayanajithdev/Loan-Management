<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}
require_csrf('pages/loans.php');

require_permission('loans.delete', 'pages/loans.php');

$loanId = (int) ($_POST['loan_id'] ?? 0);
if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

try {
    $pdo->beginTransaction();

    $loanStmt = $pdo->prepare('SELECT id, loan_number FROM loans WHERE id = :id FOR UPDATE');
    $loanStmt->execute(['id' => $loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    $collectionCountStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE loan_id = :loan_id');
    $collectionCountStmt->execute(['loan_id' => $loanId]);
    $collectionCount = (int) $collectionCountStmt->fetchColumn();
    if ($collectionCount > 0) {
        throw new RuntimeException('This loan has collections and cannot be deleted.');
    }

    $deleteInstallmentsStmt = $pdo->prepare('DELETE FROM loan_installments WHERE loan_id = :loan_id');
    $deleteInstallmentsStmt->execute(['loan_id' => $loanId]);

    $deleteLoanStmt = $pdo->prepare('DELETE FROM loans WHERE id = :loan_id');
    $deleteLoanStmt->execute(['loan_id' => $loanId]);

    $pdo->commit();

    $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
    log_activity($pdo, 'loan.deleted', 'Loan deleted: ' . $loanNumber . '.', [
        'loan_id' => $loanId,
    ]);
    set_flash('success', 'Loan deleted successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e->getMessage();
    if ($msg === 'This loan has collections and cannot be deleted.') {
        set_flash('error', 'Cannot delete this loan because it already has collections.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }

    log_activity($pdo, 'loan.delete_failed', 'Loan delete failed.', [
        'loan_id' => $loanId,
        'reason' => $msg,
    ]);
    set_flash('error', 'Failed to delete loan. Please try again.');
}

redirect('pages/loans.php');

