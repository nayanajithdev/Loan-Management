<?php
defined('APP_NAME') || exit;
?>
<header class="print-report-header">
    <div class="print-report-logo-slot">
        <?php if ($businessIconPath !== ''): ?>
            <img src="<?= e(url($businessIconPath)) ?>" alt="">
        <?php else: ?>
            <span class="print-report-logo-placeholder">logo</span>
        <?php endif; ?>
    </div>
    <div class="print-report-title-block">
        <h1><?= e($businessName) ?></h1>
        <?php if ($businessAddress !== ''): ?>
            <p><?= e($businessAddress) ?></p>
        <?php endif; ?>
        <?php if ($businessPhone !== ''): ?>
            <p class="print-report-contact">
                <em>Tel:</em> <?= e($businessPhone) ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="print-report-note"><?= $businessNote !== '' ? e((string) preg_replace('/\s+/', ' ', $businessNote)) : '' ?></div>
</header>

<div class="print-report-rule"></div>
