<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/backup.php');
}

$confirmed = (string) ($_POST['confirm_replace'] ?? '') === '1';
if (!$confirmed) {
    set_flash('error', 'Please confirm restore before uploading.');
    redirect('pages/backup.php');
}

if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
    set_flash('error', 'Backup file is required.');
    redirect('pages/backup.php');
}

$file = $_FILES['backup_file'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    set_flash('error', 'Upload failed. Please try again.');
    redirect('pages/backup.php');
}

$tmpName = (string) ($file['tmp_name'] ?? '');
$fileName = (string) ($file['name'] ?? '');
$size = (int) ($file['size'] ?? 0);

if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    set_flash('error', 'Invalid uploaded file.');
    redirect('pages/backup.php');
}

if ($size <= 0 || $size > 150 * 1024 * 1024) {
    set_flash('error', 'Backup file size must be between 1 byte and 150MB.');
    redirect('pages/backup.php');
}

$extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
if ($extension !== 'sql') {
    set_flash('error', 'Only .sql backup files are allowed.');
    redirect('pages/backup.php');
}

$sql = file_get_contents($tmpName);
if ($sql === false || trim($sql) === '') {
    set_flash('error', 'Unable to read uploaded SQL file.');
    redirect('pages/backup.php');
}

$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
if (preg_match('/\bDROP\s+DATABASE\b/i', $sql) || preg_match('/\bCREATE\s+DATABASE\b/i', $sql)) {
    set_flash('error', 'Unsafe SQL detected (database-level statements are not allowed).');
    redirect('pages/backup.php');
}

if (!extension_loaded('mysqli')) {
    set_flash('error', 'mysqli extension is required for restore.');
    redirect('pages/backup.php');
}

@set_time_limit(0);

$mysqli = mysqli_init();
if ($mysqli === false) {
    set_flash('error', 'Failed to initialize database restore connection.');
    redirect('pages/backup.php');
}

$connected = @$mysqli->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT);
if (!$connected) {
    set_flash('error', 'Database connection failed for restore.');
    redirect('pages/backup.php');
}

$mysqli->set_charset('utf8mb4');
$script = "SET FOREIGN_KEY_CHECKS=0;\n" . $sql . "\nSET FOREIGN_KEY_CHECKS=1;\n";

if (!$mysqli->multi_query($script)) {
    $errorMsg = $mysqli->error;
    $mysqli->close();
    set_flash('error', 'Restore failed: ' . $errorMsg);
    redirect('pages/backup.php');
}

do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
    if ($mysqli->errno) {
        $errorMsg = $mysqli->error;
        $mysqli->close();
        set_flash('error', 'Restore failed: ' . $errorMsg);
        redirect('pages/backup.php');
    }
} while ($mysqli->more_results() && $mysqli->next_result());

$mysqli->close();
log_activity($pdo, 'backup.restore', 'Database backup restored successfully.', [
    'file_name' => $fileName,
    'file_size' => $size,
]);
set_flash('success', 'Backup restored successfully.');
redirect('pages/backup.php');
