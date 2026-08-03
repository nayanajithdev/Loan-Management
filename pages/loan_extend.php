<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('loans.edit', 'pages/loans.php');

$pageTitle = 'Extend Loan';
$activePage = 'loans';

$selectedLoanId = (int) ($_GET['loan_id'] ?? 0);
$currencyLabel = currency_label($pdo);

if ($selectedLoanId <= 0) {
    set_flash('error', 'Open a loan first, then use Extend Loan.');
    redirect('pages/loans.php');
}

$selectedLoan = null;
$selectedPendingRows = [];
$selectedCollectionTotal = 0.0;
$selectedOutstanding = 0.0;
$selectedRemainingCount = 0;
$selectedMaxDueDate = '';
$selectedMinExtendDate = '';
$selectedNextDueDate = '';
$selectedHolidayDates = holiday_date_list($pdo);
$selectedLoanJson = '{}';
$selectedIssuedDate = '';
$selectedDisplayEndDate = '';

$selectedLoanStmt = $pdo->prepare(
    "SELECT
        l.*,
        c.full_name,
        c.nic AS customer_nic,
        c.phone AS customer_phone,
        u.full_name AS assigned_user_name
     FROM loans l
     JOIN customers c ON c.id = l.customer_id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     WHERE l.id = :loan_id
     LIMIT 1"
);
$selectedLoanStmt->execute(['loan_id' => $selectedLoanId]);
$selectedLoan = $selectedLoanStmt->fetch() ?: null;

if (!$selectedLoan) {
    set_flash('error', 'Loan not found.');
    redirect('pages/loans.php');
}

if ((string) ($selectedLoan['status'] ?? '') === 'closed') {
    set_flash('error', 'Closed loans cannot be extended.');
    redirect('pages/loan_edit.php?loan_id=' . $selectedLoanId);
}

$pendingStmt = $pdo->prepare(
    "SELECT id, installment_no, due_date, due_amount, paid_amount, status
     FROM loan_installments
     WHERE loan_id = :loan_id
       AND status IN ('pending', 'partial', 'overdue')
       AND due_amount > paid_amount
     ORDER BY due_date ASC, installment_no ASC"
);
$pendingStmt->execute(['loan_id' => $selectedLoanId]);
$selectedPendingRows = $pendingStmt->fetchAll();

foreach ($selectedPendingRows as $row) {
    $selectedOutstanding += max(0.0, round((float) $row['due_amount'] - (float) $row['paid_amount'], 2));
}
$selectedOutstanding = round($selectedOutstanding, 2);
$selectedRemainingCount = count($selectedPendingRows);
$selectedNextDueDate = $selectedPendingRows[0]['due_date'] ?? '';

$collectionTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM collections WHERE loan_id = :loan_id');
$collectionTotalStmt->execute(['loan_id' => $selectedLoanId]);
$selectedCollectionTotal = round((float) $collectionTotalStmt->fetchColumn(), 2);

$scheduleMetaStmt = $pdo->prepare(
    'SELECT COALESCE(MAX(due_date), "") AS max_due_date
     FROM loan_installments
     WHERE loan_id = :loan_id'
);
$scheduleMetaStmt->execute(['loan_id' => $selectedLoanId]);
$selectedMaxDueDate = (string) ($scheduleMetaStmt->fetchColumn() ?: '');
if ($selectedMaxDueDate === '') {
    $selectedMaxDueDate = (string) (($selectedLoan['end_date'] ?? '') ?: today());
}

$selectedIssuedDate = (string) (($selectedLoan['issued_date'] ?? '') ?: ($selectedLoan['start_date'] ?? ''));
$selectedEndDate = (string) (($selectedLoan['end_date'] ?? '') ?: $selectedMaxDueDate);
$selectedDisplayEndDate = $selectedEndDate;
$selectedMinExtendDate = next_collectible_date(
    $pdo,
    (new DateTimeImmutable($selectedEndDate))->add(new DateInterval('P1D'))->format('Y-m-d')
);

