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

$sql = "SELECT
            al.id,
            al.created_at,
            al.action_key,
            al.description,
            al.meta_json,
            al.ip_address,
            COALESCE(u.full_name, 'System') AS actor_name,
            COALESCE(u.username, '-') AS actor_username,
            COALESCE(u.role, al.actor_role, '-') AS actor_role
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
        OR COALESCE(al.ip_address, '') LIKE :q_ip
    )";
    $searchLike = '%' . $search . '%';
    $params['q_action'] = $searchLike;
    $params['q_desc'] = $searchLike;
    $params['q_name'] = $searchLike;
    $params['q_username'] = $searchLike;
    $params['q_ip'] = $searchLike;
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
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search..." aria-label="Search by action, user, text, or IP">
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
                <th>Role</th>
                <th>Action</th>
                <th>Description</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="6">No activity logs found for selected filter.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $row): ?>
                    <?php
                    $metaText = '';
                    if (!empty($row['meta_json'])) {
                        $decoded = json_decode((string) $row['meta_json'], true);
                        if (is_array($decoded) && $decoded !== []) {
                            $pairs = [];
                            foreach ($decoded as $k => $v) {
                                if (is_scalar($v) || $v === null) {
                                    $pairs[] = (string) $k . ': ' . (string) ($v ?? '-');
                                }
                            }
                            $metaText = implode(' | ', $pairs);
                        }
                    }
                    ?>
                    <tr>
                        <td><?= e(display_datetime((string) $row['created_at'])) ?></td>
                        <td><?= e((string) $row['actor_name']) ?><br><small style="color: var(--muted);">@<?= e((string) $row['actor_username']) ?></small></td>
                        <td><?= e(role_display_name((string) $row['actor_role'])) ?></td>
                        <td><?= e((string) $row['action_key']) ?></td>
                        <td>
                            <?= e((string) $row['description']) ?>
                            <?php if ($metaText !== ''): ?>
                                <br><small style="color: var(--muted);"><?= e($metaText) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $row['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
