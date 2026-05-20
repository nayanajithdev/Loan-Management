<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector']);

$pageTitle = 'Collection';
$activePage = 'today_collections';

refresh_overdue_installments($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$selectedDateMode = trim((string) ($_GET['date_mode'] ?? ''));
$customDateInput = trim((string) ($_GET['date'] ?? today()));
$customDateObj = DateTime::createFromFormat('Y-m-d', $customDateInput);
$customDate = ($customDateObj && $customDateObj->format('Y-m-d') === $customDateInput) ? $customDateInput : today();

$todayDate = today();
$tomorrowDate = (new DateTimeImmutable($todayDate))->add(new DateInterval('P1D'))->format('Y-m-d');
$dayAfterTomorrowDate = (new DateTimeImmutable($todayDate))->add(new DateInterval('P2D'))->format('Y-m-d');

if (!in_array($selectedDateMode, ['today', 'tomorrow', 'day_after_tomorrow', 'custom'], true)) {
    if ($customDate === $todayDate) {
        $selectedDateMode = 'today';
    } elseif ($customDate === $tomorrowDate) {
        $selectedDateMode = 'tomorrow';
    } elseif ($customDate === $dayAfterTomorrowDate) {
        $selectedDateMode = 'day_after_tomorrow';
    } else {
        $selectedDateMode = 'custom';
    }
}

$selectedDate = match ($selectedDateMode) {
    'today' => $todayDate,
    'tomorrow' => $tomorrowDate,
    'day_after_tomorrow' => $dayAfterTomorrowDate,
    default => $customDate,
};
$isFutureDate = $selectedDate > $todayDate;
$selectedInstallmentId = (int) ($_GET['selected_installment'] ?? 0);
$mobileRecordMode = (int) ($_GET['mobile_record'] ?? 0) === 1;
$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);
$canBackdatePaid = has_role(['superadmin', 'admin']);
$dueDateOperator = $selectedDate > $todayDate ? '=' : '<=';

$sql = "SELECT
            li.id,
            li.loan_id,
            li.installment_no,
            li.due_date,
            li.due_amount,
            li.paid_amount,
            li.status,
            l.loan_number,
            l.installment_frequency,
            c.full_name,
            c.phone
        FROM loan_installments li
        JOIN loans l ON l.id = li.loan_id
        JOIN customers c ON c.id = l.customer_id
        WHERE li.due_date {$dueDateOperator} :selected_date
          AND li.status IN ('pending', 'partial', 'overdue')";

$params = ['selected_date' => $selectedDate];
if (is_collector_role($currentRole)) {
    $sql .= ' AND (l.assigned_user_id = :assigned_user_id OR l.assigned_user_id IS NULL)';
    $params['assigned_user_id'] = $currentUserId;
}
if ($search !== '') {
    $sql .= " AND (l.loan_number LIKE :q_loan OR c.full_name LIKE :q_name OR c.phone LIKE :q_phone)";
    $searchLike = '%' . $search . '%';
    $params['q_loan'] = $searchLike;
    $params['q_name'] = $searchLike;
    $params['q_phone'] = $searchLike;
}

$sql .= ' ORDER BY li.due_date ASC, c.full_name ASC, li.installment_no ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dueInstallments = $stmt->fetchAll();

$oldestCollectibleByLoan = [];
if ($selectedDate <= $todayDate) {
    foreach ($dueInstallments as $item) {
        $loanKey = (int) $item['loan_id'];
        if (!isset($oldestCollectibleByLoan[$loanKey])) {
            $oldestCollectibleByLoan[$loanKey] = (int) $item['id'];
        }
    }
}

$selectedInstallment = null;
foreach ($dueInstallments as $item) {
    $itemId = (int) $item['id'];
    $loanKey = (int) $item['loan_id'];
    $isOldestCollectible = !isset($oldestCollectibleByLoan[$loanKey]) || $oldestCollectibleByLoan[$loanKey] === $itemId;
    if ($itemId === $selectedInstallmentId && $isOldestCollectible) {
        $selectedInstallment = $item;
        break;
    }
}

