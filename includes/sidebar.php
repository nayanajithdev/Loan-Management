<?php
$currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$businessName = system_setting($pdo, 'business_name', 'Loan Manager');
$businessIconPath = business_icon_path($pdo);
$businessInitial = strtoupper(substr(preg_replace('/\s+/', '', $businessName), 0, 1));
if ($businessInitial === '') {
    $businessInitial = 'L';
}
$iconSvgs = [
    'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
    'customers' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/></svg>',
    'loans' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 15h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 17"/><path d="m7 21 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 16 6 6"/><circle cx="16" cy="9" r="2.9"/><circle cx="6" cy="5" r="3"/></svg>',
    'calculator' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="16" x2="16" y1="14" y2="18"/><path d="M16 10h.01"/><path d="M12 10h.01"/><path d="M8 10h.01"/><path d="M12 14h.01"/><path d="M8 14h.01"/><path d="M12 18h.01"/><path d="M8 18h.01"/></svg>',
    'today_collections' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>',
    'collections' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 22h2a2 2 0 0 0 2-2V8a2.4 2.4 0 0 0-.706-1.706l-3.588-3.588A2.4 2.4 0 0 0 14 2H6a2 2 0 0 0-2 2v2.85"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M8 14v2.2l1.6 1"/><circle cx="8" cy="16" r="6"/></svg>',
    'reports' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>',
    'backup' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>',
    'activity_logs' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h1"/><path d="M3 12h1"/><path d="M3 19h1"/><path d="M8 5h1"/><path d="M8 12h1"/><path d="M8 19h1"/><path d="M13 5h8"/><path d="M13 12h8"/><path d="M13 19h8"/></svg>',
    'settings' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/></svg>',
    'system_settings' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"/></svg>',
    'users' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m14.305 19.53.923-.382"/><path d="m15.228 16.852-.923-.383"/><path d="m16.852 15.228-.383-.923"/><path d="m16.852 20.772-.383.924"/><path d="m19.148 15.228.383-.923"/><path d="m19.53 21.696-.382-.924"/><path d="M2 21a8 8 0 0 1 10.434-7.62"/><path d="m20.772 16.852.924-.383"/><path d="m20.772 19.148.924.383"/><circle cx="10" cy="8" r="5"/><circle cx="18" cy="18" r="3"/></svg>',
];

$menuItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'index.php'],
    ['key' => 'today_collections', 'label' => 'Today Collection', 'path' => 'pages/today_collections.php'],
    ['key' => 'collections', 'label' => 'Collection History', 'path' => 'pages/collections.php'],
    ['key' => 'customers', 'label' => 'Customers', 'path' => 'pages/customers.php'],
];

if (can_manage_loans()) {
    $menuItems[] = ['key' => 'loans', 'label' => 'Loans', 'path' => 'pages/loans.php'];
    $menuItems[] = ['key' => 'calculator', 'label' => 'Calculator', 'path' => 'pages/calculator.php'];
}

if (can_manage_users()) {
    $menuItems[] = ['key' => 'users', 'label' => 'Users', 'path' => 'pages/users.php'];
    $menuItems[] = ['key' => 'backup', 'label' => 'Backup', 'path' => 'pages/backup.php'];
}

if (has_role(['superadmin'])) {
    $menuItems[] = ['key' => 'activity_logs', 'label' => 'Activity Logs', 'path' => 'pages/activity_logs.php'];
}

if (has_role(['superadmin', 'admin'])) {
    $menuItems[] = ['key' => 'reports', 'label' => 'Reports', 'path' => 'pages/reports.php'];
    $menuItems[] = ['key' => 'settings', 'label' => 'Business Settings', 'path' => 'pages/settings.php'];
    $menuItems[] = ['key' => 'system_settings', 'label' => 'System Settings', 'path' => 'pages/system_settings.php'];
}
?>

<aside class="sidebar">
    <div class="brand-card">
        <div class="brand-avatar">
            <?php if ($businessIconPath !== ''): ?>
                <img src="<?= e(url($businessIconPath)) ?>" alt="Business icon">
            <?php else: ?>
                <?= e($businessInitial) ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="brand-sub">Business Profile</div>
            <div class="brand-name"><?= e($businessName) ?></div>
        </div>
    </div>

    <div class="menu-group">
        <p class="menu-title">Main Menu</p>
        <nav class="menu-list">
            <?php foreach ($menuItems as $item): ?>
                <?php if ($item['key'] === 'loans' || $item['key'] === 'customers'): ?>
                    <?php
                    $createPath = $item['key'] === 'loans' ? 'pages/loan_create.php' : 'pages/customer_create.php';
                    $createScript = $item['key'] === 'loans' ? 'loan_create.php' : 'customer_create.php';
                    ?>
                    <div class="menu-item-row <?= $activePage === $item['key'] ? 'active' : '' ?>">
                        <a class="menu-item menu-item-main <?= $activePage === $item['key'] ? 'active' : '' ?>" href="<?= e(url($item['path'])) ?>">
                            <span class="menu-icon"><?= $iconSvgs[$item['key']] ?? '' ?></span>
                            <span><?= e($item['label']) ?></span>
                        </a>
                        <a class="menu-item-add <?= $currentScript === $createScript ? 'active' : '' ?>" href="<?= e(url($createPath)) ?>" title="New <?= e(rtrim($item['label'], 's')) ?>" aria-label="New <?= e(rtrim($item['label'], 's')) ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-plus-icon lucide-circle-plus">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M8 12h8"/>
                                <path d="M12 8v8"/>
                            </svg>
                        </a>
                    </div>
                <?php else: ?>
                    <a class="menu-item <?= $activePage === $item['key'] ? 'active' : '' ?>" href="<?= e(url($item['path'])) ?>">
                        <span class="menu-icon"><?= $iconSvgs[$item['key']] ?? '' ?></span>
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="sidebar-footer">
        <p>Multi-user edition</p>
        <small>Roles: Owner, Manager, Collector L1, Collector L2.</small>
    </div>
</aside>
