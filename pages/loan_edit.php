<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin', 'collector_l2', 'collector']);

$pageTitle = 'Edit Loan';
$activePage = 'loans';
$loanId = (int) ($_GET['loan_id'] ?? 0);

if ($loanId <= 0) {
    set_flash('error', 'Invalid loan selected.');
    redirect('pages/loans.php');
}

$loanStmt = $pdo->prepare(
    "SELECT l.*, c.full_name
            , l.assigned_user_id AS loan_assigned_user_id
     FROM loans l
     JOIN customers c ON c.id = l.customer_id
     WHERE l.id = :id
     LIMIT 1"
);
$loanStmt->execute(['id' => $loanId]);
$loan = $loanStmt->fetch();

if (!$loan) {
    set_flash('error', 'Loan not found.');
    redirect('pages/loans.php');
}

$customers = $pdo->query("SELECT id, customer_code, full_name FROM customers WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username, role FROM users ORDER BY FIELD(role, 'superadmin', 'admin', 'collector_l2', 'collector_l1', 'collector'), full_name ASC")->fetchAll();
$canEditAssignment = has_role(['superadmin', 'admin']);

$collectionCountStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE loan_id = :loan_id');
$collectionCountStmt->execute(['loan_id' => $loanId]);
$collectionCount = (int) $collectionCountStmt->fetchColumn();
$hasCollections = $collectionCount > 0;

