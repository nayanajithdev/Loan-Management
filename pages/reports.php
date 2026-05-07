<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

$pageTitle = 'Reports';
$activePage = 'reports';

refresh_overdue_installments($pdo);

$defaultFrom = (new DateTimeImmutable(today()))->modify('first day of this month')->format('Y-m-d');
$defaultTo = today();

$fromDate = trim((string) ($_GET['from'] ?? $defaultFrom));
$toDate = trim((string) ($_GET['to'] ?? $defaultTo));
$search = trim((string) ($_GET['q'] ?? ''));

$fromObj = DateTimeImmutable::createFromFormat('Y-m-d', $fromDate) ?: new DateTimeImmutable($defaultFrom);
$toObj = DateTimeImmutable::createFromFormat('Y-m-d', $toDate) ?: new DateTimeImmutable($defaultTo);
if ($fromObj > $toObj) {
    [$fromObj, $toObj] = [$toObj, $fromObj];
}

$fromDate = $fromObj->format('Y-m-d');
$toDate = $toObj->format('Y-m-d');

$collectionTotalsStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(c.amount), 0) AS collected_total,
        COUNT(*) AS collection_count,
        COUNT(DISTINCT c.loan_id) AS active_loans_touched
     FROM collections c
     WHERE c.collected_on BETWEEN :from_date AND :to_date"
);
$collectionTotalsStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
$collectionTotals = $collectionTotalsStmt->fetch() ?: [];

$loanTotalsStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS loans_created,
        COALESCE(SUM(l.principal_amount), 0) AS disbursed_total,
        COALESCE(SUM(l.total_amount - l.principal_amount), 0) AS projected_profit
     FROM loans l
     WHERE DATE(l.created_at) BETWEEN :from_date AND :to_date"
);
$loanTotalsStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
$loanTotals = $loanTotalsStmt->fetch() ?: [];

$dueTotalsStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS due_installments,
        COALESCE(SUM(li.due_amount), 0) AS due_total,
        COALESCE(SUM(li.paid_amount), 0) AS paid_total
     FROM loan_installments li
     WHERE li.due_date BETWEEN :from_date AND :to_date"
);
$dueTotalsStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
$dueTotals = $dueTotalsStmt->fetch() ?: [];

$overdueSnapshotStmt = $pdo->query(
    "SELECT
        COUNT(*) AS overdue_count,
        COALESCE(SUM(li.due_amount - li.paid_amount), 0) AS overdue_amount
     FROM loan_installments li
     WHERE li.status = 'overdue'"
);
$overdueSnapshot = $overdueSnapshotStmt->fetch() ?: [];

$dailyCollectionsStmt = $pdo->prepare(
    "SELECT
        c.collected_on,
        COUNT(*) AS entries,
        COUNT(DISTINCT c.loan_id) AS loans,
        COALESCE(SUM(c.amount), 0) AS total_amount
     FROM collections c
     WHERE c.collected_on BETWEEN :from_date AND :to_date
     GROUP BY c.collected_on
     ORDER BY c.collected_on DESC"
);
$dailyCollectionsStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
$dailyCollections = $dailyCollectionsStmt->fetchAll();

$topCollectorsStmt = $pdo->prepare(
    "SELECT
        COALESCE(u.full_name, 'Unknown') AS collector_name,
        COALESCE(u.role, '-') AS role_name,
        COUNT(*) AS entries,
        COALESCE(SUM(c.amount), 0) AS total_amount
     FROM collections c
     LEFT JOIN users u ON u.id = c.collected_by_user_id
     WHERE c.collected_on BETWEEN :from_date AND :to_date
     GROUP BY c.collected_by_user_id, u.full_name, u.role
     ORDER BY total_amount DESC"
);
$topCollectorsStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
$topCollectors = $topCollectorsStmt->fetchAll();

$topCustomersStmt = $pdo->prepare(
    "SELECT
        cu.customer_code,
        cu.full_name,
        COUNT(*) AS entries,
        COALESCE(SUM(c.amount), 0) AS total_amount
     FROM collections c
     JOIN loans l ON l.id = c.loan_id
     JOIN customers cu ON cu.id = l.customer_id
     WHERE c.collected_on BETWEEN :from_date AND :to_date
     GROUP BY cu.id, cu.customer_code, cu.full_name
     ORDER BY total_amount DESC"
);
$topCustomersStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
$topCustomers = $topCustomersStmt->fetchAll();

