<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (has_superadmin($pdo)) {
    if (is_logged_in()) {
        redirect('index.php');
    }
    redirect('login.php');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Owner | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-shell">
    <section class="auth-card">
        <h1>Setup First Owner</h1>
        <p class="auth-sub">This must be done once for first system start.</p>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('actions/auth_setup_superadmin.php')) ?>" class="form-grid auth-form-grid">
            <div class="field full">
                <label>Full Name</label>
                <input type="text" name="full_name" required autofocus>
            </div>
            <div class="field full">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="field full">
                <label>Password</label>
                <input type="password" name="password" required minlength="6">
            </div>
            <div class="field full">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required minlength="6">
            </div>
            <div class="field full" style="align-self:end;">
                <button type="submit" class="btn btn-primary">Create Owner</button>
            </div>
        </form>
    </section>
</div>
</body>
</html>
