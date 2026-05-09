<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Update Availble';
$activePage = 'updates';
$updateNotice = current_update_notice();

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel about-page">
    <div class="panel-head">
        <h3 class="panel-title">System Update</h3>
    </div>

    <?php if ($updateNotice === null): ?>
        <p>No active update notice for this system version.</p>
    <?php else: ?>
        <h4>Update Title</h4>
        <p>
            <?= e((string) ($updateNotice['title'] ?? 'Update Availble')) ?>
            <?php $noticeVersion = trim((string) ($updateNotice['version'] ?? '')); ?>
            <?php if ($noticeVersion !== ''): ?>
                v<?= e($noticeVersion) ?>
            <?php endif; ?>
        </p>

        <h4>What Are The Changes</h4>
        <?php $changes = trim((string) ($updateNotice['changes'] ?? '')); ?>
        <?php if ($changes === ''): ?>
            <p>No changes listed.</p>
        <?php else: ?>
            <div class="about-note"><?= nl2br(e($changes)) ?></div>
        <?php endif; ?>

        <h4>Message</h4>
        <p><?= nl2br(e((string) ($updateNotice['message'] ?? ''))) ?></p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
