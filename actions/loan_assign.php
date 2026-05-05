<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/loans.php');
}

if (!can_manage_users()) {
    set_flash('error', 'Only Owner or Manager can assign loans.');
    redirect('pages/loans.php');
}

$loanId = (int) ($_POST['loan_id'] ?? 0);
$assignedUserIdRaw = trim((string) ($_POST['assigned_user_id'] ?? ''));
$assignedUserId = $assignedUserIdRaw === '' ? null : (int) $assignedUserIdRaw;

if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

$loanStmt = $pdo->prepare('SELECT id, loan_number FROM loans WHERE id = :id LIMIT 1');
$loanStmt->execute(['id' => $loanId]);
$loan = $loanStmt->fetch();
if (!$loan) {
    set_flash('error', 'Loan not found.');
    redirect('pages/loans.php');
}

$assignedUserName = 'Unassigned';
if ($assignedUserId !== null && $assignedUserId > 0) {
    $userStmt = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $assignedUserId]);
    $assignedUser = $userStmt->fetch();
    if (!$assignedUser) {
        set_flash('error', 'Selected user not found.');
        redirect('pages/loan_edit.php?loan_id=' . $loanId);
    }
    $assignedUserName = (string) $assignedUser['full_name'];
} else {
    $assignedUserId = null;
}

$updateStmt = $pdo->prepare('UPDATE loans SET assigned_user_id = :assigned_user_id WHERE id = :loan_id');
$updateStmt->bindValue(':assigned_user_id', $assignedUserId, $assignedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$updateStmt->bindValue(':loan_id', $loanId, PDO::PARAM_INT);
$updateStmt->execute();

log_activity($pdo, 'loan.assigned', 'Loan assignment updated: ' . (string) $loan['loan_number'] . ' -> ' . $assignedUserName . '.', [
    'loan_id' => $loanId,
    'loan_number' => (string) $loan['loan_number'],
    'assigned_user_id' => $assignedUserId,
    'assigned_user_name' => $assignedUserName,
]);

set_flash('success', 'Loan assignment updated.');
redirect('pages/loan_edit.php?loan_id=' . $loanId);
