<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector']);

$viewer = current_user();
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$customers = customer_list_rows($pdo, $viewer, $searchTerm);

$rows = [];
foreach ($customers as $customer) {
    $overdueCount = (int) ($customer['overdue_installment_count'] ?? 0);

    if ($overdueCount <= 0) {
        $qualityLabel = 'Good';
        $qualityBadgeClass = 'badge-success';
    } elseif ($overdueCount <= 3) {
        $qualityLabel = $overdueCount . ' overdue installments';
        $qualityBadgeClass = 'badge-warning';
    } else {
        $qualityLabel = $overdueCount . ' overdue installments';
        $qualityBadgeClass = 'badge-danger';
    }

    $rows[] = [
        'select_url' => url('pages/customer_edit.php?customer_id=' . (int) $customer['id']),
        'customer_code' => (string) ($customer['customer_code'] ?? ''),
        'full_name' => (string) ($customer['full_name'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'running_principal' => money_label($pdo, (float) ($customer['running_principal'] ?? 0)),
        'quality_badge_class' => $qualityBadgeClass,
        'quality_label' => $qualityLabel,
        'status_badge_class' => 'badge-' . status_badge_class((string) ($customer['status'] ?? 'active')),
        'status_label' => (string) ($customer['status'] ?? 'active'),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'rows' => $rows,
    'empty_message' => $searchTerm !== '' ? 'No customers match your search.' : 'No customers yet.',
], JSON_UNESCAPED_UNICODE);

