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
$search = substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$selectedUser = trim((string) ($_GET['user_id'] ?? 'all'));
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$fromObj = DateTimeImmutable::createFromFormat('Y-m-d', $fromDate) ?: new DateTimeImmutable($defaultFrom);
$toObj = DateTimeImmutable::createFromFormat('Y-m-d', $toDate) ?: new DateTimeImmutable($defaultTo);
if ($fromObj > $toObj) {
    [$fromObj, $toObj] = [$toObj, $fromObj];
}

$fromDate = $fromObj->format('Y-m-d');
$toDate = $toObj->format('Y-m-d');

$logUsers = $pdo->query('SELECT id, full_name, username, role FROM users ORDER BY full_name ASC, username ASC')->fetchAll();
if ($selectedUser !== 'all' && $selectedUser !== 'system' && !ctype_digit($selectedUser)) {
    $selectedUser = 'all';
}

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

function activity_logs_page_url(int $pageNumber): string
{
    $query = $_GET;
    $query['page'] = $pageNumber;

    return url('pages/activity_logs.php') . '?' . http_build_query($query);
}

$where = ['DATE(al.created_at) BETWEEN :from_date AND :to_date'];
$params = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
];

if ($selectedUser === 'system') {
    $where[] = 'al.actor_user_id IS NULL';
} elseif (ctype_digit($selectedUser) && (int) $selectedUser > 0) {
    $where[] = 'al.actor_user_id = :actor_user_id';
    $params['actor_user_id'] = (int) $selectedUser;
}

if ($search !== '') {
    $where[] = "(
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

$whereSql = 'WHERE ' . implode(' AND ', $where);

$countSql = "SELECT COUNT(*)
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.actor_user_id
        {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalLogs = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLogs / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;
$showingFrom = $totalLogs > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalLogs);
$pageStart = max(1, $currentPage - 2);
$pageEnd = min($totalPages, $currentPage + 2);

$sql = "SELECT
            al.id,
            al.created_at,
            al.action_key,
            al.description,
            COALESCE(u.full_name, 'System') AS actor_name,
            COALESCE(u.username, '-') AS actor_username
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.actor_user_id
        {$whereSql}
        ORDER BY al.id DESC
        LIMIT {$perPage} OFFSET {$offset}";

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
        <div class="field">
            <label>User</label>
            <select name="user_id">
                <option value="all" <?= $selectedUser === 'all' ? 'selected' : '' ?>>All Users</option>
                <option value="system" <?= $selectedUser === 'system' ? 'selected' : '' ?>>System</option>
                <?php foreach ($logUsers as $user): ?>
                    <?php $userId = (string) $user['id']; ?>
                    <option value="<?= e($userId) ?>" <?= $selectedUser === $userId ? 'selected' : '' ?>>
                        <?= e((string) $user['full_name']) ?> (<?= e((string) $user['username']) ?> - <?= e(role_display_name((string) $user['role'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
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

    <?php if ($totalLogs > 0): ?>
        <div class="pagination-bar">
            <p class="pagination-info">
                Showing <?= e((string) $showingFrom) ?>-<?= e((string) $showingTo) ?> of <?= e((string) $totalLogs) ?>
            </p>
            <?php if ($totalPages > 1): ?>
                <div class="pagination-links" aria-label="Activity log pagination">
                    <a class="btn pagination-link <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= e(activity_logs_page_url(max(1, $currentPage - 1))) ?>">Previous</a>
                    <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                        <?php if ($pageNumber === $currentPage): ?>
                            <span class="btn pagination-link is-current"><?= e((string) $pageNumber) ?></span>
                        <?php else: ?>
                            <a class="btn pagination-link" href="<?= e(activity_logs_page_url($pageNumber)) ?>"><?= e((string) $pageNumber) ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <a class="btn pagination-link <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e(activity_logs_page_url(min($totalPages, $currentPage + 1))) ?>">Next</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
