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
$defaultLoanCollectorId = default_loan_collector_id($pdo);
$loanDefaultCollectors = assignable_collector_rows($pdo, $defaultLoanCollectorId);
$bulkLoanCollectors = assignable_collector_rows($pdo);

require __DIR__ . '/../includes/layout_start.php';
?>

<div class="loan-edit-tabs-shell system-settings-tabs-shell" data-system-settings-tabs>
    <div class="loan-tab-frame">
        <div class="loan-tab-nav" role="tablist" aria-label="System settings sections">
            <button type="button" id="system-settings-tab-system" class="loan-tab-button is-active" data-system-settings-tab-open="system" role="tab" aria-selected="true" aria-controls="system-settings-panel-system">System Settings</button>
            <button type="button" id="system-settings-tab-loan-defaults" class="loan-tab-button" data-system-settings-tab-open="loan-defaults" role="tab" aria-selected="false" aria-controls="system-settings-panel-loan-defaults">Loan Default Settings</button>
            <button type="button" id="system-settings-tab-bulk-assignment" class="loan-tab-button" data-system-settings-tab-open="bulk-assignment" role="tab" aria-selected="false" aria-controls="system-settings-panel-bulk-assignment">Bulk Loan Assignment</button>
        </div>

        <section class="panel loan-edit-tabs system-settings-tabs-panel">
            <form id="system-settings-form" class="system-settings-form" method="post" action="<?= e(url('actions/system_settings_save.php')) ?>">
                <?= csrf_input() ?>
                <div id="system-settings-panel-system" class="loan-tab-panel system-settings-tab-panel is-active" data-system-settings-tab-panel="system" role="tabpanel" aria-labelledby="system-settings-tab-system">
                    <div class="settings-col">
                        <?php if (!$canEditSystemSettings): ?>
                            <p class="muted-block">View only. Only Owner can change system settings.</p>
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
                                <input type="text" name="date_format" maxlength="20" value="<?= e($get('date_format', 'd/m/Y')) ?>" required<?= $disabledAttr ?>>
                            </div>
                            <div class="field">
                                <label>Display Seconds (Time)</label>
                                <?php $displayTimeSeconds = $get('display_time_seconds_enabled', '1'); ?>
                                <select name="display_time_seconds_enabled" required<?= $disabledAttr ?>>
                                    <option value="1" <?= $displayTimeSeconds !== '0' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= $displayTimeSeconds === '0' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Display Cents</label>
                                <?php $displayCents = $get('display_cents_enabled', '1'); ?>
                                <select name="display_cents_enabled" required<?= $disabledAttr ?>>
                                    <option value="1" <?= $displayCents !== '0' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= $displayCents === '0' ? 'selected' : '' ?>>Disabled</option>
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
                                <label>Payment Method Selection</label>
                                <?php $paymentMethodSelection = $get('payment_method_selection_enabled', '1'); ?>
                                <select name="payment_method_selection_enabled" required<?= $disabledAttr ?>>
                                    <option value="1" <?= $paymentMethodSelection !== '0' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= $paymentMethodSelection === '0' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Live Update Interval (seconds)</label>
                                <input type="number" min="3" max="60" name="poll_interval_seconds" value="<?= e($get('poll_interval_seconds', '10')) ?>" required<?= $disabledAttr ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="system-settings-panel-loan-defaults" class="loan-tab-panel system-settings-tab-panel" data-system-settings-tab-panel="loan-defaults" role="tabpanel" aria-labelledby="system-settings-tab-loan-defaults" hidden>
                    <div class="settings-col">
                        <div class="form-grid settings-loan-default-grid">
                            <div class="field">
                                <label>Interest Rate (%)</label>
                                <input type="number" step="0.01" min="0" name="default_interest_rate" value="<?= e($get('default_interest_rate', '0.00')) ?>" required<?= $disabledAttr ?>>
                            </div>
                            <div class="field">
                                <label>Calculate Interest Rate (months)</label>
                                <input type="number" min="1" name="default_interest_rate_months" value="<?= e($get('default_interest_rate_months', '1')) ?>" required<?= $disabledAttr ?>>
                            </div>
                            <div class="field">
                                <label>Timeframe</label>
                                <?php $tUnit = $get('default_timeframe_unit', 'days'); ?>
                                <div class="combo-field">
                                    <input type="number" min="1" name="default_timeframe_value" value="<?= e($get('default_timeframe_value', '30')) ?>" required<?= $disabledAttr ?>>
                                    <select name="default_timeframe_unit" required<?= $disabledAttr ?>>
                                        <option value="days" <?= $tUnit === 'days' ? 'selected' : '' ?>>Days</option>
                                        <option value="months" <?= $tUnit === 'months' ? 'selected' : '' ?>>Months</option>
                                    </select>
                                </div>
                            </div>
                            <div class="field">
                                <label>Installment Frequency</label>
                                <?php $freq = $get('default_installment_frequency', 'daily'); ?>
                                <select name="default_installment_frequency" required<?= $disabledAttr ?>>
                                    <option value="daily" <?= $freq === 'daily' ? 'selected' : '' ?>>Daily</option>
                                    <option value="weekly" <?= $freq === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    <option value="monthly" <?= $freq === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Collector</label>
                                <select name="default_loan_collector_id" required<?= $disabledAttr ?>>
                                    <option value="0" <?= $defaultLoanCollectorId <= 0 ? 'selected' : '' ?>>All users</option>
                                    <?php foreach ($loanDefaultCollectors as $collector): ?>
                                        <?php
                                        $collectorId = (int) ($collector['id'] ?? 0);
                                        $collectorName = trim((string) ($collector['full_name'] ?? ''));
                                        if ($collectorName === '') {
                                            $collectorName = (string) ($collector['username'] ?? ('User #' . $collectorId));
                                        }
                                        ?>
                                        <option value="<?= e((string) $collectorId) ?>" <?= $collectorId === $defaultLoanCollectorId ? 'selected' : '' ?>>
                                            <?= e($collectorName . ' (' . role_display_name((string) ($collector['role'] ?? 'collector')) . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div id="system-settings-panel-bulk-assignment" class="loan-tab-panel system-settings-tab-panel" data-system-settings-tab-panel="bulk-assignment" role="tabpanel" aria-labelledby="system-settings-tab-bulk-assignment" hidden>
                <form class="bulk-loan-assignment-form" method="post" action="<?= e(url('actions/loan_bulk_assign.php')) ?>" data-confirm="Assign all loans to the selected collector? This will update every loan." data-inline-confirm="1" data-inline-confirm-label="Assign All">
                    <?= csrf_input() ?>
                    <div class="settings-col">
                        <div class="form-grid settings-loan-default-grid">
                            <div class="field">
                                <label>Assign All Loans To Collector</label>
                                <select name="assigned_user_id" required<?= $disabledAttr ?>>
                                    <option value="0">All users</option>
                                    <?php foreach ($bulkLoanCollectors as $collector): ?>
                                        <?php
                                        $collectorId = (int) ($collector['id'] ?? 0);
                                        $collectorName = trim((string) ($collector['full_name'] ?? ''));
                                        if ($collectorName === '') {
                                            $collectorName = (string) ($collector['username'] ?? ('User #' . $collectorId));
                                        }
                                        ?>
                                        <option value="<?= e((string) $collectorId) ?>">
                                            <?= e($collectorName . ' (' . role_display_name((string) ($collector['role'] ?? 'collector')) . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary customer-submit-btn"<?= $canEditSystemSettings ? '' : ' disabled' ?>>Assign All Loans</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <div class="form-actions system-settings-page-actions" data-system-settings-save-action>
            <button type="submit" form="system-settings-form" class="btn btn-primary customer-submit-btn"<?= $canEditSystemSettings ? '' : ' disabled' ?>>Save System Settings</button>
        </div>
    </div>
</div>

<script>
(() => {
    const tabRoot = document.querySelector('[data-system-settings-tabs]');
    if (!tabRoot) {
        return;
    }

    const tabButtons = Array.from(tabRoot.querySelectorAll('[data-system-settings-tab-open]'));
    const panels = Array.from(tabRoot.querySelectorAll('[data-system-settings-tab-panel]'));
    const saveAction = tabRoot.querySelector('[data-system-settings-save-action]');
    const validTabs = tabButtons.map((button) => button.getAttribute('data-system-settings-tab-open') || '');

    const openTab = (target, syncHash = true) => {
        if (!validTabs.includes(target)) {
            target = 'system';
        }

        panels.forEach((panel) => {
            const isActive = panel.getAttribute('data-system-settings-tab-panel') === target;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });

        tabButtons.forEach((button) => {
            const isActive = button.getAttribute('data-system-settings-tab-open') === target;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        if (saveAction) {
            saveAction.hidden = target === 'bulk-assignment';
        }

        if (syncHash && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${target}`);
        }
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => openTab(button.getAttribute('data-system-settings-tab-open') || 'system'));
    });

    const initialHash = window.location.hash.replace(/^#/, '');
    if (initialHash) {
        openTab(initialHash, false);
    }
})();
</script>

<?php require __DIR__ . '/../includes/layout_end.php';
