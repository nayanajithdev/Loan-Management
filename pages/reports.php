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
        GROUP_CONCAT(DISTINCT CONCAT('#', li.installment_no) ORDER BY li.installment_no SEPARATOR ', ') AS installments,
        MIN(CASE WHEN l.loan_number REGEXP '^[0-9]+$' THEN CAST(l.loan_number AS UNSIGNED) ELSE l.id END) AS loan_sort
     FROM collections c
     JOIN loans l ON l.id = c.loan_id
     JOIN customers cu ON cu.id = l.customer_id
     LEFT JOIN loan_installments li ON li.id = c.installment_id
     LEFT JOIN users u ON u.id = c.collected_by_user_id
     WHERE c.collected_on = :selected_date
     GROUP BY COALESCE(c.payment_ref, CONCAT('legacy-', c.id))
     ORDER BY {$orderBy}";
$collectionsStmt = $pdo->prepare($collectionsSql);
$collectionsStmt->execute(['selected_date' => $selectedDate]);
$collections = $collectionsStmt->fetchAll();

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

        <section class="panel loan-edit-tabs reports-tabs-panel">
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

                <section class="card-grid reports-summary-grid">
                    <article class="stat-card">
                        <p class="stat-label">Selected Date</p>
                        <p class="stat-value stat-value-small"><?= e(display_date($selectedDate)) ?></p>
                    </article>
                    <article class="stat-card">
                        <p class="stat-label">Collected Total</p>
                        <p class="stat-value"><?= e(money_label($pdo, $collectedTotal)) ?></p>
                    </article>
                    <article class="stat-card">
                        <p class="stat-label">Collection Entries</p>
                        <p class="stat-value"><?= e((string) $paymentCount) ?></p>
                    </article>
                </section>

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
                                    ?>
                                    <tr>
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
                    <div class="reports-print-actions">
                        <button type="button" class="btn btn-primary" data-print-daily-collections-report data-print-filename="<?= e($dailyCollectionFileName) ?>">Print</button>
                    </div>
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

                <section class="card-grid reports-summary-grid reports-profit-summary">
                    <article class="stat-card">
                        <p class="stat-label"><?= $profitMode === 'monthly' ? 'Selected Range' : 'Selected Date' ?></p>
                        <p class="stat-value stat-value-small">
                            <?= $profitMode === 'monthly' ? e(display_date($profitFrom) . ' - ' . display_date($profitTo)) : e(display_date($profitDate)) ?>
                        </p>
                    </article>
                    <article class="stat-card">
                        <p class="stat-label">Collected Amount</p>
                        <p class="stat-value"><?= e(money_label($pdo, $profitCollectedTotal)) ?></p>
                    </article>
                    <article class="stat-card">
                        <p class="stat-label">Profit</p>
                        <p class="stat-value"><?= e(money_label($pdo, $profitTotal)) ?></p>
                    </article>
                </section>

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
                                    <tr class="reports-profit-total-row">
                                        <td>Total</td>
                                        <td class="text-right"><?= e(money_label($pdo, $profitCollectedTotal)) ?></td>
                                        <td class="text-right"><?= e(money_label($pdo, $profitTotal)) ?></td>
                                    </tr>
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
                                    <tr class="reports-profit-total-row">
                                        <td>
                                            <span class="muted-text">Collected:</span>
                                            <?= e(money_label($pdo, $profitCollectedTotal)) ?>
                                        </td>
                                        <td class="text-right">
                                            <span class="muted-text">Profit:</span>
                                            <?= e(money_label($pdo, $profitTotal)) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="reports-print-actions">
                        <button type="button" class="btn btn-primary" data-print-profit-report data-print-filename="<?= e($profitPrintFileName) ?>">Print</button>
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>

