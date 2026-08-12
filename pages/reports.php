<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pageTitle = 'Reports';
$activePage = 'reports';

refresh_overdue_installments($pdo);

$activeReportTab = (string) ($_GET['report_tab'] ?? 'collections');
if (!in_array($activeReportTab, ['collections', 'profit'], true)) {
    $activeReportTab = 'collections';
}

$selectedDate = trim((string) ($_GET['date'] ?? today()));
$dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate) ?: new DateTimeImmutable(today());
$selectedDate = $dateObj->format('Y-m-d');

$sort = (string) ($_GET['sort'] ?? 'loan_asc');
$allowedSorts = ['loan_asc', 'loan_desc', 'latest', 'oldest'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'loan_asc';
}

$orderBy = match ($sort) {
    'loan_desc' => 'loan_sort DESC, loan_number DESC, latest_id DESC',
    'latest' => 'latest_id DESC',
    'oldest' => 'latest_id ASC',
    default => 'loan_sort ASC, loan_number ASC, latest_id ASC',
};

$collectionTotalsStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(amount), 0) AS collected_total,
        COUNT(DISTINCT COALESCE(payment_ref, CONCAT('legacy-', id))) AS payment_count
     FROM collections
     WHERE collected_on = :selected_date"
);
$collectionTotalsStmt->execute(['selected_date' => $selectedDate]);
$collectionTotals = $collectionTotalsStmt->fetch() ?: [];

$collectionsSql = "SELECT
        COALESCE(c.payment_ref, CONCAT('legacy-', c.id)) AS payment_ref,
        MAX(c.id) AS latest_id,
        MAX(c.created_at) AS collected_at,
        MAX(c.collected_on) AS collected_on,
        MAX(l.id) AS loan_id,
        MAX(l.loan_number) AS loan_number,
        MAX(cu.full_name) AS customer_name,
        MAX(cu.phone) AS phone,
        COALESCE(MAX(u.full_name), 'Unknown') AS collected_by,
        MAX(c.method) AS method,
        MAX(c.note) AS note,
        SUM(c.amount) AS amount,
        MAX(CASE WHEN l.status = 'closed'
                  AND lc.latest_collection_date = :closed_report_date
                  AND lc.latest_collection_id = c.id
                 THEN 1 ELSE 0 END) AS closed_this_payment,
        GROUP_CONCAT(DISTINCT CONCAT('#', li.installment_no) ORDER BY li.installment_no SEPARATOR ', ') AS installments,
        MIN(CASE WHEN l.loan_number REGEXP '^[0-9]+$' THEN CAST(l.loan_number AS UNSIGNED) ELSE l.id END) AS loan_sort
     FROM collections c
     JOIN loans l ON l.id = c.loan_id
     JOIN customers cu ON cu.id = l.customer_id
     LEFT JOIN loan_installments li ON li.id = c.installment_id
     LEFT JOIN users u ON u.id = c.collected_by_user_id
     LEFT JOIN (
        SELECT loan_id, MAX(id) AS latest_collection_id, MAX(collected_on) AS latest_collection_date
        FROM collections
        GROUP BY loan_id
     ) lc ON lc.loan_id = l.id
     WHERE c.collected_on = :selected_date
     GROUP BY COALESCE(c.payment_ref, CONCAT('legacy-', c.id))
     ORDER BY {$orderBy}";
$collectionsStmt = $pdo->prepare($collectionsSql);
$collectionsStmt->execute([
    'selected_date' => $selectedDate,
    'closed_report_date' => $selectedDate,
]);
$collections = $collectionsStmt->fetchAll();
$allCollections = $collections;
$collectionsPerPage = 50;
$collectionTotalRows = count($allCollections);
$collectionTotalPages = max(1, (int) ceil($collectionTotalRows / $collectionsPerPage));
$collectionPage = max(1, (int) ($_GET['collection_page'] ?? 1));
if ($collectionPage > $collectionTotalPages) {
    $collectionPage = $collectionTotalPages;
}
$collectionOffset = ($collectionPage - 1) * $collectionsPerPage;
$collections = array_slice($allCollections, $collectionOffset, $collectionsPerPage);
$collectionShowingFrom = $collectionTotalRows > 0 ? $collectionOffset + 1 : 0;
$collectionShowingTo = $collectionTotalRows > 0 ? min($collectionOffset + count($collections), $collectionTotalRows) : 0;
$collectionPaginationUrl = static function (int $page) use ($selectedDate, $sort): string {
    return url('pages/reports.php?' . http_build_query([
        'report_tab' => 'collections',
        'date' => $selectedDate,
        'sort' => $sort,
        'collection_page' => max(1, $page),
    ]));
};

