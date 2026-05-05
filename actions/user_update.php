<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin'], 'index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/users.php');
}
require_csrf('pages/users.php');

$userId = (int) ($_POST['user_id'] ?? 0);
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$role = trim((string) ($_POST['role'] ?? 'collector_l1'));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($userId <= 0 || $fullName === '' || $username === '') {
    set_flash('error', 'Required fields are missing.');
    redirect('pages/users.php');
}

$current = current_user();
if (!$current) {
    redirect('login.php');
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

if ($targetRole === 'superadmin') {
    $role = 'superadmin';
} else {
    if (!in_array($role, ['admin', 'collector_l1', 'collector_l2', 'collector'], true)) {
        set_flash('error', 'Invalid role selected.');
        redirect('pages/users.php?edit_user=' . $userId);
    }
}

if ($currentRole === 'admin' && $targetRole === 'superadmin') {
    set_flash('error', 'Manager cannot edit owner.');
    redirect('pages/users.php');
}

$existsStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
$existsStmt->execute([
    'username' => $username,
    'id' => $userId,
]);
if ($existsStmt->fetch()) {
    set_flash('error', 'Username already exists.');
    redirect('pages/users.php?edit_user=' . $userId);
}

if ($password !== '' || $confirmPassword !== '') {
    if ($password !== $confirmPassword) {
        set_flash('error', 'Passwords do not match.');
        redirect('pages/users.php?edit_user=' . $userId);
    }

    if (strlen($password) < 6) {
        set_flash('error', 'Password must be at least 6 characters.');
        redirect('pages/users.php?edit_user=' . $userId);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE users SET full_name = :full_name, username = :username, role = :role, password_hash = :password_hash WHERE id = :id'
    );
    $updateStmt->execute([
        'full_name' => $fullName,
        'username' => $username,
        'role' => $role,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);
} else {
    $updateStmt = $pdo->prepare(
        'UPDATE users SET full_name = :full_name, username = :username, role = :role WHERE id = :id'
    );
    $updateStmt->execute([
        'full_name' => $fullName,
        'username' => $username,
        'role' => $role,
        'id' => $userId,
    ]);
}

if ((int) $current['id'] === $userId) {
    $_SESSION['auth_user']['full_name'] = $fullName;
    $_SESSION['auth_user']['username'] = $username;
    $_SESSION['auth_user']['role'] = $role;
}

log_activity($pdo, 'user.updated', 'User updated: ' . $fullName . '.', [
    'user_id' => $userId,
    'old_username' => (string) $targetUser['username'],
    'new_username' => $username,
    'old_role' => role_display_name((string) $targetUser['role']),
    'new_role' => role_display_name($role),
    'password_changed' => ($password !== '' || $confirmPassword !== '') ? 1 : 0,
]);

set_flash('success', 'User updated successfully.');
redirect('pages/users.php?edit_user=' . $userId);
