<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin_login();
$activePage = 'home';

$pdo = get_db_connection();
$errors = [];

// Photos are genuinely public (residents see them), so they live inside
// the resident portal's own asset tree, not behind uploads/'s .htaccess
// lockdown - no PHP intermediary needed to serve something that was
// always meant to be public in the first place.
$photoDir = __DIR__ . '/../resident/assets/officials/';
if (!is_dir($photoDir)) {
    mkdir($photoDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_info') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $fields = [
            'tagline'        => trim($_POST['tagline'] ?? ''),
            'about_text'     => trim(strip_tags($_POST['about_text'] ?? '')),
            'address'        => normalize_label($_POST['address'] ?? ''),
            'office_hours'   => trim($_POST['office_hours'] ?? ''),
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'contact_email'  => normalize_email($_POST['contact_email'] ?? ''),
        ];
        if ($fields['address'] === '') {
            $errors[] = 'Address is required.';
        }
        if ($fields['contact_email'] !== '' && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid contact email, or leave it blank.';
        }

        if (empty($errors)) {
            $pdo->prepare(
                'UPDATE barangay_info SET
                    tagline = ?, about_text = ?, address = ?, office_hours = ?,
                    contact_number = ?, contact_email = ?, updated_by = ?, updated_at = NOW()
                 WHERE info_id = 1'
            )->execute([
                $fields['tagline'] ?: null, $fields['about_text'] ?: null, $fields['address'],
                $fields['office_hours'] ?: null, $fields['contact_number'] ?: null,
                $fields['contact_email'] ?: null, $_SESSION['staff_user_id'],
            ]);
            log_activity($pdo, $_SESSION['staff_user_id'], 'Updated barangay information', 'update');
            flash('success', 'Saved - residents see this immediately on their home page.');
            redirect('home.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_official') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $officialId = (int) ($_POST['official_id'] ?? 0);
        $fullName = normalize_person_name($_POST['full_name'] ?? '');
        $position = normalize_label($_POST['position'] ?? '');
        $displayOrder = (int) ($_POST['display_order'] ?? 100);

        if ($fullName === '' || $position === '') {
            $errors[] = 'Name and position are both required.';
        }

        $photoPath = null;
        $hasNewPhoto = isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasNewPhoto) {
            if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'There was a problem uploading that photo. Please try again.';
            } else {
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
                if (!in_array($mime, $allowedMimes, true)) {
                    $errors[] = 'Photo must be a JPG, PNG, or WEBP file.';
                } elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
                    $errors[] = 'Photo must be smaller than 3MB.';
                } else {
                    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                    $safeName = 'official_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $photoDir . $safeName)) {
                        $errors[] = 'There was a problem saving the uploaded photo. Please try again.';
                    } else {
                        $photoPath = $safeName;
                    }
                }
            }
        }

        if (empty($errors)) {
            if ($officialId > 0) {
                $oldPhotoStmt = $pdo->prepare('SELECT photo_path FROM barangay_officials WHERE official_id = ?');
                $oldPhotoStmt->execute([$officialId]);
                $oldPhoto = $oldPhotoStmt->fetchColumn();
                if ($photoPath !== null) {
                    $pdo->prepare('UPDATE barangay_officials SET full_name=?, position=?, display_order=?, photo_path=? WHERE official_id=?')
                        ->execute([$fullName, $position, $displayOrder, $photoPath, $officialId]);
                    if ($oldPhoto) {
                        @unlink($photoDir . $oldPhoto);
                    }
                } else {
                    $pdo->prepare('UPDATE barangay_officials SET full_name=?, position=?, display_order=? WHERE official_id=?')
                        ->execute([$fullName, $position, $displayOrder, $officialId]);
                }
                $logMsg = "Updated official: {$fullName}";
            } else {
                $pdo->prepare('INSERT INTO barangay_officials (full_name, position, display_order, photo_path) VALUES (?, ?, ?, ?)')
                    ->execute([$fullName, $position, $displayOrder, $photoPath]);
                $logMsg = "Added official: {$fullName} ({$position})";
            }
            log_activity($pdo, $_SESSION['staff_user_id'], $logMsg, $officialId > 0 ? 'update' : 'create');
            flash('success', 'Official saved.');
            redirect('home.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_official') {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $officialId = (int) ($_POST['official_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT photo_path FROM barangay_officials WHERE official_id = ?');
        $stmt->execute([$officialId]);
        $oldPhoto = $stmt->fetchColumn();
        $pdo->prepare('DELETE FROM barangay_officials WHERE official_id = ?')->execute([$officialId]);
        if ($oldPhoto) {
            @unlink($photoDir . $oldPhoto);
        }
        log_activity($pdo, $_SESSION['staff_user_id'], "Removed official #{$officialId}", 'delete');
        flash('success', 'Official removed.');
        redirect('home.php');
    }
    flash('error', 'Your session expired. Please try again.');
    redirect('home.php');
}

$info = $pdo->query('SELECT * FROM barangay_info WHERE info_id = 1')->fetch();
if (!$info) {
    $info = array_fill_keys(['tagline', 'about_text', 'address', 'office_hours', 'contact_number', 'contact_email'], '');
}

$editingOfficial = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM barangay_officials WHERE official_id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editingOfficial = $stmt->fetch() ?: null;
}

