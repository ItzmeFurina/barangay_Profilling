<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/beneficiary_import.php';

require_admin();
no_cache();
$activePage = 'import-beneficiaries';

function form_is(string $name): bool {
    $val = $_POST['form'] ?? '';
    return is_array($val) ? in_array($name, $val, true) : $val === $name;
}

$pdo = get_db_connection();

$step = (int) ($_SESSION['import_wizard']['step'] ?? 1);
$errors = [];
$prev = $_SESSION['import_wizard']['form_data'] ?? [];

if (!isset($_SESSION['import_wizard'])) {
    $_SESSION['import_wizard'] = ['step' => 1, 'form_data' => []];
}

$programId = (int) ($_GET['program_id'] ?? 0);
// Entering with ?program_id= (the program modal's "Import Existing
// Beneficiary List" button) starts that program's import straight at the
// upload step. Restart whenever the URL targets a DIFFERENT program than
// the wizard session is already mid-way through - refreshing inside the
// wizard for the same program must never throw away progress.
if ($programId > 0 && (int) ($prev['program_id'] ?? 0) !== $programId) {
    $autoCheck = $pdo->prepare(
        "SELECT program_id, name FROM programs
         WHERE program_id = ? AND import_existing_list = TRUE"
    );
    $autoCheck->execute([$programId]);
    $autoProg = $autoCheck->fetch();
    if ($autoProg) {
        $_SESSION['import_wizard'] = [
            'step' => 2,
            'form_data' => ['program_id' => $programId, 'program_name' => $autoProg['name']],
            'rows' => null, 'headers' => null, 'column_map' => null,
            'file_name' => null, 'file_type' => null,
        ];
        $step = 2;
        $prev = $_SESSION['import_wizard']['form_data'];
    }
}

// ── Fetch eligible programs ────────────────────────────────────────────
// The import option is independent of the program type: any active program
// whose "Import Existing Beneficiary List?" option was set to Yes appears
// here, whether it is application_required or no_application_required.
$programs = $pdo->query(
    "SELECT program_id, name FROM programs
     WHERE import_existing_list = TRUE
       AND status = 'active'
     ORDER BY name ASC"
)->fetchAll();

// ── Fetch existing beneficiary count for update detection ───────────────
$existingCount = 0;
if (!empty($prev['program_id'])) {
    $ecStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM beneficiaries
         WHERE program_id = ? AND claim_status = 'existing'
           AND source_type = 'existing_beneficiary_list'"
    );
    $ecStmt->execute([(int) $prev['program_id']]);
    $existingCount = (int) $ecStmt->fetchColumn();
}

// ── Create inline program ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('create_program_inline')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $name = normalize_label($_POST['program_name'] ?? '');
        $description = trim($_POST['program_description'] ?? '');
        $category = trim($_POST['program_category'] ?? '');
        $start = $_POST['program_start'] ?? '';
        $end = $_POST['program_end'] ?? '';

        if ($name === '') { $errors[] = 'Program name is required.'; }
        if ($category === '') { $errors[] = 'Category is required.'; }
        if ($start === '' || $end === '') { $errors[] = 'Both start and end dates are required.'; }

        $startDb = null;
        $endDb = null;
        if ($start !== '' && $end !== '') {
            $startDb = normalize_datetime_local($start);
            $endDb = normalize_datetime_local($end);
            if ($startDb === null || $endDb === null) {
                $errors[] = 'Enter valid start and end date-times.';
            } else {
                $startDt = new DateTime($startDb, new DateTimeZone(APP_TIMEZONE));
                $endDt = new DateTime($endDb, new DateTimeZone(APP_TIMEZONE));
                if ($endDt <= $startDt) {
                    $errors[] = 'End must be after start.';
                }
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO programs (name, description, status, start_date, end_date,
                                           category, application_mode, import_existing_list, created_by)
                     VALUES (?, ?, 'active', ?, ?, ?, 'no_application_required', TRUE, ?)"
                );
                $stmt->execute([
                    $name,
                    $description !== '' ? $description : null,
                    $startDb,
                    $endDb,
                    $category,
                    $_SESSION['staff_user_id'],
                ]);
                $newPid = (int) $pdo->lastInsertId();
                log_activity($pdo, $_SESSION['staff_user_id'], "Created program: {$name} (no application required, beneficiary list import)", 'create');

                $_SESSION['import_wizard']['form_data']['program_id'] = $newPid;
                $prev['program_id'] = $newPid;
                flash('success', 'Program created. Now upload your beneficiary list.');
                redirect('import-beneficiaries.php?program_id=' . $newPid);
            } catch (Exception $e) {
                error_log('Inline program creation failed: ' . $e->getMessage());
                $errors[] = 'Failed to create program. Please try again.';
            }
        }
    }
}

// ── STEP 1: Select program ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('select_program')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $pid = (int) ($_POST['program_id'] ?? 0);
        if ($pid <= 0) {
            $errors[] = 'Please select a program.';
        } else {
            $check = $pdo->prepare("SELECT program_id, name FROM programs WHERE program_id = ? AND import_existing_list = TRUE");
            $check->execute([$pid]);
            $prog = $check->fetch();
            if (!$prog) {
                $errors[] = 'Selected program not found, or it does not have beneficiary-list import enabled.';
            } else {
                $_SESSION['import_wizard'] = [
                    'step' => 2,
                    'form_data' => ['program_id' => $pid, 'program_name' => $prog['name']],
                    'rows' => null,
                    'headers' => null,
                    'column_map' => null,
                    'file_name' => null,
                    'file_type' => null,
                ];
                redirect('import-beneficiaries.php?program_id=' . $pid);
            }
        }
    }
}

