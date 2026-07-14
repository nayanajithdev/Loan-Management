<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('system_settings.view');

$pageTitle = 'System Settings';
$activePage = 'system_settings';
$canEditSystemSettings = can('system_settings.manage');
$disabledAttr = $canEditSystemSettings ? '' : ' disabled';

$settings = system_settings_all($pdo);
$get = static fn(string $key, string $default = ''): string => $settings[$key] ?? $default;

require __DIR__ . '/../includes/layout_start.php';
?>

<form method="post" action="<?= e(url('actions/system_settings_save.php')) ?>">
    <?= csrf_input() ?>
    <div class="settings-col">
        <h3 class="settings-subtitle">System Settings</h3>
        <?php if (!$canEditSystemSettings): ?>
            <p class="muted-block" style="margin-bottom: 10px;">View only. Only Owner can change system settings.</p>
        <?php endif; ?>
        <div class="form-grid settings-system-grid">
            <div class="field">
                <label>Currency Label</label>
                <input type="text" name="currency_label" maxlength="12" value="<?= e($get('currency_label', 'LKR')) ?>" required<?= $disabledAttr ?>>
            </div>
            <div class="field">
                <label>Timezone</label>
                <input type="text" name="timezone" maxlength="80" value="<?= e($get('timezone', date_default_timezone_get())) ?>" required<?= $disabledAttr ?>>
            </div>
            <div class="field">
                <label>Date Format (Display)</label>
                <input type="text" name="date_format" maxlength="20" value="<?= e($get('date_format', 'd M Y')) ?>" required<?= $disabledAttr ?>>
            </div>
            <div class="field">
                <label>Default Interest Rate (%)</label>
                <input type="number" step="0.01" min="0" name="default_interest_rate" value="<?= e($get('default_interest_rate', '0.00')) ?>" required<?= $disabledAttr ?>>
            </div>
            <div class="field">
                <label>Default Installment Frequency</label>
                <?php $freq = $get('default_installment_frequency', 'daily'); ?>
                <select name="default_installment_frequency" required<?= $disabledAttr ?>>
                    <option value="daily" <?= $freq === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly" <?= $freq === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                    <option value="monthly" <?= $freq === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </div>
            <div class="field">
                <label>Default Timeframe Value</label>
                <input type="number" min="1" name="default_timeframe_value" value="<?= e($get('default_timeframe_value', '30')) ?>" required<?= $disabledAttr ?>>
            </div>
            <div class="field">
                <label>Default Timeframe Unit</label>
                <?php $tUnit = $get('default_timeframe_unit', 'days'); ?>
                <select name="default_timeframe_unit" required<?= $disabledAttr ?>>
                    <option value="days" <?= $tUnit === 'days' ? 'selected' : '' ?>>Days</option>
                    <option value="months" <?= $tUnit === 'months' ? 'selected' : '' ?>>Months</option>
                </select>
            </div>
            <div class="field">
                <label>Allow Overpayment</label>
                <?php $allowOverpay = $get('allow_overpayment', '1'); ?>
                <select name="allow_overpayment" required<?= $disabledAttr ?>>
                    <option value="1" <?= $allowOverpay === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $allowOverpay === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="field">
                <label>Auto Fill Amount Received</label>
                <?php $autoFillAmount = $get('auto_fill_amount_received', '1'); ?>
                <select name="auto_fill_amount_received" required<?= $disabledAttr ?>>
                    <option value="1" <?= $autoFillAmount === '1' ? 'selected' : '' ?>>On</option>
                    <option value="0" <?= $autoFillAmount === '0' ? 'selected' : '' ?>>Off</option>
                </select>
            </div>
            <div class="field">
                <label>Live Update Interval (seconds)</label>
                <input type="number" min="3" max="60" name="poll_interval_seconds" value="<?= e($get('poll_interval_seconds', '10')) ?>" required<?= $disabledAttr ?>>
            </div>
        </div>
    </div>

    <div class="form-actions" style="margin-top: 12px;">
        <button type="submit" class="btn btn-primary customer-submit-btn"<?= $canEditSystemSettings ? '' : ' disabled' ?>>Save System Settings</button>
    </div>
</form>

<?php require __DIR__ . '/../includes/layout_end.php';
