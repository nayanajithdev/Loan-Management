<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

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

ob_start();
if (!$collections):
?>
<tr><td colspan="7">No collections yet.</td></tr>
<?php
else:
    foreach ($collections as $item):
        $note = (string) ($item['note'] ?? '');
        if ((int) $item['has_advance'] === 1 && stripos($note, 'advance') === false) {
            $note = trim($note === '' ? 'Advance payment' : $note . ' | Advance payment');
        }
?>
<tr>
    <td><?= e($item['collected_on']) ?></td>
    <td><?= e($item['loan_number']) ?></td>
    <td><?= e($item['full_name']) ?></td>
    <td><?= e((string) ($item['collected_by_name'] ?? '-')) ?></td>
    <td><?= e($item['method']) ?></td>
    <td><?= e($note) ?></td>
    <td class="text-right">LKR <?= e(money((float) $item['amount'])) ?></td>
</tr>
<?php
    endforeach;
endif;
$historyHtml = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'updated_at' => date('H:i:s'),
    'targets' => [
        '#collection-history-table-body' => $historyHtml,
    ],
], JSON_UNESCAPED_UNICODE);
