<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin'], 'index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/users.php');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$role = trim((string) ($_POST['role'] ?? 'collector_l1'));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($fullName === '' || $username === '' || $password === '') {
    set_flash('error', 'All fields are required.');
    redirect('pages/users.php');
}

if (!in_array($role, ['admin', 'collector_l1', 'collector_l2', 'collector'], true)) {
    set_flash('error', 'Invalid role selected.');
    redirect('pages/users.php');
}

if ($password !== $confirmPassword) {
    set_flash('error', 'Passwords do not match.');
    redirect('pages/users.php');
}

if (strlen($password) < 6) {
    set_flash('error', 'Password must be at least 6 characters.');
    redirect('pages/users.php');
}

$existsStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$existsStmt->execute(['username' => $username]);
if ($existsStmt->fetch()) {
    set_flash('error', 'Username already exists.');
    redirect('pages/users.php');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$insertStmt = $pdo->prepare(
    'INSERT INTO users (full_name, username, password_hash, role)
     VALUES (:full_name, :username, :password_hash, :role)'
);
$insertStmt->execute([
    'full_name' => $fullName,
    'username' => $username,
    'password_hash' => $passwordHash,
    'role' => $role,
]);
$createdUserId = (int) $pdo->lastInsertId();

log_activity($pdo, 'user.created', 'User created: ' . $fullName . '.', [
    'user_id' => $createdUserId,
    'username' => $username,
    'role' => role_display_name($role),
]);

set_flash('success', 'User created successfully.');
redirect('pages/users.php');
