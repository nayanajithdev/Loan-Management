<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_permission('loans.view');

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

$currentAssignedUserId = (int) ($loan['loan_assigned_user_id'] ?? 0);
if ($currentAssignedUserId <= 0) {
    $currentAssignedUserId = default_loan_collector_id($pdo);
}

$customers = $pdo->query("SELECT id, customer_code, full_name, nic FROM customers WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$users = assignable_collector_rows($pdo, $currentAssignedUserId);
$canEditAssignment = can('loans.assign');
$canScheduleNextPayment = can('collections.schedule');
$canDeleteLoan = can('loans.delete');
$canViewCustomer = can('customers.view');
$canViewCollectionHistory = can('collections.history');
$canRecordCollection = can('collections.record');

$collectionCountStmt = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE loan_id = :loan_id');
$collectionCountStmt->execute(['loan_id' => $loanId]);
$collectionCount = (int) $collectionCountStmt->fetchColumn();
$hasCollections = $collectionCount > 0;
$outstandingStmt = $pdo->prepare('SELECT COALESCE(SUM(due_amount - paid_amount), 0) FROM loan_installments WHERE loan_id = :loan_id');
$outstandingStmt->execute(['loan_id' => $loanId]);
$currentOutstanding = (float) $outstandingStmt->fetchColumn();
$hasLegacyPreCollected = !$hasCollections && ($currentOutstanding + 0.009) < (float) $loan['total_amount'];
$repaymentLocked = $hasCollections || $hasLegacyPreCollected;

$defaultTimeframeValue = match ((string) $loan['installment_frequency']) {
    'weekly' => (int) $loan['installment_count'] * 7,
    'monthly' => (int) $loan['installment_count'],
    default => (int) $loan['installment_count'],
};
$defaultTimeframeUnit = (string) $loan['installment_frequency'] === 'monthly' ? 'months' : 'days';
$defaultInterestRateType = normalize_interest_rate_type((string) ($loan['interest_rate_type'] ?? 'amount_based'));
$defaultInterestRateMonths = normalize_interest_rate_months((int) ($loan['interest_rate_months'] ?? 1));
$issuedDate = (string) ($loan['issued_date'] ?? '');
if ($issuedDate === '') {
    $issuedDate = (string) ($loan['start_date'] ?? '');
}
if ($issuedDate === '') {
    $issuedDate = substr((string) ($loan['created_at'] ?? today()), 0, 10);
}
$tomorrowDate = (new DateTimeImmutable(today()))->add(new DateInterval('P1D'))->format('Y-m-d');
$loanDisplayNumber = (string) ($loan['loan_number'] ?? ('#' . $loanId));
$loanEndDate = (string) ($loan['end_date'] ?? '');
if ($loanEndDate === '') {
    $loanEndDate = loan_original_schedule_end_date($pdo, $loanId) ?? '';
}
$holidayDates = holiday_date_list($pdo);

$collectionHistoryStmt = $pdo->prepare(
    "SELECT
        COALESCE(col.payment_ref, CONCAT('legacy-', col.id)) AS payment_ref,
        MAX(col.id) AS latest_id,
        MAX(col.collected_on) AS collected_on,
        MAX(col.created_at) AS collected_at,
        MAX(col.method) AS method,
        MAX(col.note) AS note,
        SUM(col.amount) AS amount,
        GROUP_CONCAT(DISTINCT CONCAT('#', li.installment_no) ORDER BY li.installment_no SEPARATOR ', ') AS installments,
        COALESCE(MAX(u.full_name), 'Unknown') AS collected_by_name,
        MAX(CASE WHEN col.installment_id IS NULL THEN 1 ELSE 0 END) AS has_advance
     FROM collections col
     LEFT JOIN loan_installments li ON li.id = col.installment_id
     LEFT JOIN users u ON u.id = col.collected_by_user_id
     WHERE col.loan_id = :loan_id
     GROUP BY COALESCE(col.payment_ref, CONCAT('legacy-', col.id))
     ORDER BY latest_id DESC
     LIMIT 50"
);
$collectionHistoryStmt->execute(['loan_id' => $loanId]);
$loanCollectionHistory = $collectionHistoryStmt->fetchAll();
$loanTotalRepayable = (float) ($loan['total_amount'] ?? 0);
$loanCollectedStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE loan_id = :loan_id');
$loanCollectedStmt->execute(['loan_id' => $loanId]);
$loanTotalCollected = (float) $loanCollectedStmt->fetchColumn();
$loanBalance = max(0.0, $loanTotalRepayable - $loanTotalCollected);
$loanProgressPercent = $loanTotalRepayable > 0
    ? min(100.0, ($loanTotalCollected / $loanTotalRepayable) * 100)
    : 0.0;
$loanProgressStyleValue = number_format($loanProgressPercent, 2, '.', '');
$loanProgressLabel = number_format($loanProgressPercent, 1) . '%';
$loanArrearsStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM loan_installments
     WHERE loan_id = :loan_id
       AND due_date < :today
       AND due_amount > paid_amount'
);
$loanArrearsStmt->execute([
    'loan_id' => $loanId,
    'today' => today(),
]);
$loanArrearsCount = (int) $loanArrearsStmt->fetchColumn();
$loanArrearsLabel = $loanArrearsCount === 1 ? '1 installment' : $loanArrearsCount . ' installments';

