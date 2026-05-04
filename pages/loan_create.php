<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Create Loan';
$activePage = 'loans';

$customers = $pdo->query("SELECT id, customer_code, full_name FROM customers WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$defaultFirstDueDate = (new DateTimeImmutable(today()))->add(new DateInterval('P1D'))->format('Y-m-d');

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Create Loan</h2>
        <a class="btn" href="<?= e(url('pages/loans.php')) ?>">Back to Loan List</a>
    </div>

    <?php if (!$customers): ?>
        <p>Please add an active customer first.</p>
    <?php else: ?>
        <form id="loan-form" class="form-grid" method="post" action="<?= e(url('actions/loan_save.php')) ?>">
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
                <input type="number" step="0.01" name="interest_rate" value="0" required>
            </div>
            <div class="field">
                <label>Installment Frequency</label>
                <select name="installment_frequency" required>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <div class="field">
                <label>Timeframe</label>
                <div class="combo-field">
                    <input type="number" min="1" name="timeframe_value" value="30" required>
                    <select name="timeframe_unit" required>
                        <option value="days">Days</option>
                        <option value="months">Months</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>No. of Installments (Auto)</label>
                <input type="number" name="installment_count_display" id="installment-count-display" value="30" readonly>
            </div>
            <div class="field">
                <label>First Due Date</label>
                <input type="date" name="first_due_date" value="<?= e($defaultFirstDueDate) ?>" min="<?= e($defaultFirstDueDate) ?>" required>
            </div>
            <div class="field full">
                <label>Notes</label>
                <textarea name="notes" placeholder="Optional"></textarea>
            </div>
            <div class="field">
                <label>Repayment Preview</label>
                <div class="metric-box">
                    <p>Total Repayable</p>
                    <h3>LKR <span id="preview-total">0.00</span></h3>
                    <p>Per Installment</p>
                    <h3>LKR <span id="preview-installment">0.00</span></h3>
                </div>
            </div>
            <div class="field" style="align-self:end;">
                <button type="submit" class="btn btn-primary">Save Loan + Generate Schedule</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
