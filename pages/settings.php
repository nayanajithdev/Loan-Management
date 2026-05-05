<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

$pageTitle = 'Settings';
$activePage = 'settings';

$settings = system_settings_all($pdo);
$get = static fn(string $key, string $default = ''): string => $settings[$key] ?? $default;

require __DIR__ . '/../includes/layout_start.php';
?>

<form method="post" action="<?= e(url('actions/settings_save.php')) ?>">
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
