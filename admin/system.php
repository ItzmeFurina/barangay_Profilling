<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/backup.php';

require_admin();
$activePage = 'system';

$pdo = get_db_connection();
$errors = [];

$backupDir = __DIR__ . '/../backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$isBackupRequest  = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create_backup';
$isRestoreRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'restore_backup';
$isRestoreUpload  = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'restore_upload';

if ($isBackupRequest || $isRestoreRequest || $isRestoreUpload) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$_SESSION['staff_user_id']]);
        $account = $stmt->fetch();

        if ($isRestoreRequest || $isRestoreUpload) {
            // Restore is destructive, so it gets the same email+password
            // re-confirmation as downloading a backup - password alone
            // isn't required for backup CREATION since that only reads data.
            $submittedEmail = trim($_POST['confirm_email'] ?? '');
            if (strcasecmp($submittedEmail, (string) $account['email']) !== 0
                || !password_verify($_POST['confirm_password'] ?? '', (string) $account['password_hash'])) {
                $errors[] = 'Incorrect email or password - nothing was changed.';
            }
        } else {
            if (!password_verify($_POST['confirm_password'] ?? '', (string) $account['password_hash'])) {
                $errors[] = 'Incorrect password - nothing was changed.';
            }
        }
    }
}
if (($isRestoreRequest || $isRestoreUpload) && empty($errors) && ($_POST['confirm_text'] ?? '') !== 'RESTORE') {
    $errors[] = 'Type RESTORE, in capital letters, to confirm.';
}

// ---------------------------------------------------------------------
// Backup: switches this same request into a streaming response so the
// progress bar updates live as each table actually finishes, instead of
// faking a percentage. A short pause per table just makes that progress
// visible to a human at this project's current small data volume - at
// real scale the true I/O time takes over and this is safe to remove.
// ---------------------------------------------------------------------
if ($isBackupRequest && empty($errors)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');

    $filename = 'backup_' . date('Y-m-d_His') . '.sql';
    $filePath = $backupDir . $filename;
    $backupId = null;

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<title>Creating backup - Barangay 420</title>'
       . '<link rel="stylesheet" href="assets/css/style.css"></head>'
       . '<body style="background:var(--bg);">'
       . '<div style="max-width:520px;margin:60px auto;padding:0 24px;">'
       . '<h1>Creating backup&hellip;</h1>'
       . '<div style="background:var(--white);border:1px solid var(--border);border-radius:8px;padding:24px;">'
       . '<div style="background:var(--border);border-radius:999px;height:10px;overflow:hidden;">'
       . '<div id="bar" style="background:var(--blue);height:100%;width:0%;transition:width .3s;"></div>'
       . '</div><p id="label" class="muted-note" style="margin-top:12px;">Starting&hellip;</p>'
       . '</div></div>';
    flush();

    try {
        $stmt = $pdo->prepare("INSERT INTO backup_logs (initiated_by, status) VALUES (?, 'in_progress')");
        $stmt->execute([$_SESSION['staff_user_id']]);
        $backupId = (int) $pdo->lastInsertId();

        $totalRecords = run_backup($pdo, $filePath, $backupDir, function ($table, $tableCount, $recordsSoFar, $totalRecords) {
            $percent = $totalRecords > 0 ? (int) round(($recordsSoFar / $totalRecords) * 100) : 100;
            $label = "{$table}: {$tableCount} records ({$recordsSoFar} / {$totalRecords} total so far)";
            echo '<script>document.getElementById("bar").style.width="' . $percent . '%";'
               . 'document.getElementById("label").textContent=' . json_encode($label) . ';</script>' . "\n";
            flush();
            usleep(150000);
        });

        $pdo->prepare("UPDATE backup_logs SET status='completed', completed_at=NOW(), record_count=?, file_path=? WHERE backup_id=?")
            ->execute([$totalRecords, $filename, $backupId]);
        log_activity($pdo, $_SESSION['staff_user_id'], "Created backup ({$totalRecords} records)", 'create');

        echo '<script>document.getElementById("bar").style.width="100%";'
           . 'document.getElementById("bar").style.background="var(--success)";'
           . 'var label=document.getElementById("label");'
           . 'label.style.fontSize="15px";'
           . 'label.innerHTML=' . json_encode(
                 '<span class="badge badge-active">Done</span> ' . $totalRecords . ' records backed up.'
             ) . '+\'<br><a href="system.php" class="btn-primary" style="display:inline-block;width:auto;padding:12px 28px;margin-top:16px;text-decoration:none;">Go back to System</a>\''
           . '+\'<span class="muted-note" style="display:block;margin-top:10px;">To download it, use the Download form on the System page - it asks you to confirm your email and password again first.</span>\';</script>';
    } catch (Exception $e) {
        error_log('Backup failed: ' . $e->getMessage());
        if ($backupId) {
            $pdo->prepare("UPDATE backup_logs SET status='failed' WHERE backup_id=?")->execute([$backupId]);
        }
        echo '<script>document.getElementById("bar").style.background="var(--danger)";'
           . 'var label=document.getElementById("label");'
           . 'label.style.fontSize="15px";'
           . 'label.innerHTML=' . json_encode(
                 '<span class="badge badge-rejected">Failed</span> Something went wrong.'
             ) . '+\'<br><a href="system.php" class="btn-primary" style="display:inline-block;width:auto;padding:12px 28px;margin-top:16px;text-decoration:none;background:var(--danger);">Go back and try again</a>\';</script>';
    }
    echo '</body></html>';
    exit;
}

