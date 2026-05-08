<?php

declare(strict_types=1);

function url(string $path = ''): string
{
    $path = ltrim($path, '/');

    if (BASE_PATH === '/') {
        return '/' . $path;
    }

    return rtrim(BASE_PATH, '/') . '/' . $path;
}

function absolute_url(string $path = ''): string
{
    $configured = trim(env_value('APP_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = BASE_PATH === '/' ? '' : BASE_PATH;

    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function role_display_name(string $role): string
{
    return match ($role) {
        'superadmin' => 'Owner',
        'admin' => 'Manager',
        'collector_l1' => 'Collector L1',
        'collector_l2' => 'Collector L2',
        'collector' => 'Collector L2',
        'unassigned' => 'Unassigned',
        default => ucfirst($role),
    };
}

function money(float $amount): string
{
    return number_format($amount, 2);
}

function currency_label(PDO $pdo): string
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $label = trim(system_setting($pdo, 'currency_label', 'LKR'));
    $cached = $label !== '' ? $label : 'LKR';

    return $cached;
}

function money_label(PDO $pdo, float $amount): string
{
    return currency_label($pdo) . ' ' . money($amount);
}

function timezone_offset_for_mysql(string $timezoneIdentifier): string
{
    try {
        $timezone = new DateTimeZone($timezoneIdentifier);
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    $now = new DateTimeImmutable('now', $timezone);
    $offsetSeconds = $timezone->getOffset($now);
    $sign = $offsetSeconds >= 0 ? '+' : '-';
    $abs = abs($offsetSeconds);
    $hours = intdiv($abs, 3600);
    $minutes = intdiv($abs % 3600, 60);

    return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
}

function sync_mysql_session_timezone(PDO $pdo, ?string $timezoneIdentifier = null): void
{
    $timezoneIdentifier = trim((string) $timezoneIdentifier);
    if ($timezoneIdentifier === '') {
        $timezoneIdentifier = date_default_timezone_get();
    }

    $offset = timezone_offset_for_mysql($timezoneIdentifier);

    try {
        $stmt = $pdo->prepare('SET time_zone = :tz');
        $stmt->execute(['tz' => $offset]);
    } catch (Throwable) {
        // Some hosting environments may block session timezone changes.
        // Keep app running and fallback to server timezone behavior.
    }
}

function poll_interval_ms(PDO $pdo): int
{
    $seconds = (int) system_setting($pdo, 'poll_interval_seconds', '10');
    return max(3000, min(60000, $seconds * 1000));
}

function display_date(string $dateValue, ?string $fallback = null): string
{
    static $format = null;
    if ($format === null) {
        $saved = trim(system_setting(db(), 'date_format', 'd M Y'));
        $format = $saved !== '' ? $saved : 'd M Y';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateValue);
    if (!$date) {
        return $fallback ?? $dateValue;
    }

    return $date->format($format);
}

function display_datetime(string $dateTimeValue, ?string $fallback = null): string
{
    static $dateTimeFormat = null;
    if ($dateTimeFormat === null) {
        $saved = trim(system_setting(db(), 'date_format', 'd M Y'));
        $dateTimeFormat = ($saved !== '' ? $saved : 'd M Y') . ' H:i:s';
    }

    try {
        $date = new DateTimeImmutable($dateTimeValue);
    } catch (Throwable) {
        return $fallback ?? $dateTimeValue;
    }

    return $date->format($dateTimeFormat);
}

function today(): string
{
    return date('Y-m-d');
}

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token']) || $_SESSION['_csrf_token'] === '') {
        try {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable) {
            $_SESSION['_csrf_token'] = str_replace('.', '', uniqid('csrf_', true));
        }
    }

    return (string) $_SESSION['_csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(?string $submittedToken): bool
{
    if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        return false;
    }
    if ($submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals((string) $_SESSION['_csrf_token'], $submittedToken);
}

function require_csrf(string $redirectPath = 'index.php'): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = (string) ($_POST['_csrf'] ?? '');
    if (!csrf_is_valid($token)) {
        set_flash('error', 'Invalid request token. Please try again.');
        redirect($redirectPath);
    }
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function frequency_interval(string $frequency): DateInterval
{
    return match ($frequency) {
        'daily' => new DateInterval('P1D'),
        'weekly' => new DateInterval('P7D'),
        'monthly' => new DateInterval('P1M'),
        default => new DateInterval('P1D'),
    };
}

function installment_count_from_timeframe(string $frequency, int $timeframeValue, string $timeframeUnit): int
{
    $timeframeValue = max($timeframeValue, 1);
    $timeframeUnit = in_array($timeframeUnit, ['days', 'months'], true) ? $timeframeUnit : 'days';

    $totalDays = $timeframeUnit === 'months' ? $timeframeValue * 30 : $timeframeValue;

    $count = match ($frequency) {
        'daily' => $totalDays,
        'weekly' => (int) ceil($totalDays / 7),
        'monthly' => $timeframeUnit === 'months' ? $timeframeValue : (int) ceil($totalDays / 30),
        default => $totalDays,
    };

    return max($count, 1);
}

function normalize_interest_rate_type(string $value): string
{
    return in_array($value, ['amount_based', 'monthly'], true) ? $value : 'amount_based';
}

function normalize_interest_rate_months(int $months): int
{
    return max($months, 1);
}

function loan_total_amount(
    float $principal,
    float $interestRate,
    string $interestRateType,
    int $interestRateMonths = 1
): float {
    $principal = max($principal, 0);
    $interestRate = max($interestRate, 0);
    $interestRateType = normalize_interest_rate_type($interestRateType);
    $interestRateMonths = normalize_interest_rate_months($interestRateMonths);

    $baseInterest = $principal * ($interestRate / 100);
    $multiplier = $interestRateType === 'monthly'
        ? $interestRateMonths
        : 1.0;

    return round($principal + ($baseInterest * $multiplier), 2);
}

function next_loan_number(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT id FROM loans ORDER BY id DESC LIMIT 1');
    $lastId = (int) ($stmt->fetchColumn() ?: 0);

    return 'LN-' . str_pad((string) ($lastId + 1), 6, '0', STR_PAD_LEFT);
}

function next_customer_code(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT id FROM customers ORDER BY id DESC LIMIT 1');
    $lastId = (int) ($stmt->fetchColumn() ?: 0);

    return 'CUST-' . str_pad((string) ($lastId + 1), 5, '0', STR_PAD_LEFT);
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'active', 'paid' => 'success',
        'closed' => 'info',
        'partial' => 'warning',
        'defaulted', 'overdue', 'inactive' => 'danger',
        default => 'neutral',
    };
}

function refresh_overdue_installments(PDO $pdo): void
{
    $stmt = $pdo->prepare("UPDATE loan_installments SET status = 'overdue' WHERE status IN ('pending', 'partial') AND due_date < CURDATE() AND paid_amount < due_amount");
    $stmt->execute();
}

function dashboard_stats(PDO $pdo, ?array $viewer = null): array
{
    refresh_overdue_installments($pdo);

    $viewerRole = (string) ($viewer['role'] ?? '');
    $viewerId = (int) ($viewer['id'] ?? 0);
    $isCollectorScope = is_collector_role($viewerRole) && $viewerId > 0;

    $totals = [
        'customers' => 0,
        'active_loans' => 0,
        'today_pending_amount' => 0.0,
        'today_pending_count' => 0,
        'overdue_amount' => 0.0,
        'overdue_count' => 0,
        'overdue_customers' => 0,
        'outstanding_principal' => 0.0,
        'closed_loans_profit' => 0.0,
        'expected_open_profit' => 0.0,
        'total_disbursed' => 0.0,
        'total_collected' => 0.0,
    ];

    if (!$isCollectorScope) {
        $totals['customers'] = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $totals['active_loans'] = (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'active'")->fetchColumn();
        $totals['outstanding_principal'] = (float) $pdo->query("SELECT COALESCE(SUM(li.due_amount - li.paid_amount), 0) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE l.status = 'active' AND li.status IN ('pending', 'partial', 'overdue')")->fetchColumn();
        $totals['closed_loans_profit'] = (float) $pdo->query("SELECT COALESCE(SUM(total_amount - principal_amount), 0) FROM loans WHERE status = 'closed'")->fetchColumn();
        $totals['expected_open_profit'] = (float) $pdo->query("SELECT COALESCE(SUM(total_amount - principal_amount), 0) FROM loans WHERE status <> 'closed'")->fetchColumn();
        $totals['total_disbursed'] = (float) $pdo->query('SELECT COALESCE(SUM(principal_amount), 0) FROM loans')->fetchColumn();
        $totals['total_collected'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM collections')->fetchColumn();

        $todayPendingOnly = (float) $pdo->query("SELECT COALESCE(SUM(due_amount - paid_amount), 0) FROM loan_installments WHERE due_date = CURDATE() AND status IN ('pending', 'partial', 'overdue')")->fetchColumn();
        $totals['today_pending_count'] = (int) $pdo->query("SELECT COUNT(*) FROM loan_installments WHERE due_date = CURDATE() AND status IN ('pending', 'partial', 'overdue')")->fetchColumn();

        $totals['overdue_amount'] = (float) $pdo->query("SELECT COALESCE(SUM(due_amount - paid_amount), 0) FROM loan_installments WHERE status = 'overdue'")->fetchColumn();
        $totals['overdue_count'] = (int) $pdo->query("SELECT COUNT(*) FROM loan_installments WHERE status = 'overdue'")->fetchColumn();
        $totals['overdue_customers'] = (int) $pdo->query("SELECT COUNT(DISTINCT l.customer_id) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE li.status = 'overdue'")->fetchColumn();
    } else {
        $scope = '(l.assigned_user_id = :viewer_user_id OR l.assigned_user_id IS NULL)';

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT l.customer_id) FROM loans l WHERE {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['customers'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM loans l WHERE l.status = 'active' AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['active_loans'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(li.due_amount - li.paid_amount), 0) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE l.status = 'active' AND li.status IN ('pending', 'partial', 'overdue') AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['outstanding_principal'] = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.total_amount - l.principal_amount), 0) FROM loans l WHERE l.status = 'closed' AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['closed_loans_profit'] = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.total_amount - l.principal_amount), 0) FROM loans l WHERE l.status <> 'closed' AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['expected_open_profit'] = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.principal_amount), 0) FROM loans l WHERE {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['total_disbursed'] = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(col.amount), 0) FROM collections col JOIN loans l ON l.id = col.loan_id WHERE {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['total_collected'] = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(li.due_amount - li.paid_amount), 0) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE li.due_date = CURDATE() AND li.status IN ('pending', 'partial', 'overdue') AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $todayPendingOnly = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE li.due_date = CURDATE() AND li.status IN ('pending', 'partial', 'overdue') AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['today_pending_count'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(li.due_amount - li.paid_amount), 0) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE li.status = 'overdue' AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['overdue_amount'] = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE li.status = 'overdue' AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['overdue_count'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT l.customer_id) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE li.status = 'overdue' AND {$scope}");
        $stmt->execute(['viewer_user_id' => $viewerId]);
        $totals['overdue_customers'] = (int) $stmt->fetchColumn();
    }

    $totals['today_pending_amount'] = $todayPendingOnly + $totals['overdue_amount'];

    return $totals;
}

