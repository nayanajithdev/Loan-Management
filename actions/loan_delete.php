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

$current = current_user();
$currentUserId = (int) ($current['id'] ?? 0);
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$userPasswordStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
$userPasswordStmt->execute(['id' => $currentUserId]);
$passwordHash = (string) ($userPasswordStmt->fetchColumn() ?: '');

if ($currentUserId <= 0 || $confirmPassword === '' || $passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    $failedAttempts = (int) ($_SESSION['loan_delete_password_failures'] ?? 0) + 1;
    $_SESSION['loan_delete_password_failures'] = $failedAttempts;

    log_activity($pdo, 'loan.delete_failed', 'Loan delete failed: password confirmation failed.', [
        'loan_id' => $loanId,
        'reason' => $failedAttempts >= 3 ? 'password_confirmation_failed_limit' : 'password_confirmation_failed',
        'failed_attempts' => $failedAttempts,
    ]);

    if ($failedAttempts >= 3) {
        force_logout_user_everywhere($pdo, $currentUserId);
        logout_user();
        header('Location: ' . url('login.php'));
        exit;
    }

    set_flash('error', 'Password confirmation failed. Loan was not deleted.');
    redirect('pages/loan_edit.php?loan_id=' . $loanId);
}

unset($_SESSION['loan_delete_password_failures']);

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

    $installmentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM loan_installments WHERE loan_id = :loan_id');
    $installmentCountStmt->execute(['loan_id' => $loanId]);
    $installmentCount = (int) $installmentCountStmt->fetchColumn();

    $deleteCollectionsStmt = $pdo->prepare('DELETE FROM collections WHERE loan_id = :loan_id');
    $deleteCollectionsStmt->execute(['loan_id' => $loanId]);
    $deletedCollections = $deleteCollectionsStmt->rowCount();

    $deleteInstallmentsStmt = $pdo->prepare('DELETE FROM loan_installments WHERE loan_id = :loan_id');
    $deleteInstallmentsStmt->execute(['loan_id' => $loanId]);
    $deletedInstallments = $deleteInstallmentsStmt->rowCount();

    $deleteLoanStmt = $pdo->prepare('DELETE FROM loans WHERE id = :loan_id');
    $deleteLoanStmt->execute(['loan_id' => $loanId]);

    $pdo->commit();

    $loanNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
    log_activity($pdo, 'loan.deleted', 'Loan deleted: ' . $loanNumber . '.', [
        'loan_id' => $loanId,
        'loan_number' => $loanNumber,
        'collection_count' => $collectionCount,
        'installment_count' => $installmentCount,
        'deleted_collections' => $deletedCollections,
        'deleted_installments' => $deletedInstallments,
    ]);
    set_flash('success', 'Loan deleted successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e->getMessage();
    log_activity($pdo, 'loan.delete_failed', 'Loan delete failed.', [
        'loan_id' => $loanId,
        'reason' => $msg,
    ]);
    set_flash('error', 'Failed to delete loan. Please try again.');
}

redirect('pages/loans.php');
