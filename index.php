<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$stats = dashboard_stats($pdo);
$todayGoal = today_collection_goal($pdo);
$collectionsTrend = collections_30day_trend($pdo);
$userGoals = dashboard_user_goals($pdo);
$chartWidth = 920;
$chartHeight = 280;
$targetPoints = sparkline_points_scaled($collectionsTrend['target'], 0, (float) $collectionsTrend['max_value'], $chartWidth, $chartHeight, 12);
$collectedPoints = sparkline_points_scaled($collectionsTrend['collected'], 0, (float) $collectionsTrend['max_value'], $chartWidth, $chartHeight, 12);
$closedProfitValue = (float) $stats['closed_loans_profit'];
$openProjectedProfitValue = (float) $stats['expected_open_profit'];
$profitTotal = $closedProfitValue + $openProjectedProfitValue;
$closedProfitPct = $profitTotal > 0 ? ($closedProfitValue / $profitTotal) * 100 : 0;
$openProjectedPct = $profitTotal > 0 ? ($openProjectedProfitValue / $profitTotal) * 100 : 0;

$recentCollections = $pdo->query(
    'SELECT c.collected_on, c.amount, c.method, l.loan_number, cu.full_name
     FROM collections c
     JOIN loans l ON l.id = c.loan_id
     JOIN customers cu ON cu.id = l.customer_id
     ORDER BY c.id DESC
     LIMIT 8'
)->fetchAll();

require __DIR__ . '/includes/layout_start.php';
?>

<div class="dashboard-headline">
    <p class="live-indicator" id="js-last-updated">Last update: waiting...</p>
</div>

<section class="card-grid dashboard-stat-grid" id="dashboard-stat-cards">
    <article class="stat-card goal-mini-card" id="dashboard-goal-card">
        <p class="stat-label">Today's Collections</p>
        <p class="goal-mini-collected">LKR <?= e(money($todayGoal['collected'])) ?></p>
        <p class="goal-mini-target">Target: LKR <?= e(money($todayGoal['target'])) ?></p>
        <div class="goal-progress">
            <span style="width: <?= e((string) $todayGoal['percentage']) ?>%"></span>
        </div>
    </article>

    <article class="stat-card">
        <p class="stat-label">Due Today (Pending)</p>
        <p class="stat-value">LKR <?= e(money((float) $stats['today_pending_amount'])) ?></p>
        <p class="trend-meta"><?= e((string) $stats['today_pending_count']) ?> installments pending</p>
        <p class="trend-meta <?= (int) $stats['overdue_count'] > 0 ? 'trend-danger' : '' ?>"><?= e((string) $stats['overdue_count']) ?> overdue installments</p>
    </article>

    <article class="stat-card">
        <p class="stat-label">Total Outstanding</p>
        <p class="stat-value">LKR <?= e(money((float) $stats['outstanding_principal'])) ?></p>
        <p class="trend-meta"><?= e((string) $stats['active_loans']) ?> active loans</p>
    </article>

    <article class="stat-card">
        <p class="stat-label">Profit (Closed Loans)</p>
        <p class="stat-value">LKR <?= e(money($closedProfitValue)) ?></p>
        <p class="trend-meta">LKR <?= e(money($openProjectedProfitValue)) ?> projected from open loans</p>
        <div class="dual-progress" aria-hidden="true">
            <span class="dual-progress-closed" style="width: <?= e(number_format($closedProfitPct, 2, '.', '')) ?>%"></span>
            <span class="dual-progress-open" style="width: <?= e(number_format($openProjectedPct, 2, '.', '')) ?>%"></span>
        </div>
    </article>
</section>

<section class="dashboard-two-col">
    <article class="panel dashboard-trend-panel" id="dashboard-trend-panel">
        <div class="panel-head">
            <h2 class="panel-title">Collections vs Target (30 Days)</h2>
            <div class="chart-legend">
                <span><i class="legend-dot legend-dot-collected"></i>Collected</span>
                <span><i class="legend-dot legend-dot-target"></i>Target</span>
            </div>
        </div>
        <div class="chart-meta-row">
            <p>Collected: <strong>LKR <?= e(money((float) $collectionsTrend['collected_total'])) ?></strong></p>
            <p>Target: <strong>LKR <?= e(money((float) $collectionsTrend['target_total'])) ?></strong></p>
        </div>
        <div class="big-line-chart">
            <svg viewBox="0 0 <?= e((string) $chartWidth) ?> <?= e((string) $chartHeight) ?>" aria-hidden="true">
                <polyline class="line-target" points="<?= e($targetPoints) ?>"></polyline>
                <polyline class="line-collected" points="<?= e($collectedPoints) ?>"></polyline>
            </svg>
        </div>
    </article>

    <article class="panel user-goals-panel" id="dashboard-user-goals-panel">
        <div class="panel-head">
            <h2 class="panel-title">User Goals</h2>
        </div>
        <div class="user-goals-list">
            <?php if (empty($userGoals['users'])): ?>
                <p class="muted-block">No users available.</p>
            <?php else: ?>
                <?php foreach ($userGoals['users'] as $user): ?>
                    <?php
                    $roleBadgeClass = match ($user['role']) {
                        'superadmin' => 'badge-info',
                        'admin' => 'badge-warning',
                        default => 'badge-neutral',
                    };
                    ?>
                    <div class="user-goal-item">
                        <div class="user-goal-top">
                            <div>
                                <strong><?= e($user['full_name']) ?></strong>
                                <?php if (($user['role'] ?? '') !== 'unassigned'): ?>
                                    <span class="badge <?= e($roleBadgeClass) ?>"><?= e((string) ($user['role_label'] ?? $user['role'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="user-goal-money">LKR <?= e(money((float) $user['collected'])) ?></div>
                        </div>
                        <div class="goal-progress user-goal-progress">
                            <span style="width: <?= e((string) $user['percentage']) ?>%"></span>
                        </div>
                        <p class="user-goal-target">Target: LKR <?= e(money((float) $user['target'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Recent Collections</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Loan</th>
                <th>Customer</th>
                <th>Method</th>
                <th class="text-right">Amount</th>
            </tr>
            </thead>
            <tbody id="dashboard-recent-collections-body">
            <?php if (!$recentCollections): ?>
                <tr><td colspan="5">No collections yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentCollections as $item): ?>
                    <tr>
                        <td><?= e($item['collected_on']) ?></td>
                        <td><?= e($item['loan_number']) ?></td>
                        <td><?= e($item['full_name']) ?></td>
                        <td><?= e($item['method']) ?></td>
                        <td class="text-right">LKR <?= e(money((float) $item['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/dashboard_poll.php')) ?>"
     data-poll-interval="10000"></div>

<?php require __DIR__ . '/includes/layout_end.php';
