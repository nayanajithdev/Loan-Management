<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin'], 'index.php');

function user_delete_safe_return_target(string $raw, string $fallback): string
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
$defaultDeleteReturnTo = 'pages/users.php';
$resolvedDeleteReturnTo = user_delete_safe_return_target($postedReturnTo, $defaultDeleteReturnTo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/users.php');
}
require_csrf($resolvedDeleteReturnTo);

if ($userId <= 0) {
    set_flash('error', 'Invalid user id.');
    redirect($resolvedDeleteReturnTo);
}

$current = current_user();
if (!$current) {
    redirect('login.php');
}

if ((int) $current['id'] === $userId) {
    set_flash('error', 'You cannot delete your own account while logged in.');
    redirect($resolvedDeleteReturnTo);
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

if ($currentRole === 'admin' && $targetRole === 'superadmin') {
    set_flash('error', 'Manager cannot delete owner.');
    redirect($resolvedDeleteReturnTo);
}

if ($targetRole === 'superadmin') {
    set_flash('error', 'Owner cannot be deleted (only one owner allowed).');
    redirect($resolvedDeleteReturnTo);
}

try {
    $pdo->beginTransaction();

    // Unassign loans linked to this user to satisfy FK constraints.
    $unassignStmt = $pdo->prepare('UPDATE loans SET assigned_user_id = NULL WHERE assigned_user_id = :id');
    $unassignStmt->execute(['id' => $userId]);
    $unassignedLoanCount = (int) $unassignStmt->rowCount();

    $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $deleteStmt->execute(['id' => $userId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', 'Cannot delete this user because linked records still exist. Reassign related data and try again.');
    redirect($resolvedDeleteReturnTo);
}

log_activity($pdo, 'user.deleted', 'User deleted: ' . (string) $targetUser['full_name'] . '.', [
    'user_id' => $userId,
    'username' => (string) $targetUser['username'],
    'role' => role_display_name((string) $targetUser['role']),
    'unassigned_loans' => $unassignedLoanCount ?? 0,
]);

set_flash('success', 'User deleted successfully.');
redirect('pages/users.php');