function ensure_user_schema(PDO $pdo): void
{
    $roleColStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $roleColumn = $roleColStmt->fetch();

    if (!$roleColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('superadmin','admin','collector','collector_l1','collector_l2') NOT NULL DEFAULT 'admin' AFTER password_hash");
    }
}

function ensure_user_email_schema(PDO $pdo): void
{
    $emailColStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'");
    $emailColumn = $emailColStmt->fetch();

    if (!$emailColumn) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username');
    }

    // Normalize blanks before adding a unique key.
    $pdo->exec("UPDATE users SET email = NULL WHERE email IS NOT NULL AND TRIM(email) = ''");

    $indexStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_email'"
    );
    $indexStmt->execute();
    $hasIndex = (int) $indexStmt->fetchColumn() > 0;
    if (!$hasIndex) {
        $duplicateStmt = $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT LOWER(TRIM(email)) AS normalized_email
                FROM users
                WHERE email IS NOT NULL AND TRIM(email) <> ''
                GROUP BY LOWER(TRIM(email))
                HAVING COUNT(*) > 1
            ) AS duplicate_emails"
        );
        $hasDuplicates = (int) $duplicateStmt->fetchColumn() > 0;

        if ($hasDuplicates) {
            error_log('Skipped users email unique key migration: duplicate emails exist.');
            return;
        }

        try {
            $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
        } catch (PDOException $e) {
            // Never break bootstrap on runtime migration mismatch.
            error_log('Failed to add users email unique key at startup: ' . $e->getMessage());
        }
    }
}

