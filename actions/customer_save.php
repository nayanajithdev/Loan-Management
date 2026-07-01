<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.create', 'pages/customers.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/customers.php');
}
require_csrf('pages/customer_create.php');

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$nic = trim((string) ($_POST['nic'] ?? ''));
$address = trim((string) ($_POST['address'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'active'));
$uploadedByUserId = (int) (current_user()['id'] ?? 0);
$documentsInput = $_FILES['documents'] ?? null;
$canUploadDocuments = can('customers.documents');

if ($fullName === '' || $phone === '') {
    set_flash('error', 'Full name and phone are required.');
    redirect('pages/customer_create.php');
}

if (has_uploaded_files($documentsInput) && !$canUploadDocuments) {
    set_flash('error', 'You do not have permission to upload customer documents.');
    redirect('pages/customer_create.php');
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$customerCode = next_customer_code($pdo);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO customers (customer_code, full_name, phone, nic, address, note, status)
         VALUES (:customer_code, :full_name, :phone, :nic, :address, :note, :status)'
    );

    $stmt->execute([
        'customer_code' => $customerCode,
        'full_name' => $fullName,
        'phone' => $phone,
        'nic' => $nic === '' ? null : $nic,
        'address' => $address === '' ? null : $address,
        'note' => $note === '' ? null : $note,
        'status' => $status,
    ]);

    $customerId = (int) $pdo->lastInsertId();
    $uploadedCount = $canUploadDocuments
        ? store_customer_documents($pdo, $customerId, $customerCode, $documentsInput, $uploadedByUserId)
        : 0;

    $pdo->commit();

    $message = $uploadedCount > 0
        ? 'Customer created successfully with ' . $uploadedCount . ' document(s).'
        : 'Customer created successfully.';
    log_activity($pdo, 'customer.created', 'Customer created: ' . $fullName . '.', [
        'customer_id' => $customerId,
        'customer_code' => $customerCode,
        'documents_uploaded' => $uploadedCount,
    ]);
    set_flash('success', $message);
    redirect('pages/customers.php?customer_id=' . $customerId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_activity($pdo, 'customer.create_failed', 'Customer creation failed.', [
        'reason' => $e->getMessage(),
        'full_name' => $fullName,
    ]);
    set_flash('error', 'Failed to create customer. Please try again.');
    redirect('pages/customer_create.php');
}
