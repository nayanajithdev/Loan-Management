<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin', 'collector_l1', 'collector_l2', 'collector']);

refresh_overdue_installments($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$selectedDateMode = trim((string) ($_GET['date_mode'] ?? ''));
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

$selectedDate = match ($selectedDateMode) {
    'today' => $todayDate,
    'tomorrow' => $tomorrowDate,
    'day_after_tomorrow' => $dayAfterTomorrowDate,
    default => $customDate,
};
$todayForStatus = today();
$dueDateOperator = $selectedDate > $todayDate ? '=' : '<=';
$current = current_user();
$currentRole = (string) ($current['role'] ?? '');
$currentUserId = (int) ($current['id'] ?? 0);

$selectedInstallmentId = (int) ($_GET['selected_installment'] ?? 0);

$sql = "SELECT
            li.id,
            li.loan_id,
            li.installment_no,
            li.due_date,
            li.due_amount,
            li.paid_amount,
            li.status,
            l.loan_number,
            l.installment_frequency,
            c.full_name,
            c.phone
        FROM loan_installments li
        JOIN loans l ON l.id = li.loan_id
        JOIN customers c ON c.id = l.customer_id
        WHERE li.due_date {$dueDateOperator} :selected_date
          AND li.status IN ('pending', 'partial', 'overdue')";

$params = ['selected_date' => $selectedDate];
if (is_collector_role($currentRole)) {
    $sql .= ' AND (l.assigned_user_id = :assigned_user_id OR l.assigned_user_id IS NULL)';
    $params['assigned_user_id'] = $currentUserId;
}
if ($search !== '') {
    $sql .= " AND (l.loan_number LIKE :q_loan OR c.full_name LIKE :q_name OR c.phone LIKE :q_phone)";
    $searchLike = '%' . $search . '%';
    $params['q_loan'] = $searchLike;
    $params['q_name'] = $searchLike;
    $params['q_phone'] = $searchLike;
}

$sql .= ' ORDER BY li.due_date ASC, c.full_name ASC, li.installment_no ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dueInstallments = $stmt->fetchAll();

if (is_collector_role($currentRole)) {
    $selectedCollectionTotalStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(col.amount), 0)
         FROM collections col
         JOIN loans l ON l.id = col.loan_id
         WHERE col.collected_on = :selected_date
           AND (l.assigned_user_id = :assigned_user_id OR l.assigned_user_id IS NULL)"
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
    <p>Pending Count (Selected Date)</p>
    <h3><?= e((string) count($dueInstallments)) ?></h3>
</div>
<?php
$summaryHtml = ob_get_clean();

ob_start();
if (!$dueInstallments):
?>
<tr><td colspan="7">No due installments for selected date.</td></tr>
<?php
else:
    foreach ($dueInstallments as $item):
        $displayStatus = $item['status'];
        if ($item['status'] !== 'paid' && $item['due_date'] < $todayForStatus) {
            $displayStatus = 'overdue';
        }

        $selectUrl = url('pages/today_collections.php?' . http_build_query([
            'date_mode' => $selectedDateMode,
            'date' => $selectedDate,
            'q' => $search,
            'selected_installment' => (int) $item['id'],
        ]));
?>
<tr class="table-row-clickable <?= (int) $item['id'] === $selectedInstallmentId ? 'row-selected' : '' ?>" data-select-url="<?= e($selectUrl) ?>">
    <td><?= e($item['loan_number']) ?></td>
    <td><?= e($item['full_name']) ?></td>
    <td><?= e($item['phone']) ?></td>
    <td>#<?= e((string) $item['installment_no']) ?></td>
    <td><?= e(display_date((string) $item['due_date'])) ?></td>
    <td><?= e(money_label($pdo, (float) $item['due_amount'])) ?></td>
    <td><span class="badge badge-<?= e(status_badge_class($displayStatus)) ?>"><?= e($displayStatus) ?></span></td>
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