// ---------------------------------------------------------------------
// Restore: genuinely destructive, so it gets more friction than backup
// on purpose - password AND a typed confirmation, not just one or the
// other.
// ---------------------------------------------------------------------
if ($isRestoreRequest && empty($errors)) {
    $backupId = (int) ($_POST['backup_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT file_path FROM backup_logs WHERE backup_id = ? AND status = 'completed'");
    $stmt->execute([$backupId]);
    $filename = $stmt->fetchColumn();

    if (!$filename || !is_file($backupDir . $filename)) {
        $errors[] = 'That backup file could not be found.';
    } else {
        // Defense-in-depth path check (same pattern as download-backup.php):
        // the filename comes from the DB, not user input, but resolve it and
        // make sure it really sits inside the backups directory before
        // feeding it to the restore engine.
        $fullPath = realpath($backupDir . $filename);
        $realBackupDir = realpath($backupDir);
        if ($fullPath === false || $realBackupDir === false
            || strpos($fullPath, $realBackupDir . DIRECTORY_SEPARATOR) !== 0
            || !is_file($fullPath)) {
            $errors[] = 'That backup file could not be found.';
        } else {
            try {
                // Safety net before anything destructive: snapshot the CURRENT
                // data first, so even a restore gone wrong is recoverable.
                // MySQL auto-commits DDL (TRUNCATE), so a statement-level
                // rollback is not available - the snapshot is the rollback.
                $snapshotPath = $backupDir . 'pre_restore_' . date('Y-m-d_His') . '.sql';
                $snapshotCount = run_backup($pdo, $snapshotPath, $backupDir);
                log_activity($pdo, $_SESSION['staff_user_id'], "Created pre-restore safety snapshot ({$snapshotCount} records)", 'create');

                validate_backup_file($fullPath);
                $restored = run_restore($pdo, $fullPath, $backupDir);
                log_activity_surviving_restore($pdo, (int) $_SESSION['staff_user_id'], "Restored from backup #{$backupId} ({$restored} statements)");
                flash('success', "Restore complete - {$restored} statements executed. A safety snapshot of the previous data was saved as {$snapshotCount} records in the backups folder.");
                redirect('system.php');
            } catch (Exception $e) {
                error_log('Restore failed: ' . $e->getMessage());
                $errors[] = 'The restore did not finish cleanly - check the server error log before trying anything else. The database may now be in a partial state.';
            }
        }
    }
}

// ---------------------------------------------------------------------
// Restore from an UPLOADED backup file (.sql). Same destructive
// friction as restoring a system backup, but works with a file from
// anywhere - one downloaded earlier, or from another installation -
// which is the only way to bring data back if the server's own backups
// folder was ever lost.
// ---------------------------------------------------------------------
if ($isRestoreUpload && empty($errors)) {
    if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a .sql backup file to restore from.';
    } elseif ($_FILES['backup_file']['size'] > 25 * 1024 * 1024) {
        $errors[] = 'The file is too large (max 25 MB).';
    } elseif (strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION)) !== 'sql') {
        $errors[] = 'Only .sql backup files are accepted.';
    } else {
        try {
            $snapshotPath = $backupDir . 'pre_restore_' . date('Y-m-d_His') . '.sql';
            $snapshotCount = run_backup($pdo, $snapshotPath, $backupDir);
            log_activity($pdo, $_SESSION['staff_user_id'], "Created pre-restore safety snapshot ({$snapshotCount} records)", 'create');

            validate_backup_file($_FILES['backup_file']['tmp_name']);
            $restored = run_restore($pdo, $_FILES['backup_file']['tmp_name'], $backupDir);
            log_activity_surviving_restore($pdo, (int) $_SESSION['staff_user_id'], "Restored from uploaded backup file " . basename($_FILES['backup_file']['name']) . " ({$restored} statements)");
            flash('success', "Restore complete - {$restored} statements executed from the uploaded file. A safety snapshot of the previous data was saved in the backups folder.");
            redirect('system.php');
        } catch (Exception $e) {
            error_log('Upload restore failed: ' . $e->getMessage());
            $errors[] = 'The uploaded file did not restore cleanly - check the server error log before trying anything else. The database may now be in a partial state.';
        }
    }
}

