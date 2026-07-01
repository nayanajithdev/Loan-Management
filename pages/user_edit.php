<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_permission('users.manage');

$pageTitle = 'Edit User';
$activePage = 'users';

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    set_flash('error', 'Invalid user selected.');
    redirect('pages/users.php');
}

$targetStmt = $pdo->prepare(
    "SELECT id, full_name, username, email, role, status, created_at
     FROM users
     WHERE id = :id
     LIMIT 1"
);
$targetStmt->execute(['id' => $userId]);
$editUser = $targetStmt->fetch();

if (!$editUser) {
    set_flash('error', 'Selected user was not found.');
    redirect('pages/users.php');
}

$current = current_user();
$isSelf = $current && (
    (int) ($current['id'] ?? 0) === (int) $editUser['id']
    || ((string) ($current['username'] ?? '') !== '' && (string) $current['username'] === (string) $editUser['username'])
    || ((string) ($current['email'] ?? '') !== '' && (string) ($current['email'] ?? '') === (string) ($editUser['email'] ?? ''))
);
$isTargetSuperadmin = (string) $editUser['role'] === 'superadmin';
$currentIsOwner = is_owner($current);

if ($isSelf && !$currentIsOwner) {
    set_flash('error', 'Managers cannot edit their own account from user management. Use Profile for personal account changes.');
    redirect('pages/users.php');
}

$canDelete = !$isSelf && !$isTargetSuperadmin;
$canChangeRole = !$isTargetSuperadmin;
$canChangeStatus = $currentIsOwner && !$isTargetSuperadmin && !$isSelf;
$editPermissions = $isTargetSuperadmin ? permission_keys() : user_permission_keys($pdo, (int) $editUser['id']);

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Edit User</h2>
        <div class="panel-head-actions">
            <a class="btn btn-primary" href="<?= e(url('pages/user_create.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus-icon lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </span>
                New User
            </a>
            <a class="btn" href="<?= e(url('pages/users.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                </span>
                Back to Users
            </a>
        </div>
    </div>

    <form class="form-grid" method="post" action="<?= e(url('actions/user_update.php')) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="user_id" value="<?= e((string) $editUser['id']) ?>">

        <div class="field">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?= e((string) $editUser['full_name']) ?>" required>
        </div>
        <div class="field">
            <label>Username</label>
            <input type="text" name="username" value="<?= e((string) $editUser['username']) ?>" required>
        </div>
        <div class="field">
            <label>Email</label>
            <input type="email" name="email" value="<?= e((string) ($editUser['email'] ?? '')) ?>" required>
        </div>
        <div class="field">
            <label>Role</label>
            <select name="role" <?= $canChangeRole ? '' : 'disabled' ?>>
                <?php if ((string) $editUser['role'] === 'superadmin'): ?>
                    <option value="superadmin" selected>Owner</option>
                <?php endif; ?>
                <option value="admin" <?= (string) $editUser['role'] === 'admin' ? 'selected' : '' ?>>Manager</option>
                <option value="collector" <?= (string) $editUser['role'] === 'collector' ? 'selected' : '' ?>>Collector</option>
            </select>
            <?php if (!$canChangeRole): ?>
                <input type="hidden" name="role" value="<?= e((string) $editUser['role']) ?>">
            <?php endif; ?>
        </div>
        <div class="field">
            <label>Status</label>
            <select name="status" <?= $canChangeStatus ? '' : 'disabled' ?>>
                <option value="active" <?= (string) $editUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= (string) $editUser['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <?php if (!$canChangeStatus): ?>
                <input type="hidden" name="status" value="<?= e((string) $editUser['status']) ?>">
            <?php endif; ?>
            <?php if (!$currentIsOwner): ?>
                <small>Only owner can change user active/inactive.</small>
            <?php endif; ?>
        </div>
        <div class="field">
            <label>New Password (Optional)</label>
            <input type="password" name="password" minlength="6" placeholder="Leave blank to keep current password">
        </div>
        <div class="field">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" minlength="6">
        </div>
        <?php render_permission_fields($editPermissions, $isTargetSuperadmin); ?>
        <div class="field full form-actions">
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
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
