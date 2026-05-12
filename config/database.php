<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

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
