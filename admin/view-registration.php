<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin();
$activePage = 'accounts';

$userId = (int) ($_GET['id'] ?? 0);
$pdo = get_db_connection();

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.email, u.contact_number, u.account_status, u.created_at,
            r.resident_id, r.household_id, r.household_member_id, r.census_completed
     FROM users u
     LEFT JOIN residents r ON r.user_id = u.user_id
     WHERE u.user_id = ? AND u.role = 'resident'"
);
$stmt->execute([$userId]);
$account = $stmt->fetch();

if (!$account) {
    http_response_code(404);
    die('Registration not found.');
}

$household = null;
$members = [];
if ($account['household_id'] || $account['household_member_id']) {
    $hid = $account['household_id'];
    if (!$hid && $account['household_member_id']) {
        $hstmt = $pdo->prepare('SELECT household_id FROM household_members WHERE member_id = ?');
        $hstmt->execute([$account['household_member_id']]);
        $hid = (int) $hstmt->fetchColumn();
    }

    if ($hid) {
        $stmt = $pdo->prepare(
            'SELECT house_number, street, barangay, city, old_address, monthly_income
             FROM households WHERE household_id = ?'
        );
        $stmt->execute([$hid]);
        $household = $stmt->fetch(PDO::FETCH_ASSOC);
        $household['household_id'] = $hid;

        $stmt = $pdo->prepare(
            'SELECT member_id, first_name, middle_name, last_name, suffix, birthdate, sex,
                    civil_status, citizenship, occupation, is_pwd, is_solo_parent, is_ofw,
                    is_osy, is_ip, is_unemployed, is_employed, is_student, is_household_head,
                    linked_user_id
             FROM household_members
             WHERE household_id = ? AND is_archived = FALSE
             ORDER BY (member_id = ?) DESC, member_id ASC'
        );
        $stmt->execute([$hid, $account['household_member_id']]);
        $members = $stmt->fetchAll();
    }
}

$stmt = $pdo->prepare(
    'SELECT document_id, document_type, verified_status, rejection_reason, uploaded_at
     FROM verification_documents
     WHERE user_id = ?
     ORDER BY document_id DESC'
);
$stmt->execute([$userId]);
$docs = $stmt->fetchAll();

$statusLabel = [
    'active'   => 'Active',
    'pending'  => 'Pending approval',
    'rejected' => 'Rejected',
    'archived' => 'Archived',
];

$statusTone = [
    'active'   => 'badge-active',
    'pending'  => 'badge-pending',
    'rejected' => 'badge-rejected',
    'archived' => 'badge-archived',
];

$docBadge = [
    'pending'  => 'badge-pending',
    'approved' => 'badge-active',
    'rejected' => 'badge-rejected',
];

$isRegisterOrigin = !empty($account['census_completed']);