function ensure_user_status_schema(PDO $pdo): void
{
    $statusColStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
    $statusColumn = $statusColStmt->fetch();

    if (!$statusColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER role");
        return;
    }

    $statusType = strtolower((string) ($statusColumn['Type'] ?? ''));
    if (!str_contains($statusType, "'inactive'")) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
    }
}

function ensure_password_reset_tokens_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            requested_ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_reset_tokens_token_hash (token_hash),
            INDEX idx_password_reset_tokens_user_id (user_id),
            INDEX idx_password_reset_tokens_expires_at (expires_at),
            CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
}

function ensure_user_profile_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_path'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER role');
    }
}

function ensure_collection_user_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM collections LIKE 'collected_by_user_id'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE collections ADD COLUMN collected_by_user_id INT NULL AFTER note');
        $pdo->exec('ALTER TABLE collections ADD INDEX idx_collections_collected_by_user (collected_by_user_id)');
    }
}

function ensure_collection_payment_ref_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM collections LIKE 'payment_ref'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE collections ADD COLUMN payment_ref VARCHAR(50) NULL AFTER collected_by_user_id');
        $pdo->exec('ALTER TABLE collections ADD INDEX idx_collections_payment_ref (payment_ref)');
    }
}

function ensure_loan_assignment_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM loans LIKE 'assigned_user_id'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE loans ADD COLUMN assigned_user_id INT NULL AFTER customer_id');
        $pdo->exec('ALTER TABLE loans ADD INDEX idx_loans_assigned_user (assigned_user_id)');
    }
}

function ensure_loan_interest_rate_type_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM loans LIKE 'interest_rate_type'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec("ALTER TABLE loans ADD COLUMN interest_rate_type ENUM('amount_based','monthly') NOT NULL DEFAULT 'amount_based' AFTER interest_rate");
        $pdo->exec("UPDATE loans SET interest_rate_type = 'amount_based' WHERE interest_rate_type IS NULL OR interest_rate_type = ''");
    }
}

function ensure_loan_interest_rate_months_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM loans LIKE 'interest_rate_months'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE loans ADD COLUMN interest_rate_months INT NOT NULL DEFAULT 1 AFTER interest_rate_type');
        $pdo->exec('UPDATE loans SET interest_rate_months = 1 WHERE interest_rate_months IS NULL OR interest_rate_months < 1');
    }
}

function ensure_customer_documents_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customer_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            uploaded_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customer_documents_customer_id (customer_id),
            INDEX idx_customer_documents_uploaded_by (uploaded_by_user_id),
            CONSTRAINT fk_customer_documents_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            CONSTRAINT fk_customer_documents_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    );
}

function ensure_customer_note_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM customers LIKE 'note'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE customers ADD COLUMN note TEXT NULL AFTER address');
    }
}

function ensure_system_settings_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by_user_id INT NULL,
            INDEX idx_system_settings_updated_by (updated_by_user_id),
            CONSTRAINT fk_system_settings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    );
}

function ensure_activity_logs_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS activity_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT NULL,
            actor_role VARCHAR(20) NULL,
            action_key VARCHAR(80) NOT NULL,
            description VARCHAR(255) NOT NULL,
            meta_json LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_logs_created_at (created_at),
            INDEX idx_activity_logs_actor (actor_user_id),
            INDEX idx_activity_logs_action_key (action_key),
            CONSTRAINT fk_activity_logs_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    );
}

function system_settings_all(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM system_settings');
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    return $map;
}

function system_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1');
    $stmt->execute(['setting_key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string) $value;
}

function system_settings_save(PDO $pdo, array $settings, ?int $updatedByUserId = null): void
{
    if (empty($settings)) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
         VALUES (:setting_key, :setting_value, :updated_by_user_id)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by_user_id = VALUES(updated_by_user_id)'
    );

    foreach ($settings as $key => $value) {
        $stmt->bindValue(':setting_key', (string) $key, PDO::PARAM_STR);
        $stmt->bindValue(':setting_value', (string) $value, PDO::PARAM_STR);
        $stmt->bindValue(
            ':updated_by_user_id',
            $updatedByUserId !== null && $updatedByUserId > 0 ? $updatedByUserId : null,
            $updatedByUserId !== null && $updatedByUserId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL
        );
        $stmt->execute();
    }
}

