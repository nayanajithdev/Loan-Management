<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Collection History';
$activePage = 'collections';

refresh_overdue_installments($pdo);

$collections = $pdo->query(
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
     GROUP BY COALESCE(col.payment_ref, CONCAT('legacy-', col.id)), l.loan_number, c.full_name
     ORDER BY latest_id DESC
     LIMIT 50"
)->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<p class="live-indicator" id="js-last-updated">Last update: waiting...</p>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Collection History</h2>
    </div>
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
                        <td><?= e($item['collected_on']) ?></td>
                        <td><?= e($item['loan_number']) ?></td>
                        <td><?= e($item['full_name']) ?></td>
                        <td><?= e((string) ($item['collected_by_name'] ?? '-')) ?></td>
                        <td><?= e($item['method']) ?></td>
                        <td><?= e($note) ?></td>
                        <td class="text-right">LKR <?= e(money((float) $item['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/collection_history_poll.php')) ?>"
     data-poll-interval="10000"></div>

<?php require __DIR__ . '/../includes/layout_end.php';
