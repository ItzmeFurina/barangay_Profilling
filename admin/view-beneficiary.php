<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_admin();
no_cache();
$activePage = 'beneficiaries';

$pdo = get_db_connection();

$beneficiaryId = (int) ($_GET['id'] ?? 0);
if ($beneficiaryId <= 0) {
    redirect('beneficiaries.php');
}

// ── Load beneficiary + member + household + program ────────────────────
$stmt = $pdo->prepare(
    "SELECT b.beneficiary_id, b.member_id, b.program_id, b.claim_status,
            b.source_type, b.qualification_snapshot,
            b.qualified_at, b.applied_at, b.reviewed_by, b.reviewed_at,
            b.rejection_reason, b.applicant_full_name, b.applicant_contact_number,
            b.id_document_path, b.claim_status, b.pickup_status,
            hm.first_name, hm.last_name, hm.middle_name, hm.suffix,
            hm.birthdate, hm.sex, hm.civil_status, hm.citizenship,
            hm.occupation, hm.is_pwd, hm.is_solo_parent, hm.is_ofw,
            hm.is_osy, hm.is_ip, hm.is_unemployed, hm.is_employed,
            hm.is_student, hm.is_household_head, hm.linked_user_id, hm.photo_path,
            h.household_id, h.house_number, h.street, h.barangay, h.city,
            h.old_address, h.monthly_income, h.no_of_members,
            p.name AS program_name, p.description AS program_description,
            p.program_type, p.category, p.provider, p.status AS program_status,
            p.start_date, p.end_date, p.application_deadline, p.slot_limit,
            p.application_mode, p.release_period,
            reviewer.full_name AS reviewer_name
     FROM beneficiaries b
     JOIN household_members hm ON hm.member_id = b.member_id
     JOIN households h ON h.household_id = hm.household_id
     JOIN programs p ON p.program_id = b.program_id
     LEFT JOIN users reviewer ON reviewer.user_id = b.reviewed_by
     WHERE b.beneficiary_id = ?"
);
$stmt->execute([$beneficiaryId]);
$b = $stmt->fetch();

if (!$b) {
    flash('error', 'Beneficiary not found.');
    redirect('beneficiaries.php');
}

// ── Load all household members ─────────────────────────────────────────
$membersStmt = $pdo->prepare(
    "SELECT member_id, first_name, middle_name, last_name, suffix,
            birthdate, sex, civil_status, citizenship, occupation,
            is_pwd, is_solo_parent, is_ofw, is_osy, is_ip,
            is_unemployed, is_employed, is_student, is_household_head,
            linked_user_id, photo_path
     FROM household_members
     WHERE household_id = ? AND is_archived = FALSE
     ORDER BY (member_id = ?) DESC, is_household_head DESC, member_id ASC"
);
$membersStmt->execute([(int) $b['household_id'], (int) $b['member_id']]);
$householdMembers = $membersStmt->fetchAll();

// ── Load program criteria ──────────────────────────────────────────────
$criteriaStmt = $pdo->prepare(
    "SELECT * FROM program_criteria WHERE program_id = ? LIMIT 1"
);
$criteriaStmt->execute([(int) $b['program_id']]);
$criteria = $criteriaStmt->fetch();

// ── Load required documents for this program ───────────────────────────
$docReqStmt = $pdo->prepare(
    "SELECT document_id, label, files_required FROM program_documents
     WHERE program_id = ? ORDER BY display_order ASC, document_id ASC"
);
$docReqStmt->execute([(int) $b['program_id']]);
$docRequirements = $docReqStmt->fetchAll();

// ── Load submitted benefit documents ───────────────────────────────────
$docStmt = $pdo->prepare(
    "SELECT benefit_document_id, label, file_path, uploaded_at
     FROM benefit_documents WHERE beneficiary_id = ?
     ORDER BY benefit_document_id ASC"
);
$docStmt->execute([$beneficiaryId]);
$uploadedDocs = $docStmt->fetchAll();

// ── Get eligibility explanation for this member + program ───────────────
$explainData = explain_household($pdo, (int) $b['household_id']);
$thisMemberExplain = $explainData['explain'][(int) $b['member_id']] ?? null;
$thisProgramExplain = null;
if ($thisMemberExplain) {
    $thisProgramExplain = $thisMemberExplain['programs'][(int) $b['program_id']] ?? null;
}
$isBlocked = $explainData['blocked'] || ($thisMemberExplain['blocked'] ?? false);