// ── STEP 2: Upload file ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('upload_file')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $pid = (int) ($_SESSION['import_wizard']['form_data']['program_id'] ?? 0);
        if ($pid <= 0) {
            $errors[] = 'No program selected. Please go back to Step 1.';
            $step = 1;
            $_SESSION['import_wizard']['step'] = 1;
        } else {
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $code = $_FILES['import_file']['error'] ?? -1;
                if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                    $errors[] = 'File is too large. Maximum size is 10 MB.';
                } elseif ($code === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Please select a file to upload.';
                } else {
                    $errors[] = 'File upload failed. Please try again.';
                }
            } else {
                $file = $_FILES['import_file'];
                $originalName = $file['name'];
                $tmpPath = $file['tmp_name'];
                $fileSize = $file['size'];

                if ($fileSize > 10 * 1024 * 1024) {
                    $errors[] = 'File is too large. Maximum size is 10 MB.';
                } else {
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $allowedExts = ['csv', 'xlsx'];
                    if (!in_array($ext, $allowedExts, true)) {
                        $errors[] = 'Invalid file type. Only .csv and .xlsx files are allowed.';
                    } else {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $tmpPath);
                        finfo_close($finfo);
                        $allowedMimes = [
                            'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
                            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
                        ];
                        if (!isset($allowedMimes[$ext]) || !in_array($mime, $allowedMimes[$ext], true)) {
                            $errors[] = 'File content does not match its extension. Please check the file.';
                        } else {
                            try {
                                $rows = import_parse_file($tmpPath, $ext);
                                $headerInfo = import_detect_headers($rows);
                                $dataStart = $headerInfo['data_start_index'];
                                $dataRows = array_slice($rows, $dataStart);
                                $totalData = count($dataRows);

                                if ($totalData === 0) {
                                    $errors[] = 'The file contains headers but no data rows.';
                                } else {
                                    $_SESSION['import_wizard'] = [
                                        'step' => 3,
                                        'form_data' => ['program_id' => $pid, 'program_name' => $_SESSION['import_wizard']['form_data']['program_name'] ?? ''],
                                        'rows' => $rows,
                                        'headers' => $headerInfo,
                                        'column_map' => $headerInfo['column_map'],
                                        'file_name' => $originalName,
                                        'file_type' => $ext,
                                    ];
                                    redirect('import-beneficiaries.php?program_id=' . $pid);
                                }
                            } catch (RuntimeException $e) {
                                error_log('Import file read failed: ' . $e->getMessage());
                                $errors[] = 'Could not read file. Please check that it is a valid CSV or XLSX export and try again.';
                            }
                        }
                    }
                }
            }
        }
    }
}

// ── STEP 3: Map columns ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('map_columns')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $pid = (int) ($_SESSION['import_wizard']['form_data']['program_id'] ?? 0);
        if ($pid <= 0) {
            $errors[] = 'No program selected. Please go back to Step 1.';
            $step = 1;
            $_SESSION['import_wizard']['step'] = 1;
        } else {
            $rawColMap = $_POST['col_map'] ?? [];
            $columnMap = [];
            foreach ($rawColMap as $colIdx => $fieldName) {
                $colIdx = (int) $colIdx;
                $fieldName = trim((string) $fieldName);
                if ($fieldName !== '') {
                    $columnMap[$colIdx] = $fieldName;
                }
            }
            if (empty($columnMap)) {
                $errors[] = 'Please map at least one column.';
            } else {
                $requiredFields = ['last_name', 'first_name'];
                $mappedFields = array_values($columnMap);
                $missingRequired = array_diff($requiredFields, $mappedFields);
                if (!empty($missingRequired)) {
                    $errors[] = 'You must map at least Last Name and First Name columns.';
                } else {
                    $_SESSION['import_wizard']['column_map'] = $columnMap;
                    $_SESSION['import_wizard']['step'] = 4;
                    redirect('import-beneficiaries.php?program_id=' . $pid);
                }
            }
        }
    }
}

