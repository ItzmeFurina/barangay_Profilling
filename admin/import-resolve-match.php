<?php
/**
 * AJAX endpoint: resolve a single import row's match during the import wizard
 * (Step 5 manual match / skip).
 *
 * Everything lives in $_SESSION['import_wizard'] - the wizard only writes its
 * parsed rows to `beneficiary_import_rows` at execute time, so a DB-backed
 * "row_id" lookup here could never succeed (the old version gated on a
 * session key nothing ever set, making the endpoint always return 400).
 * `row_id` is therefore the 0-based index into the session's normalized rows.
 *
 * Guard: admin only (staff cannot reach the import wizard's other steps).
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

header('Content-Type: application/json');

if (!admin_is_logged_in()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
if (admin_account_blocked()) {
    admin_clear_session();
    http_response_code(403);
    echo json_encode(['error' => 'Account is no longer active.']);
    exit;
}
if (admin_password_changed()) {
    admin_clear_session();
    http_response_code(403);
    echo json_encode(['error' => 'Password has been changed. Please sign in again.']);
    exit;
}
if (admin_role() !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Session expired. Please refresh the page.']);
    exit;
}

$rowId = (int) ($_POST['row_id'] ?? -1);
$action = $_POST['action'] ?? ''; // 'match' or 'skip'
$memberId = (int) ($_POST['member_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if ($rowId < 0 || !isset($_SESSION['import_wizard']['normalized'][$rowId])) {
    http_response_code(400);
    echo json_encode(['error' => 'No active import session for that row.']);
    exit;
}

$pdo = get_db_connection();

if ($action === 'match' && $memberId > 0) {
    $mStmt = $pdo->prepare(
        'SELECT member_id, first_name, last_name FROM household_members
         WHERE member_id = ? AND is_archived = FALSE'
    );
    $mStmt->execute([$memberId]);
    $member = $mStmt->fetch();

    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'Resident not found.']);
        exit;
    }

    $_SESSION['import_wizard']['normalized'][$rowId]['_match_status'] = 'matched';
    $_SESSION['import_wizard']['normalized'][$rowId]['_matched_member_id'] = $memberId;
    if ($notes !== '') {
        $_SESSION['import_wizard']['normalized'][$rowId]['_review_notes'] = $notes;
    }

    log_activity($pdo, $_SESSION['staff_user_id'], "Manually matched import row #" . ($rowId + 1) . " to {$member['first_name']} {$member['last_name']}", 'update');

    echo json_encode([
        'success' => true,
        'match_status' => 'matched',
        'member_name' => $member['first_name'] . ' ' . $member['last_name'],
    ]);
    exit;
}

if ($action === 'skip') {
    $_SESSION['import_wizard']['normalized'][$rowId]['_match_status'] = 'not_found';
    if ($notes !== '') {
        $_SESSION['import_wizard']['normalized'][$rowId]['_review_notes'] = $notes;
    }

    log_activity($pdo, $_SESSION['staff_user_id'], "Skipped import row #" . ($rowId + 1) . " (resident not found)", 'update');

    echo json_encode([
        'success' => true,
        'match_status' => 'not_found',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
