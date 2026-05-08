<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin'], 'index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/users.php');
}
require_csrf('pages/users.php');

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$role = trim((string) ($_POST['role'] ?? 'collector_l1'));
$status = trim((string) ($_POST['status'] ?? 'active'));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($fullName === '' || $username === '' || $email === '' || $password === '') {
    set_flash('error', 'All fields are required.');
    redirect('pages/users.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please enter a valid email.');
    redirect('pages/users.php');
}

if (!in_array($role, ['admin', 'collector_l1', 'collector_l2', 'collector'], true)) {
    set_flash('error', 'Invalid role selected.');
    redirect('pages/users.php');
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}
$current = current_user();
if (!$current || (string) ($current['role'] ?? '') !== 'superadmin') {
    $status = 'active';
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

$emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$emailStmt->execute(['email' => $email]);
if ($emailStmt->fetch()) {
    set_flash('error', 'Email already exists.');
    redirect('pages/users.php');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$insertStmt = $pdo->prepare(
    'INSERT INTO users (full_name, username, email, password_hash, role, status)
     VALUES (:full_name, :username, :email, :password_hash, :role, :status)'
);
$insertStmt->execute([
    'full_name' => $fullName,
    'username' => $username,
    'email' => $email,
    'password_hash' => $passwordHash,
    'role' => $role,
    'status' => $status,
]);
$createdUserId = (int) $pdo->lastInsertId();

log_activity($pdo, 'user.created', 'User created: ' . $fullName . '.', [
    'user_id' => $createdUserId,
    'username' => $username,
    'email' => $email,
    'role' => role_display_name($role),
    'status' => $status,
]);

set_flash('success', 'User created successfully.');
redirect('pages/users.php');
