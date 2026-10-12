<?php
/**
 * Admin/staff portal session bootstrap.
 *
 * Uses its own session NAME so an admin/staff session is never confused
 * with a resident session, even though both share this same server and
 * domain - they are simply different cookies.
 *
 * Path is deliberately left at '/' rather than '/admin/'. Scoping it to a
 * subfolder is exactly the bug that broke the resident portal's sessions
 * earlier: the moment this app is deployed under its own subfolder
 * (localhost/barangay420/admin/...) rather than a domain root, a
 * path-restricted cookie silently never gets sent back at all.
 */

require_once __DIR__ . '/../../shared/security_headers.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('admin_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function admin_is_logged_in(): bool {
    return isset($_SESSION['staff_user_id']);
}

function admin_role(): ?string {
    return $_SESSION['staff_role'] ?? null;
}

/**
 * The account row backing the current session, loaded at most once per
 * request so the status and password checks below share a single query.
 * Returns null when there is no session or the account row is gone.
 */
function admin_session_user_row(): ?array {
    static $row = null;
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $userId = $_SESSION['staff_user_id'] ?? 0;
        if ($userId) {
            require_once __DIR__ . '/../../shared/db.php';
            $stmt = get_db_connection()->prepare(
                'SELECT account_status, password_hash FROM users WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            $fetched = $stmt->fetch();
            $row = $fetched === false ? null : $fetched;
        }
    }
    return $row;
}

/**
 * True when this admin/staff account is no longer active - archived,
 * rejected, or deleted mid-session. Mirrors the resident portal's
 * resident_account_blocked() (resident/includes/session.php) so an
 * account action takes effect on the very next page load instead of
 * only at the next log in.
 */
function admin_account_blocked(): bool {
    $row = admin_session_user_row();
    return $row === null || $row['account_status'] !== 'active';
}

/**
 * True when the account's password changed after this session was issued,
 * so a reset (by the owner or by an admin) ends every other live session
 * for that account instead of only the next login.
 *
 * Sessions created before this check existed have no fingerprint stored;
 * they adopt the current one here rather than being force-logged-out on
 * the first request after deployment.
 */
function admin_password_changed(): bool {
    $row = admin_session_user_row();
    if ($row === null) {
        return true;
    }
    $current = hash('sha256', $row['password_hash']);
    if (!isset($_SESSION['pw_fp'])) {
        $_SESSION['pw_fp'] = $current;
        return false;
    }
    return !hash_equals($current, $_SESSION['pw_fp']);
}

function admin_clear_session(): void {
    unset($_SESSION['staff_user_id'], $_SESSION['staff_role'], $_SESSION['staff_full_name'], $_SESSION['pw_fp']);
}

function require_admin_login(): void {
    if (!admin_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    if (admin_account_blocked()) {
        admin_clear_session();
        require_once __DIR__ . '/../../shared/functions.php';
        flash('error', 'This account is no longer active. Please sign in again.');
        header('Location: login.php');
        exit;
    }
    if (admin_password_changed()) {
        admin_clear_session();
        require_once __DIR__ . '/../../shared/functions.php';
        flash('error', 'Your password has been changed. Please sign in again.');
        header('Location: login.php');
        exit;
    }
}

/** Gate for pages only the Admin can reach - Staff gets a plain 403. */
function require_admin(): void {
    require_admin_login();
    if (admin_role() !== 'admin') {
        http_response_code(403);
        die('This page is only available to the Admin.');
    }
}
