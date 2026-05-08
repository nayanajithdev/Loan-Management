<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('index.php');
}

$token = trim((string) ($_GET['token'] ?? ''));
$tokenRow = $token !== '' ? password_reset_row_by_token($pdo, $token) : null;
$isValidToken = $tokenRow !== null;
$faviconPath = business_icon_path($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?= e(APP_NAME) ?></title>
    <?php if ($faviconPath !== ''): ?>
        <link rel="icon" href="<?= e(url($faviconPath)) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-shell">
    <section class="auth-card">
        <h1>Reset Password</h1>
        <?php if ($isValidToken): ?>
            <p class="auth-sub">Set a new password for <?= e((string) $tokenRow['username']) ?>.</p>
        <?php else: ?>
            <p class="auth-sub">This reset link is invalid or expired.</p>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($isValidToken): ?>
            <form method="post" action="<?= e(url('actions/auth_reset_password.php')) ?>" class="form-grid auth-form-grid">
                <?= csrf_input() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field full">
                    <label>New Password</label>
                    <input type="password" name="new_password" minlength="6" required autofocus>
                </div>
                <div class="field full">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" minlength="6" required>
                </div>
                <div class="field full" style="align-self:end;">
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
            </form>
        <?php endif; ?>

        <div class="auth-links-row" style="margin-top:10px;">
            <a class="auth-link" href="<?= e(url('forgot_password.php')) ?>">Request new link</a>
            <a class="auth-link" href="<?= e(url('login.php')) ?>">Back to Login</a>
        </div>
    </section>
</div>
</body>
</html>

