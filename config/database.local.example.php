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

    // Optional: password reset email settings.
    // Copy this file to database.local.php and replace these values with your SMTP account.
    'mail_driver' => 'smtp',
    'mail_host' => 'smtp.yourprovider.com',
    'mail_port' => '465',
    'mail_encryption' => 'ssl',
    'mail_username' => 'your_email',
    'mail_password' => 'your_app_password',
    'mail_from_email' => 'your_email',
    'mail_from_name' => 'LoanDesk',
];
