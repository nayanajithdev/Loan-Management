<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

if (!has_superadmin($pdo)) {
    set_flash('error', 'No superadmin available. Create first superadmin.');
    redirect('setup_superadmin.php');
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    set_flash('error', 'Username and password are required.');
    redirect('login.php');
}

$stmt = $pdo->prepare('SELECT id, full_name, username, password_hash, role FROM users WHERE username = :username LIMIT 1');
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, (string) $user['password_hash'])) {
    set_flash('error', 'Invalid username or password.');
    redirect('login.php');
}

login_user($user);
set_flash('success', 'Login successful.');
redirect('index.php');