function normalize_uploaded_files(?array $filesInput): array
{
    if ($filesInput === null || !isset($filesInput['name'])) {
        return [];
    }

    if (!is_array($filesInput['name'])) {
        return [[
            'name' => (string) ($filesInput['name'] ?? ''),
            'type' => (string) ($filesInput['type'] ?? ''),
            'tmp_name' => (string) ($filesInput['tmp_name'] ?? ''),
            'error' => (int) ($filesInput['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($filesInput['size'] ?? 0),
        ]];
    }

    $files = [];
    foreach ($filesInput['name'] as $i => $name) {
        $files[] = [
            'name' => (string) $name,
            'type' => (string) ($filesInput['type'][$i] ?? ''),
            'tmp_name' => (string) ($filesInput['tmp_name'][$i] ?? ''),
            'error' => (int) ($filesInput['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($filesInput['size'][$i] ?? 0),
        ];
    }

    return $files;
}

function customer_documents_upload_dir_abs(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'customer_docs';
}

function customer_documents_upload_dir_rel(): string
{
    return 'uploads/customer_docs';
}

function business_icon_upload_dir_abs(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'business_icons';
}

function business_icon_upload_dir_rel(): string
{
    return 'uploads/business_icons';
}

function business_icon_path(PDO $pdo): string
{
    $path = trim(system_setting($pdo, 'business_icon_path', ''));
    if ($path === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $path);
    $requiredPrefix = business_icon_upload_dir_rel() . '/';
    if (!str_starts_with(strtolower($normalized), strtolower($requiredPrefix))) {
        return '';
    }

    $absPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (!is_file($absPath)) {
        return '';
    }

    return $normalized;
}

function ensure_customer_docs_guard_file(string $uploadDirAbs): void
{
    $guardPath = $uploadDirAbs . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($guardPath)) {
        return;
    }

    $guardContents = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($guardPath, $guardContents);
}

function store_customer_documents(PDO $pdo, int $customerId, string $customerCode, ?array $filesInput, int $uploadedByUserId = 0): int
{
    $files = normalize_uploaded_files($filesInput);
    if (empty($files)) {
        return 0;
    }

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];
    $maxBytes = 10 * 1024 * 1024; // 10MB per file

    $uploadDirAbs = customer_documents_upload_dir_abs();
    if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
        throw new RuntimeException('Failed to create upload directory.');
    }
    ensure_customer_docs_guard_file($uploadDirAbs);

    $insertStmt = $pdo->prepare(
        'INSERT INTO customer_documents (customer_id, original_name, stored_name, file_path, mime_type, file_size, uploaded_by_user_id)
         VALUES (:customer_id, :original_name, :stored_name, :file_path, :mime_type, :file_size, :uploaded_by_user_id)'
    );

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $movedAbsPaths = [];
    $storedCount = 0;

    try {
        foreach ($files as $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('One or more documents failed to upload.');
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('Invalid uploaded file.');
            }

            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('Each document must be between 1 byte and 10MB.');
            }

            $mime = (string) $finfo->file($tmpName);
            $ext = $allowedMimes[$mime] ?? null;
            if ($ext === null) {
                throw new RuntimeException('Only image files (JPG, PNG, WEBP, GIF) and PDF are allowed.');
            }

            $originalName = trim((string) ($file['name'] ?? ''));
            $originalName = $originalName === '' ? ('document.' . $ext) : basename($originalName);

            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            $safeBase = preg_replace('/[^\w\-. ]+/u', '_', $baseName) ?: 'document';
            $safeBase = trim((string) $safeBase);
            $safeExt = $ext;

            $candidateName = $safeBase . '.' . $safeExt;
            $absPath = $uploadDirAbs . DIRECTORY_SEPARATOR . $candidateName;

            if (is_file($absPath)) {
                try {
                    $random = bin2hex(random_bytes(4));
                } catch (Throwable) {
                    $random = str_replace('.', '', uniqid('', true));
                }
                $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $customerCode) ?: 'CUST';
                $candidateName = $safeBase . '_' . strtolower($safeCode) . '_' . date('YmdHis') . '_' . $random . '.' . $safeExt;
                $absPath = $uploadDirAbs . DIRECTORY_SEPARATOR . $candidateName;
            }

            $storedName = $candidateName;
            $relPath = customer_documents_upload_dir_rel() . '/' . $storedName;

            if (!move_uploaded_file($tmpName, $absPath)) {
                throw new RuntimeException('Failed to store uploaded document.');
            }
            $movedAbsPaths[] = $absPath;

            $insertStmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $insertStmt->bindValue(':original_name', $originalName, PDO::PARAM_STR);
            $insertStmt->bindValue(':stored_name', $storedName, PDO::PARAM_STR);
            $insertStmt->bindValue(':file_path', $relPath, PDO::PARAM_STR);
            $insertStmt->bindValue(':mime_type', $mime, PDO::PARAM_STR);
            $insertStmt->bindValue(':file_size', $size, PDO::PARAM_INT);
            $insertStmt->bindValue(':uploaded_by_user_id', $uploadedByUserId > 0 ? $uploadedByUserId : null, $uploadedByUserId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertStmt->execute();

            $storedCount++;
        }
    } catch (Throwable $e) {
        foreach ($movedAbsPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        throw $e;
    }

    return $storedCount;
}

function readable_file_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

function superadmin_count(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'");
    return (int) $stmt->fetchColumn();
}

function has_superadmin(PDO $pdo): bool
{
    return superadmin_count($pdo) > 0;
}

