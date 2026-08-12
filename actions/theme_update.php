<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
require_csrf('index.php');

$current = current_user();
$userId = (int) ($current['id'] ?? 0);
$theme = normalize_theme_preference(trim((string) ($_POST['theme'] ?? 'dark')));
$isJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

if ($userId <= 0) {
    if ($isJson) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Please login again.']);
        exit;
    }

    redirect('login.php');
}

$stmt = $pdo->prepare('UPDATE users SET theme_preference = :theme_preference WHERE id = :id');
$stmt->execute([
    'theme_preference' => $theme,
    'id' => $userId,
]);

$_SESSION['auth_user']['theme_preference'] = $theme;

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'theme' => $theme]);
    exit;
}

redirect(authenticated_landing_path());
