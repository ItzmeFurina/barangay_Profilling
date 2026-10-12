<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';
require_once __DIR__ . '/../shared/program_criteria_validation.php';

require_admin();
$activePage = 'programs';

const PROGRAM_CATEGORIES = [
    'Financial Assistance',
    'Food Assistance',
    'Medical/Health Assistance',
    'Educational Assistance',
    'Livelihood Assistance',
    'Emergency/Disaster Assistance',
    'Senior Citizen Assistance',
    'PWD Assistance',
    'Women and Children Assistance',
    'Housing/Shelter Assistance',
    'Employment Assistance',
];

/**
 * Normalizes a <input type="datetime-local"> value ('YYYY-MM-DDTHH:MM',
 * local app time) to the stored 'Y-m-d H:i:s' form, or returns null if it
 * does not parse. Strict: rejects out-of-range values like 2026-02-31 25:00.
 */
function normalize_datetime_local(string $value): ?string {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone(APP_TIMEZONE));
    if ($dt === false) {
        return null;
    }
    $check = $dt->format('Y-m-d\TH:i');
    if ($check !== $value) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

$pdo = get_db_connection();
maybe_archive_programs($pdo, $_SESSION['staff_user_id'] ?? null);
$errors = [];
$prev = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create_program') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $applicationMode = in_array($_POST['application_mode'] ?? '', ['application_required', 'no_application_required'], true)
            ? $_POST['application_mode'] : 'application_required';
        // The import option is a separate Yes/No choice available under BOTH
        // program types - it no longer rides on application_mode.
        $importExistingList = ($_POST['import_existing_list'] ?? '') === '1';
        $prev = [
            'application_mode'     => $applicationMode,
            'import_existing_list' => $importExistingList,
            'name'                 => normalize_label($_POST['name'] ?? ''),
            'description'          => trim($_POST['description'] ?? ''),
            'category'             => trim($_POST['category'] ?? ''),
            'category_custom'      => trim($_POST['category_custom'] ?? ''),
            'provider'             => trim($_POST['provider'] ?? ''),
            'start_date'           => trim($_POST['start_date'] ?? ''),
            'end_date'             => trim($_POST['end_date'] ?? ''),
            'application_deadline' => trim($_POST['application_deadline'] ?? ''),
            'release_mode'         => in_array($_POST['release_mode'] ?? 'single', ['none', 'single', 'range'], true)
                                        ? $_POST['release_mode'] : 'single',
            'release_date'         => trim($_POST['release_date'] ?? ''),
            'release_start'        => trim($_POST['release_start'] ?? ''),
            'release_end'          => trim($_POST['release_end'] ?? ''),
            'slot_limit'           => trim($_POST['slot_limit'] ?? ''),
            'min_age'              => trim($_POST['min_age'] ?? ''),
            'max_age'              => trim($_POST['max_age'] ?? ''),
            'income_direction'     => in_array($_POST['income_direction'] ?? 'max', ['max', 'min', 'none'], true)
                                        ? $_POST['income_direction']
                                        : 'max',
            'income_amount'        => trim($_POST['income_amount'] ?? ''),
        ];
        $flags = [
            'requires_pwd'         => isset($_POST['requires_pwd']),
            'requires_solo_parent' => isset($_POST['requires_solo_parent']),
            'requires_ofw'         => isset($_POST['requires_ofw']),
            'requires_ip'          => isset($_POST['requires_ip']),
            'requires_osy'         => isset($_POST['requires_osy']),
            'requires_unemployed'  => isset($_POST['requires_unemployed']),
            'requires_employed'    => isset($_POST['requires_employed']),
            'requires_student'     => isset($_POST['requires_student']),
        ];

        // Required documents: paired label + file-count rows. Skip fully
        // empty rows so an accidentally blank block never gets stored.
        $docLabels = $_POST['document_label'] ?? [];
        $docFiles = $_POST['document_files'] ?? [];
        $prev['documents'] = [];
        for ($i = 0; $i < count($docLabels); $i++) {
            $docLabel = trim($docLabels[$i] ?? '');
            $docFileCount = trim($docFiles[$i] ?? '');
            if ($docLabel === '' && $docFileCount === '') {
                continue;
            }
            $prev['documents'][] = ['label' => $docLabel, 'files' => $docFileCount];
        }

        // Category: a fixed pick from the list, or a custom label typed when
        // "Other" is chosen - that typed value becomes the category itself.
        $category = '';
        if ($prev['category'] === 'Other') {
            if ($prev['category_custom'] !== '') {
                $category = normalize_label($prev['category_custom']);
            }
        } elseif (in_array($prev['category'], PROGRAM_CATEGORIES, true)) {
            $category = $prev['category'];
        }
        $prev['category_resolved'] = $category;

        if ($prev['name'] === '') {
            $errors[] = 'Program name is required.';
        }
        if ($category === '') {
            $errors[] = $prev['category'] === 'Other'
                ? 'Type a name for the custom category, or pick one from the list.'
                : 'Choose a category for the program.';
        }
        if ($prev['provider'] !== '' && mb_strlen($prev['provider']) > 255) {
            $errors[] = 'The source/provider is too long (255 characters max).';
        }
        foreach (['min_age', 'max_age', 'income_amount'] as $numField) {
            if ($prev[$numField] !== '' && !is_numeric($prev[$numField])) {
                $errors[] = 'Enter a valid number for ' . str_replace('_', ' ', $numField) . '.';
            }
        }
        if (is_numeric($prev['min_age']) && is_numeric($prev['max_age'])
            && $prev['min_age'] !== '' && $prev['max_age'] !== ''
            && (int) $prev['min_age'] > (int) $prev['max_age']) {
            $errors[] = 'The minimum age cannot be higher than the maximum age.';
        }
        if ($applicationMode === 'application_required') {
            if ($prev['income_direction'] !== 'none' && $prev['income_amount'] === '') {
                $errors[] = 'Enter the monthly income amount, or pick "No income condition".';
            }
            if (($prev['income_amount'] ?? '') !== '' && (float) $prev['income_amount'] < 0) {
                $errors[] = 'Monthly income cannot be negative.';
            }
        }
        if ($prev['slot_limit'] !== '' && (filter_var($prev['slot_limit'], FILTER_VALIDATE_INT) === false || (int) $prev['slot_limit'] < 1)) {
            $errors[] = 'Enter a valid number of available slots, or leave it blank for no limit.';
        }
        if ($prev['start_date'] === '' || $prev['end_date'] === '') {
            $errors[] = 'Both a start and an end to the validity window are required.';
        } else {
            $startDb = normalize_datetime_local($prev['start_date']);
            $endDb = normalize_datetime_local($prev['end_date']);
            if ($startDb === null || $endDb === null) {
                $errors[] = 'Enter valid start and end date-times (including time) for the program.';
            } else {
                $start = new DateTime($startDb, new DateTimeZone(APP_TIMEZONE));
                $end = new DateTime($endDb, new DateTimeZone(APP_TIMEZONE));
                if ($end <= $start) {
                    $errors[] = 'The end must be after the start date-time.';
                }
                if ($end < new DateTime('now', new DateTimeZone(APP_TIMEZONE))) {
                    $errors[] = 'The end date cannot be in the past.';
                }

                // Application deadline is optional but, when given, must sit
                // inside the program's own validity window. A blank deadline
                // means "accept applications until the program ends". Only
                // application_required programs accept applications at all, so
                // a deadline posted for any other type is discarded.
                $deadlineDb = null;
                if ($applicationMode === 'application_required' && $prev['application_deadline'] !== '') {
                    $deadlineDb = normalize_datetime_local($prev['application_deadline']);
                    if ($deadlineDb === null) {
                        $errors[] = 'Enter a valid application deadline date-time.';
                    } else {
                        $deadline = new DateTime($deadlineDb, new DateTimeZone(APP_TIMEZONE));
                        if ($deadline < $start) {
                            $errors[] = 'The application deadline must be on or after the program start date-time.';
                        }
                        if ($deadline > $end) {
                            $errors[] = 'The application deadline cannot be after the program end date.';
                        }
                    }
                }
            }
        }
        if ($applicationMode === 'application_required') {
            foreach ($prev['documents'] as $doc) {
                if ($doc['label'] === '') {
                    $errors[] = 'Every required document needs a label.';
                }
                if ($doc['files'] === '' || !is_numeric($doc['files']) || (int) $doc['files'] < 1 || (int) $doc['files'] > 10) {
                    $errors[] = ($doc['label'] !== '' ? '"' . $doc['label'] . '"' : 'Every required document') . ' needs a number of files between 1 and 10.';
                }
            }
        }
        // Criteria are validated for BOTH program types: an
        // application_required program uses them to decide who may apply, a
        // no_application_required program uses them to decide who is
        // auto-enrolled. validate_program_criteria() already rejects a blank
        // set (blank passes everyone through member_criteria_result()), so a
        // no-application program can never end up enrolling the whole
        // barangay by accident.
        $criteriaData = [
            'min_age' => $prev['min_age'],
            'max_age' => $prev['max_age'],
            'income_direction' => $prev['income_direction'],
            'income_amount' => $prev['income_amount'],
            'requires_pwd' => $flags['requires_pwd'],
            'requires_solo_parent' => $flags['requires_solo_parent'],
            'requires_ofw' => $flags['requires_ofw'],
            'requires_ip' => $flags['requires_ip'],
            'requires_osy' => $flags['requires_osy'],
            'requires_unemployed' => $flags['requires_unemployed'],
            'requires_employed' => $flags['requires_employed'],
            'requires_student' => $flags['requires_student'],
        ];
        $errors = array_merge($errors, validate_program_criteria($criteriaData));

        // Release date/period validation.
        $releaseDb = null;
        $releaseStartDb = null;
        $releaseEndDb = null;
        if ($prev['release_mode'] === 'none') {
            // No date specified — leave all null.
        } elseif ($prev['release_mode'] === 'single') {
            if ($prev['release_date'] !== '') {
                $releaseDb = normalize_datetime_local($prev['release_date']);
                if ($releaseDb === null) {
                    $errors[] = 'Enter a valid benefit release date and time.';
                }
            }
        } else {
            if ($prev['release_start'] === '' || $prev['release_end'] === '') {
                $errors[] = 'Both a start and end date are required for a release period.';
            } else {
                $releaseStartDb = normalize_datetime_local($prev['release_start']);
                $releaseEndDb = normalize_datetime_local($prev['release_end']);
                if ($releaseStartDb === null || $releaseEndDb === null) {
                    $errors[] = 'Enter valid start and end date-times for the benefit release period.';
                } else {
                    $rs = new DateTime($releaseStartDb, new DateTimeZone(APP_TIMEZONE));
                    $re = new DateTime($releaseEndDb, new DateTimeZone(APP_TIMEZONE));
                    if ($re <= $rs) {
                        $errors[] = 'The release period end must be after the start.';
                    }
                }
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Build the release_period string from the new fields.
                $releasePeriodStr = null;
                if ($prev['release_mode'] === 'single' && $releaseDb !== null) {
                    $releasePeriodStr = fmt_dt($releaseDb);
                } elseif ($prev['release_mode'] === 'range' && isset($releaseStartDb) && isset($releaseEndDb)
                          && $releaseStartDb !== null && $releaseEndDb !== null) {
                    $releasePeriodStr = fmt_dt($releaseStartDb) . ' &#8594; ' . fmt_dt($releaseEndDb);
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO programs (name, description, status, start_date, end_date,
                                           category, provider, application_deadline,
                                           release_period, slot_limit, program_type,
                                           application_mode, import_existing_list, created_by)
                     VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $prev['name'],
                    $prev['description'] !== '' ? $prev['description'] : null,
                    $startDb,
                    $endDb,
                    $category,
                    $prev['provider'] !== '' ? $prev['provider'] : null,
                    $deadlineDb,
                    $releasePeriodStr,
                    $prev['slot_limit'] !== '' ? (int) $prev['slot_limit'] : null,
                    // Legacy mirror: program_type is still read for the
                    // resident apply-page's fallback ID label. It is a
                    // VARCHAR(50), so long custom categories are truncated.
                    substr($category, 0, 50),
                    $applicationMode,
                    $importExistingList ? 1 : 0,
                    $_SESSION['staff_user_id'],
                ]);
                $programId = (int) $pdo->lastInsertId();

                // Criteria exist for BOTH program types: the engine marks
                // matches 'qualified' for application_required programs and
                // auto-enrolls them for no_application_required ones - either
                // way it can only reach programs that have a criteria row.
                $incomeAmount = $prev['income_direction'] !== 'none' && $prev['income_amount'] !== ''
                    ? (float) $prev['income_amount']
                    : null;

                $stmt = $pdo->prepare(
                    'INSERT INTO program_criteria
                        (program_id, min_age, max_age, max_income, min_income,
                         requires_pwd, requires_solo_parent,
                         requires_ofw, requires_ip, requires_osy, requires_unemployed,
                         requires_employed, requires_student)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $programId,
                    $prev['min_age'] !== '' ? (int) $prev['min_age'] : null,
                    $prev['max_age'] !== '' ? (int) $prev['max_age'] : null,
                    $prev['income_direction'] === 'max' ? $incomeAmount : null,
                    $prev['income_direction'] === 'min' ? $incomeAmount : null,
                    $flags['requires_pwd'] ? 1 : 0,
                    $flags['requires_solo_parent'] ? 1 : 0,
                    $flags['requires_ofw'] ? 1 : 0,
                    $flags['requires_ip'] ? 1 : 0,
                    $flags['requires_osy'] ? 1 : 0,
                    $flags['requires_unemployed'] ? 1 : 0,
                    $flags['requires_employed'] ? 1 : 0,
                    $flags['requires_student'] ? 1 : 0,
                ]);

                // Document requirements only make sense when residents apply.
                if ($applicationMode === 'application_required') {
                    $docStmt = $pdo->prepare(
                        'INSERT INTO program_documents (program_id, label, files_required, display_order)
                         VALUES (?, ?, ?, ?)'
                    );
                    foreach ($prev['documents'] as $docIndex => $doc) {
                        $docStmt->execute([$programId, $doc['label'], (int) $doc['files'], $docIndex]);
                    }
                }

                log_activity(
                    $pdo,
                    $_SESSION['staff_user_id'],
                    'Created program: ' . $prev['name']
                        . ($applicationMode === 'no_application_required' ? ' (no application required)' : ' (application required)')
                        . ($importExistingList ? ' with existing beneficiary list import' : ''),
                    'create'
                );

                // A brand-new program might match households that already
                // finished their census before this program existed, so every
                // existing household needs a fresh pass, not just future ones.
                // This runs for both program types - a no_application_required
                // program's whole beneficiary list comes from this first pass.
                $householdIds = $pdo->query('SELECT household_id FROM households')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($householdIds as $hid) {
                    evaluate_household_eligibility($pdo, (int) $hid, $_SESSION['staff_user_id']);
                }

                $pdo->commit();
                if ($importExistingList) {
                    flash('success', 'Program created. Now import your beneficiary list.');
                    redirect('import-beneficiaries.php?program_id=' . $programId);
                } else {
                    flash('success', 'Program created. Every existing household has already been re-checked against it.');
                    redirect('programs.php');
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Program creation failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving the program. Please try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'toggle_status') {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $programId = (int) ($_POST['program_id'] ?? 0);
        $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';

        if ($newStatus === 'active') {
            $check = $pdo->prepare('SELECT end_date FROM programs WHERE program_id = ?');
            $check->execute([$programId]);
            $endDate = $check->fetchColumn();
            if ($endDate !== null && strtotime($endDate) < time()) {
                flash('error', 'This program has expired and cannot be reactivated.');
                redirect('programs.php');
            }
        }

        $pdo->prepare('UPDATE programs SET status = ? WHERE program_id = ?')->execute([$newStatus, $programId]);
        log_activity($pdo, $_SESSION['staff_user_id'], "Set program #{$programId} to {$newStatus}", 'update');
        flash('success', 'Program status updated.');
        redirect('programs.php');
    }
    flash('error', 'Your session expired. Please try again.');
    redirect('programs.php');
}

