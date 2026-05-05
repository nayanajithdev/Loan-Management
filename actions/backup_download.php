<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

@set_time_limit(0);

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
$content = implode("\n", $dump) . "\n";
$filename = 'loan_management_backup_' . date('Ymd_His') . '.sql';
log_activity($pdo, 'backup.download', 'Database backup downloaded.', [
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