$collectedTotal = (float) ($collectionTotals['collected_total'] ?? 0);
$paymentCount = (int) ($collectionTotals['payment_count'] ?? 0);
$paymentMethodSelectionEnabled = payment_method_selection_enabled($pdo);

$profitMode = (string) ($_GET['profit_mode'] ?? 'daily');
if (!in_array($profitMode, ['daily', 'monthly'], true)) {
    $profitMode = 'daily';
}

$profitDate = trim((string) ($_GET['profit_date'] ?? today()));
$profitDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $profitDate) ?: new DateTimeImmutable(today());
$profitDate = $profitDateObj->format('Y-m-d');

$defaultMonthStart = (new DateTimeImmutable(today()))->modify('first day of this month')->format('Y-m-d');
$profitFrom = trim((string) ($_GET['profit_from'] ?? $defaultMonthStart));
$profitTo = trim((string) ($_GET['profit_to'] ?? today()));
$profitFromObj = DateTimeImmutable::createFromFormat('Y-m-d', $profitFrom) ?: new DateTimeImmutable($defaultMonthStart);
$profitToObj = DateTimeImmutable::createFromFormat('Y-m-d', $profitTo) ?: new DateTimeImmutable(today());
if ($profitFromObj > $profitToObj) {
    [$profitFromObj, $profitToObj] = [$profitToObj, $profitFromObj];
}
$profitFrom = $profitFromObj->format('Y-m-d');
$profitTo = $profitToObj->format('Y-m-d');

$profitRows = [];
$profitCollectedTotal = 0.0;
$profitTotal = 0.0;

if ($profitMode === 'monthly') {
    $profitStmt = $pdo->prepare(
        "SELECT
            c.collected_on AS report_date,
            SUM(c.amount) AS collected_amount,
            SUM(
                CASE
                    WHEN l.total_amount > 0
                    THEN c.amount * ((l.total_amount - l.principal_amount) / l.total_amount)
                    ELSE 0
                END
            ) AS profit_amount
         FROM collections c
         JOIN loans l ON l.id = c.loan_id
         WHERE c.collected_on BETWEEN :profit_from AND :profit_to
         GROUP BY c.collected_on
         ORDER BY c.collected_on ASC"
    );
    $profitStmt->execute([
        'profit_from' => $profitFrom,
        'profit_to' => $profitTo,
    ]);
    $profitRows = $profitStmt->fetchAll();
} else {
    $profitStmt = $pdo->prepare(
        "SELECT
            l.loan_number,
            MIN(CASE WHEN l.loan_number REGEXP '^[0-9]+$' THEN CAST(l.loan_number AS UNSIGNED) ELSE l.id END) AS loan_sort,
            SUM(c.amount) AS collected_amount,
            SUM(
                CASE
                    WHEN l.total_amount > 0
                    THEN c.amount * ((l.total_amount - l.principal_amount) / l.total_amount)
                    ELSE 0
                END
            ) AS profit_amount
         FROM collections c
         JOIN loans l ON l.id = c.loan_id
         WHERE c.collected_on = :profit_date
         GROUP BY l.id, l.loan_number
         ORDER BY loan_sort ASC, l.loan_number ASC"
    );
    $profitStmt->execute(['profit_date' => $profitDate]);
    $profitRows = $profitStmt->fetchAll();
}

foreach ($profitRows as $profitRow) {
    $profitCollectedTotal += (float) ($profitRow['collected_amount'] ?? 0);
    $profitTotal += (float) ($profitRow['profit_amount'] ?? 0);
}
$allProfitRows = $profitRows;
$profitRowsPerPage = 50;
$profitTotalRows = count($allProfitRows);
$profitTotalPages = max(1, (int) ceil($profitTotalRows / $profitRowsPerPage));
$profitPage = max(1, (int) ($_GET['profit_page'] ?? 1));
if ($profitPage > $profitTotalPages) {
    $profitPage = $profitTotalPages;
}
$profitOffset = ($profitPage - 1) * $profitRowsPerPage;
$profitRows = array_slice($allProfitRows, $profitOffset, $profitRowsPerPage);
$profitShowingFrom = $profitTotalRows > 0 ? $profitOffset + 1 : 0;
$profitShowingTo = $profitTotalRows > 0 ? min($profitOffset + count($profitRows), $profitTotalRows) : 0;
$profitPaginationUrl = static function (int $page) use ($profitMode, $profitDate, $profitFrom, $profitTo): string {
    $params = [
        'report_tab' => 'profit',
        'profit_mode' => $profitMode,
        'profit_page' => max(1, $page),
    ];

    if ($profitMode === 'monthly') {
        $params['profit_from'] = $profitFrom;
        $params['profit_to'] = $profitTo;
    } else {
        $params['profit_date'] = $profitDate;
    }

    return url('pages/reports.php?' . http_build_query($params));
};

