<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('collections.history');

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

ob_start();
if (!$collections):
?>
<tr><td colspan="<?= $paymentMethodSelectionEnabled ? '7' : '6' ?>">No collections yet.</td></tr>
<?php
else:
    foreach ($collections as $item):
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
<?php
    endforeach;
endif;
$historyHtml = ob_get_clean();

ob_start();
if ($totalCollections > 0):
?>
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
<?php
endif;
$paginationHtml = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'updated_at' => date('H:i:s'),
    'targets' => [
        '#collection-history-table-body' => $historyHtml,
        '#collection-history-pagination-wrap' => $paginationHtml,
    ],
], JSON_UNESCAPED_UNICODE);
