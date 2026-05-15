<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l2', 'collector']);

$pageTitle = 'Add Old Loan';
$activePage = 'loans';

$customers = $pdo->query("SELECT id, customer_code, full_name FROM customers WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
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

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Add Old Loan</h2>
        <div style="display:flex; gap:8px;">
            <a class="btn" href="<?= e(url('pages/customer_create.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus-icon lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </span>
                Add New Customer
            </a>
            <a class="btn" href="<?= e(url('pages/loans.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                </span>
                Back to Loan List
            </a>
        </div>
    </div>

    <?php if (!$customers): ?>
        <p>Please add an active customer first.</p>
    <?php else: ?>
        <form id="legacy-loan-form" class="form-grid" method="post" action="<?= e(url('actions/loan_legacy_save.php')) ?>">
            <?= csrf_input() ?>

            <div class="field">
                <label>Customer</label>
                <select name="customer_id" required>
                    <option value="">Select customer</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= e((string) $customer['id']) ?>"><?= e($customer['customer_code'] . ' - ' . $customer['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

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

            <div class="field" data-legacy-interest-months-field>
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

            <div class="field">
                <label>Loan Issued Date</label>
                <input type="date" name="issued_date" value="<?= e(today()) ?>" max="<?= e(today()) ?>" required>
            </div>

            <div class="field">
                <label>How Much Collected</label>
                <div class="legacy-collected-row">
                    <input type="number" step="0.01" min="0" name="collected_amount" value="0.00" required>
                    <label class="choice-check legacy-collected-toggle">
                        <input type="checkbox" name="collected_including_today" value="1" checked>
                        <span class="choice-check-box" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check-icon lucide-check"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <span class="choice-check-label">Including Today</span>
                    </label>
                </div>
                <small id="legacy-next-collection-hint">Next collection schedule starts from tomorrow.</small>
            </div>

            <div class="field full">
                <label>Notes</label>
                <textarea name="notes" placeholder="Optional"></textarea>
            </div>

            <div class="field full loan-preview-field">
                <label>Repayment Preview</label>
                <div class="calc-preview-grid calc-preview-grid-three">
                    <div class="calc-preview-item">
                        <p>Total Repayable</p>
                        <h3><?= e(currency_label($pdo)) ?> <span id="legacy-preview-total">0.00</span></h3>
                    </div>
                    <div class="calc-preview-item">
                        <p>Collected So Far</p>
                        <h3><?= e(currency_label($pdo)) ?> <span id="legacy-preview-collected">0.00</span></h3>
                    </div>
                    <div class="calc-preview-item">
                        <p>Left to Collect</p>
                        <h3><?= e(currency_label($pdo)) ?> <span id="legacy-preview-remaining">0.00</span></h3>
                    </div>
                </div>
                <div class="calc-preview-grid" style="margin-top:10px;">
                    <div class="calc-preview-item">
                        <p>Per Installment (Remaining Plan)</p>
                        <h3><?= e(currency_label($pdo)) ?> <span id="legacy-preview-installment">0.00</span></h3>
                    </div>
                    <div class="calc-preview-item">
                        <p>No. of Installments (Remaining)</p>
                        <h3><span id="legacy-preview-installment-count">0</span></h3>
                    </div>
                </div>
            </div>

            <div class="field full loan-submit-field">
                <button type="submit" class="btn btn-primary">Save Old Loan</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
