<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_permission('dashboard.view', 'pages/about.php');

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$viewer = current_user();
$viewerRole = (string) ($viewer['role'] ?? '');
$viewerId = (int) ($viewer['id'] ?? 0);
$isCollectorScope = is_collector_role($viewerRole) && $viewerId > 0;
$chartMode = (string) ($_GET['chart'] ?? 'monthly');
$chartMode = $chartMode === 'weekly' ? 'weekly' : 'monthly';
$stats = dashboard_stats($pdo, $viewer);
$todayGoal = today_collection_goal($pdo, $viewer);
$todayCollectedTotal = today_collected_total($pdo, $viewer);
$collectionsTrend = collections_total_chart($pdo, $viewer, $chartMode);
$userGoals = dashboard_user_goals($pdo, $viewer);
$closedProfitValue = (float) $stats['closed_loans_profit'];
$openProjectedProfitValue = (float) $stats['expected_open_profit'];
$profitTotal = $closedProfitValue + $openProjectedProfitValue;
$closedProfitPct = $profitTotal > 0 ? ($closedProfitValue / $profitTotal) * 100 : 0;
$openProjectedPct = $profitTotal > 0 ? ($openProjectedProfitValue / $profitTotal) * 100 : 0;
$isTodayTargetCompleted = (float) $todayGoal['target'] > 0 && $todayCollectedTotal >= (float) $todayGoal['target'];
$todayGoalMetaText = 'Target: ' . money_label($pdo, (float) $todayGoal['target']);

if ($isCollectorScope) {
    $recentStmt = $pdo->prepare(
        'SELECT c.collected_on, c.amount, c.method, l.loan_number, cu.full_name
         FROM collections c
         JOIN loans l ON l.id = c.loan_id
         JOIN customers cu ON cu.id = l.customer_id
         WHERE l.assigned_user_id = :viewer_user_id
         ORDER BY c.id DESC
         LIMIT 8'
    );
    $recentStmt->execute(['viewer_user_id' => $viewerId]);
    $recentCollections = $recentStmt->fetchAll();
} else {
    $recentCollections = $pdo->query(
        'SELECT c.collected_on, c.amount, c.method, l.loan_number, cu.full_name
         FROM collections c
         JOIN loans l ON l.id = c.loan_id
         JOIN customers cu ON cu.id = l.customer_id
         ORDER BY c.id DESC
         LIMIT 8'
    )->fetchAll();
}

require __DIR__ . '/includes/layout_start.php';
?>

<div class="dashboard-headline">
    <p class="live-indicator" id="js-last-updated">Last update: waiting...</p>
</div>

<section class="card-grid dashboard-stat-grid" id="dashboard-stat-cards">
    <article class="stat-card goal-mini-card card-clickable" id="dashboard-goal-card" data-select-url="<?= e(url('pages/today_collections.php')) ?>">
        <p class="stat-label">Today's Collections</p>
        <p class="goal-mini-collected"><?= e(money_label($pdo, $todayCollectedTotal)) ?></p>
        <p class="goal-mini-target <?= $isTodayTargetCompleted ? 'goal-mini-target-success' : '' ?>">
            <?= e($todayGoalMetaText) ?>
        </p>
        <div class="goal-progress">
            <span style="width: <?= e((string) $todayGoal['percentage']) ?>%"></span>
        </div>
    </article>

    <article class="stat-card dashboard-card-due">
        <p class="stat-label">Due Today (Pending)</p>
        <p class="stat-value"><?= e(money_label($pdo, (float) $stats['today_pending_amount'])) ?></p>
        <p class="trend-meta"><?= e((string) $stats['today_pending_count']) ?> installments pending</p>
        <p class="trend-meta <?= (int) $stats['overdue_count'] > 0 ? 'trend-danger' : '' ?>"><?= e((string) $stats['overdue_count']) ?> overdue installments</p>
    </article>

    <?php if (!$isCollectorScope): ?>
        <article class="stat-card dashboard-card-outstanding">
            <p class="stat-label">Total Outstanding</p>
            <p class="stat-value"><?= e(money_label($pdo, (float) $stats['outstanding_principal'])) ?></p>
            <p class="trend-meta"><?= e((string) $stats['active_loans']) ?> active loans</p>
        </article>
    <?php endif; ?>

    <?php if (!$isCollectorScope): ?>
        <article class="stat-card dashboard-card-profit">
            <p class="stat-label">Profit (Closed Loans)</p>
            <p class="stat-value"><?= e(money_label($pdo, $closedProfitValue)) ?></p>
            <p class="trend-meta"><?= e(money_label($pdo, $openProjectedProfitValue)) ?> projected from open loans</p>
            <div class="dual-progress" aria-hidden="true">
                <span class="dual-progress-closed" style="width: <?= e(number_format($closedProfitPct, 2, '.', '')) ?>%"></span>
                <span class="dual-progress-open" style="width: <?= e(number_format($openProjectedPct, 2, '.', '')) ?>%"></span>
            </div>
        </article>
    <?php endif; ?>
</section>

<section class="dashboard-two-col">
    <article class="panel dashboard-trend-panel" id="dashboard-trend-panel">
        <?= dashboard_collection_chart_html($pdo, $collectionsTrend, $chartMode) ?>
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
                                <span class="badge <?= e($roleBadgeClass) ?>"><?= e((string) ($user['role_label'] ?? $user['role'])) ?></span>
                            </div>
                            <div class="user-goal-money"><?= e(money_label($pdo, (float) $user['collected'])) ?></div>
                        </div>
                        <div class="goal-progress user-goal-progress">
                            <span style="width: <?= e((string) $user['percentage']) ?>%"></span>
                        </div>
                        <p class="user-goal-target">Target: <?= e(money_label($pdo, (float) $user['target'])) ?></p>
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
        <table class="zebra-table dashboard-recent-table">
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
                        <td><?= e(display_date((string) $item['collected_on'])) ?></td>
                        <td><?= e($item['loan_number']) ?></td>
                        <td><?= e($item['full_name']) ?></td>
                        <td><?= e($item['method']) ?></td>
                        <td class="text-right"><?= e(money_label($pdo, (float) $item['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="poll-config"
     data-poll-endpoint="<?= e(url('api/dashboard_poll.php')) ?>"
     data-poll-include-query="1"
     data-poll-interval="<?= e((string) poll_interval_ms($pdo)) ?>"></div>

<?php require __DIR__ . '/includes/layout_end.php';