$backups = $pdo->query(
    "SELECT bl.backup_id, bl.started_at, bl.record_count, bl.file_path, u.full_name
     FROM backup_logs bl LEFT JOIN users u ON u.user_id = bl.initiated_by
     WHERE bl.status = 'completed' ORDER BY bl.started_at DESC"
)->fetchAll();

function human_file_size(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $size = $bytes / 1024;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, 1) . ' ' . $units[$unit];
}

$latestBackup = $backups[0] ?? null;
$storageUsed = array_sum(array_map(static function ($b) use ($backupDir) {
    return $b['file_path'] && is_file($backupDir . $b['file_path']) ? (int) filesize($backupDir . $b['file_path']) : 0;
}, $backups));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>System - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>System</h1>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($errMsg = flash('error')): ?>
    <div class="alert alert-error"><?= e($errMsg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="stat-grid">
    <div class="stat-card stat-accent-gold">
      <div class="stat-number"><?= count($backups) ?></div>
      <div class="stat-label">Backups saved on this server</div>
    </div>
    <div class="stat-card stat-accent-blue">
      <div class="stat-number"><?= $latestBackup ? e(relative_time($latestBackup['started_at'])) : '&mdash;' ?></div>
      <div class="stat-label">Latest backup</div>
    </div>
    <div class="stat-card stat-accent-green">
      <div class="stat-number"><?= e(human_file_size($storageUsed)) ?></div>
      <div class="stat-label">Storage used by backups</div>
    </div>
  </div>

  <div class="admin-section">
    <h2 class="section-title">Create a backup</h2>
    <form method="POST" style="max-width:320px;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="create_backup">
      <label for="backup_password">Confirm your password</label>
      <input type="password" id="backup_password" name="confirm_password" required>
      <button type="submit" class="btn-primary">Start backup</button>
    </form>
  </div>

  <div class="admin-section">
    <h2 class="section-title">Existing backups (<?= count($backups) ?>)</h2>
    <?php if (empty($backups)): ?>
      <p class="muted-note">No backups yet. Create one above - it appears here and in the restore list automatically.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Created</th><th>By</th><th>Records</th><th>File</th><th>Size</th></tr></thead>
        <tbody>
          <?php foreach ($backups as $i => $b): ?>
            <tr>
              <td>
                <?= e(date('M j, Y g:i A', strtotime($b['started_at']))) ?>
                <?php if ($i === 0): ?><span class="badge badge-active" style="margin-left:6px;">Latest</span><?php endif; ?>
              </td>
              <td><?= e($b['full_name'] ?? 'Unknown') ?></td>
              <td><?= (int) $b['record_count'] ?></td>
              <td style="font-size: 13px;"><?= e($b['file_path'] ?? '') ?></td>
              <td>
                <?php if ($b['file_path'] && is_file($backupDir . $b['file_path'])): ?>
                  <?= e(human_file_size((int) filesize($backupDir . $b['file_path']))) ?>
                <?php else: ?>
                  <span class="badge badge-rejected">file missing</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($backups)): ?>
  <div class="admin-section">
    <h2 class="section-title section-title-gold">Download a backup</h2>
    <form method="POST" action="download-backup.php" style="max-width:460px;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

      <label for="download_backup_id">Which backup</label>
      <select id="download_backup_id" name="backup_id" class="form-select" required>
        <?php foreach ($backups as $b): ?>
          <option value="<?= (int) $b['backup_id'] ?>">
            <?= e(date('M j, Y g:i A', strtotime($b['started_at']))) ?> &mdash; <?= (int) $b['record_count'] ?> records
          </option>
        <?php endforeach; ?>
      </select>

      <div class="member-grid">
        <div>
          <label for="download_email">Confirm your email</label>
          <input type="email" id="download_email" name="confirm_email" required>
        </div>
        <div>
          <label for="download_password">Confirm your password</label>
          <input type="password" id="download_password" name="confirm_password" required>
        </div>
      </div>

      <button type="submit" class="btn-gold">Confirm &amp; download</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="admin-section danger-card">
    <h2 class="section-title section-title-danger">Restore from a backup</h2>
    <p class="admin-section-desc">This replaces every current record with whatever is in the chosen backup. There is no undo beyond restoring a different, newer backup afterward - a safety snapshot of the current data is saved automatically first. Use this only if you are certain. Two ways to restore: pick one of the backups saved on this server (listed above), or upload a backup file - one you downloaded earlier or from another installation.</p>
    <?php if (empty($backups)): ?>
      <p class="muted-note">No system backups available to restore from yet - you can still use the upload option below.</p>
    <?php else: ?>
      <form method="POST" style="max-width:460px;" data-confirm data-confirm-title="Overwrite all current data?" data-confirm-message="Restoring replaces every current record with the backup's contents. A safety snapshot of today's data is saved first, but this cannot be undone through this page. Continue?" data-confirm-label="Restore data" data-confirm-danger>
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="form" value="restore_backup">

        <label for="backup_id">Which backup</label>
        <select id="backup_id" name="backup_id" class="form-select" required>
          <?php foreach ($backups as $b): ?>
            <option value="<?= (int) $b['backup_id'] ?>">
              <?= e(date('M j, Y g:i A', strtotime($b['started_at']))) ?> &mdash; <?= (int) $b['record_count'] ?> records
            </option>
          <?php endforeach; ?>
        </select>

        <div class="member-grid">
          <div>
            <label for="restore_email">Confirm your email</label>
            <input type="email" id="restore_email" name="confirm_email" required>
          </div>
          <div>
            <label for="restore_password">Confirm your password</label>
            <input type="password" id="restore_password" name="confirm_password" required>
          </div>
        </div>

        <label for="confirm_text">Type RESTORE to confirm</label>
        <input type="text" id="confirm_text" name="confirm_text" placeholder="RESTORE" required>

        <button type="submit" class="btn-danger">Restore this backup</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="admin-section danger-card">
    <h2 class="section-title section-title-danger">Restore from an uploaded file</h2>
    <p class="admin-section-desc">Upload a backup file (.sql) - e.g. one you downloaded earlier and saved somewhere, or a backup from another installation of this system. For safety, only files made by this system are accepted (they carry a marker on their first line), and only the statement types a real backup contains are allowed to run. Same confirmation as above, and the same warning: this replaces everything currently in the database.</p>
    <form method="POST" enctype="multipart/form-data" style="max-width:460px;" data-confirm data-confirm-title="Overwrite all current data?" data-confirm-message="Restoring this file replaces every current record with the file's contents. A safety snapshot of today's data is saved first, but this cannot be undone through this page. Continue?" data-confirm-label="Restore data" data-confirm-danger>
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="restore_upload">

      <label for="backup_file">Backup file (.sql)</label>
      <input type="file" id="backup_file" name="backup_file" accept=".sql" required>

      <div class="member-grid">
        <div>
          <label for="upload_restore_email">Confirm your email</label>
          <input type="email" id="upload_restore_email" name="confirm_email" required>
        </div>
        <div>
          <label for="upload_restore_password">Confirm your password</label>
          <input type="password" id="upload_restore_password" name="confirm_password" required>
        </div>
      </div>

      <label for="upload_confirm_text">Type RESTORE to confirm</label>
      <input type="text" id="upload_confirm_text" name="confirm_text" placeholder="RESTORE" required>

      <button type="submit" class="btn-danger">Restore from this file</button>
    </form>
  </div>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
