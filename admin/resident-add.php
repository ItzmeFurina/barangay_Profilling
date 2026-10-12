<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_admin();
$activePage = 'residents';

$pdo = get_db_connection();
$errors = [];
$prev = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add_resident') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $householdId = (int) ($_POST['household_id'] ?? 0);
        $prev = [
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
        } elseif ($prev['occupation'] !== '' && $flags['is_unemployed']) {
            $errors[] = 'An occupation is listed, so this resident cannot also be marked Unemployed.';
        } elseif ($prev['occupation'] !== '' && !$flags['is_employed']) {
            $flags['is_employed'] = true;
        }
        if ($flags['is_employed'] && $prev['occupation'] === '') {
            $errors[] = 'Resident is marked Employed - enter their occupation.';
        }

        if ($householdId <= 0) {
            $errors[] = 'Select which household this person belongs to.';
        }
        if ($prev['last_name'] === '' || $prev['first_name'] === '') {
            $errors[] = 'Last name and first name are required.';
        }
        $birthdateObj = $prev['birthdate'] !== '' ? DateTime::createFromFormat('Y-m-d', $prev['birthdate']) : null;
        if ($prev['birthdate'] !== '' && (!$birthdateObj || is_implausible_birthdate($birthdateObj))) {
            $errors[] = 'Enter a valid date of birth, or leave it blank.';
        }
        if ($prev['sex'] !== '' && !in_array($prev['sex'], ['Male', 'Female'], true)) {
            $errors[] = 'Select a valid sex, or leave it blank.';
        }
        if ($prev['civil_status'] !== '' && !in_array($prev['civil_status'], ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'], true)) {
            $errors[] = 'Select a valid civil status, or leave it blank.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO household_members
                        (household_id, last_name, first_name, middle_name, suffix, place_of_birth,
                         birthdate, sex, civil_status, citizenship, occupation,
                         is_pwd, is_solo_parent, is_ofw, is_osy, is_ip, is_unemployed, is_employed, is_student)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $householdId,
                    $prev['last_name'], $prev['first_name'],
                    $prev['middle_name'] ?: null, $prev['suffix'] ?: null, $prev['place_of_birth'] ?: null,
                    $prev['birthdate'] ?: null, $prev['sex'] ?: null, $prev['civil_status'] ?: null,
                    $prev['citizenship'] !== '' ? $prev['citizenship'] : 'Filipino', $prev['occupation'] ?: null,
                    $flags['is_pwd'] ? 1 : 0, $flags['is_solo_parent'] ? 1 : 0, $flags['is_ofw'] ? 1 : 0,
                    $flags['is_osy'] ? 1 : 0, $flags['is_ip'] ? 1 : 0, $flags['is_unemployed'] ? 1 : 0,
                    $flags['is_employed'] ? 1 : 0, $flags['is_student'] ? 1 : 0,
                ]);

                $pdo->prepare(
                    'UPDATE households SET no_of_members = (SELECT COUNT(*) FROM household_members WHERE household_id = ? AND is_archived = FALSE) WHERE household_id = ?'
                )->execute([$householdId, $householdId]);

                $householdNumber = (string) ($pdo->query(
                    "SELECT house_number FROM households WHERE household_id = {$householdId}"
                )->fetchColumn() ?: 'unknown house');

                log_activity($pdo, $_SESSION['staff_user_id'], "Added resident {$prev['first_name']} {$prev['last_name']} to household {$householdNumber}", 'create');

                evaluate_household_eligibility($pdo, $householdId, $_SESSION['staff_user_id']);

                $pdo->commit();
                flash('success', 'Resident added, and re-checked against active programs.');
                redirect('residents.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Add resident failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving this resident. Please try again.';
            }
        }
    }
}

$households = $pdo->query(
    "SELECT household_id, house_number, street, barangay, city FROM households ORDER BY street ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Add Resident - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
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
      <span class="edit-eyebrow ef-fade" style="--d:80ms">Resident records &middot; Add</span>
      <h1 class="edit-title ef-fade" style="--d:130ms">Add a resident</h1>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-error ef-fade" style="--d:200ms"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="edit-card ef-fade" style="--d:260ms">
    <div class="edit-card-head">
      <h2>New resident</h2>
      <span class="edit-card-hint">For someone missed during the original census &mdash; adds directly to an existing household</span>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="add_resident">

      <div class="edit-fields">
        <div class="ef-2">
          <label for="household_id">Household <em>*</em></label>
          <select id="household_id" name="household_id" required>
            <option value="">Select a household</option>
            <?php foreach ($households as $h): ?>
              <option value="<?= (int) $h['household_id'] ?>" <?= (int) ($prev['household_id'] ?? 0) === (int) $h['household_id'] ? 'selected' : '' ?>>
                <?= $h['house_number'] ? e($h['house_number']) : 'No house number' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label for="last_name">Last name <em>*</em></label><input type="text" id="last_name" name="last_name" value="<?= old('last_name', $prev) ?>" required></div>
        <div><label for="first_name">First name <em>*</em></label><input type="text" id="first_name" name="first_name" value="<?= old('first_name', $prev) ?>" required></div>
        <div><label for="middle_name">Middle name</label><input type="text" id="middle_name" name="middle_name" value="<?= old('middle_name', $prev) ?>"></div>
        <div><label for="suffix">Ext.</label><input type="text" id="suffix" name="suffix" value="<?= old('suffix', $prev) ?>"></div>
        <div><label for="place_of_birth">Place of birth</label><input type="text" id="place_of_birth" name="place_of_birth" value="<?= old('place_of_birth', $prev) ?>"></div>
        <div><label for="birthdate">Date of birth</label><input type="date" id="birthdate" name="birthdate" value="<?= old('birthdate', $prev) ?>"></div>
        <div>
          <label for="sex">Sex</label>
          <select id="sex" name="sex">
            <option value="">Select</option>
            <option value="Male" <?= ($prev['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
            <option value="Female" <?= ($prev['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
          </select>
        </div>
        <div>
          <label for="civil_status">Civil status</label>
          <select id="civil_status" name="civil_status">
            <option value="">Select</option>
            <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled'] as $cs): ?>
              <option value="<?= $cs ?>" <?= ($prev['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= $cs ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label for="citizenship">Citizenship</label><input type="text" id="citizenship" name="citizenship" value="<?= old('citizenship', $prev, 'Filipino') ?>"></div>
        <div><label for="occupation">Occupation</label><input type="text" id="occupation" name="occupation" value="<?= old('occupation', $prev) ?>" placeholder="Type N/A if none"></div>
      </div>

      <fieldset class="edit-chips">
        <legend>Check all that apply</legend>
        <label class="chip"><input type="checkbox" name="is_pwd" value="1"><span>PWD</span></label>
        <label class="chip"><input type="checkbox" name="is_solo_parent" value="1"><span>Solo parent</span></label>
        <label class="chip"><input type="checkbox" name="is_ofw" value="1"><span>OFW</span></label>
        <label class="chip"><input type="checkbox" name="is_osy" value="1"><span>Out-of-school youth</span></label>
        <label class="chip"><input type="checkbox" name="is_ip" value="1"><span>Indigenous person</span></label>
        <label class="chip"><input type="checkbox" name="is_unemployed" value="1"><span>Unemployed</span></label>
        <label class="chip"><input type="checkbox" name="is_employed" value="1"><span>Employed</span></label>
        <label class="chip"><input type="checkbox" name="is_student" value="1"><span>Student</span></label>
      </fieldset>

      <button type="submit" class="save-btn">
        <span>Add resident</span>
        <span class="save-btn-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
        </span>
      </button>
    </form>
  </div>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
