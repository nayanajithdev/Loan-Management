<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/profile.php');
}

$current = current_user();
if (!$current) {
    redirect('login.php');
}

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    set_flash('error', 'All password fields are required.');
    redirect('pages/profile.php');
}

if ($newPassword !== $confirmPassword) {
    set_flash('error', 'New passwords do not match.');
    redirect('pages/profile.php');
}

if (strlen($newPassword) < 6) {
    set_flash('error', 'New password must be at least 6 characters.');
    redirect('pages/profile.php');
}

$userStmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => (int) $current['id']]);
$user = $userStmt->fetch();
if (!$user) {
    logout_user();
    set_flash('error', 'Your account was removed. Please login again.');
    redirect('login.php');
}

if (!password_verify($currentPassword, (string) $user['password_hash'])) {
    set_flash('error', 'Current password is incorrect.');
    redirect('pages/profile.php');
}

$updateStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
$updateStmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'id' => (int) $current['id'],
]);

log_activity($pdo, 'profile.password_changed', 'User changed account password.', [
    'user_id' => (int) $current['id'],
]);

set_flash('success', 'Password updated successfully.');
redirect('pages/profile.php');
