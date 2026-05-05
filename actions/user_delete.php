<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin'], 'index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/users.php');
}
require_csrf('pages/users.php');

$userId = (int) ($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    set_flash('error', 'Invalid user id.');
    redirect('pages/users.php');
}

$current = current_user();
if (!$current) {
    redirect('login.php');
}

if ((int) $current['id'] === $userId) {
    set_flash('error', 'You cannot delete your own account while logged in.');
    redirect('pages/users.php');
}

$targetStmt = $pdo->prepare('SELECT id, full_name, username, role FROM users WHERE id = :id LIMIT 1');
$targetStmt->execute(['id' => $userId]);
$targetUser = $targetStmt->fetch();

if (!$targetUser) {
    set_flash('error', 'User not found.');
    redirect('pages/users.php');
}

$currentRole = (string) $current['role'];
$targetRole = (string) $targetUser['role'];

if ($currentRole === 'admin' && $targetRole === 'superadmin') {
    set_flash('error', 'Manager cannot delete owner.');
    redirect('pages/users.php');
}

if ($targetRole === 'superadmin') {
    set_flash('error', 'Owner cannot be deleted (only one owner allowed).');
    redirect('pages/users.php');
}

$deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
$deleteStmt->execute(['id' => $userId]);

log_activity($pdo, 'user.deleted', 'User deleted: ' . (string) $targetUser['full_name'] . '.', [
    'user_id' => $userId,
    'username' => (string) $targetUser['username'],
    'role' => role_display_name((string) $targetUser['role']),
]);

set_flash('success', 'User deleted successfully.');
redirect('pages/users.php');