// ── Data quality flags for this household ──────────────────────────────
$dqFlags = [];
foreach ($explainData['flags'] as $flag) {
    if ((int) ($flag['member_id'] ?? 0) === (int) $b['member_id']
        || (int) ($flag['member_id'] ?? 0) === 0) {
        $dqFlags[] = $flag;
    }
}

// ── Slot usage ─────────────────────────────────────────────────────────
$approvedCount = 0;
if ($b['slot_limit'] !== null) {
    $slotStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM beneficiaries
         WHERE program_id = ? AND claim_status = 'approved'"
    );
    $slotStmt->execute([(int) $b['program_id']]);
    $approvedCount = (int) $slotStmt->fetchColumn();
}

// ── Contact fallback: applicant > linked user > household ──────────────
$contactDisplay = $b['applicant_contact_number'];
if (!$contactDisplay && $b['linked_user_id']) {
    $cstmt = $pdo->prepare('SELECT contact_number FROM users WHERE user_id = ?');
    $cstmt->execute([(int) $b['linked_user_id']]);
    $contactDisplay = $cstmt->fetchColumn();
}
if (!$contactDisplay) {
    $cstmt = $pdo->prepare(
        "SELECT u.contact_number FROM residents r
         JOIN users u ON u.user_id = r.user_id
         WHERE r.household_id = ? AND u.contact_number IS NOT NULL AND u.contact_number != ''
         LIMIT 1"
    );
    $cstmt->execute([(int) $b['household_id']]);
    $contactDisplay = $cstmt->fetchColumn();
}

// ── Age calculation ────────────────────────────────────────────────────
function vb_age(?string $birthdate): ?int {
    if (!$birthdate) return null;
    try {
        $dob = new DateTime($birthdate);
        $now = new DateTime('now');
        return (int) $dob->diff($now)->y;
    } catch (Exception $e) {
        return null;
    }
}

// ── Member badge labels ────────────────────────────────────────────────
function vb_member_badges(array $m): string {
    $labels = [];
    if (!empty($m['is_pwd']))          $labels[] = 'PWD';
    if (!empty($m['is_solo_parent']))  $labels[] = 'Solo parent';
    if (!empty($m['is_ofw']))          $labels[] = 'OFW';
    if (!empty($m['is_osy']))          $labels[] = 'OSY';
    if (!empty($m['is_ip']))           $labels[] = 'IP';
    if (!empty($m['is_unemployed']))   $labels[] = 'Unemployed';
    if (!empty($m['is_employed']))     $labels[] = 'Employed';
    if (!empty($m['is_student']))      $labels[] = 'Student';
    if (empty($labels)) return '<span class="muted-note">&mdash;</span>';
    $html = '<span class="status-badges">';
    foreach ($labels as $l) {
        $html .= '<span class="badge badge-pending">' . e($l) . '</span>';
    }
    return $html . '</span>';
}

// ── Status display helpers ─────────────────────────────────────────────
$statusLabels = [
    'qualified' => 'Pending (not yet applied)',
    'pending'   => 'Pending review',
    'approved'  => 'Approved',
    'rejected'  => 'Rejected',
    'retracted' => 'No longer qualified',
    'existing'  => 'Existing beneficiary',
];
$statusTones = [
    'qualified' => 'badge-pending',
    'pending'   => 'badge-pending',
    'approved'  => 'badge-active',
    'rejected'  => 'badge-rejected',
    'retracted' => 'badge-rejected',
    'existing'  => 'badge-active',
];

