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
$assignedUserId = max(0, (int) ($_GET['assigned_user_id'] ?? 0));
$assignedUsers = assignable_collector_rows($pdo, $assignedUserId > 0 ? $assignedUserId : null);

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
if ($assignedUserId > 0) {
    $sql .= ' AND l.assigned_user_id = :assigned_user_id';
    $params['assigned_user_id'] = $assignedUserId;
}

$sql .= ' ORDER BY l.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();
$canCreateLoan = can('loans.create');

$renderLoansBody = static function (array $loans, PDO $pdo): string {
    ob_start();
    if (!$loans): ?>
        <tr><td colspan="8">No loans yet.</td></tr>
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
        <div>
            <form id="loan-filter-form" class="loan-filter-form" method="get">
                <div class="field loan-status-field">
                    <label>Status</label>
                    <select name="status" id="loan-status-filter" class="loan-status-select is-<?= e($status) ?>">
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
                <input type="hidden" name="assigned_user_id" id="loan-assigned-filter" value="<?= e((string) $assignedUserId) ?>">
                <div class="field loan-search-field">
                    <label class="sr-only">Search loans</label>
                    <div class="search-control">
                        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search..." aria-label="Search by customer name or ID number">
                        <button type="submit" class="btn search-submit" aria-label="Search loans">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
                        </button>
                    </div>
                </div>
                <a class="btn loan-filter-reset" href="<?= e(url('pages/loans.php')) ?>">Reset</a>
            </form>
        </div>
        <?php if ($canCreateLoan): ?>
            <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
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
                <th>
                    <div class="table-header-filter" data-table-filter-menu>
                        <button type="button" class="table-header-filter-toggle <?= $assignedUserId > 0 ? 'is-active' : '' ?>" data-table-filter-toggle aria-expanded="false">
                            <span>Assigned To</span>
                            <span class="table-header-filter-chevron" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down-icon lucide-chevron-down"><path d="m6 9 6 6 6-6"/></svg>
                            </span>
                        </button>
                        <div class="table-header-filter-menu" data-table-filter-options hidden>
                            <button type="button" data-assigned-filter-value="0" class="<?= $assignedUserId === 0 ? 'is-selected' : '' ?>">All Assigned Users</button>
                            <?php foreach ($assignedUsers as $assignedUser): ?>
                                <?php
                                $userId = (int) ($assignedUser['id'] ?? 0);
                                $label = trim((string) ($assignedUser['full_name'] ?? ''));
                                if ($label === '') {
                                    $label = (string) ($assignedUser['username'] ?? ('User #' . $userId));
                                }
                                ?>
                                <button type="button" data-assigned-filter-value="<?= e((string) $userId) ?>" class="<?= $assignedUserId === $userId ? 'is-selected' : '' ?>"><?= e($label) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </th>
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
  const assigned = document.getElementById('loan-assigned-filter');
  const tbody = document.getElementById('loans-table-body');
  if (!form || !status || !assigned || !tbody) return;
  const filterMenus = Array.from(document.querySelectorAll('[data-table-filter-menu]'));

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

  status.addEventListener('change', () => {
    status.classList.toggle('is-active', status.value === 'active');
    status.classList.toggle('is-closed', status.value === 'closed');
    loadRows();
  });
  filterMenus.forEach((menu) => {
    const toggle = menu.querySelector('[data-table-filter-toggle]');
    const options = menu.querySelector('[data-table-filter-options]');
    if (!(toggle instanceof HTMLButtonElement) || !(options instanceof HTMLElement)) return;

    toggle.addEventListener('click', (event) => {
      event.stopPropagation();
      const willOpen = options.hidden;
      filterMenus.forEach((otherMenu) => {
        const otherOptions = otherMenu.querySelector('[data-table-filter-options]');
        const otherToggle = otherMenu.querySelector('[data-table-filter-toggle]');
        if (otherOptions instanceof HTMLElement) otherOptions.hidden = true;
        if (otherToggle instanceof HTMLElement) otherToggle.setAttribute('aria-expanded', 'false');
      });
      options.hidden = !willOpen;
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    options.querySelectorAll('[data-assigned-filter-value]').forEach((option) => {
      option.addEventListener('click', () => {
        const selectedValue = option.getAttribute('data-assigned-filter-value') || '0';
        assigned.value = selectedValue;
        options.querySelectorAll('[data-assigned-filter-value]').forEach((item) => {
          item.classList.toggle('is-selected', item === option);
        });
        toggle.classList.toggle('is-active', selectedValue !== '0');
        options.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        loadRows();
      });
    });
  });

  document.addEventListener('click', () => {
    filterMenus.forEach((menu) => {
      const options = menu.querySelector('[data-table-filter-options]');
      const toggle = menu.querySelector('[data-table-filter-toggle]');
      if (options instanceof HTMLElement) options.hidden = true;
      if (toggle instanceof HTMLElement) toggle.setAttribute('aria-expanded', 'false');
    });
  });
})();
</script>

<?php require __DIR__ . '/../includes/layout_end.php';
