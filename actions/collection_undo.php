<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('collections.undo', 'pages/today_collections.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/today_collections.php');
}
require_csrf('pages/today_collections.php');

$collectionId = (int) ($_POST['collection_id'] ?? 0);
$returnToRaw = trim((string) ($_POST['return_to'] ?? 'pages/today_collections.php?collection_status=collected'));

$returnTo = 'pages/today_collections.php?collection_status=collected';
$parsedReturn = parse_url($returnToRaw);
if (is_array($parsedReturn) && isset($parsedReturn['path']) && preg_match('/^(index\.php|pages\/[a-z_]+\.php)$/', $parsedReturn['path'])) {
    $returnTo = $parsedReturn['path'];

    $allowedQuery = [];
    if (isset($parsedReturn['query'])) {
        parse_str($parsedReturn['query'], $queryValues);
        if (!empty($queryValues['date'])) {
            $allowedQuery['date'] = (string) $queryValues['date'];
        }
        if (!empty($queryValues['date_mode']) && in_array($queryValues['date_mode'], ['today', 'tomorrow', 'day_after_tomorrow', 'custom'], true)) {
            $allowedQuery['date_mode'] = (string) $queryValues['date_mode'];
        }
        if (!empty($queryValues['collection_status']) && in_array($queryValues['collection_status'], ['pending', 'collected'], true)) {
            $allowedQuery['collection_status'] = (string) $queryValues['collection_status'];
        }
        if (!empty($queryValues['q'])) {
            $allowedQuery['q'] = (string) $queryValues['q'];
        }
        if ($returnTo === 'pages/loan_edit.php' && !empty($queryValues['loan_id'])) {
            $loanIdQuery = (int) $queryValues['loan_id'];
            if ($loanIdQuery > 0) {
                $allowedQuery['loan_id'] = $loanIdQuery;
            }
        }
    }

    if (!empty($allowedQuery)) {
        $returnTo .= '?' . http_build_query($allowedQuery);
    }

    if ($returnTo === 'pages/loan_edit.php' || str_starts_with($returnTo, 'pages/loan_edit.php?')) {
        $fragment = (string) ($parsedReturn['fragment'] ?? '');
        if ($fragment === 'collections') {
            $returnTo .= '#collections';
        }
    }
}

$current = current_user();
$currentUserId = (int) ($current['id'] ?? 0);
$currentRole = (string) ($current['role'] ?? '');
$currentUserName = (string) ($current['full_name'] ?? 'Unknown');

try {
    $pdo->beginTransaction();
    $result = undo_collection_payment($pdo, $collectionId, $currentUserId, $currentRole);
    $pdo->commit();

    log_activity($pdo, 'collection.undone', $currentUserName . ' undid collection for loan ' . (string) $result['loan_number'] . '.', [
        'loan_id' => (int) $result['loan_id'],
        'loan_number' => (string) $result['loan_number'],
        'customer_name' => (string) $result['customer_name'],
        'payment_ref' => (string) $result['payment_ref'],
        'amount' => (float) $result['amount'],
        'collection_count' => (int) $result['collection_count'],
        'restored_installment_count' => (int) $result['restored_installment_count'],
    ]);

    set_flash('success', 'Collection undone successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_activity($pdo, 'collection.undo_failed', 'Collection undo failed: ' . $e->getMessage(), [
        'collection_id' => $collectionId,
    ]);

    $userError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Failed to undo collection. Please try again.';
    set_flash('error', $userError);
}

redirect($returnTo);
