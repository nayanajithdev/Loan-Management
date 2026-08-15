<?php
defined('APP_NAME') || exit;
$profitMonthlyPrintRows = $profitPrintRows ?? $profitRows;
?>
<section class="profit-print-report profit-monthly-print-report" id="profit-print-report" aria-hidden="true">
    <?php require __DIR__ . '/a4_header.php'; ?>

    <div class="profit-monthly-report-content">
        <div class="profit-monthly-report-details">
            <table>
                <tr>
                    <td class="label">Monthly Profit</td>
                    <td class="colon">:</td>
                    <td><?= e(display_date($profitFrom) . ' - ' . display_date($profitTo)) ?></td>
                </tr>
            </table>
        </div>

        <table class="profit-monthly-report-table">
            <colgroup>
                <col style="width:65mm;">
                <col style="width:63mm;">
                <col style="width:62mm;">
            </colgroup>
            <thead>
                <tr>
                    <th>DATE</th>
                    <th>AMOUNT</th>
                    <th>PROFIT</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$profitMonthlyPrintRows): ?>
                    <tr>
                        <td colspan="3">No profit records found for selected range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($profitMonthlyPrintRows as $row): ?>
                        <tr>
                            <td><?= e(display_date((string) $row['report_date'])) ?></td>
                            <td><?= e(money_label($pdo, (float) $row['collected_amount'])) ?></td>
                            <td><?= e(money_label($pdo, (float) $row['profit_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="profit-monthly-report-total-row">
            <span class="label">Collected amount</span>
            <span class="colon">:</span>
            <strong class="value"><?= e(money_label($pdo, $profitCollectedTotal)) ?></strong>
            <span class="label">Profit</span>
            <span class="colon">:</span>
            <strong class="value"><?= e(money_label($pdo, $profitTotal)) ?></strong>
        </div>
    </div>
</section>
