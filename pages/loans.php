<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l2', 'collector']);

$pageTitle = 'Loans';
$activePage = 'loans';

$loans = $pdo->query(
    "SELECT l.*, c.full_name, l.assigned_user_id, u.full_name AS assigned_user_name, u.username AS assigned_username, u.role AS assigned_role,
        COALESCE((SELECT SUM(amount) FROM collections col WHERE col.loan_id = l.id), 0) AS collected_amount
     FROM loans l
     JOIN customers c ON c.id = l.customer_id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     ORDER BY l.id DESC"
)->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Loan List</h2>
        <a class="btn btn-primary" href="<?= e(url('pages/loan_create.php')) ?>">New Loan</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Loan No</th>
                <th>Customer</th>
                <th>Principal</th>
                <th>Total</th>
                <th>Collected</th>
                <th>Balance</th>
                <th>Assigned To</th>
                <th>Frequency</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$loans): ?>
                <tr><td colspan="9">No loans yet.</td></tr>
            <?php else: ?>
                <?php foreach ($loans as $loan): ?>
                    <?php $balance = (float) $loan['total_amount'] - (float) $loan['collected_amount']; ?>
                    <?php $selectUrl = url('pages/loan_edit.php?loan_id=' . (int) $loan['id']); ?>
                    <tr class="table-row-clickable" data-select-url="<?= e($selectUrl) ?>">
                        <td><?= e($loan['loan_number']) ?></td>
                        <td><?= e($loan['full_name']) ?></td>
                        <td><?= e(money_label($pdo, (float) $loan['principal_amount'])) ?></td>
                        <td><?= e(money_label($pdo, (float) $loan['total_amount'])) ?></td>
                        <td><?= e(money_label($pdo, (float) $loan['collected_amount'])) ?></td>
                        <td><?= e(money_label($pdo, $balance)) ?></td>
                        <td>
                            <?php if (!empty($loan['assigned_user_name'])): ?>
                                <?= e($loan['assigned_user_name']) ?>
                            <?php else: ?>
                                <span class="badge badge-neutral">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($loan['installment_frequency']) ?> (<?= e((string) $loan['installment_count']) ?>)</td>
                        <td><span class="badge badge-<?= e(status_badge_class($loan['status'])) ?>"><?= e($loan['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
