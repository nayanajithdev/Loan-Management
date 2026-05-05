<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l2', 'collector']);

$pageTitle = 'Calculator';
$activePage = 'calculator';
$defaultInterestRate = system_setting($pdo, 'default_interest_rate', '0.00');
$defaultInterestRateType = 'amount_based';
$defaultFrequency = system_setting($pdo, 'default_installment_frequency', 'daily');
$defaultTimeframeValue = (int) system_setting($pdo, 'default_timeframe_value', '30');
$defaultTimeframeUnit = system_setting($pdo, 'default_timeframe_unit', 'days');

if (!in_array($defaultFrequency, ['daily', 'weekly', 'monthly'], true)) {
    $defaultFrequency = 'daily';
}
if (!in_array($defaultTimeframeUnit, ['days', 'months'], true)) {
    $defaultTimeframeUnit = 'days';
}
$defaultTimeframeValue = max(1, $defaultTimeframeValue);
$defaultInstallmentCount = installment_count_from_timeframe($defaultFrequency, $defaultTimeframeValue, $defaultTimeframeUnit);

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Loan Calculator</h2>
    </div>

    <form id="loan-form" class="form-grid" onsubmit="return false;">
        <div class="field">
            <label>Principal Amount</label>
            <input type="number" step="0.01" name="principal_amount" required>
        </div>
        <div class="field">
            <label>Interest Rate (%)</label>
            <div class="combo-field combo-field-interest">
                <input type="number" step="0.01" name="interest_rate" value="<?= e($defaultInterestRate) ?>" required>
                <select name="interest_rate_type" required>
                    <option value="amount_based" <?= $defaultInterestRateType === 'amount_based' ? 'selected' : '' ?>>Amount Based</option>
                    <option value="monthly" <?= $defaultInterestRateType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </div>
        </div>
        <div class="field" data-interest-months-field>
            <label>Calculate Interest Rate (months)</label>
            <input type="number" min="1" name="interest_rate_months" value="1">
        </div>
        <div class="field">
            <label>Installment Frequency</label>
            <select name="installment_frequency" required>
                <option value="daily" <?= $defaultFrequency === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $defaultFrequency === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $defaultFrequency === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
        </div>
        <div class="field">
            <label>Timeframe</label>
            <div class="combo-field">
                <input type="number" min="1" name="timeframe_value" value="<?= e((string) $defaultTimeframeValue) ?>" required>
                <select name="timeframe_unit" required>
                    <option value="days" <?= $defaultTimeframeUnit === 'days' ? 'selected' : '' ?>>Days</option>
                    <option value="months" <?= $defaultTimeframeUnit === 'months' ? 'selected' : '' ?>>Months</option>
                </select>
            </div>
        </div>
        <div class="field full">
            <label>Repayment Preview</label>
            <div class="calc-preview-grid calc-preview-grid-three">
                <div class="calc-preview-item">
                    <p>Total Repayable</p>
                    <h3><?= e(currency_label($pdo)) ?> <span id="preview-total">0.00</span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>Per Installment</p>
                    <h3><?= e(currency_label($pdo)) ?> <span id="preview-installment">0.00</span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>Profit</p>
                    <h3><?= e(currency_label($pdo)) ?> <span id="preview-profit">0.00</span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>No. of Installments</p>
                    <h3><span id="preview-installment-count"><?= e((string) $defaultInstallmentCount) ?></span></h3>
                </div>
            </div>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