function summarize_criteria(array $p): string {
    $parts = [];
    if ($p['min_age'] !== null && $p['max_age'] !== null) {
        $parts[] = "Age {$p['min_age']}-{$p['max_age']}";
    } elseif ($p['min_age'] !== null) {
        $parts[] = "Age {$p['min_age']}+";
    } elseif ($p['max_age'] !== null) {
        $parts[] = "Age up to {$p['max_age']}";
    }
    if (!empty($p['requires_pwd'])) { $parts[] = 'PWD'; }
    if (!empty($p['requires_solo_parent'])) { $parts[] = 'Solo parent'; }
    if (!empty($p['requires_ofw'])) { $parts[] = 'OFW'; }
    if (!empty($p['requires_ip'])) { $parts[] = 'Indigenous'; }
    if (!empty($p['requires_osy'])) { $parts[] = 'Out-of-school youth'; }
    if (!empty($p['requires_unemployed'])) { $parts[] = 'Unemployed'; }
    if (!empty($p['requires_employed'])) { $parts[] = 'Employed'; }
    if (!empty($p['requires_student'])) { $parts[] = 'Student'; }
    if ($p['min_household_size'] !== null) { $parts[] = "Household of {$p['min_household_size']}+"; }
    if ($p['max_income'] !== null && $p['max_income'] !== '') {
        $parts[] = 'Monthly income up to &#8369;' . number_format((float) $p['max_income'], 0);
    }
    if ($p['min_income'] !== null && $p['min_income'] !== '') {
        $parts[] = 'Monthly income at least &#8369;' . number_format((float) $p['min_income'], 0);
    }
    return $parts ? implode(' &middot; ', $parts) : 'No conditions set';
}

