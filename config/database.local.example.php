<?php

declare(strict_types=1);

return [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'loan_management',
    'user' => 'root',
    'pass' => '',
    // Optional: login lockout tuning (main app login).
    'auth_login_max_attempts' => '5',
    'auth_login_window_seconds' => '900',
    'auth_login_lock_seconds' => '900',
    'update_panel_url' => 'https://loansystem.example.com/api/update-check.php',
    'update_panel_cache_seconds' => '600',
];
