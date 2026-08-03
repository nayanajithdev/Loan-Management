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
                      AND " . collector_assignment_scope_sql('l_assigned', 'uid') . "
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

<div class="customers-page-toolbar">
    <form method="get" class="customers-search-form">
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
        <a class="btn btn-primary" href="<?= e(url('pages/customer_create.php')) ?>">
            <span class="btn-icon-inline" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-plus-icon lucide-user-plus"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
            </span>
            New Customer
        </a>
    <?php endif; ?>
</div>

<section class="panel customers-list-panel">
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
    <div class="customer-mobile-card-list">
        <?php if (!$customers): ?>
            <div class="customer-mobile-empty">
                <?= $searchTerm !== '' ? 'No customers match your search.' : 'No customers yet.' ?>
            </div>
        <?php else: ?>
            <?php foreach ($customers as $customer): ?>
                <?php
                $selectUrl = url('pages/customer_edit.php?customer_id=' . (int) $customer['id']);
                $overdueCount = (int) ($customer['overdue_installment_count'] ?? 0);
                $qualityLabel = $overdueCount <= 0 ? 'Good' : ((string) $overdueCount . ' overdue installments');
                $qualityClass = $overdueCount <= 0 ? 'is-good' : ($overdueCount <= 3 ? 'is-warning' : 'is-danger');
                $address = trim((string) ($customer['address'] ?? ''));
                ?>
                <article class="customer-mobile-card" data-select-url="<?= e($selectUrl) ?>">
                    <div class="customer-mobile-card-head">
                        <strong><?= e((string) $customer['full_name']) ?></strong>
                        <?php $phoneDigits = preg_replace('/\D+/', '', (string) $customer['phone']); ?>
                        <?php if ($phoneDigits !== ''): ?>
                            <a class="customer-mobile-phone" href="tel:<?= e($phoneDigits) ?>"><?= e((string) $customer['phone']) ?></a>
                        <?php else: ?>
                            <span><?= e((string) $customer['phone']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="customer-mobile-card-body">
                        <div class="customer-mobile-metric">
                            <span>ID No</span>
                            <strong><?= e(customer_id_no_label((string) ($customer['nic'] ?? ''))) ?></strong>
                        </div>
                        <div class="customer-mobile-metric is-running-loan">
                            <span>Running Loan (Principal)</span>
                            <strong><?= e(money_label($pdo, (float) ($customer['running_principal'] ?? 0))) ?></strong>
                        </div>
                        <div class="customer-mobile-metric">
                            <span>Address</span>
                            <strong><?= e($address !== '' ? $address : '-') ?></strong>
                        </div>
                        <div class="customer-mobile-metric customer-mobile-quality <?= e($qualityClass) ?>">
                            <span>Customer Quality</span>
                            <strong><?= e($qualityLabel) ?></strong>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
