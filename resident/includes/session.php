<?php
/**
 * Resident portal session bootstrap.
 *
 * Uses its own session NAME so a resident's session cookie is never
 * confused with the admin/staff session (set up separately, under its own
 * name) even though both share this same server and domain. That name-based
 * separation is what actually keeps the two apart - a resident session and
 * an admin session are simply different cookies, regardless of path.
 *
 * The cookie path itself is left at '/' on purpose. An earlier version
 * scoped this to '/resident/', assuming the app would live at a domain
 * root - but since it's actually served from a subfolder
 * (localhost/barangay420/resident/...), that path never matched the real
 * request path, so the browser silently never sent the cookie back at all.
 * Every session looked "expired" because, from PHP's point of view, a
 * brand new empty session was being created on every single request.
 */

require_once __DIR__ . '/../../shared/security_headers.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('resident_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    // Automatically adds the Secure flag once this runs behind real HTTPS
    // (it won't on localhost, which is correct - browsers require actual
    // TLS for a Secure cookie to work at all, so forcing it on now would
    // just break local testing rather than accomplish anything).
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function resident_is_logged_in(): bool {
    return isset($_SESSION['resident_id']);
}

/**
 * The account row backing the current session, loaded at most once per
 * request. The login and active guards both need it (status, archived-member
 * flag, password fingerprint), so sharing it collapses two identical account
 * lookups into one. The census check reads a different table and is
 * unchanged. Returns null when there is no session or the row is gone.
 */
function resident_session_user_row(): ?array {
    static $row = null;
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $userId = $_SESSION['user_id'] ?? 0;
        if ($userId) {
            require_once __DIR__ . '/../../shared/db.php';
            $stmt = get_db_connection()->prepare(
                "SELECT u.account_status, u.password_hash,
                        EXISTS(SELECT 1 FROM household_members hm
                               WHERE hm.is_archived = TRUE
                                 AND (hm.member_id = r.household_member_id OR hm.linked_user_id = u.user_id))
                            AS member_archived
                 FROM users u
                 JOIN residents r ON r.user_id = u.user_id
                 WHERE u.user_id = ?"
            );
            $stmt->execute([$userId]);
            $fetched = $stmt->fetch();
            $row = $fetched === false ? null : $fetched;
        }
    }
    return $row;
}

/**
 * True when this resident's account (or their household member record)
 * has been archived - in which case the session must not continue.
 * Mirrors the login gate so an archiving done mid-session takes effect
 * on the very next page load, not just on the next log in.
 *
 * Rejected accounts are blocked here. Their email is released, and they
 * must submit a new registration instead of signing in to resubmit.
 *
 * $allowPending is only for resubmit.php itself: after a successful
 * resubmission the account flips to 'pending', and that page must stay
 * reachable so it can show its "still under review" screen instead of
 * logging the resident out with a contradictory error.
 */
function resident_account_blocked(bool $allowPending = false): bool {
    $row = resident_session_user_row();
    if ($row === null) {
        return true;
    }
    $allowed = $allowPending ? ['active', 'pending'] : ['active'];
    return !in_array($row['account_status'], $allowed, true) || !empty($row['member_archived']);
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
function resident_password_changed(): bool {
    $row = resident_session_user_row();
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

function require_resident_login(bool $allowPending = false): void {
    if (!resident_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    if (resident_account_blocked($allowPending)) {
        unset($_SESSION['user_id'], $_SESSION['resident_id'], $_SESSION['full_name'], $_SESSION['pw_fp']);
        require_once __DIR__ . '/../../shared/functions.php';
        flash('error', 'This account is no longer active. Please contact the barangay office.');
        header('Location: login.php');
        exit;
    }
    if (resident_password_changed()) {
        unset($_SESSION['user_id'], $_SESSION['resident_id'], $_SESSION['full_name'], $_SESSION['pw_fp']);
        require_once __DIR__ . '/../../shared/functions.php';
        flash('error', 'Your password has been changed. Please sign in again.');
        header('Location: login.php');
        exit;
    }
}

/**
 * Blocks a logged-in resident who is not an approved, active account.
 * - rejected applications are routed to resubmit.php (their one allowed page)
 * - anything else inactive is logged out
 * Only the resubmit page and public pages may be reached before this passes.
 */
function require_resident_active(): void {
    require_resident_login();
    $row = resident_session_user_row();
    $status = $row === null ? '' : (string) $row['account_status'];
    if ($status === 'rejected') {
        header('Location: resubmit.php');
        exit;
    }
    if ($status !== 'active') {
        require_once __DIR__ . '/../../shared/functions.php';
        flash('error', 'This account is no longer active. Please contact the barangay office.');
        header('Location: login.php');
        exit;
    }
}

/**
 * Blocks a logged-in resident who has not completed their household
 * census yet. Only the census form itself may be used until then - every
 * other account page must call this right after require_resident_login().
 * Also enforces the active-account gate (rejected applicants may only use
 * resubmit.php, and users who have not completed the census are sent to
 * the census form).
 */
function require_census_completed(): void {
    require_resident_active();
    require_once __DIR__ . '/../../shared/db.php';
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT census_completed FROM residents WHERE resident_id = ?');
    $stmt->execute([$_SESSION['resident_id']]);
    if (!$stmt->fetchColumn()) {
        header('Location: census.php');
        exit;
    }
}
