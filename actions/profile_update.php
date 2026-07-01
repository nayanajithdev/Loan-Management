<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('profile.manage', 'pages/profile.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/profile.php');
}
require_csrf('pages/profile.php');

$current = current_user();
if (!$current) {
    redirect('login.php');
}

$userId = (int) $current['id'];
$fullNameInput = trim((string) ($_POST['full_name'] ?? ''));
$usernameInput = trim((string) ($_POST['username'] ?? ''));
$emailInput = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

$userStmt = $pdo->prepare('SELECT id, full_name, username, email, role, avatar_path FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch();
if (!$user) {
    logout_user();
    set_flash('error', 'Your account was removed. Please login again.');
    redirect('login.php');
}

$canEditName = ((string) $user['role']) === 'superadmin';
$newName = $canEditName ? $fullNameInput : (string) $user['full_name'];

if ($newName === '') {
    set_flash('error', 'Name is required.');
    redirect('pages/profile.php');
}

if ($usernameInput === '') {
    set_flash('error', 'Username is required.');
    redirect('pages/profile.php');
}

if ($emailInput === '' || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Valid email is required.');
    redirect('pages/profile.php');
}

$existsStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
$existsStmt->execute([
    'username' => $usernameInput,
    'id' => $userId,
]);
if ($existsStmt->fetch()) {
    set_flash('error', 'Username already exists.');
    redirect('pages/profile.php');
}

$emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
$emailStmt->execute([
    'email' => $emailInput,
    'id' => $userId,
]);
if ($emailStmt->fetch()) {
    set_flash('error', 'Email already exists.');
    redirect('pages/profile.php');
}

$avatarPathToSave = (string) ($user['avatar_path'] ?? '');
$oldAvatarPath = $avatarPathToSave;

if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['avatar'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        set_flash('error', 'Avatar upload failed.');
        redirect('pages/profile.php');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        set_flash('error', 'Invalid avatar upload.');
        redirect('pages/profile.php');
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        set_flash('error', 'Avatar must be between 1 byte and 5MB.');
        redirect('pages/profile.php');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $ext = $allowed[$mime] ?? null;
    if ($ext === null) {
        set_flash('error', 'Avatar must be JPG, PNG, WEBP, or GIF.');
        redirect('pages/profile.php');
    }

    $uploadDirAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_avatars';
    if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
        set_flash('error', 'Failed to create avatar folder.');
        redirect('pages/profile.php');
    }

    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable) {
        $random = str_replace('.', '', uniqid('', true));
    }
    $fileName = 'user_' . $userId . '_' . date('YmdHis') . '_' . $random . '.' . $ext;
    $targetAbs = $uploadDirAbs . DIRECTORY_SEPARATOR . $fileName;
    $targetRel = 'uploads/profile_avatars/' . $fileName;

    if (!move_uploaded_file($tmpName, $targetAbs)) {
        set_flash('error', 'Failed to store avatar.');
        redirect('pages/profile.php');
    }

    $avatarPathToSave = $targetRel;
}

$updateStmt = $pdo->prepare('UPDATE users SET full_name = :full_name, username = :username, email = :email, avatar_path = :avatar_path WHERE id = :id');
$updateStmt->execute([
    'full_name' => mb_substr($newName, 0, 120),
    'username' => mb_substr($usernameInput, 0, 100),
    'email' => mb_substr($emailInput, 0, 190),
    'avatar_path' => $avatarPathToSave !== '' ? $avatarPathToSave : null,
    'id' => $userId,
]);

if ($avatarPathToSave !== $oldAvatarPath && $oldAvatarPath !== '') {
    $oldAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldAvatarPath);
    if (is_file($oldAbs)) {
        @unlink($oldAbs);
    }
}

$_SESSION['auth_user']['full_name'] = mb_substr($newName, 0, 120);
$_SESSION['auth_user']['username'] = mb_substr($usernameInput, 0, 100);
$_SESSION['auth_user']['email'] = mb_substr($emailInput, 0, 190);
$_SESSION['auth_user']['avatar_path'] = $avatarPathToSave;

log_activity($pdo, 'profile.updated', 'Profile updated.', [
    'user_id' => $userId,
    'name_changed' => ((string) $user['full_name']) !== $newName ? 1 : 0,
    'username_changed' => ((string) $user['username']) !== $usernameInput ? 1 : 0,
    'email_changed' => ((string) ($user['email'] ?? '')) !== $emailInput ? 1 : 0,
    'avatar_changed' => $avatarPathToSave !== $oldAvatarPath ? 1 : 0,
]);

set_flash('success', 'Profile updated successfully.');
redirect('pages/profile.php');
