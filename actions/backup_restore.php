<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

function remove_tree_contents(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function copy_tree_contents(string $fromDir, string $toDir): int
{
    if (!is_dir($fromDir)) {
        return 0;
    }

    if (!is_dir($toDir) && !mkdir($toDir, 0775, true) && !is_dir($toDir)) {
        throw new RuntimeException('Failed to prepare restore directory.');
    }

    $copied = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fromDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $sourcePath = $item->getPathname();
        $relative = substr($sourcePath, strlen($fromDir));
        if ($relative === false) {
            continue;
        }
        $relative = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
        $targetPath = $toDir . DIRECTORY_SEPARATOR . $relative;

        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Failed to create restore subdirectory.');
            }
            continue;
        }

        $targetParent = dirname($targetPath);
        if (!is_dir($targetParent) && !mkdir($targetParent, 0775, true) && !is_dir($targetParent)) {
            throw new RuntimeException('Failed to prepare restore file directory.');
        }

        if (!@copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Failed to restore backup files.');
        }
        $copied++;
    }

    return $copied;
}

function run_restore_sql(string $sql): void
{
    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('mysqli extension is required for restore.');
    }

    $mysqli = mysqli_init();
    if ($mysqli === false) {
        throw new RuntimeException('Failed to initialize database restore connection.');
    }

    $connected = @$mysqli->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT);
    if (!$connected) {
        throw new RuntimeException('Database connection failed for restore.');
    }

    $mysqli->set_charset('utf8mb4');
    $script = "SET FOREIGN_KEY_CHECKS=0;\n" . $sql . "\nSET FOREIGN_KEY_CHECKS=1;\n";

    if (!$mysqli->multi_query($script)) {
        $errorMsg = $mysqli->error;
        $mysqli->close();
        throw new RuntimeException('Restore failed: ' . $errorMsg);
    }

    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
        if ($mysqli->errno) {
            $errorMsg = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException('Restore failed: ' . $errorMsg);
        }
    } while ($mysqli->more_results() && $mysqli->next_result());

    $mysqli->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/backup.php');
}
require_csrf('pages/backup.php');

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
if (!in_array($extension, ['sql', 'zip'], true)) {
    set_flash('error', 'Only .sql or .zip backup files are allowed.');
    redirect('pages/backup.php');
}

@set_time_limit(0);

try {
    $sql = '';
    $restoredUploads = 0;
    $restoreMode = 'sql';
    $fullBackupTempDir = '';
    $fullBackupUploadsDir = '';

    if ($extension === 'sql') {
        $sqlRaw = file_get_contents($tmpName);
        if ($sqlRaw === false || trim($sqlRaw) === '') {
            throw new RuntimeException('Unable to read uploaded SQL file.');
        }
        $sql = (string) $sqlRaw;
    } else {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is required to restore .zip backups.');
        }

        $restoreMode = 'full';
        $zip = new ZipArchive();
        $openRes = $zip->open($tmpName);
        if ($openRes !== true) {
            throw new RuntimeException('Unable to open uploaded ZIP backup.');
        }

        $sqlEntryName = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $lower = strtolower($name);
            if ($lower === 'database/backup.sql' || $lower === 'backup.sql') {
                $sqlEntryName = $name;
                break;
            }
            if ($sqlEntryName === '' && str_ends_with($lower, '.sql')) {
                $sqlEntryName = $name;
            }
        }

        if ($sqlEntryName === '') {
            $zip->close();
            throw new RuntimeException('No SQL file found inside ZIP backup.');
        }

        $sqlRaw = $zip->getFromName($sqlEntryName);
        if ($sqlRaw === false || trim((string) $sqlRaw) === '') {
            $zip->close();
            throw new RuntimeException('SQL content inside ZIP backup is empty or unreadable.');
        }
        $sql = (string) $sqlRaw;

        $fullBackupTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loan_restore_' . bin2hex(random_bytes(6));
        $fullBackupUploadsDir = $fullBackupTempDir . DIRECTORY_SEPARATOR . 'uploads';
        if (!mkdir($fullBackupUploadsDir, 0775, true) && !is_dir($fullBackupUploadsDir)) {
            $zip->close();
            throw new RuntimeException('Failed to prepare temporary restore directory.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string) $zip->getNameIndex($i);
            if ($entryName === '' || str_ends_with($entryName, '/')) {
                continue;
            }

            $normalized = str_replace('\\', '/', $entryName);
            if (!str_starts_with($normalized, 'uploads/')) {
                continue;
            }

            $relative = substr($normalized, strlen('uploads/'));
            if ($relative === false || $relative === '') {
                continue;
            }

            if (str_contains($relative, '../') || str_starts_with($relative, '/')) {
                continue;
            }

            $targetAbs = $fullBackupUploadsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $targetDir = dirname($targetAbs);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                continue;
            }

            $stream = $zip->getStream($entryName);
            if ($stream === false) {
                continue;
            }

            $out = @fopen($targetAbs, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            $restoredUploads++;
        }

        $zip->close();
    }

    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (preg_match('/\bDROP\s+DATABASE\b/i', $sql) || preg_match('/\bCREATE\s+DATABASE\b/i', $sql)) {
        throw new RuntimeException('Unsafe SQL detected (database-level statements are not allowed).');
    }

    run_restore_sql($sql);

    if ($restoreMode === 'full') {
        $projectRoot = dirname(__DIR__);
        $uploadsRoot = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
        $uploadsSnapshotDir = $fullBackupTempDir . DIRECTORY_SEPARATOR . 'uploads_snapshot';

        if (!is_dir($uploadsRoot) && !mkdir($uploadsRoot, 0775, true) && !is_dir($uploadsRoot)) {
            throw new RuntimeException('Failed to prepare uploads directory.');
        }

        if (is_dir($uploadsRoot)) {
            copy_tree_contents($uploadsRoot, $uploadsSnapshotDir);
        }

        try {
            remove_tree_contents($uploadsRoot);
            copy_tree_contents($fullBackupUploadsDir, $uploadsRoot);
            ensure_customer_docs_guard_file($uploadsRoot . DIRECTORY_SEPARATOR . 'customer_docs');
        } catch (Throwable $fileRestoreError) {
            remove_tree_contents($uploadsRoot);
            if (is_dir($uploadsSnapshotDir)) {
                copy_tree_contents($uploadsSnapshotDir, $uploadsRoot);
            }
            throw new RuntimeException('Database restored, but file restore failed. Previous files were restored.');
        }
    }

    log_activity($pdo, 'backup.restore', 'Backup restored successfully.', [
        'mode' => $restoreMode,
        'file_name' => $fileName,
        'file_size' => $size,
        'uploads_restored' => $restoredUploads ?? 0,
    ]);
    set_flash('success', $restoreMode === 'full'
        ? 'Full backup restored successfully (database + files).'
        : 'Database backup restored successfully.');
} catch (Throwable $e) {
    $message = $e->getMessage();
    if (str_starts_with($message, 'Restore failed:')) {
        $message = 'Restore failed. Please verify the backup file format and data consistency.';
    }
    log_activity($pdo, 'backup.restore_failed', 'Backup restore failed.', [
        'file_name' => $fileName ?? '',
        'reason' => $e->getMessage(),
    ]);
    set_flash('error', $message);
} finally {
    if (isset($fullBackupTempDir) && $fullBackupTempDir !== '' && is_dir($fullBackupTempDir)) {
        remove_tree_contents($fullBackupTempDir);
        @rmdir($fullBackupTempDir);
    }
}
redirect('pages/backup.php');
