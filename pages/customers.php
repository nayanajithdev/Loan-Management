<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.view');

$pageTitle = 'Customers';
$activePage = 'customers';

$current = current_user();
$currentUserId = (int) ($current['id'] ?? 0);
$canCreateCustomer = can('customers.create');
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$searchTerm = mb_substr($searchTerm, 0, 120);
$searchClause = " AND (
    c.full_name LIKE :search_name ESCAPE '\\\\'
    OR c.phone LIKE :search_phone ESCAPE '\\\\'
    OR c.nic LIKE :search_nic ESCAPE '\\\\'
)";

if (can_view_all_customers()) {
    $sql =
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
         WHERE 1=1" . ($searchTerm !== '' ? $searchClause : '') . "
         ORDER BY c.id DESC";
    $customerStmt = $pdo->prepare($sql);
    $params = [];
} else {
    $sql =
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
         WHERE (
                EXISTS (
                    SELECT 1
                    FROM loans l_assigned
                    WHERE l_assigned.customer_id = c.id
                      AND l_assigned.assigned_user_id = :uid
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM loans l_any
                    WHERE l_any.customer_id = c.id
                )
         )" . ($searchTerm !== '' ? $searchClause : '') . "
         ORDER BY c.id DESC";
    $customerStmt = $pdo->prepare($sql);
    $params = ['uid' => $currentUserId];
}

if ($searchTerm !== '') {
    $searchValue = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm) . '%';
    $params['search_name'] = $searchValue;
    $params['search_phone'] = $searchValue;
    $params['search_nic'] = $searchValue;
}

$customerStmt->execute($params);
$customers = $customerStmt->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Customer List</h2>
        <div class="panel-head-actions">
            <form
                method="get"
                class="panel-head-actions"
            >
                <div class="search-control">
                    <input
                        type="text"
                        name="q"
                        placeholder="Search..."
                        value="<?= e($searchTerm) ?>"
                        aria-label="Search customer"
                    >
                    <button type="submit" class="btn search-submit" aria-label="Search customer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
                    </button>
                </div>
                <?php if ($searchTerm !== ''): ?>
                    <a
                        class="btn"
                        href="<?= e(url('pages/customers.php')) ?>"
                    >Reset</a>
                <?php endif; ?>
            </form>
            <?php if ($canCreateCustomer): ?>
                <a class="btn btn-primary" href="<?= e(url('pages/customer_create.php')) ?>">New Customer</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-wrap">
        <table class="zebra-table customers-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>ID No</th>
                <th>Phone</th>
                <th>Running Loan (Principal)</th>
                <th>Customer Quality</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$customers): ?>
                <tr>
                    <td colspan="6">
                        <?= $searchTerm !== '' ? 'No customers match your search.' : 'No customers yet.' ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                    <?php $selectUrl = url('pages/customer_edit.php?customer_id=' . (int) $customer['id']); ?>
                    <?php $overdueCount = (int) ($customer['overdue_installment_count'] ?? 0); ?>
                    <tr class="table-row-clickable" data-select-url="<?= e($selectUrl) ?>">
                        <td><?= e($customer['full_name']) ?></td>
                        <td><?= e(customer_id_no_label((string) ($customer['nic'] ?? ''))) ?></td>
                        <td><?= e($customer['phone']) ?></td>
                        <td><?= e(money_label($pdo, (float) ($customer['running_principal'] ?? 0))) ?></td>
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
