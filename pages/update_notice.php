<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Update Available';
$activePage = 'updates';
$updateNotice = current_update_notice();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel about-page about-page-narrow">
    <div class="panel-head">
        <h3 class="panel-title">System Update</h3>
    </div>

    <?php if ($updateNotice === null): ?>
        <p>No active update notice for this system version.</p>
    <?php else: ?>
        <h4><?= e((string) ($updateNotice['title'] ?? 'Update Available')) ?></h4>
        <?php $noticeVersion = trim((string) ($updateNotice['version'] ?? '')); ?>
        <?php if ($noticeVersion !== ''): ?>
            <p>Version <?= e($noticeVersion) ?></p>
        <?php endif; ?>

        <h4>What's New</h4>
        <?php $changes = trim((string) ($updateNotice['changes'] ?? '')); ?>
        <?php if ($changes === ''): ?>
            <p>No changes listed.</p>
        <?php else: ?>
            <div class="about-note"><?= nl2br(e($changes)) ?></div>
        <?php endif; ?>

        <p><?= nl2br(e((string) ($updateNotice['message'] ?? ''))) ?></p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
