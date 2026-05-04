<?php $authUser = current_user(); ?>
<header class="topbar">
    <div>
        <p class="breadcrumb">Loan System / <?= e(ucfirst($activePage)) ?></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
    </div>

    <div class="topbar-right">
        <input class="search" type="text" placeholder="Search..." disabled>
        <?php if ($authUser): ?>
            <div class="user-chip">
                <strong><?= e($authUser['full_name']) ?></strong>
                <small><?= e(ucfirst($authUser['role'])) ?></small>
            </div>
            <a class="btn" href="<?= e(url('actions/auth_logout.php')) ?>">Logout</a>
        <?php endif; ?>
        <div class="date-chip"><?= e(date('d M Y')) ?></div>
    </div>
</header>
