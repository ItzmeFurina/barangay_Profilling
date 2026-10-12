<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/includes/census_upsert.php';

no_cache();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

try {
    $state = handle_email_verification(get_db_connection(), 'resident');
    $state['success'] = $state['success'] ?? flash('email_verified');
    $workflow = $state['verified_registration_workflow'] ?? null;
    if (in_array($workflow, ['register', 'create-account'], true)) {
        try {
            $draftId = is_string($_SESSION['verified_registration_draft_id'] ?? null)
                ? $_SESSION['verified_registration_draft_id'] : '';
            if ($workflow === 'register') {
                submit_verified_resident_registration_draft(get_db_connection(), $draftId);
            } else {
                submit_verified_resident_account_draft(get_db_connection(), $draftId);
            }
            unset($_SESSION['verified_registration_draft_id'], $_SESSION['verified_registration_workflow']);
            flash('success', $workflow === 'register'
                ? 'Your email is verified and your resident registration has been submitted for barangay review.'
                : 'Your email is verified and your account request has been submitted for barangay review.');
            flash('registration_submitted', $workflow);
            redirect('verify-email.php');
        } catch (Throwable $error) {
            error_log('Verified resident registration could not be submitted: ' . $error->getMessage());
            $state['errors'][] = 'Your email was verified, but we could not submit your request. Please continue from your saved request.';
        }
    }
    $registrationSubmitted = flash('registration_submitted');
    if ($registrationSubmitted) {
        $state['success'] = $registrationSubmitted === 'register'
            ? 'Email verification confirmed successfully. Your resident registration has been submitted for barangay review.'
            : 'Email verification confirmed successfully. Your account request has been submitted for barangay review.';
    }
} catch (PDOException $error) {
    error_log('Email verification request could not be processed.');
    http_response_code(500);
    $state = [
        'errors' => ['We could not process your request right now. Please try again later.'],
        'success' => null,
        'email' => '',
        'pending' => false,
        'verified_registration_workflow' => null,
    ];
    $registrationSubmitted = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Verify Email - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<main class="auth-stage">
  <section class="auth-reveal auth-shell">
    <div class="auth-core">
      <div class="auth-head">
        <h1><?= $registrationSubmitted || $state['verified_registration_workflow'] ? 'Email verification confirmed successfully' : 'Verify your email' ?></h1>
        <p class="auth-subtitle"><?= $registrationSubmitted
            ? ($registrationSubmitted === 'register'
                ? 'Your resident registration has been submitted and is awaiting barangay review.'
                : 'Your account request has been submitted and is awaiting barangay review.')
            : ($state['verified_registration_workflow']
                ? 'Your email is confirmed. Your saved request is being submitted for review.'
                : 'Email verification is separate from the barangay account approval process.') ?></p>
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
      <?php if ($registrationSubmitted): ?>
        <a class="btn-submit" style="display:block;text-align:center" href="login.php">Continue to login</a>
      <?php elseif ($state['verified_registration_workflow']): ?>
        <?php $registrationPage = $state['verified_registration_workflow'] === 'create-account' ? 'create-account.php' : 'register.php'; ?>
        <a class="btn-submit" style="display:block;text-align:center" href="<?= e($registrationPage) ?>">Return to registration</a>
      <?php endif; ?>
      <p class="auth-switch"><a href="login.php">Back to login</a></p>
    </div>
  </section>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
(function () {
  var workflow = <?= json_encode($state['verified_registration_workflow'] ?? null) ?>;
  var registrationSubmitted = <?= json_encode((bool) $registrationSubmitted) ?>;
  var registrationWorkflow = <?= json_encode($registrationSubmitted ?: $workflow) ?>;
  if (['create-account', 'register'].indexOf(registrationWorkflow) !== -1 && window.BroadcastChannel) {
    var channel = new BroadcastChannel('barangay420-email-verification');
    channel.postMessage({
      type: registrationSubmitted ? 'submitted' : 'verified',
      workflow: registrationWorkflow
    });
    channel.close();
  }
})();
</script>
</body>
</html>
