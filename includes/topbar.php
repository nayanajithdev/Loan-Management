<?php $authUser = current_user(); ?>
<header class="topbar">
    <div>
        <p class="breadcrumb">Loan System / <?= e(ucfirst($activePage)) ?></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
    </div>

    <div class="topbar-right">
        <input class="search" type="text" placeholder="Search..." disabled>
        <?php if ($authUser): ?>
            <?php
            $fullName = (string) $authUser['full_name'];
            $nameParts = preg_split('/\s+/', trim($fullName)) ?: [];
            $first = $nameParts[0] ?? '';
            $second = $nameParts[1] ?? '';
            $initials = strtoupper(substr($first, 0, 1) . substr($second, 0, 1));
            if ($initials === '') {
                $initials = strtoupper(substr((string) $authUser['username'], 0, 2));
            }
            $avatarPath = trim((string) ($authUser['avatar_path'] ?? ''));
            ?>
            <div class="topbar-user-menu" data-user-menu>
                <button type="button" class="user-menu-trigger" data-user-menu-toggle aria-expanded="false">
                    <span class="user-menu-avatar">
                        <?php if ($avatarPath !== ''): ?>
                            <img src="<?= e(url($avatarPath)) ?>" alt="Avatar">
                        <?php else: ?>
                            <?= e($initials) ?>
                        <?php endif; ?>
                    </span>
                    <span class="user-menu-meta">
                        <strong><?= e($authUser['full_name']) ?></strong>
                        <small><?= e(role_display_name((string) $authUser['role'])) ?></small>
                    </span>
                    <span class="user-menu-chevron" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </span>
                </button>
                <div class="user-menu-dropdown" data-user-menu-dropdown>
                    <a class="user-menu-link" href="<?= e(url('pages/profile.php')) ?>">Account</a>
                    <a class="user-menu-logout" href="<?= e(url('actions/auth_logout.php')) ?>">Logout</a>
                </div>
            </div>
        <?php endif; ?>
        <div class="date-chip"><?= e(date('d M Y')) ?></div>
    </div>
</header>
