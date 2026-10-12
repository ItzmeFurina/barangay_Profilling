<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/auth_security.php';
require_once __DIR__ . '/../shared/mail.php';
require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/partials/member-fieldset.php';
require_once __DIR__ . '/includes/census_upsert.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// Allow 'pending': after a successful resubmission this page must keep
// rendering its "still under review" screen instead of logging the user out.
require_resident_login(true);
$activePage = 'resubmit';

$pdo = get_db_connection();

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.email, u.contact_number, u.account_status,
            r.resident_id, r.household_id, r.household_member_id, r.census_completed
     FROM users u
     JOIN residents r ON r.user_id = u.user_id
     WHERE u.user_id = ? AND u.role = 'resident'"
);
$stmt->execute([$_SESSION['user_id']]);
$account = $stmt->fetch();

if (!$account) {
    unset($_SESSION['user_id'], $_SESSION['resident_id'], $_SESSION['full_name']);
    header('Location: login.php');
    exit;
}

if ($account['account_status'] === 'active') {
    redirect('profile.php');
}

$isRegisterOrigin = !empty($account['census_completed']);

$docStmt = $pdo->prepare(
    'SELECT document_id, document_type, verified_status, rejection_reason, file_path
     FROM verification_documents
     WHERE user_id = ?
     ORDER BY document_id DESC'
);
$docStmt->execute([$_SESSION['user_id']]);
$docs = $docStmt->fetchAll();

$rejectionReason = '';
foreach ($docs as $doc) {
    if ($doc['verified_status'] === 'rejected' && trim((string) $doc['rejection_reason']) !== '') {
        $rejectionReason = $doc['rejection_reason'];
        break;
    }
}

$prevHousehold = ['house_number' => '', 'street' => '', 'barangay' => 'Barangay 420', 'city' => 'Manila', 'old_address' => '', 'monthly_income' => ''];
$prevMembers = [[]];
$prevDocType = 'Electricity bill';

$prev = [
    'contact_number' => (string) $account['contact_number'],
    'email'          => (string) $account['email'],
];

$currentDocType = '';
foreach ($docs as $doc) {
    if ($doc['document_type'] !== '' && $doc['document_type'] !== 'Barangay ID') {
        $currentDocType = $doc['document_type'];
        break;
    }
}
if ($currentDocType !== '') {
    $prevDocType = $currentDocType;
}

