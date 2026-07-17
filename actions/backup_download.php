<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('backup.manage');

@set_time_limit(0);

function build_sql_dump(PDO $pdo): array
{
    $tablesRaw = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $tables = array_map(static fn(array $row): string => (string) $row[0], $tablesRaw);

    $dump = [];
    $dump[] = '-- Loan Manager Database Backup';
    $dump[] = '-- Generated at: ' . date('Y-m-d H:i:s');
    $dump[] = '-- Database: ' . DB_NAME;
    $dump[] = '';
    $dump[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
    $dump[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $dump[] = '';

    foreach ($tables as $table) {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $dump[] = '--';
        $dump[] = '-- Table structure for ' . $quotedTable;
        $dump[] = '--';
        $dump[] = 'DROP TABLE IF EXISTS ' . $quotedTable . ';';

        $createStmt = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
        $createSql = (string) ($createStmt['Create Table'] ?? '');
        if ($createSql !== '') {
            $dump[] = $createSql . ';';
        }
        $dump[] = '';

        $rows = $pdo->query('SELECT * FROM ' . $quotedTable, PDO::FETCH_ASSOC)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }

        $dump[] = '--';
        $dump[] = '-- Dumping data for ' . $quotedTable;
        $dump[] = '--';

        $firstRow = $rows[0];
        $columns = array_map(
            static fn(string $col): string => '`' . str_replace('`', '``', $col) . '`',
            array_keys($firstRow)
        );
        $columnSql = implode(', ', $columns);

        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
            }
            $dump[] = 'INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');';
        }
        $dump[] = '';
    }

    $dump[] = 'SET FOREIGN_KEY_CHECKS=1;';

    return [
        'tables' => $tables,
        'content' => implode("\n", $dump) . "\n",
    ];
}

function backup_filename_prefix(PDO $pdo): string
{
    $businessName = trim(system_setting($pdo, 'business_name', 'Loan Management'));
    $prefix = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $businessName));
    $prefix = trim($prefix, '_');

    return $prefix !== '' ? $prefix : 'loan_management';
}

$mode = trim((string) ($_GET['mode'] ?? 'sql'));
$mode = in_array($mode, ['sql', 'full'], true) ? $mode : 'sql';

$dumpData = build_sql_dump($pdo);
$tables = $dumpData['tables'];
$content = (string) $dumpData['content'];

$timestamp = date('Ymd_His');
$filenamePrefix = backup_filename_prefix($pdo);

$uploadsRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';

if ($mode === 'full' && !class_exists('ZipArchive')) {
    set_flash('error', 'Full backup requires ZIP support. Enable the PHP zip extension in XAMPP.');
    redirect('pages/backup.php');
}

if ($mode === 'full' && class_exists('ZipArchive')) {
    $tmpZipBase = tempnam(sys_get_temp_dir(), 'loan_backup_');
    if ($tmpZipBase === false) {
        set_flash('error', 'Unable to create temporary ZIP file for full backup.');
        redirect('pages/backup.php');
    } else {
        $tmpZip = $tmpZipBase . '.zip';
        @unlink($tmpZipBase);

        $zip = new ZipArchive();
        $opened = $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened === true) {
            $zip->addFromString('database/backup.sql', $content);
            $zip->addFromString('meta/backup.json', json_encode([
                'generated_at' => date('c'),
                'database' => DB_NAME,
                'tables' => count($tables),
                'mode' => 'full',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $filesAdded = 0;
            if (is_dir($uploadsRoot)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }
                    $absPath = $fileInfo->getPathname();
                    $relPath = substr($absPath, strlen($uploadsRoot));
                    if ($relPath === false) {
                        continue;
                    }
                    $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
                    if ($relPath === '') {
                        continue;
                    }
                    $zip->addFile($absPath, 'uploads/' . $relPath);
                    $filesAdded++;
                }
            }

            $zip->close();

            $filename = $filenamePrefix . '_full_backup_' . $timestamp . '.zip';
            log_activity($pdo, 'backup.download', 'Full backup downloaded.', [
                'mode' => 'full',
                'tables' => count($tables),
                'file' => $filename,
                'uploads_files' => $filesAdded,
            ]);

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string) filesize($tmpZip));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            readfile($tmpZip);
            @unlink($tmpZip);
            exit;
        }

        @unlink($tmpZip);
        set_flash('error', 'Unable to build ZIP backup file.');
        redirect('pages/backup.php');
    }
}

$filename = $filenamePrefix . '_backup_' . $timestamp . '.sql';
log_activity($pdo, 'backup.download', 'Database backup downloaded.', [
    'mode' => 'sql',
    'tables' => count($tables),
    'file' => $filename,
]);

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo $content;
exit;
