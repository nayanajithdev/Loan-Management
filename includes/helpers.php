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

function today(): string
{
    return date('Y-m-d');
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

function loan_collected_total(PDO $pdo, int $loanId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE loan_id = :loan_id');
    $stmt->execute(['loan_id' => $loanId]);

    return (float) $stmt->fetchColumn();
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
        return;
    }
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','collector','collector_l1','collector_l2') NOT NULL DEFAULT 'admin'");
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
    } else {
        $pdo->exec("ALTER TABLE loans MODIFY COLUMN interest_rate_type ENUM('amount_based','monthly') NOT NULL DEFAULT 'amount_based'");
    }

    $pdo->exec("UPDATE loans SET interest_rate_type = 'amount_based' WHERE interest_rate_type IS NULL OR interest_rate_type = ''");
}

function ensure_loan_interest_rate_months_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM loans LIKE 'interest_rate_months'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE loans ADD COLUMN interest_rate_months INT NOT NULL DEFAULT 1 AFTER interest_rate_type');
    } else {
        $pdo->exec('ALTER TABLE loans MODIFY COLUMN interest_rate_months INT NOT NULL DEFAULT 1');
    }

    $pdo->exec('UPDATE loans SET interest_rate_months = 1 WHERE interest_rate_months IS NULL OR interest_rate_months < 1');
}

function ensure_customer_assignment_schema(PDO $pdo): void
{
    $colStmt = $pdo->query("SHOW COLUMNS FROM customers LIKE 'assigned_user_id'");
    $col = $colStmt->fetch();

    if (!$col) {
        $pdo->exec('ALTER TABLE customers ADD COLUMN assigned_user_id INT NULL AFTER note');
        $pdo->exec('ALTER TABLE customers ADD INDEX idx_customers_assigned_user (assigned_user_id)');
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
            $origExt = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            $finalExt = $origExt !== '' ? $origExt : $ext;

            $safeBase = preg_replace('/[^\w\-. ]+/u', '_', $baseName) ?: 'document';
            $safeBase = trim((string) $safeBase);
            $safeExt = preg_replace('/[^a-z0-9]+/i', '', $finalExt) ?: $ext;

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
        'role' => (string) $user['role'],
    ];
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

function customer_weekly_trend(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM customers
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(created_at)"
    );
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['d']] = (int) $row['c'];
    }

    $values14 = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = (new DateTimeImmutable(today()))->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
        $values14[] = $map[$d] ?? 0;
    }

    $prev7 = array_sum(array_slice($values14, 0, 7));
    $curr7 = array_sum(array_slice($values14, 7, 7));
    $deltaPct = $prev7 > 0 ? (($curr7 - $prev7) / $prev7) * 100 : ($curr7 > 0 ? 100 : 0);

    return [
        'values' => array_slice($values14, 7, 7),
        'current_week_total' => $curr7,
        'delta_pct' => $deltaPct,
        'is_up' => $deltaPct >= 0,
    ];
}

function disbursed_weekly_trend(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(principal_amount), 0) AS a
         FROM loans
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(created_at)"
    );
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['d']] = (float) $row['a'];
    }

    $values14 = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = (new DateTimeImmutable(today()))->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
        $values14[] = $map[$d] ?? 0.0;
    }

    $prev7 = array_sum(array_slice($values14, 0, 7));
    $curr7 = array_sum(array_slice($values14, 7, 7));
    $deltaPct = $prev7 > 0 ? (($curr7 - $prev7) / $prev7) * 100 : ($curr7 > 0 ? 100 : 0);

    return [
        'values' => array_slice($values14, 7, 7),
        'current_week_total' => $curr7,
        'delta_pct' => $deltaPct,
        'is_up' => $deltaPct >= 0,
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

function sparkline_points(array $values, int $width = 140, int $height = 46, int $padding = 4): string
{
    if (empty($values)) {
        return '';
    }

    $min = min($values);
    $max = max($values);
    $range = max(1, $max - $min);
    $count = count($values);
    $innerWidth = $width - ($padding * 2);
    $innerHeight = $height - ($padding * 2);

    $points = [];
    foreach ($values as $i => $v) {
        $x = $padding + ($count === 1 ? 0 : ($innerWidth * $i / ($count - 1)));
        $norm = ($v - $min) / $range;
        $y = $padding + ($innerHeight * (1 - $norm));
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    return implode(' ', $points);
}
