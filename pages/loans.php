<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('loans.view');

$pageTitle = 'Loans';
$activePage = 'loans';

$allowedStatuses = ['active', 'closed'];
$status = strtolower(trim((string) ($_GET['status'] ?? 'active')));
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'active';
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 120);

$sql = "SELECT l.*, c.full_name, l.assigned_user_id, u.full_name AS assigned_user_name, u.username AS assigned_username, u.role AS assigned_role,
            COALESCE((SELECT SUM(li.due_amount - li.paid_amount) FROM loan_installments li WHERE li.loan_id = l.id AND li.status IN ('pending', 'partial', 'overdue')), 0) AS outstanding_amount,
            COALESCE((SELECT COUNT(*) FROM loan_installments li WHERE li.loan_id = l.id AND li.status IN ('pending', 'partial', 'overdue')), 0) AS remaining_installment_count
        FROM loans l
        JOIN customers c ON c.id = l.customer_id
        LEFT JOIN users u ON u.id = l.assigned_user_id
        WHERE l.status = :status";

$params = ['status' => $status];
if ($search !== '') {
    $searchLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    $sql .= " AND (c.full_name LIKE :search_name ESCAPE '\\\\' OR c.nic LIKE :search_nic ESCAPE '\\\\')";
    $params['search_name'] = $searchLike;
    $params['search_nic'] = $searchLike;
}

$sql .= ' ORDER BY l.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();
$canCreateLoan = can('loans.create');

$renderLoansBody = static function (array $loans, PDO $pdo): string {
    ob_start();
    if (!$loans): ?>
        <tr><td colspan="9">No loans yet.</td></tr>
    <?php else: ?>
        <?php foreach ($loans as $loan): ?>
            <?php $balance = max(0, (float) $loan['outstanding_amount']); ?>
            <?php $collectedAmount = max(0, round((float) $loan['total_amount'] - $balance, 2)); ?>
            <?php $remainingInstallments = (int) ($loan['remaining_installment_count'] ?? 0); ?>
            <?php $selectUrl = url('pages/loan_edit.php?loan_id=' . (int) $loan['id']); ?>
            <tr class="table-row-clickable" data-select-url="<?= e($selectUrl) ?>">
                <td><?= e($loan['loan_number']) ?></td>
                <td><?= e($loan['full_name']) ?></td>
                <td><?= e(money_label($pdo, (float) $loan['principal_amount'])) ?></td>
                <td><?= e(money_label($pdo, (float) $loan['total_amount'])) ?></td>
                <td><?= e(money_label($pdo, $collectedAmount)) ?></td>
                <td><?= $balance <= 0 ? '---' : e(money_label($pdo, $balance)) ?></td>
                <td>
                    <?php if ($remainingInstallments <= 0): ?>
                        <span class="badge badge-success">Completed</span>
                    <?php else: ?>
                        <?= e((string) $remainingInstallments) ?> left (<?= e((string) $loan['installment_frequency']) ?>)
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($loan['assigned_user_name'])): ?>
                        <?= e($loan['assigned_user_name']) ?>
                    <?php else: ?>
                        <span class="badge badge-info">Owner</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= e(status_badge_class($loan['status'])) ?>"><?= e($loan['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    return (string) ob_get_clean();
};

$isAjax = (
    isset($_GET['loans_ajax']) &&
    $_GET['loans_ajax'] === '1' &&
    strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
);
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'targets' => [
            '#loans-table-body' => $renderLoansBody($loans, $pdo),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
            <form id="loan-filter-form" method="get" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                <div class="field" style="min-width:170px; margin:0;">
                    <label>Status</label>
                    <select name="status" id="loan-status-filter">
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
                <div class="field" style="min-width:260px; margin:0;">
                    <label>Search Customer</label>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Type customer name or ID no">
                </div>
                <button type="submit" class="btn btn-primary">Apply</button>
                <a class="btn" href="<?= e(url('pages/loans.php')) ?>">Reset</a>
            </form>
        </div>
        <?php if ($canCreateLoan): ?>
            <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                <a class="btn" href="<?= e(url('pages/loan_legacy_create.php')) ?>">
                    <span class="btn-icon-inline" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus-icon lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    </span>
                    Add Old Loan
                </a>
                <a class="btn btn-primary" href="<?= e(url('pages/loan_create.php')) ?>">New Loan</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="zebra-table loans-table">
            <thead>
            <tr>
                <th>Loan No</th>
                <th>Customer</th>
                <th>Principal</th>
                <th>Total</th>
                <th>Collected</th>
                <th>Balance</th>
                <th>Inst. Left</th>
                <th>Assigned To</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody id="loans-table-body">
            <?= $renderLoansBody($loans, $pdo) ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(() => {
  const form = document.getElementById('loan-filter-form');
  const status = document.getElementById('loan-status-filter');
  const tbody = document.getElementById('loans-table-body');
  if (!form || !status || !tbody) return;

  const loadRows = async () => {
    const params = new URLSearchParams(new FormData(form));
    params.set('loans_ajax', '1');
    const requestUrl = `${form.getAttribute('action') || window.location.pathname}?${params.toString()}`;

    try {
      const res = await fetch(requestUrl, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
      });
      if (!res.ok) return;
      const data = await res.json();
      if (data && data.targets && data.targets['#loans-table-body'] !== undefined) {
        tbody.innerHTML = String(data.targets['#loans-table-body']);
        if (typeof window.applyMobileTableStack === 'function') window.applyMobileTableStack();
      }
    } catch (_error) {
      // Keep current rows if request fails.
    }
  };

  status.addEventListener('change', loadRows);
})();
</script>

<?php require __DIR__ . '/../includes/layout_end.php';