function current_user(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'full_name' => (string) $user['full_name'],
        'username' => (string) $user['username'],
        'email' => isset($user['email']) ? (string) $user['email'] : '',
        'role' => (string) $user['role'],
        'status' => isset($user['status']) ? (string) $user['status'] : 'active',
        'avatar_path' => isset($user['avatar_path']) ? (string) $user['avatar_path'] : '',
    ];
}

function create_password_reset_token(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable) {
        return null;
    }
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('now'))->add(new DateInterval('PT1H'))->format('Y-m-d H:i:s');
    $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $stmt = $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip)
         VALUES (:user_id, :token_hash, :expires_at, :requested_ip)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
        'requested_ip' => $ip !== '' ? $ip : null,
    ]);

    return $token;
}

function password_reset_row_by_token(PDO $pdo, string $rawToken): ?array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '') {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare(
        "SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, u.id AS u_id, u.full_name, u.username, u.email, u.status
         FROM password_reset_tokens prt
         JOIN users u ON u.id = prt.user_id
         WHERE prt.token_hash = :token_hash
         LIMIT 1"
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (!empty($row['used_at'])) {
        return null;
    }

    $expires = strtotime((string) $row['expires_at']);
    if ($expires === false || $expires < time()) {
        return null;
    }

    return $row;
}

function mark_password_reset_token_used(PDO $pdo, int $tokenId, int $userId): void
{
    if ($tokenId > 0) {
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')->execute(['id' => $tokenId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    }
}

function app_mail_config_value(string $envKey, string $localKey, string $default = ''): string
{
    $env = trim(env_value($envKey, ''));
    if ($env !== '') {
        return $env;
    }

    if (defined('LOCAL_APP_CONFIG')) {
        $local = constant('LOCAL_APP_CONFIG');
        if (is_array($local) && array_key_exists($localKey, $local)) {
            $value = $local[$localKey];
            if (is_string($value) || is_int($value) || is_float($value)) {
                $str = trim((string) $value);
                if ($str !== '') {
                    return $str;
                }
            }
        }
    }

    return $default;
}

function smtp_write_line($socket, string $line): bool
{
    return fwrite($socket, $line . "\r\n") !== false;
}

function smtp_read_response($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        // RFC-style multiline replies continue with "123-"
        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }

    return $response;
}

function smtp_expect_code(string $response, array $expected): bool
{
    if (preg_match('/^(\d{3})/m', $response, $matches) !== 1) {
        return false;
    }

    $code = (int) $matches[1];
    return in_array($code, $expected, true);
}

function smtp_send_mail_message(array $config, string $toEmail, string $subject, string $message, string $fromEmail, string $fromName): bool
{
    $host = trim((string) ($config['host'] ?? ''));
    $port = (int) ($config['port'] ?? 0);
    $encryption = strtolower(trim((string) ($config['encryption'] ?? '')));
    $username = trim((string) ($config['username'] ?? ''));
    $password = (string) ($config['password'] ?? '');

    if ($host === '' || $port <= 0 || $username === '' || $password === '') {
        return false;
    }

    $transportHost = $host;
    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $host;
    }

    $socket = @stream_socket_client(
        $transportHost . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        error_log('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($socket, 20);

    try {
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [220])) {
            return false;
        }

        $ehloHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        if (!smtp_write_line($socket, 'EHLO ' . $ehloHost)) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [250])) {
            return false;
        }

        if ($encryption === 'tls') {
            if (!smtp_write_line($socket, 'STARTTLS')) {
                return false;
            }
            $response = smtp_read_response($socket);
            if (!smtp_expect_code($response, [220])) {
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return false;
            }
            if (!smtp_write_line($socket, 'EHLO ' . $ehloHost)) {
                return false;
            }
            $response = smtp_read_response($socket);
            if (!smtp_expect_code($response, [250])) {
                return false;
            }
        }

        if (!smtp_write_line($socket, 'AUTH LOGIN')) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [334])) {
            return false;
        }

        if (!smtp_write_line($socket, base64_encode($username))) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [334])) {
            return false;
        }

        if (!smtp_write_line($socket, base64_encode($password))) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [235])) {
            return false;
        }

        if (!smtp_write_line($socket, 'MAIL FROM:<' . $fromEmail . '>')) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [250])) {
            return false;
        }

        if (!smtp_write_line($socket, 'RCPT TO:<' . $toEmail . '>')) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [250, 251])) {
            return false;
        }

        if (!smtp_write_line($socket, 'DATA')) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [354])) {
            return false;
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [];
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        $headers[] = 'To: <' . $toEmail . '>';
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $message);
        $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody) ?? $normalizedBody;
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $normalizedBody) . "\r\n.";

        if (!smtp_write_line($socket, $payload)) {
            return false;
        }
        $response = smtp_read_response($socket);
        if (!smtp_expect_code($response, [250])) {
            return false;
        }

        smtp_write_line($socket, 'QUIT');
        return true;
    } catch (Throwable $e) {
        error_log('SMTP send exception: ' . $e->getMessage());
        return false;
    } finally {
        fclose($socket);
    }
}

