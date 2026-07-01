<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_permission('users.manage', 'index.php');

function user_save_safe_return_target(string $raw, string $fallback): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return $fallback;
    }

    $parts = parse_url($raw);
    if (!is_array($parts)) {
        return $fallback;
    }

    $path = (string) ($parts['path'] ?? '');
    if ($path === '') {
        return $fallback;
    }

    $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
    $basePath = trim(str_replace('\\', '/', (string) BASE_PATH), '/');
    if ($basePath !== '' && str_starts_with($normalizedPath, $basePath . '/')) {
        $normalizedPath = substr($normalizedPath, strlen($basePath) + 1);
    }

    if (!in_array($normalizedPath, ['pages/users.php', 'pages/user_create.php', 'pages/user_edit.php'], true)) {
        return $fallback;
    }

    $allowedQuery = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $queryValues);
        if (isset($queryValues['edit_user']) && is_numeric((string) $queryValues['edit_user'])) {
            $allowedQuery['edit_user'] = (int) $queryValues['edit_user'];
        }
    }

    if ($allowedQuery !== []) {
        return $normalizedPath . '?' . http_build_query($allowedQuery);
    }

    return $normalizedPath;
}

$postedReturnTo = (string) ($_POST['return_to'] ?? '');
$defaultCreateReturnTo = 'pages/user_create.php';
$resolvedCreateReturnTo = user_save_safe_return_target($postedReturnTo, $defaultCreateReturnTo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($resolvedCreateReturnTo);
}
require_csrf($resolvedCreateReturnTo);

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$role = trim((string) ($_POST['role'] ?? 'collector'));
$status = trim((string) ($_POST['status'] ?? 'active'));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($fullName === '' || $username === '' || $email === '' || $password === '') {
    set_flash('error', 'All fields are required.');
    redirect($resolvedCreateReturnTo);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please enter a valid email.');
    redirect($resolvedCreateReturnTo);
}

if (!in_array($role, ['admin', 'collector'], true)) {
    set_flash('error', 'Invalid role selected.');
    redirect($resolvedCreateReturnTo);
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}
$current = current_user();
if (!is_owner($current)) {
    $status = 'active';
}

if ($password !== $confirmPassword) {
    set_flash('error', 'Passwords do not match.');
    redirect($resolvedCreateReturnTo);
}

if (strlen($password) < 6) {
    set_flash('error', 'Password must be at least 6 characters.');
    redirect($resolvedCreateReturnTo);
}

$existsStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$existsStmt->execute(['username' => $username]);
if ($existsStmt->fetch()) {
    set_flash('error', 'Username already exists.');
    redirect($resolvedCreateReturnTo);
}

$emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$emailStmt->execute(['email' => $email]);
if ($emailStmt->fetch()) {
    set_flash('error', 'Email already exists.');
    redirect($resolvedCreateReturnTo);
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
sync_user_permissions($pdo, $createdUserId, (array) ($_POST['permissions'] ?? []));

log_activity($pdo, 'user.created', 'User created: ' . $fullName . '.', [
    'user_id' => $createdUserId,
    'username' => $username,
    'email' => $email,
    'role' => role_display_name($role),
    'status' => $status,
]);

set_flash('success', 'User created successfully.');
if (str_starts_with($resolvedCreateReturnTo, 'pages/users.php')) {
    redirect('pages/users.php?edit_user=' . $createdUserId);
}
redirect('pages/user_edit.php?user_id=' . $createdUserId);
