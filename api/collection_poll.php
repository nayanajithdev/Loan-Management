<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('today_collections.view');

refresh_overdue_installments($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$selectedDateMode = trim((string) ($_GET['date_mode'] ?? ''));
$selectedCollectionStatus = trim((string) ($_GET['collection_status'] ?? 'pending'));
$customDateInput = trim((string) ($_GET['date'] ?? today()));
$customDateObj = DateTime::createFromFormat('Y-m-d', $customDateInput);
$customDate = ($customDateObj && $customDateObj->format('Y-m-d') === $customDateInput) ? $customDateInput : today();

$todayDate = today();
$tomorrowDate = (new DateTimeImmutable($todayDate))->add(new DateInterval('P1D'))->format('Y-m-d');
$dayAfterTomorrowDate = (new DateTimeImmutable($todayDate))->add(new DateInterval('P2D'))->format('Y-m-d');

if (!in_array($selectedDateMode, ['today', 'tomorrow', 'day_after_tomorrow', 'custom'], true)) {
    if ($customDate === $todayDate) {
        $selectedDateMode = 'today';
    } elseif ($customDate === $tomorrowDate) {
        $selectedDateMode = 'tomorrow';
    } elseif ($customDate === $dayAfterTomorrowDate) {
        $selectedDateMode = 'day_after_tomorrow';
    } else {
        $selectedDateMode = 'custom';
    }
}
if (!in_array($selectedCollectionStatus, ['pending', 'collected'], true)) {
    $selectedCollectionStatus = 'pending';
}
if ($selectedDateMode !== 'today') {
    $selectedCollectionStatus = 'pending';
}

$selectedDate = match ($selectedDateMode) {
    'today' => $todayDate,
    'tomorrow' => $tomorrowDate,
    'day_after_tomorrow' => $dayAfterTomorrowDate,
    default => $customDate,
};
$todayForStatus = today();
$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);

$selectedInstallmentId = (int) ($_GET['selected_installment'] ?? 0);
$selectedCollectionId = (int) ($_GET['selected_collection'] ?? 0);
$pendingInstallments = collection_due_installments_for_date($pdo, $selectedDate, $todayDate, $search, $currentRole, $currentUserId);
$collectedInstallments = $selectedCollectionStatus === 'collected'
    ? collection_collected_installments_for_date($pdo, $selectedDate, $search, $currentRole, $currentUserId)
    : [];
$displayInstallments = $selectedCollectionStatus === 'collected' ? $collectedInstallments : $pendingInstallments;

if (is_collector_role($currentRole)) {
    $selectedCollectionTotalStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(col.amount), 0)
         FROM collections col
         JOIN loans l ON l.id = col.loan_id
         WHERE col.collected_on = :selected_date
           AND l.assigned_user_id = :assigned_user_id"
    );
    $selectedCollectionTotalStmt->execute([
        'selected_date' => $selectedDate,
        'assigned_user_id' => $currentUserId,
    ]);
} else {
    $selectedCollectionTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE collected_on = :selected_date');
    $selectedCollectionTotalStmt->execute(['selected_date' => $selectedDate]);
}
$selectedCollectionTotal = (float) $selectedCollectionTotalStmt->fetchColumn();

ob_start();
?>
<div class="metric-box">
    <p>Collected Total (Selected Date)</p>
    <h3><?= e(money_label($pdo, $selectedCollectionTotal)) ?></h3>
</div>
<div class="metric-box">
    <p><?= $selectedCollectionStatus === 'collected' ? 'Collected Count (Selected Date)' : 'Pending Count (Selected Date)' ?></p>
    <h3><?= e((string) count($displayInstallments)) ?></h3>
</div>
<?php
$summaryHtml = ob_get_clean();

ob_start();
if (!$displayInstallments):
?>
<tr><td colspan="7"><?= $selectedCollectionStatus === 'collected' ? 'No collected installments for selected date.' : 'No due installments for selected date.' ?></td></tr>
<?php
else:
    foreach ($displayInstallments as $item):
        $balance = round((float) $item['due_amount'] - (float) $item['paid_amount'], 2);
        $displayStatus = $selectedCollectionStatus === 'collected' ? 'paid' : $item['status'];
        if ($displayStatus !== 'paid' && $item['due_date'] < $todayForStatus) {
            $displayStatus = 'overdue';
        }
        $displayStatusLabel = installment_status_label($displayStatus, (string) $item['due_date'], $todayForStatus);
        $displayAmount = $selectedCollectionStatus === 'collected'
            ? (float) ($item['collected_amount'] ?? 0)
            : $balance;

        $itemId = (int) $item['id'];
        $itemCollectionId = (int) ($item['collection_id'] ?? 0);
        $selectParams = [
            'date_mode' => $selectedDateMode,
            'date' => $selectedDate,
            'collection_status' => $selectedCollectionStatus,
            'q' => $search,
        ];
        if ($selectedCollectionStatus === 'collected') {
            $selectParams['selected_collection'] = $itemCollectionId;
        } else {
            $selectParams['selected_installment'] = $itemId;
        }
        $rowSelectUrl = url('pages/today_collections.php?' . http_build_query($selectParams));
?>
<tr class="table-row-clickable <?= (($selectedCollectionStatus === 'pending' && $itemId === $selectedInstallmentId) || ($selectedCollectionStatus === 'collected' && $itemCollectionId === $selectedCollectionId)) ? 'row-selected' : '' ?>" data-select-url="<?= e($rowSelectUrl) ?>">
    <td><?= e($item['loan_number']) ?></td>
    <td><?= e($item['full_name']) ?></td>
    <td><?= e($item['phone']) ?></td>
    <td>#<?= e((string) $item['installment_no']) ?></td>
    <td><?= e(display_date((string) $item['due_date'])) ?></td>
    <td><?= e(money_label($pdo, $displayAmount)) ?></td>
    <td><span class="badge badge-<?= e(status_badge_class($displayStatus)) ?>"><?= e($displayStatusLabel) ?></span></td>
</tr>
<?php
    endforeach;
endif;
$rowsHtml = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'updated_at' => date('H:i:s'),
    'targets' => [
        '#collection-summary-metrics' => $summaryHtml,
        '#collection-due-table-body' => $rowsHtml,
    ],
], JSON_UNESCAPED_UNICODE);
