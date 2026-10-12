<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';

require_admin();

$beneficiaryId  = (int) ($_GET['id'] ?? 0);
$documentId     = (int) ($_GET['doc'] ?? 0);

$pdo = get_db_connection();

if ($documentId > 0) {
    // Individual benefit_documents row - the full set of files a resident
    // attached to one application.
    $stmt = $pdo->prepare(
        'SELECT file_path FROM benefit_documents WHERE benefit_document_id = ? AND beneficiary_id = ?'
    );
    $stmt->execute([$documentId, $beneficiaryId]);
    $path = $stmt->fetchColumn();
} else {
    // Legacy fallback: the single id_document_path column still receives the
    // first uploaded file, so old rows (and old links) keep working.
    $stmt = $pdo->prepare('SELECT id_document_path FROM beneficiaries WHERE beneficiary_id = ?');
    $stmt->execute([$beneficiaryId]);
    $path = $stmt->fetchColumn();
}

if (!$path) {
    http_response_code(404);
    die('Document not found.');
}

$fullPath = realpath(__DIR__ . '/../uploads/' . $path);
$uploadsRoot = realpath(__DIR__ . '/../uploads');

// Same defense-in-depth guard as admin/view-document.php: confirm the
// resolved path is actually inside uploads/ before ever reading it.
if ($fullPath === false || strpos($fullPath, $uploadsRoot) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    die('Document not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);