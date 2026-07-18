<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
require_csrf('index.php');

$current = current_user();
if ($current) {
    log_activity($pdo, 'auth.logout', 'User logged out.', [
        'username' => (string) ($current['username'] ?? ''),
        'role' => role_display_name((string) ($current['role'] ?? '')),
    ], (int) $current['id']);
}

remember_forget_current($pdo);
logout_user();

header('Location: ' . url('login.php'));
exit;
