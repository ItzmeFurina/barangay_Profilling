<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/partials/member-fieldset.php';
require_once __DIR__ . '/includes/census_upsert.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

if (resident_is_logged_in()) {
    redirect('profile.php');
}
$errors = [];
$errors = [];
$prev = [
    'contact_number' => '',
    'email'          => '',
];
$prevDocType = 'Electricity bill';
$prevHousehold = ['house_number' => '', 'street' => '', 'barangay' => 'Barangay 420', 'city' => 'Manila', 'old_address' => '', 'monthly_income' => ''];
$prevMembers = [[]];
$activePage = 'register';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_verified_draft') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $draftId = is_string($_SESSION['verified_registration_draft_id'] ?? null)
            ? $_SESSION['verified_registration_draft_id'] : '';
        try {
            submit_verified_resident_registration_draft(get_db_connection(), $draftId);
            unset($_SESSION['verified_registration_draft_id'], $_SESSION['verified_registration_workflow']);
            flash('success', 'Your email is verified and your resident registration has been submitted for barangay review.');
            redirect('login.php');
        } catch (Throwable $error) {
            error_log('Verified resident registration retry failed: ' . $error->getMessage());
            $errors[] = 'We could not submit your registration right now. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $prevHousehold = [
            'house_number' => normalize_label($_POST['house_number'] ?? ''),
            'street'       => normalize_label($_POST['street'] ?? ''),
            'barangay'     => normalize_label($_POST['barangay'] ?? ''),
            'city'         => normalize_label($_POST['city'] ?? ''),
            'old_address'  => normalize_label($_POST['old_address'] ?? ''),
            'monthly_income' => trim($_POST['monthly_income'] ?? ''),
        ];
        $submittedMembers = $_POST['members'] ?? [];
        $headIndex = $_POST['household_head_index'] ?? '0';
        $prevMembers = array_values($submittedMembers);
        foreach ($prevMembers as $i => &$pm) {
            $pm['_head'] = $headIndex;
        }
        unset($pm);

        $allowedDocTypes = ['Electricity bill', 'Water bill', 'Other valid document'];
        $prevDocType = in_array($_POST['doc_type'] ?? '', $allowedDocTypes, true) ? $_POST['doc_type'] : $allowedDocTypes[0];

        $prev = [
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'email'          => normalize_email($_POST['email'] ?? ''),
        ];

        $houseErrors = census_validate_household($prevHousehold);
        $errors = array_merge($errors, $houseErrors);

        $memberResult = census_validate_members($submittedMembers, $headIndex);
        $errors = array_merge($errors, $memberResult['errors']);
        $cleanMembers = $memberResult['members'];

        $normalizedContact = normalize_ph_mobile($prev['contact_number']);
        if ($normalizedContact === null) {
            $errors[] = 'Enter a valid 11-digit mobile number starting with 09.';
        } else {
            $prev['contact_number'] = $normalizedContact;
        }
        if (strlen($prev['email']) > 150 || !filter_var($prev['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        $password = $_POST['password'] ?? '';
        if (strlen($password) < 12 || strlen($password) > 72) {
            $errors[] = 'Password must be between 12 and 72 bytes.';
        }
        if (($password !== ($_POST['confirm_password'] ?? ''))) {
            $errors[] = 'Passwords do not match.';
        }

        if (!isset($_FILES['proof']) || $_FILES['proof']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please upload a proof of residency (electricity bill, water bill, or another valid document).';
        } elseif ($_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was a problem uploading your document. Please try again.';
        } else {
            $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['proof']['tmp_name']);
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Document must be a JPG, PNG, or PDF file.';
            }
            if ($_FILES['proof']['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Document must be smaller than 5MB.';
            }
        }

        try {
        if (empty($errors)) {
            $pdo = get_db_connection();
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
            $stmt->execute([$prev['email']]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with that email already exists.';
            }
        }

        // Duplicate protection: someone who is already in the barangay's
        // resident records should use Create Account, not register again.
        if (empty($errors) && isset($cleanMembers[0])) {
            $dupFirst = strtok(trim($cleanMembers[0]['first_name']), ' ') ?: '';
            $dupLast = trim($cleanMembers[0]['last_name']);
            $dupSuffix = (string) ($cleanMembers[0]['suffix'] ?? '');
            $dupBirthdate = $cleanMembers[0]['birthdate'] ?? null;
            $stmt = $pdo->prepare(
                'SELECT hm.member_id
                 FROM household_members hm
                 WHERE hm.is_archived = FALSE
                   AND LOWER(SUBSTRING_INDEX(hm.first_name, \' \', 1)) = LOWER(?)
                   AND LOWER(hm.last_name) = LOWER(?)
                   AND (? = \'\' OR LOWER(COALESCE(hm.suffix, \'\')) = LOWER(?))
                   AND (? IS NULL OR hm.birthdate <=> ?)
                 LIMIT 1'
            );
            $stmt->execute([$dupFirst, $dupLast, $dupSuffix, $dupSuffix, $dupBirthdate, $dupBirthdate]);
            if ($stmt->fetch()) {
                $errors[] = "We already have a resident record matching that name and birth date. If you are that person, please use the Create Account form instead of Register.";
                log_activity($pdo, null, "Possible duplicate detected for {$cleanMembers[0]['first_name']} {$cleanMembers[0]['last_name']} - directed to Create Account", 'read');
            }
        }

        if (empty($errors)) {
            $ipAllowed = auth_rate_limit_allows($pdo, 'resident-registration', 'ip', auth_client_ip(), 5, 900);
            $emailAllowed = auth_rate_limit_allows($pdo, 'resident-registration', 'email', $prev['email'], 3, 900);
            $uploadDir = __DIR__ . '/../uploads/verification/';
            if (!$ipAllowed || !$emailAllowed) {
                $errors[] = 'Too many registration attempts. Please try again later.';
            } elseif (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $errors[] = 'We could not save your registration document. Please try again.';
            } else {
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
                if ($ext === null) {
                    $errors[] = 'Document must be a JPG, PNG, or PDF file.';
                } else {
                    $documentName = 'draft_' . bin2hex(random_bytes(16)) . '.' . $ext;
                    $documentAbsolutePath = $uploadDir . $documentName;
                    if (!move_uploaded_file($_FILES['proof']['tmp_name'], $documentAbsolutePath)) {
                        $errors[] = 'We could not save your registration document. Please try again.';
                    } else {
                        try {
                            $me = $cleanMembers[0];
                            $fullName = trim(implode(' ', array_filter(
                                [$me['first_name'], $me['middle_name'], $me['last_name'], $me['suffix']],
                                fn ($part) => $part !== ''
                            )));
                            $draft = create_registration_draft(
                                $pdo,
                                'register',
                                $prev['email'],
                                [
                                    'full_name' => $fullName,
                                    'contact_number' => $prev['contact_number'],
                                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                    'household' => $prevHousehold,
                                    'members' => $cleanMembers,
                                    'document_type' => $prevDocType,
                                ],
                                'verification/' . $documentName
                            );
                            $sent = send_email_verification_email($prev['email'], $draft['token'], 'resident');
                            if (!$sent) {
                                $pdo->prepare('DELETE FROM registration_drafts WHERE draft_id = ?')->execute([$draft['draft_id']]);
                                @unlink($documentAbsolutePath);
                            }
                            flash('verification_notice', ['sent' => $sent, 'workflow' => 'register']);
                            redirect('register.php');
                        } catch (Throwable $error) {
                            if (isset($draft['draft_id'])) {
                                $pdo->prepare('DELETE FROM registration_drafts WHERE draft_id = ?')->execute([$draft['draft_id']]);
                            }
                            @unlink($documentAbsolutePath);
                            error_log('Resident registration draft or verification email failed: ' . $error->getMessage());
                            $errors[] = 'We could not start email verification right now. Please try again.';
                        }
                    }
                }
            }
        }
        } catch (Throwable $error) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Resident registration request failed: ' . $error->getMessage());
            $errors[] = 'We could not submit your registration right now. Please try again later.';
        }
    }
}
$draftId = is_string($_SESSION['verified_registration_draft_id'] ?? null)
    ? $_SESSION['verified_registration_draft_id'] : '';
$verifiedDraft = verified_registration_draft(get_db_connection(), $draftId);
if ($verifiedDraft && $verifiedDraft['workflow'] !== 'register') {
    $verifiedDraft = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Register - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
<?= census_form_styles() ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<main class="auth-stage auth-stage--register">
  <?php if ($verifiedDraft): ?>
  <section class="auth-reveal auth-shell is-in" style="grid-column: 1 / -1; max-width: 620px; margin: 0 auto;">
    <div class="auth-core">
      <div class="auth-head">
        <h1>Email verification confirmed successfully</h1>
        <p class="auth-subtitle">Your resident registration is ready for barangay review.</p>
      </div>
      <div class="alert alert-success">Your email is verified. Submit your saved resident registration for review.</div>
      <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <p><strong>Email:</strong> <?= e($verifiedDraft['email']) ?></p>
      <p>Your household details and proof of residency are saved securely. The Admin will review your application before approving your account.</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="submit_verified_draft">
        <button type="submit" class="btn-submit"><span>Retry submission</span></button>
      </form>
    </div>
  </section>
  <?php else: ?>
  <aside class="auth-reveal">
    <h1 class="auth-title">New to barangay?<br><em>Let's set you up.</em></h1>
    <p class="auth-lede">Register a resident account so you can stay informed about barangay programs, services, announcements, and important community updates. You'll answer a short household census, upload a proof of residency, and choose your login details &mdash; all in one place.</p>
    <ol class="auth-rail">
      <li class="rail-card">
        <span class="rail-num">01</span>
        <div>
          <h3>Household census</h3>
          <p>Tell us who lives in your household, starting with yourself.</p>
        </div>
      </li>
      <li class="rail-card">
        <span class="rail-num">02</span>
        <div>
          <h3>Proof of residency</h3>
          <p>An electricity bill, water bill, or another valid document showing your barangay address.</p>
        </div>
      </li>
      <li class="rail-card">
        <span class="rail-num">03</span>
        <div>
          <h3>Contact &amp; login</h3>
          <p>A working mobile number, an email address, and a password to sign in.</p>
        </div>
      </li>
    </ol>
    <p class="rail-note">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z"/></svg>
      <strong>Only for people with no resident record yet.</strong> Already in our records? Use the Create Account form instead.
    </p>
  </aside>

  <section class="auth-reveal auth-shell" style="--d:120ms">
    <div class="auth-core">
      <div class="auth-head">
        <span class="auth-glyph">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><circle cx="8" cy="11" r="2"/><path d="M6 15c.5-1.3 1.2-2 2-2s1.5.7 2 2M13 9h5M13 13h5"/></svg>
        </span>
        <h2>Register your resident account</h2>
        <p class="auth-subtitle">Verify your email address to submit your household details and proof for barangay review.</p>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <section class="census-section">
          <div class="census-section-head">
            <span class="census-step-badge">1</span>
            <div>
              <h2 class="section-label">Household address</h2>
              <p class="muted-note">Where does your household live? Start yourself (and your household) off here.</p>
            </div>
          </div>

          <div class="member-grid">
            <div>
              <label for="house_number">House / Building / Unit No. <span class="req" title="Required">*</span></label>
              <input type="text" id="house_number" name="house_number" placeholder="e.g. 12, Unit 3B" value="<?= old('house_number', $prevHousehold) ?>" required>
            </div>
            <div>
              <label for="street">Street <span class="req" title="Required">*</span></label>
              <input type="text" id="street" name="street" placeholder="e.g. Purok 2, Mabini St." value="<?= old('street', $prevHousehold) ?>" required>
            </div>
            <div>
              <label for="barangay">Barangay <span class="req" title="Required">*</span></label>
              <input type="text" id="barangay" name="barangay" value="<?= old('barangay', $prevHousehold, 'Barangay 420') ?>" required>
            </div>
            <div>
              <label for="city">City <span class="req" title="Required">*</span></label>
              <input type="text" id="city" name="city" value="<?= old('city', $prevHousehold, 'Manila') ?>" required>
            </div>
          </div>

          <label for="old_address">Previous address, if your household recently moved (optional)</label>
          <input type="text" id="old_address" name="old_address" value="<?= old('old_address', $prevHousehold) ?>">

          <label for="monthly_income">Estimated monthly household income (&#8369;) - optional</label>
          <input type="number" id="monthly_income" name="monthly_income" min="0" step="0.01" placeholder="e.g. 25000" value="<?= old('monthly_income', $prevHousehold) ?>">
          <p class="muted-note">Optional, but some barangay programs use household income as a criterion.</p>
        </section>

        <section class="census-section">
          <div class="census-section-head">
            <span class="census-step-badge">2</span>
            <div>
              <h2 class="section-label">Household members</h2>
              <p class="muted-note">List everyone who lives in your household, starting with yourself. Fields marked <span class="req">*</span> are required.</p>
            </div>
          </div>

          <div id="members-container">
            <?php foreach ($prevMembers as $i => $memberData): ?>
              <?php render_member_fieldset($i, $memberData, $i === 0); ?>
            <?php endforeach; ?>
          </div>

          <button type="button" id="add-member-btn">+ Add another household member</button>

          <template id="member-template">
            <?php render_member_fieldset('__INDEX__', [], false); ?>
          </template>
        </section>

        <section class="census-section">
          <div class="census-section-head">
            <span class="census-step-badge">3</span>
            <div>
              <h2 class="section-label">Proof of residency</h2>
              <p class="muted-note">Upload a recent bill or document that shows a Barangay 420 address. The admin reviews this before approval.</p>
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="doc_type">Document type <span class="req" title="Required">*</span></label>
            <select class="form-input" id="doc_type" name="doc_type" required>
              <option value="Electricity bill" <?= $prevDocType === 'Electricity bill' ? 'selected' : '' ?>>Electricity bill</option>
              <option value="Water bill" <?= $prevDocType === 'Water bill' ? 'selected' : '' ?>>Water bill</option>
              <option value="Other valid document" <?= $prevDocType === 'Other valid document' ? 'selected' : '' ?>>Other valid document</option>
            </select>
          </div>

          <div class="field">
            <label class="field-label" for="proof">Proof of residency <span class="req" title="Required">*</span> (JPG, PNG, or PDF, max 5MB)</label>
            <label class="upload-zone" for="proof">
              <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.pdf" required>
              <span class="upload-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M8 7l4-4 4 4"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>
              </span>
              <span class="upload-label">Choose a copy of your bill or document</span>
              <span class="upload-sub">JPG, PNG or PDF &middot; up to 5MB &middot; must show a Barangay 420 address</span>
              <span class="upload-chip" hidden></span>
            </label>
          </div>
        </section>

        <section class="census-section">
          <div class="census-section-head">
            <span class="census-step-badge">4</span>
            <div>
              <h2 class="section-label">Contact &amp; login</h2>
              <p class="muted-note">How you'll sign in and how the barangay can reach you.</p>
            </div>
          </div>

          <div class="form-row-2">
            <div class="field">
              <label class="field-label" for="contact_number">Contact number <span class="req" title="Required">*</span></label>
              <input class="form-input" type="tel" id="contact_number" name="contact_number" placeholder="09XXXXXXXXX" value="<?= old('contact_number', $prev) ?>" required autocomplete="tel">
            </div>
            <div class="field">
              <label class="field-label" for="email">Email address <span class="req" title="Required">*</span></label>
              <input class="form-input" type="email" id="email" name="email" value="<?= old('email', $prev) ?>" maxlength="150" required autocomplete="email" placeholder="you@mail.com">
            </div>
            <div class="field">
              <label class="field-label" for="password">Password <span class="req" title="Required">*</span></label>
              <div class="password-wrap">
                <input class="form-input" type="password" id="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password" placeholder="12+ characters">
                <button type="button" class="pw-toggle" data-target="password" aria-label="Show or hide password">
                  <svg class="pw-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="pw-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l16 16"/><path d="M10.6 6A9.6 9.6 0 0 1 12 5.8c6 0 9.5 6.2 9.5 6.2a18.4 18.4 0 0 1-4.4 4.8M6.5 7.1A17.6 17.6 0 0 0 2.5 12S6 18.2 12 18.2a10 10 0 0 0 4-.8"/></svg>
                </button>
              </div>
            </div>
            <div class="field">
              <label class="field-label" for="confirm_password">Confirm password <span class="req" title="Required">*</span></label>
              <div class="password-wrap">
                <input class="form-input" type="password" id="confirm_password" name="confirm_password" minlength="12" maxlength="72" required autocomplete="new-password" placeholder="Repeat it">
                <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="Show or hide password">
                  <svg class="pw-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="pw-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l16 16"/><path d="M10.6 6A9.6 9.6 0 0 1 12 5.8c6 0 9.5 6.2 9.5 6.2a18.4 18.4 0 0 1-4.4 4.8M6.5 7.1A17.6 17.6 0 0 0 2.5 12S6 18.2 12 18.2a10 10 0 0 0 4-.8"/></svg>
                </button>
              </div>
            </div>
          </div>
        </section>

        <div class="census-actions">
          <button type="submit" class="btn-submit">
            <span>Verify email and submit</span>
            <span class="btn-orb">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>
            </span>
          </button>
          <p class="census-note">Confirm your email from the link we send. Your registration will then be sent for admin review.</p>
        </div>
      </form>

      <p class="auth-switch">Already in our records? <a href="create-account.php">Create an account</a></p>
      <p class="auth-switch">Already have an account? <a href="login.php">Log in</a></p>
    </div>
  </section>
  <?php endif; ?>
</main>

<?php
$verificationNotice = flash('verification_notice');
include __DIR__ . '/../shared/verification-notice.php';
?>
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
      var input = document.getElementById(btn.dataset.target);
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

  var zones = document.querySelectorAll('.upload-zone');
  for (var j = 0; j < zones.length; j++) {
    (function (zone) {
      var input = zone.querySelector('input[type="file"]');
      var chip = zone.querySelector('.upload-chip');
      if (!input || !chip) return;
      input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (!f) { chip.hidden = true; zone.classList.remove('has-file'); return; }
        chip.textContent = f.name;
        chip.hidden = false;
        zone.classList.add('has-file');
      });
    })(zones[j]);
  }

  var notice = document.getElementById('verification-notice');
  if (notice && window.BroadcastChannel && notice.getAttribute('data-workflow') === 'register') {
    var channel = new BroadcastChannel('barangay420-email-verification');
    channel.onmessage = function (event) {
      if (event.data && event.data.type === 'submitted' && event.data.workflow === 'register') {
        channel.close();
        if (notice.open) notice.close();
        window.location.href = 'login.php';
      }
    };
  }
})();
</script>
<?= census_form_scripts() ?>
</body>
</html>