$selectedLoanJson = (string) json_encode([
    'principal' => round((float) ($selectedLoan['principal_amount'] ?? 0), 2),
    'total' => round((float) ($selectedLoan['total_amount'] ?? 0), 2),
    'outstanding' => $selectedOutstanding,
    'remaining_count' => $selectedRemainingCount,
    'installment_count' => (int) ($selectedLoan['installment_count'] ?? 0),
    'interest_rate' => round((float) ($selectedLoan['interest_rate'] ?? 0), 4),
    'interest_rate_type' => normalize_interest_rate_type((string) ($selectedLoan['interest_rate_type'] ?? 'amount_based')),
    'interest_rate_months' => normalize_interest_rate_months((int) ($selectedLoan['interest_rate_months'] ?? 1)),
    'frequency' => (string) ($selectedLoan['installment_frequency'] ?? 'daily'),
    'current_end_date' => $selectedEndDate,
    'min_extend_date' => $selectedMinExtendDate,
    'pending_dates' => array_values(array_map(static fn (array $row): string => (string) $row['due_date'], $selectedPendingRows)),
    'holiday_dates' => $selectedHolidayDates,
    'currency' => $currencyLabel,
    'money_decimals' => money_display_decimals($pdo),
], JSON_UNESCAPED_SLASHES);

require __DIR__ . '/../includes/layout_start.php';
?>

<div class="create-loan-actionbar">
    <a class="btn" href="<?= e(url('pages/loan_edit.php?loan_id=' . $selectedLoanId)) ?>">
        <span class="btn-icon-inline" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
        </span>
        Back to Loan
    </a>
</div>

