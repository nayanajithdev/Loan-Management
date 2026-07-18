<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('system_settings.manage', 'pages/system_settings.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/system_settings.php');
}
require_csrf('pages/system_settings.php');

$postedAssignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
$assignedUserId = null;
$assignedLabel = 'All users';

if ($postedAssignedUserId > 0) {
    if (!is_assignable_collector($pdo, $postedAssignedUserId)) {
        set_flash('error', 'Selected collector is not available for loan assignment.');
        redirect('pages/system_settings.php');
    }

    $assignedUserId = $postedAssignedUserId;
    $userStmt = $pdo->prepare('SELECT full_name, username FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $assignedUserId]);
    $assignedUser = $userStmt->fetch();
    $assignedLabel = trim((string) ($assignedUser['full_name'] ?? ''));
    if ($assignedLabel === '') {
        $assignedLabel = (string) ($assignedUser['username'] ?? ('User #' . $assignedUserId));
    }
}

try {
    $stmt = $pdo->prepare('UPDATE loans SET assigned_user_id = :assigned_user_id');
    if ($assignedUserId === null) {
        $stmt->bindValue(':assigned_user_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':assigned_user_id', $assignedUserId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $updatedCount = $stmt->rowCount();

    log_activity($pdo, 'loan.bulk_assigned', 'All loans reassigned to ' . $assignedLabel . '.', [
        'assigned_user_id' => $assignedUserId,
        'assigned_label' => $assignedLabel,
        'updated_count' => $updatedCount,
    ]);

    set_flash('success', 'All loans assigned to ' . $assignedLabel . '. Updated ' . $updatedCount . ' loan(s).');
} catch (Throwable $e) {
    log_activity($pdo, 'loan.bulk_assign_failed', 'Bulk loan assignment failed.', [
        'assigned_user_id' => $assignedUserId,
        'error' => $e->getMessage(),
    ]);
    set_flash('error', 'Failed to assign all loans. Please try again.');
}

redirect('pages/system_settings.php');
