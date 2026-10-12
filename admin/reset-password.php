<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/mail.php';

no_cache();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

if (admin_is_logged_in()) {
    redirect('dashboard.php');
}

$token = '';
$errors = [];

try {
    $pdo = get_db_connection();
    $ip = auth_client_ip();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
        $candidate = is_string($_GET['token']) ? $_GET['token'] : '';
        $ipAllowed = auth_rate_limit_allows($pdo, 'password-reset-token-validation', 'ip', $ip, 20, 900);
        $rateToken = strlen($candidate) === 64 ? $candidate : 'invalid-token';
        $tokenAllowed = auth_rate_limit_allows($pdo, 'password-reset-token-validation', 'token', $rateToken, 5, 900);
        if ($ipAllowed && $tokenAllowed
            && preg_match('/^[a-f0-9]{64}$/i', $candidate)
            && password_reset_token_exists($pdo, $candidate, 'admin')) {
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['verified_reset_token'] = $candidate;
            redirect('reset-password.php');
        }
        $errors[] = 'This password reset link is invalid, expired, or already used.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        if (!csrf_verify($csrfToken)) {
            $errors[] = 'Your session expired. Please refresh the page and try again.';
        } else {
            $token = is_string($_SESSION['verified_reset_token'] ?? null)
                ? $_SESSION['verified_reset_token'] : '';
            $ipAllowed = auth_rate_limit_allows($pdo, 'password-reset-token-validation', 'ip', $ip, 20, 900);
            $rateToken = strlen($token) === 64 ? $token : 'invalid-token';
            $tokenAllowed = auth_rate_limit_allows($pdo, 'password-reset-token-validation', 'token', $rateToken, 5, 900);
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirmation = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

            if (!$ipAllowed || !$tokenAllowed || !preg_match('/^[a-f0-9]{64}$/i', $token)
                || !password_reset_token_exists($pdo, $token, 'admin')) {
                unset($_SESSION['verified_reset_token']);
                $token = '';
                $errors[] = 'This password reset link is invalid, expired, or already used.';
            } elseif (strlen($password) < 12 || strlen($password) > 72) {
                $errors[] = 'Password must be between 12 and 72 bytes.';
            } elseif (!hash_equals($password, $confirmation)) {
                $errors[] = 'Passwords do not match.';
            } else {
                $result = complete_password_reset($pdo, $token, 'admin', password_hash($password, PASSWORD_DEFAULT));
                if ($result === null) {
                    unset($_SESSION['verified_reset_token']);
                    $token = '';
                    $errors[] = 'This password reset link is invalid, expired, or already used.';
                } else {
                    unset($_SESSION['verified_reset_token']);
                    send_password_changed_email($result['email']);
                    flash('success', 'Password reset successfully. Please log in with your new password.');
                    session_regenerate_id(true);
                    redirect('login.php');
                }
            }
        }
    } elseif (empty($errors)) {
        $token = is_string($_SESSION['verified_reset_token'] ?? null)
            ? $_SESSION['verified_reset_token'] : '';
        if ($token === '' || !password_reset_token_exists($pdo, $token, 'admin')) {
            unset($_SESSION['verified_reset_token']);
            $token = '';
            $errors[] = 'This password reset link is invalid, expired, or already used.';
        }
    }
} catch (PDOException $error) {
    error_log('Password reset operation failed.');
    http_response_code(500);
    unset($_SESSION['verified_reset_token']);
    $token = '';
    $errors = ['We could not process your request right now. Please try again later.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Reset Password - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<main class="auth-stage">
  <section class="auth-reveal">
    <h1 class="auth-title">Create a new<br><em>password</em></h1>
    <p class="auth-lede">Choose a strong password for your Barangay 420 admin account. Use 12 to 72 bytes.</p>
    <ul class="auth-points">
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        Minimum 12 characters
      </li>
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        Keep it private and secure
      </li>
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        You'll be logged out after reset
      </li>
    </ul>
  </section>

  <section class="auth-reveal auth-shell" style="--d:140ms">
    <div class="auth-core">
      <div class="auth-head">
        <span class="auth-glyph">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </span>
        <h2>Reset password</h2>
      </div>

      <?php if (!empty($errors) && ($_SERVER['REQUEST_METHOD'] !== 'POST' || $token === '')): ?>
        <div class="alert alert-error">
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <p class="auth-switch" style="margin-top:18px;"><a href="forgot-password.php">Request a new reset link</a> &middot; <a href="login.php">Back to login</a></p>
      <?php else: ?>

        <div id="form-errors">
          <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
              <ul>
                <?php foreach ($errors as $error): ?>
                  <li><?= e($error) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>

        <form method="POST" novalidate id="reset-form">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

          <div class="field">
            <label class="field-label" for="password">New password <span class="req" title="Required">*</span></label>
            <div class="password-wrap">
              <input class="form-input" type="password" id="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password" placeholder="12+ characters">
              <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Show or hide password">
                <svg class="pw-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l16 16"/><path d="M10.6 6A9.6 9.6 0 0 1 12 5.8c6 0 9.5 6.2 9.5 6.2a18.4 18.4 0 0 1-4.4 4.8M6.5 7.1A17.6 17.6 0 0 0 2.5 12S6 18.2 12 18.2a10 10 0 0 0 4-.8"/></svg>
              </button>
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="confirm_password">Confirm password <span class="req" title="Required">*</span></label>
            <div class="password-wrap">
              <input class="form-input" type="password" id="confirm_password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password" placeholder="Repeat it">
              <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="Show or hide password">
                <svg class="pw-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l16 16"/><path d="M10.6 6A9.6 9.6 0 0 1 12 5.8c6 0 9.5 6.2 9.5 6.2a18.4 18.4 0 0 1-4.4 4.8M6.5 7.1A17.6 17.6 0 0 0 2.5 12S6 18.2 12 18.2a10 10 0 0 0 4-.8"/></svg>
              </button>
            </div>
          </div>

          <button type="submit" class="btn-submit">
            <span>Reset password</span>
            <span class="btn-orb">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>
            </span>
          </button>
        </form>

        <p class="auth-switch"><a href="login.php">Back to login</a></p>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var els = document.querySelectorAll('.auth-reveal');
  if (!('IntersectionObserver' in window) || !els.length || reduce) {
    for (var i = 0; i < els.length; i++) els[i].classList.add('is-in');
  } else {
    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          entries[i].target.classList.add('is-in');
          io.unobserve(entries[i].target);
        }
      }
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    for (var i = 0; i < els.length; i++) io.observe(els[i]);
  }

  var toggles = document.querySelectorAll('.pw-toggle');
  for (var i = 0; i < toggles.length; i++) {
    (function (btn) {
      var input = btn.id === 'pw-toggle'
        ? document.getElementById('password')
        : document.getElementById(btn.dataset.target);
      var showIcon = btn.querySelector('.pw-show');
      var hideIcon = btn.querySelector('.pw-hide');
      if (!input) return;
      btn.addEventListener('click', function () {
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        if (showIcon) showIcon.style.opacity = reveal ? '0' : '1';
        if (hideIcon) hideIcon.style.opacity = reveal ? '1' : '0';
      });
    })(toggles[i]);
  }
})();
</script>
</body>
</html>