<section class="loan-extend-layout" data-loan-extend data-loan-extend-state="<?= e($selectedLoanJson) ?>">
        <div class="panel loan-extend-details-panel">
            <div class="panel-head">
                <h2 class="panel-title">Original Details</h2>
            </div>
            <div class="loan-extend-detail-grid">
                <div>
                    <span>Loan</span>
                    <strong><?= e((string) $selectedLoan['loan_number']) ?></strong>
                </div>
                <div>
                    <span>Customer</span>
                    <strong><?= e((string) $selectedLoan['full_name']) ?></strong>
                </div>
                <div>
                    <span>Principal</span>
                    <strong><?= e(money_label($pdo, (float) $selectedLoan['principal_amount'])) ?></strong>
                </div>
                <div>
                    <span>Total Repayable</span>
                    <strong><?= e(money_label($pdo, (float) $selectedLoan['total_amount'])) ?></strong>
                </div>
                <div>
                    <span>Collected</span>
                    <strong><?= e(money_label($pdo, $selectedCollectionTotal)) ?></strong>
                </div>
                <div>
                    <span>Balance</span>
                    <strong><?= e(money_label($pdo, $selectedOutstanding)) ?></strong>
                </div>
                <div>
                    <span>Installment</span>
                    <strong><?= e(money_label($pdo, (float) $selectedLoan['installment_amount'])) ?></strong>
                </div>
                <div>
                    <span>Frequency</span>
                    <strong><?= e(ucfirst((string) $selectedLoan['installment_frequency'])) ?></strong>
                </div>
                <div>
                    <span>Issued Date</span>
                    <strong><?= e($selectedIssuedDate !== '' ? display_date($selectedIssuedDate) : '-') ?></strong>
                </div>
                <div>
                    <span>End Date</span>
                    <strong><?= e($selectedDisplayEndDate !== '' ? display_date($selectedDisplayEndDate) : '-') ?></strong>
                </div>
                <div>
                    <span>Remaining Inst.</span>
                    <strong><?= e((string) $selectedRemainingCount) ?></strong>
                </div>
                <div>
                    <span>Next Due</span>
                    <strong><?= e($selectedNextDueDate !== '' ? display_date((string) $selectedNextDueDate) : '-') ?></strong>
                </div>
                <div class="full">
                    <span>Assigned To</span>
                    <strong><?= e(trim((string) ($selectedLoan['assigned_user_name'] ?? '')) !== '' ? (string) $selectedLoan['assigned_user_name'] : 'All users') ?></strong>
                </div>
            </div>
        </div>

        <div class="panel loan-extend-options-panel">
            <div class="panel-head">
                <h2 class="panel-title">Extend Options</h2>
            </div>
            <?php if ($selectedRemainingCount <= 0): ?>
                <p class="loan-extend-empty">No unpaid installments available.</p>
            <?php else: ?>
                <form class="loan-extend-form" method="post" action="<?= e(url('actions/loan_extend.php')) ?>" data-confirm="Extend this loan and update the unpaid installment schedule?" data-inline-confirm="1">
                    <?= csrf_input() ?>
                    <input type="hidden" name="loan_id" value="<?= e((string) $selectedLoanId) ?>">

                    <div class="form-grid loan-extend-options-grid">
                        <div class="field">
                            <label>Extend Type</label>
                            <select name="extend_type" data-loan-extend-type required>
                                <option value="amount">Extend Loan Amount</option>
                                <option value="amount_date">Extend Loan + End Date</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Extend Amount</label>
                            <input type="number" step="0.01" min="0.01" name="extend_amount" data-loan-extend-amount required>
                        </div>
                        <div class="field loan-extend-date-toggle-field" data-loan-extend-date-field hidden>
                            <input type="hidden" name="extend_end_date" value="0" data-loan-extend-date-flag>
                            <label>Extend Date</label>
                            <input type="date" name="new_end_date" value="<?= e($selectedMinExtendDate) ?>" min="<?= e($selectedMinExtendDate) ?>" data-loan-extend-date disabled>
                        </div>
                        <div class="field full">
                            <label>Round Installment Amount</label>
                            <div class="loan-rounding-row" data-loan-extend-rounding-row>
                                <label class="checkline loan-rounding-toggle">
                                    <input type="checkbox" name="use_rounded_installment" value="1" data-loan-extend-rounding-toggle>
                                    <span class="loan-rounding-checkbox" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    </span>
                                </label>
                                <input type="number" step="0.01" min="0.01" name="rounded_installment_amount" placeholder="Installment amount" data-loan-extend-rounded-amount disabled>
                            </div>
                        </div>
                        <div class="field full">
                            <label>Note</label>
                            <textarea name="note" placeholder="Optional"></textarea>
                        </div>
                    </div>

                    <div class="calc-preview-grid calc-preview-grid-three loan-extend-preview-grid">
                        <div class="calc-preview-item">
                            <p>Additional Repayable</p>
                            <h3><span data-loan-extend-preview-additional><?= e($currencyLabel . ' ' . money(0, money_display_decimals($pdo))) ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>New Balance</p>
                            <h3><span data-loan-extend-preview-balance><?= e(money_label($pdo, $selectedOutstanding)) ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>New Installment</p>
                            <h3><span data-loan-extend-preview-installment><?= e($selectedRemainingCount > 0 ? money_label($pdo, round($selectedOutstanding / $selectedRemainingCount, 2)) : '-') ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>New Principal</p>
                            <h3><span data-loan-extend-preview-principal><?= e(money_label($pdo, (float) $selectedLoan['principal_amount'])) ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>Installments Left</p>
                            <h3><span data-loan-extend-preview-count><?= e((string) $selectedRemainingCount) ?></span></h3>
                        </div>
                        <div class="calc-preview-item">
                            <p>New End Date</p>
                            <h3><span data-loan-extend-preview-date><?= e($selectedDisplayEndDate !== '' ? display_date($selectedDisplayEndDate) : '-') ?></span></h3>
                        </div>
                    </div>

                    <button class="btn btn-primary loan-extend-submit-btn" type="submit">Extend Loan</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

