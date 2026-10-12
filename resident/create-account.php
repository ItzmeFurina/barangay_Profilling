<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/mail.php';
require_once __DIR__ . '/../shared/config.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

if (resident_is_logged_in()) {
    redirect('profile.php');
}

$errors = [];
$verificationNotice = flash('verification_notice');
$prev = [];
$activePage = 'create-account';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isDraftSubmission = ($_POST['action'] ?? '') === 'submit_verified_draft';
    if ($isDraftSubmission) {
        if (!csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please refresh the page and try again.';
        } else {
            $draftId = is_string($_SESSION['verified_registration_draft_id'] ?? null)
                ? $_SESSION['verified_registration_draft_id'] : '';
            $draft = verified_registration_draft(get_db_connection(), $draftId);
            if (!$draft || $draft['workflow'] !== 'create-account') {
                $errors[] = 'This verified account request is no longer available. Please start again.';
                unset($_SESSION['verified_registration_draft_id'], $_SESSION['verified_registration_workflow']);
            } else {
                $pdo = get_db_connection();
                try {
                    submit_verified_resident_account_draft($pdo, $draftId);
                    unset($_SESSION['verified_registration_draft_id'], $_SESSION['verified_registration_workflow']);
                    flash('success', 'Your email is verified and your account request has been submitted for barangay review.');
                    redirect('login.php');
                } catch (Throwable $error) {
                    error_log('Verified resident account request could not be submitted.');
                    $errors[] = 'We could not submit your account request right now. Please try again.';
                }
            }
        }
    }
    if (!$isDraftSubmission) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        // Middle Name terminology: collect and normalize the full middle
        // name for all future input. The legacy 'middle_initial' POST key
        // is accepted as a fallback only (cached pages); stored records
        // are never rewritten by this path.
        $middleNameInput = $_POST['middle_name'] ?? ($_POST['middle_initial'] ?? '');
        $prev = [
            'first_name'      => normalize_person_name($_POST['first_name'] ?? ''),
            'middle_name'     => normalize_person_name($middleNameInput),
            'surname'         => normalize_person_name($_POST['surname'] ?? ''),
            'suffix'          => normalize_suffix($_POST['suffix'] ?? ''),
            'contact_number'  => trim($_POST['contact_number'] ?? ''),
            'email'           => normalize_email($_POST['email'] ?? ''),
            'house_number'    => normalize_label($_POST['house_number'] ?? ''),
            'street'          => normalize_label($_POST['street'] ?? ''),
            'barangay'        => normalize_label($_POST['barangay'] ?? ''),
            'city'            => normalize_label($_POST['city'] ?? ''),
        ];

        if ($prev['first_name'] === '' || $prev['surname'] === '') {
            $errors[] = 'First name and surname are required.';
        }
        $normalizedContact = normalize_ph_mobile($prev['contact_number']);
        if ($normalizedContact === null) {
            $errors[] = 'Enter a valid 11-digit mobile number starting with 09.';
        } else {
            $prev['contact_number'] = $normalizedContact;
        }
        if (strlen($prev['email']) > 150 || !filter_var($prev['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($prev['street'] === '') {
            $errors[] = 'Street is required.';
        }
        if ($prev['barangay'] === '') {
            $errors[] = 'Barangay is required.';
        }
        if ($prev['city'] === '') {
            $errors[] = 'City is required.';
        }

        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        if (strlen($password) < 12 || strlen($password) > 72) {
            $errors[] = 'Password must be between 12 and 72 bytes.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!isset($_FILES['barangay_id']) || $_FILES['barangay_id']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please upload a copy of your ID so we can match it to your resident record.';
        } elseif ($_FILES['barangay_id']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was a problem uploading your document. Please try again.';
        } else {
            $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['barangay_id']['tmp_name']);
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Document must be a JPG, PNG, or PDF file.';
            }
            if ($_FILES['barangay_id']['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Document must be smaller than 5MB.';
            }
        }

        $pdo = get_db_connection();

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
            $stmt->execute([$prev['email']]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with that email already exists.';
            }
        }

        $matchedMember = null;
        if (empty($errors)) {
            // Match against the barangay's existing resident records first.
            // Like the register form, this looks for a household member whose
            // first-name first word, last name and (optional) suffix line up,
            // and prefers the household whose address matches this form.
            $matchFirstWord = strtok(trim($prev['first_name']), ' ') ?: '';
            $stmt = $pdo->prepare(
                'SELECT hm.member_id, hm.household_id, hm.first_name, hm.last_name, hm.middle_name, hm.suffix,
                        h.house_number, h.street, h.barangay, h.city
                 FROM household_members hm
                 LEFT JOIN households h ON h.household_id = hm.household_id
                 WHERE hm.is_archived = FALSE
                   AND LOWER(SUBSTRING_INDEX(hm.first_name, \' \', 1)) = LOWER(?)
                   AND LOWER(hm.last_name) = LOWER(?)
                   AND (? = \'\' OR LOWER(COALESCE(hm.suffix, \'\')) = LOWER(?))'
            );
            $stmt->execute([$matchFirstWord, $prev['surname'], (string) $prev['suffix'], (string) $prev['suffix']]);
            $candidates = $stmt->fetchAll();

            if (empty($candidates)) {
                $errors[] = 'We could not find a resident record in Barangay 420 that matches that name. If you are new to the barangay, please use the Register form instead.';
                log_activity($pdo, null, "Create Account lookup: no resident match for {$prev['first_name']} {$prev['surname']}", 'read');
            } else {
                if (count($candidates) === 1) {
                    $matchedMember = $candidates[0];
                } else {
                    // Same-name people live in different households - prefer
                    // the one whose household address matches this form.
                    $street   = strtolower(trim($prev['street']));
                    $barangay = strtolower(trim($prev['barangay']));
                    $city     = strtolower(trim($prev['city']));
                    $addressMatches = array_filter($candidates, function ($m) use ($street, $barangay, $city) {
                        return strtolower(trim((string) $m['street'])) === $street
                            && strtolower(trim((string) $m['barangay'])) === $barangay
                            && strtolower(trim((string) $m['city'])) === $city;
                    });
                    if (count($addressMatches) === 1) {
                        $matchedMember = reset($addressMatches);
                    } else {
                        $errors[] = 'More than one resident record matches that name. Please visit the barangay office so we can confirm your record, or use the Register form if you are new to the barangay.';
                    }
                }

                // A resident who already has a linked account should not be
                // able to open a second one through this path.
                if ($matchedMember) {
                    $linkedStmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM household_members WHERE member_id = ? AND (linked_user_id IS NOT NULL OR is_archived = TRUE)'
                    );
                    $linkedStmt->execute([$matchedMember['member_id']]);
                    if ((int) $linkedStmt->fetchColumn() > 0) {
                        $matchedMember = null;
                        $errors[] = 'A resident account already exists for that record. Please log in instead.';
                    }
                }
            }
        }

        if (empty($errors)) {
            $ipAllowed = auth_rate_limit_allows($pdo, 'registration-verification', 'ip', auth_client_ip(), 5, 900);
            $emailAllowed = auth_rate_limit_allows($pdo, 'registration-verification', 'email', $prev['email'], 3, 900);
            $uploadDir = __DIR__ . '/../uploads/verification/';
            if (!$ipAllowed || !$emailAllowed) {
                $errors[] = 'Too many verification requests. Please try again later.';
            } elseif (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $errors[] = 'We could not save your account document. Please try again.';
            } else {
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
                if ($ext === null) {
                    $errors[] = 'Document must be a JPG, PNG, or PDF file.';
                } else {
                    $draftName = 'draft_' . bin2hex(random_bytes(16)) . '.' . $ext;
                    $draftPath = $uploadDir . $draftName;
                    if (!move_uploaded_file($_FILES['barangay_id']['tmp_name'], $draftPath)) {
                        $errors[] = 'We could not save your account document. Please try again.';
                    } else {
                        $fullName = trim(implode(' ', array_filter(
                            [$prev['first_name'], $prev['middle_name'], $prev['surname'], $prev['suffix']],
                            fn ($part) => $part !== ''
                        )));
                        try {
                            $draft = create_registration_draft(
                                $pdo,
                                'create-account',
                                $prev['email'],
                                [
                                    'email' => $prev['email'],
                                    'full_name' => $fullName,
                                    'contact_number' => $prev['contact_number'],
                                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                    'member_id' => (int) $matchedMember['member_id'],
                                    'household_id' => (int) $matchedMember['household_id'],
                                    'house_number' => $matchedMember['house_number'],
                                    'street' => $matchedMember['street'],
                                    'barangay' => $matchedMember['barangay'],
                                    'city' => $matchedMember['city'],
                                ],
                                'verification/' . $draftName
                            );
                            $sent = send_email_verification_email($prev['email'], $draft['token'], 'resident');
                            if (!$sent) {
                                $pdo->prepare('DELETE FROM registration_drafts WHERE draft_id = ?')->execute([$draft['draft_id']]);
                                @unlink($draftPath);
                            }
                            flash('verification_notice', ['sent' => $sent, 'workflow' => 'create-account']);
                            redirect('create-account.php');
                        } catch (Throwable $error) {
                            @unlink($draftPath);
                            error_log('Resident account draft could not be saved.');
                            $errors[] = 'We could not start email verification right now. Please try again.';
                        }
                    }
                }
            }
        }
    }
    }
}
$draftId = is_string($_SESSION['verified_registration_draft_id'] ?? null)
    ? $_SESSION['verified_registration_draft_id'] : '';