function render_schedule(array $p): string {
    $start = fmt_dt($p['start_date'] ?? null);
    $end = fmt_dt($p['end_date'] ?? null);
    if ($start === null && $end === null) {
        return '<span class="muted-note">Open-ended</span>';
    }
    return ($start ?? '<span class="muted-note">Anytime</span>') . '<br>&#8594; ' . ($end ?? '<span class="muted-note">Ongoing</span>');
}

function render_application_deadline(array $p): string {
    $deadline = fmt_dt($p['application_deadline'] ?? null);
    if ($deadline !== null) {
        return $deadline;
    }
    // No explicit deadline - the program end date is the fallback.
    $end = fmt_dt($p['end_date'] ?? null);
    if ($end !== null) {
        return 'Program end<br><span class="muted-note">' . $end . '</span>';
    }
    return '<span class="muted-note">Open-ended</span>';
}

function render_slots(array $p): string {
    $limit = $p['slot_limit'] ?? null;
    if ($limit === null || $limit === '') {
        return '<span class="muted-note">Unlimited</span>';
    }
    $limit = (int) $limit;
    $filled = (int) ($p['approved_count'] ?? 0);
    $remaining = max(0, $limit - $filled);
    return $filled . ' of ' . $limit . ' filled'
        . '<div class="muted-note">' . $remaining . ' slot' . ($remaining === 1 ? '' : 's') . ' left</div>';
}

function render_program_status_badge(array $p): string {
    return match (program_state($p)) {
        'open'     => '<span class="badge badge-active">Open</span>',
        'upcoming' => '<span class="badge badge-pending">Upcoming</span>',
        'closed'   => '<span class="badge badge-archived">Closed</span>',
    };
}

function render_category(array $p): string {
    $category = $p['category'] ?? ($p['program_type'] ?? null);
    if ($category === null || $category === '') {
        return '<span class="muted-note">&mdash;</span>';
    }
    return '<span class="category-pill">' . e((string) $category) . '</span>';
}

