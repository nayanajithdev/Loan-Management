<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.edit', 'pages/customers.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/customers.php');
}
require_csrf('pages/customers.php');

$customerId = (int) ($_POST['customer_id'] ?? 0);
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$nic = trim((string) ($_POST['nic'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'active'));
$uploadedByUserId = (int) (current_user()['id'] ?? 0);
$documentsInput = $_FILES['documents'] ?? null;
$canUploadDocuments = can('customers.documents');

if ($customerId <= 0 || $fullName === '' || $phone === '' || $nic === '') {
    set_flash('error', 'Customer, full name, phone and NIC / ID are required.');
    redirect('pages/customer_edit.php?customer_id=' . $customerId);
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

if (has_uploaded_files($documentsInput) && !$canUploadDocuments) {
    set_flash('error', 'You do not have permission to upload customer documents.');
    redirect('pages/customer_edit.php?customer_id=' . $customerId);
}

$existsStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id LIMIT 1');
$existsStmt->execute(['id' => $customerId]);
if (!$existsStmt->fetch()) {
    set_flash('error', 'Customer not found.');
    redirect('pages/customers.php');
}
require_customer_access($pdo, $customerId);

$duplicateNicStmt = $pdo->prepare('SELECT id FROM customers WHERE nic = :nic AND id <> :id LIMIT 1');
$duplicateNicStmt->execute([
    'nic' => $nic,
    'id' => $customerId,
]);
if ($duplicateNicStmt->fetch()) {
    set_flash('error', 'NIC / ID is already used by another customer.');
    redirect('pages/customer_edit.php?customer_id=' . $customerId);
}

try {
    $pdo->beginTransaction();

    $customerStmt = $pdo->prepare('SELECT customer_code FROM customers WHERE id = :id FOR UPDATE');
    $customerStmt->execute(['id' => $customerId]);
    $customer = $customerStmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Customer not found.');
    }

    $updateStmt = $pdo->prepare(
        'UPDATE customers
         SET full_name = :full_name,
             phone = :phone,
             nic = :nic,
             address = :address,
             note = :note,
             status = :status
         WHERE id = :id'
    );

    $updateStmt->execute([
        'full_name' => $fullName,
        'phone' => $phone,
        'nic' => $nic,
        'address' => $address === '' ? null : $address,
        'note' => $note === '' ? null : $note,
        'status' => $status,
        'id' => $customerId,
    ]);

    $uploadedCount = $canUploadDocuments
        ? store_customer_documents($pdo, $customerId, (string) $customer['customer_code'], $documentsInput, $uploadedByUserId)
        : 0;

    $pdo->commit();

    $message = $uploadedCount > 0
        ? 'Customer updated successfully. Added ' . $uploadedCount . ' document(s).'
        : 'Customer updated successfully.';
    log_activity($pdo, 'customer.updated', 'Customer updated: ' . $fullName . '.', [
        'customer_id' => $customerId,
        'documents_uploaded' => $uploadedCount,
    ]);
    set_flash('success', $message);
    redirect('pages/customers.php?customer_id=' . $customerId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity($pdo, 'customer.update_failed', 'Customer update failed.', [
        'customer_id' => $customerId,
        'reason' => $e->getMessage(),
    ]);
    set_flash('error', 'Failed to update customer. Please try again.');
    redirect('pages/customer_edit.php?customer_id=' . $customerId);
}
