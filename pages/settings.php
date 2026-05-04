<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Settings';
$activePage = 'settings';

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Settings (Phase 2)</h2>
    </div>
    <p>Future items: business profile, default interest templates, backup and restore.</p>
    <?php if (can_manage_users()): ?>
        <p>User and role management is available in <a href="<?= e(url('pages/users.php')) ?>">Users</a>.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
