<?php
/**
 * One-time setup script - creates the first Admin account.
 *
 * Run this once, from the command line, after importing schema.sql:
 *     php create_admin.php "Full Name" "admin@email.test" "09123456789" "password"
 *
 * It refuses to run through a web browser, so there is no way for this
 * account-creation path to ever be reachable on a live server.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/mail.php';

if ($argc < 5) {
    die("Usage: php create_admin.php \"Full Name\" \"email\" \"contact\" \"password\"\n");
}

$fullName = $argv[1];
$email    = normalize_email($argv[2]);
$contact  = $argv[3];
$password = $argv[4];

if (strlen($password) < 12 || strlen($password) > 72) {
    die("Password must be between 12 and 72 bytes. Nothing was created.\n");
}
if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Enter a valid email address of 150 characters or fewer. Nothing was created.\n");
}

$pdo = get_db_connection();

$stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    die("An account with that email already exists. Nothing was created.\n");
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO users (full_name, email, contact_number, password_hash, role, account_status)
         VALUES (?, ?, ?, ?, 'admin', 'active')"
    );
    $stmt->execute([$fullName, $email, $contact, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $verificationToken = issue_email_verification_token($pdo, $userId);

    $pdo->commit();
    $sent = send_email_verification_email($email, $verificationToken, 'admin');
    echo "\nAdmin account created. Email verification is required before signing in.\n";
    if (!$sent) {
        echo "The verification message could not be sent. Configure SMTP and request another link at the admin email verification page.\n";
    }
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Initial admin account creation failed.');
    fwrite(STDERR, "Failed to create account. Check the database configuration and try again.\n");
    exit(1);
}