// ── STEP 4: Validate & preview ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('run_match')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $pid = (int) ($_SESSION['import_wizard']['form_data']['program_id'] ?? 0);
        if ($pid <= 0) {
            $errors[] = 'No program selected.';
            $step = 1;
            $_SESSION['import_wizard']['step'] = 1;
        } else {
            $rawRows = $_SESSION['import_wizard']['rows'] ?? [];
            $colMap = $_SESSION['import_wizard']['column_map'] ?? [];
            $headerInfo = $_SESSION['import_wizard']['headers'] ?? [];
            $dataStart = $headerInfo['data_start_index'] ?? 0;
            $dataRows = array_slice($rawRows, $dataStart);

            if (empty($colMap) || empty($dataRows)) {
                $errors[] = 'No data to validate. Please re-upload.';
                $_SESSION['import_wizard']['step'] = 2;
                redirect('import-beneficiaries.php?program_id=' . $pid);
            }

            if (empty($errors)) {
                // Normalize all rows
                $normalized = [];
                foreach ($dataRows as $idx => $rawRow) {
                    $norm = import_normalize_row($rawRow, $colMap);
                    $norm['_row_number'] = $dataStart + $idx + 2; // 1-indexed, +1 for 0-based, +1 for header
                    $normalized[] = $norm;
                }

                // Mark in-file duplicates
                import_check_file_duplicates($normalized);

                // Run exact matching
                foreach ($normalized as &$row) {
                    if (!empty($row['_duplicate_in_file'])) {
                        $row['_match_status'] = 'duplicate_in_file';
                        continue;
                    }
                    $match = import_exact_match($pdo, $row);
                    if ($match !== null) {
                        $row['_matched_member_id'] = (int) $match['member_id'];
                        $row['_match_status'] = 'matched';
                        $row['_matched_name'] = trim($match['first_name'] . ' ' . $match['last_name']);
                    } else {
                        // Fuzzy match
                        $fuzzies = import_fuzzy_match($pdo, $row);
                        if (!empty($fuzzies)) {
                            $row['_match_status'] = 'needs_review';
                            $row['_fuzzy_suggestions'] = $fuzzies;
                        } else {
                            $row['_match_status'] = 'not_found';
                        }
                    }
                }
                unset($row);

                // Check existing beneficiaries
                import_check_existing_beneficiaries($pdo, $pid, $normalized);

                // Final status assignment
                $stats = [
                    'total' => count($normalized),
                    'valid' => 0,
                    'matched' => 0,
                    'needs_review' => 0,
                    'not_found' => 0,
                    'duplicate_in_file' => 0,
                    'already_beneficiary' => 0,
                    'errors' => 0,
                ];
                foreach ($normalized as $row) {
                    $status = $row['_match_status'] ?? 'error';
                    if (isset($stats[$status])) {
                        $stats[$status]++;
                    } else {
                        $stats['errors']++;
                    }
                    if (!in_array($status, ['not_found', 'duplicate_in_file', 'error'])) {
                        $stats['valid']++;
                    }
                }

                // Check if this is an updated list (program already has imported beneficiaries)
                $diff = null;
                if ($existingCount > 0) {
                    // Keep-set for the removal diff: EVERY member the file
                    // still points at, not just rows confirmed "matched".
                    // Fuzzy (needs_review) rows keep their top candidate so
                    // the person that row almost certainly represents is
                    // never retracted. Rows that resolved to nobody at all
                    // (not_found/error) make the diff ambiguous - one of
                    // them may be a current beneficiary whose name changed
                    // beyond the matcher's reach - so import_generate_diff()
                    // skips removals entirely when they are present.
                    $keepMemberIds = [];
                    $unmatchedRows = 0;
                    foreach ($normalized as $r) {
                        $st = $r['_match_status'] ?? 'error';
                        if ($st === 'matched' && !empty($r['_matched_member_id'])) {
                            $keepMemberIds[] = (int) $r['_matched_member_id'];
                        } elseif ($st === 'needs_review' && !empty($r['_fuzzy_suggestions'][0]['member_id'])) {
                            $keepMemberIds[] = (int) $r['_fuzzy_suggestions'][0]['member_id'];
                        } elseif ($st === 'not_found' || $st === 'error') {
                            $unmatchedRows++;
                        }
                    }
                    $diff = import_generate_diff($pdo, $pid, $keepMemberIds, $unmatchedRows);
                }

                $_SESSION['import_wizard']['normalized'] = $normalized;
                $_SESSION['import_wizard']['stats'] = $stats;
                $_SESSION['import_wizard']['diff'] = $diff;
                $_SESSION['import_wizard']['step'] = 5;
                redirect('import-beneficiaries.php?program_id=' . $pid);
            }
        }
    }
}

// ── STEP 5 → STEP 6: Confirm import ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('confirm_import')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $pid = (int) ($_SESSION['import_wizard']['form_data']['program_id'] ?? 0);
        $normalized = $_SESSION['import_wizard']['normalized'] ?? [];
        $fileName = $_SESSION['import_wizard']['file_name'] ?? 'unknown';
        $fileType = $_SESSION['import_wizard']['file_type'] ?? 'csv';
        $totalRows = $_SESSION['import_wizard']['stats']['total'] ?? 0;
        $diff = $_SESSION['import_wizard']['diff'] ?? null;

        if ($pid <= 0 || empty($normalized)) {
            $errors[] = 'Nothing to import. Please start over.';
            unset($_SESSION['import_wizard']);
            redirect('import-beneficiaries.php');
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Create import record
                $importStmt = $pdo->prepare(
                    "INSERT INTO beneficiary_imports (program_id, file_name, file_type, total_rows, status, created_at)
                     VALUES (?, ?, ?, ?, 'pending', NOW())"
                );
                $importStmt->execute([$pid, $fileName, $fileType, $totalRows]);
                $importId = (int) $pdo->lastInsertId();

                // Execute import
                $stats = import_execute($pdo, $importId, $pid, $normalized, $_SESSION['staff_user_id']);

                // Handle removals for updated lists
                $removedCount = 0;
                if ($diff !== null && !empty($diff['removed'])) {
                    $removedCount = import_mark_removed($pdo, $pid, $diff['removed'], $importId, $_SESSION['staff_user_id']);
                }

                log_activity(
                    $pdo,
                    $_SESSION['staff_user_id'],
                    "Imported beneficiary list for program #{$pid}: {$stats['imported']} imported, {$stats['skipped']} skipped, {$stats['restored']} restored, {$removedCount} removed",
                    'create'
                );

                $pdo->commit();

                // Build summary message
                $msg = "Import completed! {$stats['imported']} beneficiary(ies) added.";
                if ($stats['restored'] > 0) {
                    $msg .= " {$stats['restored']} previously removed beneficiary(ies) restored to the list.";
                }
                if ($stats['skipped'] > 0) {
                    $msg .= " {$stats['skipped']} skipped.";
                }
                if ($removedCount > 0) {
                    $msg .= " {$removedCount} previously listed beneficiary(ies) marked as removed.";
                }
                if (!empty($diff['removals_skipped'])) {
                    $msg .= " {$diff['unmatched_rows']} row(s) could not be matched to a resident, so no beneficiaries were marked as removed this run - review those rows and re-import.";
                }

                unset($_SESSION['import_wizard']);
                flash('success', $msg);
                redirect('beneficiaries.php?program_id=' . $pid);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Import execution failed: " . $e->getMessage());
                // Never surface the raw exception: PDO/parse messages leak
                // table and column names to the browser.
                $errors[] = 'Import failed - no changes were saved. Please try again or contact support if the problem persists.';
            }
        }
    }
}

// ── Reset wizard ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('reset_wizard')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session expired.';
    } else {
        unset($_SESSION['import_wizard']);
        redirect('import-beneficiaries.php');
    }
}

// ── Go back to previous step ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_is('go_back')) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session expired.';
    } else {
        $backTo = (int) ($_POST['go_to_step'] ?? 1);
        if ($backTo >= 1 && $backTo <= 5) {
            $_SESSION['import_wizard']['step'] = $backTo;
            $step = $backTo;
        }
    }
}

// ── Sync step from session ─────────────────────────────────────────────
$step = (int) ($_SESSION['import_wizard']['step'] ?? 1);

