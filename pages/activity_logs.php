<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('activity_logs.view');

$pageTitle = 'Activity Logs';
$activePage = 'activity_logs';

$defaultFrom = (new DateTimeImmutable(today()))->modify('first day of this month')->format('Y-m-d');
$defaultTo = today();

$fromDate = trim((string) ($_GET['from'] ?? $defaultFrom));
$toDate = trim((string) ($_GET['to'] ?? $defaultTo));
$search = trim((string) ($_GET['q'] ?? ''));

$fromObj = DateTimeImmutable::createFromFormat('Y-m-d', $fromDate) ?: new DateTimeImmutable($defaultFrom);
$toObj = DateTimeImmutable::createFromFormat('Y-m-d', $toDate) ?: new DateTimeImmutable($defaultTo);
if ($fromObj > $toObj) {
    [$fromObj, $toObj] = [$toObj, $fromObj];
}

$fromDate = $fromObj->format('Y-m-d');
$toDate = $toObj->format('Y-m-d');

function activity_action_label(string $actionKey): string
{
    $labels = [
        'auth.login' => 'Signed in',
        'auth.logout' => 'Signed out',
        'auth.login_failed' => 'Sign-in failed',
        'auth.login_throttled' => 'Sign-in locked',
        'auth.login_blocked' => 'Sign-in blocked',
        'auth.login_blocked_inactive' => 'Inactive user blocked',
        'auth.owner_created' => 'Owner created',
        'auth.owner_setup_blocked' => 'Owner setup blocked',
        'auth.password_reset_requested' => 'Password reset requested',
        'auth.password_reset_completed' => 'Password reset completed',
        'auth.password_reset_throttled' => 'Password reset limited',
        'profile.updated' => 'Profile updated',
        'profile.password_changed' => 'Password changed',
        'user.created' => 'User created',
        'user.updated' => 'User updated',
        'user.deleted' => 'User deleted',
        'customer.created' => 'Customer created',
        'customer.updated' => 'Customer updated',
        'customer.deleted' => 'Customer deleted',
        'customer.document_view' => 'Document viewed',
        'customer.document_download' => 'Document downloaded',
        'loan.created' => 'Loan created',
        'loan.updated' => 'Loan updated',
        'loan.deleted' => 'Loan deleted',
        'collection.recorded' => 'Payment collected',
        'collection.failed' => 'Payment failed',
        'backup.download' => 'Backup downloaded',
        'backup.restore' => 'Backup restored',
        'backup.restore_failed' => 'Backup restore failed',
        'settings.business_updated' => 'Business settings updated',
        'settings.system_updated' => 'System settings updated',
        'holiday.enabled' => 'Holiday mode enabled',
        'holiday.enable_failed' => 'Holiday mode failed',
    ];

    if (isset($labels[$actionKey])) {
        return $labels[$actionKey];
    }

    $label = str_replace(['.', '_', '-'], ' ', $actionKey);
    return ucwords($label);
}

function activity_description_for_user(string $actionKey, string $description): string
{
    $failedDescriptions = [
        'loan.create_failed' => 'Loan could not be created.',
        'loan.update_failed' => 'Loan could not be updated.',
        'loan.delete_failed' => 'Loan could not be deleted.',
        'customer.create_failed' => 'Customer could not be created.',
        'customer.update_failed' => 'Customer could not be updated.',
        'customer.delete_failed' => 'Customer could not be deleted.',
        'collection.failed' => 'Payment could not be saved.',
        'backup.restore_failed' => 'Backup could not be restored.',
        'holiday.enable_failed' => 'Holiday mode could not be enabled.',
    ];

    if (isset($failedDescriptions[$actionKey])) {
        return $failedDescriptions[$actionKey];
    }

    return $description;
}

$sql = "SELECT
            al.id,
            al.created_at,
            al.action_key,
            al.description,
            COALESCE(u.full_name, 'System') AS actor_name,
            COALESCE(u.username, '-') AS actor_username
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.actor_user_id
        WHERE DATE(al.created_at) BETWEEN :from_date AND :to_date";
$params = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
];

if ($search !== '') {
    $sql .= " AND (
        al.action_key LIKE :q_action
        OR al.description LIKE :q_desc
        OR COALESCE(u.full_name, '') LIKE :q_name
        OR COALESCE(u.username, '') LIKE :q_username
    )";
    $searchLike = '%' . $search . '%';
    $params['q_action'] = $searchLike;
    $params['q_desc'] = $searchLike;
    $params['q_name'] = $searchLike;
    $params['q_username'] = $searchLike;
}

$sql .= ' ORDER BY al.id DESC LIMIT 600';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Activity Logs</h2>
    </div>

    <form method="get" class="form-grid activity-log-filter-form">
        <div class="field">
            <label>From Date</label>
            <input type="date" name="from" value="<?= e($fromDate) ?>" required>
        </div>
        <div class="field">
            <label>To Date</label>
            <input type="date" name="to" value="<?= e($toDate) ?>" required>
        </div>
        <div class="field activity-log-search-field">
            <label class="sr-only">Search activity logs</label>
            <div class="search-control">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search..." aria-label="Search by action, user, or description">
                <button type="submit" class="btn search-submit" aria-label="Search activity logs">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
                </button>
            </div>
        </div>
        <div class="field full reports-filter-actions">
            <a class="btn" href="<?= e(url('pages/activity_logs.php')) ?>">Reset</a>
            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">All Activities</h2>
    </div>

    <div class="table-wrap">
        <table class="zebra-table activity-logs-table">
            <thead>
            <tr>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Description</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="4">No activity logs found for selected filter.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $row): ?>
                    <?php
                    $actionKey = (string) $row['action_key'];
                    $description = activity_description_for_user($actionKey, (string) $row['description']);
                    ?>
                    <tr>
                        <td><?= e(display_datetime((string) $row['created_at'])) ?></td>
                        <td><?= e((string) $row['actor_name']) ?></td>
                        <td><?= e(activity_action_label($actionKey)) ?></td>
                        <td><?= e($description) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
