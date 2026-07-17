<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('system_settings.manage', 'pages/system_settings.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/system_settings.php');
}
require_csrf('pages/system_settings.php');

$currencyLabel = strtoupper(trim((string) ($_POST['currency_label'] ?? 'LKR')));
$timezone = trim((string) ($_POST['timezone'] ?? date_default_timezone_get()));
$dateFormat = trim((string) ($_POST['date_format'] ?? 'd M Y'));
$defaultInterestRate = (float) ($_POST['default_interest_rate'] ?? 0);
$defaultInterestRateMonths = normalize_interest_rate_months((int) ($_POST['default_interest_rate_months'] ?? 1));
$defaultFrequency = (string) ($_POST['default_installment_frequency'] ?? 'daily');
$defaultTimeframeValue = max(1, (int) ($_POST['default_timeframe_value'] ?? 30));
$defaultTimeframeUnit = (string) ($_POST['default_timeframe_unit'] ?? 'days');
$defaultLoanCollectorId = (int) ($_POST['default_loan_collector_id'] ?? 0);
$allowOverpayment = (string) ($_POST['allow_overpayment'] ?? '1') === '0' ? '0' : '1';
$autoFillAmountReceived = (string) ($_POST['auto_fill_amount_received'] ?? '1') === '0' ? '0' : '1';
$paymentMethodSelectionEnabled = (string) ($_POST['payment_method_selection_enabled'] ?? '1') === '0' ? '0' : '1';
$pollIntervalSeconds = max(3, min(60, (int) ($_POST['poll_interval_seconds'] ?? 10)));

if (!in_array($defaultFrequency, ['daily', 'weekly', 'monthly'], true)) {
    $defaultFrequency = 'daily';
}

if (!in_array($defaultTimeframeUnit, ['days', 'months'], true)) {
    $defaultTimeframeUnit = 'days';
}

if (!is_assignable_collector($pdo, $defaultLoanCollectorId)) {
    $defaultLoanCollectorId = default_loan_collector_id($pdo);
}

$settingsToSave = [
    'currency_label' => mb_substr($currencyLabel !== '' ? $currencyLabel : 'LKR', 0, 12),
    'timezone' => mb_substr($timezone, 0, 80),
    'date_format' => mb_substr($dateFormat !== '' ? $dateFormat : 'd M Y', 0, 20),
    'default_interest_rate' => number_format(max($defaultInterestRate, 0), 2, '.', ''),
    'default_interest_rate_months' => (string) $defaultInterestRateMonths,
    'default_installment_frequency' => $defaultFrequency,
    'default_timeframe_value' => (string) $defaultTimeframeValue,
    'default_timeframe_unit' => $defaultTimeframeUnit,
    'default_loan_collector_id' => (string) $defaultLoanCollectorId,
    'allow_overpayment' => $allowOverpayment,
    'auto_fill_amount_received' => $autoFillAmountReceived,
    'payment_method_selection_enabled' => $paymentMethodSelectionEnabled,
    'poll_interval_seconds' => (string) $pollIntervalSeconds,
];

try {
    system_settings_save($pdo, $settingsToSave, (int) (current_user()['id'] ?? 0));
    log_activity($pdo, 'settings.system_updated', 'System settings updated.', [
        'currency_label' => $settingsToSave['currency_label'],
        'timezone' => $settingsToSave['timezone'],
        'poll_interval_seconds' => $settingsToSave['poll_interval_seconds'],
    ]);
    set_flash('success', 'System settings saved successfully.');
} catch (Throwable $e) {
    set_flash('error', 'Failed to save system settings.');
}

redirect('pages/system_settings.php');
