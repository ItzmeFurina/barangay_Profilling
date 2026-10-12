<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_resident_login();
require_census_completed();
no_cache();
$activePage = 'programs';

$pdo = get_db_connection();

$beneficiaryId = (int) ($_GET['id'] ?? $_POST['beneficiary_id'] ?? 0);

// Ownership check: the beneficiary row has to belong to a member of THIS
// resident's own household - never trust the id in the URL alone, since
// it's just a plain sequential integer.
    $stmt = $pdo->prepare(
        'SELECT b.*, hm.first_name, hm.last_name, p.name AS program_name, p.program_type, p.description,
                p.category, p.provider, p.status AS program_status, p.start_date, p.end_date,
                p.application_deadline, p.slot_limit, p.release_period, p.application_mode,
                (SELECT COUNT(*) FROM beneficiaries b2
                 WHERE b2.program_id = p.program_id AND b2.claim_status = \'approved\') AS program_approved_count
         FROM beneficiaries b
         JOIN household_members hm ON hm.member_id = b.member_id AND hm.is_archived = FALSE
         JOIN households h ON h.household_id = hm.household_id
         JOIN residents r ON r.household_id = h.household_id
         JOIN programs p ON p.program_id = b.program_id
         WHERE b.beneficiary_id = ? AND r.resident_id = ?'
    );
$stmt->execute([$beneficiaryId, $_SESSION['resident_id']]);
$beneficiary = $stmt->fetch();

if (!$beneficiary) {
    flash('error', 'That benefit could not be found.');
    redirect('dashboard.php');
}
if ($beneficiary['claim_status'] !== 'qualified') {
    // Already applied, already decided, or somehow not eligible - nothing
    // to do here, the dashboard already reflects the current state.
    redirect('dashboard.php');
}

    // Program state gates: the dashboard hides the Apply button when the
    // program does not take applications, is inactive, or has not opened
    // yet, but a directly-opened link must not slip through and submit
    // documents for a program the barangay is not accepting.
    if (($beneficiary['application_mode'] ?? 'application_required') !== 'application_required') {
        flash('error', $beneficiary['program_name'] . ' does not take applications - the barangay adds matching households automatically.');
        redirect('dashboard.php');
    }
    if ($beneficiary['program_status'] !== 'active') {
        flash('error', 'Applications for ' . $beneficiary['program_name'] . ' are not open right now.');
        redirect('dashboard.php');
    }
    if ($beneficiary['start_date'] !== null && strtotime($beneficiary['start_date']) > time()) {
        flash('error', 'Applications for ' . $beneficiary['program_name'] . ' open on ' . date('M j, Y', strtotime($beneficiary['start_date'])) . '.');
        redirect('dashboard.php');
    }

    // Hard gate: applications stop once the deadline (or program end date, when
    // no explicit deadline is set) passes, and once approved beneficiaries fill
    // every available slot. The dashboard already hides the Apply button in both
    // cases - this keeps a directly-opened link from slipping through.
    $deadline = $beneficiary['application_deadline'] ?: $beneficiary['end_date'];
if ($deadline !== null && strtotime($deadline) < time()) {
    flash('error', 'Applications for ' . $beneficiary['program_name'] . ' closed on ' . date('M j, Y g:i A', strtotime($deadline)) . '.');
    redirect('dashboard.php');
}
if ($beneficiary['slot_limit'] !== null
    && (int) $beneficiary['slot_limit'] > 0
    && (int) $beneficiary['program_approved_count'] >= (int) $beneficiary['slot_limit']) {
    flash('error', 'All available slots for ' . $beneficiary['program_name'] . ' have been filled.');
    redirect('dashboard.php');
}

// A household under data review has automatic eligibility updates paused - a
// resident with ERROR-level flags cannot apply either, until the admin
// fixes the record (the dashboard already hides the Apply button).
$hhStmt = $pdo->prepare('SELECT household_id FROM residents WHERE resident_id = ?');
$hhStmt->execute([$_SESSION['resident_id']]);
$residentHouseholdId = (int) $hhStmt->fetchColumn();
if ($residentHouseholdId > 0) {
    $householdExplain = explain_household($pdo, $residentHouseholdId);
    if ($householdExplain['blocked']
        || !empty($householdExplain['explain'][(int) $beneficiary['member_id']]['blocked'])) {
        flash('error', 'Applications are paused while a data review is pending for your household. Please ask the barangay to fix the record.');
        redirect('dashboard.php');
    }
}

$idLabel = match ($beneficiary['program_type']) {
    'Senior' => 'Senior Citizen ID',
    'PWD'    => 'PWD ID',
    default  => 'Supporting ID',
};

// The documents the admin configured as required for this program
// (program_documents). Programs without any configured requirements fall
// back to the single legacy ID so older installs keep working unchanged.
$docReqStmt = $pdo->prepare(
    'SELECT document_id, label, files_required FROM program_documents
     WHERE program_id = ? ORDER BY display_order ASC, document_id ASC'
);
$docReqStmt->execute([(int) $beneficiary['program_id']]);
$docRequirements = $docReqStmt->fetchAll();
if (empty($docRequirements)) {
    $docRequirements = [['document_id' => 0, 'label' => $idLabel, 'files_required' => 1]];
}

$errors = [];
$prev = [
    'applicant_full_name'      => trim($beneficiary['first_name'] . ' ' . $beneficiary['last_name']),
    'applicant_contact_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $prev['applicant_full_name']      = normalize_person_name($_POST['applicant_full_name'] ?? '');
        $prev['applicant_contact_number'] = trim($_POST['applicant_contact_number'] ?? '');

        if ($prev['applicant_full_name'] === '') {
            $errors[] = 'Full name is required.';
        }
        $normalizedContact = normalize_ph_mobile($prev['applicant_contact_number']);
        if ($normalizedContact === null) {
            $errors[] = 'Enter a valid 11-digit active mobile number starting with 09.';
        } else {
            $prev['applicant_contact_number'] = $normalizedContact;
        }

        // Collapse the nested per-document file array into a flat, validated
        // list. A document requirement with files_required = N needs exactly
        // N uploaded files, each a JPG/PNG/PDF under 5MB.
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        $uploaded = $_FILES['document_uploads'] ?? null;
        $preparedFiles = [];
        foreach ($docRequirements as $docIdx => $req) {
            $label = $req['label'];
            $count = max(1, min(10, (int) $req['files_required']));
            for ($f = 0; $f < $count; $f++) {
                $file = null;
                if ($uploaded
                    && isset($uploaded['error'][$docIdx][$f], $uploaded['tmp_name'][$docIdx][$f])) {
                    $file = [
                        'error'    => $uploaded['error'][$docIdx][$f],
                        'name'     => $uploaded['name'][$docIdx][$f] ?? '',
                        'tmp_name' => $uploaded['tmp_name'][$docIdx][$f],
                        'size'     => $uploaded['size'][$docIdx][$f] ?? 0,
                    ];
                }
                if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                    $errors[] = "Please upload a copy of the {$label}.";
                } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = "There was a problem uploading the {$label}. Please try again.";
                } else {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                    if (!in_array($mime, $allowedMimes, true)) {
                        $errors[] = "The {$label} must be a JPG, PNG, or PDF file.";
                    }
                    if ($file['size'] > 5 * 1024 * 1024) {
                        $errors[] = "The {$label} must be smaller than 5MB.";
                    }
                    if (in_array($mime, $allowedMimes, true) && $file['size'] <= 5 * 1024 * 1024) {
                        $preparedFiles[] = ['label' => $label, 'tmp' => $file['tmp_name'], 'name' => $file['name']];
                    }
                }
            }
        }

        if (empty($errors)) {
            // Private upload, same as verification_documents at registration -
            // IDs are sensitive, so they live under uploads/ (blocked by
            // .htaccess) and are only ever served through a PHP-controlled,
            // admin-only route, never a direct public URL.
            $uploadDir = __DIR__ . '/../uploads/benefits/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Files land on disk first, then the DB write happens in one
            // transaction - if either step fails, the files written so far
            // are unlinked again so nothing orphaned is left behind.
            $savedFiles = [];
            $savedPaths = [];
            try {
                foreach ($preparedFiles as $n => $pf) {
                    $ext = strtolower(pathinfo($pf['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
                        $ext = 'bin'; // MIME was already validated; this keeps the name harmless.
                    }
                    $safeName = 'benefit_' . $beneficiaryId . '_' . bin2hex(random_bytes(8)) . "_$n.$ext";
                    $dest = $uploadDir . $safeName;
                    if (!move_uploaded_file($pf['tmp'], $dest)) {
                        throw new RuntimeException('Could not save the uploaded document.');
                    }
                    $savedFiles[] = $dest;
                    $savedPaths[] = ['label' => $pf['label'], 'path' => 'benefits/' . $safeName];
                }
            } catch (Exception $e) {
                foreach ($savedFiles as $f) {
                    @unlink($f);
                }
                error_log('Benefit document upload failed: ' . $e->getMessage());
                $errors[] = 'There was a problem saving your documents. Please try again.';
                $savedFiles = [];
                $savedPaths = [];
            }

            if (empty($errors) && !empty($savedPaths)) {
                $pdo->beginTransaction();
                try {
                    // State-machine guard inside the transaction: only a row
                    // still sitting in 'qualified' can be submitted. The fast
                    // check at the top of this page ran long before the files
                    // were uploaded, so without this a double-click / second
                    // tab would pass it twice and stack a duplicate set of
                    // benefit_documents onto one application.
                    $appliedStmt = $pdo->prepare(
                        "UPDATE beneficiaries
                         SET claim_status = 'pending',
                             applicant_full_name = ?,
                             applicant_contact_number = ?,
                             id_document_path = ?,
                             applied_at = NOW()
                         WHERE beneficiary_id = ? AND claim_status = 'qualified'"
                    );
                    $appliedStmt->execute([
                        $prev['applicant_full_name'],
                        $prev['applicant_contact_number'],
                        $savedPaths[0]['path'],
                        $beneficiaryId,
                    ]);

                    if ($appliedStmt->rowCount() === 0) {
                        $pdo->rollBack();
                        foreach ($savedFiles as $f) {
                            @unlink($f);
                        }
                        flash('error', 'Your application for ' . $beneficiary['program_name'] . ' was already submitted.');
                        redirect('dashboard.php');
                    }

                    $docInsert = $pdo->prepare(
                        'INSERT INTO benefit_documents (beneficiary_id, label, file_path) VALUES (?, ?, ?)'
                    );
                    foreach ($savedPaths as $sp) {
                        $docInsert->execute([$beneficiaryId, $sp['label'], $sp['path']]);
                    }

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    foreach ($savedFiles as $f) {
                        @unlink($f);
                    }
                    error_log('Benefit application save failed: ' . $e->getMessage());
                    $errors[] = 'Something went wrong while saving your application. Please try again.';
                }
            }
        }

        if (empty($errors)) {
            log_activity($pdo, $_SESSION['user_id'], 'Applied for ' . $beneficiary['program_name'], 'create');

            flash('success', 'Your application for ' . $beneficiary['program_name'] . ' was submitted. The barangay admin will review it.');
            redirect('dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Apply - <?= e($beneficiary['program_name']) ?> - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
<style>
.program-info {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 10px;
  margin: 14px 0 2px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 12px;
}
.program-info-item { display: flex; flex-direction: column; gap: 2px; }
.program-info-item span { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #3C5470; }
.program-info-item strong { font-size: 14px; color: #16233A; font-weight: 600; }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar-start.php'; ?>

<div class="dashboard">
  <section class="dashboard-welcome">
    <h1>Apply for <?= e($beneficiary['program_name']) ?></h1>
    <?php if (!empty($beneficiary['description'])): ?>
      <p class="muted-note"><?= e($beneficiary['description']) ?></p>
    <?php endif; ?>
  </section>

  <section class="dashboard-section">
    <p class="muted-note">Applying on behalf of <strong><?= e($beneficiary['first_name'] . ' ' . $beneficiary['last_name']) ?></strong>, who matched this benefit's requirements.</p>

    <div class="program-info">
      <?php $programDeadline = fmt_dt($beneficiary['application_deadline']) ?? fmt_dt($beneficiary['end_date']); ?>
      <div class="program-info-item"><span>Category</span><strong><?= !empty($beneficiary['category']) ? e($beneficiary['category']) : '&mdash;' ?></strong></div>
      <div class="program-info-item"><span>Source / provider</span><strong><?= !empty($beneficiary['provider']) ? e($beneficiary['provider']) : '&mdash;' ?></strong></div>
      <div class="program-info-item"><span>Applications close</span><strong><?= $programDeadline !== null ? e($programDeadline) : '&mdash;' ?></strong></div>
      <div class="program-info-item"><span>Benefit release</span><strong><?= !empty($beneficiary['release_period']) ? e($beneficiary['release_period']) : '&mdash;' ?></strong></div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="beneficiary_id" value="<?= $beneficiaryId ?>">

      <label for="applicant_full_name">Full name</label>
      <input type="text" id="applicant_full_name" name="applicant_full_name" value="<?= old('applicant_full_name', $prev) ?>" required>

      <label for="applicant_contact_number">Active contact number</label>
      <input type="text" id="applicant_contact_number" name="applicant_contact_number" placeholder="09XXXXXXXXX" value="<?= old('applicant_contact_number', $prev) ?>" required>

      <?php foreach ($docRequirements as $docIdx => $req): ?>
        <?php $docLabel = $req['label']; $docCount = max(1, min(10, (int) $req['files_required'])); ?>
        <h3 style="margin-top:20px;"><?= e($docLabel) ?></h3>
        <?php for ($f = 0; $f < $docCount; $f++): ?>
          <label for="document_upload_<?= $docIdx ?>_<?= $f ?>" style="margin-top:10px;">
            <?= e($docLabel) ?> (JPG, PNG, or PDF, max 5MB)<?= $docCount > 1 ? ' - file ' . ($f + 1) . ' of ' . $docCount : '' ?>
          </label>
          <input type="file" id="document_upload_<?= $docIdx ?>_<?= $f ?>" name="document_uploads[<?= $docIdx ?>][<?= $f ?>]" accept=".jpg,.jpeg,.png,.pdf" required>
        <?php endfor; ?>
      <?php endforeach; ?>

      <button type="submit" class="btn-primary" style="width:auto; padding:14px 28px; margin-top:20px;">Submit application</button>
      <a href="dashboard.php" style="margin-left:12px; font-size: 15px;">Cancel</a>
    </form>
  </section>
</div>

<?php include __DIR__ . '/partials/sidebar-end.php'; ?>
</body>
</html>