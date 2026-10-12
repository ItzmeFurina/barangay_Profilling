<?php
/**
 * App-level settings that aren't credentials (those stay in shared/db.php).
 */

// The real resident registration workflow is: register -> account sits
// as 'pending' -> an Admin reviews the uploaded ID and approves or
// rejects it. SKIP_RESIDENT_VERIFICATION bypasses that review and is a
// TESTING-ONLY convenience that MUST stay false in production - the guard
// below refuses to run if it is ever enabled somewhere that isn't
// localhost, so it cannot silently slip onto a real server.
define('SKIP_RESIDENT_VERIFICATION', false);

if (SKIP_RESIDENT_VERIFICATION
    && !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    error_log('SKIP_RESIDENT_VERIFICATION is enabled outside localhost - refusing to run.');
    http_response_code(500);
    die('Registration approval is disabled for this environment. Please contact the administrator.');
}
