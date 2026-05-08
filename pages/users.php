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

$editUserId = (int) ($_GET['edit_user'] ?? 0);
$editUser = null;
if ($editUserId > 0) {
    foreach ($users as $user) {
        if ((int) $user['id'] === $editUserId) {
            $editUser = $user;
            break;
        }
    }

    if (!$editUser) {
        set_flash('error', 'Selected user was not found.');
        redirect('pages/users.php');
    }
}

$isEditMode = $editUser !== null;
$current = current_user();
$currentRole = $current['role'] ?? null;

require __DIR__ . '/../includes/layout_start.php';
?>

<div class="split-layout">
    <div>
        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">User List</h2>
            </div>

            <div class="table-wrap">
                <table>
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
                            <tr class="table-row-clickable <?= $isEditMode && (int) $user['id'] === (int) $editUser['id'] ? 'row-selected' : '' ?>"
                                data-select-url="<?= e(url('pages/users.php?edit_user=' . (int) $user['id'])) ?>">
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

        <?php if ($isEditMode): ?>
            <div style="display:flex; justify-content:flex-end; margin-top:8px;">
                <a class="btn btn-primary" href="<?= e(url('pages/users.php')) ?>">+ Create New User</a>
            </div>
        <?php endif; ?>
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2 class="panel-title"><?= $isEditMode ? 'Edit User' : 'Create User' ?></h2>
        </div>

        <?php if ($isEditMode): ?>
            <?php
            $isSelf = $current && (int) $current['id'] === (int) $editUser['id'];
            $isTargetSuperadmin = $editUser['role'] === 'superadmin';
            $canDelete = !$isSelf && !($isTargetSuperadmin || $currentRole === 'admin' && $isTargetSuperadmin);
            $canChangeRole = !($currentRole === 'admin' && $isTargetSuperadmin);
            $canChangeStatus = ((string) $currentRole) === 'superadmin' && !$isTargetSuperadmin && !$isSelf;
            ?>

            <form class="form-grid" method="post" action="<?= e(url('actions/user_update.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="user_id" value="<?= e((string) $editUser['id']) ?>">

                <div class="field full">
                    <label>Full Name</label>
                    <input type="text" name="full_name" value="<?= e($editUser['full_name']) ?>" required>
                </div>
                <div class="field full">
                    <label>Username</label>
                    <input type="text" name="username" value="<?= e($editUser['username']) ?>" required>
                </div>
                <div class="field full">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= e((string) ($editUser['email'] ?? '')) ?>" required>
                </div>
                <div class="field full">
                    <label>Role</label>
                    <select name="role" <?= $canChangeRole ? '' : 'disabled' ?>>
                        <?php if ($editUser['role'] === 'superadmin'): ?>
                            <option value="superadmin" selected>Owner</option>
                        <?php endif; ?>
                        <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Manager</option>
                        <option value="collector_l2" <?= $editUser['role'] === 'collector_l2' || $editUser['role'] === 'collector' ? 'selected' : '' ?>>Collector L2</option>
                        <option value="collector_l1" <?= $editUser['role'] === 'collector_l1' ? 'selected' : '' ?>>Collector L1</option>
                    </select>
                    <?php if (!$canChangeRole): ?>
                        <input type="hidden" name="role" value="<?= e($editUser['role']) ?>">
                    <?php endif; ?>
                </div>
                <div class="field full">
                    <label>Status</label>
                    <select name="status" <?= $canChangeStatus ? '' : 'disabled' ?>>
                        <option value="active" <?= ((string) $editUser['status']) === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ((string) $editUser['status']) === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <?php if (!$canChangeStatus): ?>
                        <input type="hidden" name="status" value="<?= e((string) $editUser['status']) ?>">
                    <?php endif; ?>
                    <?php if (((string) $currentRole) !== 'superadmin'): ?>
                        <small>Only owner can change user active/inactive.</small>
                    <?php endif; ?>
                </div>
                <div class="field full">
                    <label>New Password (Optional)</label>
                    <input type="password" name="password" minlength="6" placeholder="Leave blank to keep current password">
                </div>
                <div class="field full">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" minlength="6">
                </div>
                <div class="field full" style="display:flex; gap:8px; align-items:center;">
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>

            <hr style="border-color:#333; margin:16px 0;">
            <form method="post" action="<?= e(url('actions/user_delete.php')) ?>" data-confirm="Delete this user? This cannot be undone.">
                <?= csrf_input() ?>
                <input type="hidden" name="user_id" value="<?= e((string) $editUser['id']) ?>">
                <button type="submit" class="btn" <?= $canDelete ? '' : 'disabled' ?>>Delete User</button>
                <?php if ($isSelf): ?>
                    <small style="display:block; margin-top:6px; color:#9c9c9c;">You cannot delete your own logged-in account.</small>
                <?php elseif ($isTargetSuperadmin): ?>
                    <small style="display:block; margin-top:6px; color:#9c9c9c;">Owner cannot be deleted.</small>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <form class="form-grid" method="post" action="<?= e(url('actions/user_save.php')) ?>">
                <?= csrf_input() ?>
                <div class="field full">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required>
                </div>
                <div class="field full">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="field full">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="field full">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="admin">Manager</option>
                        <option value="collector_l2">Collector L2</option>
                        <option value="collector_l1">Collector L1</option>
                    </select>
                </div>
                <div class="field full">
                    <label>Password</label>
                    <input type="password" name="password" minlength="6" required>
                </div>
                <div class="field full">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" minlength="6" required>
                </div>
                <input type="hidden" name="status" value="active">
                <div class="field full" style="align-self:end;">
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/layout_end.php';
