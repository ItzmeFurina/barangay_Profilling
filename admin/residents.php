<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_admin();
$activePage = 'residents';

if (isset($_GET['download_template'])) {
    $fields = ['Last Name', 'First Name', 'Middle Name', 'Ext', 'Place of Birth', 'Birthdate', 'Sex', 'Civil Status', 'Citizenship', 'Occupation', 'House Number', 'Street', 'Head of Household', 'PWD', 'Solo Parent', 'OFW', 'OSY', 'Indigenous Person', 'Unemployed', 'Employed', 'Student'];
    $sample1 = ['Regala', 'Michael John', 'M.', '', 'Manila', '1990-05-15', 'Male', 'Married', 'Filipino', 'Admin', '12', 'Mabini St.', 'Yes', 'No', 'No', 'No', 'No', 'No', 'Yes', 'No', 'No'];
    $sample2 = ['Regala', 'Ana', 'M.', '', 'Tondo, Manila', '2012-03-08', 'Female', 'Single', 'Filipino', 'Student', '12', 'Mabini St.', 'No', 'No', 'No', 'No', 'Yes', 'No', 'No', 'Yes', 'No'];
    $quote = static fn(array $cells) => implode(',', array_map(static function ($c) {
        return (str_contains($c, ',') || str_contains($c, '"')) ? '"' . str_replace('"', '""', $c) . '"' : $c;
    }, $cells));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="resident-import-template.csv"');
    echo "\xEF\xBB\xBF";
    echo $quote($fields) . "\r\n";
    echo $quote($sample1) . "\r\n";
    echo $quote($sample2) . "\r\n";
    exit;
}

$pdo = get_db_connection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'import_csv') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a CSV file to upload.';
    } elseif ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
        $errors[] = 'The file is too large (max 10 MB).';
    } else {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $errors[] = 'Only .csv files are accepted (Google Sheets: File > Download > Comma-separated values).';
        } else {
            require_once __DIR__ . '/includes/resident_csv_import.php';
            // All-or-nothing: one bad row (e.g. an over-long column) must not
            // leave a half-imported census behind with households stuck at
            // no_of_members = 0 and no eligibility run. The import function
            // has no transaction of its own, so the caller owns one here.
            try {
                $pdo->beginTransaction();
                $res = import_residents_csv($_FILES['csv_file']['tmp_name'], $pdo, (int) $_SESSION['staff_user_id']);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Resident CSV import failed: ' . $e->getMessage());
                flash('import_errors', ['Import failed and no changes were saved. Please check the file (column lengths, dates, encoding) and try again.']);
                redirect('residents.php');
            }
            $summary = "Spreadsheet import: {$res['imported']} resident(s) added, {$res['updated']} updated";
            if ($res['skipped'] > 0) {
                $summary .= ", {$res['skipped']} skipped";
            }
            $summary .= '.';
            $problems = array_merge($res['errors'], $res['warnings']);
            if (!empty($problems)) {
                flash('import_errors', $problems);
            }
            flash('import_result', $summary);
            redirect('residents.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'archive_member') {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $householdIdStmt = $pdo->prepare('SELECT household_id FROM household_members WHERE member_id = ?');
            $householdIdStmt->execute([$memberId]);
            $householdId = (int) $householdIdStmt->fetchColumn();

            $pdo->prepare('UPDATE household_members SET is_archived = TRUE WHERE member_id = ?')->execute([$memberId]);
            // A resident who is archived must not be able to log back in:
            // archive their linked account too (the resident login already
            // refuses 'archived' accounts), and kill their session via the
            // same flag when it is next checked. Scoped to the account's
            // own registered member row (residents.household_member_id) so
            // a stray linked_user_id left on some other member row can
            // never archive an unrelated account.
            $pdo->prepare(
                'UPDATE users u
                 JOIN residents r ON r.user_id = u.user_id AND r.household_member_id = ?
                 JOIN household_members hm ON hm.member_id = r.household_member_id AND hm.linked_user_id = u.user_id
                 SET u.account_status = \'archived\'
                 WHERE hm.member_id = ?'
            )->execute([$memberId, $memberId]);
            // Keep households.no_of_members in sync now that this member no
            // longer counts as living there - same recompute used everywhere
            // else membership changes (resident-add.php, profile.php).
            if ($householdId > 0) {
                $pdo->prepare(
                    'UPDATE households SET no_of_members = (SELECT COUNT(*) FROM household_members WHERE household_id = ? AND is_archived = FALSE) WHERE household_id = ?'
                )->execute([$householdId, $householdId]);
            }
            log_activity($pdo, $_SESSION['staff_user_id'], "Archived resident #{$memberId}", 'delete');

            // An archived member must not keep an open benefit: retract their
            // qualified/pending/approved rows with the same transition the
            // eligibility engine uses, then re-run the household sync so the
            // rest of the household stays current.
            if ($householdId > 0) {
                $pdo->prepare(
                    "UPDATE beneficiaries
                     SET claim_status = 'retracted',
                         rejection_reason = ?,
                         reviewed_by = NULL,
                         reviewed_at = NULL,
                         resident_seen_at = NULL
                     WHERE member_id = ?
                       AND claim_status IN ('qualified', 'pending', 'approved', 'existing')"
                )->execute([ELIGIBILITY_RETRACTION_REASON, $memberId]);
                evaluate_household_eligibility($pdo, $householdId, $_SESSION['staff_user_id']);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            // Rollback is correct, but a raw rethrow here surfaced a plain
            // HTTP 500 (with a stack trace under display_errors) instead of
            // the friendly error every other handler in this portal shows.
            error_log('Archive resident failed: ' . $e->getMessage());
            flash('error', 'Something went wrong while archiving that resident. No changes were saved.');
            redirect('residents.php');
        }
        flash('success', 'Resident archived.');
        redirect('residents.php');
    }
    flash('error', 'Your session expired. Please try again.');
    redirect('residents.php');
}

