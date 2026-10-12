
<?php
/**
 * Shared database connection for Barangay 420.
 * Supports InfinityFree hosting and local XAMPP.
 */

date_default_timezone_set('Asia/Manila');

define('APP_TIMEZONE', 'Asia/Manila');

// Load database settings from the private configuration file.
$configPath = __DIR__ . '/private_config.php';
$config = [];

if (is_file($configPath) && is_readable($configPath)) {
    $loadedConfig = require $configPath;

    if (is_array($loadedConfig)) {
        $config = $loadedConfig;
    }
}

// Environment variables can override configuration values.
$dbHost = trim((string) (getenv('BARANGAY420_DB_HOST') ?: ($config['db_host'] ?? '')));
$dbName = trim((string) (getenv('BARANGAY420_DB_NAME') ?: ($config['db_name'] ?? '')));
$dbUser = trim((string) (getenv('BARANGAY420_DB_USER') ?: ($config['db_user'] ?? '')));
$dbPass = (string) (getenv('BARANGAY420_DB_PASS') ?: ($config['db_pass'] ?? ''));
$appEnv = trim((string) (getenv('APP_ENV') ?: ($config['app_env'] ?? '')));

// Detect a hosted website so missing production settings cannot silently
// fall back to the local XAMPP database.
$httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isHosted = $httpHost !== ''
    && strpos($httpHost, 'localhost') === false
    && strpos($httpHost, '127.0.0.1') === false;

$production = $appEnv === 'production' || $isHosted;

if ($production) {
    if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPass === '') {
        error_log('Barangay 420: Production database configuration is incomplete.');
        http_response_code(500);
        exit('We could not connect to the system right now. Please try again shortly.');
    }
} else {
    // Local XAMPP defaults, used only when production is not detected.
    $dbHost = $dbHost ?: 'localhost';
    $dbName = $dbName ?: 'barangay420';
    $dbUser = $dbUser ?: 'root';
    $dbPass = $dbPass ?: '';
}

if (!defined('DB_HOST')) {
    define('DB_HOST', $dbHost);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $dbName);
}
if (!defined('DB_USER')) {
    define('DB_USER', $dbUser);
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $dbPass);
}

/**
 * Return a reusable PDO database connection.
 */
function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST
        . ';port=3306'
        . ';dbname=' . DB_NAME
        . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+08:00'");

        return $pdo;
    } catch (PDOException $e) {
        // Keep credentials and detailed errors out of public responses.
        error_log('Barangay 420 database connection failed: ' . $e->getMessage());

        http_response_code(500);
        exit('We could not connect to the system right now. Please try again shortly.');
    }
}
