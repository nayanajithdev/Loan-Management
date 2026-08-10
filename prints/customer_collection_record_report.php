<?php
defined('APP_NAME') || exit;

if (empty($canViewLoanCollectionRecords)) {
    return;
}
?>
<section class="loan-collection-print-report" id="loan-collection-print-report" aria-hidden="true">
    <?php require __DIR__ . '/a4_header.php'; ?>

    <section class="print-report-summary">
        <div class="print-customer-block">
            <div class="print-customer-details">
                <div class="print-info-row">
                    <strong>Loan no</strong>
                    <span>:</span>
                    <p><?= e($reportLoanNumber) ?></p>
                </div>
                <div class="print-info-row">
                    <strong>Name</strong>
                    <span>:</span>
                    <p><?= e((string) $loan['full_name']) ?></p>
                </div>
                <div class="print-info-row">
                    <strong>Mobile no</strong>
                    <span>:</span>
                    <p><?= e(trim((string) ($loan['customer_phone'] ?? '')) !== '' ? (string) $loan['customer_phone'] : '-') ?></p>
                </div>
                <div class="print-info-row">
                    <strong>NIC</strong>
                    <span>:</span>
                    <p><?= e($customerNicNumber) ?></p>
                </div>
                <div class="print-info-row">
                    <strong>Address</strong>
                    <span>:</span>
                    <p><?= e(trim((string) ($loan['customer_address'] ?? '')) !== '' ? (string) $loan['customer_address'] : '-') ?></p>
                </div>
                <div class="print-info-row">
                    <strong>Loan amount</strong>
                    <span>:</span>
                    <p><?= e(money_label($pdo, (float) $loan['principal_amount'])) ?></p>
                </div>
            </div>
        </div>

        <div class="print-amount-summary">
            <div class="print-info-row">
                <strong>Loan Issue date</strong>
                <span>:</span>
                <p><?= e(display_date($issuedDate)) ?></p>
            </div>
            <div class="print-info-row">
                <strong>Loan End Date</strong>
                <span>:</span>
                <p><?= e($loanEndDate !== '' ? display_date($loanEndDate) : '-') ?></p>
            </div>
            <div class="print-info-row">
                <strong>Total Loan</strong>
                <span>:</span>
                <p><?= e(money_label($pdo, $loanTotalRepayable)) ?></p>
            </div>
            <div class="print-info-row">
                <strong>Total Payment</strong>
                <span>:</span>
                <p><?= e(money_label($pdo, $loanTotalCollected)) ?></p>
            </div>
            <div class="print-info-row print-balance-row">
                <strong>Balance</strong>
                <span>:</span>
                <p><?= e(money_label($pdo, $loanBalance)) ?></p>
            </div>
        </div>
    </section>

    <div class="single-customer-collection-grid" role="table" aria-label="Collection payments">
        <div class="single-customer-collection-row single-customer-collection-head" role="row">
            <div role="columnheader">Inst.</div>
            <div role="columnheader">Date &amp; Time</div>
            <div role="columnheader">Payment</div>
        </div>
        <?php if (!$loanCollectionReportHistory): ?>
            <div class="single-customer-collection-empty" role="row">
                No collection payments recorded.
            </div>
        <?php else: ?>
            <?php foreach ($loanCollectionReportHistory as $index => $history): ?>
                <?php
                $historyCollectedAt = trim((string) ($history['collected_at'] ?? ''));
                $historyTime = $historyCollectedAt !== '' ? display_time($historyCollectedAt) : '';
                ?>
                <div class="single-customer-collection-row" role="row">
                    <div role="cell">#<?= e((string) ($index + 1)) ?></div>
                    <div role="cell">
                        <?= e(display_date((string) $history['collected_on'])) ?>
                        <?php if ($historyTime !== ''): ?>
                            <span class="single-customer-collection-time"><?= e($historyTime) ?></span>
                        <?php endif; ?>
                    </div>
                    <div role="cell"><?= e(money_label($pdo, (float) $history['amount'])) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <footer class="print-report-footer">
        <span><?= e($reportGeneratedDate) ?></span>
        <span class="print-page-number"></span>
    </footer>
</section>
