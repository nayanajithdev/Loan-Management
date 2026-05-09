<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin']);

$pageTitle = 'Users';
$activePage = 'users';

$users = $pdo->query(
    "SELECT id, full_name, username, email, role, status, created_at
     FROM users
     ORDER BY FIELD(role, 'superadmin', 'admin', 'collector_l2', 'collector_l1', 'collector'), id ASC"
)->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">User List</h2>
        <div class="panel-head-actions">
            <a class="btn btn-primary" href="<?= e(url('pages/user_create.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus-icon lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </span>
                New User
            </a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="zebra-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td colspan="6">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <?php $roleBadge = $user['role'] === 'superadmin' ? 'info' : ($user['role'] === 'admin' ? 'warning' : 'neutral'); ?>
                    <?php $statusBadge = ((string) $user['status']) === 'active' ? 'success' : 'danger'; ?>
                    <tr class="table-row-clickable"
                        data-select-url="<?= e(url('pages/user_edit.php?user_id=' . (int) $user['id'])) ?>">
                        <td><?= e($user['full_name']) ?></td>
                        <td><?= e($user['username']) ?></td>
                        <td><?= e((string) ($user['email'] ?? '-')) ?></td>
                        <td><span class="badge badge-<?= e($roleBadge) ?>"><?= e(role_display_name((string) $user['role'])) ?></span></td>
                        <td><span class="badge badge-<?= e($statusBadge) ?>"><?= e((string) $user['status']) ?></span></td>
                        <td><?= e(display_datetime((string) $user['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
