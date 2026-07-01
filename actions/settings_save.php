<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('business_settings.manage', 'pages/settings.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/settings.php');
}
require_csrf('pages/settings.php');

$businessName = trim((string) ($_POST['business_name'] ?? ''));
$businessPhone = trim((string) ($_POST['business_phone'] ?? ''));
$businessEmail = trim((string) ($_POST['business_email'] ?? ''));
$businessAddress = trim((string) ($_POST['business_address'] ?? ''));
$businessNote = trim((string) ($_POST['business_note'] ?? ''));

if ($businessName === '') {
    set_flash('error', 'Business name is required.');
    redirect('pages/settings.php');
}

if ($businessEmail !== '' && !filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please enter a valid business email.');
    redirect('pages/settings.php');
}

$settingsToSave = [
    'business_name' => mb_substr($businessName, 0, 120),
    'business_phone' => mb_substr($businessPhone, 0, 40),
    'business_email' => mb_substr($businessEmail, 0, 120),
    'business_address' => mb_substr($businessAddress, 0, 600),
    'business_note' => mb_substr($businessNote, 0, 600),
];

$oldBusinessIconPath = business_icon_path($pdo);
$newBusinessIconPath = $oldBusinessIconPath;

if (isset($_FILES['business_icon']) && is_array($_FILES['business_icon']) && (int) ($_FILES['business_icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['business_icon'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        set_flash('error', 'Business icon upload failed.');
        redirect('pages/settings.php');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        set_flash('error', 'Invalid business icon upload.');
        redirect('pages/settings.php');
    }
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        set_flash('error', 'Business icon must be between 1 byte and 2MB.');
        redirect('pages/settings.php');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    $ext = $allowed[$mime] ?? null;
    if ($ext === null) {
        set_flash('error', 'Business icon must be JPG, PNG, WEBP, GIF, or ICO.');
        redirect('pages/settings.php');
    }

    $uploadDirAbs = business_icon_upload_dir_abs();
    if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
        set_flash('error', 'Failed to create business icon folder.');
        redirect('pages/settings.php');
    }

    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable) {
        $random = str_replace('.', '', uniqid('', true));
    }
    $fileName = 'business_icon_' . date('YmdHis') . '_' . $random . '.' . $ext;
    $targetAbs = $uploadDirAbs . DIRECTORY_SEPARATOR . $fileName;
    $targetRel = business_icon_upload_dir_rel() . '/' . $fileName;

    if (!move_uploaded_file($tmpName, $targetAbs)) {
        set_flash('error', 'Failed to store business icon.');
        redirect('pages/settings.php');
    }

    $newBusinessIconPath = $targetRel;
    $settingsToSave['business_icon_path'] = $newBusinessIconPath;
}

try {
    system_settings_save($pdo, $settingsToSave, (int) (current_user()['id'] ?? 0));
    if ($newBusinessIconPath !== $oldBusinessIconPath && $oldBusinessIconPath !== '') {
        $oldAbsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldBusinessIconPath);
        if (is_file($oldAbsPath)) {
            @unlink($oldAbsPath);
        }
    }

    log_activity($pdo, 'settings.business_updated', 'Business settings updated.', [
        'business_name' => $settingsToSave['business_name'],
        'icon_updated' => $newBusinessIconPath !== $oldBusinessIconPath ? 1 : 0,
    ]);
    set_flash('success', 'Business settings saved successfully.');
} catch (Throwable $e) {
    set_flash('error', 'Failed to save business settings.');
}

redirect('pages/settings.php');
