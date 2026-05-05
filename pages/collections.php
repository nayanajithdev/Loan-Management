<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector']);

$pageTitle = 'Collection History';
$activePage = 'collections';

refresh_overdue_installments($pdo);
$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);
$selectedCustomerId = (int) ($_GET['customer_id'] ?? 0);

$scopeSql = '';
$params = [];
if (is_collector_role($currentRole)) {
    $scopeSql = ' WHERE (l.assigned_user_id = :assigned_user_id OR l.assigned_user_id IS NULL)';
    $params['assigned_user_id'] = $currentUserId;
}

$customerFilterSql = '';
if ($selectedCustomerId > 0) {
    $customerFilterSql = $scopeSql === '' ? ' WHERE c.id = :customer_id' : ' AND c.id = :customer_id';
    $params['customer_id'] = $selectedCustomerId;
}

$customerListSql = "SELECT DISTINCT c.id, c.full_name
                    FROM customers c
                    LEFT JOIN loans l ON l.customer_id = c.id";
if (is_collector_role($currentRole)) {
    $customerListSql .= " WHERE (l.assigned_user_id = :assigned_user_id OR l.assigned_user_id IS NULL OR l.id IS NULL)";
}
$customerListSql .= " ORDER BY c.full_name ASC";
$customerListStmt = $pdo->prepare($customerListSql);
if (is_collector_role($currentRole)) {
    $customerListStmt->execute(['assigned_user_id' => $currentUserId]);
} else {
    $customerListStmt->execute();
}
$customerOptions = $customerListStmt->fetchAll();

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
     {$scopeSql}{$customerFilterSql}
     GROUP BY COALESCE(col.payment_ref, CONCAT('legacy-', col.id)), l.loan_number, c.full_name
     ORDER BY latest_id DESC
     LIMIT 50"
);
$collectionsStmt->execute($params);
$collections = $collectionsStmt->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<p class="live-indicator" id="js-last-updated">Last update: waiting...</p>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Collection History</h2>
    </div>
    <form method="get" class="form-grid" style="margin-bottom: 12px;">
        <div class="field">
            <label>Customer</label>
            <select name="customer_id">
                <option value="0">All Customers</option>
                <?php foreach ($customerOptions as $customer): ?>
                    <option value="<?= e((string) $customer['id']) ?>" <?= (int) $customer['id'] === $selectedCustomerId ? 'selected' : '' ?>>
                        <?= e((string) $customer['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field reports-filter-actions" style="grid-column: span 12; justify-content: flex-start;">
            <a class="btn" href="<?= e(url('pages/collections.php')) ?>">Reset</a>
            <button type="submit" class="btn btn-primary">Apply</button>
        </div>
    </form>
    <div class="table-wrap">
        <table>
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
                    $note = (string) ($item['note'] ?? '');
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
</section>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/collection_history_poll.php')) ?>"
     data-poll-include-query="1"
     data-poll-interval="<?= e((string) poll_interval_ms($pdo)) ?>"></div>

<?php require __DIR__ . '/../includes/layout_end.php';
