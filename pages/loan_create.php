<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('loans.create');

$pageTitle = 'Create Loan';
$activePage = 'loans';
$canCreateCustomer = can('customers.create');
$canAssignLoan = can('loans.assign');

$customers = $pdo->query("SELECT id, customer_code, full_name, nic FROM customers WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$collectors = $canAssignLoan
    ? assignable_collector_rows($pdo)
    : [];
$defaultCollectorId = default_loan_collector_id($pdo);
$defaultInterestRate = system_setting($pdo, 'default_interest_rate', '0.00');
$defaultInterestRateType = 'monthly';
$defaultInterestRateMonths = normalize_interest_rate_months((int) system_setting($pdo, 'default_interest_rate_months', '1'));
$defaultFrequency = system_setting($pdo, 'default_installment_frequency', 'daily');
$defaultTimeframeValue = (int) system_setting($pdo, 'default_timeframe_value', '30');
$defaultTimeframeUnit = system_setting($pdo, 'default_timeframe_unit', 'days');
$suggestedLoanNumber = next_loan_number($pdo);
$defaultIssuedDate = today();
$defaultFirstPaymentDate = next_collectible_date($pdo, (new DateTimeImmutable($defaultIssuedDate))->add(new DateInterval('P1D'))->format('Y-m-d'));
$scheduleStartDate = $defaultIssuedDate;
$holidayDates = holiday_date_list($pdo);

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

<div class="create-loan-actionbar">
    <div class="panel-head-actions">
        <?php if ($canCreateCustomer): ?>
            <button
                class="btn inline-customer-toggle"
                type="button"
                aria-pressed="<?= !$customers ? 'true' : 'false' ?>"
                data-inline-customer-toggle
                data-inline-customer-force-new="<?= !$customers ? '1' : '0' ?>"
            >
                <span class="inline-customer-checkbox" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-plus-icon lucide-user-plus"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                </span>
                New Customer
            </button>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('pages/loans.php')) ?>">
            <span class="btn-icon-inline" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
            </span>
            Back to Loan List
        </a>
    </div>
</div>

<?php if (!$customers && !$canCreateCustomer): ?>
    <section class="panel">
        <p>Please add an active customer first.</p>
    </section>
<?php else: ?>
    <form
        id="loan-form"
        class="create-loan-form"
        method="post"
        action="<?= e(url('actions/loan_save.php')) ?>"
        data-start-date="<?= e($scheduleStartDate) ?>"
        data-holiday-dates="<?= e((string) json_encode($holidayDates, JSON_UNESCAPED_SLASHES)) ?>"
        data-money-decimals="<?= e((string) money_display_decimals($pdo)) ?>"
    >
            <?= csrf_input() ?>
            <?php if ($canCreateCustomer): ?>
                <input type="hidden" name="create_new_customer" value="<?= !$customers ? '1' : '0' ?>" data-inline-customer-flag>
            <?php endif; ?>
            <div class="create-loan-body">
                <div class="create-loan-left">
                    <?php if ($canCreateCustomer): ?>
                        <section class="inline-customer-panel" data-inline-customer-panel <?= $customers ? 'hidden' : '' ?>>
                            <div class="inline-customer-panel-head">
                                <h3>Customer Personal Info</h3>
                            </div>
                            <div class="form-grid inline-customer-grid">
                                <div class="field">
                                    <label>Full Name</label>
                                    <input type="text" name="new_customer_full_name" data-inline-customer-required>
                                </div>
                                <div class="field">
                                    <label>Phone</label>
                                    <input type="text" name="new_customer_phone" data-inline-customer-required>
                                </div>
                                <div class="field">
                                    <label>NIC / ID</label>
                                    <input type="text" name="new_customer_nic" data-inline-customer-required>
                                </div>
                                <div class="field">
                                    <label>Address</label>
                                    <textarea name="new_customer_address"></textarea>
                                </div>
                                <div class="field">
                                    <label>Note</label>
                                    <textarea name="new_customer_note" placeholder="Optional"></textarea>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                    <div class="create-loan-main form-grid">
                    <div class="loan-form-divider">Loan Details</div>
                    <div class="field">
                        <label>Loan No</label>
                        <input type="text" name="loan_number" value="<?= e($suggestedLoanNumber) ?>" inputmode="numeric" pattern="\d+" required>
                    </div>
                    <div class="field">
                        <label>Customer</label>
                        <div class="searchable-select" data-searchable-select>
                            <input type="hidden" name="customer_id" data-select-value required>
                            <input type="search" data-select-search placeholder="Select customer" autocomplete="off" role="combobox" aria-expanded="false">
                            <div class="searchable-select-menu" data-select-menu hidden>
                                <?php foreach ($customers as $customer): ?>
                                    <button type="button" data-select-option value="<?= e((string) $customer['id']) ?>">
                                        <?= e(customer_display_label($customer)) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <small class="searchable-select-empty" data-select-empty hidden>No matching customers.</small>
                        </div>
                    </div>
                    <div class="field">
                        <label>Loan Issued Date</label>
                        <input type="date" name="issued_date" value="<?= e($defaultIssuedDate) ?>" required>
                    </div>
                    <div class="loan-form-divider">Terms &amp; Repayment</div>
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
                        <input type="number" min="1" name="interest_rate_months" value="<?= e((string) $defaultInterestRateMonths) ?>">
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
                    <?php if ($canAssignLoan): ?>
                        <div class="field">
                            <label>Assign Loan To Collector</label>
                            <select name="assigned_user_id" required>
                                <option value="0">All users</option>
                                <?php foreach ($collectors as $collector): ?>
                                    <?php $collectorId = (int) $collector['id']; ?>
                                    <option value="<?= e((string) $collectorId) ?>" <?= $collectorId === $defaultCollectorId ? 'selected' : '' ?>>
                                        <?= e($collector['full_name'] . ' (' . $collector['username'] . ' - ' . role_display_name((string) $collector['role']) . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="loan-form-divider">Installment Options</div>
                    <div class="field loan-schedule-field">
                        <label>Schedule First Payment</label>
                        <input type="date" name="first_payment_date" value="<?= e($defaultFirstPaymentDate) ?>" min="<?= e($defaultFirstPaymentDate) ?>" required>
                    </div>
                    <div class="field loan-installment-amount-field">
                        <label>Change Installment Amount</label>
                        <input type="number" step="0.01" min="0.01" name="rounded_installment_amount" id="rounded-installment-amount">
                    </div>
                    <div class="loan-form-divider">Notes</div>
                    <div class="field full">
                        <label>Note</label>
                        <textarea name="notes" placeholder="Optional"></textarea>
                    </div>
                    </div>
                </div>

                <aside class="create-loan-preview-panel">
                    <h3 class="create-loan-preview-title">Repayment Preview</h3>
                    <div class="calc-preview-grid calc-preview-grid-four create-loan-preview-grid">
                        <div class="calc-preview-item">
                            <p>Total Repayable</p>
                            <h3><?= e(currency_label($pdo)) ?> <span id="preview-total"><?= e(money(0, money_display_decimals($pdo))) ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>Per Installment</p>
                            <h3><?= e(currency_label($pdo)) ?> <span id="preview-installment"><?= e(money(0, money_display_decimals($pdo))) ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>No. of Installments</p>
                            <h3><span id="preview-installment-count"><?= e((string) $defaultInstallmentCount) ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>Loan End Date</p>
                            <h3><span id="preview-end-date">-</span></h3>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary create-loan-submit-btn">Create Loan</button>
                </aside>
            </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_end.php';
