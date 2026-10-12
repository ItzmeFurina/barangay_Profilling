<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin();

// A backup contains every resident's full record, so being logged in
// isn't treated as enough on its own - this re-confirms identity the
// same way Restore does, rather than releasing the file to anyone at an
// already-open Admin session. GET is refused entirely: there is no
// way to trigger a download without submitting the confirmation form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('This page only accepts the download confirmation form.');
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    flash('error', 'Your session expired. Please try again.');
    redirect('system.php');
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE user_id = ?');
$stmt->execute([$_SESSION['staff_user_id']]);
$account = $stmt->fetch();

$submittedEmail = trim($_POST['confirm_email'] ?? '');
if (strcasecmp($submittedEmail, (string) $account['email']) !== 0
    || !password_verify($_POST['confirm_password'] ?? '', (string) $account['password_hash'])) {
    log_activity($pdo, $_SESSION['staff_user_id'], 'Failed backup download confirmation', 'read');
    flash('error', 'Incorrect email or password - download cancelled.');
    redirect('system.php');
}

$backupId = (int) ($_POST['backup_id'] ?? 0);
$stmt = $pdo->prepare("SELECT file_path FROM backup_logs WHERE backup_id = ? AND status = 'completed'");
$stmt->execute([$backupId]);
$filename = $stmt->fetchColumn();

$backupDir = realpath(__DIR__ . '/../backups');
$fullPath = ($filename && $backupDir) ? realpath($backupDir . '/' . $filename) : false;

if (!$filename || $fullPath === false || strpos($fullPath, $backupDir) !== 0 || !is_file($fullPath)) {
    flash('error', 'That backup file could not be found.');
    redirect('system.php');
}

log_activity($pdo, $_SESSION['staff_user_id'], "Downloaded backup #{$backupId}", 'read');

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
