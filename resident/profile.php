<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_resident_login();
require_census_completed();
no_cache();
$activePage = 'profile';

$pdo = get_db_connection();
$errors = [];
$addErrors = [];
$addPrev = [];
$editMemberId = null;

$stmt = $pdo->prepare(
    'SELECT r.resident_id, r.household_id, r.household_member_id,
            u.user_id, u.full_name, u.contact_number, u.photo_path,
            hm.photo_path AS member_photo,
            h.house_number, h.street, h.barangay, h.city, h.monthly_income
     FROM residents r
     JOIN users u ON u.user_id = r.user_id
     LEFT JOIN household_members hm ON hm.member_id = r.household_member_id
     LEFT JOIN households h ON h.household_id = r.household_id
     WHERE r.resident_id = ?'
);
$stmt->execute([$_SESSION['resident_id']]);
$me = $stmt->fetch();

const AVATAR_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const AVATAR_MAX_BYTES = 5 * 1024 * 1024;

$avatarDir = __DIR__ . '/assets/avatars/';
if (!is_dir($avatarDir)) {
    mkdir($avatarDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload_photo') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (empty($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Choose a photo to upload.';
    } else {
        $file = $_FILES['profile_photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was a problem uploading the photo. Please try again.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset(AVATAR_MIMES[$mime])) {
                $errors[] = 'Profile photos must be JPG, PNG, or WEBP files.';
            } elseif ($file['size'] > AVATAR_MAX_BYTES) {
                $errors[] = 'Profile photo must be smaller than 5MB.';
            } else {
                $safeName = 'avatar_' . bin2hex(random_bytes(8)) . '.' . AVATAR_MIMES[$mime];
                if (!move_uploaded_file($file['tmp_name'], $avatarDir . $safeName)) {
                    $errors[] = 'There was a problem saving the photo. Please try again.';
                } else {
                    $oldUserPhoto = $me['photo_path'] ?? null;
                    $oldMemberPhoto = null;
                    if (!empty($me['household_member_id'])) {
                        $oldStmt = $pdo->prepare('SELECT photo_path FROM household_members WHERE member_id = ?');
                        $oldStmt->execute([(int) $me['household_member_id']]);
                        $oldMemberPhoto = $oldStmt->fetchColumn();
                    }

                    $pdo->prepare('UPDATE users SET photo_path = ? WHERE user_id = ?')
                        ->execute([$safeName, $me['user_id']]);
                    // Keep the household member record in sync so every
                    // other account in this household sees the new photo too.
                    if (!empty($me['household_member_id'])) {
                        $pdo->prepare('UPDATE household_members SET photo_path = ? WHERE member_id = ?')
                            ->execute([$safeName, (int) $me['household_member_id']]);
                    }
                    foreach ([$oldUserPhoto, $oldMemberPhoto] as $oldPath) {
                        if ($oldPath && $oldPath !== $safeName) {
                            $old = $avatarDir . basename($oldPath);
                            if (is_file($old)) {
                                unlink($old);
                            }
                        }
                    }
                    log_activity($pdo, $me['user_id'], 'Updated profile picture', 'update');
                    flash('success', 'Profile picture updated.');
                    redirect('profile.php');
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'remove_photo') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $oldUserPhoto = $me['photo_path'] ?? null;
        $oldMemberPhoto = null;
        if (!empty($me['household_member_id'])) {
            $oldStmt = $pdo->prepare('SELECT photo_path FROM household_members WHERE member_id = ?');
            $oldStmt->execute([(int) $me['household_member_id']]);
            $oldMemberPhoto = $oldStmt->fetchColumn();
        }

        $pdo->prepare('UPDATE users SET photo_path = NULL WHERE user_id = ?')
            ->execute([$me['user_id']]);
        if (!empty($me['household_member_id'])) {
            $pdo->prepare('UPDATE household_members SET photo_path = NULL WHERE member_id = ?')
                ->execute([(int) $me['household_member_id']]);
        }
        foreach ([$oldUserPhoto, $oldMemberPhoto] as $oldPath) {
            if ($oldPath) {
                $old = $avatarDir . basename($oldPath);
                if (is_file($old)) {
                    unlink($old);
                }
            }
        }
        log_activity($pdo, $me['user_id'], 'Removed profile picture', 'delete');
        flash('success', 'Profile picture removed.');
        redirect('profile.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload_member_photo') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if (!$me['household_id'] || !member_belongs_to_household($pdo, $memberId, (int) $me['household_id'])) {
            $errors[] = 'That household member could not be found.';
        } elseif (empty($_FILES['member_photo']) || $_FILES['member_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose a photo to upload.';
        } else {
            $file = $_FILES['member_photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'There was a problem uploading the photo. Please try again.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if (!isset(AVATAR_MIMES[$mime])) {
                    $errors[] = 'Member photos must be JPG, PNG, or WEBP files.';
                } elseif ($file['size'] > AVATAR_MAX_BYTES) {
                    $errors[] = 'Member photo must be smaller than 5MB.';
                } else {
                    $safeName = 'member_' . bin2hex(random_bytes(8)) . '.' . AVATAR_MIMES[$mime];
                    if (!move_uploaded_file($file['tmp_name'], $avatarDir . $safeName)) {
                        $errors[] = 'There was a problem saving the photo. Please try again.';
                    } else {
                        $oldStmt = $pdo->prepare('SELECT photo_path, linked_user_id FROM household_members WHERE member_id = ?');
                        $oldStmt->execute([$memberId]);
                        $oldRow = $oldStmt->fetch();
                        $oldPath = $oldRow['photo_path'] ?? null;
                        $linkedUserId = !empty($oldRow['linked_user_id']) ? (int) $oldRow['linked_user_id'] : 0;
                        $oldUserPhoto = null;
                        if ($linkedUserId) {
                            $uStmt = $pdo->prepare('SELECT photo_path FROM users WHERE user_id = ?');
                            $uStmt->execute([$linkedUserId]);
                            $oldUserPhoto = $uStmt->fetchColumn();
                        }

                        $pdo->prepare('UPDATE household_members SET photo_path = ? WHERE member_id = ?')
                            ->execute([$safeName, $memberId]);
                        // If this member has their own login account, keep
                        // its profile picture in sync so the person sees the
                        // same photo wherever they are logged in from.
                        if ($linkedUserId) {
                            $pdo->prepare('UPDATE users SET photo_path = ? WHERE user_id = ?')
                                ->execute([$safeName, $linkedUserId]);
                        }
                        foreach ([$oldPath, $oldUserPhoto] as $oldPathToDelete) {
                            if ($oldPathToDelete && $oldPathToDelete !== $safeName) {
                                $old = $avatarDir . basename($oldPathToDelete);
                                if (is_file($old)) {
                                    unlink($old);
                                }
                            }
                        }
                        log_activity($pdo, $me['user_id'], "Updated photo for household member #{$memberId}", 'update');
                        flash('success', 'Member photo updated.');
                        redirect('profile.php');
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'remove_member_photo') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if (!$me['household_id'] || !member_belongs_to_household($pdo, $memberId, (int) $me['household_id'])) {
            $errors[] = 'That household member could not be found.';
        } else {
            $oldStmt = $pdo->prepare('SELECT photo_path, linked_user_id FROM household_members WHERE member_id = ?');
            $oldStmt->execute([$memberId]);
            $oldRow = $oldStmt->fetch();
            $oldPath = $oldRow['photo_path'] ?? null;
            $linkedUserId = !empty($oldRow['linked_user_id']) ? (int) $oldRow['linked_user_id'] : 0;
            $oldUserPhoto = null;
            if ($linkedUserId) {
                $uStmt = $pdo->prepare('SELECT photo_path FROM users WHERE user_id = ?');
                $uStmt->execute([$linkedUserId]);
                $oldUserPhoto = $uStmt->fetchColumn();
            }

            $pdo->prepare('UPDATE household_members SET photo_path = NULL WHERE member_id = ?')
                ->execute([$memberId]);
            if ($linkedUserId) {
                $pdo->prepare('UPDATE users SET photo_path = NULL WHERE user_id = ?')
                    ->execute([$linkedUserId]);
            }
            foreach ([$oldPath, $oldUserPhoto] as $oldPathToDelete) {
                if ($oldPathToDelete) {
                    $old = $avatarDir . basename($oldPathToDelete);
                    if (is_file($old)) {
                        unlink($old);
                    }
                }
            }
            log_activity($pdo, $me['user_id'], "Removed photo for household member #{$memberId}", 'delete');
            flash('success', 'Member photo removed.');
            redirect('profile.php');
        }
    }
}

// Every action on this page touches a household_members row - always
// re-check it actually belongs to this resident's own household before
// writing anything, since a member_id is just a plain integer in the form.
function member_belongs_to_household(PDO $pdo, int $memberId, int $householdId): bool {
    $check = $pdo->prepare('SELECT 1 FROM household_members WHERE member_id = ? AND household_id = ? AND is_archived = FALSE');
    $check->execute([$memberId, $householdId]);
    return (bool) $check->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update_member') {
    $editMemberId = (int) ($_POST['member_id'] ?? 0);
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if (!$me['household_id'] || !member_belongs_to_household($pdo, $memberId, (int) $me['household_id'])) {
            $errors[] = 'That household member could not be found.';
        } else {
            $isAccountHolder = $memberId === (int) $me['household_member_id'];
            $occupationRaw = $_POST['occupation'] ?? '';
            $occupation = normalize_occupation($occupationRaw);
            $civilStatus = normalize_civil_status($_POST['civil_status'] ?? '') ?: null;
            $civilStatuses = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
            $flags = [
                'is_pwd'         => isset($_POST['is_pwd']),
                'is_solo_parent' => isset($_POST['is_solo_parent']),
                'is_ofw'         => isset($_POST['is_ofw']),
                'is_osy'         => isset($_POST['is_osy']),
                'is_ip'          => isset($_POST['is_ip']),
                'is_unemployed'  => isset($_POST['is_unemployed']),
                'is_employed'    => isset($_POST['is_employed']),
                'is_student'     => isset($_POST['is_student']),
            ];
            if ($civilStatus !== null && !in_array($civilStatus, $civilStatuses, true)) {
                $errors[] = 'Select a valid civil status.';
            }
            if (is_none_occupation($occupationRaw)) {
                $flags['is_unemployed'] = true;
                $flags['is_employed'] = false;
            } elseif ($occupation !== '' && $flags['is_unemployed']) {
                $errors[] = 'An occupation is listed, so this member cannot also be marked Unemployed.';
            } elseif ($occupation !== '' && !$flags['is_employed']) {
                $flags['is_employed'] = true;
            }
            if ($flags['is_employed'] && $occupation === '') {
                $errors[] = 'Member is marked Employed - enter their occupation.';
            }

            $contactNumber = null;
            if ($isAccountHolder) {
                $contactNumber = trim($_POST['contact_number'] ?? '');

                $normalizedContact = normalize_ph_mobile($contactNumber);
                if ($normalizedContact === null) {
                    $errors[] = 'Enter a valid 11-digit mobile number starting with 09.';
                } else {
                    $contactNumber = $normalizedContact;
                }
            }

            if (empty($errors)) {
                $pdo->beginTransaction();

                $pdo->prepare(
                    'UPDATE household_members SET
                        occupation = ?, civil_status = ?, is_pwd = ?, is_solo_parent = ?, is_ofw = ?, is_osy = ?, is_ip = ?, is_unemployed = ?, is_employed = ?, is_student = ?
                     WHERE member_id = ?'
                )->execute([
                    $occupation ?: null,
                    $civilStatus,
                    $flags['is_pwd'] ? 1 : 0, $flags['is_solo_parent'] ? 1 : 0, $flags['is_ofw'] ? 1 : 0,
                    $flags['is_osy'] ? 1 : 0, $flags['is_ip'] ? 1 : 0, $flags['is_unemployed'] ? 1 : 0,
                    $flags['is_employed'] ? 1 : 0, $flags['is_student'] ? 1 : 0,
                    $memberId,
                ]);

                if ($isAccountHolder) {
                    $pdo->prepare('UPDATE users SET contact_number = ? WHERE user_id = ?')
                        ->execute([$contactNumber, $me['user_id']]);
                }

                log_activity($pdo, $me['user_id'], "Updated household member #{$memberId}", 'update');

evaluate_household_eligibility($pdo, (int) $me['household_id'], $me['user_id']);

                $pdo->commit();
                flash('success', 'Saved. If this changes what your household qualifies for, the dashboard will reflect it right away.');
                redirect('profile.php');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add_member') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $addErrors[] = 'Your session expired. Please try again.';
    } elseif (!$me['household_id']) {
        $addErrors[] = 'Complete your household census first before adding more members.';
    } else {
        $addPrev = [
            'last_name'      => normalize_person_name($_POST['last_name'] ?? ''),
            'first_name'     => normalize_person_name($_POST['first_name'] ?? ''),
            'middle_name'    => normalize_person_name($_POST['middle_name'] ?? ''),
            'suffix'         => normalize_suffix($_POST['suffix'] ?? ''),
            'place_of_birth' => normalize_label($_POST['place_of_birth'] ?? ''),
            'birthdate'      => trim($_POST['birthdate'] ?? ''),
            'sex'            => trim($_POST['sex'] ?? ''),
            'civil_status'   => normalize_civil_status($_POST['civil_status'] ?? ''),
            'citizenship'    => normalize_label($_POST['citizenship'] ?? ''),
            'occupation'     => normalize_occupation($_POST['occupation'] ?? ''),
        ];
        $addFlags = [
            'is_pwd'         => isset($_POST['is_pwd']),
            'is_solo_parent' => isset($_POST['is_solo_parent']),
            'is_ofw'         => isset($_POST['is_ofw']),
            'is_osy'         => isset($_POST['is_osy']),
            'is_ip'          => isset($_POST['is_ip']),
            'is_unemployed'  => isset($_POST['is_unemployed']),
            'is_employed'    => isset($_POST['is_employed']),
            'is_student'     => isset($_POST['is_student']),
        ];

        if ($addPrev['last_name'] === '' || $addPrev['first_name'] === '') {
            $addErrors[] = 'Last name and first name are required.';
        }
        $birthdateObj = $addPrev['birthdate'] !== '' ? DateTime::createFromFormat('Y-m-d', $addPrev['birthdate']) : null;
        if ($addPrev['birthdate'] !== '' && (!$birthdateObj || is_implausible_birthdate($birthdateObj))) {
            $addErrors[] = 'Enter a valid date of birth, or leave it blank.';
        }
        if ($addPrev['sex'] !== '' && !in_array($addPrev['sex'], ['Male', 'Female'], true)) {
            $addErrors[] = 'Select a valid sex, or leave it blank.';
        }
        if ($addPrev['civil_status'] !== '' && !in_array($addPrev['civil_status'], ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'], true)) {
            $addErrors[] = 'Select a valid civil status, or leave it blank.';
        }
        if (is_none_occupation($_POST['occupation'] ?? '')) {
            $addFlags['is_unemployed'] = true;
            $addFlags['is_employed'] = false;
        } elseif ($addPrev['occupation'] !== '' && $addFlags['is_unemployed']) {
            $addErrors[] = 'An occupation is listed, so this member cannot also be marked Unemployed.';
        } elseif ($addPrev['occupation'] !== '' && !$addFlags['is_employed']) {
            $addFlags['is_employed'] = true;
        }
        if ($addFlags['is_employed'] && $addPrev['occupation'] === '') {
            $addErrors[] = 'Member is marked Employed - enter their occupation.';
        }

        if (empty($addErrors)) {
            $pdo->beginTransaction();

            $pdo->prepare(
                'INSERT INTO household_members
                    (household_id, last_name, first_name, middle_name, suffix, place_of_birth,
                     birthdate, sex, civil_status, citizenship, occupation,
                     is_pwd, is_solo_parent, is_ofw, is_osy, is_ip, is_unemployed, is_employed, is_student)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $me['household_id'],
                $addPrev['last_name'], $addPrev['first_name'],
                $addPrev['middle_name'] ?: null, $addPrev['suffix'] ?: null, $addPrev['place_of_birth'] ?: null,
                $addPrev['birthdate'] ?: null, $addPrev['sex'] ?: null, $addPrev['civil_status'] ?: null,
                $addPrev['citizenship'] !== '' ? $addPrev['citizenship'] : 'Filipino', $addPrev['occupation'] ?: null,
                $addFlags['is_pwd'] ? 1 : 0, $addFlags['is_solo_parent'] ? 1 : 0, $addFlags['is_ofw'] ? 1 : 0,
                $addFlags['is_osy'] ? 1 : 0, $addFlags['is_ip'] ? 1 : 0, $addFlags['is_unemployed'] ? 1 : 0,
                $addFlags['is_employed'] ? 1 : 0, $addFlags['is_student'] ? 1 : 0,
            ]);

            $pdo->prepare(
                'UPDATE households SET no_of_members = (SELECT COUNT(*) FROM household_members WHERE household_id = ? AND is_archived = FALSE) WHERE household_id = ?'
            )->execute([$me['household_id'], $me['household_id']]);

            log_activity($pdo, $me['user_id'], "Registered new household member {$addPrev['first_name']} {$addPrev['last_name']}", 'create');

            evaluate_household_eligibility($pdo, (int) $me['household_id'], $me['user_id']);

            $pdo->commit();
            flash('success', 'New household member added, and checked against active programs.');
            redirect('profile.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'edit_income') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$me['household_id']) {
        $errors[] = 'Complete your household census first before setting household income.';
    } else {
        $incomeInput = trim($_POST['monthly_income'] ?? '');
        if ($incomeInput !== '' && !is_numeric($incomeInput)) {
            $errors[] = 'Monthly household income must be a number.';
        } elseif ($incomeInput !== '' && (float) $incomeInput < 0) {
            $errors[] = 'Monthly household income cannot be negative.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE households SET monthly_income = ? WHERE household_id = ?')
                    ->execute([
                        $incomeInput !== '' ? (float) $incomeInput : null,
                        (int) $me['household_id'],
                    ]);
                evaluate_household_eligibility($pdo, (int) $me['household_id'], $me['user_id']);
                $pdo->commit();
                flash('success', 'Household income updated, and your eligibility has been re-checked.');
                redirect('profile.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Income update failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your household income. Please try again.';
            }
        }
    }
}

$allMembers = [];
if ($me['household_id']) {
    $stmt = $pdo->prepare(
        'SELECT member_id, last_name, first_name, middle_name, suffix, place_of_birth, birthdate, sex,
                civil_status, citizenship, occupation, photo_path, is_household_head,
                is_pwd, is_solo_parent, is_ofw, is_osy, is_ip, is_unemployed, is_employed, is_student,
                TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) AS age
         FROM household_members
         WHERE household_id = ? AND is_archived = FALSE
         ORDER BY is_household_head DESC, last_name ASC, first_name ASC'
    );
    $stmt->execute([$me['household_id']]);
    $allMembers = $stmt->fetchAll();
}
$householdSize = count($allMembers);

/** Renders one member's read-only info + an Edit button that reveals the editable form. */
function render_member_card(array $m, string $label, array $me): void {
    global $editMemberId;
    $isAccountHolder = (int) $m['member_id'] === (int) $me['household_member_id'];
    $fullName = trim($m['first_name'] . ' ' . $m['middle_name'] . ' ' . $m['last_name'] . ' ' . $m['suffix']);
    $initials = strtoupper(substr(trim($m['first_name'] ?? ''), 0, 1) . substr(trim($m['last_name'] ?? ''), 0, 1));
    $initials = $initials !== '' ? $initials : '?';
    ?>
    <section class="dashboard-section member-card">
      <div class="member-card-head">
        <div class="member-avatar-wrap">
          <?php if ($memberPhoto = $isAccountHolder ? ($me['photo_path'] ?: ($m['photo_path'] ?? null)) : ($m['photo_path'] ?? null)): ?>
            <img class="member-avatar member-avatar-img" src="assets/avatars/<?= e($memberPhoto) ?>" alt="">
          <?php else: ?>
            <div class="member-avatar"><?= e($initials) ?></div>
          <?php endif; ?>
          <div class="member-avatar-actions">
            <form method="POST" enctype="multipart/form-data" class="member-avatar-form">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="form" value="upload_member_photo">
              <input type="hidden" name="member_id" value="<?= (int) $m['member_id'] ?>">
              <label class="member-avatar-edit" title="Upload a photo for this member">
                <input type="file" name="member_photo" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()">
                <span>Change</span>
              </label>
            </form>
            <?php if ($memberPhoto): ?>
              <form method="POST" class="member-avatar-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="form" value="remove_member_photo">
                <input type="hidden" name="member_id" value="<?= (int) $m['member_id'] ?>">
                <button type="submit" class="member-avatar-remove" title="Remove this photo">Remove</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <div class="member-card-title">
          <h2><?= e($label) ?></h2>
          <div class="member-card-badges">
            <?php if ($isAccountHolder): ?><span class="profile-chip profile-chip-navy">You</span><?php endif; ?>
            <?php if (!empty($m['is_household_head'])): ?><span class="profile-chip profile-chip-gold">Head of household</span><?php endif; ?>
          </div>
        </div>
        <button type="button" class="member-edit-btn" onclick="toggleMemberEdit(this)">Edit</button>
      </div>

      <div class="member-grid member-grid-info">
        <div class="member-field"><span class="member-field-label">Full name</span><span class="member-field-value"><?= e($fullName) ?></span></div>
        <div class="member-field"><span class="member-field-label">Age</span><span class="member-field-value"><?= $m['age'] !== null ? (int) $m['age'] : '&mdash;' ?></span></div>
        <div class="member-field"><span class="member-field-label">Date of birth</span><span class="member-field-value"><?= $m['birthdate'] ? e(date('M j, Y', strtotime($m['birthdate']))) : '&mdash;' ?></span></div>
        <div class="member-field"><span class="member-field-label">Sex</span><span class="member-field-value"><?= e($m['sex'] ?? '&mdash;') ?></span></div>
        <div class="member-field"><span class="member-field-label">Civil status</span><span class="member-field-value"><?= e($m['civil_status'] ?? '&mdash;') ?></span></div>
        <div class="member-field"><span class="member-field-label">Citizenship</span><span class="member-field-value"><?= e($m['citizenship'] ?? '&mdash;') ?></span></div>
        <div class="member-field"><span class="member-field-label">Occupation / work</span><span class="member-field-value"><?= $m['occupation'] ? e($m['occupation']) : '&mdash;' ?></span></div>
        <?php if ($isAccountHolder): ?>
          <div class="member-field"><span class="member-field-label">Contact number</span><span class="member-field-value"><?= e($me['contact_number'] ?? '&mdash;') ?></span></div>
        <?php endif; ?>
        <?php
          $situationLabels = [];
          if (!empty($m['is_pwd']))         $situationLabels[] = 'Person with disability';
          if (!empty($m['is_solo_parent'])) $situationLabels[] = 'Solo parent';
          if (!empty($m['is_ofw']))         $situationLabels[] = 'OFW / OFW dependent';
          if (!empty($m['is_osy']))         $situationLabels[] = 'Out-of-school youth';
          if (!empty($m['is_ip']))          $situationLabels[] = 'Indigenous person';
          if (!empty($m['is_unemployed']))  $situationLabels[] = 'Unemployed';
          if (!empty($m['is_employed']))    $situationLabels[] = 'Employed';
          if (!empty($m['is_student']))     $situationLabels[] = 'Student';
        ?>
        <div class="member-field member-field-full">
          <span class="member-field-label">Status</span>
          <div class="situation-chips">
            <?php if ($situationLabels): ?>
              <?php foreach ($situationLabels as $sl): ?><span class="situation-chip"><?= e($sl) ?></span><?php endforeach; ?>
            <?php else: ?>
              <span class="member-field-value">&mdash;</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="member-edit"<?= (int) $editMemberId === (int) $m['member_id'] ? ' style="display:block;"' : '' ?>>
        <h3 style="margin:0 0 14px; font-size: 16px;">Editable</h3>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="form" value="update_member">
          <input type="hidden" name="member_id" value="<?= (int) $m['member_id'] ?>">

          <div class="member-grid">
            <?php if ($isAccountHolder): ?>
              <div>
                <label for="contact_number_<?= (int) $m['member_id'] ?>">Contact number (login)</label>
                <input type="tel" id="contact_number_<?= (int) $m['member_id'] ?>" name="contact_number" value="<?= e($me['contact_number'] ?? '') ?>" required>
              </div>
            <?php endif; ?>
            <div>
              <label for="civil_status_<?= (int) $m['member_id'] ?>">Civil status</label>
              <select id="civil_status_<?= (int) $m['member_id'] ?>" name="civil_status">
                <option value="" <?= empty($m['civil_status']) ? 'selected' : '' ?>>Select&hellip;</option>
                <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled'] as $cs): ?>
                  <option value="<?= e($cs) ?>" <?= ($m['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= e($cs) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="occupation_<?= (int) $m['member_id'] ?>">Occupation / work</label>
              <input type="text" id="occupation_<?= (int) $m['member_id'] ?>" name="occupation" value="<?= e($m['occupation'] ?? '') ?>" placeholder="Type N/A if none">
            </div>
          </div>

          <fieldset class="checkbox-group">
            <legend>Update if this person's situation has changed</legend>
            <label class="checkbox-label"><input type="checkbox" name="is_pwd" value="1" <?= !empty($m['is_pwd']) ? 'checked' : '' ?>> Person with disability (PWD)</label>
            <label class="checkbox-label"><input type="checkbox" name="is_solo_parent" value="1" <?= !empty($m['is_solo_parent']) ? 'checked' : '' ?>> Solo parent</label>
            <label class="checkbox-label"><input type="checkbox" name="is_ofw" value="1" <?= !empty($m['is_ofw']) ? 'checked' : '' ?>> OFW or OFW dependent</label>
            <label class="checkbox-label"><input type="checkbox" name="is_osy" value="1" <?= !empty($m['is_osy']) ? 'checked' : '' ?>> Out-of-school youth</label>
            <label class="checkbox-label"><input type="checkbox" name="is_ip" value="1" <?= !empty($m['is_ip']) ? 'checked' : '' ?>> Indigenous person</label>
            <label class="checkbox-label"><input type="checkbox" name="is_unemployed" value="1" <?= !empty($m['is_unemployed']) ? 'checked' : '' ?>> Unemployed</label>
            <label class="checkbox-label"><input type="checkbox" name="is_employed" value="1" <?= !empty($m['is_employed']) ? 'checked' : '' ?>> Employed</label>
            <label class="checkbox-label"><input type="checkbox" name="is_student" value="1" <?= !empty($m['is_student']) ? 'checked' : '' ?>> Student</label>
          </fieldset>

          <div class="member-edit-actions">
            <button type="submit" class="btn-primary">Save changes</button>
            <button type="button" class="member-edit-cancel" onclick="toggleMemberEdit(this)">Cancel</button>
          </div>
        </form>
      </div>
    </section>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>My Profile - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
<style>
.profile-hero {
  background: linear-gradient(135deg, #0B2E5C, #1B5FA8);
  border-radius: 14px;
  padding: 26px 28px;
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}
.profile-avatar {
  width: 68px;
  height: 68px;
  border-radius: 50%;
  background: #E8A93D;
  color: #4A2F04;
  font-size: 28px;
  font-weight: 700;
  font-family: 'Fraunces', Georgia, serif;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 4px 14px rgba(6, 14, 28, 0.35);
}
.profile-avatar-img { object-fit: cover; }
.profile-avatar-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.profile-avatar-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.profile-avatar-actions form { margin: 0; }
.profile-avatar-actions .profile-chip { flex: 1 1 0; }
.profile-avatar-form { margin: 0; }
.profile-avatar-edit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  background: #E8A93D;
  color: #4A2F04;
  font-size: 14px;
  font-weight: 600;
  padding: 5px 14px;
  border-radius: 999px;
  cursor: pointer;
  font-family: inherit;
}
.profile-avatar-edit input { display: none; }
.profile-avatar-edit:hover { background: #C98E24; }
.profile-chip.profile-chip-change {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 26px;
  box-sizing: border-box;
  padding: 0 12px;
  margin: 0;
  background: #E8A93D;
  color: #4A2F04;
  cursor: pointer;
  font-family: inherit;
}
.profile-chip.profile-chip-change input { display: none; }
.profile-chip.profile-chip-change:hover { background: #C98E24; }
.profile-chip.profile-chip-remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 26px;
  box-sizing: border-box;
  padding: 0 12px;
  margin: 0;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.35);
  font-family: inherit;
  cursor: pointer;
}
.profile-chip.profile-chip-remove:hover { background: rgba(255, 255, 255, 0.28); color: #fff; }
.profile-hero-info { min-width: 0; }
.profile-hero-info h1 { margin: 0; font-size: 28px; color: #fff; }
.profile-hero-meta { margin: 4px 0 0; color: #CFE0F5; font-size: 16px; }
.profile-hero-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 12px; }
.profile-chip {
  display: inline-block;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 999px;
}
.profile-chip-gold { background: #E8A93D; color: #4A2F04; }
.profile-chip-navy { background: #0B2E5C; color: #fff; }

.member-card-head {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 18px;
}
.member-avatar {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: #E9F0FA;
  color: #0B2E5C;
  font-size: 18px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.member-avatar-img { object-fit: cover; display: block; }
.member-avatar-wrap {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: center;
  width: 140px;
  height: 88px;
  flex-shrink: 0;
}
.member-avatar-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 24px;
  flex-wrap: nowrap;
  white-space: nowrap;
  width: 100%;
}
.member-avatar-actions .member-avatar-edit,
.member-avatar-actions .member-avatar-remove {
  flex: 1 1 0;
  min-width: 0;
  max-width: 67px;
}
.member-avatar-form { margin: 0; display: contents; }
.member-avatar-edit,
.member-avatar-remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  height: 22px;
  box-sizing: border-box;
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  padding: 0 10px;
  border-radius: 999px;
  cursor: pointer;
  font-family: inherit;
  border: 1px solid transparent;
}
.member-avatar-edit {
  background: #E9F0FA;
  color: #0B2E5C;
}
.member-avatar-edit input { display: none; }
.member-avatar-edit:hover { background: #DCE8F7; }
.member-avatar-remove {
  background: none;
  border-color: #D6E1EE;
  color: #3C5470;
}
.member-avatar-remove:hover { background: #F4F6F9; color: #C0392B; }
.member-card-title h2 { margin: 0; font-size: 19px; }
.member-card-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.member-card-badges .profile-chip { font-size: 13px; padding: 3px 10px; }
.member-card-head .member-edit-btn { margin: 0 0 0 auto; }
.member-field { display: flex; flex-direction: column; gap: 3px; }
.member-field-label {
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #3C5470;
}
.member-field-value { font-size: 16px; font-weight: 500; color: #16233A; }
.member-field-full { grid-column: 1 / -1; }
.member-grid-info {
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}
.member-grid-info .member-field {
  background: #fff;
  border: 1px solid #D6E1EE;
  border-radius: 8px;
  padding: 10px 14px;
}
.member-grid-info .member-field-full .situation-chips { margin-top: 2px; }
.member-grid-five {
  grid-template-columns: repeat(5, minmax(0, 1fr));
}
@media (max-width: 900px) {
  .member-grid-info { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
  .member-grid-info { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .member-grid-five { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 480px) {
  .member-grid-info { grid-template-columns: 1fr; }
  .member-grid-five { grid-template-columns: 1fr; }
  .member-avatar-actions { flex-wrap: wrap; }
  .member-avatar-actions .member-avatar-edit,
  .member-avatar-actions .member-avatar-remove { max-width: none; }
}
.situation-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.situation-chip {
  background: #E9F0FA;
  color: #0B2E5C;
  font-size: 14px;
  font-weight: 500;
  padding: 4px 10px;
  border-radius: 999px;
}
.add-member-section { border-left: 4px solid #E8A93D; }
.member-edit-btn {
  width: auto;
  margin-top: 14px;
  background: var(--navy);
  color: var(--white);
  border: none;
  padding: 10px 26px;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
}
.member-edit-btn:hover { background: var(--blue); }
.member-edit {
  display: none;
  margin-top: 18px;
  border-top: 1px solid var(--border);
  padding-top: 16px;
}
.member-edit-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
}
.member-edit-actions .btn-primary { width: auto; margin-top: 0; padding: 14px 28px; }
.member-edit-cancel {
  background: var(--white);
  color: var(--muted);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 14px 24px;
  font-size: 16px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
}
.member-edit-cancel:hover { background: var(--sky); }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar-start.php'; ?>

<div class="dashboard">
  <section class="profile-hero">
    <?php
      $heroParts = preg_split('/\s+/', trim($me['full_name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
      $heroInitials = strtoupper(substr($heroParts[0] ?? '', 0, 1) . substr($heroParts[1] ?? '', 0, 1));
      $heroInitials = $heroInitials !== '' ? $heroInitials : '?';
    ?>
    <div class="profile-avatar-wrap">
      <?php $heroPhoto = $me['photo_path'] ?: $me['member_photo']; ?>
      <?php if ($heroPhoto): ?>
        <img class="profile-avatar profile-avatar-img" src="assets/avatars/<?= e($heroPhoto) ?>" alt="Profile photo">
      <?php else: ?>
        <div class="profile-avatar"><?= e($heroInitials) ?></div>
      <?php endif; ?>
      <div class="profile-avatar-actions">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="form" value="upload_photo">
          <label class="profile-chip profile-chip-change" title="Upload a profile picture">
            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()">
            <span>Change</span>
          </label>
        </form>
        <?php if ($heroPhoto): ?>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="form" value="remove_photo">
            <button type="submit" class="profile-chip profile-chip-remove" title="Remove profile picture">Remove</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="profile-hero-info">
      <h1><?= e($me['full_name'] ?? 'My Profile') ?></h1>
      <?php if ($me['household_id']): ?>
        <p class="profile-hero-meta">Household &middot; <?= e(trim(($me['house_number'] ?? '') . ' ' . ($me['street'] ?? '') . ', ' . ($me['barangay'] ?? '') . ', ' . ($me['city'] ?? ''))) ?></p>
      <?php endif; ?>
      <div class="profile-hero-badges">
        <span class="profile-chip profile-chip-gold">Account holder</span>
        <?php if ($householdSize > 0): ?>
          <span class="profile-chip"><?= $householdSize ?> household member<?= $householdSize === 1 ? '' : 's' ?></span>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if (empty($allMembers)): ?>
    <section class="dashboard-section">
      <p class="muted-note">No household record on file yet - complete your <a href="census.php">household census</a> first.</p>
    </section>
  <?php else: ?>
    <?php
      $memberNumber = 0;
      foreach ($allMembers as $m):
        if (!empty($m['is_household_head'])) {
            $label = 'Head of the household';
        } else {
            $memberNumber++;
            $label = 'Member ' . $memberNumber;
        }
        render_member_card($m, $label, $me);
      endforeach;
    ?>
  <?php endif; ?>

  <?php if ($me['household_id']): ?>
    <section class="dashboard-section add-member-section">
      <h2>Household income</h2>
      <p class="admin-section-desc">Some barangay programs use monthly household income as a criterion. Keep it up to date so your household is matched correctly.</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="form" value="edit_income">
        <div class="member-grid member-grid-five">
          <div>
            <label for="monthly_income">Estimated monthly household income (&#8369;) - leave blank if unknown</label>
            <input type="number" id="monthly_income" name="monthly_income" min="0" step="0.01" placeholder="e.g. 25000" value="<?= $me['monthly_income'] !== null ? e((string) $me['monthly_income']) : '' ?>">
          </div>
          <div class="income-save-wrap">
            <button type="submit" class="btn-primary" style="width:auto; padding:12px 20px; margin-top:24px;">Save income</button>
          </div>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($me['household_id']): ?>
    <section class="dashboard-section add-member-section">
      <h2>Register a new member</h2>
      <p class="admin-section-desc">For someone missed during the original census - a newborn, a new household member.</p>

      <?php if (!empty($addErrors)): ?>
        <div class="alert alert-error"><ul><?php foreach ($addErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="form" value="add_member">

        <div class="member-grid member-grid-five">
          <div><label for="new_last_name">Last name</label><input type="text" id="new_last_name" name="last_name" value="<?= old('last_name', $addPrev) ?>" required></div>
          <div><label for="new_first_name">First name</label><input type="text" id="new_first_name" name="first_name" value="<?= old('first_name', $addPrev) ?>" required></div>
          <div><label for="new_middle_name">Middle name</label><input type="text" id="new_middle_name" name="middle_name" value="<?= old('middle_name', $addPrev) ?>"></div>
          <div><label for="new_suffix">Ext.</label><input type="text" id="new_suffix" name="suffix" value="<?= old('suffix', $addPrev) ?>"></div>
          <div><label for="new_place_of_birth">Place of birth</label><input type="text" id="new_place_of_birth" name="place_of_birth" value="<?= old('place_of_birth', $addPrev) ?>"></div>
          <div><label for="new_birthdate">Date of birth</label><input type="date" id="new_birthdate" name="birthdate" value="<?= old('birthdate', $addPrev) ?>"></div>
          <div>
            <label for="new_sex">Sex</label>
            <select id="new_sex" name="sex">
              <option value="">Select</option>
              <option value="Male" <?= ($addPrev['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= ($addPrev['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>
          <div>
            <label for="new_civil_status">Civil status</label>
            <select id="new_civil_status" name="civil_status">
              <option value="">Select</option>
              <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled'] as $cs): ?>
                <option value="<?= $cs ?>" <?= ($addPrev['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= $cs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label for="new_citizenship">Citizenship</label><input type="text" id="new_citizenship" name="citizenship" value="<?= e((string) ($addPrev['citizenship'] ?? 'Filipino')) ?>"></div>
          <div><label for="new_occupation">Occupation</label><input type="text" id="new_occupation" name="occupation" value="<?= old('occupation', $addPrev) ?>" placeholder="Type N/A if none"></div>
        </div>

        <fieldset class="checkbox-group">
          <legend>Check all that apply</legend>
          <label class="checkbox-label"><input type="checkbox" name="is_pwd" value="1"> Person with disability (PWD)</label>
          <label class="checkbox-label"><input type="checkbox" name="is_solo_parent" value="1"> Solo parent</label>
          <label class="checkbox-label"><input type="checkbox" name="is_ofw" value="1"> OFW or OFW dependent</label>
          <label class="checkbox-label"><input type="checkbox" name="is_osy" value="1"> Out-of-school youth</label>
          <label class="checkbox-label"><input type="checkbox" name="is_ip" value="1"> Indigenous person</label>
          <label class="checkbox-label"><input type="checkbox" name="is_unemployed" value="1"> Unemployed</label>
          <label class="checkbox-label"><input type="checkbox" name="is_employed" value="1"> Employed</label>
          <label class="checkbox-label"><input type="checkbox" name="is_student" value="1"> Student</label>
        </fieldset>

        <button type="submit" class="btn-primary" style="width:auto; padding:14px 28px; margin-top:14px;">Add member</button>
      </form>
    </section>
  <?php endif; ?>
</div>

<script>
function toggleMemberEdit(btn) {
  var card = btn.closest('.member-card');
  if (!card) return;
  var box = card.querySelector('.member-edit');
  if (!box) return;
  var open = box.style.display === 'block';
  box.style.display = open ? 'none' : 'block';
  if (!open) {
    var first = box.querySelector('input, select, textarea');
    if (first) first.focus();
  }
}
</script>

<?php include __DIR__ . '/partials/sidebar-end.php'; ?>
</body>
</html>
