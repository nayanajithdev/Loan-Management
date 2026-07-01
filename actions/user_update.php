<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_permission('users.manage', 'index.php');

function user_update_safe_return_target(string $raw, string $fallback): string
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

    if (!in_array($normalizedPath, ['pages/users.php', 'pages/user_edit.php'], true)) {
        return $fallback;
    }

    $allowedQuery = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $queryValues);
        if (isset($queryValues['user_id']) && is_numeric((string) $queryValues['user_id'])) {
            $allowedQuery['user_id'] = (int) $queryValues['user_id'];
        }
        if (isset($queryValues['edit_user']) && is_numeric((string) $queryValues['edit_user'])) {
            $allowedQuery['edit_user'] = (int) $queryValues['edit_user'];
        }
    }

    if ($allowedQuery !== []) {
        return $normalizedPath . '?' . http_build_query($allowedQuery);
    }

    return $normalizedPath;
}

$userId = (int) ($_POST['user_id'] ?? 0);
$postedReturnTo = (string) ($_POST['return_to'] ?? '');
$defaultEditReturnTo = 'pages/user_edit.php?user_id=' . $userId;
$resolvedEditReturnTo = user_update_safe_return_target($postedReturnTo, $defaultEditReturnTo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/users.php');
}
require_csrf($resolvedEditReturnTo);

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$role = trim((string) ($_POST['role'] ?? 'collector'));
$status = trim((string) ($_POST['status'] ?? 'active'));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($userId <= 0 || $fullName === '' || $username === '' || $email === '') {
    set_flash('error', 'Required fields are missing.');
    redirect($resolvedEditReturnTo);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please enter a valid email.');
    redirect($resolvedEditReturnTo);
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

$targetRole = (string) $targetUser['role'];

if ($targetRole === 'superadmin') {
    $role = 'superadmin';
} else {
    if (!in_array($role, ['admin', 'collector'], true)) {
        set_flash('error', 'Invalid role selected.');
        redirect($resolvedEditReturnTo);
    }
}

if (!is_owner($current) && $targetRole === 'superadmin') {
    set_flash('error', 'Only owner can edit owner account.');
    redirect('pages/users.php');
}

if (!is_owner($current)) {
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
    redirect($resolvedEditReturnTo);
}

$emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
$emailStmt->execute([
    'email' => $email,
    'id' => $userId,
]);
if ($emailStmt->fetch()) {
    set_flash('error', 'Email already exists.');
    redirect($resolvedEditReturnTo);
}

if ($password !== '' || $confirmPassword !== '') {
    if ($password !== $confirmPassword) {
        set_flash('error', 'Passwords do not match.');
        redirect($resolvedEditReturnTo);
    }

    if (strlen($password) < 6) {
        set_flash('error', 'Password must be at least 6 characters.');
        redirect($resolvedEditReturnTo);
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

if ($targetRole !== 'superadmin') {
    sync_user_permissions($pdo, $userId, (array) ($_POST['permissions'] ?? []));
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
if (str_starts_with($resolvedEditReturnTo, 'pages/users.php')) {
    redirect('pages/users.php?edit_user=' . $userId);
}
redirect($resolvedEditReturnTo);