// ── POST: Approve / Reject ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'Session expired. Please try again.');
        redirect('view-beneficiary.php?id=' . $beneficiaryId);
    }

    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['approve', 'reject'], true)) {
        redirect('view-beneficiary.php?id=' . $beneficiaryId);
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $rejectionReason = $action === 'reject'
        ? (trim($_POST['rejection_reason'] ?? '') ?: null)
        : null;

    // Require rejection reason
    if ($action === 'reject' && $rejectionReason === null) {
        flash('error', 'A rejection reason is required.');
        redirect('view-beneficiary.php?id=' . $beneficiaryId);
    }

    // Fast pre-check from the value rendered on this page. The authoritative
    // slot count is re-taken inside the transaction below - this GET-time
    // value is stale the moment a second admin has the page open.
    if ($action === 'approve' && $b['slot_limit'] !== null
        && $approvedCount >= (int) $b['slot_limit']) {
        flash('error', "This program is full: all {$b['slot_limit']} slot(s) are already approved.");
        redirect('view-beneficiary.php?id=' . $beneficiaryId);
    }

    try {
        $pdo->beginTransaction();

        // Authoritative slot check: count inside the transaction so two
        // concurrent approvals cannot both pass a stale GET-time count and
        // exceed slot_limit (the guarded UPDATE below cannot detect this on
        // its own - it only locks the row being decided, not the count).
        if ($action === 'approve' && $b['slot_limit'] !== null) {
            $cntStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM beneficiaries WHERE program_id = ? AND claim_status = 'approved'"
            );
            $cntStmt->execute([(int) $b['program_id']]);
            $liveApproved = (int) $cntStmt->fetchColumn();
            if ($liveApproved >= (int) $b['slot_limit']) {
                $pdo->rollBack();
                flash('error', "This program is full: all {$b['slot_limit']} slot(s) are already approved.");
                redirect('view-beneficiary.php?id=' . $beneficiaryId);
            }
        }

        // Only a row still awaiting a decision may be decided. Without this
        // guard a crafted POST (or a sweep that retracted the row between page
        // load and submit) could approve a rejected/retracted/existing row, or
        // re-approve one and reset its resident-seen flag.
        $sql = "UPDATE beneficiaries
                SET claim_status = ?,
                    rejection_reason = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    resident_seen_at = NULL
                WHERE beneficiary_id = ? AND claim_status IN ('qualified', 'pending')";
        $upd = $pdo->prepare($sql);
        $upd->execute([$newStatus, $rejectionReason, $_SESSION['staff_user_id'], $beneficiaryId]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            flash('error', 'This application has already been reviewed, or is no longer awaiting a decision.');
            redirect('view-beneficiary.php?id=' . $beneficiaryId);
        }

        // Activity log
        $programName = $b['program_name'];
        $memberName = $b['first_name'] . ' ' . $b['last_name'];
        if ($action === 'approve') {
            $logAction = "Reviewed and approved beneficiary #{$beneficiaryId} ({$memberName}) for '{$programName}'";
        } else {
            $logAction = "Reviewed and rejected beneficiary #{$beneficiaryId} ({$memberName}) for '{$programName}'. Reason: {$rejectionReason}";
        }
        log_activity($pdo, $_SESSION['staff_user_id'], $logAction, 'update');

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Benefit decision failed: ' . $e->getMessage());
        flash('error', 'Something went wrong while saving the decision. No changes were made.');
        redirect('view-beneficiary.php?id=' . $beneficiaryId);
    }

    flash('success', $action === 'approve'
        ? "Application for {$memberName} has been approved."
        : "Application for {$memberName} has been rejected.");

    // Land the admin where the record now lives: an approved application is
    // a beneficiary of that program, a rejected one leaves For Approval but
    // stays visible in history.
    if ($action === 'approve') {
        redirect('beneficiaries.php?program_id=' . (int) $b['program_id']);
    }
    redirect('approved-beneficiaries.php?program_id=' . (int) $b['program_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Review Application - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
.vb-timeline {
  display: flex; flex-wrap: wrap; gap: 6px 20px;
  margin-top: 10px;
}
.vb-timeline-item {
  display: flex; flex-direction: column; gap: 1px;
}
.vb-timeline-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.06em; color: var(--muted);
}
.vb-timeline-value {
  font-size: 14px; color: var(--ink); font-weight: 500;
}
.criteria-list {
  list-style: none; margin: 10px 0 0; padding: 0;
}
.criteria-list li {
  padding: 6px 0; font-size: 14px; border-bottom: 1px solid var(--border);
  display: flex; align-items: baseline; gap: 8px;
}
.criteria-list li:last-child { border-bottom: none; }
.criteria-pass { color: var(--success); font-weight: 600; white-space: nowrap; }
.criteria-fail { color: var(--danger); font-weight: 600; white-space: nowrap; }
.criteria-text { color: var(--ink); }
.vb-section-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  row-gap: 8px; column-gap: 18px;
}
.vb-section-grid > div { display: flex; flex-direction: column; gap: 1px; }
.vb-doc-list { margin: 10px 0 0; padding: 0; list-style: none; }
.vb-doc-list li {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 0; border-bottom: 1px solid var(--border);
}
.vb-doc-list li:last-child { border-bottom: none; }
.vb-doc-label { font-weight: 500; font-size: 14px; }
.vb-doc-date { font-size: 13px; color: var(--muted); }
.vb-doc-view { margin-left: auto; font-size: 14px; }
.dq-flag {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 8px 12px; border-radius: 8px; font-size: 13px;
  margin-top: 6px;
}
.dq-flag-error { background: var(--danger-bg); color: var(--danger); }
.dq-flag-warning { background: var(--warning-bg); color: var(--warning-text); }
.dq-flag-dup { background: #F0EBFF; color: #5B3FA0; }
.vb-decision-actions {
  display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px;
}
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <p style="margin-bottom: 6px;"><?php if ($b['claim_status'] === 'pending'): ?><a href="approved-beneficiaries.php?program_id=<?= (int) $b['program_id'] ?>">&larr; Back to For Approval</a><?php else: ?><a href="beneficiaries.php?program_id=<?= (int) $b['program_id'] ?>">&larr; Back to beneficiaries</a><?php endif; ?></p>

  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
    <div>
      <h1 style="margin-bottom: 2px;">Review Application</h1>
      <span class="muted-note"><?= e($b['program_name']) ?> &middot; <?= e($b['first_name'] . ' ' . $b['last_name']) ?></span>
    </div>
    <span class="badge <?= $statusTones[$b['claim_status']] ?? '' ?>" style="font-size: 14px;">
      <?= e($statusLabels[$b['claim_status']] ?? ucfirst($b['claim_status'])) ?>
    </span>
  </div>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
  <?php endif; ?>

  <!-- ── Application Timeline ──────────────────────────────────────── -->
  <div class="admin-section">
    <h2>Application Timeline</h2>
    <div class="vb-timeline">
      <div class="vb-timeline-item">
        <span class="vb-timeline-label">Qualified</span>
        <span class="vb-timeline-value"><?= fmt_dt($b['qualified_at']) ?></span>
      </div>
      <?php if ($b['applied_at']): ?>
        <div class="vb-timeline-item">
          <span class="vb-timeline-label">Applied</span>
          <span class="vb-timeline-value"><?= fmt_dt($b['applied_at']) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($b['reviewed_at']): ?>
        <div class="vb-timeline-item">
          <span class="vb-timeline-label">Reviewed</span>
          <span class="vb-timeline-value"><?= fmt_dt($b['reviewed_at']) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($b['reviewer_name']): ?>
        <div class="vb-timeline-item">
          <span class="vb-timeline-label">Reviewer</span>
          <span class="vb-timeline-value"><?= e($b['reviewer_name']) ?></span>
        </div>
      <?php endif; ?>
    </div>
    <?php if (($b['source_type'] ?? '') !== ''): ?>
      <div class="vb-timeline-item">
        <span class="vb-timeline-label">Source</span>
        <span class="vb-timeline-value"><?= $b['source_type'] === 'existing_beneficiary_list'
            ? 'Imported beneficiary list'
            : ($b['claim_status'] === 'existing' ? 'Detected automatically by the eligibility rules' : 'Eligibility engine') ?></span>
      </div>
    <?php endif; ?>
    <?php $snapshot = !empty($b['qualification_snapshot']) ? json_decode($b['qualification_snapshot'], true) : null; ?>
    <?php if (!empty($snapshot['checks'])): ?>
      <div style="margin-top: 12px; padding: 10px 14px; background: var(--bg-soft, #f5f7fb); border-radius: 10px; font-size: 14px;">
        <strong>Qualified on <?= fmt_dt($b['qualified_at']) ?> for these reasons:</strong>
        <ul style="margin: 8px 0 0; padding-left: 18px;">
          <?php foreach ($snapshot['checks'] as $check): ?>
            <li><?= e($check) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($b['claim_status'] === 'rejected' && !empty($b['rejection_reason'])): ?>
      <div style="margin-top: 12px; padding: 10px 14px; background: var(--danger-bg); border-radius: 10px; font-size: 14px; color: var(--danger);">
        <strong>Rejection reason:</strong> <?= e($b['rejection_reason']) ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Resident Information ──────────────────────────────────────── -->
  <div class="admin-section">
    <h2>Resident Information</h2>
    <div class="vb-section-grid">
      <div><span class="muted-note">Full name</span><br><strong><?= e(trim($b['first_name'] . ' ' . ($b['middle_name'] ?? '') . ' ' . $b['last_name'] . ($b['suffix'] ? ', ' . $b['suffix'] : ''))) ?></strong></div>
      <div><span class="muted-note">Sex</span><br><strong><?= e($b['sex'] ?? '—') ?></strong></div>
      <div><span class="muted-note">Birthdate</span><br><strong><?= $b['birthdate'] ? e($b['birthdate']) . ' (age ' . vb_age($b['birthdate']) . ')' : '—' ?></strong></div>
      <div><span class="muted-note">Civil status</span><br><strong><?= e($b['civil_status'] ?? '—') ?></strong></div>
      <div><span class="muted-note">Citizenship</span><br><strong><?= e($b['citizenship'] ?? '—') ?></strong></div>
      <div><span class="muted-note">Occupation</span><br><strong><?= e($b['occupation'] ?? '—') ?></strong></div>
      <div><span class="muted-note">Contact number</span><br><strong><?= $contactDisplay ? e($contactDisplay) : '<span class="muted-note">No contact on file</span>' ?></strong></div>
      <div><span class="muted-note">Household role</span><br><strong><?= !empty($b['is_household_head']) ? 'Household head' : 'Member' ?></strong></div>
    </div>
    <?php if (!empty($b['is_pwd']) || !empty($b['is_solo_parent']) || !empty($b['is_ofw']) || !empty($b['is_osy']) || !empty($b['is_ip']) || !empty($b['is_unemployed']) || !empty($b['is_employed']) || !empty($b['is_student'])): ?>
      <div style="margin-top: 10px;">
        <span class="muted-note">Special categories</span><br>
        <?= vb_member_badges($b) ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Household Information ─────────────────────────────────────── -->
  <div class="admin-section">
    <h2>Household</h2>
    <div class="vb-section-grid" style="margin-bottom: 14px;">
      <div><span class="muted-note">Address</span><br><strong><?= e(format_household_address($b)) ?></strong></div>
      <div><span class="muted-note">Monthly income</span><br><strong><?= $b['monthly_income'] !== null ? '&#8369;' . number_format((float) $b['monthly_income'], 2) : '<span class="muted-note">Not provided</span>' ?></strong></div>
      <div><span class="muted-note">Members</span><br><strong><?= (int) $b['no_of_members'] ?></strong></div>
    </div>

    <h3 style="font-size: 16px; margin-bottom: 8px;">Members (<?= count($householdMembers) ?>)</h3>
    <div class="table-scroll">
    <table class="admin-table">
      <thead>
        <tr><th>Name</th><th>Birthdate</th><th>Age</th><th>Sex</th><th>Civil status</th><th>Occupation</th><th>Flags</th><th>Role</th></tr>
      </thead>
      <tbody>
        <?php foreach ($householdMembers as $hm): ?>
          <tr style="<?= (int) $hm['member_id'] === (int) $b['member_id'] ? 'background: var(--gold-soft);' : '' ?>">
            <td>
              <?= e(trim($hm['first_name'] . ' ' . ($hm['middle_name'] ?? '') . ' ' . $hm['last_name'] . ($hm['suffix'] ? ', ' . $hm['suffix'] : ''))) ?>
              <?php if ((int) $hm['member_id'] === (int) $b['member_id']): ?>
                <span class="badge badge-active">applicant</span>
              <?php endif; ?>
            </td>
            <td><?= e($hm['birthdate'] ?? '—') ?></td>
            <td><?= $hm['birthdate'] ? (int) vb_age($hm['birthdate']) : '—' ?></td>
            <td><?= e($hm['sex'] ?? '—') ?></td>
            <td><?= e($hm['civil_status'] ?? '—') ?></td>
            <td><?= e($hm['occupation'] ?? '—') ?></td>
            <td><?= vb_member_badges($hm) ?></td>
            <td><?= !empty($hm['is_household_head']) ? 'Head' : 'Member' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- ── Program Details ───────────────────────────────────────────── -->
  <div class="admin-section">
    <h2>Program Details</h2>
    <div class="vb-section-grid">
      <div><span class="muted-note">Program name</span><br><strong><?= e($b['program_name']) ?></strong></div>
      <div><span class="muted-note">Type</span><br><strong><?= e($b['program_type'] ?? '—') ?></strong></div>
      <div><span class="muted-note">Category</span><br><strong><?= e($b['category'] ?? '—') ?></strong></div>
      <div><span class="muted-note">Provider</span><br><strong><?= e($b['provider'] ?? '—') ?></strong></div>
      <div><span class="muted-note">Status</span><br><strong><?= e(ucfirst($b['program_status'])) ?></strong></div>
      <div><span class="muted-note">Release period</span><br><strong><?= e($b['release_period'] ?? '—') ?></strong></div>
      <?php if ($b['start_date']): ?>
        <div><span class="muted-note">Start date</span><br><strong><?= e(date('M j, Y', strtotime($b['start_date']))) ?></strong></div>
      <?php endif; ?>
      <?php if ($b['end_date']): ?>
        <div><span class="muted-note">End date</span><br><strong><?= e(date('M j, Y', strtotime($b['end_date']))) ?></strong></div>
      <?php endif; ?>
      <?php if ($b['application_deadline'] && ($b['application_mode'] ?? 'application_required') === 'application_required'): ?>
        <div><span class="muted-note">Application deadline</span><br><strong><?= e(date('M j, Y g:i A', strtotime($b['application_deadline']))) ?></strong></div>
      <?php endif; ?>
      <?php if ($b['slot_limit'] !== null): ?>
        <div><span class="muted-note">Slots</span><br><strong><?= $approvedCount ?> / <?= (int) $b['slot_limit'] ?> approved</strong></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($b['program_description'])): ?>
      <div style="margin-top: 12px; padding: 12px 14px; background: var(--bg); border-radius: 10px; font-size: 14px; color: var(--ink);">
        <?= nl2br(e($b['program_description'])) ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Eligibility Criteria ──────────────────────────────────────── -->
  <?php if ($criteria): ?>
  <div class="admin-section">
    <h2>Program Criteria</h2>
    <div class="vb-section-grid">
      <?php if ($criteria['min_age'] !== null): ?>
        <div><span class="muted-note">Minimum age</span><br><strong><?= (int) $criteria['min_age'] ?></strong></div>
      <?php endif; ?>
      <?php if ($criteria['max_age'] !== null): ?>
        <div><span class="muted-note">Maximum age</span><br><strong><?= (int) $criteria['max_age'] ?></strong></div>
      <?php endif; ?>
      <?php if ($criteria['min_household_size'] !== null): ?>
        <div><span class="muted-note">Minimum household size</span><br><strong><?= (int) $criteria['min_household_size'] ?></strong></div>
      <?php endif; ?>
      <?php if ($criteria['max_income'] !== null): ?>
        <div><span class="muted-note">Maximum monthly income</span><br><strong>&#8369;<?= number_format((float) $criteria['max_income'], 2) ?></strong></div>
      <?php endif; ?>
      <?php if ($criteria['min_income'] !== null): ?>
        <div><span class="muted-note">Minimum monthly income</span><br><strong>&#8369;<?= number_format((float) $criteria['min_income'], 2) ?></strong></div>
      <?php endif; ?>
    </div>
    <?php
      $flagLabels = [];
      if (!empty($criteria['requires_pwd']))          $flagLabels[] = 'PWD';
      if (!empty($criteria['requires_solo_parent']))   $flagLabels[] = 'Solo parent';
      if (!empty($criteria['requires_ofw']))           $flagLabels[] = 'OFW';
      if (!empty($criteria['requires_ip']))            $flagLabels[] = 'Indigenous person';
      if (!empty($criteria['requires_osy']))           $flagLabels[] = 'Out-of-school youth';
      if (!empty($criteria['requires_unemployed']))    $flagLabels[] = 'Unemployed';
      if (!empty($criteria['requires_employed']))      $flagLabels[] = 'Employed';
      if (!empty($criteria['requires_student']))       $flagLabels[] = 'Student';
    ?>
    <?php if (!empty($flagLabels)): ?>
      <div style="margin-top: 10px;">
        <span class="muted-note">Required categories</span><br>
        <span class="status-badges">
          <?php foreach ($flagLabels as $fl): ?>
            <span class="badge badge-pending"><?= e($fl) ?></span>
          <?php endforeach; ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── System Eligibility Assessment ─────────────────────────────── -->
  <div class="admin-section">
    <h2>Eligibility Assessment</h2>
    <p class="muted-note" style="margin-bottom: 8px;">System-generated result based on this resident's census data against the program's criteria. This is evaluated automatically and should not be manually altered.</p>

    <?php if ($isBlocked): ?>
      <div class="dq-flag dq-flag-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span>This member's eligibility check is paused due to data quality issues. The household record needs to be corrected before the system can evaluate eligibility.</span>
      </div>
    <?php endif; ?>

    <?php if ($thisProgramExplain !== null): ?>
      <div style="margin-top: 10px; padding: 12px 14px; background: <?= $thisProgramExplain['match'] ? 'var(--success-bg)' : 'var(--danger-bg)' ?>; border-radius: 10px;">
        <strong style="color: <?= $thisProgramExplain['match'] ? 'var(--success)' : 'var(--danger)' ?>;">
          <?= $thisProgramExplain['match'] ? 'System result: QUALIFIES' : 'System result: DOES NOT QUALIFY' ?>
        </strong>
      </div>
      <ul class="criteria-list">
        <?php foreach ($thisProgramExplain['checks'] as $check): ?>
          <?php
            $isPass = str_starts_with($check, 'PASS');
            $label = $isPass ? 'PASS' : 'FAIL';
            $detail = $isPass ? substr($check, 5) : substr($check, 5);
          ?>
          <li>
            <span class="<?= $isPass ? 'criteria-pass' : 'criteria-fail' ?>"><?= e($label) ?></span>
            <span class="criteria-text"><?= e($detail) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($thisProgramExplain['checks'])): ?>
          <li><span class="criteria-text muted-note">No criteria were checked (member may be blocked by data quality issues).</span></li>
        <?php endif; ?>
      </ul>
    <?php else: ?>
      <div style="margin-top: 10px; padding: 12px 14px; background: var(--bg); border-radius: 10px; font-size: 14px; color: var(--muted);">
        No eligibility assessment available for this member against this program.
      </div>
    <?php endif; ?>

    <?php if (!empty($dqFlags)): ?>
      <div style="margin-top: 14px;">
        <strong style="font-size: 14px;">Data Quality Flags</strong>
        <?php foreach ($dqFlags as $flag): ?>
          <?php
            $flagClass = match($flag['severity'] ?? '') {
              'error' => 'dq-flag-error',
              'warning' => 'dq-flag-warning',
              'possible_duplicate' => 'dq-flag-dup',
              default => 'dq-flag-warning',
            };
          ?>
          <div class="dq-flag <?= $flagClass ?>">
            <strong style="white-space:nowrap;"><?= e(ucfirst(str_replace('_', ' ', $flag['severity'] ?? ''))) ?></strong>
            <span><?= e($flag['message'] ?? '') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Submitted Documents ───────────────────────────────────────── -->
  <div class="admin-section">
    <h2>Submitted Documents</h2>
    <?php if (empty($uploadedDocs) && empty($docRequirements)): ?>
      <p class="muted-note">No document requirements configured for this program and no documents uploaded.</p>
    <?php else: ?>
      <?php if (!empty($docRequirements)): ?>
        <div style="margin-bottom: 10px;">
          <span class="muted-note">Required documents:</span>
          <span style="font-size: 14px;">
            <?= implode(', ', array_map(fn($r) => e($r['label']), $docRequirements)) ?>
          </span>
        </div>
      <?php endif; ?>
      <?php if (!empty($uploadedDocs)): ?>
        <ul class="vb-doc-list">
          <?php foreach ($uploadedDocs as $doc): ?>
            <li>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <div>
                <span class="vb-doc-label"><?= e($doc['label']) ?></span>
                <span class="vb-doc-date">&middot; uploaded <?= e(date('M j, Y g:i A', strtotime($doc['uploaded_at']))) ?></span>
              </div>
              <a href="view-benefit-document.php?id=<?= $beneficiaryId ?>&amp;doc=<?= (int) $doc['benefit_document_id'] ?>" target="_blank" class="vb-doc-view">View</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php elseif (!empty($b['id_document_path'])): ?>
        <ul class="vb-doc-list">
          <li>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span class="vb-doc-label">Uploaded ID document</span>
            <a href="view-benefit-document.php?id=<?= $beneficiaryId ?>" target="_blank" class="vb-doc-view">View</a>
          </li>
        </ul>
      <?php else: ?>
        <p class="muted-note">No documents uploaded yet.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ── Review Decision ───────────────────────────────────────────── -->
  <?php if (in_array($b['claim_status'], ['qualified', 'pending'], true)): ?>
  <div class="admin-section">
    <h2>Review Decision</h2>
    <p class="muted-note">Review the application details above, then approve or reject. The system-generated eligibility result above is for your reference — your decision as the reviewer is recorded separately in the audit trail.</p>

    <?php if ($b['claim_status'] === 'qualified' && !$b['applied_at']): ?>
      <div style="margin-top: 8px; padding: 10px 14px; background: var(--warning-bg); border-radius: 10px; font-size: 14px; color: var(--warning-text);">
        This resident has not yet applied (no application submitted). You can still review and decide, but note that no application documents have been uploaded.
      </div>
    <?php endif; ?>

    <form method="POST" id="reviewForm" style="margin-top: 14px;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" id="reviewAction" value="">
      <input type="hidden" name="rejection_reason" id="rejectionReason" value="">

      <div class="vb-decision-actions">
        <button type="button" class="btn-approve" onclick="confirmApprove()">
          Approve Application
        </button>
        <button type="button" class="btn-reject" onclick="confirmReject()">
          Reject Application
        </button>
      </div>
    </form>
  </div>
  <?php elseif ($b['claim_status'] === 'approved'): ?>
    <div class="admin-section">
      <h2>Review Decision</h2>
      <p class="muted-note">This application was approved<?= $b['reviewer_name'] ? ' by ' . e($b['reviewer_name']) : '' ?><?= $b['reviewed_at'] ? ' on ' . e(date('M j, Y g:i A', strtotime($b['reviewed_at']))) : '' ?>. No further action is needed here.</p>
    </div>
  <?php elseif ($b['claim_status'] === 'rejected'): ?>
    <div class="admin-section">
      <h2>Review Decision</h2>
      <p class="muted-note">This application was rejected<?= $b['reviewer_name'] ? ' by ' . e($b['reviewer_name']) : '' ?><?= $b['reviewed_at'] ? ' on ' . e(date('M j, Y g:i A', strtotime($b['reviewed_at']))) : '' ?>.</p>
      <?php if (!empty($b['rejection_reason'])): ?>
        <div style="margin-top: 8px; padding: 10px 14px; background: var(--danger-bg); border-radius: 10px; font-size: 14px; color: var(--danger);">
          <strong>Reason:</strong> <?= e($b['rejection_reason']) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php elseif ($b['claim_status'] === 'retracted'): ?>
    <div class="admin-section">
      <h2>Review Decision</h2>
      <p class="muted-note">This beneficiary was automatically retracted because the resident no longer meets this program's criteria. The system will re-qualify them if the criteria are met again.</p>
      <?php if (!empty($b['rejection_reason'])): ?>
        <div style="margin-top: 8px; padding: 10px 14px; background: var(--warning-bg); border-radius: 10px; font-size: 14px; color: var(--warning-text);">
          <?= e($b['rejection_reason']) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<script>
function confirmApprove() {
  window.brgyConfirm({
    title: 'Approve this application?',
    message: 'The resident will be notified that their application for <?= e(addslashes($b["program_name"])) ?> has been approved.',
    confirmLabel: 'Approve application',
  }).then(function (ok) {
    if (!ok) return;
    document.getElementById('reviewAction').value = 'approve';
    document.getElementById('reviewForm').submit();
  });
}

function confirmReject() {
  window.brgyPrompt({
    title: 'Reject this application',
    message: 'Type the reason for rejecting - the resident will see it on their dashboard.',
    confirmLabel: 'Reject application',
    danger: true,
    placeholder: 'Reason for rejecting this application\u2026',
  }).then(function (reason) {
    if (reason === null) return;
    document.getElementById('rejectionReason').value = reason;
    document.getElementById('reviewAction').value = 'reject';
    document.getElementById('reviewForm').submit();
  });
}
</script>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