$hasSelectedInstallment = $selectedInstallment !== null;
$isFutureInstallmentSelected = $hasSelectedInstallment && (string) $selectedInstallment['due_date'] > $todayDate;
$isTodayInstallmentSelected = $hasSelectedInstallment && (string) $selectedInstallment['due_date'] === $todayDate;
$canCollectSelectedInstallment = $hasSelectedInstallment && !$isFutureInstallmentSelected;
$canUseBackdatedEntryForSelection = $hasSelectedInstallment && !$isFutureInstallmentSelected && !$isTodayInstallmentSelected;
$effectiveCollectedOn = $isFutureDate ? $todayDate : $selectedDate;

if (is_collector_role($currentRole)) {
    $selectedCollectionTotalStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(col.amount), 0)
         FROM collections col
         JOIN loans l ON l.id = col.loan_id
         WHERE col.collected_on = :selected_date
           AND (l.assigned_user_id = :assigned_user_id OR l.assigned_user_id IS NULL)"
    );
    $selectedCollectionTotalStmt->execute([
        'selected_date' => $selectedDate,
        'assigned_user_id' => $currentUserId,
    ]);
} else {
    $selectedCollectionTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE collected_on = :selected_date');
    $selectedCollectionTotalStmt->execute(['selected_date' => $selectedDate]);
}
$selectedCollectionTotal = (float) $selectedCollectionTotalStmt->fetchColumn();
$nextPaymentDefault = $tomorrowDate;
if ($hasSelectedInstallment) {
    try {
        $candidateDate = (new DateTimeImmutable((string) $selectedInstallment['due_date']))->add(new DateInterval('P1D'))->format('Y-m-d');
        if ($candidateDate > $nextPaymentDefault) {
            $nextPaymentDefault = $candidateDate;
        }
    } catch (Throwable) {
        // keep tomorrow default
    }
}

$baseQueryParams = [
    'date_mode' => $selectedDateMode,
    'date' => $selectedDate,
];
if ($search !== '') {
    $baseQueryParams['q'] = $search;
}
$listViewUrl = url('pages/today_collections.php?' . http_build_query($baseQueryParams));
$returnTo = 'pages/today_collections.php?' . http_build_query($baseQueryParams);

require __DIR__ . '/../includes/layout_start.php';
?>

<p class="live-indicator" id="js-last-updated">Last update: waiting...</p>