$matchedRecord = null; // used by the create-account (already-registered) flow
if (!$isRegisterOrigin && $account['household_member_id']) {
    $stmt = $pdo->prepare(
        'SELECT hm.member_id, hm.first_name, hm.middle_name, hm.last_name, hm.suffix,
                h.house_number, h.street, h.barangay, h.city
         FROM household_members hm
         LEFT JOIN households h ON h.household_id = hm.household_id
         WHERE hm.member_id = ?'
    );
    $stmt->execute([$account['household_member_id']]);
    $matchedRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($isRegisterOrigin && $account['household_id']) {
    $stmt = $pdo->prepare(
        'SELECT house_number, street, barangay, city, old_address, monthly_income
         FROM households WHERE household_id = ?'
    );
    $stmt->execute([$account['household_id']]);
    $hh = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($hh) {
        $prevHousehold = [
            'house_number'   => (string) $hh['house_number'],
            'street'         => (string) $hh['street'],
            'barangay'       => (string) $hh['barangay'],
            'city'           => (string) $hh['city'],
            'old_address'    => (string) ($hh['old_address'] ?? ''),
            'monthly_income' => (string) ($hh['monthly_income'] ?? ''),
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT member_id, last_name, first_name, middle_name, suffix, place_of_birth,
                birthdate, sex, civil_status, citizenship, occupation,
                is_household_head, is_pwd, is_solo_parent, is_ofw, is_osy, is_ip,
                is_unemployed, is_employed, is_student
         FROM household_members
         WHERE household_id = ? AND is_archived = FALSE
         ORDER BY (member_id = ?) DESC, member_id ASC'
    );
    $stmt->execute([$account['household_id'], $account['household_member_id']]);
    $memberRows = $stmt->fetchAll();

    $headIndex = 0;
    foreach ($memberRows as $idx => $row) {
        if (!empty($row['is_household_head'])) {
            $headIndex = $idx;
            break;
        }
    }

    $prevMembers = [];
    foreach ($memberRows as $idx => $row) {
        $memberData = [
            'last_name'         => (string) $row['last_name'],
            'first_name'        => (string) $row['first_name'],
            'middle_name'       => (string) $row['middle_name'],
            'suffix'            => (string) $row['suffix'],
            'place_of_birth'    => (string) $row['place_of_birth'],
            'birthdate'         => (string) $row['birthdate'],
            'sex'               => (string) $row['sex'],
            'civil_status'      => (string) $row['civil_status'],
            'citizenship'       => (string) $row['citizenship'],
            'occupation'        => (string) $row['occupation'],
            '_head'             => $headIndex,
        ];
        foreach (['is_pwd', 'is_solo_parent', 'is_ofw', 'is_osy', 'is_ip', 'is_unemployed', 'is_employed', 'is_student'] as $flag) {
            if (!empty($row[$flag])) {
                $memberData[$flag] = 1;
            }
        }
        $prevMembers[] = $memberData;
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account['account_status'] === 'rejected' or $errors[] = 'This submission is no longer waiting for your changes.';

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $prev = [
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'email'          => normalize_email($_POST['email'] ?? ''),
        ];

        if ($isRegisterOrigin) {
            $prevHousehold = [
                'house_number'   => normalize_label($_POST['house_number'] ?? ''),
                'street'         => normalize_label($_POST['street'] ?? ''),
                'barangay'       => normalize_label($_POST['barangay'] ?? ''),
                'city'           => normalize_label($_POST['city'] ?? ''),
                'old_address'    => normalize_label($_POST['old_address'] ?? ''),
                'monthly_income' => trim($_POST['monthly_income'] ?? ''),
            ];
            $submittedMembers = $_POST['members'] ?? [];
            $headIndex = $_POST['household_head_index'] ?? '0';
            $allowedDocTypes = ['Electricity bill', 'Water bill', 'Other valid document'];
            $prevDocType = in_array($_POST['doc_type'] ?? '', $allowedDocTypes, true) ? $_POST['doc_type'] : $prevDocType;

            $errors = array_merge($errors, census_validate_household($prevHousehold));
            $memberResult = census_validate_members($submittedMembers, $headIndex);
            $errors = array_merge($errors, $memberResult['errors']);
            $cleanMembers = $memberResult['members'];
        } else {
            $cleanMembers = [];
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

        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $passwordProvided = $password !== '';
        if ($passwordProvided && (strlen($password) < 12 || strlen($password) > 72)) {
            $errors[] = 'New password must be between 12 and 72 bytes.';
        }
        if ($passwordProvided && $password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        $newFile = isset($_FILES['proof']) ? $_FILES['proof'] : null;
        $fileProvided = $newFile && ($newFile['error'] ?? null) !== UPLOAD_ERR_NO_FILE;
        $mime = null;
        if ($fileProvided && $newFile['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was a problem uploading your document. Please try again.';
        } elseif ($fileProvided) {
            $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($newFile['tmp_name']);
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Document must be a JPG, PNG, or PDF file.';
            }
            if ($newFile['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Document must be smaller than 5MB.';
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
            $stmt->execute([$prev['email'], $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with that email already exists.';
            }
        }

        if (empty($errors)) {
            $savedUploadPath = null;
            $alreadySubmitted = false;
            $verificationToken = '';
            $emailChanged = strcasecmp((string) $account['email'], $prev['email']) !== 0;
            try {
                $pdo->beginTransaction();

                $updates = [];
                $bindings = [];
                $updates[] = 'contact_number = ?';
                $bindings[] = $prev['contact_number'];
                $updates[] = 'email = ?';
                $bindings[] = $prev['email'];
                if ($emailChanged) {
                    $updates[] = 'email_verified_at = NULL';
                    $updates[] = 'email_verification_required = TRUE';
                }
                $newPasswordHash = null;
                if ($passwordProvided) {
                    $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);
                    $updates[] = 'password_hash = ?';
                    $bindings[] = $newPasswordHash;
                }
                $updates[] = "account_status = 'pending'";
                $bindings[] = $_SESSION['user_id'];
                // Atomic state-machine guard: only a row still sitting in
                // 'rejected' can be resubmitted. The status check at the top
                // of this handler ran before validation and file uploads, so
                // a double-click / second tab would otherwise pass it twice
                // and insert a duplicate verification document. A concurrent
                // submit (or an admin archiving the account meanwhile) makes
                // this rowCount() 0.
                $usersStmt = $pdo->prepare(
                    "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ? AND account_status = 'rejected'"
                );
                $usersStmt->execute($bindings);
                if ($usersStmt->rowCount() === 0) {
                    $alreadySubmitted = true;
                    throw new RuntimeException('Resubmit precondition no longer holds.');
                }
                if ($emailChanged) {
                    $verificationToken = issue_email_verification_token($pdo, (int) $_SESSION['user_id']);
                }

                $householdId = $account['household_id'];
                $memberId = $account['household_member_id'];

                if ($isRegisterOrigin) {
                    $saved = census_save($pdo, $prevHousehold, $cleanMembers, (int) $_SESSION['user_id']);
                    $householdId = $saved['household_id'];
                    $memberId = $saved['member_id'];

                    $pdo->prepare(
                        'UPDATE residents
                         SET household_id = ?, household_member_id = ?, house_number = ?, street = ?, barangay = ?, city = ?
                         WHERE resident_id = ?'
                    )->execute([
                        $householdId,
                        $memberId,
                        $prevHousehold['house_number'] !== '' ? $prevHousehold['house_number'] : null,
                        $prevHousehold['street'],
                        $prevHousehold['barangay'],
                        $prevHousehold['city'],
                        $_SESSION['resident_id'],
                    ]);
                }

                if ($fileProvided) {
                    $uploadDir = __DIR__ . '/../uploads/verification/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
                    if ($ext === null) {
                        throw new RuntimeException('Unsupported document type after validation.');
                    }
                    $safeName = 'user_' . $_SESSION['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (!move_uploaded_file($newFile['tmp_name'], $uploadDir . $safeName)) {
                        throw new RuntimeException('Could not save the uploaded verification document.');
                    }
                    $savedUploadPath = $uploadDir . $safeName;

                    $docType = $isRegisterOrigin ? $prevDocType : 'Barangay ID';
                    $pdo->prepare(
                        'INSERT INTO verification_documents (user_id, document_type, file_path)
                         VALUES (?, ?, ?)'
                    )->execute([$_SESSION['user_id'], $docType, 'verification/' . $safeName]);
                } else {
                    // Keep the previous document, but reopen it for review so
                    // the rejection no longer shows as final. Only rejected
                    // rows are touched: a blanket WHERE user_id used to reset
                    // EVERY document - including ones an admin already
                    // approved - back to pending and wiped verified_by/at.
                    $pdo->prepare(
                        "UPDATE verification_documents
                         SET verified_status = 'pending', rejection_reason = NULL, verified_by = NULL, verified_at = NULL
                         WHERE user_id = ? AND verified_status = 'rejected'"
                    )->execute([$_SESSION['user_id']]);
                }

                log_activity($pdo, $_SESSION['user_id'], 'Registration resubmitted for review', 'create');

                $pdo->commit();
                if ($emailChanged) {
                    send_email_verification_email($prev['email'], $verificationToken, 'resident');
                }

                // Keep this session's fingerprint in step with the password we
                // just wrote, otherwise the next page load would treat this
                // very session as a stale one and log the resident straight out.
                if ($newPasswordHash !== null) {
                    $_SESSION['pw_fp'] = hash('sha256', $newPasswordHash);
                }

                flash('success', $emailChanged
                    ? 'Your registration was resubmitted. If email delivery succeeds, verify your new email address; your application is under review again.'
                    : 'Your registration was resubmitted and is now under review again.');
                redirect('resubmit.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                if ($savedUploadPath !== null && is_file($savedUploadPath)) {
                    @unlink($savedUploadPath);
                }
                if ($alreadySubmitted) {
                    flash('error', 'Your registration was already resubmitted - no changes were saved.');
                    redirect('resubmit.php');
                }
                error_log('Registration resubmission failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your changes. Please try again.';
            }
        } else {
            if ($isRegisterOrigin) {
                $prevMembers = array_values($submittedMembers ?? []);
                foreach ($prevMembers as $i => &$pm) {
                    $pm['_head'] = $headIndex ?? '0';
                }
                unset($pm);
            }
        }
    }
}

$isPendingNow = $account['account_status'] === 'pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Registration status - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
<?= census_form_styles() ?>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>

<main class="auth-stage auth-stage--register">
  <aside class="auth-reveal">
    <h1 class="auth-title">Your registration<br><em>at a glance.</em></h1>
    <p class="auth-lede">Track where your resident application stands. If the admin rejected something, fix it here and resubmit &mdash; no need to start over.</p>
    <ul class="auth-rail">
      <li class="rail-card">
        <span class="rail-num">01</span>
        <div>
          <h3>Submitted</h3>
          <p>Your application was sent to the barangay office.</p>
        </div>
      </li>
      <li class="rail-card">
        <span class="rail-num">02</span>
        <div>
          <h3>Reviewed</h3>
          <p>The admin checks your details and documents.</p>
        </div>
      </li>
      <li class="rail-card">
        <span class="rail-num">03</span>
        <div>
          <h3>Approved</h3>
          <p>Once approved, you can use your resident account.</p>
        </div>
      </li>
    </ul>
  </aside>

  <section class="auth-reveal auth-shell" style="--d:120ms">
    <div class="auth-core">
      <div class="auth-head">
        <span class="auth-glyph">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z"/></svg>
        </span>
        <h2><?= $isPendingNow ? 'Still under review' : 'Tell us what went wrong' ?></h2>
      </div>

      <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success"><?= e($msg) ?></div>
      <?php endif; ?>

      <?php if ($isPendingNow): ?>
        <div class="alert alert-success"><strong>Your registration is now with the barangay admin for review.</strong> You will be able to sign in as soon as it is approved.</div>
        <p class="auth-switch"><a href="logout.php">Log out</a></p>
        <?php include __DIR__ . '/partials/footer.php'; ?>
        </body>
        </html>
        <?php exit; ?>
      <?php endif; ?>

      <?php if ($rejectionReason !== ''): ?>
        <div class="alert alert-error"><strong>Your submission was not approved.</strong> Reason given: <?= e($rejectionReason) ?></div>
      <?php else: ?>
        <div class="alert alert-error"><strong>Your submission was not approved.</strong> Please review the details below, fix anything that needs correcting, and resubmit.</div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (!$isRegisterOrigin && $matchedRecord): ?>
        <div class="census-section">
          <p class="muted-note">We matched this application to the following resident record:</p>
          <div class="alert alert-success">
            <strong><?= e(trim($matchedRecord['first_name'] . ' ' . $matchedRecord['middle_name'] . ' ' . $matchedRecord['last_name'] . ' ' . $matchedRecord['suffix'])) ?></strong><br>
            <?= e(trim(($matchedRecord['house_number'] ?? '') . ' ' . $matchedRecord['street'] . ', ' . $matchedRecord['barangay'] . ', ' . $matchedRecord['city'])) ?>
          </div>
          <p class="muted-note">If this is not you, please visit the barangay office so we can correct your match. You can still update your contact information and uploaded ID below.</p>
        </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <?php if ($isRegisterOrigin): ?>
          <section class="census-section">
            <div class="census-section-head">
              <span class="census-step-badge">1</span>
              <div>
                <h2 class="section-label">Household address</h2>
                <p class="muted-note">Update anything that is no longer correct.</p>
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
          </section>

          <section class="census-section">
            <div class="census-section-head">
              <span class="census-step-badge">2</span>
              <div>
                <h2 class="section-label">Household members</h2>
                <p class="muted-note">Update any household member details that were rejected.</p>
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
                <p class="muted-note">Leave the upload empty to keep your previous document.</p>
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
              <label class="field-label" for="proof">New proof of residency (optional - JPG, PNG, or PDF, max 5MB)</label>
              <label class="upload-zone" for="proof">
                <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.pdf">
                <span class="upload-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M8 7l4-4 4 4"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>
                </span>
                <span class="upload-label">Choose a new copy of your document</span>
                <span class="upload-sub">Optional &middot; JPG, PNG or PDF &middot; up to 5MB</span>
                <span class="upload-chip" hidden></span>
              </label>
            </div>
          </section>
        <?php else: ?>
          <section class="census-section">
            <div class="census-section-head">
              <span class="census-step-badge">1</span>
              <div>
                <h2 class="section-label">Your ID</h2>
                <p class="muted-note">Upload a new copy of your ID if the previous one was unclear.</p>
              </div>
            </div>

            <div class="field">
              <label class="field-label" for="proof">New Barangay ID / Valid ID (optional - JPG, PNG, or PDF, max 5MB)</label>
              <label class="upload-zone" for="proof">
                <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.pdf">
                <span class="upload-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M8 7l4-4 4 4"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>
                </span>
                <span class="upload-label">Choose a new copy of your ID</span>
                <span class="upload-sub">Optional &middot; JPG, PNG or PDF &middot; up to 5MB</span>
                <span class="upload-chip" hidden></span>
              </label>
            </div>
          </section>
        <?php endif; ?>

        <section class="census-section">
          <div class="census-section-head">
            <span class="census-step-badge"><?= $isRegisterOrigin ? 4 : 2 ?></span>
            <div>
              <h2 class="section-label">Contact &amp; login</h2>
              <p class="muted-note">Leave the password empty to keep your current one.</p>
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
              <label class="field-label" for="password">New password</label>
              <div class="password-wrap">
                <input class="form-input" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Leave blank to keep current">
                <button type="button" class="pw-toggle" data-target="password" aria-label="Show or hide password">
                  <svg class="pw-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="pw-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l16 16"/><path d="M10.6 6A9.6 9.6 0 0 1 12 5.8c6 0 9.5 6.2 9.5 6.2a18.4 18.4 0 0 1-4.4 4.8M6.5 7.1A17.6 17.6 0 0 0 2.5 12S6 18.2 12 18.2a10 10 0 0 0 4-.8"/></svg>
                </button>
              </div>
            </div>
            <div class="field">
              <label class="field-label" for="confirm_password">Confirm new password</label>
              <div class="password-wrap">
                <input class="form-input" type="password" id="confirm_password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Repeat it">
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
            <span>Resubmit for review</span>
            <span class="btn-orb">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M8 7h9v9"/></svg>
            </span>
          </button>
          <p class="census-note">The admin will review your submission again.</p>
        </div>
      </form>

      <p class="auth-switch"><a href="logout.php">Log out</a></p>
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
})();
</script>
<?= census_form_scripts() ?>
</body>
</html>