$overdueLoansStmt = $pdo->query(
    "SELECT
        l.loan_number,
        cu.full_name AS customer_name,
        COALESCE(u.full_name, 'Unassigned') AS assigned_to,
        COUNT(li.id) AS overdue_installments,
        MIN(li.due_date) AS oldest_due_date,
        COALESCE(SUM(li.due_amount - li.paid_amount), 0) AS overdue_balance
     FROM loan_installments li
     JOIN loans l ON l.id = li.loan_id
     JOIN customers cu ON cu.id = l.customer_id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     WHERE li.status = 'overdue'
     GROUP BY l.id, l.loan_number, cu.full_name, u.full_name
     ORDER BY overdue_balance DESC, overdue_installments DESC"
);
$overdueLoans = $overdueLoansStmt->fetchAll();

$recentSql = "SELECT
        c.collected_on,
        l.loan_number,
        cu.full_name AS customer_name,
        cu.phone,
        c.method,
        c.amount,
        COALESCE(u.full_name, '-') AS collected_by
     FROM collections c
     JOIN loans l ON l.id = c.loan_id
     JOIN customers cu ON cu.id = l.customer_id
     LEFT JOIN users u ON u.id = c.collected_by_user_id
     WHERE c.collected_on BETWEEN :from_date AND :to_date";

$recentParams = ['from_date' => $fromDate, 'to_date' => $toDate];
if ($search !== '') {
    $recentSql .= " AND (l.loan_number LIKE :q_loan OR cu.full_name LIKE :q_name OR cu.phone LIKE :q_phone)";
    $searchLike = '%' . $search . '%';
    $recentParams['q_loan'] = $searchLike;
    $recentParams['q_name'] = $searchLike;
    $recentParams['q_phone'] = $searchLike;
}

$recentSql .= " ORDER BY c.id DESC LIMIT 120";
$recentCollectionsStmt = $pdo->prepare($recentSql);
$recentCollectionsStmt->execute($recentParams);
$recentCollections = $recentCollectionsStmt->fetchAll();

$collectedTotal = (float) ($collectionTotals['collected_total'] ?? 0.0);
$collectionCount = (int) ($collectionTotals['collection_count'] ?? 0);
$avgCollection = $collectionCount > 0 ? $collectedTotal / $collectionCount : 0.0;
$dueTotal = (float) ($dueTotals['due_total'] ?? 0.0);
$paidTotal = (float) ($dueTotals['paid_total'] ?? 0.0);
$dueRecoveryPct = $dueTotal > 0 ? min(100, ($paidTotal / $dueTotal) * 100) : 0.0;

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Reports</h2>
    </div>
    <form method="get" class="form-grid">
        <div class="field">
            <label>From Date</label>
            <input type="date" name="from" value="<?= e($fromDate) ?>" required>
        </div>
        <div class="field">
            <label>To Date</label>
            <input type="date" name="to" value="<?= e($toDate) ?>" required>
        </div>
        <div class="field">
            <label>Search (Loan / Customer / Phone)</label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Type and search">
        </div>
        <div class="field full reports-filter-actions">
            <a class="btn" href="<?= e(url('pages/reports.php')) ?>">Reset</a>
            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </div>
    </form>
</section>

<section class="card-grid dashboard-stat-grid">
    <article class="stat-card">
        <p class="stat-label">Collected (Range)</p>
        <p class="stat-value"><?= e(money_label($pdo, $collectedTotal)) ?></p>
        <p class="trend-meta"><?= e((string) $collectionCount) ?> transactions</p>
    </article>
    <article class="stat-card">
        <p class="stat-label">Average Collection</p>
        <p class="stat-value"><?= e(money_label($pdo, $avgCollection)) ?></p>
        <p class="trend-meta"><?= e((string) ($collectionTotals['active_loans_touched'] ?? 0)) ?> loans touched</p>
    </article>
    <article class="stat-card">
        <p class="stat-label">Disbursed (New Loans)</p>
        <p class="stat-value"><?= e(money_label($pdo, (float) ($loanTotals['disbursed_total'] ?? 0))) ?></p>
        <p class="trend-meta"><?= e((string) ($loanTotals['loans_created'] ?? 0)) ?> new loans</p>
    </article>
    <article class="stat-card">
        <p class="stat-label">Due Recovery (Range)</p>
        <p class="stat-value"><?= e(number_format($dueRecoveryPct, 1)) ?>%</p>
        <p class="trend-meta"><?= e(money_label($pdo, $paidTotal)) ?> / <?= e(money_label($pdo, $dueTotal)) ?></p>
    </article>
</section>

