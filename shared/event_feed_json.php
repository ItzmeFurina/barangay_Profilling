<?php
/**
 * Lightweight JSON feed of all events (with photos), shared by the
 * resident and staff event pages. Each page polls this endpoint and
 * re-renders its feed whenever the signature changes, so an event
 * created/edited/deleted on one account shows up everywhere else
 * without a manual page reload.
 *
 * Accepts either a resident session or an admin/staff session (the two
 * portals use different session cookies on purpose). Returns 403 for
 * anyone else.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security_headers.php';

$isResident = false;
$isStaff    = false;

if (!empty($_COOKIE['resident_session'])) {
    session_name('resident_session');
    session_start();
    $isResident = !empty($_SESSION['resident_id']);
    session_write_close();
}
if (!$isResident && !empty($_COOKIE['admin_session'])) {
    session_name('admin_session');
    session_start();
    $isStaff = !empty($_SESSION['staff_user_id']);
    session_write_close();
}

if (!$isResident && !$isStaff) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $pdo = get_db_connection();

    $events = $pdo->query(
        "SELECT e.event_id, e.title, e.description, e.event_date, u.full_name AS created_by_name
         FROM events e LEFT JOIN users u ON u.user_id = e.created_by
         ORDER BY (e.event_date >= CURDATE()) DESC,
                  CASE WHEN e.event_date >= CURDATE() THEN e.event_date END ASC,
                  CASE WHEN e.event_date < CURDATE() THEN e.event_date END DESC"
    )->fetchAll();

    $photosByEvent = [];
    if (!empty($events)) {
        $ids = array_column($events, 'event_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT event_id, photo_path FROM event_photos
             WHERE event_id IN ($placeholders)
             ORDER BY display_order ASC, photo_id ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $photo) {
            $photosByEvent[(int) $photo['event_id']][] = $photo['photo_path'];
        }
    }
} catch (Throwable $e) {
    error_log('event_feed_json.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal_error']);
    exit;
}

// Legacy imports can hold bytes that are not valid UTF-8, which would break
// the whole response when json_encode refuses them. Coerce every free-text
// field back to valid UTF-8 (replacing stray bytes) before building payload.
$clean = static function (?string $s): ?string {
    if ($s === null) {
        return null;
    }
    $fixed = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    return $fixed === false ? '' : $fixed;
};

$payload = [];
foreach ($events as $ev) {
    $payload[] = [
        'event_id'        => (int) $ev['event_id'],
        'title'           => $clean($ev['title']),
        'description'     => $clean($ev['description']),
        'event_date'      => (string) $ev['event_date'],
        'created_by_name' => $clean($ev['created_by_name']),
        'status'          => $ev['event_date'] >= date('Y-m-d') ? 'upcoming' : 'past',
        'photos'          => $photosByEvent[(int) $ev['event_id']] ?? [],
    ];
}

$json = json_encode([
    'signature' => md5(json_encode($payload)),
    'events'    => $payload,
]);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['error' => 'internal_error']);
    exit;
}
echo $json;
