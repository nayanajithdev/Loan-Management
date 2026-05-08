<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector']);

$pageTitle = 'Profile';
$activePage = 'profile';

$authUser = current_user();
if (!$authUser) {
    redirect('login.php');
}

$userStmt = $pdo->prepare('SELECT id, full_name, username, email, role, avatar_path FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => (int) $authUser['id']]);
$user = $userStmt->fetch();

if (!$user) {
    logout_user();
    set_flash('error', 'Your account was removed. Please login again.');
    redirect('login.php');
}

$canEditName = ((string) $user['role']) === 'superadmin';
$fullName = (string) $user['full_name'];
$parts = preg_split('/\s+/', trim($fullName)) ?: [];
$initials = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1));
if ($initials === '') {
    $initials = strtoupper(substr((string) $user['username'], 0, 2));
}
$avatarPath = trim((string) ($user['avatar_path'] ?? ''));

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="split-layout profile-split-layout">
    <article class="panel profile-panel">
        <div class="panel-head">
            <h2 class="panel-title">Profile Details</h2>
            <span class="badge badge-info"><?= e(role_display_name((string) $user['role'])) ?></span>
        </div>

        <form method="post" action="<?= e(url('actions/profile_update.php')) ?>" enctype="multipart/form-data" class="form-grid profile-form-grid">
            <?= csrf_input() ?>
            <div class="field full profile-avatar-row">
                <div class="profile-avatar">
                    <?php if ($avatarPath !== ''): ?>
                        <img src="<?= e(url($avatarPath)) ?>" alt="Avatar">
                    <?php else: ?>
                        <span><?= e($initials) ?></span>
                    <?php endif; ?>
                </div>
                <div class="profile-avatar-upload">
                    <label>Profile Icon</label>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif,image/*">
                    <small>JPG, PNG, WEBP or GIF. Max 5MB.</small>
                </div>
            </div>

            <div class="field">
                <label>Name</label>
                <input type="text" name="full_name" maxlength="120" value="<?= e((string) $user['full_name']) ?>" <?= $canEditName ? '' : 'readonly class="profile-readonly-input"' ?> required>
                <?php if (!$canEditName): ?>
                    <small>Only Owner can change name.</small>
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Username</label>
                <input type="text" name="username" maxlength="100" value="<?= e((string) $user['username']) ?>" required>
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" maxlength="190" value="<?= e((string) ($user['email'] ?? '')) ?>" required>
            </div>

            <div class="field">
                <label>Role</label>
                <input type="text" value="<?= e(role_display_name((string) $user['role'])) ?>" readonly class="profile-readonly-input">
                <small>Role cannot be changed from profile.</small>
            </div>

            <div class="field full form-actions">
                <button type="submit" class="btn btn-primary customer-submit-btn">Save Profile</button>
            </div>
        </form>
    </article>

    <article class="panel profile-panel">
        <div class="panel-head">
            <h2 class="panel-title">Change Password</h2>
        </div>

        <form method="post" action="<?= e(url('actions/profile_password.php')) ?>" class="form-grid profile-password-grid">
            <?= csrf_input() ?>
            <div class="field">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="field">
                <label>New Password</label>
                <input type="password" name="new_password" minlength="6" required>
            </div>
            <div class="field">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" minlength="6" required>
            </div>

            <div class="field full profile-security-note">
                <strong>Security note</strong>
                <p>Use a unique password with at least 6 characters.</p>
            </div>

            <div class="field full form-actions">
                <button type="submit" class="btn btn-primary customer-submit-btn">Update Password</button>
            </div>
        </form>
    </article>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