<section class="card-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
    <article class="stat-card">
        <p class="stat-label">Projected Profit (New Loans)</p>
        <p class="stat-value"><?= e(money_label($pdo, (float) ($loanTotals['projected_profit'] ?? 0))) ?></p>
    </article>
    <article class="stat-card">
        <p class="stat-label">Overdue Installments (Current)</p>
        <p class="stat-value"><?= e((string) ($overdueSnapshot['overdue_count'] ?? 0)) ?></p>
        <p class="trend-meta"><?= e(money_label($pdo, (float) ($overdueSnapshot['overdue_amount'] ?? 0))) ?> overdue balance</p>
    </article>
    <article class="stat-card">
        <p class="stat-label">Installments Due (Range)</p>
        <p class="stat-value"><?= e((string) ($dueTotals['due_installments'] ?? 0)) ?></p>
    </article>
</section>

<section class="split-layout reports-split-layout">
    <article class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Daily Collection Summary</h2>
        </div>
        <div class="table-wrap">
            <table class="reports-table-compact">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Entries</th>
                    <th>Loans</th>
                    <th class="text-right">Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($dailyCollections)): ?>
                    <tr><td colspan="4">No daily collection data for selected range.</td></tr>
                <?php else: ?>
                    <?php foreach ($dailyCollections as $row): ?>
                        <tr>
                            <td><?= e(display_date((string) $row['collected_on'])) ?></td>
                            <td><?= e((string) $row['entries']) ?></td>
                            <td><?= e((string) $row['loans']) ?></td>
                            <td class="text-right"><?= e(money_label($pdo, (float) $row['total_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Top Collectors</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Collector</th>
                    <th>Role</th>
                    <th>Entries</th>
                    <th class="text-right">Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($topCollectors)): ?>
                    <tr><td colspan="4">No collector activity for selected range.</td></tr>
                <?php else: ?>
                    <?php foreach ($topCollectors as $row): ?>
                        <tr>
                            <td><?= e((string) $row['collector_name']) ?></td>
                            <td><?= e(role_display_name((string) $row['role_name'])) ?></td>
                            <td><?= e((string) $row['entries']) ?></td>
                            <td class="text-right"><?= e(money_label($pdo, (float) $row['total_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="split-layout reports-split-layout">
    <article class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Top Customers by Collection</h2>
        </div>
        <div class="table-wrap">
            <table class="reports-table-compact">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Customer</th>
                    <th>Entries</th>
                    <th class="text-right">Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($topCustomers)): ?>
                    <tr><td colspan="4">No customer collections for selected range.</td></tr>
                <?php else: ?>
                    <?php foreach ($topCustomers as $row): ?>
                        <tr>
                            <td><?= e((string) $row['customer_code']) ?></td>
                            <td><?= e((string) $row['full_name']) ?></td>
                            <td><?= e((string) $row['entries']) ?></td>
                            <td class="text-right"><?= e(money_label($pdo, (float) $row['total_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Overdue Loans (Current)</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Loan</th>
                    <th>Customer</th>
                    <th>Assigned</th>
                    <th>Overdue Inst.</th>
                    <th>Oldest Due</th>
                    <th class="text-right">Overdue Balance</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($overdueLoans)): ?>
                    <tr><td colspan="6">No overdue loans right now.</td></tr>
                <?php else: ?>
                    <?php foreach ($overdueLoans as $row): ?>
                        <tr>
                            <td><?= e((string) $row['loan_number']) ?></td>
                            <td><?= e((string) $row['customer_name']) ?></td>
                            <td><?= e((string) $row['assigned_to']) ?></td>
                            <td><?= e((string) $row['overdue_installments']) ?></td>
                            <td><?= e(display_date((string) $row['oldest_due_date'])) ?></td>
                            <td class="text-right"><?= e(money_label($pdo, (float) $row['overdue_balance'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Collections (Detailed)</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Loan</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Collector</th>
                <th>Method</th>
                <th class="text-right">Amount</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($recentCollections)): ?>
                <tr><td colspan="7">No collections found for selected filter.</td></tr>
            <?php else: ?>
                <?php foreach ($recentCollections as $row): ?>
                    <tr>
                        <td><?= e(display_date((string) $row['collected_on'])) ?></td>
                        <td><?= e((string) $row['loan_number']) ?></td>
                        <td><?= e((string) $row['customer_name']) ?></td>
                        <td><?= e((string) $row['phone']) ?></td>
                        <td><?= e((string) $row['collected_by']) ?></td>
                        <td><?= e((string) $row['method']) ?></td>
                        <td class="text-right"><?= e(money_label($pdo, (float) $row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
