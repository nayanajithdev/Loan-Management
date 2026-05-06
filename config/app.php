<?php

declare(strict_types=1);

if (!function_exists('load_env_file')) {
    /**
     * Minimal .env loader for shared hosting (no external package needed).
     */
    function load_env_file(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === '') {
                continue;
            }

            // Strip matching quotes.
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}

load_env_file(__DIR__ . '/../.env');

if (!function_exists('load_local_db_config')) {
    function load_local_db_config(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }
}

if (!function_exists('db_config_value')) {
    function db_config_value(string $envKey, string $localKey, string $default, array $localDbConfig): string
    {
        $env = env_value($envKey, '');
        if ($env !== '') {
            return $env;
        }

        $local = $localDbConfig[$localKey] ?? null;
        if (is_string($local) && $local !== '') {
            return $local;
        }

        return $default;
    }
}

$localDbConfig = load_local_db_config(__DIR__ . '/database.local.php');

const APP_NAME = 'Loan Management System';
define('DB_HOST', db_config_value('DB_HOST', 'host', '127.0.0.1', $localDbConfig));
define('DB_PORT', db_config_value('DB_PORT', 'port', '3306', $localDbConfig));
define('DB_NAME', db_config_value('DB_NAME', 'name', 'loan_management', $localDbConfig));
define('DB_USER', db_config_value('DB_USER', 'user', 'root', $localDbConfig));
define('DB_PASS', db_config_value('DB_PASS', 'pass', '', $localDbConfig));

date_default_timezone_set('Asia/Colombo');

$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$projectRoot = realpath(__DIR__ . '/..') ?: '';
$basePath = '/';

if ($documentRoot !== '' && $projectRoot !== '' && str_starts_with(strtolower($projectRoot), strtolower($documentRoot))) {
    $relative = str_replace('\\', '/', substr($projectRoot, strlen($documentRoot)));
    $basePath = $relative === '' ? '/' : $relative;
}

define('BASE_PATH', rtrim($basePath, '/') === '' ? '/' : rtrim($basePath, '/'));
