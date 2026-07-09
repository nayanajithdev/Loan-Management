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
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$perPage = 50;
$queryLimit = $perPage + 1;

$scopeSql = '';
$params = [];
if (is_collector_role($currentRole)) {
    $scopeSql = ' WHERE l.assigned_user_id = :assigned_user_id';
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

$queryForMore = $_GET;
$queryForMore['offset'] = $offset + $perPage;
$loadMoreUrl = url('pages/collections.php') . '?' . http_build_query($queryForMore);

require __DIR__ . '/../includes/layout_start.php';
?>

<p class="live-indicator" id="js-last-updated">Last update: waiting...</p>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Collection History</h2>
    </div>
    <form method="get" class="form-grid" style="margin-bottom: 12px;">
        <div class="field" style="grid-column: span 5;">
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
        <div class="field reports-filter-actions" style="grid-column: span 3; justify-content: flex-start; align-self: end;">
            <a class="btn" href="<?= e(url('pages/collections.php')) ?>">Reset</a>
        </div>
    </form>
    <div class="table-wrap">
        <table class="collection-history-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Loan</th>
                <th>Customer</th>
                <th>Collected By</th>
                <th>Method</th>
                <th>Note</th>
                <th class="text-right">Amount</th>
            </tr>
            </thead>
            <tbody id="collection-history-table-body">
            <?php if (!$collections): ?>
                <tr><td colspan="7">No collections yet.</td></tr>
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
                        <td><?= e(display_date((string) $item['collected_on'])) ?></td>
                        <td><?= e($item['loan_number']) ?></td>
                        <td><?= e($item['full_name']) ?></td>
                        <td><?= e((string) ($item['collected_by_name'] ?? '-')) ?></td>
                        <td><?= e($item['method']) ?></td>
                        <td><?= e($note) ?></td>
                        <td class="text-right"><?= e(money_label($pdo, (float) $item['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="collection-history-load-more-wrap">
        <?php if ($hasMore): ?>
            <div class="reports-filter-actions" style="justify-content: flex-end; margin-top: 12px;">
                <a class="btn btn-primary" href="<?= e($loadMoreUrl) ?>">Load More</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/collection_history_poll.php')) ?>"
     data-poll-include-query="1"
     data-poll-interval="<?= e((string) poll_interval_ms($pdo)) ?>"></div>

<?php require __DIR__ . '/../includes/layout_end.php';
