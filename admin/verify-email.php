<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/auth_security.php';

no_cache();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

try {
    $state = handle_email_verification(get_db_connection(), 'admin');
    $state['success'] = $state['success'] ?? flash('email_verified');
    if (($state['verified_registration_workflow'] ?? null) === 'staff-create-account') {
        try {
            $draftId = is_string($_SESSION['verified_registration_draft_id'] ?? null)
                ? $_SESSION['verified_registration_draft_id'] : '';
            submit_verified_staff_application_draft(get_db_connection(), $draftId);
            unset($_SESSION['verified_registration_draft_id'], $_SESSION['verified_registration_workflow']);
            flash('staff_application_submitted', 'submitted');
            redirect('verify-email.php');
        } catch (Throwable $error) {
            error_log('Verified staff application could not be submitted: ' . $error->getMessage());
            $state['errors'][] = 'Your email was verified, but we could not submit your staff application. Return to the saved application and retry.';
        }
    }
    $applicationSubmitted = flash('staff_application_submitted') === 'submitted';
    if ($applicationSubmitted) {
        $state['success'] = 'Email verification confirmed successfully. Your staff application has been submitted for Admin review.';
    }
} catch (PDOException $error) {
    error_log('Email verification request could not be processed.');
    http_response_code(500);
    $state = [
        'errors' => ['We could not process your request right now. Please try again later.'],
        'success' => null,
        'email' => '',
        'verified_registration_workflow' => null,
    ];
    $applicationSubmitted = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Verify Email - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<main class="auth-stage">
  <section class="auth-reveal auth-shell">
    <div class="auth-core">
      <div class="auth-head">
        <h1><?= $applicationSubmitted || ($state['verified_registration_workflow'] ?? null) === 'staff-create-account' ? 'Email verification confirmed successfully' : 'Verify your email' ?></h1>
        <p class="auth-subtitle"><?= $applicationSubmitted
            ? 'Your staff application has been submitted and is awaiting Admin review.'
            : (($state['verified_registration_workflow'] ?? null) === 'staff-create-account'
                ? 'Your email is confirmed. Your staff application is being submitted for Admin review.'
                : "Email verification is separate from the Admin's account approval process.") ?></p>
      </div>
      <?php if ($state['success'] !== null): ?>
        <div class="alert alert-success"><?= e($state['success']) ?></div>
      <?php endif; ?>
      <?php if (!empty($state['errors'])): ?>
        <div class="alert alert-error">
          <ul><?php foreach ($state['errors'] as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <?php if (!empty($state['pending'])): ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="verify">
          <button type="submit" class="btn-submit"><span>Confirm email verification</span></button>
        </form>
      <?php endif; ?>
      <?php if ($applicationSubmitted): ?>
        <a class="btn-submit" style="display:block;text-align:center" href="login.php">Continue to login</a>
      <?php elseif (($state['verified_registration_workflow'] ?? null) === 'staff-create-account'): ?>
        <a class="btn-submit" style="display:block;text-align:center" href="create-account.php">Return to staff registration</a>
      <?php endif; ?>
      <p class="auth-switch"><a href="login.php">Back to login</a></p>
    </div>
  </section>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
(function () {
  var verifiedWorkflow = <?= json_encode($state['verified_registration_workflow'] ?? null) ?>;
  var applicationSubmitted = <?= json_encode($applicationSubmitted) ?>;
  if ((verifiedWorkflow === 'staff-create-account' || applicationSubmitted) && window.BroadcastChannel) {
    var channel = new BroadcastChannel('barangay420-email-verification');
    channel.postMessage({
      type: applicationSubmitted ? 'submitted' : 'verified',
      workflow: 'staff-create-account'
    });
    channel.close();
  }

  var els = document.querySelectorAll('.auth-reveal');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!('IntersectionObserver' in window) || reduce) {
    for (var i = 0; i < els.length; i++) els[i].classList.add('is-in');
    return;
  }

  var io = new IntersectionObserver(function (entries) {
    for (var i = 0; i < entries.length; i++) {
      if (entries[i].isIntersecting) {
        entries[i].target.classList.add('is-in');
        io.unobserve(entries[i].target);
      }
    }
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
  for (var i = 0; i < els.length; i++) io.observe(els[i]);
})();
</script>
</body>
</html>