<div class="split-layout today-collections-layout <?= $mobileRecordMode ? 'mobile-record-mode' : '' ?>">
    <section class="panel today-collections-list-panel">
        <div class="panel-head">
            <h2 class="panel-title">Due Installments</h2>
        </div>

        <form method="get" action="<?= e(url('pages/today_collections.php')) ?>" class="form-grid">
            <div class="field">
                <label>Select Date</label>
                <select name="date_mode" id="date-mode-select">
                    <option value="today" <?= $selectedDateMode === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="tomorrow" <?= $selectedDateMode === 'tomorrow' ? 'selected' : '' ?>>Tomorrow</option>
                    <option value="day_after_tomorrow" <?= $selectedDateMode === 'day_after_tomorrow' ? 'selected' : '' ?>>Day After Tomorrow</option>
                    <option value="custom" <?= $selectedDateMode === 'custom' ? 'selected' : '' ?>>Custom Date</option>
                </select>
            </div>
            <div class="field" id="custom-date-field">
                <label>Calendar</label>
                <input type="date" name="date" id="custom-date-input" value="<?= e($selectedDateMode === 'custom' ? $selectedDate : $customDate) ?>">
            </div>
            <div class="field" style="grid-column: span 6;">
                <label>Search (Loan No / Customer Name / Phone)</label>
                <div class="combo-field combo-field-search">
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Type and search">
                    <button type="submit" class="btn">Search</button>
                </div>
            </div>
        </form>

        <div class="metric-row" style="margin: 10px 0 14px;" id="collection-summary-metrics">
            <div class="metric-box">
                <p>Collected Total (Selected Date)</p>
                <h3><?= e(money_label($pdo, $selectedCollectionTotal)) ?></h3>
            </div>
            <div class="metric-box">
                <p>Pending Count (Selected Date)</p>
                <h3><?= e((string) count($dueInstallments)) ?></h3>
            </div>
        </div>

        <div class="table-wrap">
            <table class="due-installments-table">
                <thead>
                <tr>
                    <th>Loan</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Inst.</th>
                    <th>Due Date</th>
                    <th>Due</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody id="collection-due-table-body">
                <?php if (!$dueInstallments): ?>
                    <tr><td colspan="7">No due installments for selected date.</td></tr>
                <?php else: ?>
                    <?php foreach ($dueInstallments as $item): ?>
                        <?php $balance = round((float) $item['due_amount'] - (float) $item['paid_amount'], 2); ?>
                        <?php
                        $displayStatus = $item['status'];
                        if ($item['status'] !== 'paid' && $item['due_date'] < $todayDate) {
                            $displayStatus = 'overdue';
                        }
                        ?>
                        <?php
                        $loanKey = (int) $item['loan_id'];
                        $itemId = (int) $item['id'];
                        $isOldestCollectible = !isset($oldestCollectibleByLoan[$loanKey]) || $oldestCollectibleByLoan[$loanKey] === $itemId;
                        $canSelectThisRow = $isOldestCollectible;
                        $rowSelectUrl = $canSelectThisRow
                            ? url('pages/today_collections.php?' . http_build_query(['date_mode' => $selectedDateMode, 'date' => $selectedDate, 'q' => $search, 'selected_installment' => $itemId]))
                            : '';
                        ?>
                        <tr class="<?= $canSelectThisRow ? 'table-row-clickable' : 'row-disabled' ?> <?= $itemId === $selectedInstallmentId ? 'row-selected' : '' ?>" <?= $canSelectThisRow ? ('data-select-url="' . e($rowSelectUrl) . '"') : '' ?>>
                            <td><?= e($item['loan_number']) ?></td>
                            <td><?= e($item['full_name']) ?></td>
                            <td><?= e($item['phone']) ?></td>
                            <td>#<?= e((string) $item['installment_no']) ?></td>
                            <td><?= e(display_date((string) $item['due_date'])) ?></td>
                            <td><?= e(money_label($pdo, $balance)) ?></td>
                            <td><span class="badge badge-<?= e(status_badge_class($displayStatus)) ?>"><?= e($displayStatus) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel today-collections-record-panel">
        <div class="panel-head">
            <h2 class="panel-title">Record Collection</h2>
            <?php if ($mobileRecordMode): ?>
                <div class="panel-head-actions">
                    <a class="btn" href="<?= e($listViewUrl) ?>">
                        <span class="btn-icon-inline" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                        </span>
                        Back to List
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php
        $selectedBalance = $hasSelectedInstallment
            ? round((float) $selectedInstallment['due_amount'] - (float) $selectedInstallment['paid_amount'], 2)
            : 0.0;
        ?>

        <p>
            <?php
            if ($hasSelectedInstallment && $isFutureInstallmentSelected) {
                echo 'Future installment selected. Collection is disabled for future dues.';
            } else {
                echo $hasSelectedInstallment ? 'Selected installment is ready for collection.' : 'Select an installment to continue.';
            }
            ?>
        </p>

        <div class="metric-row" style="margin-bottom: 12px;">
            <div class="metric-box">
                <p>Loan</p>
                <h3><?= e($hasSelectedInstallment ? (string) $selectedInstallment['loan_number'] : '-') ?></h3>
            </div>
            <div class="metric-box">
                <p>Customer</p>
                <h3><?= e($hasSelectedInstallment ? (string) $selectedInstallment['full_name'] : '-') ?></h3>
            </div>
        </div>

        <form method="post" action="<?= e(url('actions/collection_save.php')) ?>" class="form-grid" data-confirm="Confirm this collection payment?">
            <?= csrf_input() ?>
            <input type="hidden" name="loan_id" value="<?= e($hasSelectedInstallment ? (string) $selectedInstallment['loan_id'] : '') ?>">
            <input type="hidden" name="installment_id" value="<?= e($hasSelectedInstallment ? (string) $selectedInstallment['id'] : '') ?>">
            <input type="hidden" name="collected_on" value="<?= e($effectiveCollectedOn) ?>">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

            <div class="field full">
                <label>Installment</label>
                <div class="readonly-value"><?= e($hasSelectedInstallment ? ('#' . (string) $selectedInstallment['installment_no'] . ' | ' . display_date((string) $selectedInstallment['due_date'])) : 'Not selected') ?></div>
            </div>
            <div class="field">
                <label>Due Amount</label>
                <div class="readonly-value"><?= e($hasSelectedInstallment ? money_label($pdo, $selectedBalance) : money_label($pdo, 0.0)) ?></div>
            </div>
            <div class="field">
                <label>Amount Received</label>
                <input type="number" name="amount" step="0.01" min="0.01" value="<?= e($hasSelectedInstallment ? (string) $selectedBalance : '') ?>" <?= $canCollectSelectedInstallment ? 'required' : 'disabled' ?>>
            </div>
            <div class="field">
                <label>Method</label>
                <select name="method" <?= $canCollectSelectedInstallment ? '' : 'disabled' ?>>
                    <option value="cash">Cash</option>
                    <option value="bank">Bank Transfer</option>
                    <option value="online">Online</option>
                </select>
            </div>
            <?php if ($canBackdatePaid): ?>
                <div class="field full">
                    <label class="choice-check">
                        <input type="checkbox" name="backdated_entry" id="backdated-entry-toggle" value="1" <?= ($canCollectSelectedInstallment && $canUseBackdatedEntryForSelection) ? '' : 'disabled' ?>>
                        <span class="choice-check-box" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check-icon lucide-check"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <span class="choice-check-label">Backdated Entry (paid on an earlier date)</span>
                    </label>
                </div>
                <div class="field" id="paid-date-field" style="display:none;">
                    <label>Paid Date (Actual)</label>
                    <input type="date" name="paid_on_date" id="paid-on-date-input" value="<?= e($hasSelectedInstallment ? (string) $selectedInstallment['due_date'] : $effectiveCollectedOn) ?>" max="<?= e($effectiveCollectedOn) ?>" <?= ($canCollectSelectedInstallment && $canUseBackdatedEntryForSelection) ? '' : 'disabled' ?>>
                </div>
            <?php endif; ?>
            <div class="field full">
                <label class="choice-check">
                    <input type="checkbox" name="schedule_next_payment" id="schedule-next-payment-toggle" value="1" <?= $canCollectSelectedInstallment ? '' : 'disabled' ?>>
                    <span class="choice-check-box" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check-icon lucide-check"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <span class="choice-check-label">Schedule Next Payment</span>
                </label>
            </div>
            <div class="field" id="next-payment-date-field" style="display:none;">
                <label>Next Payment Date</label>
                <input
                    type="date"
                    name="next_payment_date"
                    id="next-payment-date-input"
                    value="<?= e($nextPaymentDefault) ?>"
                    min="<?= e($tomorrowDate) ?>"
                    <?= $canCollectSelectedInstallment ? '' : 'disabled' ?>
                >
            </div>
            <div class="field full">
                <label>Note</label>
                <textarea name="note" placeholder="Optional" <?= $canCollectSelectedInstallment ? '' : 'disabled' ?>></textarea>
            </div>
            <div class="field" style="align-self:end;">
                <button type="submit" class="btn btn-primary" <?= $canCollectSelectedInstallment ? '' : 'disabled' ?>>Save Collection</button>
            </div>
        </form>
    </section>
