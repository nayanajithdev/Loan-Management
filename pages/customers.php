<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Customers';
$activePage = 'customers';

$current = current_user();
$currentUserId = (int) ($current['id'] ?? 0);

if (can_view_all_customers()) {
    $customers = $pdo->query(
        "SELECT
            c.*,
            COALESCE((
                SELECT SUM(l.principal_amount)
                FROM loans l
                WHERE l.customer_id = c.id
                  AND l.status = 'active'
            ), 0) AS running_principal,
            COALESCE((
                SELECT COUNT(*)
                FROM loan_installments li
                JOIN loans lq ON lq.id = li.loan_id
                WHERE lq.customer_id = c.id
                  AND (
                      (li.paid_on IS NOT NULL AND li.paid_on > li.due_date)
                      OR (li.paid_on IS NULL AND li.due_date < CURDATE() AND li.status IN ('pending', 'partial', 'overdue'))
                  )
            ), 0) AS overdue_installment_count
         FROM customers c
         ORDER BY c.id DESC"
    )->fetchAll();
} else {
    $customerStmt = $pdo->prepare(
        "SELECT
            c.*,
            COALESCE((
                SELECT SUM(l2.principal_amount)
                FROM loans l2
                WHERE l2.customer_id = c.id
                  AND l2.status = 'active'
            ), 0) AS running_principal,
            COALESCE((
                SELECT COUNT(*)
                FROM loan_installments li
                JOIN loans lq ON lq.id = li.loan_id
                WHERE lq.customer_id = c.id
                  AND (
                      (li.paid_on IS NOT NULL AND li.paid_on > li.due_date)
                      OR (li.paid_on IS NULL AND li.due_date < CURDATE() AND li.status IN ('pending', 'partial', 'overdue'))
                  )
            ), 0) AS overdue_installment_count
         FROM customers c
         WHERE EXISTS (
                SELECT 1
                FROM loans l_assigned
                WHERE l_assigned.customer_id = c.id
                  AND l_assigned.assigned_user_id = :uid
            )
            OR EXISTS (
                SELECT 1
                FROM loans l_unassigned
                WHERE l_unassigned.customer_id = c.id
                  AND l_unassigned.assigned_user_id IS NULL
            )
            OR NOT EXISTS (
                SELECT 1
                FROM loans l_any
                WHERE l_any.customer_id = c.id
            )
         ORDER BY c.id DESC"
    );
    $customerStmt->execute(['uid' => $currentUserId]);
    $customers = $customerStmt->fetchAll();
}

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Customer List</h2>
        <a class="btn btn-primary" href="<?= e(url('pages/customer_create.php')) ?>">New Customer</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Running Loan (Principal)</th>
                <th>Customer Quality</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$customers): ?>
                <tr><td colspan="6">No customers yet.</td></tr>
            <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                    <?php $selectUrl = url('pages/customer_edit.php?customer_id=' . (int) $customer['id']); ?>
                    <?php $overdueCount = (int) ($customer['overdue_installment_count'] ?? 0); ?>
                    <tr class="table-row-clickable" data-select-url="<?= e($selectUrl) ?>">
                        <td><?= e($customer['customer_code']) ?></td>
                        <td><?= e($customer['full_name']) ?></td>
                        <td><?= e($customer['phone']) ?></td>
                        <td>LKR <?= e(money((float) ($customer['running_principal'] ?? 0))) ?></td>
                        <td>
                            <?php if ($overdueCount <= 0): ?>
                                <span class="badge badge-success">Good</span>
                            <?php elseif ($overdueCount <= 3): ?>
                                <span class="badge badge-warning"><?= e((string) $overdueCount) ?> overdue installments</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><?= e((string) $overdueCount) ?> overdue installments</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= e(status_badge_class($customer['status'])) ?>"><?= e($customer['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
