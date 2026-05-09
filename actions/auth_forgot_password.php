<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('forgot_password.php');
}
require_csrf('forgot_password.php');

$email = trim((string) ($_POST['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please enter a valid email.');
    redirect('forgot_password.php');
}

$clientIp = client_ip_address();
$ipRate = rate_limit_consume('forgot_password_ip', $clientIp !== '' ? $clientIp : 'unknown', 8, 900);
$emailRate = rate_limit_consume('forgot_password_email', strtolower($email), 5, 900);

if (!$ipRate['allowed'] || !$emailRate['allowed']) {
    $retryAfter = max((int) $ipRate['retry_after'], (int) $emailRate['retry_after']);
    log_activity($pdo, 'auth.password_reset_throttled', 'Password reset request throttled.', [
        'email' => $email,
        'retry_after_seconds' => $retryAfter,
    ]);
    // Keep the same generic success response to avoid account enumeration.
    set_flash('success', 'If the email exists, a password reset link has been sent.');
    redirect('forgot_password.php');
}

$stmt = $pdo->prepare('SELECT id, full_name, username, email, status FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

$linkSent = false;
if ($user && (string) ($user['status'] ?? 'active') === 'active') {
    $token = create_password_reset_token($pdo, (int) $user['id']);
    if ($token !== null) {
        $resetLink = absolute_url('reset_password.php?token=' . urlencode($token));
        $linkSent = send_password_reset_email(
            $pdo,
            (string) $user['email'],
            (string) $user['full_name'],
            $resetLink
        );
    }
}

log_activity($pdo, 'auth.password_reset_requested', 'Password reset requested.', [
    'email' => $email,
    'user_found' => $user ? 1 : 0,
    'mail_sent' => $linkSent ? 1 : 0,
]);

set_flash('success', 'If the email exists, a password reset link has been sent.');
redirect('forgot_password.php');
