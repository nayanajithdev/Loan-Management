<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('holidays.manage');

$pageTitle = 'Holiday Mode';
$activePage = 'holiday_mode';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('pages/holiday_mode.php');

    $holidayDate = trim((string) ($_POST['holiday_date'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $currentUser = current_user();
    $userId = (int) ($currentUser['id'] ?? 0);

    try {
        $result = mark_holiday_date($pdo, $holidayDate, $note, $userId);
        $shiftedCount = (int) ($result['shifted_count'] ?? 0);
        log_activity($pdo, 'holiday.enabled', 'Holiday mode enabled for ' . $holidayDate . '.', [
            'holiday_date' => $holidayDate,
            'shifted_installments' => $shiftedCount,
            'note' => $note,
        ]);
        set_flash('success', 'Holiday mode enabled for ' . display_date($holidayDate) . '. ' . $shiftedCount . ' installments shifted.');
    } catch (Throwable $e) {
        log_activity($pdo, 'holiday.enable_failed', 'Holiday mode enable failed.', [
            'holiday_date' => $holidayDate,
            'reason' => $e->getMessage(),
        ]);
        set_flash('error', $e->getMessage());
    }

    redirect('pages/holiday_mode.php');
}

$history = holiday_history($pdo);

require __DIR__ . '/../includes/layout_start.php';
?>

<section class="holiday-layout">
    <article class="panel holiday-option-panel">
        <div class="panel-head compact-panel-head">
            <div>
                <h3 class="panel-title">Turn On Holiday Mode</h3>
                <p class="panel-subtext">Mark a non-working day and move unpaid schedules forward.</p>
            </div>
        </div>

        <form method="post" action="<?= e(url('pages/holiday_mode.php')) ?>" class="form-grid holiday-form" data-confirm="Turn on Holiday Mode for the selected date? Unpaid schedules due on or after this date will be moved forward." data-inline-confirm="1">
            <?= csrf_input() ?>
            <div class="field full">
                <label>Holiday Date</label>
                <input type="date" name="holiday_date" value="<?= e(today()) ?>" required>
                <small>Future dates can be marked anytime. Past dates are allowed only when no collections exist from that date until today.</small>
            </div>
            <div class="field full">
                <label>Note</label>
                <textarea name="note" maxlength="255" placeholder="Optional"></textarea>
            </div>
            <div class="field full">
                <button type="submit" class="btn btn-primary">Turn On Holiday Mode</button>
            </div>
        </form>

        <div class="holiday-rule-box">
            <h4>How it works</h4>
            <p>All unpaid active-loan installments due on or after the holiday are shifted to the next available collection day.</p>
            <p>Holiday dates are skipped when new schedules are generated or the next payment date is changed.</p>
        </div>
    </article>

    <article class="panel holiday-history-panel">
        <div class="panel-head compact-panel-head">
            <div>
                <h3 class="panel-title">Holiday History</h3>
                <p class="panel-subtext">Latest marked holidays.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table zebra-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Note</th>
                        <th>Added By</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($history === []): ?>
                        <tr>
                            <td colspan="4">No holidays marked yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $holiday): ?>
                            <tr>
                                <td><?= e(display_date((string) $holiday['holiday_date'])) ?></td>
                                <td><?= e((string) ($holiday['note'] ?? '-')) ?></td>
                                <td><?= e((string) ($holiday['created_by_name'] ?? 'Unknown')) ?></td>
                                <td><?= e(display_datetime((string) $holiday['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php require __DIR__ . '/../includes/layout_end.php';
