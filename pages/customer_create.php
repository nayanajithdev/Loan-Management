<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.create');

$pageTitle = 'Create Customer';
$activePage = 'customers';
$canUploadDocuments = can('customers.documents');

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Create Customer</h2>
        <a class="btn" href="<?= e(url('pages/customers.php')) ?>">
            <span class="btn-icon-inline" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
            </span>
            Back to Customers
        </a>
    </div>

    <form class="form-grid" method="post" action="<?= e(url('actions/customer_save.php')) ?>" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <div class="field">
            <label>Full Name</label>
            <input type="text" name="full_name" required>
        </div>
        <div class="field">
            <label>Phone</label>
            <input type="text" name="phone" required>
        </div>
        <div class="field">
            <label>NIC / ID</label>
            <input type="text" name="nic" required>
        </div>
        <div class="field full">
            <label>Status</label>
            <select name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div class="field" style="grid-column: span 6;">
            <label>Address</label>
            <textarea name="address"></textarea>
        </div>
        <div class="field" style="grid-column: span 6;">
            <label>Note</label>
            <textarea name="note" placeholder="Optional"></textarea>
        </div>
        <?php if ($canUploadDocuments): ?>
            <div class="field full">
                <label>Documents (Images or PDF)</label>
                <input type="file" name="documents[]" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,application/pdf,image/*" multiple>
                <small>You can select multiple files. Max 10MB each.</small>
            </div>
        <?php endif; ?>
        <div class="field full form-actions">
            <button type="submit" class="btn btn-primary customer-submit-btn">Save Customer</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