function send_password_reset_email(PDO $pdo, string $toEmail, string $fullName, string $resetLink): bool
{
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $businessName = trim(system_setting($pdo, 'business_name', APP_NAME));
    $subject = $businessName . ' - Password Reset';

    $safeName = trim($fullName) !== '' ? trim($fullName) : 'User';
    $message = "Hello {$safeName},\n\n";
    $message .= "We received a request to reset your password.\n";
    $message .= "Use this secure link to set a new password:\n{$resetLink}\n\n";
    $message .= "This link will expire in 1 hour.\n";
    $message .= "If you did not request this, you can ignore this email.\n\n";
    $message .= "{$businessName}";

    $fromEmail = trim(app_mail_config_value('MAIL_FROM_EMAIL', 'mail_from_email', ''));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/:\d+$/', '', $host) ?? 'localhost';
        $fromEmail = 'no-reply@' . $host;
    }

    $fromName = trim(app_mail_config_value('MAIL_FROM_NAME', 'mail_from_name', $businessName));
    $mailDriver = strtolower(app_mail_config_value('MAIL_DRIVER', 'mail_driver', 'mail'));

    if ($mailDriver === 'smtp') {
        $smtpConfig = [
            'host' => app_mail_config_value('MAIL_HOST', 'mail_host', ''),
            'port' => (int) app_mail_config_value('MAIL_PORT', 'mail_port', '465'),
            'encryption' => app_mail_config_value('MAIL_ENCRYPTION', 'mail_encryption', 'ssl'),
            'username' => app_mail_config_value('MAIL_USERNAME', 'mail_username', ''),
            'password' => app_mail_config_value('MAIL_PASSWORD', 'mail_password', ''),
        ];

        $smtpSent = smtp_send_mail_message($smtpConfig, $toEmail, $subject, $message, $fromEmail, $fromName);
        if ($smtpSent) {
            return true;
        }
        error_log('SMTP send failed for password reset email; falling back to mail().');
    }

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;

    return @mail($toEmail, $subject, $message, implode("\r\n", $headers));
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function log_activity(PDO $pdo, string $actionKey, string $description, array $meta = [], ?int $actorUserId = null): void
{
    try {
        $authUser = current_user();
        $userId = $actorUserId;
        if ($userId === null && isset($authUser['id'])) {
            $userId = (int) $authUser['id'];
        }

        $role = null;
        if (isset($authUser['role'])) {
            $role = (string) $authUser['role'];
        }

        $metaJson = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) {
            $metaJson = null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (actor_user_id, actor_role, action_key, description, meta_json, ip_address)
             VALUES (:actor_user_id, :actor_role, :action_key, :description, :meta_json, :ip_address)'
        );
        $stmt->bindValue(':actor_user_id', $userId !== null && $userId > 0 ? $userId : null, $userId !== null && $userId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':actor_role', $role, $role !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':action_key', mb_substr($actionKey, 0, 80), PDO::PARAM_STR);
        $stmt->bindValue(':description', mb_substr($description, 0, 255), PDO::PARAM_STR);
        $stmt->bindValue(':meta_json', $metaJson, $metaJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':ip_address', mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), PDO::PARAM_STR);
        $stmt->execute();
    } catch (Throwable) {
        // Activity logging should never block business operations.
    }
}

function current_user_role(): ?string
{
    $user = current_user();
    return $user['role'] ?? null;
}

function has_role(array $roles): bool
{
    $role = current_user_role();
    return $role !== null && in_array($role, $roles, true);
}

function can_manage_users(): bool
{
    return has_role(['superadmin', 'admin']);
}

function is_collector_role(?string $role): bool
{
    return in_array((string) $role, ['collector', 'collector_l1', 'collector_l2'], true);
}

function can_manage_loans(): bool
{
    return has_role(['superadmin', 'admin', 'collector_l2', 'collector']);
}

function can_view_all_customers(): bool
{
    return has_role(['superadmin', 'admin', 'collector_l2', 'collector']);
}

function can_access_customer(PDO $pdo, int $customerId, ?array $viewer = null): bool
{
    if ($customerId <= 0) {
        return false;
    }

    $viewer = $viewer ?? current_user();
    if (!$viewer) {
        return false;
    }

    $viewerRole = (string) ($viewer['role'] ?? '');
    if (in_array($viewerRole, ['superadmin', 'admin', 'collector_l2', 'collector'], true)) {
        return true;
    }

    $viewerId = (int) ($viewer['id'] ?? 0);
    if ($viewerId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM customers c
         WHERE c.id = :customer_id
           AND (
                EXISTS (
                    SELECT 1
                    FROM loans l_assigned
                    WHERE l_assigned.customer_id = c.id
                      AND l_assigned.assigned_user_id = :viewer_user_id
                )
                OR EXISTS (
                    SELECT 1
                    FROM loans l_unassigned
                    WHERE l_unassigned.customer_id = c.id
                      AND l_unassigned.assigned_user_id IS NULL
                )
                OR NOT EXISTS (
                    SELECT 1
                    FROM loans l_any
                    WHERE l_any.customer_id = c.id
                )
           )
         LIMIT 1"
    );
    $stmt->execute([
        'customer_id' => $customerId,
        'viewer_user_id' => $viewerId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function require_customer_access(PDO $pdo, int $customerId, string $redirectPath = 'pages/customers.php'): void
{
    if (!can_access_customer($pdo, $customerId)) {
        set_flash('error', 'You do not have permission to access that customer.');
        redirect($redirectPath);
    }
}

function require_roles(array $roles, string $redirectPath = 'index.php'): void
{
    if (!has_role($roles)) {
        set_flash('error', 'You do not have permission to access that page.');
        redirect($redirectPath);
    }
}

function today_collection_goal(PDO $pdo, ?array $viewer = null): array
{
    $viewerRole = (string) ($viewer['role'] ?? '');
    $viewerId = (int) ($viewer['id'] ?? 0);
    $isCollectorScope = is_collector_role($viewerRole) && $viewerId > 0;

    if (!$isCollectorScope) {
        $remainingStmt = $pdo->query("SELECT COALESCE(SUM(due_amount - paid_amount), 0) FROM loan_installments WHERE due_date <= CURDATE() AND status IN ('pending', 'partial', 'overdue')");
        $remainingNow = (float) $remainingStmt->fetchColumn();

        $collectedStmt = $pdo->query(
            "SELECT COALESCE(SUM(c.amount), 0)
             FROM collections c
             JOIN loan_installments li ON li.id = c.installment_id
             WHERE c.collected_on = CURDATE() AND li.due_date <= CURDATE()"
        );
        $collectedTowardGoalToday = (float) $collectedStmt->fetchColumn();
    } else {
        $scope = '(l.assigned_user_id = :viewer_user_id OR l.assigned_user_id IS NULL)';

        $remainingStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(li.due_amount - li.paid_amount), 0)
             FROM loan_installments li
             JOIN loans l ON l.id = li.loan_id
             WHERE li.due_date <= CURDATE()
               AND li.status IN ('pending', 'partial', 'overdue')
               AND {$scope}"
        );
        $remainingStmt->execute(['viewer_user_id' => $viewerId]);
        $remainingNow = (float) $remainingStmt->fetchColumn();

        $collectedStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(c.amount), 0)
             FROM collections c
             JOIN loan_installments li ON li.id = c.installment_id
             JOIN loans l ON l.id = c.loan_id
             WHERE c.collected_on = CURDATE()
               AND li.due_date <= CURDATE()
               AND {$scope}"
        );
        $collectedStmt->execute(['viewer_user_id' => $viewerId]);
        $collectedTowardGoalToday = (float) $collectedStmt->fetchColumn();
    }

    // Goal baseline = what was due at start of day = still remaining + collected today toward due/overdue.
    $target = $remainingNow + $collectedTowardGoalToday;
    $collected = $collectedTowardGoalToday;
    $remaining = $remainingNow;
    $percentage = $target > 0 ? min(100, ($collected / $target) * 100) : 0;

    return [
        'target' => $target,
        'collected' => $collected,
        'remaining' => $remaining,
        'percentage' => $percentage,
    ];
}

