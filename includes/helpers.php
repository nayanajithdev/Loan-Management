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

function dashboard_stats(PDO $pdo): array
{
    refresh_overdue_installments($pdo);

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
    $totals['today_pending_amount'] = $todayPendingOnly + $totals['overdue_amount'];

    return $totals;
}

function ensure_user_schema(PDO $pdo): void
{
    $roleColStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $roleColumn = $roleColStmt->fetch();

    if (!$roleColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('superadmin','admin','collector') NOT NULL DEFAULT 'admin' AFTER password_hash");
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

function require_roles(array $roles, string $redirectPath = 'index.php'): void
{
    if (!has_role($roles)) {
        set_flash('error', 'You do not have permission to access that page.');
        redirect($redirectPath);
    }
}

function today_collection_goal(PDO $pdo): array
{
    $remainingStmt = $pdo->query("SELECT COALESCE(SUM(due_amount - paid_amount), 0) FROM loan_installments WHERE due_date <= CURDATE() AND status IN ('pending', 'partial', 'overdue')");
    $remainingNow = (float) $remainingStmt->fetchColumn();

    $collectedStmt = $pdo->query(
        "SELECT COALESCE(SUM(c.amount), 0)
         FROM collections c
         JOIN loan_installments li ON li.id = c.installment_id
         WHERE c.collected_on = CURDATE() AND li.due_date <= CURDATE()"
    );
    $collectedTowardGoalToday = (float) $collectedStmt->fetchColumn();

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

function collections_30day_trend(PDO $pdo): array
{
    $days = 30;
    $start = (new DateTimeImmutable(today()))->sub(new DateInterval('P' . ($days - 1) . 'D'))->format('Y-m-d');

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

function dashboard_user_goals(PDO $pdo): array
{
    $users = $pdo->query("SELECT id, full_name, username, role FROM users WHERE role IN ('superadmin', 'admin', 'collector') ORDER BY FIELD(role, 'collector', 'admin', 'superadmin'), full_name ASC")->fetchAll();
    $goalRows = [];
    foreach ($users as $user) {
        $uid = (int) $user['id'];
        $goalRows['user_' . $uid] = [
            'id' => $uid,
            'full_name' => (string) $user['full_name'],
            'role' => (string) $user['role'],
            'role_label' => ucfirst((string) $user['role']),
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

    $remainingRows = $pdo->query(
        "SELECT l.assigned_user_id, COALESCE(SUM(li.due_amount - li.paid_amount), 0) AS remaining
         FROM loan_installments li
         JOIN loans l ON l.id = li.loan_id
         WHERE li.due_date <= CURDATE()
           AND li.status IN ('pending', 'partial', 'overdue')
         GROUP BY l.assigned_user_id"
    )->fetchAll();

    foreach ($remainingRows as $row) {
        $key = $row['assigned_user_id'] === null ? 'unassigned' : 'user_' . (int) $row['assigned_user_id'];
        if (!isset($goalRows[$key])) {
            continue;
        }
        $goalRows[$key]['remaining'] = (float) $row['remaining'];
    }

    $collectedRows = $pdo->query(
        "SELECT l.assigned_user_id, COALESCE(SUM(c.amount), 0) AS collected
         FROM collections c
         JOIN loan_installments li ON li.id = c.installment_id
         JOIN loans l ON l.id = c.loan_id
         WHERE c.collected_on = CURDATE()
           AND li.due_date <= CURDATE()
         GROUP BY l.assigned_user_id"
    )->fetchAll();

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