/**
 * Loads household members with their household and (optional) account
 * info. $archived picks which side of the soft-delete flag to load - the
 * main list below shows live residents, and a separate section further
 * down the page shows the archived ones.
 */
function fetch_residents(PDO $pdo, bool $archived): array {
    $stmt = $pdo->prepare(
        "SELECT hm.member_id, hm.last_name, hm.first_name, hm.middle_name, hm.suffix,
                hm.sex, hm.birthdate, hm.civil_status, hm.citizenship, hm.occupation, hm.place_of_birth,
                hm.photo_path AS member_photo,
                hm.is_pwd, hm.is_solo_parent, hm.is_ofw, hm.is_osy, hm.is_ip, hm.is_unemployed, hm.is_employed, hm.is_student,
                hm.is_household_head,
                TIMESTAMPDIFF(YEAR, hm.birthdate, CURDATE()) AS age,
                h.household_id, h.house_number, h.street, h.barangay, h.city,
                u.contact_number, u.email, u.photo_path AS account_photo, u.account_status
         FROM household_members hm
         JOIN households h ON h.household_id = hm.household_id
         LEFT JOIN users u ON u.user_id = hm.linked_user_id
         WHERE hm.is_archived = " . ($archived ? 'TRUE' : 'FALSE') . "
         ORDER BY hm.is_household_head DESC, hm.last_name ASC, hm.first_name ASC"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

// Every resident renders once, grouped under a household head row. The
// first row in for a household is either the flagged head (sorted first
// above) or, if nobody in this household was ever marked as head, just
// whoever comes first alphabetically - every household still needs one
// row to anchor the group.
function group_residents_by_household(array $residents): array {
    $groups = [];
    foreach ($residents as $member) {
        $hid = (int) $member['household_id'];
        if (!isset($groups[$hid])) {
            $groups[$hid] = ['head' => null, 'members' => []];
        }
        if ($groups[$hid]['head'] === null) {
            $groups[$hid]['head'] = $member;
        } else {
            $groups[$hid]['members'][] = $member;
        }
    }
    uasort($groups, fn($a, $b) => strcmp($a['head']['last_name'] . $a['head']['first_name'], $b['head']['last_name'] . $b['head']['first_name']));
    return $groups;
}

/** Name cell for a household head row - expand arrow, "no head on file" note, member count badge. */
function household_head_name_cell(array $head, int $householdTotal): string {
    return '<span class="household-toggle-arrow">&#9656;</span>'
        . e(trim($head['last_name'] . ', ' . $head['first_name'] . ' ' . $head['middle_name']))
        . (empty($head['is_household_head'])
            ? ' <span class="muted-note">(no head on file)</span>'
            : '')
        . '<span class="badge badge-pending household-count">' . $householdTotal
        . ' member' . ($householdTotal === 1 ? '' : 's') . '</span>';
}

/**
 * Renders the expandable head + member rows for one list of household
 * groups. $isArchivedList hides the Archive action for the archived
 * section - those residents are already archived.
 */
function render_resident_table_rows(array $groups, bool $isArchivedList): string {
    $html = '';
    foreach ($groups as $hid => $group) {
        $householdTotal = 1 + count($group['members']);
        $head = $group['head'];
        $html .= '<tr class="household-head-row"'
            . ' data-hid="' . (int) $hid . '"'
            . ' data-name="' . e(resident_search_name($head)) . '"'
            . ' data-address="' . e(strtolower(format_household_address($head))) . '"'
            . ' data-age="' . ($head['age'] !== null ? (int) $head['age'] : '') . '"'
            . ' data-sex="' . e((string) $head['sex']) . '"'
            . ' data-civil="' . e((string) $head['civil_status']) . '"'
            . ' data-cat="' . e(resident_category_keys($head)) . '"'
            . ' onclick="toggleHouseholdMembers(' . (int) $hid . ', this)">'
            . resident_row_cells($head, $isArchivedList, household_head_name_cell($head, $householdTotal))
            . '</tr>';
        if ($householdTotal === 1) {
            $html .= '<tr class="household-empty-row household-' . (int) $hid . '">'
                . '<td colspan="6" class="muted-note">No other members on file for this household.</td></tr>';
        } else {
            foreach ($group['members'] as $m) {
                $html .= '<tr class="household-member-row household-' . (int) $hid . '"'
                    . ' data-hid="' . (int) $hid . '"'
                    . ' data-name="' . e(resident_search_name($m)) . '"'
                    . ' data-address="' . e(strtolower(format_household_address($m))) . '"'
                    . ' data-age="' . ($m['age'] !== null ? (int) $m['age'] : '') . '"'
                    . ' data-sex="' . e((string) $m['sex']) . '"'
                    . ' data-civil="' . e((string) $m['civil_status']) . '"'
                    . ' data-cat="' . e(resident_category_keys($m)) . '">'
                    . resident_row_cells($m, $isArchivedList)
                    . '</tr>';
            }
        }
    }
    return $html;
}

$allResidents = fetch_residents($pdo, false);
$archivedResidents = fetch_residents($pdo, true);

$householdGroups = group_residents_by_household($allResidents);
$archivedGroups = group_residents_by_household($archivedResidents);

$totalResidents = array_sum(array_map(fn($g) => 1 + count($g['members']), $householdGroups));
$countLabel = (count($householdGroups) === 1 ? '1 household' : count($householdGroups) . ' households')
    . ', ' . $totalResidents . ($totalResidents === 1 ? ' resident' : ' residents');

$archivedTotal = array_sum(array_map(fn($g) => 1 + count($g['members']), $archivedGroups));
$archivedCountLabel = (count($archivedGroups) === 1 ? '1 household' : count($archivedGroups) . ' households')
    . ', ' . $archivedTotal . ($archivedTotal === 1 ? ' resident' : ' residents');

$accountStatusLabel = [
    'active'   => 'Active',
    'pending'  => 'Pending approval',
    'rejected' => 'Rejected',
    'archived' => 'Archived',
];

/** Lowercased "Last First Middle" used by the client-side search. */
function resident_search_name(array $r): string {
    return strtolower(trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '')));
}

