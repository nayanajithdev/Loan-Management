<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('index.php');
}

$faviconPath = business_icon_path($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?= e(APP_NAME) ?></title>
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
        <h1>Forgot Password</h1>
        <p class="auth-sub">Enter your user email to receive a reset link.</p>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('actions/auth_forgot_password.php')) ?>" class="form-grid auth-form-grid">
            <?= csrf_input() ?>
            <div class="field full">
                <label>Email</label>
                <input type="email" name="email" required autofocus>
            </div>
            <div class="field full" style="align-self:end;">
                <button type="submit" class="btn btn-primary">Send Reset Link</button>
            </div>
            <div class="field full auth-links-row">
                <a class="auth-link" href="<?= e(url('login.php')) ?>">Back to Login</a>
            </div>
        </form>
    </section>
</div>
</body>
</html>