function collections_30day_trend(PDO $pdo, ?array $viewer = null): array
{
    $days = 30;
    $start = (new DateTimeImmutable(today()))->sub(new DateInterval('P' . ($days - 1) . 'D'))->format('Y-m-d');
    $viewerRole = (string) ($viewer['role'] ?? '');
    $viewerId = (int) ($viewer['id'] ?? 0);
    $isCollectorScope = is_collector_role($viewerRole) && $viewerId > 0;

    if (!$isCollectorScope) {
        $collectedStmt = $pdo->prepare(
            "SELECT collected_on AS d, COALESCE(SUM(amount), 0) AS total
             FROM collections
             WHERE collected_on >= :start_date
             GROUP BY collected_on"
        );
        $collectedStmt->execute(['start_date' => $start]);
        $collectedRows = $collectedStmt->fetchAll();

        $targetStmt = $pdo->prepare(
            "SELECT due_date AS d, COALESCE(SUM(due_amount), 0) AS total
             FROM loan_installments
             WHERE due_date >= :start_date
             GROUP BY due_date"
        );
        $targetStmt->execute(['start_date' => $start]);
        $targetRows = $targetStmt->fetchAll();
    } else {
        $scope = '(l.assigned_user_id = :viewer_user_id OR l.assigned_user_id IS NULL)';

        $collectedStmt = $pdo->prepare(
            "SELECT c.collected_on AS d, COALESCE(SUM(c.amount), 0) AS total
             FROM collections c
             JOIN loans l ON l.id = c.loan_id
             WHERE c.collected_on >= :start_date
               AND {$scope}
             GROUP BY c.collected_on"
        );
        $collectedStmt->execute([
            'start_date' => $start,
            'viewer_user_id' => $viewerId,
        ]);
        $collectedRows = $collectedStmt->fetchAll();

        $targetStmt = $pdo->prepare(
            "SELECT li.due_date AS d, COALESCE(SUM(li.due_amount), 0) AS total
             FROM loan_installments li
             JOIN loans l ON l.id = li.loan_id
             WHERE li.due_date >= :start_date
               AND {$scope}
             GROUP BY li.due_date"
        );
        $targetStmt->execute([
            'start_date' => $start,
            'viewer_user_id' => $viewerId,
        ]);
        $targetRows = $targetStmt->fetchAll();
    }

    $collectedMap = [];
    foreach ($collectedRows as $row) {
        $collectedMap[(string) $row['d']] = (float) $row['total'];
    }

    $targetMap = [];
    foreach ($targetRows as $row) {
        $targetMap[(string) $row['d']] = (float) $row['total'];
    }

    $labels = [];
    $collected = [];
    $target = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $date = (new DateTimeImmutable(today()))->sub(new DateInterval('P' . $i . 'D'));
        $key = $date->format('Y-m-d');
        $labels[] = $date->format('M d');
        $collected[] = $collectedMap[$key] ?? 0.0;
        $target[] = $targetMap[$key] ?? 0.0;
    }

    return [
        'labels' => $labels,
        'collected' => $collected,
        'target' => $target,
        'collected_total' => array_sum($collected),
        'target_total' => array_sum($target),
        'max_value' => max([1.0, ...$collected, ...$target]),
    ];
}

