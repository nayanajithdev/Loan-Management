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
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$role = trim((string) ($_POST['role'] ?? 'collector_l1'));
$status = trim((string) ($_POST['status'] ?? 'active'));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($userId <= 0 || $fullName === '' || $username === '' || $email === '') {
    set_flash('error', 'Required fields are missing.');
    redirect('pages/users.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please enter a valid email.');
    redirect('pages/users.php?edit_user=' . $userId);
}

$current = current_user();
if (!$current) {
    redirect('login.php');
}

$targetStmt = $pdo->prepare('SELECT id, full_name, username, email, role, status FROM users WHERE id = :id LIMIT 1');
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

if (((string) $currentRole) !== 'superadmin') {
    $status = (string) $targetUser['status'];
}
if (!in_array($status, ['active', 'inactive'], true)) {
    $status = (string) $targetUser['status'];
}
if ($targetRole === 'superadmin' || ((int) $current['id'] === $userId)) {
    $status = 'active';
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

$emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
$emailStmt->execute([
    'email' => $email,
    'id' => $userId,
]);
if ($emailStmt->fetch()) {
    set_flash('error', 'Email already exists.');
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
        'UPDATE users SET full_name = :full_name, username = :username, email = :email, role = :role, status = :status, password_hash = :password_hash WHERE id = :id'
    );
    $updateStmt->execute([
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);
} else {
    $updateStmt = $pdo->prepare(
        'UPDATE users SET full_name = :full_name, username = :username, email = :email, role = :role, status = :status WHERE id = :id'
    );
    $updateStmt->execute([
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'id' => $userId,
    ]);
}

if ((int) $current['id'] === $userId) {
    $_SESSION['auth_user']['full_name'] = $fullName;
    $_SESSION['auth_user']['username'] = $username;
    $_SESSION['auth_user']['email'] = $email;
    $_SESSION['auth_user']['role'] = $role;
    $_SESSION['auth_user']['status'] = $status;
}

log_activity($pdo, 'user.updated', 'User updated: ' . $fullName . '.', [
    'user_id' => $userId,
    'old_username' => (string) $targetUser['username'],
    'new_username' => $username,
    'old_email' => (string) ($targetUser['email'] ?? ''),
    'new_email' => $email,
    'old_role' => role_display_name((string) $targetUser['role']),
    'new_role' => role_display_name($role),
    'old_status' => (string) ($targetUser['status'] ?? 'active'),
    'new_status' => $status,
    'password_changed' => ($password !== '' || $confirmPassword !== '') ? 1 : 0,
]);

set_flash('success', 'User updated successfully.');
redirect('pages/users.php?edit_user=' . $userId);
