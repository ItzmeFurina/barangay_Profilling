<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_admin();
$activePage = 'residents';

$pdo = get_db_connection();
$memberId = (int) ($_GET['id'] ?? $_POST['member_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT hm.*, h.house_number, h.street, h.barangay, h.city, h.household_id
     FROM household_members hm
     JOIN households h ON h.household_id = hm.household_id
     WHERE hm.member_id = ?'
);
$stmt->execute([$memberId]);
$member = $stmt->fetch();

if (!$member) {
    http_response_code(404);
    die('Resident not found.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $updated = [
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
        if (is_none_occupation($_POST['occupation'] ?? '')) {
            $flags['is_unemployed'] = true;
            $flags['is_employed'] = false;
        } elseif ($updated['occupation'] !== '' && $flags['is_unemployed']) {
            $errors[] = 'An occupation is listed, so this resident cannot also be marked Unemployed.';
        } elseif ($updated['occupation'] !== '' && !$flags['is_employed']) {
            $flags['is_employed'] = true;
        }
        if ($flags['is_employed'] && $updated['occupation'] === '') {
            $errors[] = 'Resident is marked Employed - enter their occupation.';
        }

        if ($updated['last_name'] === '' || $updated['first_name'] === '') {
            $errors[] = 'Last name and first name are required.';
        }
        $birthdateObj = $updated['birthdate'] !== '' ? DateTime::createFromFormat('Y-m-d', $updated['birthdate']) : null;
        if ($updated['birthdate'] !== '' && (!$birthdateObj || is_implausible_birthdate($birthdateObj))) {
            $errors[] = 'Enter a valid date of birth, or leave it blank.';
        }
        if ($updated['civil_status'] !== '' && !in_array($updated['civil_status'], ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'], true)) {
            $errors[] = 'Select a valid civil status, or leave it blank.';
        }
        if ($updated['sex'] !== '' && !in_array($updated['sex'], ['Male', 'Female'], true)) {
            $errors[] = 'Select a valid sex, or leave it blank.';
        }

        if (empty($errors)) {
            $pdo->prepare(
                'UPDATE household_members SET
                    last_name = ?, first_name = ?, middle_name = ?, suffix = ?, place_of_birth = ?,
                    birthdate = ?, sex = ?, civil_status = ?, citizenship = ?, occupation = ?,
                    is_pwd = ?, is_solo_parent = ?, is_ofw = ?, is_osy = ?, is_ip = ?, is_unemployed = ?, is_employed = ?, is_student = ?
                 WHERE member_id = ?'
            )->execute([
                $updated['last_name'], $updated['first_name'], $updated['middle_name'] ?: null,
                $updated['suffix'] ?: null, $updated['place_of_birth'] ?: null, $updated['birthdate'] ?: null,
                $updated['sex'] ?: null, $updated['civil_status'] ?: null,
                $updated['citizenship'] !== '' ? $updated['citizenship'] : 'Filipino', $updated['occupation'] ?: null,
                $flags['is_pwd'] ? 1 : 0, $flags['is_solo_parent'] ? 1 : 0, $flags['is_ofw'] ? 1 : 0,
                $flags['is_osy'] ? 1 : 0, $flags['is_ip'] ? 1 : 0, $flags['is_unemployed'] ? 1 : 0,
                $flags['is_employed'] ? 1 : 0, $flags['is_student'] ? 1 : 0,
                $memberId,
            ]);

            log_activity($pdo, $_SESSION['staff_user_id'], "Updated resident #{$memberId}", 'update');

            // Editing age, income-relevant flags, etc. can change eligibility,
            // so re-run the sync for this household - validates every member
            // against every active program and retracts stale qualifications
            // (this is how an edited birthdate removes a now-ineligible
            // senior from the Senior Benefit, including a past approval).
            evaluate_household_eligibility($pdo, (int) $member['household_id'], $_SESSION['staff_user_id']);

            flash('success', 'Resident record updated, and re-checked against active programs.');
            redirect('residents.php');
        }

        $member = array_merge($member, $updated, [
            'is_pwd' => $flags['is_pwd'], 'is_solo_parent' => $flags['is_solo_parent'],
            'is_ofw' => $flags['is_ofw'], 'is_osy' => $flags['is_osy'],
            'is_ip' => $flags['is_ip'], 'is_unemployed' => $flags['is_unemployed'],
            'is_employed' => $flags['is_employed'], 'is_student' => $flags['is_student'],
        ]);
    }
}

$householdMembers = $pdo->prepare(
    'SELECT member_id, first_name, last_name, is_household_head FROM household_members WHERE household_id = ? AND is_archived = FALSE ORDER BY is_household_head DESC, last_name ASC'
);
$householdMembers->execute([$member['household_id']]);
$otherMembers = $householdMembers->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title><?= e($member['first_name'] . ' ' . $member['last_name']) ?> - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
  /* Page-specific pieces for the edit page. The shared record-form kit
     (back pill, eyebrow, edit-card, fields, dropdowns, chips, save
     button, entry animation) lives in assets/css/style.css so the add
     and edit pages stay pixel-identical by construction. */
  .edit-head-side { flex-shrink: 0; text-align: right; }
  .eh-label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--muted);
  }
  .eh-number {
    display: block;
    margin-top: 2px;
    font-family: 'Fraunces', Georgia, serif;
    font-style: italic;
    font-weight: 500;
    font-size: 34px;
    line-height: 1;
    color: var(--blue);
    word-break: break-word;
  }

  /* Record plate: address + household context in one slim bar */
  .record-plate {
    display: flex;
    align-items: center;
    gap: 22px;
    flex-wrap: wrap;
    margin-top: 26px;
    padding: 16px 20px;
    border-radius: 14px;
    background: var(--white);
    border: 1px solid var(--border);
  }
  .rp-item { display: flex; flex-direction: column; min-width: 0; }
  .rp-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--muted);
  }
  .rp-value {
    margin-top: 3px;
    font-size: 14.5px;
    font-weight: 550;
    color: var(--ink);
    word-break: break-word;
  }
  .rp-sep { width: 1px; height: 30px; background: var(--border); flex-shrink: 0; }
  .rp-empty {
    font-family: 'Fraunces', Georgia, serif;
    font-style: italic;
    font-weight: 400;
    font-size: 13px;
    color: var(--muted);
  }

  @media (max-width: 640px) {
    .edit-head-side { display: none; }
    .record-plate { flex-direction: column; align-items: flex-start; gap: 12px; }
    .rp-sep { display: none; }
  }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <div class="edit-head">
    <a href="residents.php" class="back-btn ef-fade" style="--d:40ms">
      <span class="back-btn-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg>
      </span>
      <span>Back to Residents</span>
    </a>
    <div class="edit-head-main">
      <span class="edit-eyebrow ef-fade" style="--d:80ms">Resident records &middot; Edit</span>
      <h1 class="edit-title ef-fade" style="--d:130ms"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></h1>
    </div>
    <div class="edit-head-side ef-fade" style="--d:160ms">
      <span class="eh-label">Household</span>
      <span class="eh-number"><?= $member['house_number'] ? e($member['house_number']) : '<em style="font-style:italic; font-size:18px; font-weight:400; color:var(--muted);">No house number on file</em>' ?></span>
    </div>
  </div>

  <div class="record-plate ef-fade" style="--d:200ms">
    <span class="rp-item">
      <span class="rp-label">Address</span>
      <span class="rp-value"><?= e(format_household_address($member)) ?></span>
    </span>
    <span class="rp-sep"></span>
    <span class="rp-item">
      <span class="rp-label">Other members</span>
      <span class="rp-value"><?php
        $others = array_filter($otherMembers, fn($m) => (int) $m['member_id'] !== $memberId);
        echo $others ? e(implode(', ', array_map(fn($m) => $m['first_name'] . ' ' . $m['last_name'], $others))) : '<em class="rp-empty">None on file</em>';
      ?></span>
    </span>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-error ef-fade" style="--d:240ms"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="edit-card ef-fade" style="--d:280ms">
    <div class="edit-card-head">
      <h2>Edit details</h2>
      <span class="edit-card-hint">Saving re-checks this household against active programs</span>
    </div>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="member_id" value="<?= $memberId ?>">

        <div class="edit-fields">
          <div><label for="last_name">Last name <em>*</em></label><input type="text" id="last_name" name="last_name" value="<?= e((string) $member['last_name']) ?>" required></div>
          <div><label for="first_name">First name <em>*</em></label><input type="text" id="first_name" name="first_name" value="<?= e((string) $member['first_name']) ?>" required></div>
          <div><label for="middle_name">Middle name</label><input type="text" id="middle_name" name="middle_name" value="<?= e((string) $member['middle_name']) ?>"></div>
          <div><label for="suffix">Ext.</label><input type="text" id="suffix" name="suffix" value="<?= e((string) $member['suffix']) ?>"></div>
          <div><label for="place_of_birth">Place of birth</label><input type="text" id="place_of_birth" name="place_of_birth" value="<?= e((string) $member['place_of_birth']) ?>"></div>
          <div><label for="birthdate">Date of birth</label><input type="date" id="birthdate" name="birthdate" value="<?= e((string) $member['birthdate']) ?>"></div>
          <div>
            <label for="sex">Sex</label>
            <select id="sex" name="sex">
              <option value="">Select</option>
              <option value="Male" <?= $member['sex'] === 'Male' ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= $member['sex'] === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>
          <div>
            <label for="civil_status">Civil status</label>
            <select id="civil_status" name="civil_status">
              <option value="">Select</option>
              <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled'] as $cs): ?>
                <option value="<?= $cs ?>" <?= $member['civil_status'] === $cs ? 'selected' : '' ?>><?= $cs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ef-2"><label for="citizenship">Citizenship</label><input type="text" id="citizenship" name="citizenship" value="<?= e((string) $member['citizenship']) ?>"></div>
          <div class="ef-2"><label for="occupation">Occupation</label><input type="text" id="occupation" name="occupation" value="<?= e((string) $member['occupation']) ?>" placeholder="Type N/A if none"></div>
        </div>

        <fieldset class="edit-chips">
          <legend>Check all that apply</legend>
          <label class="chip"><input type="checkbox" name="is_pwd" value="1" <?= $member['is_pwd'] ? 'checked' : '' ?>><span>PWD</span></label>
          <label class="chip"><input type="checkbox" name="is_solo_parent" value="1" <?= $member['is_solo_parent'] ? 'checked' : '' ?>><span>Solo parent</span></label>
          <label class="chip"><input type="checkbox" name="is_ofw" value="1" <?= $member['is_ofw'] ? 'checked' : '' ?>><span>OFW</span></label>
          <label class="chip"><input type="checkbox" name="is_osy" value="1" <?= $member['is_osy'] ? 'checked' : '' ?>><span>Out-of-school youth</span></label>
          <label class="chip"><input type="checkbox" name="is_ip" value="1" <?= $member['is_ip'] ? 'checked' : '' ?>><span>Indigenous person</span></label>
          <label class="chip"><input type="checkbox" name="is_unemployed" value="1" <?= $member['is_unemployed'] ? 'checked' : '' ?>><span>Unemployed</span></label>
          <label class="chip"><input type="checkbox" name="is_employed" value="1" <?= $member['is_employed'] ? 'checked' : '' ?>><span>Employed</span></label>
          <label class="chip"><input type="checkbox" name="is_student" value="1" <?= $member['is_student'] ? 'checked' : '' ?>><span>Student</span></label>
        </fieldset>

        <button type="submit" class="save-btn">
          <span>Save changes</span>
          <span class="save-btn-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 12.5 5 5 10-11"/></svg>
          </span>
        </button>
      </form>
  </div>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
