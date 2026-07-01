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

$customers = $pdo->query("SELECT id, customer_code, full_name FROM customers WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username, role FROM users ORDER BY FIELD(role, 'superadmin', 'admin', 'collector'), full_name ASC")->fetchAll();
$canEditAssignment = can('loans.assign');
$canScheduleNextPayment = can('collections.schedule');
$canDeleteLoan = can('loans.delete');
$canViewCustomer = can('customers.view');
$canViewCollectionHistory = can('collections.history');

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
$tomorrowDate = (new DateTimeImmutable(today()))->add(new DateInterval('P1D'))->format('Y-m-d');

$installmentsStmt = $pdo->prepare(
    "SELECT id, loan_id, installment_no, due_date, due_amount, paid_amount, paid_on, status
     FROM loan_installments
     WHERE loan_id = :loan_id
     ORDER BY installment_no ASC"
);
$installmentsStmt->execute(['loan_id' => $loanId]);
$installments = $installmentsStmt->fetchAll();

$installmentMetaMap = [];
$installmentDisplayPaidAmountMap = [];
$installmentHasCollectionRowsMap = [];
$paymentRefSummaryMap = [];
$latestUndoInstallmentId = 0;
$firstPendingInstallmentId = 0;
$pendingForCollect = array_values(array_filter(
    $installments,
    static fn (array $row): bool => in_array((string) $row['status'], ['pending', 'partial', 'overdue'], true)
));
if ($pendingForCollect !== []) {
    usort(
        $pendingForCollect,
        static function (array $a, array $b): int {
            $dateCompare = strcmp((string) $a['due_date'], (string) $b['due_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((int) $a['installment_no']) <=> ((int) $b['installment_no']);
        }
    );
    $firstPendingInstallmentId = (int) ($pendingForCollect[0]['id'] ?? 0);
}

if ($installments !== []) {
    $metaStmt = $pdo->prepare(
        "SELECT c.installment_id, c.collected_on, c.created_at, c.amount, COALESCE(u.full_name, 'Unknown') AS collector_name
         FROM collections c
         LEFT JOIN users u ON u.id = c.collected_by_user_id
         INNER JOIN (
             SELECT installment_id, MAX(id) AS max_id
             FROM collections
             WHERE loan_id = :loan_id AND installment_id IS NOT NULL
             GROUP BY installment_id
         ) latest ON latest.max_id = c.id"
    );
    $metaStmt->execute(['loan_id' => $loanId]);
    foreach ($metaStmt->fetchAll() as $row) {
        $installmentId = (int) $row['installment_id'];
        $installmentMetaMap[$installmentId] = $row;
    }

    $groupSummaryStmt = $pdo->prepare(
        "SELECT
            c.payment_ref,
            COALESCE(SUM(c.amount), 0) AS group_amount,
            COUNT(DISTINCT c.installment_id) AS installment_count,
            COALESCE(MIN(li.installment_no), 0) AS anchor_installment_no
         FROM collections c
         LEFT JOIN loan_installments li ON li.id = c.installment_id
         WHERE c.loan_id = :loan_id
           AND c.payment_ref IS NOT NULL
           AND c.payment_ref <> ''
         GROUP BY c.payment_ref"
    );
    $groupSummaryStmt->execute(['loan_id' => $loanId]);
    foreach ($groupSummaryStmt->fetchAll() as $row) {
        $ref = trim((string) ($row['payment_ref'] ?? ''));
        if ($ref === '') {
            continue;
        }
        $paymentRefSummaryMap[$ref] = [
            'amount' => round((float) ($row['group_amount'] ?? 0), 2),
            'installment_count' => (int) ($row['installment_count'] ?? 0),
            'anchor_installment_no' => (int) ($row['anchor_installment_no'] ?? 0),
        ];
    }

    // Build display paid amounts without double-counting spillover:
    // - multi-installment payment groups are shown on anchor card only
    // - partial cards with only spillover still show their partial paid amount
    $displayAmountStmt = $pdo->prepare(
        "SELECT c.installment_id, c.amount, c.payment_ref, li.installment_no
         FROM collections c
         JOIN loan_installments li ON li.id = c.installment_id
         WHERE c.loan_id = :loan_id
           AND c.installment_id IS NOT NULL
         ORDER BY c.id ASC"
    );
    $displayAmountStmt->execute(['loan_id' => $loanId]);
    $anchorCountedRefs = [];
    foreach ($displayAmountStmt->fetchAll() as $row) {
        $instId = (int) ($row['installment_id'] ?? 0);
        if ($instId <= 0) {
            continue;
        }
        $installmentHasCollectionRowsMap[$instId] = true;

        $contribution = round((float) ($row['amount'] ?? 0), 2);
        $ref = trim((string) ($row['payment_ref'] ?? ''));
        $installmentNo = (int) ($row['installment_no'] ?? 0);

        if ($ref !== '' && isset($paymentRefSummaryMap[$ref])) {
            $summary = $paymentRefSummaryMap[$ref];
            $groupInstallmentCount = (int) ($summary['installment_count'] ?? 0);
            $anchorNo = (int) ($summary['anchor_installment_no'] ?? 0);

            if ($groupInstallmentCount > 1) {
                if ($installmentNo === $anchorNo) {
                    if (!isset($anchorCountedRefs[$ref])) {
                        $contribution = round((float) ($summary['amount'] ?? 0), 2);
                        $anchorCountedRefs[$ref] = true;
                    } else {
                        $contribution = 0.0;
                    }
                } else {
                    $contribution = 0.0;
                }
            }
        }

        $installmentDisplayPaidAmountMap[$instId] = round(
            (float) ($installmentDisplayPaidAmountMap[$instId] ?? 0) + $contribution,
            2
        );
    }
}

$canInlineCollect = can('loans.collect_inline') && $firstPendingInstallmentId > 0 && (string) $loan['status'] !== 'closed';
$latestUndoAvailable = false;

if ($hasCollections) {
    $latestCollectionStmt = $pdo->prepare(
        'SELECT id, installment_id, payment_ref, created_at
         FROM collections
         WHERE loan_id = :loan_id
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $latestCollectionStmt->execute(['loan_id' => $loanId]);
    $latestCollection = $latestCollectionStmt->fetch();

    if ($latestCollection) {
        $latestUndoAvailable = true;
        $latestUndoInstallmentId = (int) ($latestCollection['installment_id'] ?? 0);
    }
}

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
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
            <?php if ($canViewCollectionHistory): ?>
                <a class="btn" href="<?= e(url('pages/collections.php?customer_id=' . (int) $loan['customer_id'])) ?>">Collection History</a>
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

    <?php if ($repaymentLocked): ?>
        <div class="flash flash-warning">
            <?php if ($hasCollections): ?>
                This loan already has collections. Repayment structure fields are locked. You can still update assignment, notes and status.
            <?php else: ?>
                This old loan has pre-collected value. Repayment structure fields are locked to protect collected totals. You can still update assignment, notes and status.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form id="loan-form" class="form-grid" method="post" action="<?= e(url('actions/loan_update.php')) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="loan_id" value="<?= e((string) $loan['id']) ?>">

        <div class="field">
            <label>Customer</label>
            <select name="customer_id" required <?= $repaymentLocked ? 'disabled' : '' ?>>
                <option value="">Select customer</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= e((string) $customer['id']) ?>" <?= (int) $loan['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>>
                        <?= e($customer['customer_code'] . ' - ' . $customer['full_name']) ?>
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

        <div class="field full">
            <label>Notes</label>
            <textarea name="notes" placeholder="Optional"><?= e((string) ($loan['notes'] ?? '')) ?></textarea>
        </div>

        <div class="field full loan-preview-field">
            <label>Repayment Preview</label>
            <div class="calc-preview-grid calc-preview-grid-three">
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
            </div>
        </div>

        <div class="field full loan-submit-field">
            <button type="submit" class="btn btn-primary">Update Loan</button>
        </div>
    </form>
</section>

<section class="panel loan-installments-panel" id="installment-cards">
    <div class="panel-head">
        <h2 class="panel-title">Repayment Timeline</h2>
    </div>

    <?php if (!$installments): ?>
        <p class="muted-block">No installments generated for this loan.</p>
    <?php else: ?>
        <div class="loan-installments-grid">
            <?php foreach ($installments as $inst): ?>
                <?php
                $instId = (int) $inst['id'];
                $dueAmount = (float) $inst['due_amount'];
                $paidAmount = (float) $inst['paid_amount'];
                $balance = max(0.0, round($dueAmount - $paidAmount, 2));
                $displayStatus = (string) $inst['status'];
                if ($displayStatus !== 'paid' && (string) $inst['due_date'] < today() && $balance > 0) {
                    $displayStatus = 'overdue';
                }
                $isCurrentCollectCard = $canInlineCollect && $instId === $firstPendingInstallmentId;
                $latestMeta = $installmentMetaMap[$instId] ?? null;
                $collectorNameShort = $latestMeta ? short_name_words((string) ($latestMeta['collector_name'] ?? ''), 2) : '---';
                $collectedAtText = $latestMeta ? display_datetime((string) $latestMeta['created_at']) : '---';
                $displayPaidAmount = round((float) ($installmentDisplayPaidAmountMap[$instId] ?? 0), 2);
                $hasCollectionRowsForInstallment = isset($installmentHasCollectionRowsMap[$instId]);
                if (
                    (string) $inst['status'] === 'partial'
                    && $displayPaidAmount <= 0.0009
                    && $paidAmount > 0.0009
                    && !$hasCollectionRowsForInstallment
                ) {
                    $displayPaidAmount = round($paidAmount, 2);
                }
                $showPaidAmount = $displayPaidAmount > 0.0009 || in_array((string) $inst['status'], ['partial', 'paid'], true);
                $amountLabel = $showPaidAmount ? 'Paid Amount' : 'Installment Amount';
                $amountValue = $showPaidAmount ? money_label($pdo, $displayPaidAmount) : money_label($pdo, $dueAmount);
                $amountValueIsBlank = false;
                $showUndoOnCard = $latestUndoAvailable && $latestUndoInstallmentId > 0 && $instId === $latestUndoInstallmentId;

                if ($showPaidAmount && $displayPaidAmount <= 0.0009) {
                    $amountValue = '';
                    $amountLabel = '';
                    $amountValueIsBlank = true;
                }
                ?>
                <article class="loan-inst-card loan-inst-status-<?= e(status_badge_class($displayStatus)) ?> <?= $isCurrentCollectCard ? 'loan-inst-current' : '' ?>">
                    <div class="loan-inst-top">
                        <div class="loan-inst-no">#<?= e(str_pad((string) $inst['installment_no'], 2, '0', STR_PAD_LEFT)) ?></div>
                        <?php if ($displayStatus !== 'paid' || $showUndoOnCard): ?>
                            <div class="loan-inst-badges">
                                <?php if ($showUndoOnCard): ?>
                                    <form method="post" action="<?= e(url('actions/loan_collect_inline_undo.php')) ?>" class="loan-inst-undo-form" data-confirm="Undo latest collection for this loan?">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="loan_id" value="<?= e((string) $loanId) ?>">
                                        <button type="submit" class="loan-inst-undo-btn" title="Undo Latest" aria-label="Undo Latest">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-undo2-icon lucide-undo-2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($displayStatus !== 'paid'): ?>
                                <span class="badge badge-<?= e(status_badge_class($displayStatus)) ?>"><?= e(ucfirst($displayStatus)) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="loan-inst-money-row single">
                        <div>
                            <?php if (!$amountValueIsBlank && $amountLabel !== ''): ?>
                                <p><?= e($amountLabel) ?></p>
                            <?php endif; ?>
                            <strong class="<?= $amountValueIsBlank ? 'loan-inst-amount-empty' : '' ?>"><?= e($amountValue) ?></strong>
                        </div>
                    </div>

                    <?php if ($isCurrentCollectCard): ?>
                        <form method="post" action="<?= e(url('actions/loan_collect_inline.php')) ?>" class="loan-inst-collect-form" data-confirm="Record this collection?">
                            <?= csrf_input() ?>
                            <input type="hidden" name="loan_id" value="<?= e((string) $loanId) ?>">
                            <input type="hidden" name="installment_id" value="<?= e((string) $instId) ?>">
                            <input type="hidden" name="method" value="cash">
                            <input type="number" name="amount" step="0.01" min="0.01" max="<?= e((string) $currentOutstanding) ?>" value="<?= e((string) $balance) ?>" required>
                            <button type="submit" class="btn btn-primary">Collect</button>
                        </form>
                    <?php endif; ?>

                    <div class="loan-inst-meta">
                        <span><?= e($collectedAtText) ?></span>
                        <span><?= e($collectorNameShort) ?></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
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
