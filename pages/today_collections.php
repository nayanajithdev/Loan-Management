<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

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
$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);

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
        WHERE li.due_date <= :selected_date
          AND li.status IN ('pending', 'partial', 'overdue')";

$params = ['selected_date' => $selectedDate];
if ($currentRole === 'collector') {
    $sql .= ' AND (l.assigned_user_id = :assigned_user_id OR l.assigned_user_id IS NULL)';
    $params['assigned_user_id'] = $currentUserId;
}
if ($search !== '') {
    $sql .= " AND (l.loan_number LIKE :q OR c.full_name LIKE :q OR c.phone LIKE :q)";
    $params['q'] = '%' . $search . '%';
}

$sql .= ' ORDER BY li.due_date ASC, c.full_name ASC, li.installment_no ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dueInstallments = $stmt->fetchAll();

$selectedInstallment = null;
foreach ($dueInstallments as $item) {
    if ((int) $item['id'] === $selectedInstallmentId) {
        $selectedInstallment = $item;
        break;
    }
}

$selectedCollectionTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE collected_on = :selected_date');
$selectedCollectionTotalStmt->execute(['selected_date' => $selectedDate]);
$selectedCollectionTotal = (float) $selectedCollectionTotalStmt->fetchColumn();

$returnParams = [
    'date_mode' => $selectedDateMode,
    'date' => $selectedDate,
];
if ($search !== '') {
    $returnParams['q'] = $search;
}
$returnTo = 'pages/today_collections.php?' . http_build_query($returnParams);

require __DIR__ . '/../includes/layout_start.php';
?>

<p class="live-indicator" id="js-last-updated">Last update: waiting...</p>

<div class="split-layout">
    <section class="panel">
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
                <h3>LKR <?= e(money($selectedCollectionTotal)) ?></h3>
            </div>
            <div class="metric-box">
                <p>Pending Count (Selected Date)</p>
                <h3><?= e((string) count($dueInstallments)) ?></h3>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Loan</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Inst.</th>
                    <th>Due Date</th>
                    <th>Due</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody id="collection-due-table-body">
                <?php if (!$dueInstallments): ?>
                    <tr><td colspan="9">No due installments for selected date.</td></tr>
                <?php else: ?>
                    <?php foreach ($dueInstallments as $item): ?>
                        <?php $balance = round((float) $item['due_amount'] - (float) $item['paid_amount'], 2); ?>
                        <?php
                        $displayStatus = $item['status'];
                        if ($item['status'] !== 'paid' && $item['due_date'] < $todayDate) {
                            $displayStatus = 'overdue';
                        }
                        ?>
                        <?php $selectUrl = url('pages/today_collections.php?' . http_build_query(['date_mode' => $selectedDateMode, 'date' => $selectedDate, 'q' => $search, 'selected_installment' => (int) $item['id']])); ?>
                        <tr class="table-row-clickable <?= (int) $item['id'] === $selectedInstallmentId ? 'row-selected' : '' ?>" data-select-url="<?= e($selectUrl) ?>">
                            <td><?= e($item['loan_number']) ?></td>
                            <td><?= e($item['full_name']) ?></td>
                            <td><?= e($item['phone']) ?></td>
                            <td>#<?= e((string) $item['installment_no']) ?></td>
                            <td><?= e($item['due_date']) ?></td>
                            <td>LKR <?= e(money((float) $item['due_amount'])) ?></td>
                            <td>LKR <?= e(money((float) $item['paid_amount'])) ?></td>
                            <td>LKR <?= e(money($balance)) ?></td>
                            <td><span class="badge badge-<?= e(status_badge_class($displayStatus)) ?>"><?= e($displayStatus) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Record Collection</h2>
        </div>

        <?php
        $hasSelectedInstallment = $selectedInstallment !== null;
        $selectedBalance = $hasSelectedInstallment
            ? round((float) $selectedInstallment['due_amount'] - (float) $selectedInstallment['paid_amount'], 2)
            : 0.0;
        ?>

        <p>
            <?php
            if ($isFutureDate) {
                echo 'Future date view only. Saving collection is disabled.';
            } else {
                echo $hasSelectedInstallment ? 'Selected installment is ready for collection.' : 'Select an installment from the left table.';
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
            <input type="hidden" name="loan_id" value="<?= e($hasSelectedInstallment ? (string) $selectedInstallment['loan_id'] : '') ?>">
            <input type="hidden" name="installment_id" value="<?= e($hasSelectedInstallment ? (string) $selectedInstallment['id'] : '') ?>">
            <input type="hidden" name="collected_on" value="<?= e($selectedDate) ?>">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

            <div class="field full">
                <label>Installment</label>
                <input type="text" value="<?= e($hasSelectedInstallment ? ('#' . (string) $selectedInstallment['installment_no'] . ' | ' . $selectedInstallment['due_date']) : 'Not selected') ?>" readonly>
            </div>
            <div class="field">
                <label>Due Amount</label>
                <input type="text" value="<?= e($hasSelectedInstallment ? ('LKR ' . money((float) $selectedInstallment['due_amount'])) : 'LKR 0.00') ?>" readonly>
            </div>
            <div class="field">
                <label>Already Paid</label>
                <input type="text" value="<?= e($hasSelectedInstallment ? ('LKR ' . money((float) $selectedInstallment['paid_amount'])) : 'LKR 0.00') ?>" readonly>
            </div>
            <div class="field">
                <label>Balance</label>
                <input type="text" value="LKR <?= e(money($selectedBalance)) ?>" readonly>
            </div>
            <div class="field">
                <label>Amount Received</label>
                <input type="number" name="amount" step="0.01" min="0.01" value="<?= e($hasSelectedInstallment ? (string) $selectedBalance : '') ?>" <?= $hasSelectedInstallment && !$isFutureDate ? 'required' : 'disabled' ?>>
            </div>
            <div class="field">
                <label>Method</label>
                <select name="method" <?= $hasSelectedInstallment && !$isFutureDate ? '' : 'disabled' ?>>
                    <option value="cash">Cash</option>
                    <option value="bank">Bank Transfer</option>
                    <option value="online">Online</option>
                </select>
            </div>
            <div class="field full">
                <label>Note</label>
                <textarea name="note" placeholder="Optional" <?= $hasSelectedInstallment && !$isFutureDate ? '' : 'disabled' ?>></textarea>
            </div>
            <div class="field" style="align-self:end;">
                <button type="submit" class="btn btn-primary" <?= $hasSelectedInstallment && !$isFutureDate ? '' : 'disabled' ?>>Save Collection</button>
            </div>
        </form>
    </section>
</div>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/collection_poll.php')) ?>"
     data-poll-interval="10000"
     data-poll-include-query="1"></div>

<?php require __DIR__ . '/../includes/layout_end.php';
