<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('index.php');
}

$superadminAvailable = has_superadmin($pdo);
$faviconPath = business_icon_path($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= e(APP_NAME) ?></title>
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
        <h1><?= e(APP_NAME) ?></h1>
        <p class="auth-sub">Login to continue</p>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$superadminAvailable): ?>
            <div class="flash flash-error">No owner found. Create first owner user to start.</div>
            <a class="btn btn-primary" href="<?= e(url('setup_superadmin.php')) ?>">Create First Owner</a>
        <?php else: ?>
            <form method="post" action="<?= e(url('actions/auth_login.php')) ?>" class="form-grid auth-form-grid">
                <?= csrf_input() ?>
                <div class="field full">
                    <label>Username</label>
                    <input type="text" name="username" required autofocus>
                </div>
                <div class="field full">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="field full" style="align-self:end;">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