function render_doc_requirement(array $doc): void {
    $label = $doc['label'] ?? '';
    $files = isset($doc['files']) && $doc['files'] !== '' ? (int) $doc['files'] : 1;
    if ($files < 1) { $files = 1; }
    if ($files > 10) { $files = 10; }
    ?>
    <div class="doc-requirement">
      <div class="doc-requirement-head">
        <strong>Document requirement</strong>
        <button type="button" class="btn-archive" onclick="removeDocRequirement(this)">Remove</button>
      </div>
      <div class="member-grid">
        <div>
          <label>Document label</label>
          <input type="text" name="document_label[]" placeholder="e.g. Barangay Clearance" value="<?= e($label) ?>">
        </div>
        <div>
          <label>Number of files required</label>
          <input type="number" name="document_files[]" min="1" max="10" value="<?= $files ?>" oninput="updateDocPreview(this)">
        </div>
      </div>
      <div class="doc-preview">
        <div class="doc-preview-label">Preview</div>
        <div class="doc-preview-files">
          <?php for ($i = 0; $i < $files; $i++): ?><input type="file" disabled><?php endfor; ?>
        </div>
      </div>
    </div>
    <?php
}

$programs = $pdo->query(
    "SELECT p.*, pc.min_age, pc.max_age, pc.max_income, pc.min_income, pc.requires_pwd, pc.requires_solo_parent,
            pc.requires_ofw, pc.requires_ip, pc.requires_osy, pc.requires_unemployed,
            pc.requires_employed, pc.requires_student, pc.min_household_size,
            (SELECT COUNT(*) FROM beneficiaries b WHERE b.program_id = p.program_id) AS beneficiary_count,
            (SELECT COUNT(*) FROM beneficiaries b WHERE b.program_id = p.program_id AND b.claim_status = 'approved') AS approved_count,
            (SELECT COUNT(*) FROM beneficiary_imports bi WHERE bi.program_id = p.program_id AND bi.status = 'completed') AS import_count
     FROM programs p
     LEFT JOIN program_criteria pc ON pc.program_id = p.program_id
     ORDER BY p.created_at DESC"
)->fetchAll();

$programDocs = [];
if (!empty($programs)) {
    $ids = array_column($programs, 'program_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $docStmt = $pdo->prepare(
        "SELECT program_id, label, files_required FROM program_documents
         WHERE program_id IN ($placeholders) ORDER BY display_order ASC, document_id ASC"
    );
    $docStmt->execute($ids);
    foreach ($docStmt->fetchAll() as $doc) {
        $programDocs[(int) $doc['program_id']][] = $doc;
    }
}

