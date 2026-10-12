<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin_login();
$activePage = 'events';

$pdo = get_db_connection();
$errors = [];

// Event photos are genuinely public (residents see them on their events
// feed), so - same reasoning as barangay_officials.photo_path - they live
// inside the resident portal's own asset tree, not behind uploads/'s
// .htaccess lockdown.
$photoDir = __DIR__ . '/../resident/assets/events/';
if (!is_dir($photoDir)) {
    mkdir($photoDir, 0755, true);
}

const EVENT_PHOTO_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const EVENT_PHOTO_MAX_BYTES = 5 * 1024 * 1024;

/** Validates and stores one uploaded photo, returns its stored filename or null. */
function save_event_photo(array $file, string $photoDir, array &$errors): ?string {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'There was a problem uploading one of the photos. Please try again.';
        return null;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(EVENT_PHOTO_MIMES[$mime])) {
        $errors[] = 'Photos must be JPG, PNG, or WEBP files.';
        return null;
    }
    if ($file['size'] > EVENT_PHOTO_MAX_BYTES) {
        $errors[] = 'Each photo must be smaller than 5MB.';
        return null;
    }
    $safeName = 'event_' . bin2hex(random_bytes(8)) . '.' . EVENT_PHOTO_MIMES[$mime];
    if (!move_uploaded_file($file['tmp_name'], $photoDir . $safeName)) {
        $errors[] = 'There was a problem saving one of the photos. Please try again.';
        return null;
    }
    return $safeName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_event') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $eventId     = (int) ($_POST['event_id'] ?? 0);
        $title       = normalize_label($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $eventDate   = trim($_POST['event_date'] ?? '');

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        $dateObj = DateTime::createFromFormat('Y-m-d', $eventDate);
        if (!$dateObj) {
            $errors[] = 'Enter a valid event date.';
        }

        // Collect and pre-validate photos before touching the database, so
        // a bad file doesn't leave us with an event saved but no photos.
        $uploadedFiles = [];
        if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
            $count = count($_FILES['photos']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $uploadedFiles[] = [
                    'name'     => $_FILES['photos']['name'][$i],
                    'type'     => $_FILES['photos']['type'][$i],
                    'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                    'error'    => $_FILES['photos']['error'][$i],
                    'size'     => $_FILES['photos']['size'][$i],
                ];
            }
        }

        $headerFile = null;
        if (!empty($_FILES['header_photo']) && $_FILES['header_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $headerFile = $_FILES['header_photo'];
        }

        if (empty($errors)) {
            // Files written during this request are tracked separately from
            // the DB transaction: new files must be removed if the txn rolls
            // back (no orphaned files), and the old header file must only be
            // unlinked AFTER a successful commit (so a failed upload never
            // destroys the current cover that the rolled-back row still
            // points at).
            $newlySavedFiles = [];
            $oldFilesToDelete = [];
            try {
                $pdo->beginTransaction();

                if ($eventId > 0) {
                    $pdo->prepare('UPDATE events SET title = ?, description = ?, event_date = ? WHERE event_id = ?')
                        ->execute([$title, $description ?: null, $eventDate, $eventId]);
                    $logMsg = "Updated event: {$title}";
                } else {
                    $pdo->prepare('INSERT INTO events (title, description, event_date, created_by) VALUES (?, ?, ?, ?)')
                        ->execute([$title, $description ?: null, $eventDate, $_SESSION['staff_user_id']]);
                    $eventId = (int) $pdo->lastInsertId();
                    $logMsg = "Created event: {$title}";
                }

                // Header photo: replaces any existing cover (display_order 0)
                // so there is never more than one cover per event. Save the
                // replacement first; only touch the old cover once the new
                // file is safely on disk.
                if ($headerFile !== null) {
                    $headerName = save_event_photo($headerFile, $photoDir, $errors);
                    if ($headerName !== null) {
                        $newlySavedFiles[] = $headerName;
                        $oldStmt = $pdo->prepare('SELECT photo_id, photo_path FROM event_photos WHERE event_id = ? AND display_order = 0');
                        $oldStmt->execute([$eventId]);
                        if ($old = $oldStmt->fetch()) {
                            $pdo->prepare('DELETE FROM event_photos WHERE photo_id = ?')->execute([$old['photo_id']]);
                            $oldFilesToDelete[] = $photoDir . basename($old['photo_path']);
                        }
                        $pdo->prepare('INSERT INTO event_photos (event_id, photo_path, display_order) VALUES (?, ?, 0)')
                            ->execute([$eventId, $headerName]);
                    }
                }

                if (!empty($uploadedFiles)) {
                    $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(display_order), -1) + 1 FROM event_photos WHERE event_id = ?');
                    $orderStmt->execute([$eventId]);
                    $nextOrder = (int) $orderStmt->fetchColumn();

                    $insertPhoto = $pdo->prepare('INSERT INTO event_photos (event_id, photo_path, display_order) VALUES (?, ?, ?)');
                    foreach ($uploadedFiles as $file) {
                        $savedName = save_event_photo($file, $photoDir, $errors);
                        if ($savedName !== null) {
                            $newlySavedFiles[] = $savedName;
                            $insertPhoto->execute([$eventId, $savedName, $nextOrder]);
                            $nextOrder++;
                        }
                    }
                }

                if (empty($errors)) {
                    log_activity($pdo, $_SESSION['staff_user_id'], $logMsg, $eventId > 0 ? 'update' : 'create');
                    $pdo->commit();
                    foreach ($oldFilesToDelete as $oldPath) {
                        if (is_file($oldPath)) {
                            unlink($oldPath);
                        }
                    }
                    flash('success', 'Event saved - residents will see it on their dashboard and events page.');
                    redirect('events.php');
                } else {
                    $pdo->rollBack();
                    foreach ($newlySavedFiles as $savedName) {
                        $path = $photoDir . basename($savedName);
                        if (is_file($path)) {
                            unlink($path);
                        }
                    }
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                foreach ($newlySavedFiles as $savedName) {
                    $path = $photoDir . basename($savedName);
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                error_log('Event save failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while saving the event. Please try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_photo') {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT photo_path FROM event_photos WHERE photo_id = ? AND event_id = ?');
        $stmt->execute([$photoId, $eventId]);
        if ($photoPath = $stmt->fetchColumn()) {
            $pdo->prepare('DELETE FROM event_photos WHERE photo_id = ? AND event_id = ?')->execute([$photoId, $eventId]);
            $fullPath = $photoDir . basename($photoPath);
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
            log_activity($pdo, $_SESSION['staff_user_id'], "Deleted a photo from event #{$eventId}", 'delete');
        }
        redirect('event-create.php?edit=' . $eventId);
    }
    flash('error', 'Your session expired. Please try again.');
    redirect('events.php');
}

$editingEvent = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE event_id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editingEvent = $stmt->fetch() ?: null;
}
$editingPhotos = [];
if ($editingEvent) {
    $stmt = $pdo->prepare('SELECT * FROM event_photos WHERE event_id = ? ORDER BY display_order ASC, photo_id ASC');
    $stmt->execute([(int) $editingEvent['event_id']]);
    $editingPhotos = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title><?= $editingEvent ? 'Edit event' : 'Create event' ?> - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
/* Kept inline (in addition to assets/css/style.css) so this page's
   photo preview/manage grid always renders and behaves correctly even
   if the shared stylesheet is out of date or fails to load. */
.event-photo-manage-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
  gap: 10px;
  margin-top: 8px;
}
.event-photo-manage-item { position: relative; }
.event-photo-manage-item img {
  width: 100%;
  height: 70px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid var(--border, #D6E1EE);
  display: block;
}
.event-photo-remove {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: none;
  background: var(--danger, #9A2C2C);
  color: #fff;
  font-size: 15px;
  line-height: 1;
  cursor: pointer;
}
.event-photo-preview {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
  gap: 10px;
  margin-top: 8px;
}
.event-photo-preview img { width: 100%; height: 70px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border, #D6E1EE); display: block; }
.event-photo-preview-item { position: relative; }
.event-photo-preview-remove {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: none;
  background: var(--danger, #9A2C2C);
  color: #fff;
  font-size: 15px;
  line-height: 1;
  cursor: pointer;
}
.header-photo-box {
  position: relative;
  width: 220px;
  margin-top: 8px;
}
.header-photo-box img {
  width: 220px;
  height: 130px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid var(--border, #D6E1EE);
  display: block;
}
.header-photo-badge {
  position: absolute;
  bottom: 8px;
  left: 8px;
  background: var(--gold, #E8A93D);
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 4px;
}
.event-photo-header-badge {
  position: absolute;
  bottom: 2px;
  left: 2px;
  background: rgba(232, 169, 61, 0.95);
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 5px;
  border-radius: 3px;
}
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <div class="edit-head">
    <a href="events.php" class="back-btn ef-fade" style="--d:40ms">
      <span class="back-btn-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg>
      </span>
      <span>Back to Events</span>
    </a>
    <div class="edit-head-main">
      <h1 class="edit-title ef-fade" style="--d:130ms"><?= $editingEvent ? 'Edit event' : 'Create an event' ?></h1>
    </div>
  </div>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="admin-section">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="form" value="save_event">
      <input type="hidden" name="event_id" value="<?= $editingEvent ? (int) $editingEvent['event_id'] : 0 ?>">

      <div class="member-grid">
        <div>
          <label for="title">Title</label>
          <input type="text" id="title" name="title" value="<?= e($editingEvent['title'] ?? '') ?>" required>
        </div>
        <div>
          <label for="event_date">Date</label>
          <input type="date" id="event_date" name="event_date" value="<?= e($editingEvent['event_date'] ?? '') ?>" required>
          <p class="muted-note" style="margin:4px 0 0;">A date in the future is automatically labeled "Upcoming" for residents.</p>
        </div>
      </div>

      <label for="description">Caption</label>
      <textarea id="description" name="description" rows="3"><?= e($editingEvent['description'] ?? '') ?></textarea>

      <label for="header_photo">Header / cover photo (JPG, PNG, or WEBP, max 5MB each)</label>
      <input type="file" id="header_photo" name="header_photo" accept=".jpg,.jpeg,.png,.webp" onchange="previewHeaderPhoto(this)">
      <p class="muted-note" style="margin:4px 0 0;">This is the big photo shown at the top of the post. If you skip it, the first gallery photo below is used instead.</p>
      <?php
      $currentHeader = null;
      if ($editingEvent) {
          foreach ($editingPhotos as $p) {
              if ((int) $p['display_order'] === 0) {
                  $currentHeader = $p;
                  break;
              }
          }
      }
      ?>
      <div id="header-preview">
        <?php if ($currentHeader): ?>
          <div class="header-photo-box">
            <img src="../resident/assets/events/<?= e($currentHeader['photo_path']) ?>" alt="">
            <span class="header-photo-badge">Current header</span>
          </div>
        <?php endif; ?>
      </div>

      <label for="photos">Photos (JPG, PNG, or WEBP, max 5MB each)</label>
      <input type="file" id="photos" name="photos[]" accept=".jpg,.jpeg,.png,.webp" multiple onchange="previewEventPhotos(this)">
      <p class="muted-note" id="photos-count" style="margin:4px 0 0;">You can select several photos at once - they'll all attach to this one post.</p>
      <div class="event-photo-preview" id="photos-preview"></div>

      <?php if ($editingEvent && !empty($editingPhotos)): ?>
        <p class="muted-note" style="margin:12px 0 4px;">Current photos - the gold-tagged one is the header shown on top:</p>
        <div class="event-photo-manage-grid">
          <?php foreach ($editingPhotos as $photo): ?>
            <div class="event-photo-manage-item">
              <img src="../resident/assets/events/<?= e($photo['photo_path']) ?>" alt="">
              <?php if ((int) $photo['display_order'] === 0): ?>
                <span class="event-photo-header-badge">Header</span>
              <?php endif; ?>
              <form method="POST" data-confirm data-confirm-title="Remove this photo?" data-confirm-message="The photo is removed from this event. This cannot be undone." data-confirm-label="Remove photo" data-confirm-danger>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="form" value="delete_photo">
                <input type="hidden" name="photo_id" value="<?= (int) $photo['photo_id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int) $editingEvent['event_id'] ?>">
                <button type="submit" class="event-photo-remove" title="Remove photo">&times;</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <button type="submit" class="btn-primary" style="width:auto; padding:14px 28px; margin-top:14px;"><?= $editingEvent ? 'Save changes' : 'Post event' ?></button>
      <?php if ($editingEvent): ?>
        <a href="events.php" style="margin-left:12px; font-size: 15px;">Cancel</a>
      <?php endif; ?>
    </form>
  </div>

<script>
let selectedEventPhotos = new DataTransfer();

function previewEventPhotos(input) {
  // Merge newly-picked files with whatever was already selected, so
  // choosing photos in more than one pass (or after removing one)
  // doesn't wipe out the earlier picks.
  Array.from(input.files || []).forEach(function (file) {
    selectedEventPhotos.items.add(file);
  });
  input.files = selectedEventPhotos.files;
  renderEventPhotoPreview();
}

function previewHeaderPhoto(input) {
  var box = document.getElementById('header-preview');
  var file = input.files[0];
  if (!file) {
    box.innerHTML = '';
    return;
  }
  var reader = new FileReader();
  reader.onload = function (e) {
    box.innerHTML = '<div class="header-photo-box"><img src="' + e.target.result +
      '" alt=""><span class="header-photo-badge">New header</span></div>';
  };
  reader.readAsDataURL(file);
}

function removeSelectedEventPhoto(index) {
  const kept = new DataTransfer();
  Array.from(selectedEventPhotos.files).forEach(function (file, i) {
    if (i !== index) kept.items.add(file);
  });
  selectedEventPhotos = kept;
  document.getElementById('photos').files = selectedEventPhotos.files;
  renderEventPhotoPreview();
}

function renderEventPhotoPreview() {
  const preview = document.getElementById('photos-preview');
  const countLabel = document.getElementById('photos-count');
  preview.innerHTML = '';
  const files = Array.from(selectedEventPhotos.files);
  if (files.length === 0) {
    countLabel.textContent = "You can select several photos at once - they'll all attach to this one post.";
    return;
  }
  countLabel.textContent = files.length + (files.length === 1 ? ' photo selected.' : ' photos selected - all will attach to this one post.');
  files.forEach(function (file, index) {
    const item = document.createElement('div');
    item.className = 'event-photo-preview-item';
    const img = document.createElement('img');
    img.src = URL.createObjectURL(file);
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'event-photo-preview-remove';
    removeBtn.title = 'Remove this photo';
    removeBtn.textContent = '\u00d7';
    removeBtn.onclick = function () { removeSelectedEventPhoto(index); };
    item.appendChild(img);
    item.appendChild(removeBtn);
    preview.appendChild(item);
  });
}
</script>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