function dashboard_user_goals(PDO $pdo, ?array $viewer = null): array
{
    $viewerRole = (string) ($viewer['role'] ?? '');
    $viewerId = (int) ($viewer['id'] ?? 0);
    $isCollectorScope = is_collector_role($viewerRole) && $viewerId > 0;

    if ($isCollectorScope) {
        $usersStmt = $pdo->prepare("SELECT id, full_name, username, role FROM users WHERE id = :id LIMIT 1");
        $usersStmt->execute(['id' => $viewerId]);
        $users = $usersStmt->fetchAll();
    } else {
        $users = $pdo->query("SELECT id, full_name, username, role FROM users WHERE role IN ('superadmin', 'admin', 'collector', 'collector_l1', 'collector_l2') ORDER BY FIELD(role, 'collector_l1', 'collector_l2', 'collector', 'admin', 'superadmin'), full_name ASC")->fetchAll();
    }

    $goalRows = [];
    foreach ($users as $user) {
        $uid = (int) $user['id'];
        $goalRows['user_' . $uid] = [
            'id' => $uid,
            'full_name' => (string) $user['full_name'],
            'role' => (string) $user['role'],
            'role_label' => role_display_name((string) $user['role']),
            'remaining' => 0.0,
            'collected' => 0.0,
            'target' => 0.0,
            'percentage' => 0.0,
        ];
    }

    $goalRows['unassigned'] = [
        'id' => null,
        'full_name' => 'Unassigned',
        'role' => 'unassigned',
        'role_label' => 'Unassigned',
        'remaining' => 0.0,
        'collected' => 0.0,
        'target' => 0.0,
        'percentage' => 0.0,
    ];

    if ($isCollectorScope) {
        $remainingStmt = $pdo->prepare(
            "SELECT l.assigned_user_id, COALESCE(SUM(li.due_amount - li.paid_amount), 0) AS remaining
             FROM loan_installments li
             JOIN loans l ON l.id = li.loan_id
             WHERE li.due_date <= CURDATE()
               AND li.status IN ('pending', 'partial', 'overdue')
               AND (l.assigned_user_id = :viewer_user_id OR l.assigned_user_id IS NULL)
             GROUP BY l.assigned_user_id"
        );
        $remainingStmt->execute(['viewer_user_id' => $viewerId]);
        $remainingRows = $remainingStmt->fetchAll();
    } else {
        $remainingRows = $pdo->query(
            "SELECT l.assigned_user_id, COALESCE(SUM(li.due_amount - li.paid_amount), 0) AS remaining
             FROM loan_installments li
             JOIN loans l ON l.id = li.loan_id
             WHERE li.due_date <= CURDATE()
               AND li.status IN ('pending', 'partial', 'overdue')
             GROUP BY l.assigned_user_id"
        )->fetchAll();
    }

    foreach ($remainingRows as $row) {
        $key = $row['assigned_user_id'] === null ? 'unassigned' : 'user_' . (int) $row['assigned_user_id'];
        if (!isset($goalRows[$key])) {
            continue;
        }
        $goalRows[$key]['remaining'] = (float) $row['remaining'];
    }

    if ($isCollectorScope) {
        $collectedStmt = $pdo->prepare(
            "SELECT l.assigned_user_id, COALESCE(SUM(c.amount), 0) AS collected
             FROM collections c
             JOIN loan_installments li ON li.id = c.installment_id
             JOIN loans l ON l.id = c.loan_id
             WHERE c.collected_on = CURDATE()
               AND li.due_date <= CURDATE()
               AND (l.assigned_user_id = :viewer_user_id OR l.assigned_user_id IS NULL)
             GROUP BY l.assigned_user_id"
        );
        $collectedStmt->execute(['viewer_user_id' => $viewerId]);
        $collectedRows = $collectedStmt->fetchAll();
    } else {
        $collectedRows = $pdo->query(
            "SELECT l.assigned_user_id, COALESCE(SUM(c.amount), 0) AS collected
             FROM collections c
             JOIN loan_installments li ON li.id = c.installment_id
             JOIN loans l ON l.id = c.loan_id
             WHERE c.collected_on = CURDATE()
               AND li.due_date <= CURDATE()
             GROUP BY l.assigned_user_id"
        )->fetchAll();
    }

    foreach ($collectedRows as $row) {
        $key = $row['assigned_user_id'] === null ? 'unassigned' : 'user_' . (int) $row['assigned_user_id'];
        if (!isset($goalRows[$key])) {
            continue;
        }
        $goalRows[$key]['collected'] = (float) $row['collected'];
    }

    $totalTarget = 0.0;
    foreach ($goalRows as $key => $goal) {
        $target = (float) $goal['remaining'] + (float) $goal['collected'];
        $percentage = $target > 0 ? min(100, (((float) $goal['collected']) / $target) * 100) : 0.0;
        $goalRows[$key]['target'] = $target;
        $goalRows[$key]['percentage'] = $percentage;
        $totalTarget += $target;
        unset($goalRows[$key]['remaining']);
    }

    $goals = array_values($goalRows);
    usort($goals, static function (array $a, array $b): int {
        if ($a['role'] === 'unassigned' && $b['role'] !== 'unassigned') {
            return 1;
        }
        if ($b['role'] === 'unassigned' && $a['role'] !== 'unassigned') {
            return -1;
        }
        return $b['percentage'] <=> $a['percentage'];
    });

    return [
        'total_target' => $totalTarget,
        'users' => $goals,
    ];
}

function sparkline_points_scaled(array $values, float $min, float $max, int $width = 140, int $height = 46, int $padding = 4): string
{
    if (empty($values)) {
        return '';
    }

    $range = max(1.0, $max - $min);
    $count = count($values);
    $innerWidth = $width - ($padding * 2);
    $innerHeight = $height - ($padding * 2);

    $points = [];
    foreach ($values as $i => $v) {
        $x = $padding + ($count === 1 ? 0 : ($innerWidth * $i / ($count - 1)));
        $norm = (((float) $v) - $min) / $range;
        $norm = max(0.0, min(1.0, $norm));
        $y = $padding + ($innerHeight * (1 - $norm));
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    return implode(' ', $points);
}
