<?php
/**
 * Security response headers, sent by every portal page and JSON endpoint.
 *
 * Included by admin/includes/session.php and resident/includes/session.php
 * (the bootstraps every page in those portals runs before any output) and
 * by shared/event_feed_json.php, which does not bootstrap a session.
 *
 * Root .htaccess sends the identical set for the few paths that emit
 * output WITHOUT a bootstrap - admin/partials/*, resident/partials/* and
 * database/*. The two layers are deliberately scoped to not overlap:
 * mod_headers runs before PHP flushes its own headers, so a blanket
 * `Header set` would arrive alongside the PHP header() and the client
 * would receive every header twice.
 *
 * Deliberately limited to headers with no rendering side effects:
 * - nosniff            stops a browser from re-interpreting a served file
 *                      with a guessed content type.
 * - frame-ancestors /  nothing in this system frames itself or is framed
 *   X-Frame-Options    by anyone, and there are no <iframe>/<object>/<embed>
 *                      tags in the codebase, so DENY cannot break a view.
 * - Referrer-Policy    keeps URLs out of third-party referrers; all assets
 *                      are same-origin or Google Fonts, neither of which
 *                      needs a referrer.
 * - Permissions-Policy  the app never uses camera/microphone/geolocation/
 *                      payment, so they are switched off outright.
 *
 * No broader Content-Security-Policy is set: both portals rely heavily on
 * inline <style> and <script> blocks, so a strict policy would break them,
 * and a permissive one would add nothing. frame-ancestors is sent as a
 * standalone CSP directive because it is enforced independently of every
 * other CSP directive - it does not activate the rest of a policy.
 */
function send_security_headers(): void {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (getenv('APP_ENV') === 'production'
        && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

send_security_headers();