/** Comma-joined category keys (pwd,student,...) matching the filter select values. */
function resident_category_keys(array $r): string {
    $keys = [];
    foreach ([
        'pwd'         => 'is_pwd',
        'solo_parent' => 'is_solo_parent',
        'ofw'         => 'is_ofw',
        'osy'         => 'is_osy',
        'ip'          => 'is_ip',
        'unemployed'  => 'is_unemployed',
        'employed'    => 'is_employed',
        'student'     => 'is_student',
    ] as $k => $col) {
        if (!empty($r[$col])) {
            $keys[] = $k;
        }
    }
    return implode(',', $keys);
}

function status_badges(array $m): string {
    $labels = [];
    if (!empty($m['is_pwd'])) { $labels[] = 'PWD'; }
    if (!empty($m['is_solo_parent'])) { $labels[] = 'Solo parent'; }
    if (!empty($m['is_ofw'])) { $labels[] = 'OFW'; }
    if (!empty($m['is_osy'])) { $labels[] = 'OSY'; }
    if (!empty($m['is_ip'])) { $labels[] = 'IP'; }
    if (!empty($m['is_unemployed'])) { $labels[] = 'Unemployed'; }
    if (!empty($m['is_employed'])) { $labels[] = 'Employed'; }
    if (!empty($m['is_student'])) { $labels[] = 'Student'; }
    if (empty($labels)) { return '<span class="muted-note">&mdash;</span>'; }
    $html = '<span class="status-badges">';
    foreach ($labels as $l) {
        $html .= '<span class="badge badge-pending">' . e($l) . '</span>';
    }
    return $html . '</span>';
}

