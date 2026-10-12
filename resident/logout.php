<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

if (isset($_SESSION['user_id'])) {
    $pdo = get_db_connection();
    log_activity($pdo, $_SESSION['user_id'], 'Resident logged out', 'read');
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
