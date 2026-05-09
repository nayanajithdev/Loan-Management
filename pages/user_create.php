<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_roles(['superadmin', 'admin']);

$pageTitle = 'Create User';
$activePage = 'users';

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Create User</h2>
        <a class="btn" href="<?= e(url('pages/users.php')) ?>">
            <span class="btn-icon-inline" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left-icon lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
            </span>
            Back to Users
        </a>
    </div>

    <form class="form-grid" method="post" action="<?= e(url('actions/user_save.php')) ?>">
        <?= csrf_input() ?>
        <div class="field">
            <label>Full Name</label>
            <input type="text" name="full_name" required>
        </div>
        <div class="field">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>
        <div class="field">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>
        <div class="field">
            <label>Role</label>
            <select name="role" required>
                <option value="admin">Manager</option>
                <option value="collector_l2">Collector L2</option>
                <option value="collector_l1">Collector L1</option>
            </select>
        </div>
        <div class="field">
            <label>Password</label>
            <input type="password" name="password" minlength="6" required>
        </div>
        <div class="field">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" minlength="6" required>
        </div>
        <input type="hidden" name="status" value="active">
        <div class="field full form-actions">
            <button type="submit" class="btn btn-primary">Create User</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
