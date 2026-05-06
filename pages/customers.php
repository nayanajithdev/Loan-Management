<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector']);

$pageTitle = 'Customers';
$activePage = 'customers';

$current = current_user();
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$customers = customer_list_rows($pdo, $current, $searchTerm);

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Customer List</h2>
        <div class="panel-head-actions">
            <form
                method="get"
                class="panel-head-actions"
                data-customer-search-form
                data-search-endpoint="<?= e(url('api/customers_search.php')) ?>"
            >
                <input
                    type="text"
                    name="q"
                    class="search"
                    placeholder="Search customer"
                    value="<?= e($searchTerm) ?>"
                    data-customer-search-input
                    autocomplete="off"
                >
                <a
                    class="btn"
                    href="<?= e(url('pages/customers.php')) ?>"
                    data-customer-search-reset
                >Reset</a>
            </form>
            <a class="btn btn-primary" href="<?= e(url('pages/customer_create.php')) ?>">New Customer</a>
        </div>
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
            <tbody data-customer-table-body>
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
                        <td><?= e($customer['customer_code']) ?></td>
                        <td><?= e($customer['full_name']) ?></td>
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