$verifiedDraft = verified_registration_draft(get_db_connection(), $draftId);
if ($verifiedDraft && $verifiedDraft['workflow'] !== 'create-account') {
    $verifiedDraft = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Create Account - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<main class="auth-stage auth-stage--register">
  <?php if ($verifiedDraft): ?>
  <section class="auth-reveal auth-shell is-in" style="grid-column: 1 / -1; max-width: 620px; margin: 0 auto;">
    <div class="auth-core">
      <div class="auth-head">
        <h1>Email verification confirmed successfully</h1>
        <p class="auth-subtitle">Your email is confirmed. Your account request has not been submitted yet.</p>
      </div>
      <div class="alert alert-success">Email verification confirmed successfully. Your account request is ready for submission.</div>
      <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <p><strong>Email:</strong> <?= e($verifiedDraft['email']) ?></p>
      <p>Your account request and uploaded ID are saved securely until submission. The Admin will still review your resident record and ID.</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="submit_verified_draft">
        <button type="submit" class="btn-submit"><span>Submit for review</span></button>
      </form>
    </div>
  </section>
  <?php else: ?>
  <aside class="auth-reveal">
    <h1 class="auth-title">You're one of us.<br><em>Now make it official.</em></h1>
    <p class="auth-lede">This is for residents who already have a household record at Barangay 420. We'll match the details you enter against our resident records, and the admin will double-check your ID before approving your account.</p>
    <ol class="auth-rail">
      <li class="rail-card">
        <span class="rail-num">01</span>
        <div>
          <h3>Barangay ID / Valid ID</h3>
          <p>Submit a copy of your ID so we can match it to your resident record.</p>
        </div>
      </li>
      <li class="rail-card">
        <span class="rail-num">02</span>
        <div>
          <h3>Resident record</h3>
          <p>We verify your name and address against the barangay's household records.</p>
        </div>
      </li>
      <li class="rail-card">
        <span class="rail-num">03</span>
        <div>
          <h3>Contact &amp; login</h3>
          <p>A working mobile number and email address for signing in.</p>
        </div>
      </li>
    </ol>
    <p class="rail-note">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z"/></svg>
      <strong>Already-registered residents only.</strong> New to the barangay? Use the Register form instead.
    </p>
  </aside>

  <section class="auth-reveal auth-shell" style="--d:120ms">
    <div class="auth-core">
      <div class="auth-head">
        <span class="auth-glyph">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15a6 6 0 0 1 0 12"/><path d="M16 21a4 4 0 1 0-8 0"/><path d="M9 7a3 3 0 1 1 6 0v10a3 3 0 0 1-6 0z"/><circle cx="12" cy="7" r="1.5"/></svg>
        </span>
        <h2>Create your account</h2>
        <p class="auth-subtitle">For residents with a household record on file. Have your ID ready &mdash; we use it to confirm you're the resident in our records.</p>
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

        <div class="field">
          <label class="field-label" for="first_name">First Name <span class="req" title="Required">*</span></label>
          <input class="form-input" type="text" id="first_name" name="first_name" value="<?= old('first_name', $prev) ?>" required autocomplete="given-name" placeholder="Juan">
        </div>
        <div class="field">
          <label class="field-label" for="middle_name">Middle Name</label>
          <input class="form-input" type="text" id="middle_name" name="middle_name" value="<?= old('middle_name', $prev) ?>" autocomplete="additional-name" placeholder="e.g. Santos">
        </div>
        <div class="field">
          <label class="field-label" for="surname">Surname <span class="req" title="Required">*</span></label>
          <input class="form-input" type="text" id="surname" name="surname" value="<?= old('surname', $prev) ?>" required autocomplete="family-name" placeholder="Dela Cruz">
        </div>
        <div class="field">
          <label class="field-label" for="suffix">Suffix (optional)</label>
          <input class="form-input" type="text" id="suffix" name="suffix" value="<?= old('suffix', $prev) ?>" autocomplete="honorific-suffix" placeholder="e.g. Jr., Sr., III">
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
        </div>

        <div class="form-section-label">Your address</div>

        <div class="form-row-2">
          <div class="field">
            <label class="field-label" for="house_number">House / Building / Unit No.</label>
            <input class="form-input" type="text" id="house_number" name="house_number" placeholder="e.g. 12, Unit 3B" value="<?= old('house_number', $prev) ?>">
          </div>
          <div class="field">
            <label class="field-label" for="street">Street <span class="req" title="Required">*</span></label>
            <input class="form-input" type="text" id="street" name="street" placeholder="e.g. Purok 2, Mabini St." value="<?= old('street', $prev) ?>" required autocomplete="address-line1">
          </div>
          <div class="field">
            <label class="field-label" for="barangay">Barangay <span class="req" title="Required">*</span></label>
            <input class="form-input" type="text" id="barangay" name="barangay" value="<?= old('barangay', $prev, 'Barangay 420') ?>" required autocomplete="address-level2">
          </div>
          <div class="field">
            <label class="field-label" for="city">City <span class="req" title="Required">*</span></label>
            <input class="form-input" type="text" id="city" name="city" value="<?= old('city', $prev, 'Manila') ?>" required autocomplete="address-level1">
          </div>
        </div>

        <div class="form-row-2">
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

        <div class="field">
          <label class="field-label" for="barangay_id">Barangay ID / Valid ID <span class="req" title="Required">*</span> (JPG, PNG, or PDF, max 5MB)</label>
          <label class="upload-zone" for="barangay_id">
            <input type="file" id="barangay_id" name="barangay_id" accept=".jpg,.jpeg,.png,.pdf" required>
            <span class="upload-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M8 7l4-4 4 4"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>
            </span>
            <span class="upload-label">Choose a copy of your ID</span>
            <span class="upload-sub">JPG, PNG or PDF &middot; up to 5MB</span>
            <span class="upload-chip" hidden></span>
          </label>
        </div>

        <button type="submit" class="btn-submit">
          <span>Submit for review</span>
          <span class="btn-orb">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>
          </span>
        </button>
      </form>

      <p class="auth-switch">New to Barangay 420? <a href="register.php">Register here</a></p>
      <p class="auth-switch">Already have an account? <a href="login.php">Log in</a></p>
    </div>
  </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../shared/verification-notice.php'; ?>
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
  if (notice && window.BroadcastChannel) {
    var workflow = notice.getAttribute('data-workflow');
    if (workflow === 'create-account') {
      var channel = new BroadcastChannel('barangay420-email-verification');
      channel.onmessage = function (event) {
        if (event.data
            && ['verified', 'submitted'].indexOf(event.data.type) !== -1
            && event.data.workflow === workflow) {
          channel.close();
          if (notice.open) notice.close();
          if (event.data.type === 'submitted') {
            window.location.href = 'login.php';
          } else {
            window.location.reload();
          }
        }
      };
    }
  }
})();
</script>
</body>
</html>