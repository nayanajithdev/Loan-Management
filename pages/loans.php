<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l2', 'collector']);

$pageTitle = 'Loans';
$activePage = 'loans';

$loans = $pdo->query(
    "SELECT l.*, c.full_name, l.assigned_user_id, u.full_name AS assigned_user_name, u.username AS assigned_username, u.role AS assigned_role,
        COALESCE((SELECT SUM(li.due_amount - li.paid_amount) FROM loan_installments li WHERE li.loan_id = l.id AND li.status IN ('pending', 'partial', 'overdue')), 0) AS outstanding_amount,
        COALESCE((SELECT COUNT(*) FROM loan_installments li WHERE li.loan_id = l.id AND li.status IN ('pending', 'partial', 'overdue')), 0) AS remaining_installment_count
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
        <div style="display:flex; gap:8px;">
            <a class="btn" href="<?= e(url('pages/loan_legacy_create.php')) ?>">
                <span class="btn-icon-inline" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus-icon lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </span>
                Add Old Loan
            </a>
            <a class="btn btn-primary" href="<?= e(url('pages/loan_create.php')) ?>">New Loan</a>
        </div>
    </div>
    <div class="table-wrap">
        <table class="zebra-table loans-table">
            <thead>
            <tr>
                <th>Loan No</th>
                <th>Customer</th>
                <th>Principal</th>
                <th>Total</th>
                <th>Collected</th>
                <th>Balance</th>
                <th>Assigned To</th>
                <th>Remaining Installments</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$loans): ?>
                <tr><td colspan="9">No loans yet.</td></tr>
            <?php else: ?>
                <?php foreach ($loans as $loan): ?>
                    <?php $balance = max(0, (float) $loan['outstanding_amount']); ?>
                    <?php $collectedAmount = max(0, round((float) $loan['total_amount'] - $balance, 2)); ?>
                    <?php $remainingInstallments = (int) ($loan['remaining_installment_count'] ?? 0); ?>
                    <?php $selectUrl = url('pages/loan_edit.php?loan_id=' . (int) $loan['id']); ?>
                    <tr class="table-row-clickable" data-select-url="<?= e($selectUrl) ?>">
                        <td><?= e($loan['loan_number']) ?></td>
                        <td><?= e($loan['full_name']) ?></td>
                        <td><?= e(money_label($pdo, (float) $loan['principal_amount'])) ?></td>
                        <td><?= e(money_label($pdo, (float) $loan['total_amount'])) ?></td>
                        <td><?= e(money_label($pdo, $collectedAmount)) ?></td>
                        <td><?= $balance <= 0 ? '---' : e(money_label($pdo, $balance)) ?></td>
                        <td>
                            <?php if (!empty($loan['assigned_user_name'])): ?>
                                <?= e($loan['assigned_user_name']) ?>
                            <?php else: ?>
                                <span class="badge badge-neutral">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($remainingInstallments <= 0): ?>
                                <span class="badge badge-success">Completed</span>
                            <?php else: ?>
                                <?= e((string) $remainingInstallments) ?> left (<?= e((string) $loan['installment_frequency']) ?>)
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= e(status_badge_class($loan['status'])) ?>"><?= e($loan['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