$defaultTimeframeValue = match ((string) $loan['installment_frequency']) {
    'weekly' => (int) $loan['installment_count'] * 7,
    'monthly' => (int) $loan['installment_count'],
    default => (int) $loan['installment_count'],
};
$defaultTimeframeUnit = (string) $loan['installment_frequency'] === 'monthly' ? 'months' : 'days';
$defaultInterestRateType = normalize_interest_rate_type((string) ($loan['interest_rate_type'] ?? 'amount_based'));
$defaultInterestRateMonths = normalize_interest_rate_months((int) ($loan['interest_rate_months'] ?? 1));

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Edit Loan</h2>
        <div class="panel-head-actions">
            <a class="btn" href="<?= e(url('pages/customer_edit.php?customer_id=' . (int) $loan['customer_id'])) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-icon lucide-user"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                View Customer
            </a>
            <a class="btn" href="<?= e(url('pages/collections.php?customer_id=' . (int) $loan['customer_id'])) ?>">Collection History</a>
            <a class="btn" href="<?= e(url('pages/loans.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                </span>
                Back to Loan List
            </a>
        </div>
    </div>

    <?php if ($hasCollections): ?>
        <div class="flash flash-error">This loan already has collections. Repayment structure fields are locked. You can still update assignment, notes and status.</div>
    <?php endif; ?>

    <form id="loan-form" class="form-grid" method="post" action="<?= e(url('actions/loan_update.php')) ?>">
        <input type="hidden" name="loan_id" value="<?= e((string) $loan['id']) ?>">

        <div class="field">
            <label>Customer</label>
            <select name="customer_id" required <?= $hasCollections ? 'disabled' : '' ?>>
                <option value="">Select customer</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= e((string) $customer['id']) ?>" <?= (int) $loan['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>>
                        <?= e($customer['customer_code'] . ' - ' . $customer['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($hasCollections): ?>
                <input type="hidden" name="customer_id" value="<?= e((string) $loan['customer_id']) ?>">
            <?php endif; ?>
        </div>

        <div class="field">
            <label>Principal Amount</label>
            <input type="number" step="0.01" name="principal_amount" value="<?= e((string) $loan['principal_amount']) ?>" required <?= $hasCollections ? 'readonly' : '' ?>>
        </div>

        <div class="field">
            <label>Interest Rate (%)</label>
            <div class="combo-field combo-field-interest">
                <input type="number" step="0.01" name="interest_rate" value="<?= e((string) $loan['interest_rate']) ?>" required <?= $hasCollections ? 'readonly' : '' ?>>
                <select name="interest_rate_type" required <?= $hasCollections ? 'disabled' : '' ?>>
                    <option value="amount_based" <?= $defaultInterestRateType === 'amount_based' ? 'selected' : '' ?>>Amount Based</option>
                    <option value="monthly" <?= $defaultInterestRateType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </div>
            <?php if ($hasCollections): ?>
                <input type="hidden" name="interest_rate_type" value="<?= e($defaultInterestRateType) ?>">
            <?php endif; ?>
        </div>
        <div class="field" data-interest-months-field>
            <label>Calculate Interest Rate (months)</label>
            <input type="number" min="1" name="interest_rate_months" value="<?= e((string) $defaultInterestRateMonths) ?>" <?= $hasCollections ? 'readonly' : '' ?>>
        </div>

        <div class="field">
            <label>Installment Frequency</label>
            <select name="installment_frequency" required <?= $hasCollections ? 'disabled' : '' ?>>
                <option value="daily" <?= $loan['installment_frequency'] === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $loan['installment_frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $loan['installment_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
            <?php if ($hasCollections): ?>
                <input type="hidden" name="installment_frequency" value="<?= e((string) $loan['installment_frequency']) ?>">
            <?php endif; ?>
        </div>

        <div class="field">
            <label>Timeframe</label>
            <div class="combo-field">
                <input type="number" min="1" name="timeframe_value" value="<?= e((string) $defaultTimeframeValue) ?>" required <?= $hasCollections ? 'readonly' : '' ?>>
                <select name="timeframe_unit" required <?= $hasCollections ? 'disabled' : '' ?>>
                    <option value="days" <?= $defaultTimeframeUnit === 'days' ? 'selected' : '' ?>>Days</option>
                    <option value="months" <?= $defaultTimeframeUnit === 'months' ? 'selected' : '' ?>>Months</option>
                </select>
                <?php if ($hasCollections): ?>
                    <input type="hidden" name="timeframe_unit" value="<?= e($defaultTimeframeUnit) ?>">
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label>Status</label>
            <select name="status" required>
                <option value="active" <?= $loan['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="closed" <?= $loan['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                <option value="defaulted" <?= $loan['status'] === 'defaulted' ? 'selected' : '' ?>>Defaulted</option>
            </select>
        </div>

        <div class="field">
            <label>Assign Loan To User</label>
            <select name="assigned_user_id" <?= $canEditAssignment ? '' : 'disabled' ?>>
                <option value="">Unassigned</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e((string) $user['id']) ?>" <?= (int) $loan['loan_assigned_user_id'] === (int) $user['id'] ? 'selected' : '' ?>>
                        <?= e($user['full_name'] . ' (' . $user['username'] . ' - ' . role_display_name((string) $user['role']) . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$canEditAssignment): ?>
                <input type="hidden" name="assigned_user_id" value="<?= e((string) ($loan['loan_assigned_user_id'] ?? '')) ?>">
            <?php endif; ?>
        </div>

        <div class="field full">
            <label>Notes</label>
            <textarea name="notes" placeholder="Optional"><?= e((string) ($loan['notes'] ?? '')) ?></textarea>
        </div>

        <div class="field full loan-preview-field">
            <label>Repayment Preview</label>
            <div class="calc-preview-grid calc-preview-grid-three">
                <div class="calc-preview-item">
                    <p>Total Repayable</p>
                    <h3>LKR <span id="preview-total"><?= e(money((float) $loan['total_amount'])) ?></span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>Per Installment</p>
                    <h3>LKR <span id="preview-installment"><?= e(money((float) $loan['installment_amount'])) ?></span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>No. of Installments</p>
                    <h3><span id="preview-installment-count"><?= e((string) $loan['installment_count']) ?></span></h3>
                </div>
            </div>
        </div>

        <div class="field full loan-submit-field">
            <button type="submit" class="btn btn-primary">Update Loan</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
