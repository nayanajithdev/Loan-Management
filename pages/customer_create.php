<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector']);

$pageTitle = 'Create Customer';
$activePage = 'customers';

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Create Customer</h2>
        <a class="btn" href="<?= e(url('pages/customers.php')) ?>">Back to Customers</a>
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
            <input type="text" name="nic">
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
        <div class="field full">
            <label>Documents (Images or PDF)</label>
            <input type="file" name="documents[]" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,application/pdf,image/*" multiple>
            <small>You can select multiple files. Max 10MB each.</small>
        </div>
        <div class="field full form-actions">
            <button type="submit" class="btn btn-primary customer-submit-btn">Save Customer</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
