<?php

declare(strict_types=1);

const APP_NAME = 'Loan Management System';
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'loan_management';
const DB_USER = 'root';
const DB_PASS = '';

date_default_timezone_set('Asia/Colombo');

$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$projectRoot = realpath(__DIR__ . '/..') ?: '';
$basePath = '/';

if ($documentRoot !== '' && $projectRoot !== '' && str_starts_with(strtolower($projectRoot), strtolower($documentRoot))) {
    $relative = str_replace('\\', '/', substr($projectRoot, strlen($documentRoot)));
    $basePath = $relative === '' ? '/' : $relative;
}

define('BASE_PATH', rtrim($basePath, '/') === '' ? '/' : rtrim($basePath, '/'));
