<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/customers.php');
}
require_csrf('pages/customers.php');

require_roles(['superadmin', 'admin'], 'pages/customers.php');

$customerId = (int) ($_POST['customer_id'] ?? 0);
if ($customerId <= 0) {
    set_flash('error', 'Invalid customer selected.');
    redirect('pages/customers.php');
}

try {
    $pdo->beginTransaction();

    $customerStmt = $pdo->prepare('SELECT id, customer_code, full_name FROM customers WHERE id = :id FOR UPDATE');
    $customerStmt->execute(['id' => $customerId]);
    $customer = $customerStmt->fetch();

    if (!$customer) {
        throw new RuntimeException('Customer not found.');
    }

    $loanSummaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_loans,
            SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) AS non_closed_loans
         FROM loans
         WHERE customer_id = :customer_id"
    );
    $loanSummaryStmt->execute(['customer_id' => $customerId]);
    $loanSummary = $loanSummaryStmt->fetch() ?: ['total_loans' => 0, 'non_closed_loans' => 0];
    $totalLoans = (int) ($loanSummary['total_loans'] ?? 0);
    $nonClosedLoans = (int) ($loanSummary['non_closed_loans'] ?? 0);
    if ($nonClosedLoans > 0) {
        throw new RuntimeException('Customer has non-closed loans and cannot be deleted.');
    }

    $docsStmt = $pdo->prepare('SELECT file_path FROM customer_documents WHERE customer_id = :customer_id');
    $docsStmt->execute(['customer_id' => $customerId]);
    $docRows = $docsStmt->fetchAll();
    $docAbsPaths = [];
    foreach ($docRows as $row) {
        $relPath = trim((string) ($row['file_path'] ?? ''));
        if ($relPath === '') {
            continue;
        }
        $docAbsPaths[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    }

    if ($totalLoans > 0) {
        $loanIdsStmt = $pdo->prepare('SELECT id FROM loans WHERE customer_id = :customer_id');
        $loanIdsStmt->execute(['customer_id' => $customerId]);
        $loanIds = array_map(static fn(array $row): int => (int) $row['id'], $loanIdsStmt->fetchAll());

        if ($loanIds !== []) {
            $placeholders = implode(',', array_fill(0, count($loanIds), '?'));

            $deleteCollectionsStmt = $pdo->prepare("DELETE FROM collections WHERE loan_id IN ($placeholders)");
            $deleteCollectionsStmt->execute($loanIds);

            $deleteInstallmentsStmt = $pdo->prepare("DELETE FROM loan_installments WHERE loan_id IN ($placeholders)");
            $deleteInstallmentsStmt->execute($loanIds);

            $deleteLoansStmt = $pdo->prepare("DELETE FROM loans WHERE id IN ($placeholders)");
            $deleteLoansStmt->execute($loanIds);
        }
    }

    $deleteCustomerStmt = $pdo->prepare('DELETE FROM customers WHERE id = :customer_id');
    $deleteCustomerStmt->execute(['customer_id' => $customerId]);

    $pdo->commit();

    foreach ($docAbsPaths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $customerLabel = trim((string) ($customer['customer_code'] ?? '')) . ' - ' . trim((string) ($customer['full_name'] ?? ''));
    log_activity($pdo, 'customer.deleted', 'Customer deleted: ' . trim($customerLabel, ' -') . '.', [
        'customer_id' => $customerId,
        'customer_code' => (string) ($customer['customer_code'] ?? ''),
    ]);
    set_flash('success', 'Customer deleted successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e->getMessage() === 'Customer has non-closed loans and cannot be deleted.') {
        set_flash('error', 'Cannot delete this customer because active/defaulted loans are linked.');
        redirect('pages/customer_edit.php?customer_id=' . $customerId);
    }

    log_activity($pdo, 'customer.delete_failed', 'Customer delete failed.', [
        'customer_id' => $customerId,
        'reason' => $e->getMessage(),
    ]);
    set_flash('error', 'Failed to delete customer. Please try again.');
}

redirect('pages/customers.php');