<section class="daily-collections-print-report" id="daily-collections-print-report" aria-hidden="true">
    <header class="print-report-header">
        <div class="print-report-logo-slot">
            <?php if ($businessIconPath !== ''): ?>
                <img src="<?= e(url($businessIconPath)) ?>" alt="">
            <?php endif; ?>
        </div>
        <div class="print-report-title-block">
            <h1><?= e($businessName) ?></h1>
            <?php if ($businessAddress !== ''): ?>
                <p><?= e($businessAddress) ?></p>
            <?php endif; ?>
            <?php if ($businessPhone !== ''): ?>
                <p class="print-report-contact">
                    <em>Tel:</em> <?= e($businessPhone) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="print-report-note"><?= $businessNote !== '' ? nl2br(e($businessNote)) : '' ?></div>
    </header>

    <div class="print-report-rule"></div>

    <div class="daily-print-meta">
        <span>Collection Date</span>
        <strong><?= e(display_date($selectedDate)) ?></strong>
    </div>

    <table class="daily-collections-print-table">
        <thead>
            <tr>
                <th>Collector</th>
                <th>Time</th>
                <th>Loan no</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$collections): ?>
                <tr>
                    <td colspan="4">No collections found for selected date.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($collections as $row): ?>
                    <tr>
                        <td><?= e((string) $row['collected_by']) ?></td>
                        <td><?= e(date('H:i:s', strtotime((string) $row['collected_at']))) ?></td>
                        <td><?= e((string) $row['loan_number']) ?></td>
                        <td><?= e(money_label($pdo, (float) $row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="daily-print-total">
        <span>Collected total</span>
        <strong><?= e(money_label($pdo, $collectedTotal)) ?></strong>
    </div>
</section>

<section class="profit-print-report" id="profit-print-report" aria-hidden="true">
    <header class="print-report-header">
        <div class="print-report-logo-slot">
            <?php if ($businessIconPath !== ''): ?>
                <img src="<?= e(url($businessIconPath)) ?>" alt="">
            <?php endif; ?>
        </div>
        <div class="print-report-title-block">
            <h1><?= e($businessName) ?></h1>
            <?php if ($businessAddress !== ''): ?>
                <p><?= e($businessAddress) ?></p>
            <?php endif; ?>
            <?php if ($businessPhone !== ''): ?>
                <p class="print-report-contact">
                    <em>Tel:</em> <?= e($businessPhone) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="print-report-note"><?= $businessNote !== '' ? nl2br(e($businessNote)) : '' ?></div>
    </header>

    <div class="print-report-rule"></div>

    <div class="daily-print-meta">
        <span><?= $profitMode === 'monthly' ? 'Monthly Profit' : 'Daily Profit' ?></span>
        <strong>
            <?= $profitMode === 'monthly'
                ? e(display_date($profitFrom) . ' - ' . display_date($profitTo))
                : e(display_date($profitDate)) ?>
        </strong>
    </div>

    <table class="daily-collections-print-table profit-print-table">
        <thead>
            <tr>
                <?php if ($profitMode === 'monthly'): ?>
                    <th>Date</th>
                    <th>Collected Amount</th>
                    <th>Profit</th>
                <?php else: ?>
                    <th>Loan no</th>
                    <th>Collected Amount</th>
                    <th>Profit</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$profitRows): ?>
                <tr>
                    <td colspan="3"><?= $profitMode === 'monthly' ? 'No profit records found for selected range.' : 'No profit records found for selected date.' ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($profitRows as $row): ?>
                    <tr>
                        <?php if ($profitMode === 'monthly'): ?>
                            <td><?= e(display_date((string) $row['report_date'])) ?></td>
                        <?php else: ?>
                            <td><?= e((string) $row['loan_number']) ?></td>
                        <?php endif; ?>
                        <td><?= e(money_label($pdo, (float) $row['collected_amount'])) ?></td>
                        <td><?= e(money_label($pdo, (float) $row['profit_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="daily-print-total">
        <span>Collected amount</span>
        <strong><?= e(money_label($pdo, $profitCollectedTotal)) ?></strong>
        <span>Profit</span>
        <strong><?= e(money_label($pdo, $profitTotal)) ?></strong>
    </div>
</section>

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
