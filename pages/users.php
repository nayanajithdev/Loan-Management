<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_permission('users.manage');

$pageTitle = 'Users & Roles';
$activePage = 'users';

$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 120);

$params = [];
$sql = "SELECT id, full_name, username, email, role, status, created_at
        FROM users";

if ($search !== '') {
    $sql .= " WHERE full_name LIKE :search
              OR username LIKE :search
              OR email LIKE :search";
    $params['search'] = '%' . $search . '%';
}

$sql .= " ORDER BY FIELD(role, 'superadmin', 'admin', 'collector'), full_name ASC, id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
$current = current_user();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel users-directory-panel">
    <div class="users-directory-toolbar">
        <div>
            <p class="panel-kicker">User Directory</p>
            <h2 class="panel-title">System users</h2>
        </div>

        <div class="users-directory-actions">
            <form class="users-search-form" method="get" action="<?= e(url('pages/users.php')) ?>">
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email, username" autocomplete="off">
                <button class="btn btn-icon-only" type="submit" aria-label="Search users">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
                </button>
                <?php if ($search !== ''): ?>
                    <a class="btn" href="<?= e(url('pages/users.php')) ?>">Reset</a>
                <?php endif; ?>
            </form>

            <a class="btn btn-primary" href="<?= e(url('pages/user_create.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus-icon lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </span>
                New User
            </a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="zebra-table users-directory-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td colspan="7">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <?php
                    $role = (string) $user['role'];
                    $isOwnerRole = $role === 'superadmin';
                    $isCurrentUser = $current && (
                        (int) ($current['id'] ?? 0) === (int) $user['id']
                        || ((string) ($current['username'] ?? '') !== '' && (string) $current['username'] === (string) $user['username'])
                        || ((string) ($current['email'] ?? '') !== '' && (string) ($current['email'] ?? '') === (string) ($user['email'] ?? ''))
                    );
                    $isSelfRestricted = $isCurrentUser && !is_owner($current);
                    $canOpenUser = !$isOwnerRole && !$isSelfRestricted;
                    $roleBadge = $isOwnerRole ? 'info' : ($role === 'admin' ? 'warning' : 'neutral');
                    $statusBadge = ((string) $user['status']) === 'active' ? 'success' : 'danger';
                    $editUrl = url('pages/user_edit.php?user_id=' . (int) $user['id']);
                    ?>
                    <tr class="<?= $canOpenUser ? 'table-row-clickable' : '' ?>" <?= $canOpenUser ? 'data-select-url="' . e($editUrl) . '"' : '' ?>>
                        <td data-label="Name"><?= e((string) $user['full_name']) ?></td>
                        <td data-label="Email"><?= e((string) ($user['email'] ?? '-')) ?></td>
                        <td data-label="Username"><?= e((string) $user['username']) ?></td>
                        <td data-label="Role"><span class="badge badge-<?= e($roleBadge) ?>"><?= e(role_display_name($role)) ?></span></td>
                        <td data-label="Status"><span class="badge badge-<?= e($statusBadge) ?>"><?= e(ucfirst((string) $user['status'])) ?></span></td>
                        <td data-label="Created"><?= e(display_date(substr((string) $user['created_at'], 0, 10))) ?></td>
                        <td data-label="Action">
                            <?php if (!$canOpenUser): ?>
                                <span class="muted-text">Protected</span>
                            <?php else: ?>
                                <a class="btn btn-icon-only" href="<?= e($editUrl) ?>" aria-label="Edit user">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