// Helper for normalizing datetime-local input (same as programs.php)
function normalize_datetime_local(string $value): ?string {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone(APP_TIMEZONE));
    if ($dt === false) { return null; }
    $check = $dt->format('Y-m-d\TH:i');
    if ($check !== $value) { return null; }
    return $dt->format('Y-m-d H:i:s');
}

// Column alias list for dropdown
$aliasOptions = import_column_aliases();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Import Beneficiaries - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
/* ── Wizard step indicator ──────────────────────────────────────── */
.wizard-steps {
  display: flex;
  gap: 0;
  margin-bottom: 32px;
  background: #fff;
  border-radius: 12px;
  border: 1px solid var(--border, #D6E1EE);
  overflow: hidden;
}
.wizard-step {
  flex: 1;
  text-align: center;
  padding: 16px 10px 14px;
  font-size: 13px;
  font-weight: 600;
  color: var(--muted, #3C5470);
  background: #F7F9FC;
  border-right: 1px solid var(--border, #D6E1EE);
  position: relative;
  transition: background 0.25s, color 0.25s;
}
.wizard-step:last-child { border-right: none; }
.wizard-step .step-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--border, #D6E1EE);
  color: var(--muted, #3C5470);
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 6px;
  transition: background 0.25s, color 0.25s;
}
.wizard-step.is-current {
  background: #EEF3FA;
  color: var(--navy, #0B2E5C);
}
.wizard-step.is-current .step-num {
  background: var(--navy, #0B2E5C);
  color: #fff;
}
.wizard-step.is-done {
  color: var(--navy, #0B2E5C);
}
.wizard-step.is-done .step-num {
  background: var(--green, #2E7D32);
  color: #fff;
}
/* ── Mapping table ──────────────────────────────────────────────── */
.map-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 12px;
}
.map-table th,
.map-table td {
  padding: 10px 14px;
  text-align: left;
  border-bottom: 1px solid var(--border, #D6E1EE);
  font-size: 14px;
}
.map-table th {
  background: #F7F9FC;
  font-weight: 600;
  color: var(--navy, #0B2E5C);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.map-table select {
  width: 100%;
  max-width: 260px;
  padding: 8px 12px;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: #fff;
  color: var(--ink, #16233A);
}
/* ── Stats grid ─────────────────────────────────────────────────── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin: 18px 0;
}
.stat-box {
  background: #F7F9FC;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 10px;
  padding: 16px;
  text-align: center;
}
.stat-box .stat-val {
  font-size: 28px;
  font-weight: 700;
  color: var(--navy, #0B2E5C);
  line-height: 1.1;
}
.stat-box .stat-lbl {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted, #3C5470);
  margin-top: 4px;
}
.stat-box.stat-good .stat-val { color: var(--green, #2E7D32); }
.stat-box.stat-warn .stat-val { color: var(--amber, #F9A825); }
.stat-box.stat-bad .stat-val { color: var(--red, #C62828); }
.stat-box.stat-info .stat-val { color: var(--blue, #1B5FA8); }
/* ── Preview table ──────────────────────────────────────────────── */
.preview-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 12px;
  font-size: 13px;
}
.preview-table th,
.preview-table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--border, #D6E1EE);
  text-align: left;
  white-space: nowrap;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.preview-table th {
  background: #F7F9FC;
  font-weight: 600;
  color: var(--navy, #0B2E5C);
  font-size: 11.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  position: sticky;
  top: 0;
}
.preview-table td.muted-cell { color: var(--muted, #3C5470); font-style: italic; }
/* ── Import result table ────────────────────────────────────────── */
.result-table-wrap {
  max-height: 500px;
  overflow: auto;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 10px;
  margin-top: 12px;
}
.result-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.result-table th,
.result-table td {
  padding: 9px 12px;
  border-bottom: 1px solid var(--border, #D6E1EE);
  text-align: left;
}
.result-table th {
  background: #F7F9FC;
  font-weight: 600;
  color: var(--navy, #0B2E5C);
  font-size: 11.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  position: sticky;
  top: 0;
  z-index: 1;
}
.result-table tr:nth-child(even) { background: #FAFCFE; }
.result-table tr:hover { background: #EEF3FA; }
/* ── Badges ─────────────────────────────────────────────────────── */
.badge-matched {
  display: inline-block;
  background: #E8F5E9;
  color: #1B5E20;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
}
.badge-needs-review {
  display: inline-block;
  background: #FFF8E1;
  color: #F57F17;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
}
.badge-not-found {
  display: inline-block;
  background: #FFEBEE;
  color: #C62828;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
}
.badge-duplicate {
  display: inline-block;
  background: #F3E5F5;
  color: #6A1B9A;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
}
.badge-already {
  display: inline-block;
  background: #E3F2FD;
  color: #1565C0;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
}
.badge-error {
  display: inline-block;
  background: #FFEBEE;
  color: #B71C1C;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
}
/* ── Diff section ───────────────────────────────────────────────── */
.diff-section {
  background: #FFF8E1;
  border: 1px solid #FFE082;
  border-radius: 10px;
  padding: 18px 22px;
  margin: 18px 0;
}
.diff-section h3 {
  margin: 0 0 10px;
  font-size: 16px;
  color: var(--navy, #0B2E5C);
}
.diff-list {
  margin: 0;
  padding-left: 20px;
  font-size: 14px;
  color: var(--ink, #16233A);
}
.diff-list li { margin-bottom: 4px; }
/* ── Fuzzy suggestions ──────────────────────────────────────────── */
.fuzzy-suggest {
  font-size: 12px;
  color: var(--muted, #3C5470);
  margin-top: 4px;
}
.fuzzy-suggest strong { color: var(--ink, #16233A); }
/* ── Inline form row ────────────────────────────────────────────── */
.form-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 18px;
}
.form-row > div label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--muted, #3C5470);
  margin-bottom: 5px;
}
.form-row > div input,
.form-row > div select,
.form-row > div textarea {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: #fff;
  color: var(--ink, #16233A);
  box-sizing: border-box;
}
.form-row > div input:focus,
.form-row > div select:focus,
.form-row > div textarea:focus {
  outline: none;
  border-color: var(--navy, #0B2E5C);
  box-shadow: 0 0 0 3px rgba(11, 46, 92, 0.1);
}
/* ── Upload zone ────────────────────────────────────────────────── */
.upload-zone {
  border: 2px dashed var(--border, #D6E1EE);
  border-radius: 12px;
  padding: 40px 20px;
  text-align: center;
  background: #F7F9FC;
  transition: border-color 0.2s, background 0.2s;
  cursor: pointer;
}
.upload-zone:hover,
.upload-zone.drag-over {
  border-color: var(--navy, #0B2E5C);
  background: #EEF3FA;
}
.upload-zone .upload-icon {
  font-size: 36px;
  margin-bottom: 8px;
  display: block;
  color: var(--muted, #3C5470);
}
.upload-zone .upload-text {
  font-size: 15px;
  font-weight: 600;
  color: var(--ink, #16233A);
}
.upload-zone .upload-hint {
  font-size: 13px;
  color: var(--muted, #3C5470);
  margin-top: 6px;
}
.upload-zone input[type="file"] {
  margin-top: 14px;
}
/* ── Wizard action bar ──────────────────────────────────────────── */
.wizard-actions {
  display: flex;
  gap: 12px;
  justify-content: space-between;
  align-items: center;
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid var(--border, #D6E1EE);
}
.wizard-actions-left { display: flex; gap: 10px; }
.wizard-actions-right { display: flex; gap: 10px; }
/* ── Section heading within wizard ──────────────────────────────── */
.wizard-section-label {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--muted, #3C5470);
  margin-bottom: 8px;
}
.wizard-hint {
  font-size: 13.5px;
  color: var(--muted, #3C5470);
  margin-bottom: 14px;
  line-height: 1.6;
}
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <a href="programs.php" style="display:inline-flex; align-items:center; gap:6px; font-size:14px; font-weight:600; color:var(--blue, #1B5FA8); text-decoration:none; margin-bottom:10px;">
    <span style="font-size:18px;">&larr;</span>
    <span>Back to programs</span>
  </a>

  <h1>Import Beneficiaries</h1>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <!-- Step indicator -->
  <div class="wizard-steps">
    <div class="wizard-step <?= $step === 1 ? 'is-current' : ($step > 1 ? 'is-done' : '') ?>">
      <span class="step-num"><?= $step > 1 ? '&#10003;' : '1' ?></span>
      <div>Select Program</div>
    </div>
    <div class="wizard-step <?= $step === 2 ? 'is-current' : ($step > 2 ? 'is-done' : '') ?>">
      <span class="step-num"><?= $step > 2 ? '&#10003;' : '2' ?></span>
      <div>Upload File</div>
    </div>
    <div class="wizard-step <?= $step === 3 ? 'is-current' : ($step > 3 ? 'is-done' : '') ?>">
      <span class="step-num"><?= $step > 3 ? '&#10003;' : '3' ?></span>
      <div>Map Columns</div>
    </div>
    <div class="wizard-step <?= $step === 4 ? 'is-current' : ($step > 4 ? 'is-done' : '') ?>">
      <span class="step-num"><?= $step > 4 ? '&#10003;' : '4' ?></span>
      <div>Validate &amp; Preview</div>
    </div>
    <div class="wizard-step <?= $step === 5 ? 'is-current' : '' ?>">
      <span class="step-num">5</span>
      <div>Review &amp; Confirm</div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════ -->
  <!-- STEP 1: Select Program                                       -->
  <!-- ══════════════════════════════════════════════════════════════ -->
  <?php if ($step === 1): ?>
  <div class="admin-section">
    <h2>Select a Program</h2>
    <p class="wizard-hint">Choose a program that has beneficiary-list import enabled, or create a new one below. Imported members join that program's beneficiary list without applying, and are marked as Imported (distinct from residents the system detects automatically).</p>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="select_program">

      <div class="form-row">
        <div style="grid-column: 1 / -1; max-width: 500px;">
          <label for="program_id">Program</label>
          <select id="program_id" name="program_id" required>
            <option value="0">-- Select a program --</option>
            <?php foreach ($programs as $p): ?>
              <option value="<?= (int) $p['program_id'] ?>"<?= ((int) ($prev['program_id'] ?? 0) === (int) $p['program_id']) ? ' selected' : '' ?>>
                <?= e($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button type="submit" class="btn-primary" style="width: auto; padding: 12px 28px;">
        Continue
        <span style="margin-left: 6px;">&#8594;</span>
      </button>
    </form>

    <hr style="margin: 28px 0; border: none; border-top: 1px solid var(--border, #D6E1EE);">

    <h3 style="font-size: 16px; color: var(--navy, #0B2E5C); margin-bottom: 12px;">Or create a new program</h3>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="create_program_inline">

      <div class="form-row">
        <div>
          <label for="program_name">Program name <em>*</em></label>
          <input type="text" id="program_name" name="program_name" required placeholder="e.g. 4Ps Beneficiary List 2026" value="<?= old('program_name', $prev) ?>">
        </div>
        <div>
          <label for="program_category">Category <em>*</em></label>
          <select id="program_category" name="program_category" required>
            <option value="">-- Select --</option>
            <option value="Financial Assistance">Financial Assistance</option>
            <option value="Food Assistance">Food Assistance</option>
            <option value="Medical/Health Assistance">Medical/Health Assistance</option>
            <option value="Educational Assistance">Educational Assistance</option>
            <option value="Livelihood Assistance">Livelihood Assistance</option>
            <option value="Emergency/Disaster Assistance">Emergency/Disaster Assistance</option>
            <option value="Senior Citizen Assistance">Senior Citizen Assistance</option>
            <option value="PWD Assistance">PWD Assistance</option>
            <option value="Women and Children Assistance">Women and Children Assistance</option>
            <option value="Housing/Shelter Assistance">Housing/Shelter Assistance</option>
            <option value="Employment Assistance">Employment Assistance</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div>
          <label for="program_description">Description</label>
          <textarea id="program_description" name="program_description" rows="2" placeholder="Brief description of the program"></textarea>
        </div>
      </div>
      <div class="form-row">
        <div>
          <label for="program_start">Start date &amp; time <em>*</em></label>
          <input type="datetime-local" id="program_start" name="program_start" required>
        </div>
        <div>
          <label for="program_end">End date &amp; time <em>*</em></label>
          <input type="datetime-local" id="program_end" name="program_end" required>
        </div>
      </div>

      <button type="submit" class="btn-primary" style="width: auto; padding: 12px 28px;">
        Create Program
        <span style="margin-left: 6px;">&#8594;</span>
      </button>
    </form>
  </div>

  <!-- ══════════════════════════════════════════════════════════════ -->
  <!-- STEP 2: Upload File                                          -->
  <!-- ══════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 2): ?>
  <div class="admin-section">
    <h2>Upload Beneficiary List</h2>
    <?php if (!empty($prev['program_name'])): ?>
    <p style="margin: 0 0 8px; font-size: 15px; font-weight: 600; color: var(--navy, #0B2E5C);">Program: <?= e($prev['program_name']) ?></p>
    <?php endif; ?>
    <p class="wizard-hint">Upload a <strong>.csv</strong> or <strong>.xlsx</strong> file containing the beneficiary list. Maximum file size is 10 MB.</p>

    <form method="POST" enctype="multipart/form-data" id="upload-form">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="upload_file">
      <input type="hidden" name="go_to_step" value="">

      <div class="upload-zone" id="upload-zone" onclick="document.getElementById('import_file').click();">
        <span class="upload-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="48" height="48"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/></svg>
        </span>
        <div class="upload-text">Click to choose a file</div>
        <div class="upload-hint">or drag and drop here &middot; .csv or .xlsx, max 10 MB</div>
        <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx" required onchange="onFileSelected(this)">
      </div>
      <div id="file-name-display" style="margin-top: 10px; font-size: 14px; font-weight: 600; color: var(--navy, #0B2E5C); display: none;"></div>

      <div class="wizard-actions">
        <div class="wizard-actions-left">
          <button type="submit" class="btn-archive" style="width: auto; padding: 10px 20px;" onclick="this.form.elements['form'].value='go_back'; this.form.elements['go_to_step'].value='1';">
            <span style="margin-right: 6px;">&#8592;</span> Back
          </button>
        </div>
        <div class="wizard-actions-right">
          <button type="submit" class="btn-primary" style="width: auto; padding: 12px 28px;">
            Upload &amp; Parse
            <span style="margin-left: 6px;">&#8594;</span>
          </button>
        </div>
      </div>
    </form>
  </div>

  <script>
  function onFileSelected(input) {
    var display = document.getElementById('file-name-display');
    if (input.files && input.files.length > 0) {
      display.textContent = input.files[0].name;
      display.style.display = '';
    } else {
      display.style.display = 'none';
    }
  }
  (function() {
    var zone = document.getElementById('upload-zone');
    if (!zone) return;
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function(e) {
      e.preventDefault();
      zone.classList.remove('drag-over');
      var input = document.getElementById('import_file');
      if (e.dataTransfer.files.length > 0) {
        input.files = e.dataTransfer.files;
        onFileSelected(input);
      }
    });
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════ -->
  <!-- STEP 3: Map Columns                                           -->
  <!-- ══════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 3): ?>
  <?php
    $headerInfo = $_SESSION['import_wizard']['headers'] ?? [];
    $allRows = $_SESSION['import_wizard']['rows'] ?? [];
    $currentMap = $_SESSION['import_wizard']['column_map'] ?? [];
    $headerLabels = [];
    $detectedMap = $headerInfo['column_map'] ?? [];
    // Build header labels from the header row
    $headerRowIndex = $headerInfo['header_row_index'] ?? null;
    if ($headerRowIndex !== null && isset($allRows[$headerRowIndex])) {
        $headerRow = $allRows[$headerRowIndex];
        foreach ($detectedMap as $colIdx => $field) {
            $headerLabels[$colIdx] = trim((string) ($headerRow[$colIdx] ?? "Column " . ($colIdx + 1)));
        }
        // Also add unmapped columns
        foreach ($headerRow as $colIdx => $cell) {
            $cellStr = trim((string) $cell);
            if ($cellStr !== '' && !isset($headerLabels[$colIdx])) {
                $headerLabels[$colIdx] = $cellStr;
            }
        }
    }
    $dataStart = $headerInfo['data_start_index'] ?? 0;
    $previewRows = array_slice($allRows, $dataStart, 5);
  ?>
  <div class="admin-section">
    <h2>Map Columns</h2>
    <p class="wizard-hint">Map each column in your file to the corresponding beneficiary field. <strong>Last Name</strong> and <strong>First Name</strong> are required. Columns not mapped will be ignored.</p>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="map_columns">

      <table class="map-table">
        <thead>
          <tr>
            <th style="width: 35%;">Column in File</th>
            <th style="width: 35%;">Maps To</th>
            <th>Preview (first row)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($headerLabels as $colIdx => $label): ?>
            <?php
              $previewVal = '';
              if (!empty($previewRows)) {
                  $previewVal = $previewRows[0][$colIdx] ?? '';
              }
              $currentMapping = $currentMap[$colIdx] ?? '';
            ?>
            <tr>
              <td>
                <strong><?= e($label) ?></strong>
                <span class="muted-note" style="display: block; font-size: 11px;">Column <?= $colIdx + 1 ?></span>
              </td>
              <td>
                <select name="col_map[<?= (int) $colIdx ?>]">
                  <option value="">-- Skip this column --</option>
                  <?php foreach ($aliasOptions as $fieldKey => $aliases): ?>
                    <option value="<?= e($fieldKey) ?>"<?= $currentMapping === $fieldKey ? ' selected' : '' ?>>
                      <?= e(ucfirst(str_replace('_', ' ', $fieldKey))) ?>
                      <?php if ($fieldKey === 'last_name' || $fieldKey === 'first_name'): ?> *<?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="muted-cell"><?= e(mb_substr((string) $previewVal, 0, 50)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (empty($headerLabels)): ?>
        <p class="muted-note" style="margin-top: 14px;">No header columns detected. All columns are available for manual mapping.</p>
        <?php
          // Show all columns from first data row for manual mapping
          $firstRow = $allRows[0] ?? [];
          foreach ($firstRow as $colIdx => $cell):
            $cellStr = trim((string) $cell);
            if ($cellStr === '') continue;
        ?>
        <div class="form-row" style="margin-bottom: 10px;">
          <div>
            <label>Column <?= $colIdx + 1 ?>: <?= e(mb_substr($cellStr, 0, 40)) ?></label>
            <select name="col_map[<?= (int) $colIdx ?>]" style="max-width: 300px;">
              <option value="">-- Skip --</option>
              <?php foreach ($aliasOptions as $fieldKey => $aliases): ?>
                <option value="<?= e($fieldKey) ?>"<?= ($currentMap[$colIdx] ?? '') === $fieldKey ? ' selected' : '' ?>>
                  <?= e(ucfirst(str_replace('_', ' ', $fieldKey))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <h3 style="font-size: 15px; color: var(--navy, #0B2E5C); margin-top: 24px;">Data Preview (first 5 rows)</h3>
      <?php if (!empty($previewRows)): ?>
        <div style="overflow-x: auto; border: 1px solid var(--border, #D6E1EE); border-radius: 10px;">
          <table class="preview-table">
            <thead>
              <tr>
                <?php foreach ($headerLabels as $label): ?>
                  <th><?= e($label) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($previewRows as $row): ?>
                <tr>
                  <?php foreach ($headerLabels as $colIdx => $label): ?>
                    <td class="muted-cell"><?= e(mb_substr((string) ($row[$colIdx] ?? ''), 0, 40)) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="wizard-actions">
        <div class="wizard-actions-left">
          <button type="submit" class="btn-archive" style="width: auto; padding: 10px 20px;" onclick="this.form.elements['form'].value='go_back'; this.form.elements['go_to_step'].value='2';">
            <span style="margin-right: 6px;">&#8592;</span> Back
          </button>
        </div>
        <div class="wizard-actions-right">
          <button type="submit" class="btn-primary" style="width: auto; padding: 12px 28px;">
            Continue
            <span style="margin-left: 6px;">&#8594;</span>
          </button>
        </div>
      </div>
      <input type="hidden" name="go_to_step" value="3">
    </form>
  </div>

  <!-- ══════════════════════════════════════════════════════════════ -->
  <!-- STEP 4: Validate & Preview                                   -->
  <!-- ══════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 4): ?>
  <div class="admin-section">
    <h2>Validate &amp; Preview</h2>
    <p class="wizard-hint">We are now matching each row against your resident database. This may take a moment for large files.</p>

    <div style="text-align: center; padding: 40px 20px;">
      <div style="font-size: 24px; margin-bottom: 12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--navy, #0B2E5C)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="40" height="40" style="animation: spin 1.5s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
      </div>
      <p style="font-size: 16px; font-weight: 600; color: var(--navy, #0B2E5C);">Processing rows...</p>
      <p style="font-size: 13px; color: var(--muted, #3C5470);">Running exact and fuzzy matching against the resident database.</p>
    </div>

    <style>@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>

    <form method="POST" id="run-match-form" style="display: none;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="run_match">
    </form>
  </div>

  <script>
  // Auto-submit the match form when this step loads
  (function() {
    var form = document.getElementById('run-match-form');
    if (form) form.submit();
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════ -->
  <!-- STEP 5: Review & Confirm                                     -->
  <!-- ══════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 5): ?>
  <?php
    $normRows = $_SESSION['import_wizard']['normalized'] ?? [];
    $stats = $_SESSION['import_wizard']['stats'] ?? [];
    $diff = $_SESSION['import_wizard']['diff'] ?? null;
    $isUpdate = $existingCount > 0;
  ?>
  <div class="admin-section">
    <h2>Review &amp; Confirm Import</h2>
    <?php if ($isUpdate): ?>
      <p class="wizard-hint" style="background: #FFF8E1; border-left: 4px solid #F9A825; padding: 12px 16px; border-radius: 4px;">
        <strong>Update detected:</strong> This program already has <?= number_format($existingCount) ?> existing beneficiary(ies). This import will be an update &mdash; new members will be added, and those no longer in the list will be marked as removed.
      </p>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid">
      <div class="stat-box">
        <div class="stat-val"><?= number_format($stats['total'] ?? 0) ?></div>
        <div class="stat-lbl">Total Rows</div>
      </div>
      <div class="stat-box stat-good">
        <div class="stat-val"><?= number_format($stats['matched'] ?? 0) ?></div>
        <div class="stat-lbl">Matched</div>
      </div>
      <div class="stat-box stat-warn">
        <div class="stat-val"><?= number_format($stats['needs_review'] ?? 0) ?></div>
        <div class="stat-lbl">Needs Review</div>
      </div>
      <div class="stat-box stat-bad">
        <div class="stat-val"><?= number_format($stats['not_found'] ?? 0) ?></div>
        <div class="stat-lbl">Not Found</div>
      </div>
      <div class="stat-box stat-info">
        <div class="stat-val"><?= number_format($stats['duplicate_in_file'] ?? 0) ?></div>
        <div class="stat-lbl">Duplicates</div>
      </div>
      <div class="stat-box stat-info">
        <div class="stat-val"><?= number_format($stats['already_beneficiary'] ?? 0) ?></div>
        <div class="stat-lbl">Already a Beneficiary</div>
      </div>
      <?php if (($stats['errors'] ?? 0) > 0): ?>
      <div class="stat-box stat-bad">
        <div class="stat-val"><?= number_format($stats['errors']) ?></div>
        <div class="stat-lbl">Errors</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Diff for updated lists -->
    <?php if ($diff !== null): ?>
      <div class="diff-section">
        <h3>List Changes</h3>
        <div class="stats-grid" style="margin-top: 12px;">
          <div class="stat-box stat-good">
            <div class="stat-val"><?= count($diff['added']) ?></div>
            <div class="stat-lbl">New Members</div>
          </div>
          <div class="stat-box stat-bad">
            <div class="stat-val"><?= count($diff['removed']) ?></div>
            <div class="stat-lbl">Removed</div>
          </div>
          <div class="stat-box stat-info">
            <div class="stat-val"><?= count($diff['unchanged']) ?></div>
            <div class="stat-lbl">Unchanged</div>
          </div>
          <div class="stat-box">
            <div class="stat-val"><?= $diff['current_count'] ?> &rarr; <?= $diff['new_count'] ?></div>
            <div class="stat-lbl">List Size</div>
          </div>
        </div>
        <?php if (!empty($diff['removals_skipped'])): ?>
          <p class="wizard-hint" style="background: #FFF3E0; border-left: 4px solid #E65100; padding: 12px 16px; border-radius: 4px; margin-top: 12px;">
            <strong>Removals paused:</strong> <?= number_format($diff['unmatched_rows']) ?> row(s) could not be matched to any resident. Because one of them may be an existing beneficiary whose name changed, <strong>no members will be marked as removed</strong> in this import. Resolve the unmatched rows first, then re-import to update removals.
          </p>
        <?php endif; ?>
        <?php if (!empty($diff['removed_details'])): ?>
          <h3 style="margin-top: 16px;">Members Being Removed</h3>
          <ul class="diff-list">
            <?php foreach ($diff['removed_details'] as $rd): ?>
              <li><?= e(trim(($rd['first_name'] ?? '') . ' ' . ($rd['last_name'] ?? ''))) ?>
                <?php if (!empty($rd['birthdate'])): ?>
                  <span class="muted-note">(DOB: <?= e($rd['birthdate']) ?>)</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Full results table -->
    <?php if (!empty($normRows)): ?>
    <h3 style="font-size: 16px; color: var(--navy, #0B2E5C); margin-top: 24px; margin-bottom: 4px;">
      All Rows (<?= count($normRows) ?>)
    </h3>
    <div class="result-table-wrap">
      <table class="result-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Birthdate</th>
            <th>Zone</th>
            <th>Address</th>
            <th>Status</th>
            <th>Matched To</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($normRows as $row): ?>
            <?php
              $status = $row['_match_status'] ?? 'error';
              $badgeClass = match($status) {
                'matched' => 'badge-matched',
                'needs_review' => 'badge-needs-review',
                'not_found' => 'badge-not-found',
                'duplicate_in_file' => 'badge-duplicate',
                'already_beneficiary' => 'badge-already',
                default => 'badge-error',
              };
              $statusLabel = match($status) {
                'matched' => 'Matched',
                'needs_review' => 'Needs Review',
                'not_found' => 'Not Found',
                'duplicate_in_file' => 'Duplicate in File',
                'already_beneficiary' => 'Already a Beneficiary',
                default => 'Error',
              };
              $displayName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            ?>
            <tr>
              <td class="muted-cell"><?= (int) ($row['_row_number'] ?? 0) ?></td>
              <td><strong><?= e($displayName) ?></strong></td>
              <td class="muted-cell"><?= $row['birthdate'] ? e($row['birthdate']) : '<span class="muted-note">&mdash;</span>' ?></td>
              <td class="muted-cell"><?= $row['zone'] ? e($row['zone']) : '' ?></td>
              <td class="muted-cell"><?= $row['address'] ? e(mb_substr($row['address'], 0, 35)) : '' ?></td>
              <td>
                <span class="<?= $badgeClass ?>"><?= $statusLabel ?></span>
                <?php if ($row['already_beneficiary'] ?? false): ?>
                  <span class="badge-already" style="margin-left: 4px;">Existing</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($status === 'matched' && isset($row['_matched_name'])): ?>
                  <span style="font-size: 13px;"><?= e($row['_matched_name']) ?></span>
                <?php elseif ($status === 'needs_review' && !empty($row['_fuzzy_suggestions'])): ?>
                  <div class="fuzzy-suggest">
                    <?php foreach (array_slice($row['_fuzzy_suggestions'], 0, 3) as $sug): ?>
                      <div><strong><?= e(trim(($sug['first_name'] ?? '') . ' ' . ($sug['last_name'] ?? ''))) ?></strong> (<?= number_format($sug['confidence'], 0) ?>%)</div>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($status === 'not_found'): ?>
                  <span class="muted-note" style="font-size: 12px;">No matching resident found</span>
                <?php elseif ($status === 'duplicate_in_file'): ?>
                  <span class="muted-note" style="font-size: 12px;">Duplicate row in file</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="confirm_import">
      <input type="hidden" name="go_to_step" value="5">

      <div class="wizard-actions">
        <div class="wizard-actions-left">
          <button type="submit" class="btn-archive" style="width: auto; padding: 10px 20px;" onclick="this.form.elements['form'].value='go_back'; this.form.elements['go_to_step'].value='3';">
            <span style="margin-right: 6px;">&#8592;</span> Back to Mapping
          </button>
        </div>
        <div class="wizard-actions-right">
          <button type="submit" class="save-btn" style="width: auto; padding: 14px 32px; font-size: 16px;" onclick="return confirm('Import <?= number_format($stats['matched'] ?? 0) ?> matched beneficiary(ies)? This action cannot be undone.');">
            <span>Confirm Import</span>
            <span class="save-btn-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            </span>
          </button>
        </div>
      </div>
    </form>
  </div>

  <?php endif; ?>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