<script>
(() => {
    const root = document.querySelector('[data-loan-extend]');
    if (!(root instanceof HTMLElement)) return;

    let state = {};
    try {
        state = JSON.parse(root.getAttribute('data-loan-extend-state') || '{}') || {};
    } catch (_error) {
        state = {};
    }

    const form = root.querySelector('.loan-extend-form');
    if (!(form instanceof HTMLFormElement)) return;

    const extendType = form.querySelector('[data-loan-extend-type]');
    const amountInput = form.querySelector('[data-loan-extend-amount]');
    const dateField = form.querySelector('[data-loan-extend-date-field]');
    const dateFlag = form.querySelector('[data-loan-extend-date-flag]');
    const dateInput = form.querySelector('[data-loan-extend-date]');
    const roundingToggle = form.querySelector('[data-loan-extend-rounding-toggle]');
    const roundingInput = form.querySelector('[data-loan-extend-rounded-amount]');
    const roundingRow = form.querySelector('[data-loan-extend-rounding-row]');
    const previewAdditional = root.querySelector('[data-loan-extend-preview-additional]');
    const previewBalance = root.querySelector('[data-loan-extend-preview-balance]');
    const previewInstallment = root.querySelector('[data-loan-extend-preview-installment]');
    const previewPrincipal = root.querySelector('[data-loan-extend-preview-principal]');
    const previewCount = root.querySelector('[data-loan-extend-preview-count]');
    const previewDate = root.querySelector('[data-loan-extend-preview-date]');

    const money = (value) => {
        const numberValue = Number.isFinite(value) ? value : 0;
        const decimals = Number(state.money_decimals) === 0 ? 0 : 2;
        return `${state.currency || 'LKR'} ${numberValue.toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        })}`;
    };
    const parseAmount = (input) => input instanceof HTMLInputElement ? Math.max(0, Number.parseFloat(input.value || '0') || 0) : 0;
    const roundMoney = (value) => Math.round((value + Number.EPSILON) * 100) / 100;
    const dateValue = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || '')) ? String(value) : '';
    const formatDate = (value) => {
        const raw = dateValue(value);
        if (!raw) return '-';
        const [year, month, day] = raw.split('-');
        return `${day}/${month}/${year}`;
    };
    const formatUtcDate = (date) => {
        const year = date.getUTCFullYear();
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const day = String(date.getUTCDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    const parseUtcDate = (value) => {
        const raw = dateValue(value);
        if (!raw) return null;
        const [year, month, day] = raw.split('-').map((part) => Number.parseInt(part, 10));
        return new Date(Date.UTC(year, month - 1, day));
    };
    const addDays = (value, days) => {
        const date = parseUtcDate(value);
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return value;
        date.setUTCDate(date.getUTCDate() + days);
        return formatUtcDate(date);
    };
    const addMonths = (value, months) => {
        const date = parseUtcDate(value);
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return value;
        date.setUTCMonth(date.getUTCMonth() + months);
        return formatUtcDate(date);
    };
    const nextCollectible = (value) => {
        const holidays = Array.isArray(state.holiday_dates) ? new Set(state.holiday_dates) : new Set();
        let candidate = value;
        for (let guard = 0; guard < 366; guard += 1) {
            if (!holidays.has(candidate)) return candidate;
            candidate = addDays(candidate, 1);
        }
        return value;
    };
    const nextFrequencyDate = (value) => {
        if (state.frequency === 'weekly') return nextCollectible(addDays(value, 7));
        if (state.frequency === 'monthly') return nextCollectible(addMonths(value, 1));
        return nextCollectible(addDays(value, 1));
    };
    const extraDateCount = () => {
        if (!(extendType instanceof HTMLSelectElement) || extendType.value !== 'amount_date' || !(dateInput instanceof HTMLInputElement)) {
            return 0;
        }
        const selectedEnd = nextCollectible(dateValue(dateInput.value));
        const currentEnd = dateValue(state.current_end_date);
        if (!selectedEnd || !currentEnd || selectedEnd <= currentEnd) {
            return 0;
        }
        const dates = [];
        let cursor = currentEnd;
        for (let guard = 0; guard < 2000; guard += 1) {
            const candidate = nextFrequencyDate(cursor);
            if (candidate > selectedEnd) break;
            dates.push(candidate);
            cursor = candidate;
        }
        if (dates.length === 0 || dates[dates.length - 1] !== selectedEnd) {
            dates.push(selectedEnd);
        }
        return Array.from(new Set(dates)).length;
    };
    const generatedEndDateForCount = (targetCount) => {
        const pendingDates = Array.isArray(state.pending_dates)
            ? state.pending_dates.filter((item) => dateValue(item) !== '')
            : [];
        const safeCount = Math.max(1, Number.parseInt(String(targetCount || 1), 10) || 1);
        if (pendingDates.length >= safeCount) {
            return pendingDates[safeCount - 1];
        }

        let cursor = dateValue(state.current_end_date);
        if (!cursor) {
            return pendingDates[pendingDates.length - 1] || '';
        }

        for (let count = pendingDates.length; count < safeCount; count += 1) {
            cursor = nextFrequencyDate(cursor);
        }

        return cursor;
    };
    const updatePreview = () => {
        const amount = parseAmount(amountInput);
        const multiplier = state.interest_rate_type === 'monthly'
            ? Math.max(1, Number.parseInt(String(state.interest_rate_months || 1), 10) || 1)
            : 1;
        const additional = roundMoney(amount + (amount * (Number(state.interest_rate || 0) / 100) * multiplier));
        const newBalance = roundMoney(Number(state.outstanding || 0) + additional);
        const roundedValue = parseAmount(roundingInput);
        const usingRounded = roundingToggle instanceof HTMLInputElement && roundingToggle.checked && roundedValue > 0;
        let count = Math.max(1, Number(state.remaining_count || 0));
        let displayEnd = state.current_end_date;

        if (extendType instanceof HTMLSelectElement && extendType.value === 'amount_date' && dateInput instanceof HTMLInputElement && !usingRounded) {
            count += extraDateCount();
            displayEnd = nextCollectible(dateValue(dateInput.value));
        } else if (usingRounded) {
            count = Math.max(1, Math.ceil(newBalance / roundedValue));
            displayEnd = generatedEndDateForCount(count);
        }

        const installment = usingRounded ? roundedValue : roundMoney(newBalance / count);
        const newPrincipal = roundMoney(Number(state.principal || 0) + amount);

        if (previewAdditional instanceof HTMLElement) previewAdditional.textContent = money(additional);
        if (previewBalance instanceof HTMLElement) previewBalance.textContent = money(newBalance);
        if (previewInstallment instanceof HTMLElement) previewInstallment.textContent = money(installment);
        if (previewPrincipal instanceof HTMLElement) previewPrincipal.textContent = money(newPrincipal);
        if (previewCount instanceof HTMLElement) previewCount.textContent = String(count);
        if (previewDate instanceof HTMLElement) previewDate.textContent = formatDate(displayEnd);
    };

    if (extendType instanceof HTMLSelectElement && dateInput instanceof HTMLInputElement) {
        const syncExtendType = () => {
            const extendsDate = extendType.value === 'amount_date';
            if (dateField instanceof HTMLElement) {
                dateField.hidden = !extendsDate;
            }
            dateInput.disabled = !extendsDate;
            dateInput.required = extendsDate;
            if (dateFlag instanceof HTMLInputElement) {
                dateFlag.value = extendsDate ? '1' : '0';
            }
            updatePreview();
        };
        extendType.addEventListener('change', syncExtendType);
        syncExtendType();
    }
    if (roundingToggle instanceof HTMLInputElement && roundingInput instanceof HTMLInputElement) {
        roundingToggle.addEventListener('change', () => {
            roundingInput.disabled = !roundingToggle.checked;
            roundingInput.required = roundingToggle.checked;
            if (roundingRow instanceof HTMLElement) {
                roundingRow.classList.toggle('is-checked', roundingToggle.checked);
            }
            updatePreview();
        });
    }
    [extendType, amountInput, dateInput, roundingInput].forEach((input) => {
        if (input instanceof HTMLElement) {
            input.addEventListener('input', updatePreview);
            input.addEventListener('change', updatePreview);
        }
    });
    updatePreview();
})();
</script>

<?php require __DIR__ . '/../includes/layout_end.php'; ?>
