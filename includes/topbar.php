<?php
$authUser = current_user();
$topbarBusinessName = system_setting($pdo, 'business_name', 'Loan Manager');
?>
<header class="topbar">
    <div class="topbar-left">
        <div>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
        </div>
    </div>

    <div class="topbar-right">
        <div class="connection-chip" id="js-connection-chip" role="status" aria-live="polite">
            <span class="connection-dot" id="js-connection-dot" aria-hidden="true"></span>
            <span id="js-connection-text">Checking...</span>
        </div>
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
                    <form method="post" action="<?= e(url('actions/auth_logout.php')) ?>" class="user-menu-logout-form">
                        <?= csrf_input() ?>
                        <button type="submit" class="user-menu-logout">Logout</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <div class="date-chip"><?= e(display_date(today(), today())) ?></div>
        <?php if ($authUser): ?>
            <?php
            $themePreference = normalize_theme_preference((string) ($authUser['theme_preference'] ?? 'dark'));
            $nextThemePreference = $themePreference === 'light' ? 'dark' : 'light';
            ?>
            <form method="post" action="<?= e(url('actions/theme_update.php')) ?>" class="theme-toggle-form" data-theme-toggle-form>
                <?= csrf_input() ?>
                <input type="hidden" name="theme" value="<?= e($nextThemePreference) ?>" data-theme-toggle-input>
                <button
                    type="submit"
                    class="theme-toggle-btn"
                    data-theme-toggle
                    data-current-theme="<?= e($themePreference) ?>"
                    aria-label="<?= e($themePreference === 'light' ? 'Switch to dark mode' : 'Switch to light mode') ?>"
                    title="<?= e($themePreference === 'light' ? 'Dark mode' : 'Light mode') ?>"
                >
                    <span class="theme-toggle-icon theme-toggle-icon-sun" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                    </span>
                    <span class="theme-toggle-icon theme-toggle-icon-moon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/></svg>
                    </span>
                </button>
            </form>
        <?php endif; ?>
        <button
            type="button"
            class="sidebar-toggle-btn"
            data-sidebar-toggle
            aria-label="Open menu"
            aria-expanded="false"
            aria-controls="main-sidebar"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 5h16"/>
                <path d="M4 12h16"/>
                <path d="M4 19h16"/>
            </svg>
        </button>
    </div>
</header>
