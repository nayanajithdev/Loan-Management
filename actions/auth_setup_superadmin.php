<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('setup_superadmin.php');
}
require_csrf('setup_superadmin.php');

if (has_superadmin($pdo)) {
    log_activity($pdo, 'auth.owner_setup_blocked', 'Owner setup blocked because owner already exists.');
    set_flash('error', 'Owner already exists.');
    redirect('login.php');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($fullName === '' || $username === '' || $email === '' || $password === '') {
    set_flash('error', 'All fields are required.');
    redirect('setup_superadmin.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please enter a valid email.');
    redirect('setup_superadmin.php');
}

if ($password !== $confirmPassword) {
    set_flash('error', 'Passwords do not match.');
    redirect('setup_superadmin.php');
}

if (strlen($password) < 6) {
    set_flash('error', 'Password must be at least 6 characters.');
    redirect('setup_superadmin.php');
}

$existingStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$existingStmt->execute(['username' => $username]);
if ($existingStmt->fetch()) {
    set_flash('error', 'Username already in use.');
    redirect('setup_superadmin.php');
}

$emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$emailStmt->execute(['email' => $email]);
if ($emailStmt->fetch()) {
    set_flash('error', 'Email already in use.');
    redirect('setup_superadmin.php');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$insertStmt = $pdo->prepare(
    "INSERT INTO users (full_name, username, email, password_hash, role, status)
     VALUES (:full_name, :username, :email, :password_hash, 'superadmin', 'active')"
);
$insertStmt->execute([
    'full_name' => $fullName,
    'username' => $username,
    'email' => $email,
    'password_hash' => $passwordHash,
]);

$userId = (int) $pdo->lastInsertId();

$userStmt = $pdo->prepare('SELECT id, full_name, username, email, role, status FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$newUser = $userStmt->fetch();

if (!$newUser) {
    set_flash('error', 'Could not create owner.');
    redirect('setup_superadmin.php');
}

login_user($newUser);
log_activity($pdo, 'auth.owner_created', 'First owner account created.', [
    'user_id' => $userId,
    'username' => (string) $newUser['username'],
], $userId);
set_flash('success', 'Owner created successfully.');
redirect('index.php');