function vr_member_badges(array $m): string {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Review registration - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <p style="margin-bottom: 6px;"><a href="accounts.php">&larr; Back to accounts</a></p>

  <h1>Review registration</h1>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>

  <div class="admin-section">
    <div class="member-grid" style="grid-template-columns: 1fr auto; align-items: center; row-gap: 8px; margin-bottom: 14px;">
      <div>
        <span class="muted-note"><?= $isRegisterOrigin ? 'Registered online (full census submission)' : 'Create-account (matched to existing record)' ?></span>
        <h2 style="margin: 4px 0 0;"><?= e($account['full_name']) ?></h2>
      </div>
      <span class="badge <?= $statusTone[$account['account_status']] ?? '' ?>"><?= e($statusLabel[$account['account_status']] ?? ucfirst($account['account_status'])) ?></span>
    </div>

    <div class="member-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); row-gap: 8px; column-gap: 18px;">
      <div><span class="muted-note">Contact number</span><br><strong><?= e($account['contact_number'] ?: '&mdash;') ?></strong></div>
      <div><span class="muted-note">Email</span><br><strong><?= e($account['email']) ?></strong></div>
      <div><span class="muted-note">Submitted</span><br><strong><?= e(date('F j, Y g:i A', strtotime($account['created_at']))) ?></strong></div>
    </div>
  </div>

  <?php if ($household): ?>
  <div class="admin-section">
    <h2>Household &amp; members</h2>
    <div class="member-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); row-gap: 8px; column-gap: 18px; margin-bottom: 14px;">
      <div><span class="muted-note">Address</span><br><strong><?= e(trim(($household['house_number'] ?? '') . ' ' . $household['street'] . ', ' . $household['barangay'] . ', ' . $household['city'])) ?></strong></div>
      <div><span class="muted-note">Monthly income</span><br><strong><?= $household['monthly_income'] !== null ? e('&#8369;' . number_format((float) $household['monthly_income'], 2)) : '<span class="muted-note">Not provided</span>' ?></strong></div>
    </div>

    <div class="table-scroll">
    <table class="admin-table">
      <thead>
        <tr><th>Name</th><th>Birthdate</th><th>Sex</th><th>Civil status</th><th>Occupation</th><th>Flags</th><th>Role</th></tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
          <tr>
            <td>
              <?= e(trim(($m['first_name'] ?? '') . ' ' . ($m['middle_name'] ?? '') . ' ' . ($m['last_name'] ?? '') . ($m['suffix'] ? ', ' . $m['suffix'] : ''))) ?>
              <?php if (!empty($m['linked_user_id']) && (int) $m['linked_user_id'] === $userId): ?>
                <span class="badge badge-active" title="This applicant is linked to this record">applicant</span>
              <?php endif; ?>
            </td>
            <td><?= e($m['birthdate'] ?? '&mdash;') ?></td>
            <td><?= e($m['sex'] ?? '&mdash;') ?></td>
            <td><?= e($m['civil_status'] ?? '&mdash;') ?></td>
            <td><?= e($m['occupation'] ?? '&mdash;') ?></td>
            <td><?= vr_member_badges($m) ?></td>
            <td><?= !empty($m['is_household_head']) ? 'Head' : 'Member' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="admin-section">
    <h2>Verification documents (<?= count($docs) ?>)</h2>
    <?php if (empty($docs)): ?>
      <p class="muted-note">No verification documents found.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="admin-table">
      <thead>
        <tr><th>Type</th><th>Uploaded</th><th>Status</th><th>Rejection reason</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
          <tr>
            <td><?= e($d['document_type']) ?></td>
            <td><?= e(date('M j, Y g:i A', strtotime($d['uploaded_at']))) ?></td>
            <td><span class="badge <?= $docBadge[$d['verified_status']] ?? '' ?>"><?= e(ucfirst($d['verified_status'])) ?></span></td>
            <td><?= $d['rejection_reason'] ? e($d['rejection_reason']) : '<span class="muted-note">&mdash;</span>' ?></td>
            <td><a href="view-document.php?id=<?= (int) $d['document_id'] ?>" target="_blank">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($account['account_status'] === 'pending'): ?>
  <div class="admin-section">
    <h2>Decision</h2>
    <form method="POST" action="accounts.php">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
      <p class="muted-note">Approving activates the account, links it to the matched resident record, and runs benefit eligibility. Rejecting requires a reason - the applicant will see it and can correct and resubmit.</p>
      <div class="member-grid" style="grid-template-columns: 1fr; row-gap: 6px; margin: 10px 0;">
        <label for="reason">Rejection reason</label>
        <input type="text" id="reason" name="reason" class="reason-input" maxlength="200" placeholder="Required when rejecting" style="width: 100%; max-width: 420px;">
      </div>
      <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button type="submit" name="action" value="approve" class="btn-approve">Approve account</button>
        <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
      </div>
    </form>
  </div>
  <?php else: ?>
    <div class="admin-section">
      <p class="muted-note">This registration is not awaiting a decision. Go back to <a href="accounts.php">accounts</a> to manage it.</p>
    </div>
  <?php endif; ?>

<script>
(function () {
  var form = document.querySelector('form[action="accounts.php"]');
  var reason = form && form.querySelector('input[name="reason"]');
  var rejectBtn = form && form.querySelector('button[value="reject"]');
  if (!form || !reason || !rejectBtn) return;
  form.addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.value === 'reject' && !reason.value.trim()) {
      e.preventDefault();
      alert('A rejection reason is required so the applicant can fix their submission.');
      reason.focus();
    }
  });
})();
</script>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>