</div>
<?php if ($canBackdatePaid): ?>
<script>
(() => {
    const toggle = document.getElementById('backdated-entry-toggle');
    const field = document.getElementById('paid-date-field');
    const input = document.getElementById('paid-on-date-input');
    if (!toggle || !field || !input) {
        return;
    }

    const sync = () => {
        const enabled = toggle.checked && !toggle.disabled;
        field.style.display = enabled ? '' : 'none';
        input.required = enabled;
    };

    toggle.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>
<script>
(() => {
    const scheduleToggle = document.getElementById('schedule-next-payment-toggle');
    const scheduleField = document.getElementById('next-payment-date-field');
    const scheduleInput = document.getElementById('next-payment-date-input');
    if (!scheduleToggle || !scheduleField || !scheduleInput) {
        return;
    }

    const syncSchedule = () => {
        const enabled = scheduleToggle.checked && !scheduleToggle.disabled;
        scheduleField.style.display = enabled ? '' : 'none';
        scheduleInput.required = enabled;
    };

    scheduleToggle.addEventListener('change', syncSchedule);
    syncSchedule();
})();
</script>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/collection_poll.php')) ?>"
     data-poll-interval="<?= e((string) poll_interval_ms($pdo)) ?>"
     data-poll-include-query="1"></div>

<?php require __DIR__ . '/../includes/layout_end.php';