$businessSettings = system_settings_all($pdo);
$businessName = trim((string) ($businessSettings['business_name'] ?? 'Loan Manager'));
$businessAddress = trim((string) ($businessSettings['business_address'] ?? ''));
$businessPhone = trim((string) ($businessSettings['business_phone'] ?? ''));
$businessNote = trim((string) ($businessSettings['business_note'] ?? ''));
$businessIconPath = business_icon_path($pdo);
$dailyCollectionFileName = 'daily-collections-' . $selectedDate;
$profitPrintFileName = $profitMode === 'monthly'
    ? 'profit-' . $profitFrom . '-to-' . $profitTo
    : 'profit-' . $profitDate;

require __DIR__ . '/../includes/layout_start.php';
?>

<div class="loan-edit-tabs-shell reports-tabs-shell" data-reports-tabs>
    <div class="loan-tab-frame">
        <div class="loan-tab-nav" role="tablist" aria-label="Report sections">
            <button type="button" class="loan-tab-button <?= $activeReportTab === 'collections' ? 'is-active' : '' ?>" data-report-tab-open="collections" role="tab" aria-selected="<?= $activeReportTab === 'collections' ? 'true' : 'false' ?>">Collections</button>
            <button type="button" class="loan-tab-button <?= $activeReportTab === 'profit' ? 'is-active' : '' ?>" data-report-tab-open="profit" role="tab" aria-selected="<?= $activeReportTab === 'profit' ? 'true' : 'false' ?>">Profit</button>
        </div>

        <div class="loan-edit-tabs reports-tabs-content">
            <div class="loan-tab-panel <?= $activeReportTab === 'collections' ? 'is-active' : '' ?>" data-report-tab-panel="collections" role="tabpanel" <?= $activeReportTab === 'collections' ? '' : 'hidden' ?>>
                <form method="get" class="form-grid reports-collections-filter">
                    <input type="hidden" name="report_tab" value="collections">
                    <div class="field reports-date-field">
                        <label>Select Date</label>
                        <input type="date" name="date" value="<?= e($selectedDate) ?>" required>
                    </div>
                    <div class="field reports-sort-field">
                        <label>Sort</label>
                        <select name="sort">
                            <option value="loan_asc" <?= $sort === 'loan_asc' ? 'selected' : '' ?>>Loan No 1 - 10</option>
                            <option value="loan_desc" <?= $sort === 'loan_desc' ? 'selected' : '' ?>>Loan No 10 - 1</option>
                            <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>Latest First</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        </select>
                    </div>
                    <div class="field reports-filter-button-field">
                        <label class="sr-only">Apply</label>
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </div>
                    <div class="field reports-filter-button-field">
                        <label class="sr-only">Reset</label>
                        <a class="btn" href="<?= e(url('pages/reports.php')) ?>">Reset</a>
                    </div>
                </form>

                <section class="panel reports-tabs-panel">
                <section class="reports-table-section">
                    <div class="panel-head reports-table-head">
                        <h2 class="panel-title">Collections</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="collection-history-table <?= $paymentMethodSelectionEnabled ? '' : 'is-method-hidden' ?>">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Loan</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Inst.</th>
                                <th>Collected By</th>
                                <?php if ($paymentMethodSelectionEnabled): ?>
                                    <th>Method</th>
                                <?php endif; ?>
                                <th>Note</th>
                                <th class="text-right">Amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$collections): ?>
                                <tr><td colspan="<?= $paymentMethodSelectionEnabled ? '9' : '8' ?>">No collections found for selected date.</td></tr>
                            <?php else: ?>
                                <?php foreach ($collections as $row): ?>
                                    <?php
                                    $noteParts = collection_note_split((string) ($row['note'] ?? ''));
                                    $noteText = trim((string) ($noteParts['public'] ?? ''));
                                    $installments = trim((string) ($row['installments'] ?? ''));
                                    $isClosedPayment = (int) ($row['closed_this_payment'] ?? 0) === 1;
                                    ?>
                                    <tr class="<?= $isClosedPayment ? 'is-closed-loan-row' : '' ?>">
                                        <td><?= e(display_datetime((string) $row['collected_at'])) ?></td>
                                        <td><?= e((string) $row['loan_number']) ?></td>
                                        <td><?= e((string) $row['customer_name']) ?></td>
                                        <td><?= e((string) $row['phone']) ?></td>
                                        <td><?= e($installments === '' ? '-' : $installments) ?></td>
                                        <td><?= e((string) $row['collected_by']) ?></td>
                                        <?php if ($paymentMethodSelectionEnabled): ?>
                                            <td><?= e(ucfirst((string) $row['method'])) ?></td>
                                        <?php endif; ?>
                                        <td><?= e($noteText === '' ? '-' : $noteText) ?></td>
                                        <td class="text-right"><?= e(money_label($pdo, (float) $row['amount'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($collectionTotalRows > 0): ?>
                        <div class="pagination-bar">
                            <p class="pagination-info">Showing <?= e((string) $collectionShowingFrom) ?>-<?= e((string) $collectionShowingTo) ?> of <?= e((string) $collectionTotalRows) ?> collection(s)</p>
                            <?php if ($collectionTotalPages > 1): ?>
                                <div class="pagination-links">
                                    <a class="btn pagination-link <?= $collectionPage <= 1 ? 'is-disabled' : '' ?>" href="<?= e($collectionPaginationUrl(max(1, $collectionPage - 1))) ?>">Previous</a>
                                    <?php for ($page = 1; $page <= $collectionTotalPages; $page++): ?>
                                        <a class="btn pagination-link <?= $page === $collectionPage ? 'is-current' : '' ?>" href="<?= e($collectionPaginationUrl($page)) ?>"><?= e((string) $page) ?></a>
                                    <?php endfor; ?>
                                    <a class="btn pagination-link <?= $collectionPage >= $collectionTotalPages ? 'is-disabled' : '' ?>" href="<?= e($collectionPaginationUrl(min($collectionTotalPages, $collectionPage + 1))) ?>">Next</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="reports-panel-footer">
                        <button type="button" class="btn btn-primary" data-print-daily-collections-report data-print-filename="<?= e($dailyCollectionFileName) ?>">Print</button>
                        <p class="reports-footer-total reports-footer-total-collected">Collected Total: <?= e(money_label($pdo, $collectedTotal)) ?></p>
                    </div>
                </section>
                </section>
            </div>

            <div class="loan-tab-panel <?= $activeReportTab === 'profit' ? 'is-active' : '' ?>" data-report-tab-panel="profit" role="tabpanel" <?= $activeReportTab === 'profit' ? '' : 'hidden' ?>>
                <form method="get" class="form-grid reports-profit-filter">
                    <input type="hidden" name="report_tab" value="profit">
                    <div class="field reports-profit-mode-field">
                        <label>Report Type</label>
                        <select name="profit_mode" data-profit-mode>
                            <option value="daily" <?= $profitMode === 'daily' ? 'selected' : '' ?>>Daily</option>
                            <option value="monthly" <?= $profitMode === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        </select>
                    </div>
                    <div class="field reports-profit-date-field" data-profit-daily <?= $profitMode === 'monthly' ? 'hidden' : '' ?>>
                        <label>Select Date</label>
                        <input type="date" name="profit_date" value="<?= e($profitDate) ?>" <?= $profitMode === 'monthly' ? 'disabled' : '' ?>>
                    </div>
                    <div class="field reports-profit-date-field" data-profit-monthly <?= $profitMode === 'monthly' ? '' : 'hidden' ?>>
                        <label>From Date</label>
                        <input type="date" name="profit_from" value="<?= e($profitFrom) ?>" <?= $profitMode === 'monthly' ? '' : 'disabled' ?>>
                    </div>
                    <div class="field reports-profit-date-field" data-profit-monthly <?= $profitMode === 'monthly' ? '' : 'hidden' ?>>
                        <label>To Date</label>
                        <input type="date" name="profit_to" value="<?= e($profitTo) ?>" <?= $profitMode === 'monthly' ? '' : 'disabled' ?>>
                    </div>
                    <div class="field reports-filter-button-field reports-profit-action-field">
                        <label class="sr-only">Apply</label>
                        <button type="submit" class="btn btn-primary">Apply</button>
                    </div>
                    <div class="field reports-filter-button-field reports-profit-action-field">
                        <label class="sr-only">Reset</label>
                        <a class="btn" href="<?= e(url('pages/reports.php?report_tab=profit')) ?>">Reset</a>
                    </div>
                </form>

                <section class="panel reports-tabs-panel">
                <section class="reports-table-section">
                    <div class="panel-head reports-table-head">
                        <h2 class="panel-title"><?= $profitMode === 'monthly' ? 'Monthly Profit' : 'Daily Profit' ?></h2>
                    </div>
                    <div class="table-wrap">
                        <?php if ($profitMode === 'monthly'): ?>
                            <table class="collection-history-table">
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-right">Collected Amount</th>
                                    <th class="text-right">Profit</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!$profitRows): ?>
                                    <tr><td colspan="3">No profit records found for selected range.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($profitRows as $row): ?>
                                        <tr>
                                            <td><?= e(display_date((string) $row['report_date'])) ?></td>
                                            <td class="text-right"><?= e(money_label($pdo, (float) $row['collected_amount'])) ?></td>
                                            <td class="text-right"><?= e(money_label($pdo, (float) $row['profit_amount'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <table class="collection-history-table">
                                <thead>
                                <tr>
                                    <th>Loan No</th>
                                    <th class="text-right">Profit</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!$profitRows): ?>
                                    <tr><td colspan="2">No profit records found for selected date.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($profitRows as $row): ?>
                                        <tr>
                                            <td><?= e((string) $row['loan_number']) ?></td>
                                            <td class="text-right"><?= e(money_label($pdo, (float) $row['profit_amount'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <?php if ($profitTotalRows > 0): ?>
                        <div class="pagination-bar">
                            <p class="pagination-info">Showing <?= e((string) $profitShowingFrom) ?>-<?= e((string) $profitShowingTo) ?> of <?= e((string) $profitTotalRows) ?> profit row(s)</p>
                            <?php if ($profitTotalPages > 1): ?>
                                <div class="pagination-links">
                                    <a class="btn pagination-link <?= $profitPage <= 1 ? 'is-disabled' : '' ?>" href="<?= e($profitPaginationUrl(max(1, $profitPage - 1))) ?>">Previous</a>
                                    <?php for ($page = 1; $page <= $profitTotalPages; $page++): ?>
                                        <a class="btn pagination-link <?= $page === $profitPage ? 'is-current' : '' ?>" href="<?= e($profitPaginationUrl($page)) ?>"><?= e((string) $page) ?></a>
                                    <?php endfor; ?>
                                    <a class="btn pagination-link <?= $profitPage >= $profitTotalPages ? 'is-disabled' : '' ?>" href="<?= e($profitPaginationUrl(min($profitTotalPages, $profitPage + 1))) ?>">Next</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="reports-panel-footer">
                        <button type="button" class="btn btn-primary" data-print-profit-report data-print-filename="<?= e($profitPrintFileName) ?>">Print</button>
                        <p class="reports-footer-total">
                            <span class="reports-footer-total-collected">Collected: <?= e(money_label($pdo, $profitCollectedTotal)) ?></span>
                            <span class="reports-footer-total-profit">Profit: <?= e(money_label($pdo, $profitTotal)) ?></span>
                        </p>
                    </div>
                </section>
                </section>
            </div>
        </div>
    </div>
</div>

<?php $dailyPrintCollections = $allCollections; ?>
<?php require __DIR__ . '/../prints/daily_collections_report.php'; ?>
<?php $profitPrintRows = $allProfitRows; ?>
<?php if ($profitMode === 'monthly'): ?>
    <?php require __DIR__ . '/../prints/profit_monthly_report.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/../prints/profit_daily_report.php'; ?>
<?php endif; ?>

<script>
(() => {
    const tabRoot = document.querySelector('[data-reports-tabs]');
    if (!tabRoot) {
        return;
    }

    const tabButtons = Array.from(tabRoot.querySelectorAll('[data-report-tab-open]'));
    const panels = Array.from(tabRoot.querySelectorAll('[data-report-tab-panel]'));

    const openTab = (target) => {
        panels.forEach((panel) => {
            const isActive = panel.getAttribute('data-report-tab-panel') === target;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });

        tabButtons.forEach((button) => {
            const isActive = button.getAttribute('data-report-tab-open') === target;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => openTab(button.getAttribute('data-report-tab-open') || 'collections'));
    });

    const profitMode = tabRoot.querySelector('[data-profit-mode]');
    if (!profitMode) {
        return;
    }

    const dailyFields = Array.from(tabRoot.querySelectorAll('[data-profit-daily]'));
    const monthlyFields = Array.from(tabRoot.querySelectorAll('[data-profit-monthly]'));
    const setFieldState = (fields, enabled) => {
        fields.forEach((field) => {
            field.hidden = !enabled;
            field.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !enabled;
            });
        });
    };
    const syncProfitMode = () => {
        const isMonthly = profitMode.value === 'monthly';
        setFieldState(dailyFields, !isMonthly);
        setFieldState(monthlyFields, isMonthly);
    };

    profitMode.addEventListener('change', syncProfitMode);
    syncProfitMode();
})();
</script>

<?php require __DIR__ . '/../includes/layout_end.php';
