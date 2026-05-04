<?php
$currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$iconSvgs = [
    'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
    'customers' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/></svg>',
    'loans' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 15h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 17"/><path d="m7 21 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 16 6 6"/><circle cx="16" cy="9" r="2.9"/><circle cx="6" cy="5" r="3"/></svg>',
    'today_collections' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>',
    'collections' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 22h2a2 2 0 0 0 2-2V8a2.4 2.4 0 0 0-.706-1.706l-3.588-3.588A2.4 2.4 0 0 0 14 2H6a2 2 0 0 0-2 2v2.85"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M8 14v2.2l1.6 1"/><circle cx="8" cy="16" r="6"/></svg>',
    'reports' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>',
    'settings' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/></svg>',
    'users' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m14.305 19.53.923-.382"/><path d="m15.228 16.852-.923-.383"/><path d="m16.852 15.228-.383-.923"/><path d="m16.852 20.772-.383.924"/><path d="m19.148 15.228.383-.923"/><path d="m19.53 21.696-.382-.924"/><path d="M2 21a8 8 0 0 1 10.434-7.62"/><path d="m20.772 16.852.924-.383"/><path d="m20.772 19.148.924.383"/><circle cx="10" cy="8" r="5"/><circle cx="18" cy="18" r="3"/></svg>',
];

$menuItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'index.php'],
    ['key' => 'today_collections', 'label' => 'Collection', 'path' => 'pages/today_collections.php'],
    ['key' => 'collections', 'label' => 'Collection History', 'path' => 'pages/collections.php'],
    ['key' => 'customers', 'label' => 'Customers', 'path' => 'pages/customers.php'],
    ['key' => 'loans', 'label' => 'Loans', 'path' => 'pages/loans.php'],
];

if (can_manage_users()) {
    $menuItems[] = ['key' => 'users', 'label' => 'Users', 'path' => 'pages/users.php'];
}

$menuItems[] = ['key' => 'reports', 'label' => 'Reports', 'path' => 'pages/reports.php'];
$menuItems[] = ['key' => 'settings', 'label' => 'Settings', 'path' => 'pages/settings.php'];
?>

<aside class="sidebar">
    <div class="brand-card">
        <div class="brand-avatar">L</div>
        <div>
            <div class="brand-sub">Business</div>
            <div class="brand-name">Loan Manager</div>
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
        <small>Roles: Superadmin, Admin, Collector.</small>
    </div>
</aside>
