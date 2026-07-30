<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    exit;
}

$token = (string) ($_POST['_csrf'] ?? '');
if (!csrf_is_valid($token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid request token. Please try again.']);
    exit;
}

$current = current_user();
$currentUserId = (int) ($current['id'] ?? 0);
$password = (string) ($_POST['password'] ?? '');

if ($currentUserId <= 0 || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Password is required.']);
    exit;
}

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $currentUserId]);
$passwordHash = (string) ($stmt->fetchColumn() ?: '');

if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
    $failedAttempts = (int) ($_SESSION['loan_delete_password_failures'] ?? 0) + 1;
    $_SESSION['loan_delete_password_failures'] = $failedAttempts;

    if ($failedAttempts >= 3) {
        log_activity($pdo, 'loan.delete_failed', 'Loan delete password confirmation failed 3 times. User logged out.', [
            'reason' => 'password_confirmation_failed_limit',
        ], $currentUserId);
        force_logout_user_everywhere($pdo, $currentUserId);
        $loginUrl = url('login.php');
        logout_user();

        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'logged_out' => true,
            'redirect' => $loginUrl,
            'message' => 'Too many incorrect password attempts. Please login again.',
        ]);
        exit;
    }

    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'attempts' => $failedAttempts,
        'remaining_attempts' => max(0, 3 - $failedAttempts),
        'message' => 'Incorrect password. ' . max(0, 3 - $failedAttempts) . ' attempt(s) remaining.',
    ]);
    exit;
}

unset($_SESSION['loan_delete_password_failures']);

echo json_encode(['ok' => true]);
