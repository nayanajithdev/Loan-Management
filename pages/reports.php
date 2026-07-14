<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pageTitle = 'Reports';
$activePage = 'reports';

refresh_overdue_installments($pdo);

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

require __DIR__ . '/../includes/layout_start.php';
?>

<div class="loan-edit-tabs-shell reports-tabs-shell" data-reports-tabs>
    <div class="loan-tab-frame">
        <div class="loan-tab-nav" role="tablist" aria-label="Report sections">
            <button type="button" class="loan-tab-button is-active" data-report-tab-open="collections" role="tab" aria-selected="true">Collections</button>
            <button type="button" class="loan-tab-button" data-report-tab-open="more" role="tab" aria-selected="false">More Reports</button>
        </div>

        <section class="panel loan-edit-tabs reports-tabs-panel">
            <div class="loan-tab-panel is-active" data-report-tab-panel="collections" role="tabpanel">
                <form method="get" class="form-grid reports-collections-filter">
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

                <section class="panel reports-inner-panel">
                    <div class="panel-head">
                        <h2 class="panel-title">Collections</h2>
                    </div>
                    <div class="table-wrap">
                        <table class="collection-history-table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Loan</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Inst.</th>
                                <th>Collected By</th>
                                <th>Method</th>
                                <th>Note</th>
                                <th class="text-right">Amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$collections): ?>
                                <tr><td colspan="9">No collections found for selected date.</td></tr>
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
                                        <td><?= e(ucfirst((string) $row['method'])) ?></td>
                                        <td><?= e($noteText === '' ? '-' : $noteText) ?></td>
                                        <td class="text-right"><?= e(money_label($pdo, (float) $row['amount'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="loan-tab-panel" data-report-tab-panel="more" role="tabpanel" hidden>
                <section class="panel reports-inner-panel reports-placeholder-panel">
                    <h2 class="panel-title">More Reports</h2>
                    <p class="muted-text">This tab is ready. We can build the next report here later.</p>
                </section>
            </div>
        </section>
    </div>
</div>

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
})();
</script>

<?php require __DIR__ . '/../includes/layout_end.php';
