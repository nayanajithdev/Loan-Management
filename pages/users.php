<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin']);

$pageTitle = 'Users';
$activePage = 'users';

$users = $pdo->query(
    "SELECT id, full_name, username, role, created_at
     FROM users
     ORDER BY FIELD(role, 'superadmin', 'admin', 'collector'), id ASC"
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
                        <th>Role</th>
                        <th>Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="4">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <?php $roleBadge = $user['role'] === 'superadmin' ? 'info' : ($user['role'] === 'admin' ? 'warning' : 'neutral'); ?>
                            <tr class="table-row-clickable <?= $isEditMode && (int) $user['id'] === (int) $editUser['id'] ? 'row-selected' : '' ?>"
                                data-select-url="<?= e(url('pages/users.php?edit_user=' . (int) $user['id'])) ?>">
                                <td><?= e($user['full_name']) ?></td>
                                <td><?= e($user['username']) ?></td>
                                <td><span class="badge badge-<?= e($roleBadge) ?>"><?= e($user['role']) ?></span></td>
                                <td><?= e($user['created_at']) ?></td>
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
            ?>

            <form class="form-grid" method="post" action="<?= e(url('actions/user_update.php')) ?>">
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
                    <label>Role</label>
                    <select name="role" <?= $canChangeRole ? '' : 'disabled' ?>>
                        <?php if ($editUser['role'] === 'superadmin'): ?>
                            <option value="superadmin" selected>Superadmin</option>
                        <?php endif; ?>
                        <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="collector" <?= $editUser['role'] === 'collector' ? 'selected' : '' ?>>Collector</option>
                    </select>
                    <?php if (!$canChangeRole): ?>
                        <input type="hidden" name="role" value="<?= e($editUser['role']) ?>">
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
                <input type="hidden" name="user_id" value="<?= e((string) $editUser['id']) ?>">
                <button type="submit" class="btn" <?= $canDelete ? '' : 'disabled' ?>>Delete User</button>
                <?php if ($isSelf): ?>
                    <small style="display:block; margin-top:6px; color:#9c9c9c;">You cannot delete your own logged-in account.</small>
                <?php elseif ($isTargetSuperadmin): ?>
                    <small style="display:block; margin-top:6px; color:#9c9c9c;">Superadmin cannot be deleted.</small>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <form class="form-grid" method="post" action="<?= e(url('actions/user_save.php')) ?>">
                <div class="field full">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required>
                </div>
                <div class="field full">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="field full">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="admin">Admin</option>
                        <option value="collector">Collector</option>
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
                <div class="field full" style="align-self:end;">
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/layout_end.php';