$nextInstallmentStmt = $pdo->prepare(
    "SELECT *
     FROM loan_installments
     WHERE loan_id = :loan_id
       AND status IN ('pending', 'partial', 'overdue')
       AND due_amount > paid_amount
     ORDER BY due_date ASC, installment_no ASC
     LIMIT 1"
);
$nextInstallmentStmt->execute(['loan_id' => $loanId]);
$nextInstallment = $nextInstallmentStmt->fetch() ?: null;
$currentCollectible = $nextInstallment;
$collectibleBalance = $currentCollectible
    ? max(0.0, (float) $currentCollectible['due_amount'] - (float) $currentCollectible['paid_amount'])
    : 0.0;
$autoFillAmountReceived = system_setting($pdo, 'auto_fill_amount_received', '1') !== '0';

require __DIR__ . '/../includes/layout_start.php';
?>

<div class="loan-edit-tabs-shell" data-loan-tabs>
    <div class="loan-edit-actionbar">
        <div class="panel-head-actions">
            <a class="btn" href="<?= e(url('pages/loans.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                </span>
                Back to Loan List
            </a>
        </div>
        <div class="panel-head-actions">
            <?php if ($canViewCustomer): ?>
                <a class="btn" href="<?= e(url('pages/customer_edit.php?customer_id=' . (int) $loan['customer_id'])) ?>">
                    <span class="btn-icon-inline" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-icon lucide-user"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    View Customer
                </a>
            <?php endif; ?>
            <?php if ($canDeleteLoan): ?>
                <form method="post" action="<?= e(url('actions/loan_delete.php')) ?>" class="inline-form" onsubmit="return confirm('Delete this loan permanently? This action cannot be undone.');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="loan_id" value="<?= e((string) $loanId) ?>">
                    <button type="submit" class="btn btn-danger">
                        <span class="btn-icon-inline" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-icon lucide-trash"><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </span>
                        Delete Loan
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="loan-tab-frame">
    <div class="loan-tab-nav" role="tablist" aria-label="Loan edit sections">
        <button type="button" class="loan-tab-button is-active" data-loan-tab-open="details" role="tab" aria-selected="true">Loan Details</button>
        <button type="button" class="loan-tab-button" data-loan-tab-open="collections" role="tab" aria-selected="false">Collection History</button>
    </div>

    <section class="panel loan-edit-tabs">
    <div class="loan-tab-panel is-active" data-loan-tab-panel="details" role="tabpanel">
        <?php if ($repaymentLocked): ?>
            <div class="flash flash-warning">
                <?php if ($hasCollections): ?>
                    This loan already has collections. Repayment structure fields are locked. You can still update assignment, notes and status.
                <?php else: ?>
                    This loan has collected value. Repayment structure fields are locked to protect collected totals. You can still update assignment, notes and status.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form
            id="loan-form"
            class="form-grid"
            method="post"
            action="<?= e(url('actions/loan_update.php')) ?>"
            data-start-date="<?= e($issuedDate) ?>"
            data-first-due-date="<?= e((string) ($loan['first_due_date'] ?? '')) ?>"
            data-repayment-locked="<?= $repaymentLocked ? '1' : '0' ?>"
            data-holiday-dates="<?= e((string) json_encode($holidayDates, JSON_UNESCAPED_SLASHES)) ?>"
        >
        <?= csrf_input() ?>
        <input type="hidden" name="loan_id" value="<?= e((string) $loan['id']) ?>">

        <div class="loan-form-divider">Loan Details</div>
        <div class="field">
            <label>Customer</label>
            <select name="customer_id" required <?= $repaymentLocked ? 'disabled' : '' ?>>
                <option value="">Select customer</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= e((string) $customer['id']) ?>" <?= (int) $loan['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>>
                        <?= e(customer_display_label($customer)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($repaymentLocked): ?>
                <input type="hidden" name="customer_id" value="<?= e((string) $loan['customer_id']) ?>">
            <?php endif; ?>
        </div>

        <div class="field">
            <label>Principal Amount</label>
            <input type="number" step="0.01" name="principal_amount" value="<?= e((string) $loan['principal_amount']) ?>" required <?= $repaymentLocked ? 'readonly' : '' ?>>
        </div>

        <div class="field">
            <label>Loan Issued Date</label>
            <input type="date" name="issued_date" value="<?= e($issuedDate) ?>" required>
        </div>

        <div class="loan-form-divider">Terms &amp; Repayment</div>
        <div class="field">
            <label>Interest Rate (%)</label>
            <div class="combo-field combo-field-interest">
                <input type="number" step="0.01" name="interest_rate" value="<?= e((string) $loan['interest_rate']) ?>" required <?= $repaymentLocked ? 'readonly' : '' ?>>
                <select name="interest_rate_type" required <?= $repaymentLocked ? 'disabled' : '' ?>>
                    <option value="amount_based" <?= $defaultInterestRateType === 'amount_based' ? 'selected' : '' ?>>Amount Based</option>
                    <option value="monthly" <?= $defaultInterestRateType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </div>
            <?php if ($repaymentLocked): ?>
                <input type="hidden" name="interest_rate_type" value="<?= e($defaultInterestRateType) ?>">
            <?php endif; ?>
        </div>
        <div class="field" data-interest-months-field>
            <label>Calculate Interest Rate (months)</label>
            <input type="number" min="1" name="interest_rate_months" value="<?= e((string) $defaultInterestRateMonths) ?>" <?= $repaymentLocked ? 'readonly' : '' ?>>
        </div>

        <div class="field">
            <label>Installment Frequency</label>
            <select name="installment_frequency" required <?= $repaymentLocked ? 'disabled' : '' ?>>
                <option value="daily" <?= $loan['installment_frequency'] === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $loan['installment_frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $loan['installment_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
            <?php if ($repaymentLocked): ?>
                <input type="hidden" name="installment_frequency" value="<?= e((string) $loan['installment_frequency']) ?>">
            <?php endif; ?>
        </div>

        <div class="field">
            <label>Timeframe</label>
            <div class="combo-field">
                <input type="number" min="1" name="timeframe_value" value="<?= e((string) $defaultTimeframeValue) ?>" required <?= $repaymentLocked ? 'readonly' : '' ?>>
                <select name="timeframe_unit" required <?= $repaymentLocked ? 'disabled' : '' ?>>
                    <option value="days" <?= $defaultTimeframeUnit === 'days' ? 'selected' : '' ?>>Days</option>
                    <option value="months" <?= $defaultTimeframeUnit === 'months' ? 'selected' : '' ?>>Months</option>
                </select>
                <?php if ($repaymentLocked): ?>
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
            <label>Assign Loan To Collector</label>
            <select name="assigned_user_id" <?= $canEditAssignment ? '' : 'disabled' ?>>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e((string) $user['id']) ?>" <?= $currentAssignedUserId === (int) $user['id'] ? 'selected' : '' ?>>
                        <?= e($user['full_name'] . ' (' . $user['username'] . ' - ' . role_display_name((string) $user['role']) . ((string) ($user['status'] ?? 'active') !== 'active' ? ', inactive' : '') . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$canEditAssignment): ?>
                <input type="hidden" name="assigned_user_id" value="<?= e((string) $currentAssignedUserId) ?>">
            <?php endif; ?>
        </div>

        <div class="loan-form-divider">Installment Options</div>
        <div class="field loan-schedule-field">
            <label>Schedule Next Payment</label>
            <div class="loan-schedule-row">
                <div class="loan-schedule-checkbox-field">
                    <input type="checkbox" name="schedule_next_payment" id="schedule-next-payment-toggle" value="1" class="loan-schedule-checkbox-input" <?= $canScheduleNextPayment ? '' : 'disabled' ?>>
                </div>
                <input type="date" name="next_payment_date" id="next-payment-date-input" value="<?= e($tomorrowDate) ?>" min="<?= e($tomorrowDate) ?>" disabled>
            </div>
            <?php if (!$canScheduleNextPayment): ?>
                <small>You do not have permission to schedule payments.</small>
            <?php endif; ?>
        </div>

        <div class="loan-form-divider">Notes</div>
        <div class="field full">
            <label class="sr-only">Notes</label>
            <textarea name="notes" placeholder="Optional"><?= e((string) ($loan['notes'] ?? '')) ?></textarea>
        </div>

        <div class="loan-form-divider">Repayment Preview</div>
        <div class="field full loan-preview-field">
            <div class="calc-preview-grid calc-preview-grid-four">
                <div class="calc-preview-item">
                    <p>Total Repayable</p>
                    <h3><?= e(currency_label($pdo)) ?> <span id="preview-total"><?= e(money((float) $loan['total_amount'])) ?></span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>Per Installment</p>
                    <h3><?= e(currency_label($pdo)) ?> <span id="preview-installment"><?= e(money((float) $loan['installment_amount'])) ?></span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>No. of Installments</p>
                    <h3><span id="preview-installment-count"><?= e((string) $loan['installment_count']) ?></span></h3>
                </div>
                <div class="calc-preview-item">
                    <p>Loan End Date</p>
                    <h3><span id="preview-end-date"><?= e($loanEndDate !== '' ? display_date($loanEndDate) : '-') ?></span></h3>
                </div>
            </div>
        </div>

        <div class="field full loan-submit-field">
            <button type="submit" class="btn btn-primary">Update Loan</button>
        </div>
        </form>
    </div>

    <div class="loan-tab-panel" data-loan-tab-panel="collections" role="tabpanel" hidden>
        <section class="loan-payment-layout">
            <div class="loan-history-column">
                <section class="loan-progress-panel" aria-label="Loan progress">
                    <div class="loan-progress-head">
                        <div>
                            <h2 class="loan-progress-title">Loan Progress</h2>
                            <p class="loan-progress-subtitle">Track repayment progress for this loan.</p>
                        </div>
                        <span class="loan-progress-pill"><?= e($loanProgressLabel) ?></span>
                    </div>
                    <div class="loan-progress-track" aria-hidden="true">
                        <div class="loan-progress-fill" style="--loan-progress: <?= e($loanProgressStyleValue) ?>%;"></div>
                    </div>
                    <div class="loan-progress-stats">
                        <div class="loan-progress-stat">
                            <span>Total Repayable</span>
                            <strong><?= e(money_label($pdo, $loanTotalRepayable)) ?></strong>
                        </div>
                        <div class="loan-progress-stat is-collected">
                            <span>Collected</span>
                            <strong><?= e(money_label($pdo, $loanTotalCollected)) ?></strong>
                        </div>
                        <div class="loan-progress-stat is-balance">
                            <span>Balance</span>
                            <strong><?= e(money_label($pdo, $loanBalance)) ?></strong>
                        </div>
                        <div class="loan-progress-stat is-arrears <?= $loanArrearsCount > 0 ? 'has-arrears' : '' ?>">
                            <span>Arrears</span>
                            <strong><?= e($loanArrearsLabel) ?></strong>
                        </div>
                    </div>
                </section>

    <div class="panel loan-history-panel">
        <div class="panel-head">
            <h2 class="panel-title">Collection History</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Inst.</th>
                        <th>Collected By</th>
                        <th>Method</th>
                        <th>Note</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$loanCollectionHistory): ?>
                        <tr>
                            <td colspan="6">No collections recorded for this loan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($loanCollectionHistory as $history): ?>
                            <?php
                            $noteParts = collection_note_split((string) ($history['note'] ?? ''));
                            $noteText = trim((string) ($noteParts['public'] ?? ''));
                            $installments = trim((string) ($history['installments'] ?? ''));
                            if ($installments === '') {
                                $installments = (int) ($history['has_advance'] ?? 0) === 1 ? 'Advance' : '-';
                            }
                            $collectedAt = (string) ($history['collected_at'] ?? '');
                            $collectedDisplay = $collectedAt !== '' ? display_datetime($collectedAt) : '-';
                            ?>
                            <tr>
                                <td><?= e($collectedDisplay) ?></td>
                                <td><?= e($installments) ?></td>
                                <td><?= e((string) ($history['collected_by_name'] ?? 'Unknown')) ?></td>
                                <td><?= e(ucfirst((string) ($history['method'] ?? 'cash'))) ?></td>
                                <td><?= e($noteText === '' ? '-' : $noteText) ?></td>
                                <td class="text-right"><?= e(money_label($pdo, (float) $history['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>

    <aside class="panel loan-collect-panel">
        <div class="panel-head">
            <h2 class="panel-title">Collect Payment</h2>
        </div>

        <?php if (!$canRecordCollection): ?>
            <p class="loan-collect-empty">You do not have permission to record collections.</p>
        <?php elseif ((string) $loan['status'] === 'closed'): ?>
            <p class="loan-collect-empty">This loan is closed.</p>
        <?php elseif (!$currentCollectible): ?>
            <p class="loan-collect-empty">No pending installments available for this loan.</p>
        <?php else: ?>
            <p class="loan-collect-empty">Record any payment amount for the next installment.</p>
            <div class="loan-collect-summary">
                <div class="loan-collect-item">
                    <span>Loan</span>
                    <strong><?= e($loanDisplayNumber) ?></strong>
                </div>
                <div class="loan-collect-item">
                    <span>Customer</span>
                    <strong><?= e((string) $loan['full_name']) ?></strong>
                </div>
                <div class="loan-collect-item">
                    <span>Installment</span>
                    <strong>#<?= e((string) $currentCollectible['installment_no']) ?> | <?= e(display_date((string) $currentCollectible['due_date'])) ?></strong>
                </div>
                <div class="loan-collect-item">
                    <span>Due Amount</span>
                    <strong><?= e(money_label($pdo, $collectibleBalance)) ?></strong>
                </div>
            </div>

            <form class="loan-collect-form" method="post" action="<?= e(url('actions/collection_save.php')) ?>" data-confirm="Confirm this collection payment?">
                <?= csrf_input() ?>
                <input type="hidden" name="loan_id" value="<?= e((string) $loanId) ?>">
                <input type="hidden" name="installment_id" value="<?= e((string) $currentCollectible['id']) ?>">
                <input type="hidden" name="collected_on" value="<?= e(today()) ?>">
                <input type="hidden" name="return_to" value="<?= e('pages/loan_edit.php?loan_id=' . $loanId . '#collections') ?>">

                <div class="field">
                    <label>Amount Received</label>
                    <input type="number" step="0.01" min="0.01" name="amount" value="<?= e($autoFillAmountReceived ? number_format($collectibleBalance, 2, '.', '') : '') ?>" required>
                </div>
                <div class="field">
                    <label>Method</label>
                    <select name="method">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank</option>
                        <option value="online">Online</option>
                    </select>
                </div>
                <div class="field">
                    <label>Note</label>
                    <textarea name="note" placeholder="Optional"></textarea>
                </div>
                <button class="btn btn-primary" type="submit">Save Collection</button>
            </form>
        <?php endif; ?>
    </aside>
</section>
    </div>
    </section>
    </div>
</div>

<script>
(() => {
    const tabRoot = document.querySelector('[data-loan-tabs]');
    if (!tabRoot) {
        return;
    }

    const tabButtons = Array.from(tabRoot.querySelectorAll('[data-loan-tab-open]'));
    const panels = Array.from(tabRoot.querySelectorAll('[data-loan-tab-panel]'));

    const openTab = (target) => {
        panels.forEach((panel) => {
            const isActive = panel.getAttribute('data-loan-tab-panel') === target;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });

        tabButtons.forEach((button) => {
            const isActive = button.getAttribute('data-loan-tab-open') === target;
            button.classList.toggle('is-active', isActive && button.classList.contains('loan-tab-button'));
            if (button.classList.contains('loan-tab-button')) {
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            }
        });

        if (target === 'collections') {
            history.replaceState(null, '', '#collections');
        } else if (window.location.hash === '#collections') {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            openTab(button.getAttribute('data-loan-tab-open') || 'details');
        });
    });

    if (window.location.hash === '#collections') {
        openTab('collections');
    }
})();

(() => {
    const scheduleToggle = document.getElementById('schedule-next-payment-toggle');
    const scheduleInput = document.getElementById('next-payment-date-input');
    if (!scheduleToggle || !scheduleInput) {
        return;
    }

    const syncSchedule = () => {
        const enabled = scheduleToggle.checked;
        scheduleInput.disabled = !enabled;
        scheduleInput.required = enabled;
    };

    scheduleToggle.addEventListener('change', syncSchedule);
    syncSchedule();
})();
</script>

<?php require __DIR__ . '/../includes/layout_end.php';
