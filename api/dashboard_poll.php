<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

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

ob_start();
?>
<article class="stat-card trend-card">
    <p class="stat-label">Total Customers</p>
    <div class="trend-main">
        <p class="stat-value"><?= e((string) $stats['customers']) ?></p>
        <svg class="trend-spark" viewBox="0 0 140 46" aria-hidden="true">
            <polyline points="<?= e(sparkline_points($customerTrend['values'], 140, 46, 4)) ?>"></polyline>
        </svg>
    </div>
    <p class="trend-meta <?= e($trendClass) ?>"><?= e($trendPrefix . number_format($customerTrend['delta_pct'], 1)) ?>% weekly</p>
</article>

<article class="stat-card">
    <p class="stat-label">Active Loans</p>
    <p class="stat-value"><?= e((string) $stats['active_loans']) ?></p>
</article>

<article class="stat-card trend-card trend-card-disbursed">
    <p class="stat-label">Total Disbursed</p>
    <p class="stat-value">LKR <?= e(money((float) $stats['total_disbursed'])) ?></p>
    <svg class="trend-spark trend-spark-under" viewBox="0 0 260 56" aria-hidden="true">
        <polyline points="<?= e(sparkline_points($disbursedTrend['values'], 260, 56, 4)) ?>"></polyline>
    </svg>
    <p class="trend-meta <?= e($disbursedTrendClass) ?>"><?= e($disbursedTrendPrefix . number_format($disbursedTrend['delta_pct'], 1)) ?>% weekly</p>
</article>

<article class="stat-card">
    <p class="stat-label">Total Collected</p>
    <p class="stat-value">LKR <?= e(money((float) $stats['total_collected'])) ?></p>
</article>

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
<?php
$cardsHtml = ob_get_clean();

ob_start();
?>
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
<?php
$trendPanelHtml = ob_get_clean();

ob_start();
?>
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
<?php
$userGoalsHtml = ob_get_clean();

ob_start();
if (!$recentCollections):
?>
<tr><td colspan="5">No collections yet.</td></tr>
<?php
else:
    foreach ($recentCollections as $item):
?>
<tr>
    <td><?= e($item['collected_on']) ?></td>
    <td><?= e($item['loan_number']) ?></td>
    <td><?= e($item['full_name']) ?></td>
    <td><?= e($item['method']) ?></td>
    <td class="text-right">LKR <?= e(money((float) $item['amount'])) ?></td>
</tr>
<?php
    endforeach;
endif;
$recentHtml = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'updated_at' => date('H:i:s'),
    'targets' => [
        '#dashboard-stat-cards' => $cardsHtml,
        '#dashboard-trend-panel' => $trendPanelHtml,
        '#dashboard-user-goals-panel' => $userGoalsHtml,
        '#dashboard-recent-collections-body' => $recentHtml,
    ],
], JSON_UNESCAPED_UNICODE);
