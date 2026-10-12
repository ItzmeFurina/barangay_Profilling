<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';
require_once __DIR__ . '/partials/member-fieldset.php';
require_once __DIR__ . '/includes/census_upsert.php';

require_resident_active();
$pendingCensus = true;

$pdo = get_db_connection();

$stmt = $pdo->prepare('SELECT census_completed FROM residents WHERE resident_id = ?');
$stmt->execute([$_SESSION['resident_id']]);
$existingResident = $stmt->fetch();

if ($existingResident && $existingResident['census_completed']) {
    redirect('profile.php');
}

$errors = [];
$prevHousehold = ['house_number' => '', 'street' => '', 'barangay' => 'Barangay 420', 'city' => 'Manila', 'old_address' => '', 'monthly_income' => ''];
$prevMembers = [[]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        $houseErrors = census_validate_household($prevHousehold);
        $errors = array_merge($errors, $houseErrors);

        $memberResult = census_validate_members($submittedMembers, $headIndex);
        $errors = array_merge($errors, $memberResult['errors']);
        $cleanMembers = $memberResult['members'];

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $saved = census_save($pdo, $prevHousehold, $cleanMembers, (int) $_SESSION['user_id']);
                $householdId = $saved['household_id'];
                $accountHolderMemberId = $saved['member_id'];

                $stmt = $pdo->prepare(
                    'UPDATE residents
                     SET household_id = ?, household_member_id = ?, census_completed = TRUE, census_completed_at = NOW()
                     WHERE resident_id = ?'
                );
                $stmt->execute([$householdId, $accountHolderMemberId, $_SESSION['resident_id']]);

                log_activity($pdo, $_SESSION['user_id'], 'Completed household census (' . count($cleanMembers) . ' member(s))', 'create');

                evaluate_household_eligibility($pdo, $householdId, $_SESSION['user_id']);

                $pdo->commit();

                flash('success', 'Thanks - your household record has been saved.');
                redirect('profile.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Census submission failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving your information. Please try again.';
            }
        } else {
            // Normalize the submitted member array keys to sequential ints
            // (array_values) before re-rendering: the keys came from HTTP and
            // could contain markup, and every key is echoed into name= / value=
            // attributes by render_member_fieldset() below.
            $prevMembers = array_values($submittedMembers);
            foreach ($prevMembers as $i => &$pm) {
                $pm['_head'] = $headIndex;
            }
            unset($pm);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Household Census - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
<?= census_form_styles() ?>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar-start.php'; ?>

<div class="auth-page">
  <div class="auth-card census-card">
    <h1>Complete your household record</h1>
    <p class="auth-subtitle">We checked, and we don't have a household record on file yet for your name and address - so we need you to answer this census before we can check which barangay programs your household may qualify for. This mirrors the same household census form the barangay already keeps on paper.</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= e($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

      <section class="census-section">
        <div class="census-section-head">
          <span class="census-step-badge">1</span>
          <div>
            <h2 class="section-label">Household address</h2>
            <p class="muted-note">If someone in your household has already answered this census, enter the exact same address so your household can be matched.</p>
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
        <p class="muted-note">Optional, but some barangay programs use household income as a criterion - fill it in so those programs know whether your household qualifies.</p>
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

        <div class="census-actions">
          <button type="submit" class="btn-primary">Save household record</button>
          <p class="census-note">You can still update this information later from your profile.</p>
        </div>
      </section>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/sidebar-end.php'; ?>

<?= census_form_scripts() ?>
</body>
</html>
