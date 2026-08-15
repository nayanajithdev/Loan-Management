<?php
defined('APP_NAME') || exit;
$dailyPrintRows = $dailyPrintCollections ?? $collections;
?>
<section class="daily-collections-print-report" id="daily-collections-print-report" aria-hidden="true">
    <?php require __DIR__ . '/a4_header.php'; ?>

    <div class="daily-report-content">
        <div class="daily-report-details">
            <table>
                <tr>
                    <td class="label">Collection Date</td>
                    <td class="colon">:</td>
                    <td><?= e(display_date($selectedDate)) ?></td>
                </tr>
            </table>
        </div>

        <table class="daily-report-table">
            <colgroup>
                <col style="width:53mm;">
                <col style="width:42mm;">
                <col style="width:42mm;">
                <col style="width:53mm;">
            </colgroup>
            <thead>
                <tr>
                    <th>COLLECTOR</th>
                    <th>TIME</th>
                    <th>LOAN NO</th>
                    <th>AMOUNT</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$dailyPrintRows): ?>
                    <tr>
                        <td colspan="4">No collections found for selected date.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dailyPrintRows as $row): ?>
                        <?php
                        $amountLabel = money_label($pdo, (float) $row['amount']);
                        if ((int) ($row['closed_this_payment'] ?? 0) === 1) {
                            $amountLabel = 'Closed - ' . $amountLabel;
                        }
                        ?>
                        <tr>
                            <td><?= e((string) $row['collected_by']) ?></td>
                            <td><?= e(display_time((string) $row['collected_at'])) ?></td>
                            <td><?= e((string) $row['loan_number']) ?></td>
                            <td><?= e($amountLabel) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="daily-report-total-row">
            <span class="label">Collected total</span>
            <span class="colon">:</span>
            <strong class="value"><?= e(money_label($pdo, $collectedTotal)) ?></strong>
        </div>
    </div>
</section>
