<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin();

$documentId = (int) ($_GET['id'] ?? 0);

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT file_path, user_id FROM verification_documents WHERE document_id = ?');
$stmt->execute([$documentId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Document not found.');
}

log_activity($pdo, (int) $_SESSION['staff_user_id'], 'Verification document viewed', 'read');

$fullPath = realpath(__DIR__ . '/../uploads/' . $doc['file_path']);
$uploadsRoot = realpath(__DIR__ . '/../uploads');

// Guard against the file_path ever containing something like ../../ that
// could point outside the uploads folder - defense in depth even though
// this value is only ever written by our own upload code, never by user input directly.
if ($fullPath === false || strpos($fullPath, $uploadsRoot) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    die('Document not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
