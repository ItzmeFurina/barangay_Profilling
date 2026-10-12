<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/mail.php';
require_once __DIR__ . '/../shared/auth_security.php';

no_cache();
header('Referrer-Policy: no-referrer');

if (resident_is_logged_in()) {
    redirect('profile.php');
}

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$errors = [];
$message = 'If the address belongs to an account and email delivery succeeds, a reset link will be sent.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    if (!csrf_verify($csrfToken)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $submittedEmail = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $email = strlen($submittedEmail) <= 150 ? normalize_email($submittedEmail) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            try {
                $pdo = get_db_connection();
                $ipAllowed = auth_rate_limit_allows($pdo, 'password-reset-request', 'ip', auth_client_ip(), 5, 900);
                $emailAllowed = auth_rate_limit_allows($pdo, 'password-reset-request', 'email', $email, 3, 900);
                if ($ipAllowed && $emailAllowed) {
                    issue_password_reset_link($pdo, $email, 'resident');
                }
            } catch (Throwable $error) {
                error_log('Password reset request could not be processed.');
                http_response_code(500);
                $errors[] = 'We could not process your request right now. Please try again later.';
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(empty($errors)
            ? ['success' => true, 'message' => $message]
            : ['success' => false, 'errors' => $errors]);
        exit;
    }

    if (empty($errors)) {
        flash('success', $message);
        redirect('forgot-password.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Forgot Password - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<main class="auth-stage">
  <section class="auth-reveal">
    <h1 class="auth-title">Forgot your<br><em>password?</em></h1>
    <p class="auth-lede">No worries. Enter the email address you used to register your Barangay 420 account and we'll send you a link to reset your password.</p>
    <ul class="auth-points">
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        Secure reset link
      </li>
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        Expires in 15 minutes
      </li>
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        One-time use only
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
        <p class="auth-subtitle">We'll email you a reset link.</p>
      </div>

      <?php $successMessage = flash('success'); ?>
      <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= e($successMessage) ?></div>
      <?php endif; ?>

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

      <form method="POST" novalidate id="forgot-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="field">
          <label class="field-label" for="email">Email address <span class="req" title="Required">*</span></label>
          <input class="form-input" type="email" id="email" name="email" maxlength="150" required autofocus autocomplete="email" placeholder="you@mail.com">
        </div>

        <button type="submit" class="btn-submit">
          <span>Send reset link</span>
          <span class="btn-orb">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>
          </span>
        </button>
      </form>

      <p class="auth-switch">Remember your password? <a href="login.php">Back to login</a></p>
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

  var form = document.getElementById('forgot-form');
  var errorBox = document.getElementById('form-errors');

  form.addEventListener('submit', function (e) {
    if (form.dataset.skipAjax === 'true') {
      return;
    }
    e.preventDefault();
    fetch('forgot-password.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new FormData(form),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          errorBox.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
          form.reset();
          return;
        }
        var html = '<div class="alert alert-error"><ul>';
        data.errors.forEach(function () { html += '<li></li>'; });
        html += '</ul></div>';
        errorBox.innerHTML = html;
        var items = errorBox.querySelectorAll('li');
        data.errors.forEach(function (msg, i) { items[i].textContent = msg; });
      })
      .catch(function () {
        form.dataset.skipAjax = 'true';
        form.submit();
      });
  });
})();
</script>
</body>
</html>