$stateCounts = ['all' => count($programs), 'open' => 0, 'upcoming' => 0, 'closed' => 0];
foreach ($programs as $prog) {
    $stateCounts[program_state($prog)]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Programs - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
/* Self-contained (in addition to assets/css/style.css) so the filter tabs,
   category pills and detail modal render correctly even if the shared
   stylesheet is stale. Tokens mirror the shared set. */
.program-filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.program-filter-tab {
  border: 1px solid var(--border, #D6E1EE);
  background: #fff;
  color: var(--muted, #3C5470);
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  padding: 10px 20px;
  border-radius: 28px;
  cursor: pointer;
  line-height: normal;
  white-space: nowrap;
  transition: background 0.25s, color 0.25s;
}
.program-filter-tab:hover, .program-filter-tab.is-active {
  background: var(--navy, #0B2E5C);
  border-color: var(--navy, #0B2E5C);
  color: #fff;
}
.category-pill {
  display: inline-block;
  background: #EEF3FA;
  color: var(--navy, #0B2E5C);
  font-size: 12.5px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
  white-space: nowrap;
}
.program-modal-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
  padding: 22px 26px 0;
}
.program-modal-head h2 { margin: 0; font-size: 26px; }
.program-modal-body { padding: 14px 26px 26px; }
.program-detail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px 18px;
  margin-top: 14px;
}
.program-detail-grid .detail-item .detail-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted, #3C5470);
  margin-bottom: 3px;
}
.program-detail-grid .detail-item .detail-value { font-size: 15px; color: var(--ink, #16233A); }
.program-modal-block {
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1px solid var(--border, #D6E1EE);
}
.program-modal-block h3 { margin: 0 0 6px; font-size: 15px; color: var(--navy, #0B2E5C); }
.program-modal-block p, .program-modal-block li { font-size: 14.5px; color: var(--ink, #16233A); }
.program-modal-docs { margin: 0; padding-left: 20px; }
.doc-note { font-size: 14.5px; font-weight: 500; color: var(--blue, #1B5FA8); }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>Programs &amp; Eligibility Criteria</h1>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="admin-section">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap;">
      <h2 style="margin:0;">Existing programs (<?= count($programs) ?>)</h2>
      <div class="program-filter-tabs" id="program-filter-tabs">
        <button type="button" class="program-filter-tab is-active" data-filter="all">All (<?= $stateCounts['all'] ?>)</button>
        <button type="button" class="program-filter-tab" data-filter="open">Open (<?= $stateCounts['open'] ?>)</button>
        <button type="button" class="program-filter-tab" data-filter="upcoming">Upcoming (<?= $stateCounts['upcoming'] ?>)</button>
        <button type="button" class="program-filter-tab" data-filter="closed">Closed (<?= $stateCounts['closed'] ?>)</button>
      </div>
    </div>
    <?php if (empty($programs)): ?>
      <p class="muted-note">No programs yet - create the first one below.</p>
    <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr><th>Category</th><th>Program</th><th>Mode</th><th>Validity</th><th>Beneficiaries</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($programs as $prog): ?>
            <?php $state = program_state($prog); ?>
            <tr data-state="<?= e($state) ?>">
              <td><?= render_category($prog) ?></td>
              <td>
                <strong><?= e($prog['name']) ?></strong>
              </td>
              <td>
                <?php if (($prog['application_mode'] ?? 'application_required') === 'no_application_required'): ?>
                  <span class="badge badge-pending">No Application</span>
                <?php else: ?>
                  <span class="badge badge-active">Application Required</span>
                <?php endif; ?>
                <?php if (!empty($prog['import_existing_list'])): ?>
                  <span class="badge badge-archived" title="An official beneficiary list may be imported into this program">Import</span>
                <?php endif; ?>
              </td>
              <td class="muted-note"><?= render_schedule($prog) ?></td>
              <td><?= (int) $prog['beneficiary_count'] ?></td>
              <td><?= render_program_status_badge($prog) ?></td>
              <td>
                <div class="inline-form">
                  <button type="button" class="btn-primary" style="width:auto; padding:6px 14px; font-size:14px;" onclick="openProgramModal(<?= (int) $prog['program_id'] ?>)">View</button>
                  <?php if (!empty($prog['import_existing_list'])): ?>
                    <a href="import-beneficiaries.php?program_id=<?= (int) $prog['program_id'] ?>" class="btn-gold" style="width:auto; padding:6px 14px; font-size:14px; text-decoration:none;">Import List</a>
                  <?php endif; ?>
                  <?php
                    $isExpired = $prog['status'] === 'inactive'
                        && !empty($prog['end_date'])
                        && strtotime($prog['end_date']) < time();
                  ?>
                  <?php if ($isExpired): ?>
                    <span class="muted-note" style="font-size:13px; font-style:italic;">Archived</span>
                  <?php else: ?>
                    <form method="POST" class="inline-form">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="form" value="toggle_status">
                      <input type="hidden" name="program_id" value="<?= (int) $prog['program_id'] ?>">
                      <?php if ($prog['status'] === 'active'): ?>
                        <input type="hidden" name="new_status" value="inactive">
                        <button type="submit" class="btn-archive">Deactivate</button>
                      <?php else: ?>
                        <input type="hidden" name="new_status" value="active">
                        <button type="submit" class="btn-approve">Reactivate</button>
                      <?php endif; ?>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div style="text-align:center; margin-top:24px;">
      <button type="button" class="btn-primary" id="show-create-btn" onclick="showCreateForm()" style="width:auto; padding:14px 32px; font-size:16px;">+ Create Program</button>
    </div>
  </div>

  <div class="edit-card" id="create-program-form" style="display:none;">
    <a href="programs.php" onclick="hideCreateForm(); return false;" class="back-btn ef-fade" style="--d:40ms; margin-bottom:20px;">
      <span class="back-btn-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg>
      </span>
      <span>Back to programs</span>
    </a>
    <div class="edit-card-head ef-fade" style="--d:80ms">
      <h2>Create a new program</h2>
      <span class="edit-card-hint">Set up eligibility criteria, schedule, and required documents</span>
    </div>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="create_program">

      <div class="edit-fields ef-fade" style="--d:130ms">
        <div class="ef-2">
          <label>Program type <em>*</em></label>
          <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:6px;">
            <label class="chip" style="margin:0;">
              <input type="radio" name="application_mode" value="application_required" <?= ($prev['application_mode'] ?? 'application_required') === 'application_required' ? 'checked' : '' ?> onchange="toggleApplicationMode()">
              <span>Application Required</span>
            </label>
            <label class="chip" style="margin:0;">
              <input type="radio" name="application_mode" value="no_application_required" <?= ($prev['application_mode'] ?? '') === 'no_application_required' ? 'checked' : '' ?> onchange="toggleApplicationMode()">
              <span>No Application Required</span>
            </label>
          </div>
          <p class="muted-note" id="application-mode-hint">Residents must apply and be reviewed by an admin.</p>
        </div>
        <div class="ef-2">
          <label>Import Existing Beneficiary List? <em>*</em></label>
          <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:6px;">
            <label class="chip" style="margin:0;">
              <input type="radio" name="import_existing_list" value="1" <?= !empty($prev['import_existing_list']) ? 'checked' : '' ?> onchange="toggleImportListOption()">
              <span>Yes</span>
            </label>
            <label class="chip" style="margin:0;">
              <input type="radio" name="import_existing_list" value="0" <?= empty($prev['import_existing_list']) ? 'checked' : '' ?> onchange="toggleImportListOption()">
              <span>No</span>
            </label>
          </div>
          <p class="muted-note" id="import-list-hint">Imported beneficiaries join this program's list without applying, and are kept distinct from residents the system detects automatically.</p>
        </div>
        <div class="ef-2">
          <label for="name">Program name <em>*</em></label>
          <input type="text" id="name" name="name" value="<?= old('name', $prev) ?>" required>
        </div>
        <div class="ef-2">
          <label for="category">Category <em>*</em></label>
          <select id="category" name="category" onchange="onCategoryChange(this)" required<?= ($prev['category'] ?? '') === 'Other' ? ' style="display:none;"' : '' ?>>
            <option value="" disabled<?= ($prev['category'] ?? '') === '' ? ' selected' : '' ?>>-- Select a category --</option>
            <?php foreach (PROGRAM_CATEGORIES as $cat): ?>
              <option value="<?= e($cat) ?>"<?= ($prev['category'] ?? '') === $cat ? ' selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
            <option value="Other"<?= ($prev['category'] ?? '') === 'Other' ? ' selected' : '' ?>>Other (type a custom category)</option>
          </select>
          <input type="text" id="category_custom" name="category_custom" placeholder="Type a custom category" value="<?= old('category_custom', $prev) ?>"<?= ($prev['category'] ?? '') === 'Other' ? '' : ' style="display:none;"' ?>>
          <button type="button" id="category-custom-back" onclick="revertCategorySelect()" style="display:<?= ($prev['category'] ?? '') === 'Other' ? 'inline' : 'none' ?>; margin-top:6px; background:none; border:none; color:var(--blue); font-size:12.5px; font-weight:600; cursor:pointer; padding:0;">&larr; Choose from list</button>
        </div>
        <div class="ef-2">
          <label for="provider">Source / provider</label>
          <input type="text" id="provider" name="provider" placeholder="e.g. DSWD Manila, Barangay Office, a private sponsor" value="<?= old('provider', $prev) ?>">
        </div>
        <div class="ef-4">
          <label for="description">Description</label>
          <textarea id="description" name="description" rows="3"><?= old('description', $prev) ?></textarea>
        </div>
      </div>

      <h2 class="section-label ef-fade" style="--d:180ms;">Program schedule</h2>
      <p class="muted-note">The program is active only between these moments. When the end passes, the program is automatically archived and stops matching households.</p>

      <div class="edit-fields ef-fade" style="--d:220ms">
        <div class="ef-2">
          <label for="start_date">Start date &amp; time <em>*</em></label>
          <input type="datetime-local" id="start_date" name="start_date" value="<?= old('start_date', $prev) ?>" required>
        </div>
        <div class="ef-2">
          <label for="end_date">End date &amp; time <em>*</em></label>
          <input type="datetime-local" id="end_date" name="end_date" value="<?= old('end_date', $prev) ?>" required>
        </div>
      </div>

      <div id="applications-section">
      <h2 class="section-label ef-fade" style="--d:260ms;">Applications</h2>

      <div class="edit-fields ef-fade" style="--d:300ms">
        <div class="ef-2">
          <label for="application_deadline">Application deadline</label>
          <input type="datetime-local" id="application_deadline" name="application_deadline" value="<?= old('application_deadline', $prev) ?>">
          <p class="muted-note">Last moment to apply. Leave blank to accept until program end.</p>
        </div>
      </div>
      </div>

      <h2 class="section-label ef-fade" style="--d:320ms;" id="release-section">Benefit release</h2>

      <div class="edit-fields ef-fade" style="--d:340ms">
        <div class="ef-2">
          <label>Benefit release date / period</label>
          <div style="display:flex; gap:16px; margin-bottom:8px; flex-wrap:wrap;">
            <label class="chip" style="margin:0;"><input type="radio" name="release_mode" value="none" <?= ($prev['release_mode'] ?? 'single') === 'none' ? 'checked' : '' ?> onchange="toggleReleaseMode()"><span>No specific date yet</span></label>
            <label class="chip" style="margin:0;"><input type="radio" name="release_mode" value="single" <?= ($prev['release_mode'] ?? 'single') === 'single' ? 'checked' : '' ?> onchange="toggleReleaseMode()"><span>Single date</span></label>
            <label class="chip" style="margin:0;"><input type="radio" name="release_mode" value="range" <?= ($prev['release_mode'] ?? 'single') === 'range' ? 'checked' : '' ?> onchange="toggleReleaseMode()"><span>Date range</span></label>
          </div>
          <div id="release-single" style="<?= in_array(($prev['release_mode'] ?? 'single'), ['range', 'none'], true) ? 'display:none;' : '' ?>">
            <input type="datetime-local" id="release_date" name="release_date" value="<?= old('release_date', $prev) ?>">
          </div>
          <div id="release-range" style="<?= ($prev['release_mode'] ?? 'single') === 'range' ? '' : 'display:none;' ?>">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
              <div>
                <label style="display:block; margin:0 0 7px; font-size:11px; font-weight:600; letter-spacing:0.14em; text-transform:uppercase; color:var(--muted);">From</label>
                <input type="datetime-local" id="release_start" name="release_start" value="<?= old('release_start', $prev) ?>">
              </div>
              <div>
                <label style="display:block; margin:0 0 7px; font-size:11px; font-weight:600; letter-spacing:0.14em; text-transform:uppercase; color:var(--muted);">To</label>
                <input type="datetime-local" id="release_end" name="release_end" value="<?= old('release_end', $prev) ?>">
              </div>
            </div>
          </div>
          <p class="muted-note">When the benefit is released/picked up.</p>
        </div>
        <div class="ef-2">
          <label for="slot_limit">Available slots / beneficiary limit</label>
          <input type="number" id="slot_limit" name="slot_limit" min="1" step="1" placeholder="e.g. 100" value="<?= old('slot_limit', $prev) ?>">
          <p class="muted-note">Leave blank for unlimited slots.</p>
        </div>
      </div>

      <h2 class="section-label ef-fade" style="--d:380ms;" id="eligibility-section">Eligibility conditions</h2>
      <p class="muted-note">Every condition set below must ALL be true for a household member to qualify. Leave blank to skip that condition.</p>

      <div id="eligibility-fields" class="ef-fade" style="--d:420ms;">
      <div class="edit-fields">
        <div>
          <label>Age requirement</label>
          <label class="chip" style="margin:0 0 12px;"><input type="checkbox" id="no_age" name="no_age" value="1" onchange="toggleAgeRestriction()"<?= empty(old('min_age', $prev)) && empty(old('max_age', $prev)) ? ' checked' : '' ?>><span>No age restriction</span></label>
          <div id="age-range" style="display:<?= (!empty(old('min_age', $prev)) || !empty(old('max_age', $prev))) ? 'grid' : 'none' ?>; grid-template-columns:1fr 1fr; gap:10px;">
            <div>
              <label style="display:block; margin:0 0 5px; font-size:11px; font-weight:600; letter-spacing:0.14em; text-transform:uppercase; color:var(--muted);">From</label>
              <input type="number" id="min_age" name="min_age" min="0" placeholder="e.g. 18" value="<?= old('min_age', $prev) ?>">
            </div>
            <div>
              <label style="display:block; margin:0 0 5px; font-size:11px; font-weight:600; letter-spacing:0.14em; text-transform:uppercase; color:var(--muted);">To</label>
              <input type="number" id="max_age" name="max_age" min="0" placeholder="e.g. 60" value="<?= old('max_age', $prev) ?>">
            </div>
          </div>
        </div>
        <div>
          <label for="income_direction">Income requirement</label>
          <div class="income-direction-row">
            <select id="income_direction" name="income_direction" onchange="toggleIncomeAmount(this)">
              <?php $incomeDir = old('income_direction', $prev, 'max'); ?>
              <option value="max" <?= $incomeDir === 'max' ? 'selected' : '' ?>>Monthly income up to</option>
              <option value="min" <?= $incomeDir === 'min' ? 'selected' : '' ?>>Monthly income at least</option>
              <option value="none" <?= $incomeDir === 'none' ? 'selected' : '' ?>>No income condition</option>
            </select>
            <input type="number" id="income_amount" name="income_amount" min="0" step="0.01" placeholder="e.g. 10000" value="<?= old('income_amount', $prev) ?>">
          </div>
        </div>
      </div>

      <p class="muted-note">The income condition compares against the household's monthly income on file. A household with no income recorded will not qualify either way.</p>

      <fieldset class="edit-chips ef-fade" style="--d:460ms;">
        <legend>Must also be</legend>
        <label class="chip"><input type="checkbox" name="requires_pwd" value="1"><span>PWD</span></label>
        <label class="chip"><input type="checkbox" name="requires_solo_parent" value="1"><span>Solo parent</span></label>
        <label class="chip"><input type="checkbox" name="requires_ofw" value="1"><span>OFW</span></label>
        <label class="chip"><input type="checkbox" name="requires_ip" value="1"><span>Indigenous person</span></label>
        <label class="chip"><input type="checkbox" name="requires_osy" value="1"><span>Out-of-school youth</span></label>
        <label class="chip"><input type="checkbox" name="requires_unemployed" value="1"><span>Unemployed</span></label>
        <label class="chip"><input type="checkbox" name="requires_employed" value="1"><span>Employed</span></label>
        <label class="chip"><input type="checkbox" name="requires_student" value="1"><span>Student</span></label>
      </fieldset>
      </div>

      <h2 class="section-label ef-fade" style="--d:500ms;" id="documents-section">Required documents</h2>

      <div id="doc-requirements" class="ef-fade" style="--d:540ms;">
        <?php if (!empty($prev['documents'])): ?>
          <?php foreach ($prev['documents'] as $doc): render_doc_requirement($doc); endforeach; ?>
        <?php else: ?>
          <?php render_doc_requirement(['label' => '', 'files' => '1']); ?>
        <?php endif; ?>
      </div>

      <div class="ef-fade" style="--d:580ms;">
        <button type="button" class="btn-archive" style="margin-top:14px;" onclick="addDocRequirement()">+ Add document requirement</button>
      </div>

      <button type="submit" class="save-btn ef-fade" style="--d:620ms;">
        <span>Create program</span>
        <span class="save-btn-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
        </span>
      </button>
    </form>
  </div>

  <?php if (!empty($programs)): ?>
    <?php foreach ($programs as $prog):
      $docs = $programDocs[(int) $prog['program_id']] ?? [];
      $scheduleNote = render_schedule($prog);
    ?>
      <div class="event-modal-backdrop" id="program-modal-<?= (int) $prog['program_id'] ?>" onclick="if (event.target === this) closeProgramModal(<?= (int) $prog['program_id'] ?>)">
        <div class="event-modal">
          <button type="button" class="event-modal-close" onclick="closeProgramModal(<?= (int) $prog['program_id'] ?>)">&times;</button>
          <div class="program-modal-head">
            <div>
              <?= render_category($prog) ?>
              <h2><?= e($prog['name']) ?></h2>
              <?php if (!empty($prog['provider'])): ?><p class="muted-note" style="margin-top:4px;">Provided by <?= e($prog['provider']) ?></p><?php endif; ?>
            </div>
            <?= render_program_status_badge($prog) ?>
          </div>
          <div class="program-modal-body">
            <?php if (!empty($prog['description'])): ?>
              <p style="margin:0; font-size:16px; line-height:1.6; white-space:pre-line;"><?= e($prog['description']) ?></p>
            <?php endif; ?>

            <div class="program-detail-grid">
              <div class="detail-item">
                <span class="detail-label">Program type</span>
                <span class="detail-value"><?= ($prog['application_mode'] ?? 'application_required') === 'no_application_required' ? 'No Application Required' : 'Application Required' ?><?= !empty($prog['import_existing_list']) ? ' &middot; Beneficiary list import enabled' : '' ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Program schedule</span>
                <span class="detail-value"><?= $scheduleNote ?></span>
              </div>
              <?php if (($prog['application_mode'] ?? 'application_required') === 'application_required'): ?>
              <div class="detail-item">
                <span class="detail-label">Applications close</span>
                <span class="detail-value"><?= render_application_deadline($prog) ?></span>
              </div>
              <?php endif; ?>
              <div class="detail-item">
                <span class="detail-label">Benefit release</span>
                <span class="detail-value"><?= !empty($prog['release_period']) ? $prog['release_period'] : '<span class="muted-note">To be announced</span>' ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Available slots</span>
                <span class="detail-value"><?= render_slots($prog) ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Matched beneficiaries</span>
                <span class="detail-value"><?= (int) $prog['beneficiary_count'] ?> household member(s) matched so far</span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Source / provider</span>
                <span class="detail-value"><?= !empty($prog['provider']) ? e($prog['provider']) : '<span class="muted-note">&mdash;</span>' ?></span>
              </div>
            </div>

            <div class="program-modal-block">
              <h3>Eligibility conditions</h3>
              <?php if (($prog['application_mode'] ?? 'application_required') === 'no_application_required'): ?>
                <p><?= summarize_criteria($prog) ?></p>
                <p class="muted-note" style="margin-top:6px;">Residents who match these conditions are added to the beneficiary list automatically - no application or approval needed.</p>
              <?php else: ?>
                <p><?= summarize_criteria($prog) ?></p>
              <?php endif; ?>
            </div>

            <div class="program-modal-block">
              <h3>Documents residents must upload when applying</h3>
              <?php if (($prog['application_mode'] ?? 'application_required') === 'no_application_required'): ?>
                <p class="muted-note">No document uploads required - residents do not apply for this program.</p>
              <?php elseif (empty($docs)): ?>
                <p class="muted-note">No required document uploads configured - the resident applies with a supporting ID only.</p>
              <?php else: ?>
                <ul class="program-modal-docs">
                  <?php foreach ($docs as $doc): ?>
                    <li><span class="doc-note"><?= e($doc['label']) ?></span> &mdash; <?= max(1, min(10, (int) $doc['files_required'])) ?> file(s)</li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <?php if (!empty($prog['import_existing_list'])): ?>
            <div class="program-modal-block">
              <h3>Import Existing Beneficiary List</h3>
              <p class="muted-note">Add people from an official barangay list straight into this program's beneficiaries - they join the same list as system-detected residents, marked as Imported, and never have to apply. Available for both Application Required and No Application Required programs.</p>
              <p style="margin-top:8px;"><strong><?= (int) ($prog['import_count'] ?? 0) ?></strong> import(s) completed for this program.</p>
              <a href="import-beneficiaries.php?program_id=<?= (int) $prog['program_id'] ?>" class="btn-gold" style="display:inline-block; margin-top:10px; padding:8px 20px; text-decoration:none; border-radius:999px; font-weight:700;">Import Existing Beneficiary List</a>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php include __DIR__ . '/partials/layout-end.php'; ?>

<script>
// Required-document builder: add/remove requirement blocks and preview
// the disabled file inputs residents will later see when applying.
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function toggleIncomeAmount(sel) {
  var amount = document.getElementById('income_amount');
  if (!amount) return;
  amount.disabled = sel.value === 'none';
}
function toggleAgeRestriction() {
  var cb = document.getElementById('no_age');
  var range = document.getElementById('age-range');
  var minA = document.getElementById('min_age');
  var maxA = document.getElementById('max_age');
  if (cb.checked) {
    range.style.display = 'none';
    minA.value = '';
    maxA.value = '';
  } else {
    range.style.display = 'grid';
  }
}
function docBlockHTML(label, files) {
  var n = parseInt(files, 10);
  if (!n || n < 1) n = 1;
  if (n > 10) n = 10;
  var preview = '';
  for (var i = 0; i < n; i++) {
    preview += '<input type="file" disabled>';
  }
  return '<div class="doc-requirement">' +
    '<div class="doc-requirement-head">' +
      '<strong>Document requirement</strong>' +
      '<button type="button" class="btn-archive" onclick="removeDocRequirement(this)">Remove</button>' +
    '</div>' +
    '<div class="member-grid">' +
      '<div><label>Document label</label>' +
        '<input type="text" name="document_label[]" placeholder="e.g. Barangay Clearance" value="' + esc(label) + '"></div>' +
      '<div><label>Number of files required</label>' +
        '<input type="number" name="document_files[]" min="1" max="10" value="' + n + '" oninput="updateDocPreview(this)"></div>' +
    '</div>' +
    '<div class="doc-preview">' +
      '<div class="doc-preview-label">Preview</div>' +
      '<div class="doc-preview-files">' + preview + '</div>' +
    '</div>' +
  '</div>';
}
function addDocRequirement() {
  var container = document.getElementById('doc-requirements');
  container.insertAdjacentHTML('beforeend', docBlockHTML('', '1'));
  var last = container.lastElementChild;
  if (last) {
    var firstInput = last.querySelector('input');
    if (firstInput) firstInput.focus();
  }
}
function removeDocRequirement(btn) {
  var block = btn.closest('.doc-requirement');
  if (block) block.remove();
}
function updateDocPreview(input) {
  var block = input.closest('.doc-requirement');
  if (!block) return;
  var wrap = block.querySelector('.doc-preview-files');
  if (!wrap) return;
  var n = parseInt(input.value, 10);
  if (!n || n < 1) n = 1;
  if (n > 10) n = 10;
  var html = '';
  for (var i = 0; i < n; i++) {
    html += '<input type="file" disabled>';
  }
  wrap.innerHTML = html;
}

// Show/hide the custom-category input when "Other" is picked.
function onCategoryChange(sel) {
  var custom = document.getElementById('category_custom');
  var back = document.getElementById('category-custom-back');
  if (sel.value === 'Other') {
    sel.style.display = 'none';
    custom.style.display = '';
    back.style.display = '';
    custom.focus();
  } else {
    custom.style.display = 'none';
    back.style.display = 'none';
  }
}

// Toggle between the two program types.
//   application_required    -> deadline + document requirements + apply flow
//   no_application_required -> those two sections hide; the eligibility
//                              conditions section STAYS (it is what drives
//                              automatic enrollment), and the import option
//                              below stays visible either way.
// Also re-runs toggleImportListOption() so the Import hint immediately
// rewrites itself for the new type instead of keeping the old wording.
function toggleApplicationMode() {
  var mode = document.querySelector('input[name="application_mode"]:checked').value;
  var isNoApplication = mode === 'no_application_required';
  var eligibilityFields = document.getElementById('eligibility-fields');
  var eligibilitySection = document.getElementById('eligibility-section');
  var documentsSection = document.getElementById('documents-section');
  var applicationsSection = document.getElementById('applications-section');
  var hint = document.getElementById('application-mode-hint');

  if (eligibilityFields) eligibilityFields.style.display = '';
  if (eligibilitySection) eligibilitySection.style.display = '';
  if (documentsSection) documentsSection.style.display = isNoApplication ? 'none' : '';
  if (applicationsSection) applicationsSection.style.display = isNoApplication ? 'none' : '';
  if (hint) {
    hint.textContent = isNoApplication
      ? 'Residents do not apply - everyone who matches the conditions below is added to the beneficiary list automatically.'
      : 'Residents must apply and be reviewed by an admin.';
  }

  // Keep the Import Existing Beneficiary List? hint in step with the type.
  toggleImportListOption();
}

// The import question is independent of the program type; only its hint text
// changes with the type.
function toggleImportListOption() {
  var type = document.querySelector('input[name="application_mode"]:checked').value;
  var importSel = document.querySelector('input[name="import_existing_list"]:checked');
  var hint = document.getElementById('import-list-hint');
  if (!hint) return;
  if (importSel && importSel.value === '0') {
    hint.textContent = type === 'no_application_required'
      ? 'The beneficiary list will be filled automatically - no file import needed.'
      : 'Residents apply through the portal; no official list will be imported.';
  } else {
    hint.textContent = type === 'application_required'
      ? 'Imported beneficiaries are treated as existing list members - they do not have to submit an application, while other residents still apply as usual.'
      : 'Imported beneficiaries join this program\'s list without applying, and are kept distinct from residents the system detects automatically.';
  }
}

// Revert from custom text input back to the dropdown.
function revertCategorySelect() {
  var sel = document.getElementById('category');
  var custom = document.getElementById('category_custom');
  var back = document.getElementById('category-custom-back');
  sel.value = '';
  sel.style.display = '';
  custom.style.display = 'none';
  custom.value = '';
  back.style.display = 'none';
  sel.focus();
}

// Toggle between single release date, date range, and no specific date.
function toggleReleaseMode() {
  var mode = document.querySelector('input[name="release_mode"]:checked').value;
  document.getElementById('release-single').style.display = mode === 'single' ? '' : 'none';
  document.getElementById('release-range').style.display = mode === 'range' ? '' : 'none';
}

// Create form toggle.
function showCreateForm() {
  var form = document.getElementById('create-program-form');
  var btn = document.getElementById('show-create-btn');
  if (form) form.style.display = '';
  if (btn) btn.style.display = 'none';
  if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function hideCreateForm() {
  var form = document.getElementById('create-program-form');
  var btn = document.getElementById('show-create-btn');
  if (form) form.style.display = 'none';
  if (btn) btn.style.display = '';
  if (btn) btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Program detail modals.
function openProgramModal(id) {
  document.getElementById('program-modal-' + id).classList.add('is-open');
  document.body.style.overflow = 'hidden';
}
function closeProgramModal(id) {
  document.getElementById('program-modal-' + id).classList.remove('is-open');
  document.body.style.overflow = '';
}

// Filter tabs - All / Open / Upcoming / Closed (driven by the same state
// program_state() computes server-side and stamps on each row).
var currentProgramFilter = 'all';
document.querySelectorAll('#program-filter-tabs .program-filter-tab').forEach(function (tab) {
  tab.addEventListener('click', function () {
    document.querySelectorAll('#program-filter-tabs .program-filter-tab').forEach(function (t) {
      t.classList.remove('is-active');
    });
    tab.classList.add('is-active');
    currentProgramFilter = tab.getAttribute('data-filter');
    document.querySelectorAll('tr[data-state]').forEach(function (row) {
      var show = currentProgramFilter === 'all' || row.getAttribute('data-state') === currentProgramFilter;
      row.style.display = show ? '' : 'none';
    });
  });
});

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.event-modal-backdrop.is-open').forEach(function (el) {
      el.classList.remove('is-open');
    });
    document.body.style.overflow = '';
  }
});

(function () {
  var dirSel = document.getElementById('income_direction');
  if (dirSel) toggleIncomeAmount(dirSel);
  var catSel = document.getElementById('category');
  if (catSel) onCategoryChange(catSel);

  // Initialize application mode + import option toggles
  toggleApplicationMode();
  toggleImportListOption();

  // If there are validation errors, the form was submitted and failed -
  // show it automatically so the user can see and fix the errors.
  <?php if (!empty($errors)): ?>
  showCreateForm();
  <?php endif; ?>

  // Keep the related date fields in range of each other: start caps the
  // end+deadline, end floors the deadline - so the pickers guide the admin
  // instead of rejecting values on submit.
  var startEl = document.getElementById('start_date');
  var endEl = document.getElementById('end_date');
  var deadlineEl = document.getElementById('application_deadline');
  function syncDates() {
    if (startEl && startEl.value && endEl) endEl.min = startEl.value;
    if (endEl && endEl.value) {
      if (startEl) startEl.max = endEl.value;
      if (deadlineEl) deadlineEl.max = endEl.value;
    }
    if (startEl && startEl.value && deadlineEl) deadlineEl.min = startEl.value;
  }
  [startEl, endEl, deadlineEl].forEach(function (el) {
    if (el) el.addEventListener('change', syncDates);
  });
  syncDates();
})();
</script>
</body>
</html>