$officials = $pdo->query('SELECT * FROM barangay_officials ORDER BY display_order ASC, full_name ASC')->fetchAll();
$commonPositions = ['Punong Barangay', 'Admin', 'Treasurer', 'Kagawad', 'SK Chairman'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Home - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>Barangay Information</h1>
  <p class="admin-section-desc">What residents see on the public home page - editable by Staff or Admin.</p>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="admin-section">
    <h2>General information</h2>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="save_info">

      <label for="tagline">Tagline (shown under the barangay name)</label>
      <input type="text" id="tagline" name="tagline" value="<?= old('tagline', $info) ?>">

      <label for="about_text">About the barangay</label>
      <textarea id="about_text" name="about_text" rows="4"><?= e($info['about_text'] ?? '') ?></textarea>

      <div class="member-grid" style="margin-top:12px;">
        <div><label for="address">Address</label><input type="text" id="address" name="address" value="<?= old('address', $info) ?>" required></div>
        <div><label for="office_hours">Office hours</label><input type="text" id="office_hours" name="office_hours" value="<?= old('office_hours', $info) ?>"></div>
        <div><label for="contact_number">Contact number</label><input type="text" id="contact_number" name="contact_number" value="<?= old('contact_number', $info) ?>"></div>
        <div><label for="contact_email">Contact email</label><input type="text" id="contact_email" name="contact_email" value="<?= old('contact_email', $info) ?>"></div>
      </div>

      <button type="submit" class="btn-primary" style="width:auto; padding:14px 28px; margin-top:16px;">Save</button>
    </form>
  </div>

  <div class="admin-section">
    <h2><?= $editingOfficial ? 'Edit official' : 'Add an official' ?></h2>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="save_official">
      <input type="hidden" name="official_id" value="<?= $editingOfficial ? (int) $editingOfficial['official_id'] : 0 ?>">

      <div class="member-grid">
        <div>
          <label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" value="<?= e($editingOfficial['full_name'] ?? '') ?>" required>
        </div>
        <div>
          <label for="position">Position</label>
          <input type="text" id="position" name="position" list="position-suggestions" value="<?= e($editingOfficial['position'] ?? '') ?>" required>
          <datalist id="position-suggestions">
            <?php foreach ($commonPositions as $pos): ?><option value="<?= e($pos) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label for="display_order">Display order (lower shows first)</label>
          <input type="number" id="display_order" name="display_order" value="<?= (int) ($editingOfficial['display_order'] ?? 100) ?>">
        </div>
        <div>
          <label for="photo">Photo (optional - JPG/PNG/WEBP, max 3MB)</label>
          <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.webp">
        </div>
      </div>

      <button type="submit" class="btn-primary" style="width:auto; padding:14px 28px; margin-top:14px;"><?= $editingOfficial ? 'Save changes' : 'Add official' ?></button>
      <?php if ($editingOfficial): ?><a href="home.php" style="margin-left:12px; font-size: 15px;">Cancel</a><?php endif; ?>
    </form>
  </div>

  <div class="admin-section">
    <h2>Current officials (<?= count($officials) ?>)</h2>
    <?php if (empty($officials)): ?>
      <p class="muted-note">None added yet.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th></th><th>Name</th><th>Position</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($officials as $o): ?>
            <tr>
              <td>
                <?php if ($o['photo_path']): ?>
                  <img src="../resident/assets/officials/<?= e($o['photo_path']) ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                <?php else: ?>
                  <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:var(--navy);color:#fff;font-size: 14px;font-weight:600;"><?= e(official_initials($o['full_name'])) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($o['full_name']) ?></td>
              <td class="muted-note"><?= e($o['position']) ?></td>
              <td>
                <a href="home.php?edit=<?= (int) $o['official_id'] ?>#full_name">Edit</a>
                &nbsp;|&nbsp;
                <form method="POST" class="inline-form" data-confirm data-confirm-title="Remove this official?" data-confirm-message="The official is removed from the barangay directory that residents see. This cannot be undone." data-confirm-label="Remove official" data-confirm-danger style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="form" value="delete_official">
                  <input type="hidden" name="official_id" value="<?= (int) $o['official_id'] ?>">
                  <button type="submit" class="btn-archive" style="padding:4px 10px; color:var(--danger); border-color:var(--danger);">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
