<?php
defined('APP_NAME') || exit;
?>
<section class="profit-print-report profit-daily-print-report" id="profit-print-report" aria-hidden="true">
    <?php require __DIR__ . '/a4_header.php'; ?>

    <div class="profit-daily-report-content">
        <div class="profit-daily-report-details">
            <table>
                <tr>
                    <td class="label">Daily Profit</td>
                    <td class="colon">:</td>
                    <td><?= e(display_date($profitDate)) ?></td>
                </tr>
            </table>
        </div>

        <table class="profit-daily-report-table">
            <colgroup>
                <col style="width:34%;">
                <col style="width:33%;">
                <col style="width:33%;">
            </colgroup>
            <thead>
                <tr>
                    <th>LOAN NO</th>
                    <th>AMOUNT</th>
                    <th>PROFIT</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$profitRows): ?>
                    <tr>
                        <td colspan="3">No profit records found for selected date.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($profitRows as $row): ?>
                        <tr>
                            <td><?= e((string) $row['loan_number']) ?></td>
                            <td><?= e(money_label($pdo, (float) $row['collected_amount'])) ?></td>
                            <td><?= e(money_label($pdo, (float) $row['profit_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="profit-daily-report-total-row">
            <span class="label">Collected amount</span>
            <span class="colon">:</span>
            <strong class="value"><?= e(money_label($pdo, $profitCollectedTotal)) ?></strong>
            <span class="label">Profit</span>
            <span class="colon">:</span>
            <strong class="value"><?= e(money_label($pdo, $profitTotal)) ?></strong>
        </div>
    </div>
</section>
