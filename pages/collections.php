<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('collections.history');

$pageTitle = 'Collection History';
$activePage = 'collections';

refresh_overdue_installments($pdo);
$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);
$selectedCustomerId = (int) ($_GET['customer_id'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 120);
$perPage = 50;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$paymentMethodSelectionEnabled = payment_method_selection_enabled($pdo);

$scopeSql = '';
$params = [];
if (is_collector_role($currentRole)) {
    $scopeSql = ' WHERE ' . collector_assignment_scope_sql('l', 'assigned_user_id');
    $params['assigned_user_id'] = $currentUserId;
}

$legacyCustomerFilterSql = '';
if ($selectedCustomerId > 0) {
    $legacyCustomerFilterSql = $scopeSql === '' ? ' WHERE c.id = :customer_id' : ' AND c.id = :customer_id';
    $params['customer_id'] = $selectedCustomerId;
}

$searchFilterSql = '';
if ($search !== '') {
    $searchFilterSql = ($scopeSql === '' && $legacyCustomerFilterSql === '')
        ? ' WHERE '
        : ' AND ';
    $searchFilterSql .= '(l.loan_number LIKE :search_loan ESCAPE \'\\\\\' OR c.full_name LIKE :search_name ESCAPE \'\\\\\' OR c.phone LIKE :search_phone ESCAPE \'\\\\\')';
    $searchTerm = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    $params['search_loan'] = $searchTerm;
    $params['search_name'] = $searchTerm;
    $params['search_phone'] = $searchTerm;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM (
        SELECT 1
        FROM collections col
        JOIN loans l ON l.id = col.loan_id
        JOIN customers c ON c.id = l.customer_id
        LEFT JOIN users u ON u.id = col.collected_by_user_id
        {$scopeSql}{$legacyCustomerFilterSql}{$searchFilterSql}
        GROUP BY COALESCE(col.payment_ref, CONCAT('legacy-', col.id)), l.loan_number, c.full_name
     ) grouped_collections"
);
$countStmt->execute($params);
$totalCollections = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCollections / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;
$showingFrom = $totalCollections > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalCollections);
$pageStart = max(1, $currentPage - 2);
$pageEnd = min($totalPages, $currentPage + 2);

$baseQueryParams = [];
if ($selectedCustomerId > 0) {
    $baseQueryParams['customer_id'] = $selectedCustomerId;
}
if ($search !== '') {
    $baseQueryParams['q'] = $search;
}
$paginationUrl = static function (int $page) use ($baseQueryParams): string {
    $params = $baseQueryParams;
    if ($page > 1) {
        $params['page'] = $page;
    }

    $query = http_build_query($params);
    return url('pages/collections.php' . ($query !== '' ? '?' . $query : ''));
};

$collectionsStmt = $pdo->prepare(
    "SELECT
        MAX(col.id) AS latest_id,
        MAX(col.collected_on) AS collected_on,
        MAX(col.created_at) AS collected_at,
        l.loan_number,
        c.full_name,
        MAX(u.full_name) AS collected_by_name,
        MAX(col.method) AS method,
        MAX(col.note) AS note,
        SUM(col.amount) AS amount,
        MAX(CASE WHEN col.installment_id IS NULL THEN 1 ELSE 0 END) AS has_advance
     FROM collections col
     JOIN loans l ON l.id = col.loan_id
     JOIN customers c ON c.id = l.customer_id
     LEFT JOIN users u ON u.id = col.collected_by_user_id
     {$scopeSql}{$legacyCustomerFilterSql}{$searchFilterSql}
     GROUP BY COALESCE(col.payment_ref, CONCAT('legacy-', col.id)), l.loan_number, c.full_name
     ORDER BY latest_id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$collectionsStmt->execute($params);
$collections = $collectionsStmt->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<form method="get" class="collection-history-filter">
    <div class="collection-history-search">
        <label class="sr-only">Search collection history</label>
        <div class="search-control">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search..." aria-label="Search by loan number, customer name, or phone">
            <button type="submit" class="btn search-submit" aria-label="Search collection history">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
            </button>
        </div>
        <?php if ($selectedCustomerId > 0): ?>
            <input type="hidden" name="customer_id" value="<?= e((string) $selectedCustomerId) ?>">
        <?php endif; ?>
    </div>
    <div class="collection-history-filter-actions">
        <a class="btn" href="<?= e(url('pages/collections.php')) ?>">Reset</a>
    </div>
</form>

<section class="panel collections-history-panel">
    <div class="table-wrap">
        <table class="collection-history-table collections-history-table <?= $paymentMethodSelectionEnabled ? '' : 'is-method-hidden' ?>">
            <thead>
            <tr>
                <th>Date &amp; Time</th>
                <th>Loan</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Collected By</th>
                <?php if ($paymentMethodSelectionEnabled): ?>
                    <th>Method</th>
                <?php endif; ?>
                <th>Note</th>
            </tr>
            </thead>
            <tbody id="collection-history-table-body">
            <?php if (!$collections): ?>
                <tr><td colspan="<?= $paymentMethodSelectionEnabled ? '7' : '6' ?>">No collections yet.</td></tr>
            <?php else: ?>
                <?php foreach ($collections as $item): ?>
                    <?php
                    $noteParts = collection_note_split((string) ($item['note'] ?? ''));
                    $note = (string) ($noteParts['public'] ?? '');
                    if ((int) $item['has_advance'] === 1 && stripos($note, 'advance') === false) {
                        $note = trim($note === '' ? 'Advance payment' : $note . ' | Advance payment');
                    }
                    ?>
                    <tr>
                        <td data-label="Date"><?= e(display_datetime((string) ($item['collected_at'] ?? ''), display_date((string) $item['collected_on']))) ?></td>
                        <td data-label="Loan no"><?= e($item['loan_number']) ?></td>
                        <td data-label="Customer"><?= e($item['full_name']) ?></td>
                        <td data-label="Amount"><?= e(money_label($pdo, (float) $item['amount'])) ?></td>
                        <td data-label="By"><?= e((string) ($item['collected_by_name'] ?? '-')) ?></td>
                        <?php if ($paymentMethodSelectionEnabled): ?>
                            <td data-label="Method"><?= e($item['method']) ?></td>
                        <?php endif; ?>
                        <td data-label="Note" class="collection-history-note <?= $note === '' ? 'is-empty-note' : '' ?>"><?= e($note) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="collection-history-pagination-wrap">
        <?php if ($totalCollections > 0): ?>
            <nav class="pagination-bar" aria-label="Collection history pagination">
                <p class="pagination-info">
                    Showing <?= e((string) $showingFrom) ?>-<?= e((string) $showingTo) ?> of <?= e((string) $totalCollections) ?>
                </p>
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-links">
                        <a class="btn pagination-link <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= e($paginationUrl(max(1, $currentPage - 1))) ?>">Previous</a>
                        <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                            <?php if ($pageNumber === $currentPage): ?>
                                <span class="btn pagination-link is-current"><?= e((string) $pageNumber) ?></span>
                            <?php else: ?>
                                <a class="btn pagination-link" href="<?= e($paginationUrl($pageNumber)) ?>"><?= e((string) $pageNumber) ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <a class="btn pagination-link <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e($paginationUrl(min($totalPages, $currentPage + 1))) ?>">Next</a>
                    </div>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</section>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/collection_history_poll.php')) ?>"
     data-poll-include-query="1"
     data-poll-interval="<?= e((string) poll_interval_ms($pdo)) ?>"></div>

<?php require __DIR__ . '/../includes/layout_end.php';
