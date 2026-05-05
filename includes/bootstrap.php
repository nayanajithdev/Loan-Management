<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

$pdo = db();
ensure_user_schema($pdo);
ensure_user_profile_schema($pdo);
ensure_collection_user_schema($pdo);
ensure_collection_payment_ref_schema($pdo);
ensure_loan_assignment_schema($pdo);
ensure_loan_interest_rate_type_schema($pdo);
ensure_loan_interest_rate_months_schema($pdo);
ensure_customer_documents_schema($pdo);
ensure_customer_note_schema($pdo);
ensure_system_settings_schema($pdo);
ensure_activity_logs_schema($pdo);
$flash = get_flash();

$scriptBaseName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$publicScripts = [
    'login.php',
    'setup_superadmin.php',
    'auth_login.php',
    'auth_setup_superadmin.php',
];

if (!in_array($scriptBaseName, $publicScripts, true)) {
    if (!is_logged_in()) {
        if (!has_superadmin($pdo)) {
            redirect('setup_superadmin.php');
        }
        redirect('login.php');
    }

    $current = current_user();
    $refreshStmt = $pdo->prepare('SELECT id, full_name, username, role, avatar_path FROM users WHERE id = :id LIMIT 1');
    $refreshStmt->execute(['id' => (int) $current['id']]);
    $latestUser = $refreshStmt->fetch();

    if (!$latestUser) {
        logout_user();
        set_flash('error', 'Your account was removed. Please login again.');
        redirect('login.php');
    }

    $_SESSION['auth_user'] = [
        'id' => (int) $latestUser['id'],
        'full_name' => (string) $latestUser['full_name'],
        'username' => (string) $latestUser['username'],
        'role' => (string) $latestUser['role'],
        'avatar_path' => (string) ($latestUser['avatar_path'] ?? ''),
    ];
}
