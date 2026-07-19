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
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$perPage = 50;
$queryLimit = $perPage + 1;
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
     LIMIT {$queryLimit} OFFSET {$offset}"
);
$collectionsStmt->execute($params);
$collectionsRaw = $collectionsStmt->fetchAll();
$hasMore = count($collectionsRaw) > $perPage;
$collections = $hasMore ? array_slice($collectionsRaw, 0, $perPage) : $collectionsRaw;

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
    <td><?= e(display_datetime((string) ($item['collected_at'] ?? ''), display_date((string) $item['collected_on']))) ?></td>
    <td><?= e($item['loan_number']) ?></td>
    <td><?= e($item['full_name']) ?></td>
    <td><?= e(money_label($pdo, (float) $item['amount'])) ?></td>
    <td><?= e((string) ($item['collected_by_name'] ?? '-')) ?></td>
    <?php if ($paymentMethodSelectionEnabled): ?>
        <td><?= e($item['method']) ?></td>
    <?php endif; ?>
    <td class="collection-history-note"><?= e($note) ?></td>
</tr>
<?php
    endforeach;
endif;
$historyHtml = ob_get_clean();

$queryForMore = $_GET;
$queryForMore['offset'] = $offset + $perPage;
$loadMoreUrl = url('pages/collections.php') . '?' . http_build_query($queryForMore);

ob_start();
if ($hasMore):
?>
<div class="reports-filter-actions" style="justify-content: flex-end; margin-top: 12px;">
    <a class="btn btn-primary" href="<?= e($loadMoreUrl) ?>">Load More</a>
</div>
<?php
endif;
$loadMoreHtml = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'updated_at' => date('H:i:s'),
    'targets' => [
        '#collection-history-table-body' => $historyHtml,
        '#collection-history-load-more-wrap' => $loadMoreHtml,
    ],
], JSON_UNESCAPED_UNICODE);
