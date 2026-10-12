<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

if (resident_is_logged_in()) {
    redirect('profile.php');
}

$activePage = 'login';
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$errors = [];
$successMessage = flash('success');
$errorMessage = flash('error');
$showVerificationLink = $showVerificationLink ?? false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $email = normalize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $pdo = get_db_connection();
        $stmt = $pdo->prepare(
            "SELECT u.user_id, u.full_name, u.password_hash, u.account_status, u.email_verified_at,
                    u.email_verification_required,
                    u.failed_login_attempts, u.locked_until,
                    r.resident_id, r.census_completed,
                    EXISTS(SELECT 1 FROM household_members hm
                           WHERE hm.is_archived = TRUE
                             AND (hm.member_id = r.household_member_id OR hm.linked_user_id = u.user_id))
                        AS member_archived
             FROM users u
             JOIN residents r ON r.user_id = u.user_id
             WHERE LOWER(u.email) = LOWER(?) AND u.role = 'resident'"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Verified when the account does not exist, so a missing account
        // costs the same CPU as a real one. Without it, response time alone
        // would reveal which email addresses have accounts.
        static $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

        $haveUser = $user !== false;
        $locked = $haveUser
            && $user['locked_until'] !== null
            && strtotime($user['locked_until']) > time();

        // A lock whose deadline has passed is spent: treat the account as
        // fresh again. The stored count of 5 would otherwise make the very
        // next wrong attempt re-lock for another 15 minutes, so a single
        // try per quarter hour could hold an account out indefinitely.
        if ($haveUser && $user['locked_until'] !== null && strtotime($user['locked_until']) <= time()) {
            $user['failed_login_attempts'] = 0;
            $user['locked_until'] = null;
        }

        $passwordOk = password_verify($password, $haveUser ? $user['password_hash'] : $dummyHash);

        if ($locked && !$passwordOk) {
            // Wrong password on a locked account: same wording and same work
            // as a non-existent account, so lock state cannot be used to
            // confirm that an email address has an account.
            $errors[] = 'Incorrect email or password.';
        } elseif ($locked) {
            // Correct password on a locked account - tell the real owner when
            // the lock lifts. Only ever shown to someone who already knows
            // the password, so it reveals nothing to an attacker probing for
            // valid accounts.
            $errors[] = 'Too many failed attempts. Please try again after '
                . date('g:i A', strtotime($user['locked_until'])) . '.';
        } elseif (!$passwordOk) {
            if ($haveUser) {
                $attempts = $user['failed_login_attempts'] + 1;
                $lockUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                $upd = $pdo->prepare(
                    'UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE user_id = ?'
                );
                $upd->execute([$attempts, $lockUntil, $user['user_id']]);
            }
            $errors[] = 'Incorrect email or password.';
        } elseif ($user['email_verification_required'] && $user['email_verified_at'] === null) {
            $errors[] = 'Please verify your email address before signing in.';
            $showVerificationLink = true;
        } elseif ($user['account_status'] === 'pending') {
            $errors[] = 'Your account is still awaiting verification by the barangay admin.';
        } elseif ($user['account_status'] === 'archived') {
            $errors[] = 'This account is no longer active. Please contact the barangay office.';
        } elseif (!empty($user['member_archived'])) {
            // Member row is archived even though the account status
            // survived it (e.g. archived before this rule existed).
            $errors[] = 'This account is no longer active. Please contact the barangay office.';
        } else {
            $pdo->prepare(
                'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?'
            )->execute([$user['user_id']]);

            session_regenerate_id(true);
            $_SESSION['user_id']     = $user['user_id'];
            $_SESSION['resident_id'] = $user['resident_id'];
            $_SESSION['full_name']   = $user['full_name'];
            // Fingerprint checked on every subsequent request, so changing
            // this password later ends every other session for this account.
            $_SESSION['pw_fp'] = hash('sha256', $user['password_hash']);

            log_activity($pdo, $user['user_id'], 'Resident logged in', 'read');

            // Rejected applicants may sign in, but only to the resubmit
            // area; every other resident page blocks them.
            if ($user['account_status'] === 'rejected') {
                $target = 'resubmit.php';
            } else {
                $target = $user['census_completed'] ? 'profile.php' : 'census.php';
            }
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'redirect' => $target]);
                exit;
            }
            redirect($target);
        }

        // Only reached on a failed login attempt.
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Log in - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<main class="auth-stage">
  <section class="auth-reveal">
    <h1 class="auth-title">Welcome back<br><em>Residents!</em></h1>
    <p class="auth-lede">Sign in to stay informed about barangay programs, announcements, and your household information, all in one place.</p>
    <ul class="auth-points">
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        Benefits &amp; programs
      </li>
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        Household records
      </li>
      <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        Event announcements
      </li>
    </ul>
  </section>

  <section class="auth-reveal auth-shell" style="--d:140ms">
    <div class="auth-core">
      <div class="auth-head">
        <span class="auth-glyph">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2.5"/><path d="M8 11V8a4 4 0 1 1 8 0v3"/></svg>
        </span>
        <h2>Log in</h2>
      </div>

      <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= e($successMessage) ?></div>
      <?php endif; ?>

      <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?= e($errorMessage) ?></div>
      <?php endif; ?>

      <div id="login-errors">
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

      <form method="POST" novalidate id="login-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="field">
          <label class="field-label" for="email">Email address <span class="req" title="Required">*</span></label>
          <input class="form-input" type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email" placeholder="you@mail.com">
        </div>

        <div class="field">
          <label class="field-label" for="password">Password <span class="req" title="Required">*</span></label>
          <div class="password-wrap">
            <input class="form-input" type="password" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
            <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Show or hide password">
              <svg class="pw-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="pw-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l16 16"/><path d="M10.6 6A9.6 9.6 0 0 1 12 5.8c6 0 9.5 6.2 9.5 6.2a18.4 18.4 0 0 1-4.4 4.8M6.5 7.1A17.6 17.6 0 0 0 2.5 12S6 18.2 12 18.2a10 10 0 0 0 4-.8"/></svg>
            </button>
          </div>
        </div>

        <p class="auth-forgot"><a href="forgot-password.php">Forgot password?</a>
          <?php if ($showVerificationLink): ?> &middot; <a href="verify-email.php">Verify email</a><?php endif; ?>
        </p>

        <button type="submit" class="btn-submit">
          <span>Log in</span>
          <span class="btn-orb">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>
          </span>
        </button>
      </form>

      <p class="auth-switch">New here? <a href="create-account.php">Create an account</a> &middot; Not registered with the barangay? <a href="register.php">Register</a></p>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
(function () {
  /* Scroll choreography - IntersectionObserver adds .is-in to panels
     as they enter the viewport (CSS handles the heavy fade-up). */
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

  /* Password visibility toggle with icon morph. */
  var toggle = document.getElementById('pw-toggle');
  var input = document.getElementById('password');
  if (toggle && input) {
    var showIcon = toggle.querySelector('.pw-show');
    var hideIcon = toggle.querySelector('.pw-hide');
    toggle.addEventListener('click', function () {
      var reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      if (showIcon) showIcon.style.opacity = reveal ? '0' : '1';
      if (hideIcon) hideIcon.style.opacity = reveal ? '1' : '0';
    });
  }

  var form = document.getElementById('login-form');
  var errorBox = document.getElementById('login-errors');

  form.addEventListener('submit', function (e) {
    if (form.dataset.skipAjax === 'true') {
      return; // Let this one submission go through natively.
    }
    e.preventDefault();
    fetch('login.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new FormData(form),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          window.location.href = data.redirect;
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
        // If fetch itself fails for any reason, fall back to one normal
        // submission - the page still works without JavaScript, just
        // with a reload for that one attempt.
        form.dataset.skipAjax = 'true';
        form.submit();
      });
  });
})();
</script>
</body>
</html>
