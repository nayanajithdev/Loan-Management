<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/settings.php');
}

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

try {
    system_settings_save($pdo, $settingsToSave, (int) (current_user()['id'] ?? 0));
    log_activity($pdo, 'settings.business_updated', 'Business settings updated.', [
        'business_name' => $settingsToSave['business_name'],
    ]);
    set_flash('success', 'Business settings saved successfully.');
} catch (Throwable $e) {
    set_flash('error', 'Failed to save business settings.');
}

redirect('pages/settings.php');
