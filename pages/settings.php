<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

$pageTitle = 'Business Settings';
$activePage = 'settings';

$settings = system_settings_all($pdo);
$get = static fn(string $key, string $default = ''): string => $settings[$key] ?? $default;
$businessIconPath = business_icon_path($pdo);
$businessName = trim($get('business_name', 'Loan Manager'));
$businessInitial = strtoupper(substr(preg_replace('/\s+/', '', $businessName), 0, 1));
if ($businessInitial === '') {
    $businessInitial = 'L';
}

require __DIR__ . '/../includes/layout_start.php';
?>

<form method="post" action="<?= e(url('actions/settings_save.php')) ?>" enctype="multipart/form-data">
    <?= csrf_input() ?>
    <div class="settings-col">
        <h3 class="settings-subtitle">Business Settings</h3>
        <div class="form-grid settings-business-grid">
            <div class="field">
                <label>Business Name</label>
                <input type="text" name="business_name" maxlength="120" value="<?= e($get('business_name', 'Loan Manager')) ?>" required>
            </div>
            <div class="field">
                <label>Business Phone</label>
                <input type="text" name="business_phone" maxlength="40" value="<?= e($get('business_phone', '')) ?>">
            </div>
            <div class="field">
                <label>Business Email</label>
                <input type="email" name="business_email" maxlength="120" value="<?= e($get('business_email', '')) ?>">
            </div>
            <div class="field business-icon-field">
                <label>Business Icon</label>
                <div class="settings-icon-upload-wrap">
                    <div class="settings-icon-preview">
                        <?php if ($businessIconPath !== ''): ?>
                            <img src="<?= e(url($businessIconPath)) ?>" alt="Business icon">
                        <?php else: ?>
                            <?= e($businessInitial) ?>
                        <?php endif; ?>
                    </div>
                    <div class="settings-icon-input">
                        <input type="file" name="business_icon" accept=".jpg,.jpeg,.png,.webp,.gif,.ico,image/*">
                    </div>
                </div>
                <small>JPG, PNG, WEBP, GIF or ICO. Max 2MB.</small>
            </div>
            <div class="field full">
                <label>Business Address</label>
                <textarea name="business_address" maxlength="600"><?= e($get('business_address', '')) ?></textarea>
            </div>
            <div class="field full">
                <label>Business Note</label>
                <textarea name="business_note" maxlength="600" placeholder="Optional"><?= e($get('business_note', '')) ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-actions" style="margin-top: 12px;">
        <button type="submit" class="btn btn-primary customer-submit-btn">Save Business Settings</button>
    </div>
</form>

<?php require __DIR__ . '/../includes/layout_end.php';