/** Read-only modal body for one resident, shown via the View action. */
function resident_modal_html(array $r, array $accountStatusLabel): string {
    $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    if (!empty($r['suffix'])) {
        $fullName .= ', ' . $r['suffix'];
    }
    $photo = ($r['account_photo'] ?? '') ?: ($r['member_photo'] ?? '');
    $noRecord = static fn($v): string => $v ? e((string) $v) : '<em class="rm-missing">No record on file</em>';
    $accountStatus = (string) ($r['account_status'] ?? '');
    $statusLabel = $accountStatus !== '' ? ($accountStatusLabel[$accountStatus] ?? ucfirst($accountStatus)) : '';
    $statusTone = $accountStatus === 'active' ? 'rm-chip-active'
        : ($accountStatus === 'pending' ? 'rm-chip-pending'
        : ($accountStatus === 'rejected' || $accountStatus === 'archived' ? 'rm-chip-danger' : 'rm-chip-muted'));
    ob_start();
    ?>
    <div class="rm-shell">
      <div class="rm-core">
        <div class="rm-hero">
          <div class="rm-avatar-shell rm-fade" style="--d:60ms">
            <div class="rm-avatar">
              <?php if ($photo): ?>
                <img src="../resident/assets/avatars/<?= e($photo) ?>" alt="Profile photo">
              <?php else: ?>
                <div class="rm-initials"><?= e(official_initials($fullName)) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="rm-hero-main">
            <span class="rm-eyebrow rm-fade" style="--d:40ms">Resident record</span>
            <h2 class="rm-name rm-fade" style="--d:90ms"><?= e($fullName) ?></h2>
            <div class="rm-role-row rm-fade" style="--d:130ms">
              <span class="rm-rule"></span>
              <span class="rm-role"><?= !empty($r['is_household_head']) ? 'Head of the household' : 'Household member' ?></span>
              <?php if ($statusLabel): ?>
                <span class="rm-chip <?= e($statusTone) ?>"><?= e($statusLabel) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="rm-hero-side rm-fade" style="--d:120ms">
            <span class="rm-hh-label">Household Number</span>
            <span class="rm-hh-number"><?= $noRecord($r['house_number']) ?></span>
          </div>
        </div>

        <div class="rm-contact">
          <div class="rm-contact-item rm-fade" style="--d:180ms">
            <span class="rm-contact-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-6.5-5.3-6.5-10a6.5 6.5 0 1 1 13 0c0 4.7-6.5 10-6.5 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
            </span>
            <span class="rm-contact-text">
              <span class="rm-contact-label">Address</span>
              <span class="rm-contact-value"><?= $noRecord(format_household_address($r)) ?></span>
            </span>
          </div>
          <div class="rm-contact-item rm-fade" style="--d:220ms">
            <span class="rm-contact-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l1.8 4.5-2.2 1.7a12.5 12.5 0 0 0 5 5l1.7-2.2L20 15v4a1.8 1.8 0 0 1-2 1.8C10.4 20.1 4 13.7 3.2 6A1.8 1.8 0 0 1 5 4z"/></svg>
            </span>
            <span class="rm-contact-text">
              <span class="rm-contact-label">Contact number</span>
              <span class="rm-contact-value"><?= $noRecord($r['contact_number']) ?></span>
            </span>
          </div>
          <div class="rm-contact-item rm-fade" style="--d:260ms">
            <span class="rm-contact-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m4.5 7.5 7.5 5.5 7.5-5.5"/></svg>
            </span>
            <span class="rm-contact-text">
              <span class="rm-contact-label">Email</span>
              <span class="rm-contact-value"><?= $noRecord($r['email']) ?></span>
            </span>
          </div>
        </div>

        <div class="rm-grid">
          <div class="rm-cell rm-fade" style="--d:300ms">
            <span class="rm-cell-label">Birth date</span>
            <span class="rm-cell-value"><?= $r['birthdate'] ? e(date('M j, Y', strtotime($r['birthdate']))) : '<em class="rm-missing">&mdash;</em>' ?></span>
          </div>
          <div class="rm-cell rm-fade" style="--d:320ms">
            <span class="rm-cell-label">Age</span>
            <span class="rm-cell-value"><?= $r['age'] !== null ? (int) $r['age'] : '<em class="rm-missing">&mdash;</em>' ?></span>
          </div>
          <div class="rm-cell rm-fade" style="--d:340ms">
            <span class="rm-cell-label">Sex</span>
            <span class="rm-cell-value"><?= $r['sex'] ? e($r['sex']) : '<em class="rm-missing">&mdash;</em>' ?></span>
          </div>
          <div class="rm-cell rm-fade" style="--d:360ms">
            <span class="rm-cell-label">Place of birth</span>
            <span class="rm-cell-value"><?= $r['place_of_birth'] ? e($r['place_of_birth']) : '<em class="rm-missing">&mdash;</em>' ?></span>
          </div>
          <div class="rm-cell rm-fade" style="--d:380ms">
            <span class="rm-cell-label">Civil status</span>
            <span class="rm-cell-value"><?= $r['civil_status'] ? e($r['civil_status']) : '<em class="rm-missing">&mdash;</em>' ?></span>
          </div>
          <div class="rm-cell rm-fade" style="--d:400ms">
            <span class="rm-cell-label">Citizenship</span>
            <span class="rm-cell-value"><?= $r['citizenship'] ? e($r['citizenship']) : '<em class="rm-missing">&mdash;</em>' ?></span>
          </div>
          <div class="rm-cell rm-fade" style="--d:420ms">
            <span class="rm-cell-label">Occupation</span>
            <span class="rm-cell-value"><?= $r['occupation'] ? e($r['occupation']) : '<em class="rm-missing">No record on file</em>' ?></span>
          </div>
          <div class="rm-cell rm-fade" style="--d:440ms">
            <span class="rm-cell-label">Status</span>
            <span class="rm-cell-value"><?= status_badges($r) ?></span>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/** View | Edit | Archive cell - shared by head and member rows. */
function resident_action_cell(array $r, bool $isArchivedList): string {
    ob_start();
    ?>
    <td class="resident-actions" onclick="event.stopPropagation();">
      <button type="button" class="action-link" onclick="openResidentModal(<?= (int) $r['member_id'] ?>)">View</button>
      &nbsp;|&nbsp;
      <a href="resident-edit.php?id=<?= (int) $r['member_id'] ?>">Edit</a>
      <?php if (!$isArchivedList): ?>
        &nbsp;|&nbsp;
        <form method="POST" class="inline-form" data-confirm data-confirm-title="Archive this resident?" data-confirm-message="This resident is hidden from the active list and excluded from future eligibility checks. Their history is kept intact." data-confirm-label="Archive resident" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="form" value="archive_member">
          <input type="hidden" name="member_id" value="<?= (int) $r['member_id'] ?>">
          <button type="submit" class="btn-archive" style="padding:4px 10px;">Archive</button>
        </form>
      <?php endif; ?>
    </td>
    <?php
    return ob_get_clean();
}

/** Renders the shared Name/Age/Household/Contact/Status/Action cells for one resident row. */
function resident_row_cells(array $r, bool $isArchivedList, ?string $nameCellHtml = null): string {
    ob_start();
    ?>
    <td><?= $nameCellHtml !== null ? $nameCellHtml : e(trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . $r['middle_name'])) ?></td>
    <td><?= $r['age'] !== null ? (int) $r['age'] : '&mdash;' ?></td>
    <td style="text-align:center;"><?= $r['house_number'] ? e($r['house_number']) : '&mdash;' ?></td>
    <td><?= $r['contact_number'] ? e($r['contact_number']) : '<span class="muted-note">No contact on file</span>' ?></td>
    <td><?= status_badges($r) ?></td>
    <?= resident_action_cell($r, $isArchivedList) ?>
    <?php
    return ob_get_clean();
}

