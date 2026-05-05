<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}
require_csrf('login.php');

if (!has_superadmin($pdo)) {
    log_activity($pdo, 'auth.login_blocked', 'Login blocked because no owner exists.');
    set_flash('error', 'No owner available. Create first owner.');
    redirect('setup_superadmin.php');
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    log_activity($pdo, 'auth.login_failed', 'Login failed: missing username or password.', [
        'username' => $username,
    ]);
    set_flash('error', 'Username and password are required.');
    redirect('login.php');
}

$stmt = $pdo->prepare('SELECT id, full_name, username, password_hash, role FROM users WHERE username = :username LIMIT 1');
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, (string) $user['password_hash'])) {
    log_activity($pdo, 'auth.login_failed', 'Login failed: invalid username or password.', [
        'username' => $username,
    ]);
    set_flash('error', 'Invalid username or password.');
    redirect('login.php');
}

login_user($user);
log_activity($pdo, 'auth.login', 'User logged in.', [
    'user_id' => (int) $user['id'],
    'username' => (string) $user['username'],
    'role' => role_display_name((string) $user['role']),
], (int) $user['id']);
set_flash('success', 'Login successful.');
redirect('index.php');
