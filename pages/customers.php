<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Customers';
$activePage = 'customers';

$customers = $pdo->query('SELECT * FROM customers ORDER BY id DESC')->fetchAll();

$selectedCustomerId = (int) ($_GET['customer_id'] ?? 0);
$selectedCustomer = null;
$selectedCustomerDocs = [];
if ($selectedCustomerId > 0) {
    foreach ($customers as $customer) {
        if ((int) $customer['id'] === $selectedCustomerId) {
            $selectedCustomer = $customer;
            break;
        }
    }

    if ($selectedCustomer) {
        $docStmt = $pdo->prepare(
            'SELECT id, original_name, file_path, mime_type, file_size, created_at
             FROM customer_documents
             WHERE customer_id = :customer_id
             ORDER BY id DESC'
        );
        $docStmt->execute(['customer_id' => $selectedCustomerId]);
        $selectedCustomerDocs = $docStmt->fetchAll();
    }
}

require __DIR__ . '/../includes/layout_start.php';
?>

<div class="split-layout">
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
                    <th>NIC</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$customers): ?>
                    <tr><td colspan="5">No customers yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <?php $selectUrl = url('pages/customers.php?customer_id=' . (int) $customer['id']); ?>
                        <tr class="table-row-clickable <?= $selectedCustomer && (int) $customer['id'] === (int) $selectedCustomer['id'] ? 'row-selected' : '' ?>" data-select-url="<?= e($selectUrl) ?>">
                            <td><?= e($customer['customer_code']) ?></td>
                            <td><?= e($customer['full_name']) ?></td>
                            <td><?= e($customer['phone']) ?></td>
                            <td><?= e((string) $customer['nic']) ?></td>
                            <td><span class="badge badge-<?= e(status_badge_class($customer['status'])) ?>"><?= e($customer['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Customer Info</h2>
        </div>

        <?php if (!$selectedCustomer): ?>
            <div class="customer-info-empty">
                <div class="metric-row" style="margin-bottom:12px;">
                    <div class="metric-box">
                        <p>Code</p>
                        <h3>-</h3>
                    </div>
                    <div class="metric-box">
                        <p>Status</p>
                        <h3>-</h3>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field full">
                        <label>Full Name</label>
                        <input type="text" value="" placeholder="-" readonly>
                    </div>
                    <div class="field full">
                        <label>Phone</label>
                        <input type="text" value="" placeholder="-" readonly>
                    </div>
                    <div class="field full">
                        <label>NIC / ID</label>
                        <input type="text" value="" placeholder="-" readonly>
                    </div>
                    <div class="field full">
                        <label>Address</label>
                        <textarea placeholder="-" readonly></textarea>
                    </div>
                    <div class="field full">
                        <label>Note</label>
                        <textarea placeholder="-" readonly></textarea>
                    </div>
                    <div class="field full">
                        <label>Created</label>
                        <input type="text" value="" placeholder="-" readonly>
                    </div>
                </div>

                <div style="margin-top:14px;">
                    <h3 style="margin:0 0 10px; font-size:16px;">Documents</h3>
                    <p style="margin:0; color:#9c9c9c;">No customer selected.</p>
                </div>

                <div class="panel-actions-footer">
                    <button class="btn btn-primary" type="button" disabled>Edit Customer</button>
                </div>
            </div>
        <?php else: ?>
            <div class="metric-row" style="margin-bottom:12px;">
                <div class="metric-box">
                    <p>Code</p>
                    <h3><?= e($selectedCustomer['customer_code']) ?></h3>
                </div>
                <div class="metric-box">
                    <p>Status</p>
                    <h3><?= e(ucfirst((string) $selectedCustomer['status'])) ?></h3>
                </div>
            </div>

            <div class="form-grid">
                <div class="field full">
                    <label>Full Name</label>
                    <input type="text" value="<?= e($selectedCustomer['full_name']) ?>" readonly>
                </div>
                <div class="field full">
                    <label>Phone</label>
                    <input type="text" value="<?= e($selectedCustomer['phone']) ?>" readonly>
                </div>
                <div class="field full">
                    <label>NIC / ID</label>
                    <input type="text" value="<?= e((string) $selectedCustomer['nic']) ?>" readonly>
                </div>
                <div class="field full">
                    <label>Address</label>
                    <textarea readonly><?= e((string) $selectedCustomer['address']) ?></textarea>
                </div>
                <div class="field full">
                    <label>Note</label>
                    <textarea readonly><?= e((string) ($selectedCustomer['note'] ?? '')) ?></textarea>
                </div>
                <div class="field full">
                    <label>Created</label>
                    <input type="text" value="<?= e($selectedCustomer['created_at']) ?>" readonly>
                </div>
            </div>

            <div style="margin-top:14px;">
                <h3 style="margin:0 0 10px; font-size:16px;">Documents</h3>
                <?php if (!$selectedCustomerDocs): ?>
                    <p style="margin:0; color:#9c9c9c;">No documents uploaded.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="docs-table-compact">
                            <thead>
                            <tr>
                                <th>File</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($selectedCustomerDocs as $doc): ?>
                                <?php
                                $docName = (string) $doc['original_name'];
                                $docNameShort = strlen($docName) > 30 ? substr($docName, 0, 30) . '...' : $docName;
                                ?>
                                <tr>
                                    <td class="doc-name-cell" title="<?= e($docName) ?>"><?= e($docNameShort) ?></td>
                                    <td>
                                        <a class="btn btn-icon" href="<?= e(url((string) $doc['file_path'])) ?>" target="_blank" rel="noopener" title="View" aria-label="View">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye-icon lucide-eye"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <a class="btn btn-icon" href="<?= e(url('actions/customer_document_download.php?doc_id=' . (int) $doc['id'])) ?>" title="Download" aria-label="Download">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-download-icon lucide-download"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel-actions-footer">
                <a class="btn btn-primary" href="<?= e(url('pages/customer_edit.php?customer_id=' . (int) $selectedCustomer['id'])) ?>">Edit Customer</a>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/layout_end.php';