$residentDetails = [];
foreach (array_merge($householdGroups, $archivedGroups) as $group) {
    $residentDetails[$group['head']['member_id']] = resident_modal_html($group['head'], $accountStatusLabel);
    foreach ($group['members'] as $m) {
        $residentDetails[$m['member_id']] = resident_modal_html($m, $accountStatusLabel);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Residents - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
  /* ============ Resident dossier modal - editorial luxury ============
     A machined tray (navy shell) holding a cream paper dossier (core):
     the double-bezel. All entry motion is transform/opacity only. */
  :root {
    --ease-expo: cubic-bezier(0.32, 0.72, 0, 1);
    --cream: #FBF6EC;
    --cream-2: #F4ECDC;
    --cream-deep: #EFE4CC;
    --espresso: #2E2418;
    --espresso-muted: #85745C;
    --gold-deep: #C08A2E;
    --ink-hairline: rgba(46, 36, 24, 0.10);
  }

  /* Backdrop: deep night, softened by glass. Fixed = blur is legal here. */
  .resident-backdrop {
    opacity: 0;
    transition: opacity 0.4s var(--ease-expo);
    background: rgba(6, 14, 28, 0.62);
    -webkit-backdrop-filter: blur(10px);
    backdrop-filter: blur(10px);
  }
  .resident-backdrop.is-open { opacity: 1; }
  .resident-backdrop.is-closing { opacity: 0; }

  /* Modal shell overrides - the tray owns the look now. */
  .resident-modal {
    padding: 0;
    background: transparent;
    border-radius: 32px;
    max-width: 940px;
    max-height: none;
    overflow: visible;
    transform: translateY(18px) scale(0.97);
    transition: transform 0.6s var(--ease-expo);
  }
  .resident-backdrop.is-open .resident-modal { transform: translateY(0) scale(1); }
  .resident-backdrop.is-closing .resident-modal { transform: translateY(10px) scale(0.98); }

  .resident-modal-body { padding: 0; }

  /* Double-bezel 1: the machined tray. Radial gold glow + navy gradient,
     hairline inner highlight, generous padding so the core floats in it. */
  .rm-shell {
    background:
      radial-gradient(560px 280px at 90% -12%, rgba(232, 169, 61, 0.30), transparent 62%),
      radial-gradient(420px 260px at -8% 112%, rgba(27, 95, 168, 0.45), transparent 60%),
      linear-gradient(160deg, #14396E, #071C38 72%);
    border-radius: 32px;
    padding: 12px;
    box-shadow:
      0 34px 70px -22px rgba(4, 14, 30, 0.55),
      inset 0 1px 0 rgba(255, 255, 255, 0.14);
  }

  /* Double-bezel 2: the cream dossier. Concentric inner radius, paper
     gradient, hairline ring + top light catching the sheet. */
  .rm-core {
    background: linear-gradient(180deg, #FDFAF3, var(--cream) 58%, var(--cream-2));
    border-radius: 24px;
    padding: 40px 42px 38px;
    max-height: calc(86vh - 20px);
    overflow-y: auto;
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.9),
      inset 0 0 0 1px rgba(46, 36, 24, 0.05);
  }

  /* ---- Hero: portrait in a gold bezel, eyebrow, serif name ---- */
  .rm-hero { display: flex; align-items: center; gap: 26px; }
  .rm-avatar-shell {
    padding: 4px;
    border-radius: 50%;
    flex-shrink: 0;
    background: linear-gradient(140deg, #E8C37F, var(--gold-deep));
    box-shadow: 0 8px 20px -8px rgba(192, 138, 46, 0.55);
  }
  .rm-avatar {
    width: 102px;
    height: 102px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--navy);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: inset 0 2px 7px rgba(6, 14, 28, 0.4);
  }
  .rm-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .rm-initials {
    font-family: 'Fraunces', Georgia, serif;
    font-style: italic;
    font-weight: 600;
    font-size: 36px;
    color: var(--gold);
  }

  .rm-hero-main { flex: 1; min-width: 0; }
  .rm-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: #8A6A1F;
    background: rgba(232, 169, 61, 0.14);
    box-shadow: inset 0 0 0 1px rgba(192, 138, 46, 0.35);
  }
  .rm-name {
    margin: 10px 0 8px;
    font-family: 'Fraunces', Georgia, serif;
    font-weight: 600;
    font-size: clamp(28px, 4.8vw, 40px);
    line-height: 1.08;
    letter-spacing: -0.01em;
    color: var(--navy);
    word-break: break-word;
  }
  .rm-role-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .rm-rule { width: 26px; height: 2px; border-radius: 2px; background: linear-gradient(90deg, var(--gold-deep), rgba(192, 138, 46, 0.15)); flex-shrink: 0; }
  .rm-role { font-size: 15px; font-weight: 600; color: var(--espresso); letter-spacing: 0.02em; }
  .rm-chip {
    display: inline-flex;
    align-items: center;
    padding: 3px 11px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }
  .rm-chip-active { background: #E4EED9; color: #44622F; box-shadow: inset 0 0 0 1px rgba(68, 98, 47, 0.25); }
  .rm-chip-pending { background: #F7E8C8; color: #8A6A1F; box-shadow: inset 0 0 0 1px rgba(192, 138, 46, 0.35); }
  .rm-chip-danger { background: #F5DED9; color: #96413A; box-shadow: inset 0 0 0 1px rgba(150, 65, 58, 0.25); }
  .rm-chip-muted { background: var(--cream-deep); color: var(--espresso-muted); box-shadow: inset 0 0 0 1px var(--ink-hairline); }

  /* Ghost household numeral - the "archive plate" on the far right. */
  .rm-hero-side { flex-shrink: 0; text-align: right; padding-left: 10px; }
  .rm-hh-label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--espresso-muted);
  }
  .rm-hh-number {
    display: block;
    margin-top: 2px;
    font-family: 'Fraunces', Georgia, serif;
    font-style: italic;
    font-weight: 500;
    font-size: 40px;
    line-height: 1;
    color: var(--gold-deep);
    word-break: break-word;
  }

  /* ---- Contact strip: three nested mini-cards with thin-line icons ---- */
  .rm-contact {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-top: 30px;
  }
  .rm-contact-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 15px 16px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.72);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.9),
      inset 0 0 0 1px var(--ink-hairline);
    min-width: 0;
  }
  .rm-contact-icon {
    width: 34px;
    height: 34px;
    border-radius: 11px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--cream-2);
    color: var(--gold-deep);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85), inset 0 0 0 1px var(--ink-hairline);
  }
  .rm-contact-icon svg { width: 16px; height: 16px; }
  .rm-contact-text { display: flex; flex-direction: column; min-width: 0; }
  .rm-contact-label {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--espresso-muted);
  }
  .rm-contact-value {
    margin-top: 3px;
    font-size: 16px;
    font-weight: 500;
    color: var(--espresso);
    word-break: break-word;
  }

  /* ---- Bento: personal details with asymmetric spans ---- */
  .rm-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-top: 18px;
  }
  .rm-cell {
    display: flex;
    flex-direction: column;
    padding: 15px 16px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.72);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.9),
      inset 0 0 0 1px var(--ink-hairline);
    min-width: 0;
  }
  .rm-cell-label {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--espresso-muted);
  }
  .rm-cell-value {
    margin-top: 4px;
    font-size: 17px;
    font-weight: 550;
    color: var(--espresso);
    word-break: break-word;
  }
  .rm-span-3 { grid-column: span 3; }
  .rm-span-4 { grid-column: span 4; }
  .rm-missing {
    font-family: 'Fraunces', Georgia, serif;
    font-style: italic;
    font-weight: 400;
    font-size: 13px;
    color: #A39278;
  }

  /* Badges inside the dossier - gold-tinted hairline pills. */
  .rm-core .status-badges .badge {
    background: #F3E8CB;
    color: #8A6A1F;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    box-shadow: inset 0 0 0 1px rgba(192, 138, 46, 0.3);
  }
  .rm-core .status-badges .badge:empty { display: none; }

  /* Close: hairline glass disc over the navy tray, rotates shut. */
  .resident-modal-close {
    top: 22px;
    right: 22px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.22);
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.9);
    font-size: 17px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 3;
    transition: transform 0.5s var(--ease-expo), background-color 0.5s var(--ease-expo), color 0.5s var(--ease-expo), border-color 0.5s var(--ease-expo);
  }
  .resident-modal-close:hover {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--gold-text);
    transform: rotate(90deg);
  }

  /* ---- Entry choreography: heavy fade-up, staggered per layer ---- */
  .rm-fade { animation: rmFadeUp 0.85s var(--ease-expo) backwards; animation-delay: var(--d, 0ms); }
  @keyframes rmFadeUp {
    from { opacity: 0; transform: translateY(18px); filter: blur(5px); }
    to   { opacity: 1; transform: translateY(0); filter: blur(0); }
  }

  /* View action - a small pressable pill instead of a bare text link. */
  .resident-actions .action-link {
    background: var(--navy);
    color: #FFFFFF;
    border-radius: 999px;
    padding: 5px 15px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.02em;
    box-shadow: 0 2px 9px -2px rgba(11, 46, 92, 0.4);
    transition: transform 0.45s var(--ease-expo), background-color 0.45s var(--ease-expo), box-shadow 0.45s var(--ease-expo);
  }
  .resident-actions .action-link:hover {
    background: var(--gold);
    color: var(--gold-text);
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 5px 14px -4px rgba(192, 138, 46, 0.55);
  }
  .resident-actions .action-link:active { transform: translateY(0) scale(0.97); }
  .resident-actions { white-space: nowrap; }

  /* ---- Collapse below 768px: single column, everything stacks ---- */
  @media (max-width: 640px) {
    .resident-modal { border-radius: 26px; }
    .rm-shell { border-radius: 26px; padding: 8px; }
    .rm-core { border-radius: 19px; padding: 22px 18px 20px; }
    .rm-hero { gap: 14px; }
    .rm-avatar { width: 64px; height: 64px; }
    .rm-initials { font-size: 23px; }
    .rm-hero-side { display: none; }
    .rm-contact { grid-template-columns: 1fr; gap: 8px; }
    .rm-grid { grid-template-columns: 1fr; gap: 8px; }
    .rm-span-3, .rm-span-4 { grid-column: span 1; }
    .resident-modal-close { top: 18px; right: 18px; }
  }

  @media (prefers-reduced-motion: reduce) {
    .resident-backdrop, .resident-modal, .resident-modal-close, .resident-actions .action-link { transition: none; }
    .rm-fade { animation: none; }
  }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>Residents</h1>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($importMsg = flash('import_result')): ?>
    <div class="alert alert-success"><?= e($importMsg) ?></div>
  <?php endif; ?>
  <?php if ($importProblems = flash('import_errors')): ?>
    <div class="alert alert-error"><ul>
      <?php if (is_array($importProblems)): ?>
        <?php foreach ($importProblems as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?>
      <?php else: ?>
        <li><?= e($importProblems) ?></li>
      <?php endif; ?>
    </ul></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="admin-section">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <h2>Records (<span id="recordsCount"><?= e($countLabel) ?></span>)</h2>
      <div style="display:flex; gap:16px; align-items:center;">
        <a href="resident-add.php" class="btn-primary" style="text-decoration:none; padding:8px 16px; font-size:15px;">+ Add resident</a>
      </div>
    </div>

    <div class="filter-bar">
      <div class="filter-search">
        <label for="q">Search</label>
        <div class="search-field">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="21" y1="21" x2="16.5" y2="16.5"></line>
          </svg>
          <input type="text" id="q" placeholder="Search by name, address, or street" autocomplete="off">
        </div>
      </div>
      <div>
        <label for="category">Category</label>
        <select id="category">
          <option value="">Any</option>
          <option value="pwd">PWD</option>
          <option value="solo_parent">Solo parent</option>
          <option value="ofw">OFW</option>
          <option value="osy">Out-of-school youth</option>
          <option value="ip">Indigenous person</option>
          <option value="unemployed">Unemployed</option>
          <option value="employed">Employed</option>
          <option value="student">Student</option>
        </select>
      </div>
      <div>
        <label for="sex">Sex</label>
        <select id="sex">
          <option value="">Any</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
      </div>
      <div>
        <label for="civil_status">Civil status</label>
        <select id="civil_status">
          <option value="">Any</option>
          <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled'] as $cs): ?>
            <option value="<?= $cs ?>"><?= $cs ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="age_from">Min age</label>
        <input type="number" id="age_from" min="0" max="120" placeholder="e.g. 18">
      </div>
      <div>
        <label for="age_to">Max age</label>
        <input type="number" id="age_to" min="0" max="120" placeholder="e.g. 60">
      </div>
    </div>

    <div class="table-scroll">
    <table class="admin-table" id="residents-table">
      <thead>
        <tr><th colspan="6">Head of the household</th></tr>
        <tr><th>Name</th><th>Age</th><th style="text-align:center;">Household Number</th><th>Contact</th><th>Status flags</th><th>Action</th></tr>
      </thead>
      <tbody id="residentsBody">
        <?= render_resident_table_rows($householdGroups, false) ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="admin-section">
    <h2>Archived residents (<span id="archivedCount"><?= e($archivedCountLabel) ?></span>)</h2>

    <div class="filter-bar">
      <div class="filter-search">
        <label for="q_archived">Search</label>
        <div class="search-field">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="21" y1="21" x2="16.5" y2="16.5"></line>
          </svg>
          <input type="text" id="q_archived" placeholder="Search archived residents by name, address, or household number" autocomplete="off">
        </div>
      </div>
    </div>

    <div class="table-scroll">
    <table class="admin-table" id="archived-table">
      <thead>
        <tr><th colspan="6">Head of the household</th></tr>
        <tr><th>Name</th><th>Age</th><th style="text-align:center;">Household Number</th><th>Contact</th><th>Status flags</th><th>Action</th></tr>
      </thead>
      <tbody id="archivedBody">
        <?php if (empty($archivedGroups)): ?>
          <tr class="table-empty"><td colspan="6">No archived residents.</td></tr>
        <?php else: ?>
          <?= render_resident_table_rows($archivedGroups, true) ?>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="admin-section">
    <h2>Import residents from spreadsheet</h2>
    <p class="admin-section-desc">Upload a CSV exported from Google Sheets (File &rarr; Download &rarr; Comma-separated values). Label rows at the top are skipped automatically - the row with the column names is detected for you. New streets create a new household; residents already on file (same household + name + birthdate) get their details updated instead of duplicated.</p>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="import_csv">
      <input type="file" name="csv_file" accept=".csv,text/csv" required>
      <button type="submit" class="btn-primary" style="width:auto; padding:12px 26px; margin-top:12px;">Upload and import</button>
    </form>
    <p class="muted-note" style="margin-top:12px;"><a href="residents.php?download_template=1">Download a CSV template</a> to match the expected column names.</p>
  </div>

  <div class="event-modal-backdrop resident-backdrop" id="resident-modal" onclick="if (event.target === this) closeResidentModal()">
    <div class="event-modal resident-modal" role="dialog" aria-modal="true" aria-label="Resident details">
      <button type="button" class="event-modal-close resident-modal-close" onclick="closeResidentModal()" aria-label="Close">&times;</button>
      <div class="resident-modal-body" id="resident-modal-body"></div>
    </div>
  </div>

  <script>
  var residentDetails = <?= json_encode($residentDetails, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function openResidentModal(memberId) {
    var html = residentDetails[memberId];
    if (!html) { return; }
    var modal = document.getElementById('resident-modal');
    modal.classList.remove('is-closing');
    document.getElementById('resident-modal-body').innerHTML = html;
    modal.classList.add('is-open');
  }

  function closeResidentModal() {
    var modal = document.getElementById('resident-modal');
    if (!modal.classList.contains('is-open')) { return; }
    modal.classList.add('is-closing');
    window.setTimeout(function () {
      modal.classList.remove('is-open', 'is-closing');
    }, 420);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeResidentModal(); }
  });

  function toggleHouseholdMembers(householdId, headRow) {
    var isOpen = headRow.classList.contains('is-open');
    var rows = headRow.closest('table').querySelectorAll('.household-' + householdId);
    if (isOpen) {
      headRow.classList.remove('is-open');
      for (var i = 0; i < rows.length; i++) { rows[i].classList.remove('is-open'); }
    } else {
      headRow.classList.add('is-open');
      for (var i = 0; i < rows.length; i++) { rows[i].classList.add('is-open'); }
    }
  }

  function setupResidentFilters(tableId, searchId, countId) {
    var tbody = document.getElementById(tableId);
    var searchInput = document.getElementById(searchId);
    var countEl = document.getElementById(countId);
    var categorySelect = document.getElementById('category');
    var sexSelect = document.getElementById('sex');
    var civilSelect = document.getElementById('civil_status');
    var ageFromInput = document.getElementById('age_from');
    var ageToInput = document.getElementById('age_to');
    if (!tbody || !searchInput || !countEl) return;

    var groups = [];
    Array.prototype.forEach.call(tbody.querySelectorAll('tr.household-head-row'), function (headRow) {
      var hid = headRow.dataset.hid;
      var memberRows = Array.prototype.filter.call(
        tbody.querySelectorAll('tr.household-' + hid),
        function (r) { return !!r.dataset.name; }
      );
      groups.push({ head: headRow, members: memberRows });
    });

    function matches(r) {
      var query = searchInput.value.trim().toLowerCase();
      if (query) {
        var nameHit = r.dataset.name.indexOf(query) !== -1;
        var addressHit = r.dataset.address.indexOf(query) !== -1;
        var hidHit = /^\d+$/.test(query) && r.dataset.hid === query;
        if (!(nameHit || addressHit || hidHit)) { return false; }
      }
      if (categorySelect && categorySelect.value && r.dataset.cat.split(',').indexOf(categorySelect.value) === -1) { return false; }
      if (sexSelect && sexSelect.value && r.dataset.sex !== sexSelect.value) { return false; }
      if (civilSelect && civilSelect.value && r.dataset.civil !== civilSelect.value) { return false; }
      var ageFrom = ageFromInput ? parseInt(ageFromInput.value, 10) : NaN;
      if (!isNaN(ageFrom) && (r.dataset.age === '' || parseInt(r.dataset.age, 10) < ageFrom)) { return false; }
      var ageTo = ageToInput ? parseInt(ageToInput.value, 10) : NaN;
      if (!isNaN(ageTo) && (r.dataset.age === '' || parseInt(r.dataset.age, 10) > ageTo)) { return false; }
      return true;
    }

    function pluralize(n, word) {
      return n + ' ' + word + (n === 1 ? '' : 's');
    }

    function applyFilters() {
      var visibleHouseholds = 0;
      var visibleResidents = 0;
      groups.forEach(function (g) {
        var show = matches(g.head) || g.members.some(matches);
        g.head.style.display = show ? '' : 'none';
        g.members.forEach(function (m) {
          m.style.display = show ? '' : 'none';
        });
        if (show) {
          visibleHouseholds++;
          visibleResidents += 1 + g.members.length;
        }
      });
      countEl.textContent = pluralize(visibleHouseholds, 'household') + ', ' + pluralize(visibleResidents, 'resident');

      var emptyRow = tbody.querySelector('tr.table-empty');
      if (visibleHouseholds === 0) {
        if (!emptyRow) {
          emptyRow = document.createElement('tr');
          emptyRow.className = 'table-empty';
          emptyRow.innerHTML = '<td colspan="6">No residents match your search or filter criteria.</td>';
          tbody.appendChild(emptyRow);
        }
        emptyRow.style.display = '';
      } else if (emptyRow) {
        emptyRow.style.display = 'none';
      }
    }

    searchInput.addEventListener('input', applyFilters);
    applyFilters();
    return applyFilters;
  }

  var applyMainFilters = setupResidentFilters('residentsBody', 'q', 'recordsCount');
  var applyArchivedFilters = setupResidentFilters('archivedBody', 'q_archived', 'archivedCount');

  (function () {
    var categorySelect = document.getElementById('category');
    var sexSelect = document.getElementById('sex');
    var civilSelect = document.getElementById('civil_status');
    var ageFromInput = document.getElementById('age_from');
    var ageToInput = document.getElementById('age_to');
    if (!categorySelect && !sexSelect && !civilSelect && !ageFromInput && !ageToInput) return;
    [categorySelect, sexSelect, civilSelect, ageFromInput, ageToInput].forEach(function (el) {
      if (el) el.addEventListener('change', function () { applyMainFilters(); applyArchivedFilters(); });
    });
  })();
  </script>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
