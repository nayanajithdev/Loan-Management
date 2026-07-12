<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function render_database_unavailable_page(): never
{
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('Database is not connected or schema is not ready.');
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }

    $schemaPath = 'db/schema.sql';

    echo '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Not Connected</title>
    <style>
        :root {
            --bg: #0b1117;
            --surface: #14202a;
            --surface-2: #1b2a36;
            --border: #2c4354;
            --warning: #f59e0b;
            --text: #f3f3f3;
            --muted: #b7c4d0;
            --blue: #b7d2ff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at center, rgba(35, 56, 72, 0.42), transparent 38%),
                linear-gradient(135deg, #0b1117 0%, #101820 48%, #0b1117 100%);
            color: var(--text);
            font-family: Manrope, Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding: 18px;
        }
        .db-error-card {
            width: min(480px, 100%);
            border: 1px solid var(--border);
            border-radius: 6px;
            background: rgba(27, 42, 54, 0.96);
            padding: 24px;
        }
        .db-error-inner {
            border: 1px solid rgba(245, 158, 11, 0.55);
            border-radius: 6px;
            background: rgba(20, 32, 42, 0.94);
            padding: 16px 18px;
            display: grid;
            grid-template-columns: 18px 1fr;
            gap: 10px;
            align-items: start;
        }
        .db-error-icon {
            color: var(--warning);
            width: 18px;
            height: 18px;
            margin-top: 2px;
        }
        .db-error-title {
            color: var(--warning);
            font-size: 16px;
            font-weight: 800;
            margin: 0 0 6px;
        }
        .db-error-text {
            color: var(--blue);
            font-size: 16px;
            line-height: 1.45;
            margin: 0;
        }
        code {
            display: inline-block;
            background: #07111d;
            color: #fff;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 13px;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <main class="db-error-card" role="main">
        <div class="db-error-inner">
            <svg class="db-error-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
            <div>
                <p class="db-error-title">Database is not connected.</p>
                <p class="db-error-text">Create the database and import <code>' . htmlspecialchars($schemaPath, ENT_QUOTES, 'UTF-8') . '</code> before creating owner.</p>
            </div>
        </div>
    </main>
</body>
</html>';
    exit;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        render_database_unavailable_page();
    }

    // Local-only fake date support for development:
    // if config/database.local.php contains ['fake_today' => 'YYYY-MM-DD'],
    // force this DB session to use that date for CURDATE()/NOW().
    $fakeTodayRaw = '';
    if (defined('LOCAL_APP_CONFIG') && is_array(LOCAL_APP_CONFIG)) {
        $fakeTodayRaw = trim((string) (LOCAL_APP_CONFIG['fake_today'] ?? ''));
    }
    if ($fakeTodayRaw !== '') {
        $parsedFakeToday = DateTimeImmutable::createFromFormat('Y-m-d', $fakeTodayRaw);
        if ($parsedFakeToday && $parsedFakeToday->format('Y-m-d') === $fakeTodayRaw) {
            $fakeDateTime = $fakeTodayRaw . ' 12:00:00';
            try {
                $pdo->exec('SET timestamp = UNIX_TIMESTAMP(' . $pdo->quote($fakeDateTime) . ')');
            } catch (Throwable) {
                // Ignore if host/database blocks session timestamp changes.
            }
        }
    }

    return $pdo;
}
