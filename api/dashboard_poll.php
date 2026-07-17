<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('dashboard.view');

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
$dailyProfitValue = (float) $stats['daily_profit'];
$dailyCollectedValue = (float) $stats['daily_collected_amount'];
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

ob_start();
?>
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
        <p class="stat-label">Daily Profit</p>
        <p class="stat-value"><?= e(money_label($pdo, $dailyProfitValue)) ?></p>
        <p class="trend-meta"><?= e(money_label($pdo, $dailyCollectedValue)) ?> collected today</p>
    </article>
<?php endif; ?>
<?php
$cardsHtml = ob_get_clean();

ob_start();
?>
<?= dashboard_collection_chart_html($pdo, $collectionsTrend, $chartMode) ?>
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
    <td><?= e(display_date((string) $item['collected_on'])) ?></td>
    <td><?= e($item['loan_number']) ?></td>
    <td><?= e($item['full_name']) ?></td>
    <td><?= e($item['method']) ?></td>
    <td class="text-right"><?= e(money_label($pdo, (float) $item['amount'])) ?></td>
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
