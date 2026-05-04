<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Edit Customer';
$activePage = 'customers';
$customerId = (int) ($_GET['customer_id'] ?? 0);

if ($customerId <= 0) {
    set_flash('error', 'Invalid customer selected.');
    redirect('pages/customers.php');
}

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    set_flash('error', 'Customer not found.');
    redirect('pages/customers.php');
}

$docStmt = $pdo->prepare(
    'SELECT id, original_name, file_path, mime_type, file_size, created_at
     FROM customer_documents
     WHERE customer_id = :customer_id
     ORDER BY id DESC'
);
$docStmt->execute(['customer_id' => $customerId]);
$documents = $docStmt->fetchAll();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Edit Customer</h2>
        <a class="btn" href="<?= e(url('pages/customers.php?customer_id=' . $customerId)) ?>">Back to Customers</a>
    </div>

    <form class="form-grid" method="post" action="<?= e(url('actions/customer_update.php')) ?>" enctype="multipart/form-data">
        <input type="hidden" name="customer_id" value="<?= e((string) $customer['id']) ?>">

        <div class="field">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?= e($customer['full_name']) ?>" required>
        </div>
        <div class="field">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= e($customer['phone']) ?>" required>
        </div>
        <div class="field">
            <label>NIC / ID</label>
            <input type="text" name="nic" value="<?= e((string) $customer['nic']) ?>">
        </div>
        <div class="field full">
            <label>Status</label>
            <select name="status">
                <option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $customer['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="field" style="grid-column: span 6;">
            <label>Address</label>
            <textarea name="address"><?= e((string) $customer['address']) ?></textarea>
        </div>
        <div class="field" style="grid-column: span 6;">
            <label>Note</label>
            <textarea name="note" placeholder="Optional"><?= e((string) ($customer['note'] ?? '')) ?></textarea>
        </div>
        <div class="field full">
            <label>Add Documents (Images or PDF)</label>
            <input type="file" name="documents[]" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,application/pdf,image/*" multiple>
            <small>You can add more files. Max 10MB each.</small>
        </div>
        <div class="field full form-actions">
            <button type="submit" class="btn btn-primary customer-submit-btn">Update Customer</button>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Documents</h2>
    </div>

    <?php if (!$documents): ?>
        <p>No documents uploaded for this customer.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>File</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Uploaded</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td class="doc-name-cell" title="<?= e($doc['original_name']) ?>"><?= e($doc['original_name']) ?></td>
                        <td><?= e($doc['mime_type']) ?></td>
                        <td><?= e(readable_file_size((int) $doc['file_size'])) ?></td>
                        <td><?= e($doc['created_at']) ?></td>
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
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
