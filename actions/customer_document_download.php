<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$docId = (int) ($_GET['doc_id'] ?? 0);
if ($docId <= 0) {
    set_flash('error', 'Invalid document selected.');
    redirect('pages/customers.php');
}

$stmt = $pdo->prepare(
    'SELECT d.*, c.id AS customer_id
     FROM customer_documents d
     JOIN customers c ON c.id = d.customer_id
     WHERE d.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $docId]);
$doc = $stmt->fetch();

if (!$doc) {
    set_flash('error', 'Document not found.');
    redirect('pages/customers.php');
}

$absPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $doc['file_path']);
if (!is_file($absPath)) {
    set_flash('error', 'Document file is missing.');
    redirect('pages/customers.php?customer_id=' . (int) $doc['customer_id']);
}

$downloadName = (string) ($doc['original_name'] ?? $doc['stored_name'] ?? ('document-' . $docId));
$mimeType = (string) ($doc['mime_type'] ?? 'application/octet-stream');

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . (string) filesize($absPath));
header('Pragma: public');
header('Cache-Control: private, must-revalidate');
readfile($absPath);
exit;

