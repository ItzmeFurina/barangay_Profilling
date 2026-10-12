<?php
$hasVerificationNotice = is_array($verificationNotice ?? null);
$verificationEmailSent = $hasVerificationNotice && ($verificationNotice['sent'] ?? false) === true;
$staffRegistrationAlreadySubmitted = ($verificationNotice['workflow'] ?? '') === 'staff-submitted';
$staffAccountRequest = ($verificationNotice['workflow'] ?? '') === 'staff-create-account';
$residentAccountRequest = ($verificationNotice['workflow'] ?? '') === 'create-account';
$residentRegistration = ($verificationNotice['workflow'] ?? '') === 'register';
?>
<?php if ($hasVerificationNotice): ?>
<dialog class="verification-notice" id="verification-notice" data-workflow="<?= e((string) ($verificationNotice['workflow'] ?? '')) ?>" open aria-labelledby="verification-notice-title">
  <div class="verification-notice-icon" aria-hidden="true"><?= $verificationEmailSent ? '&#10003;' : '!' ?></div>
  <h2 id="verification-notice-title"><?= $staffRegistrationAlreadySubmitted ? 'Registration submitted' : ($verificationEmailSent ? 'Check your email' : 'Registration not submitted') ?></h2>
  <?php if ($verificationEmailSent): ?>
      <?php if ($staffRegistrationAlreadySubmitted): ?>
        <p>Your email has been verified and your staff application was submitted for Admin review. You can sign in after the Admin approves your account.</p>
      <?php elseif ($staffAccountRequest): ?>
        <p>Open the verification link and confirm your email. Your staff application will then be submitted automatically for Admin review. Closing this notice does not cancel your application.</p>
      <?php elseif ($residentAccountRequest): ?>
        <p>Open the verification link and confirm your email. Your account request will then be submitted automatically for barangay review. Closing this notice does not cancel your request.</p>
      <?php elseif ($residentRegistration): ?>
        <p>Open the verification link and confirm your email. Your resident registration will then be submitted automatically for barangay review. Closing this notice does not cancel your registration.</p>
      <?php else: ?>
        <p>We sent a verification link to the email address you entered. Open that email and confirm your address. Then return to this registration page and press <strong>Submit for review</strong>.</p>
      <?php endif; ?>
  <?php else: ?>
      <?php if ($staffRegistrationAlreadySubmitted): ?>
        <p>Your staff application was submitted for Admin review, but we could not send the verification email. Please contact the site administrator to configure email before signing in.</p>
      <?php elseif ($staffAccountRequest): ?>
        <p>Your staff application has not been submitted for review because we could not send the verification email. Please contact the site administrator to configure email, then complete the form again.</p>
      <?php elseif ($residentRegistration): ?>
        <p>Your registration has not been submitted for review because we could not send the verification email. Please try again later or contact the site administrator.</p>
      <?php else: ?>
        <p>Your information has not been submitted for review because we could not send the verification email. Please contact the site administrator to configure email, then complete the form again.</p>
      <?php endif; ?>
    <?php endif; ?>
    <form method="dialog">
      <button type="submit" class="verification-notice-button"><?= ($residentAccountRequest || $staffAccountRequest || $residentRegistration) ? 'Close for now' : ($verificationEmailSent ? 'Got it, check my email' : 'Close') ?></button>
    </form>
</dialog>
<style>
.verification-notice {
  position: fixed;
  inset: 0;
  z-index: 1000;
  margin: auto;
  width: min(440px, calc(100% - 36px));
  padding: 32px;
  border: 1px solid rgba(34, 28, 19, 0.14);
  border-radius: 24px;
  color: #221C13;
  background: #FFFEFA;
  box-shadow: 0 24px 80px rgba(11, 46, 92, 0.24);
  text-align: center;
  font-family: 'IBM Plex Sans', sans-serif;
}
.verification-notice::backdrop { background: rgba(11, 28, 50, 0.58); backdrop-filter: blur(3px); }
.verification-notice-icon {
  display: grid;
  place-items: center;
  width: 54px;
  height: 54px;
  margin: 0 auto 14px;
  border-radius: 50%;
  color: #2B5F1F;
  background: #EAF3DE;
  font-size: 26px;
  font-weight: 700;
}
.verification-notice h2 { margin: 0 0 10px; color: #0B2E5C; font-family: 'Fraunces', Georgia, serif; }
.verification-notice p { color: #6B6253; line-height: 1.6; }
.verification-notice a { color: #8A5D0B; font-weight: 600; }
.verification-notice-button {
  display: inline-block;
  margin-top: 10px;
  padding: 12px 22px;
  border: 0;
  border-radius: 999px;
  color: #fff;
  background: #0B2E5C;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
}
</style>
<script>
(function () {
  var notice = document.getElementById('verification-notice');
  if (notice && typeof notice.showModal === 'function') {
    notice.removeAttribute('open');
    notice.showModal();
  }
})();
</script>
<?php endif; ?>
