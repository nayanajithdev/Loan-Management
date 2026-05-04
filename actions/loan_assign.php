<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}

if (!can_manage_users()) {
    set_flash('error', 'Only Superadmin or Admin can assign loans.');
    redirect('pages/loans.php');
}

$loanId = (int) ($_POST['loan_id'] ?? 0);
$assignedUserIdRaw = trim((string) ($_POST['assigned_user_id'] ?? ''));
$assignedUserId = $assignedUserIdRaw === '' ? null : (int) $assignedUserIdRaw;

if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

$loanStmt = $pdo->prepare('SELECT id FROM loans WHERE id = :id LIMIT 1');
$loanStmt->execute(['id' => $loanId]);
if (!$loanStmt->fetch()) {
    set_flash('error', 'Loan not found.');
    redirect('pages/loans.php');
}

if ($assignedUserId !== null && $assignedUserId > 0) {
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $assignedUserId]);
    if (!$userStmt->fetch()) {
        set_flash('error', 'Selected user not found.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }
} else {
    $assignedUserId = null;
}

$updateStmt = $pdo->prepare('UPDATE loans SET assigned_user_id = :assigned_user_id WHERE id = :id');
$updateStmt->bindValue(':assigned_user_id', $assignedUserId, $assignedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$updateStmt->bindValue(':id', $loanId, PDO::PARAM_INT);
$updateStmt->execute();

set_flash('success', 'Loan assignment updated.');
redirect('pages/loan_edit.php?loan_id=' . $loanId);
