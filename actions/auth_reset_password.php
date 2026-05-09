<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('forgot_password.php');
}
require_csrf('forgot_password.php');

$token = trim((string) ($_POST['token'] ?? ''));
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($token === '') {
    set_flash('error', 'Reset token is missing.');
    redirect('forgot_password.php');
}

if ($newPassword === '' || $confirmPassword === '') {
    set_flash('error', 'Please fill all password fields.');
    redirect('reset_password.php?token=' . urlencode($token));
}

if ($newPassword !== $confirmPassword) {
    set_flash('error', 'Passwords do not match.');
    redirect('reset_password.php?token=' . urlencode($token));
}

if (
    strlen($newPassword) < 8
    || preg_match('/[A-Za-z]/', $newPassword) !== 1
    || preg_match('/\d/', $newPassword) !== 1
) {
    set_flash('error', 'Password must be at least 8 characters and include at least one letter and one number.');
    redirect('reset_password.php?token=' . urlencode($token));
}

$tokenRow = password_reset_row_by_token($pdo, $token);
if (!$tokenRow) {
    set_flash('error', 'This reset link is invalid or expired.');
    redirect('forgot_password.php');
}

if ((string) ($tokenRow['status'] ?? 'active') !== 'active') {
    set_flash('error', 'Your account is inactive. Please contact owner.');
    redirect('login.php');
}

$userId = (int) $tokenRow['user_id'];
$updateStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
$updateStmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'id' => $userId,
]);

mark_password_reset_token_used($pdo, (int) $tokenRow['id'], $userId);

log_activity($pdo, 'auth.password_reset_completed', 'Password reset completed.', [
    'user_id' => $userId,
    'username' => (string) ($tokenRow['username'] ?? ''),
], $userId);

set_flash('success', 'Password updated. You can now login.');
redirect('login.php');
