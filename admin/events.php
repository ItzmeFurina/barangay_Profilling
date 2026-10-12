<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin_login();
$activePage = 'events';

$pdo = get_db_connection();

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'upcoming', 'past'], true)) {
    $filter = 'all';
}

$photoDir = __DIR__ . '/../resident/assets/events/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete_event') {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $eventId = (int) ($_POST['event_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT photo_path FROM event_photos WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $photosToRemove = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare('DELETE FROM events WHERE event_id = ?')->execute([$eventId]);

        foreach ($photosToRemove as $photoPath) {
            $fullPath = $photoDir . basename($photoPath);
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }

        log_activity($pdo, $_SESSION['staff_user_id'], "Deleted event #{$eventId}", 'delete');
        flash('success', 'Event removed.');
        redirect('events.php');
    }
    flash('error', 'Your session expired. Please try again.');
    redirect('events.php');
}

$events = $pdo->query(
    "SELECT e.*, u.full_name AS created_by_name
     FROM events e LEFT JOIN users u ON u.user_id = e.created_by
     ORDER BY (e.event_date >= CURDATE()) DESC,
              CASE WHEN e.event_date >= CURDATE() THEN e.event_date END ASC,
              CASE WHEN e.event_date < CURDATE() THEN e.event_date END DESC"
)->fetchAll();

$totalEvents = count($events);
$upcomingEventsCount = 0;
foreach ($events as $ev) {
    if ($ev['event_date'] >= date('Y-m-d')) {
        $upcomingEventsCount++;
    }
}
$pastEventsCount = $totalEvents - $upcomingEventsCount;

// Pull every photo for every event in one query and group them in PHP,
// rather than one query per event in the loop below.
$photosByEvent = [];
if (!empty($events)) {
    $ids = array_column($events, 'event_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM event_photos WHERE event_id IN ($placeholders) ORDER BY display_order ASC, photo_id ASC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $photo) {
        $photosByEvent[(int) $photo['event_id']][] = $photo;
    }
}

// Signature used by the live-refresh poller: same shape (and therefore
// same md5) as the shared/event_feed_json.php endpoint.
$feedPayload = [];
foreach ($events as $ev) {
    $feedPayload[] = [
        'event_id'        => (int) $ev['event_id'],
        'title'           => $ev['title'],
        'description'     => $ev['description'],
        'event_date'      => $ev['event_date'],
        'created_by_name' => $ev['created_by_name'],
        'status'          => $ev['event_date'] >= date('Y-m-d') ? 'upcoming' : 'past',
        'photos'          => array_map(function ($p) {
            return $p['photo_path'];
        }, $photosByEvent[(int) $ev['event_id']] ?? []),
    ];
}
$signature = md5(json_encode($feedPayload));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Events - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
/* Kept inline (in addition to assets/css/style.css) so this page's
   feed/modal always render and behave correctly even if the shared
   stylesheet is out of date or fails to load on a given deployment. */
.badge-open {
  display: inline-block;
  background: #FDF0DA;
  color: #7A4E10;
  font-size: 12.5px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
  white-space: nowrap;
}
.badge-past {
  display: inline-block;
  background: #E7EBF1;
  color: #3C5470;
  font-size: 12.5px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
  white-space: nowrap;
}
.events-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.event-filter-tabs { display: flex; gap: 6px; }
.event-filter-tab {
  border: 1px solid var(--border, #D6E1EE);
  background: #fff;
  color: var(--muted, #3C5470);
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  padding: 14px 28px;
  border-radius: 28px;
  cursor: pointer;
  min-width: 120px;
  line-height: normal;
  white-space: nowrap;
  transition: background 0.25s, color 0.25s;
}
.event-filter-tab:hover {
  background: var(--navy, #0B2E5C);
  color: #fff;
}
.event-filter-tab.is-active {
  background: var(--navy, #0B2E5C);
  border-color: var(--navy, #0B2E5C);
  color: #fff;
}
.event-filter-tab.is-active {
  background: var(--navy, #0B2E5C);
  border-color: var(--navy, #0B2E5C);
  color: #fff;
}
.event-feed {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 16px;
  margin-top: 16px;
}
.event-card {
  background: #fff;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.85), 0 0 0 1px rgba(214, 225, 238, 0.6), 0 12px 32px -18px rgba(11, 46, 92, 0.2);
  transition: box-shadow 0.6s cubic-bezier(0.32, 0.72, 0, 1), transform 0.6s cubic-bezier(0.32, 0.72, 0, 1);
}
.event-card:hover {
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 0 0 1px #B8C9DD, 0 24px 48px -20px rgba(11, 46, 92, 0.28);
  transform: translateY(-2px);
}
.event-card-open {
  display: block;
  width: 100%;
  padding: 0;
  border: none;
  background: none;
  text-align: left;
  cursor: pointer;
  font-family: inherit;
  color: inherit;
}
.event-card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 12px 14px 0;
}
.event-card-title-row h2, .event-card-title-row h3 { margin: 0; font-size: 19px; }
.event-modal .event-card-title-row h2 { font-size: 23px; }
.event-card-photo-wrap { position: relative; margin-top: 18px; }
.event-card-photo {
  display: block;
  width: 100%;
  height: 150px;
  object-fit: cover;
}
.event-card-photo-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F4F6F9;
  color: var(--muted, #3C5470);
  font-size: 14px;
}
.event-card-date-badge {
  position: absolute;
  left: 10px;
  bottom: 10px;
  background: rgba(11, 46, 92, 0.85);
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 4px;
}
.event-card-photo-count {
  position: absolute;
  right: 10px;
  bottom: 10px;
  background: rgba(11, 46, 92, 0.78);
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.18);
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 6px;
}
.event-card-meta { padding: 10px 14px 12px; color: var(--muted, #3C5470); font-size: 15px; }
.event-card-actions {
  padding: 8px 14px 12px;
  border-top: 1px solid var(--border, #D6E1EE);
  font-size: 14px;
}
.event-modal-backdrop {
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(10, 27, 51, 0.6);
  z-index: 999;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.event-modal-backdrop.is-open {
  display: flex;
  animation: eventBackdropIn 0.4s cubic-bezier(0.32, 0.72, 0, 1);
}
@keyframes eventBackdropIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
.event-modal {
  background: #fff;
  border-radius: 16px;
  max-width: 900px;
  width: 100%;
  max-height: 88vh;
  overflow-y: auto;
  position: relative;
  box-shadow: 0 24px 60px rgba(5, 18, 38, 0.35);
  animation: eventModalIn 0.55s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes eventModalIn {
  from { opacity: 0; transform: translateY(14px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.event-modal-close {
  position: absolute;
  top: 14px;
  right: 14px;
  z-index: 2;
  width: 34px;
  height: 34px;
  border: none;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.92);
  font-size: 22px;
  line-height: 1;
  color: var(--navy, #0B2E5C);
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(6, 14, 28, 0.25);
  transition: background 0.4s cubic-bezier(0.32, 0.72, 0, 1), transform 0.4s cubic-bezier(0.32, 0.72, 0, 1);
}
.event-modal-close:hover { background: #fff; transform: scale(1.08); }

/* Hero banner - cover photo with the title and a date chip overlaid */
.event-modal-hero {
  position: relative;
  height: 300px;
  overflow: hidden;
  background: linear-gradient(135deg, var(--navy, #0B2E5C), var(--blue, #1B5FA8));
}
.event-modal-hero img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.event-modal-hero-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: flex-end;
  gap: 16px;
  padding: 22px 26px;
  background: linear-gradient(to top, rgba(6, 20, 42, 0.82) 0%, rgba(6, 20, 42, 0.35) 55%, transparent 100%);
}
.event-modal-date-chip {
  flex-shrink: 0;
  width: 62px;
  height: 62px;
  border-radius: 12px;
  background: var(--gold, #E8A93D);
  color: var(--gold-text, #4A2F04);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 14px rgba(6, 14, 28, 0.3);
}
.event-modal-date-day { font-size: 26px; font-weight: 700; line-height: 1; font-family: 'Fraunces', Georgia, serif; }
.event-modal-date-month { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }
.event-modal-hero-info { min-width: 0; }
.event-modal-hero-info h2 {
  margin: 6px 0 0;
  font-size: 30px;
  color: #fff;
  line-height: 1.15;
  text-shadow: 0 2px 8px rgba(6, 20, 42, 0.45);
}
.event-modal-hero-info .badge-open,
.event-modal-hero-info .badge-past { margin-bottom: 0; }

/* Body: full date + caption + gallery */
.event-modal-body { padding: 20px 26px 28px; }
.event-modal-full-date {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--muted, #3C5470);
  font-size: 16px;
  font-weight: 500;
  margin: 0 0 14px;
}
.event-modal-caption { font-size: 18px; line-height: 1.6; margin-top: 4px; white-space: pre-line; color: var(--ink, #16233A); }
.event-modal-gallery {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 10px;
  margin-top: 28px;
}
.event-modal-gallery img {
  width: 100%;
  height: 160px;
  object-fit: cover;
  border-radius: 8px;
  cursor: zoom-in;
  transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1), box-shadow 0.5s cubic-bezier(0.32, 0.72, 0, 1);
  box-shadow: 0 1px 3px rgba(6, 14, 28, 0.12);
}
.event-modal-gallery img:hover {
  transform: scale(1.02);
  box-shadow: 0 6px 18px rgba(11, 46, 92, 0.25);
}

/* Top statistics cards - styled exactly like the resident portal's event
   stats cards (see resident/events.php) so both portals look identical. */
.stat-grid-compact {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  align-items: stretch;
}
.stat-grid-compact > a { display: flex; }
.stat-grid-compact > a .stat-card-compact { flex: 1; }
.stat-card-compact {
  background: var(--white, #fff);
  border: 1px solid #D6E1EE;
  border-radius: 12px;
  padding: 12px 14px;
  border-top: 3px solid var(--navy, #0B2E5C);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 3px;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.85), 0 0 0 1px rgba(214, 225, 238, 0.6), 0 12px 32px -18px rgba(11, 46, 92, 0.2);
}
.stat-card-compact .stat-number { font-size: 22px; font-weight: 600; color: var(--navy, #0B2E5C); line-height: 1.1; }
.stat-card-compact .stat-label { font-size: 13px; color: #3C5470; margin-top: 2px; }
.stat-accent-blue { border-top-color: var(--blue, #1B5FA8); }
.stat-accent-gold { border-top-color: var(--gold, #E8A93D); }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>Events</h1>

  <div class="stat-grid-compact" style="margin-top:18px;">
    <a href="events.php" style="text-decoration:none;">
      <div class="stat-card-compact stat-accent-blue"><div class="stat-number" id="stat-total"><?= $totalEvents ?></div><div class="stat-label">Total events</div></div>
    </a>
    <a href="events.php?filter=upcoming" style="text-decoration:none;">
      <div class="stat-card-compact stat-accent-gold"><div class="stat-number" id="stat-upcoming"><?= $upcomingEventsCount ?></div><div class="stat-label">Upcoming</div></div>
    </a>
    <a href="events.php?filter=past" style="text-decoration:none;">
      <div class="stat-card-compact"><div class="stat-number" id="stat-past"><?= $pastEventsCount ?></div><div class="stat-label">Past</div></div>
    </a>
  </div>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>

  <div class="admin-section">
    <div class="events-head">
      <h2 style="margin:0;">All events (<span id="feed-count"><?= count($events) ?></span>)</h2>
      <div style="display:flex; gap:10px; align-items:center;">
        <div class="event-filter-tabs">
          <button type="button" class="event-filter-tab<?= $filter === 'all' ? ' is-active' : '' ?>" data-filter="all">All</button>
          <button type="button" class="event-filter-tab<?= $filter === 'upcoming' ? ' is-active' : '' ?>" data-filter="upcoming">Upcoming</button>
          <button type="button" class="event-filter-tab<?= $filter === 'past' ? ' is-active' : '' ?>" data-filter="past">Past</button>
        </div>
        <a href="event-create.php" class="btn-primary" style="padding:8px 16px; font-size: 15px; text-decoration:none;">+ Create event</a>
      </div>
    </div>
    <?php if (empty($events)): ?>
      <p class="muted-note">No events yet - create the first one.</p>
    <?php else: ?>
      <div class="event-feed" id="event-feed">
        <?php foreach ($events as $ev):
          $eid = (int) $ev['event_id'];
          $photos = $photosByEvent[$eid] ?? [];
          $cover = $photos[0]['photo_path'] ?? null;
          $isUpcoming = $ev['event_date'] >= date('Y-m-d');
          $status = $isUpcoming ? 'upcoming' : 'past';
          $hidden = $filter !== 'all' && $filter !== $status;
        ?>
          <article class="event-card" data-status="<?= $status ?>"<?= $hidden ? ' style="display:none;"' : '' ?>>
            <button type="button" class="event-card-open" onclick="openEventModal(<?= $eid ?>)">
              <div class="event-card-title-row">
                <h3><?= e($ev['title']) ?></h3>                <?php if ($isUpcoming): ?><span class="badge-open">Upcoming</span>
                <?php else: ?><span class="badge-past">Past</span><?php endif; ?>
              </div>
              <div class="event-card-photo-wrap">
                <?php if ($cover): ?>
                  <img class="event-card-photo" src="../resident/assets/events/<?= e($cover) ?>" alt="">
                <?php else: ?>
                  <div class="event-card-photo event-card-photo-empty">No photo</div>
                <?php endif; ?>
                <span class="event-card-date-badge"><?= e(date('M j', strtotime($ev['event_date']))) ?></span>
                <?php if (count($photos) > 1): ?>
                  <span class="event-card-photo-count"><?= count($photos) ?> photos</span>
                <?php endif; ?>
              </div>
              <div class="event-card-meta">
                <?= e(date('l, F j, Y', strtotime($ev['event_date']))) ?>
                <?php if (!empty($ev['created_by_name'])): ?> &middot; by <?= e($ev['created_by_name']) ?><?php endif; ?>
              </div>
            </button>
            <div class="event-card-actions">
              <a href="event-create.php?edit=<?= $eid ?>">Edit</a>
              &nbsp;|&nbsp;
              <form method="POST" class="inline-form" data-confirm data-confirm-title="Delete this event?" data-confirm-message="The event and all its photos are removed permanently. This cannot be undone." data-confirm-label="Delete event" data-confirm-danger style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="form" value="delete_event">
                <input type="hidden" name="event_id" value="<?= $eid ?>">
                <button type="submit" class="btn-archive" style="padding:4px 10px; color:var(--danger); border-color:var(--danger);">Delete</button>
              </form>
            </div>
          </article>

          <div class="event-modal-backdrop" id="event-modal-<?= $eid ?>" onclick="if (event.target === this) closeEventModal(<?= $eid ?>)">
            <div class="event-modal">
              <button type="button" class="event-modal-close" onclick="closeEventModal(<?= $eid ?>)">&times;</button>
              <div class="event-modal-hero">
                <?php if ($cover): ?>
                  <img src="../resident/assets/events/<?= e($cover) ?>" alt="">
                <?php endif; ?>
                <div class="event-modal-hero-overlay">
                  <div class="event-modal-date-chip">
                    <span class="event-modal-date-day"><?= e(date('j', strtotime($ev['event_date']))) ?></span>
                    <span class="event-modal-date-month"><?= e(date('M', strtotime($ev['event_date']))) ?></span>
                  </div>
                  <div class="event-modal-hero-info">
                    <?php if ($isUpcoming): ?><span class="badge-open">Upcoming</span>
                    <?php else: ?><span class="badge-past">Past</span><?php endif; ?>
                    <h2><?= e($ev['title']) ?></h2>
                  </div>
                </div>
              </div>
              <div class="event-modal-body">
                <p class="event-modal-full-date">
                  <?= e(date('l, F j, Y', strtotime($ev['event_date']))) ?>
                  <?php if (!empty($ev['created_by_name'])): ?> &middot; posted by <?= e($ev['created_by_name']) ?><?php endif; ?>
                </p>
                <?php if (!empty($ev['description'])): ?>
                  <p class="event-modal-caption"><?= nl2br(e($ev['description'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($photos)): ?>
                  <div class="event-modal-gallery">
                    <?php foreach ($photos as $photo): ?>
                      <img src="../resident/assets/events/<?= e($photo['photo_path']) ?>" alt="" onclick="window.open(this.src, '_blank')">
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<script>
function openEventModal(id) {
  document.getElementById('event-modal-' + id).classList.add('is-open');
  document.body.style.overflow = 'hidden';
}
function closeEventModal(id) {
  document.getElementById('event-modal-' + id).classList.remove('is-open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.event-modal-backdrop.is-open').forEach(function (el) {
      el.classList.remove('is-open');
    });
    document.body.style.overflow = '';
  }
});

// Filter tabs - All / Upcoming / Past
document.querySelectorAll('.event-filter-tab').forEach(function (tab) {
  tab.addEventListener('click', function () {
    document.querySelectorAll('.event-filter-tab').forEach(function (t) {
      t.classList.remove('is-active');
    });
    tab.classList.add('is-active');
    currentFilter = tab.getAttribute('data-filter');
    applyFilter();
  });
});

// Live refresh - polls the shared JSON feed every 10 seconds; when the
// feed changes (event created/edited/deleted anywhere), the stats, feed
// and modals are re-rendered in place, keeping the current filter and
// any open modal.
var EVENTS_BASE = '../resident/assets/events/';
var EVENTS_SIGNATURE = '<?= $signature ?>';
var CSRF_TOKEN = '<?= csrf_token() ?>';
var currentFilter = '<?= e($filter) ?>';

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function eventDate(ev) {
  return new Date(ev.event_date + 'T00:00:00');
}
function eventCardHTML(ev) {
  var d = eventDate(ev);
  var cover = ev.photos[0] || null;
  var badge = ev.status === 'upcoming'
    ? '<span class="badge-open">Upcoming</span>'
    : '<span class="badge-past">Past</span>';
  var coverHtml = cover
    ? '<img class="event-card-photo" src="' + EVENTS_BASE + esc(cover) + '" alt="">'
    : '<div class="event-card-photo event-card-photo-empty">No photo</div>';
  var countBadge = ev.photos.length > 1
    ? '<span class="event-card-photo-count">' + ev.photos.length + ' photos</span>' : '';
  var fullDate = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  var shortDate = d.toLocaleDateString('en-US', { month: 'short' }).replace('.', '') + ' ' + d.getDate();
  var author = ev.created_by_name ? ' &middot; by ' + esc(ev.created_by_name) : '';
  return '<article class="event-card" data-status="' + ev.status + '">' +
    '<button type="button" class="event-card-open" onclick="openEventModal(' + ev.event_id + ')">' +
      '<div class="event-card-title-row"><h3>' + esc(ev.title) + '</h3>' + badge + '</div>' +
      '<div class="event-card-photo-wrap">' + coverHtml +
        '<span class="event-card-date-badge">' + shortDate + '</span>' + countBadge +
      '</div>' +
      '<div class="event-card-meta">' + fullDate + author + '</div>' +
    '</button>' +
    '<div class="event-card-actions">' +
      '<a href="event-create.php?edit=' + ev.event_id + '">Edit</a> &nbsp;|&nbsp; ' +
      '<form method="POST" class="inline-form" data-confirm data-confirm-title="Delete this event?" data-confirm-message="The event and all its photos are removed permanently. This cannot be undone." data-confirm-label="Delete event" data-confirm-danger style="display:inline;">' +
        '<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">' +
        '<input type="hidden" name="form" value="delete_event">' +
        '<input type="hidden" name="event_id" value="' + ev.event_id + '">' +
        '<button type="submit" class="btn-archive" style="padding:4px 10px; color:var(--danger); border-color:var(--danger);">Delete</button>' +
      '</form>' +
    '</div></article>';
}
function eventModalHTML(ev) {
  var d = eventDate(ev);
  var cover = ev.photos[0] || null;
  var badge = ev.status === 'upcoming'
    ? '<span class="badge-open">Upcoming</span>'
    : '<span class="badge-past">Past</span>';
  var fullDate = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  var month = d.toLocaleDateString('en-US', { month: 'short' }).replace('.', '').toUpperCase();
  var author = ev.created_by_name ? ' &middot; posted by ' + esc(ev.created_by_name) : '';
  var photos = ev.photos.map(function (p) {
    return '<img src="' + EVENTS_BASE + esc(p) + '" alt="" onclick="window.open(this.src, \'_blank\')">';
  }).join('');
  return '<div class="event-modal-backdrop" id="event-modal-' + ev.event_id + '" onclick="if (event.target === this) closeEventModal(' + ev.event_id + ')">' +
    '<div class="event-modal">' +
      '<button type="button" class="event-modal-close" onclick="closeEventModal(' + ev.event_id + ')">&times;</button>' +
      '<div class="event-modal-hero">' +
        (cover ? '<img src="' + EVENTS_BASE + esc(cover) + '" alt="">' : '') +
        '<div class="event-modal-hero-overlay">' +
          '<div class="event-modal-date-chip">' +
            '<span class="event-modal-date-day">' + d.getDate() + '</span>' +
            '<span class="event-modal-date-month">' + month + '</span>' +
          '</div>' +
          '<div class="event-modal-hero-info">' + badge + '<h2>' + esc(ev.title) + '</h2></div>' +
        '</div>' +
      '</div>' +
      '<div class="event-modal-body">' +
        '<p class="event-modal-full-date">' + fullDate + author + '</p>' +
        (ev.description ? '<p class="event-modal-caption">' + esc(ev.description).replace(/\n/g, '<br>') + '</p>' : '') +
        (photos ? '<div class="event-modal-gallery">' + photos + '</div>' : '') +
      '</div>' +
    '</div></div>';
}
function applyFilter() {
  document.querySelectorAll('.event-card').forEach(function (card) {
    var show = currentFilter === 'all' || card.getAttribute('data-status') === currentFilter;
    card.style.display = show ? '' : 'none';
  });
}
function renderFeed(events) {
  var openIds = [];
  document.querySelectorAll('.event-modal-backdrop.is-open').forEach(function (el) {
    openIds.push(el.id.replace('event-modal-', ''));
  });
  var upcoming = 0;
  events.forEach(function (ev) { if (ev.status === 'upcoming') upcoming++; });
  document.getElementById('stat-total').textContent = events.length;
  document.getElementById('stat-upcoming').textContent = upcoming;
  document.getElementById('stat-past').textContent = events.length - upcoming;
  document.getElementById('feed-count').textContent = events.length;
  document.getElementById('event-feed').innerHTML = events.map(function (ev) {
    return eventCardHTML(ev) + eventModalHTML(ev);
  }).join('');
  applyFilter();
  openIds.forEach(function (id) {
    var el = document.getElementById('event-modal-' + id);
    if (el) {
      el.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }
  });
}
function pollEvents() {
  fetch('../shared/event_feed_json.php', { headers: { 'X-Requested-With': 'fetch' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (!data || data.signature === EVENTS_SIGNATURE) return;
      EVENTS_SIGNATURE = data.signature;
      renderFeed(data.events || []);
    })
    .catch(function () {});
}
setInterval(pollEvents, 10000);
</script>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
