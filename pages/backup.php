<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles(['superadmin', 'admin']);

$pageTitle = 'Backup';
$activePage = 'backup';

$tableRows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
$tableNames = array_map(static fn(array $row): string => (string) $row[0], $tableRows);
$tableCount = count($tableNames);

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="backup-shell">
    <article class="backup-section">
        <div class="backup-section-head">
            <div>
                <p class="backup-kicker">Database Backup</p>
                <h2 class="panel-title">Download current backup</h2>
            </div>
            <span class="backup-badge"><?= e((string) $tableCount) ?> tables</span>
        </div>

        <div class="backup-note">
            <h3>What this download contains</h3>
            <p>This SQL file includes current database structure and saved records.</p>
            <p class="backup-table-list"><?= e(implode(', ', $tableNames)) ?></p>
        </div>

        <div class="backup-actions">
            <a class="btn btn-primary" href="<?= e(url('actions/backup_download.php')) ?>">Download Backup (.sql)</a>
        </div>
    </article>

    <article class="backup-section">
        <div class="backup-section-head">
            <div>
                <p class="backup-kicker">Recovery</p>
                <h2 class="panel-title">Upload and restore backup</h2>
            </div>
            <span class="backup-badge">Owner / Manager only</span>
        </div>

        <div class="backup-note backup-warning">
            <h3>Warning</h3>
            <p>Restoring a backup will replace current database structure and data. Download a fresh backup first if needed.</p>
        </div>

        <form method="post" action="<?= e(url('actions/backup_restore.php')) ?>" enctype="multipart/form-data" class="form-grid">
            <div class="field full">
                <label>Backup File (.sql)</label>
                <input type="file" name="backup_file" accept=".sql,text/plain,application/sql,application/octet-stream" required>
            </div>
            <div class="field full">
                <label class="backup-checkbox">
                    <input type="checkbox" name="confirm_replace" value="1" required>
                    <span>I understand this will replace the current database.</span>
                </label>
            </div>
            <div class="field full">
                <button type="submit" class="btn backup-restore-btn">Upload and Restore Backup</button>
            </div>
        </form>
    </article>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
