<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_resident_login();
require_census_completed();
no_cache();

$pdo = get_db_connection();

// Fully automatic eligibility: a throttled once-a-24h sweep (safe to run on a
// resident page - see maybe_run_eligibility_sweep's database lock) catches
// time-based changes like birthdays crossing a program's age floor; this
// household's own data changes are already synced per-event by the call sites.
maybe_run_eligibility_sweep($pdo);

$stmt = $pdo->prepare('SELECT household_id, household_member_id FROM residents WHERE resident_id = ?');
$stmt->execute([$_SESSION['resident_id']]);
$myResident = $stmt->fetch();
$householdId = $myResident['household_id'];
$myMemberId = (int) ($myResident['household_member_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT b.beneficiary_id, b.program_id, b.member_id, b.claim_status, b.pickup_status, b.claimed_at, b.qualified_at, b.reviewed_at, b.rejection_reason, b.resident_seen_at, b.source_type,
            b.qualification_snapshot,
            p.name AS program_name, p.description, p.provider, p.status, p.start_date, p.end_date,
            p.application_deadline, p.slot_limit, p.application_mode, p.release_period,
            (SELECT COUNT(*) FROM beneficiaries b2
             WHERE b2.program_id = p.program_id AND b2.claim_status = \'approved\') AS program_approved_count,
            hm.first_name, hm.last_name
     FROM beneficiaries b
     JOIN programs p ON p.program_id = b.program_id
     JOIN household_members hm ON hm.member_id = b.member_id AND hm.is_archived = FALSE
     WHERE hm.household_id = ?
     ORDER BY b.qualified_at DESC'
);
$stmt->execute([$householdId]);
$beneficiaryRows = $stmt->fetchAll();

// A claimed/rejected row with resident_seen_at still NULL is a decision
// the resident hasn't seen yet - flag it as "new" for this one render,
// then mark it seen so it only ever shows the "New" flag once.
$newlySeenIds = [];
foreach ($beneficiaryRows as &$row) {
    $row['is_new'] = in_array($row['claim_status'], ['approved', 'rejected', 'retracted'], true) && $row['resident_seen_at'] === null;
    if ($row['is_new']) {
        $newlySeenIds[] = (int) $row['beneficiary_id'];
    }
}
unset($row);
if (!empty($newlySeenIds)) {
    $placeholders = implode(',', array_fill(0, count($newlySeenIds), '?'));
    $pdo->prepare("UPDATE beneficiaries SET resident_seen_at = NOW() WHERE beneficiary_id IN ($placeholders)")
        ->execute($newlySeenIds);
}

$upcomingEvents = $pdo->query(
    "SELECT title, event_date FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 3"
)->fetchAll();

$successMessage = flash('success');
$errorMessage = flash('error');
$activePage = 'dashboard';

$statusLabels = [
    'qualified' => ['label' => 'Qualified', 'class' => 'status-qualified'],
    'existing'  => ['label' => 'Existing Beneficiary', 'class' => 'status-existing'],
    'pending'   => ['label' => 'Pending review', 'class' => 'status-pending'],
    'approved'  => ['label' => 'Approved', 'class' => 'stamp stamp-approved'],
    'rejected'  => ['label' => 'Not approved', 'class' => 'stamp stamp-rejected'],
    'retracted' => ['label' => 'No longer qualified', 'class' => 'stamp stamp-retracted'],
];

// Read-only household decision (cleaning + validation + matching, no writes)
// used to show residents exactly why something qualifies - or doesn't.
$householdExplain = explain_household($pdo, (int) $householdId);
$explainById = $householdExplain['explain'];
$householdBlocked = $householdExplain['blocked'];

$snapshotOf = static function (?string $raw): ?array {
    if ($raw === null || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
};

// Date the eligibility engine judged this row: the one-time qualification
// snapshot wins, then the qualification/decision timestamps. Rows written
// before migration_025 have no snapshot at all.
$evaluatedDate = static function (array $row) use ($snapshotOf): ?string {
    $snap = $snapshotOf($row['qualification_snapshot'] ?? null);
    if (!empty($snap['evaluated_at'])) {
        return (string) $snap['evaluated_at'];
    }
    if (!empty($row['qualified_at'])) {
        return (string) $row['qualified_at'];
    }
    if (!empty($row['reviewed_at'])) {
        return (string) $row['reviewed_at'];
    }
    return null;
};

// ── Section bucketing ────────────────────────────────────────────────────
// Every row lands in exactly one section, so a pair the resident sees as
// "Qualified to Apply" can never also read "Not Qualified":
//
//   apply      claim_status 'qualified' and the application window is usable
//              (or only temporarily blocked: not yet open, slots full,
//              household data review - shown with an explanatory line and
//              no Apply button, exactly as before)
//   period     claim_status 'qualified' but there is nothing to apply to:
//              program takes no applications, program is not active, or the
//              deadline/end date has passed
//   beneficiary claim_status 'approved' or 'existing'
//   decisions  claim_status 'pending', 'rejected' or 'retracted'
$sections = ['apply' => [], 'beneficiary' => [], 'period' => [], 'decisions' => []];
foreach ($beneficiaryRows as $row) {
    $row['_apply_blocked'] = null;
    $row['_period_ended']  = false;
    $row['_snapshot']      = $snapshotOf($row['qualification_snapshot'] ?? null);
    $row['_evaluated_at']  = $evaluatedDate($row);

    if ($row['claim_status'] === 'qualified') {
        $memberBlocked = $householdBlocked
            || !empty($explainById[(int) $row['member_id']]['blocked']);
        $deadline = $row['application_deadline'] ?: $row['end_date'];

        // Order matches the server-side gates in apply-benefit.php, so the
        // button, the badge and the actual submit path can never disagree.
        if (($row['application_mode'] ?? 'application_required') !== 'application_required') {
            $row['_period_ended']  = true;
            $row['_apply_blocked'] = 'This program does not take applications - the barangay adds matching households to it automatically.';
        } elseif ($row['status'] !== 'active') {
            // program_state() is documented as the resident Apply
            // gate but was only ever called from the admin portal -
            // an inactive program still showed a working Apply button
            // here and on apply-benefit.php.
            $row['_period_ended']  = true;
            $row['_apply_blocked'] = 'This program is not accepting applications right now - ask the barangay about it.';
        } elseif ($row['start_date'] !== null && strtotime($row['start_date']) > time()) {
            $row['_apply_blocked'] = 'Applications for this program open on ' . date('M j, Y', strtotime($row['start_date'])) . '.';
        } elseif ($deadline !== null && strtotime($deadline) < time()) {
            $row['_period_ended']  = true;
            $row['_apply_blocked'] = 'Applications for this program closed on ' . date('M j, Y g:i A', strtotime($deadline)) . '.';
        } elseif ($row['slot_limit'] !== null
                  && (int) $row['slot_limit'] > 0
                  && (int) $row['program_approved_count'] >= (int) $row['slot_limit']) {
            $row['_apply_blocked'] = 'All available slots for this program have been filled.';
        } elseif ($memberBlocked) {
            $row['_apply_blocked'] = 'Applications are paused while a data review is pending for your household - ask the barangay about fixing the record, and eligibility will be re-checked automatically.';
        }
    }

    if ($row['claim_status'] === 'qualified') {
        $sections[$row['_period_ended'] ? 'period' : 'apply'][] = $row;
    } elseif (in_array($row['claim_status'], ['approved', 'existing'], true)) {
        $sections['beneficiary'][] = $row;
    } else {
        $sections['decisions'][] = $row;
    }
}

// Source labels for rows the resident does not have to (or could not) apply
// for - the three source states a resident may see, no database internals.
$sourceLabel = static function (array $row): string {
    if ($row['claim_status'] === 'approved') {
        return 'Approved Application';
    }
    return ($row['source_type'] ?? 'eligibility_engine') === 'existing_beneficiary_list'
        ? 'Imported Beneficiary'
        : 'System-Detected';
};

// ── "Not Qualified" list ─────────────────────────────────────────────────
// Straight from the read-only explain map: every household member against
// every active program that has configured criteria, keeping only the pairs
// that do NOT match and have no beneficiaries row of their own (those are
// already filed above). Nothing here re-decides eligibility - the explain
// map IS the engine's own answer, computed for this page view.
// A household or member under a data-quality hold is never listed: the
// engine neither grants nor retracts them, so calling them "not qualified"
// would be a second, unauthorised decision.
$notQualified = [];
if (!$householdBlocked) {
    $rowKeys = [];
    foreach ($beneficiaryRows as $r) {
        $rowKeys[(int) $r['member_id'] . ':' . (int) $r['program_id']] = true;
    }
    foreach ($explainById as $mid => $entry) {
        if (!empty($entry['blocked'])) {
            continue;
        }
        foreach ($entry['programs'] as $pid => $prog) {
            if (!empty($prog['match']) || isset($rowKeys[$mid . ':' . $pid])) {
                continue;
            }
            if (!isset($notQualified[(int) $mid])) {
                $notQualified[(int) $mid] = [
                    'name'     => $entry['name'],
                    'age'      => $entry['age'],
                    'programs' => [],
                ];
            }
            $progRow = $prog;
            $progRow['program_id'] = (int) $pid;
            $notQualified[(int) $mid]['programs'][] = $progRow;
        }
    }
    foreach ($notQualified as &$group) {
        usort($group['programs'], fn($a, $b) => strcmp($a['name'], $b['name']));
    }
    unset($group);
}

$summaryCounts = [
    'apply'        => count($sections['apply']),
    'beneficiary'  => count($sections['beneficiary']),
    'period'       => count($sections['period']),
    'not_qualified' => array_sum(array_map(fn($g) => count($g['programs']), $notQualified)),
    'decisions'    => count($sections['decisions']),
];
$summaryStyles = [
    'apply'        => ['label' => 'Qualified to apply',      'class' => 'sum-eligible'],
    'beneficiary'  => ['label' => 'Already a beneficiary',    'class' => 'sum-existing'],
    'period'       => ['label' => 'Application period ended', 'class' => 'sum-period'],
    'not_qualified'=> ['label' => 'Not qualified',            'class' => 'sum-notqual'],
    'decisions'    => ['label' => 'Applications & decisions', 'class' => 'sum-pending'],
];

// ── Rendering helpers ────────────────────────────────────────────────────
// Structured rows for one (member, program) verdict, straight from the
// engine's explain map. Rows written before the structured detail existed
// fall back to their display strings rather than to a second calculation.
$criteriaRows = static function (array $prog): array {
    if (!empty($prog['details']) && is_array($prog['details'])) {
        return array_values($prog['details']);
    }
    $rows = [];
    foreach ($prog['checks'] ?? [] as $check) {
        $rows[] = [
            'result'   => str_starts_with($check, 'PASS') ? 'PASS' : 'FAIL',
            'reason'   => '',
            'basis'    => (string) substr($check, 5),
            'required' => '',
            'actual'   => '',
            'text'     => (string) $check,
        ];
    }
    return $rows;
};

// Basis | Required | Your information - the four fields a resident needs to
// understand one verdict without knowing any rule or database internals.
$criteriaTable = static function (array $rows, string $wanted): void {
    $rows = array_values(array_filter($rows, static fn(array $r): bool => ($r['result'] ?? '') === $wanted));
    if (!$rows) {
        return;
    }
    ?>
    <table class="criteria-table">
      <thead>
        <tr><th>Basis</th><th>Required</th><th>Your information</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><strong><?= e($r['basis']) ?></strong><?php if ($r['reason'] !== ''): ?><span class="criteria-reason"><?= e($r['reason']) ?></span><?php endif; ?></td>
            <td><?= $r['required'] !== '' ? e($r['required']) : '<span class="muted-note">&mdash;</span>' ?></td>
            <td><?= $r['actual'] !== '' ? e($r['actual']) : '<span class="muted-note">&mdash;</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};

// One benefit card for sections 1-3. The card never decides anything: it
// renders the row it is handed plus the engine's own explain verdict.
$renderCard = static function (array $row, array $opts) use ($myMemberId, $statusLabels, $explainById, $criteriaRows, $criteriaTable, $sourceLabel): void {
    $status     = $statusLabels[$row['claim_status']] ?? ['label' => $row['claim_status'], 'class' => ''];
    $isSelf     = ((int) $row['member_id']) === $myMemberId;
    $memberName = $row['first_name'] . ' ' . $row['last_name'];
    $badge      = $opts['badge'] ?? $status['label'];

    $entry = $explainById[(int) $row['member_id']]['programs'][(int) $row['program_id']] ?? null;
    $rows  = $entry ? $criteriaRows($entry) : [];
    $passes = array_values(array_filter($rows, static fn(array $r): bool => ($r['result'] ?? '') === 'PASS'));
    $fails  = array_values(array_filter($rows, static fn(array $r): bool => ($r['result'] ?? '') === 'FAIL'));
    ?>
    <div class="benefit-card">
      <div class="benefit-card-head">
        <div>
          <div class="benefit-name"><?= e($row['program_name']) ?></div>
          <div class="benefit-member">For: <?= $isSelf ? 'You' : e($memberName) ?></div>
        </div>
        <div class="benefit-card-actions">
          <span class="status-badge <?= e($status['class']) ?>"><?= e($badge) ?></span>
          <?php if (!empty($opts['show_source'])): ?>
            <span class="status-badge status-source" title="How this entry was created"><?= e($sourceLabel($row)) ?></span>
          <?php endif; ?>
          <?php if (!empty($opts['show_apply'])): ?>
            <a href="apply-benefit.php?id=<?= (int) $row['beneficiary_id'] ?>" class="btn-apply">Apply</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($row['description'])): ?>
        <div class="benefit-desc"><?= e($row['description']) ?></div>
      <?php endif; ?>
      <?php if (!empty($row['provider'])): ?>
        <div class="benefit-meta">Benefit/source: <?= e($row['provider']) ?></div>
      <?php endif; ?>
      <?php
        // Actionable deadline: only on open application_required cards.
        // Closed cards already carry the historical "Applications closed on …"
        // blocked_note; no-application programs never show a deadline.
        if (!empty($opts['show_deadline'])
            && ($row['application_mode'] ?? 'application_required') === 'application_required'):
            $cardDeadline = $row['application_deadline'] ?: ($row['end_date'] ?? null);
            if ($cardDeadline !== null && $cardDeadline !== ''):
      ?>
        <div class="benefit-meta">Application Deadline: <?= e(date('F j, Y g:i A', strtotime($cardDeadline))) ?></div>
      <?php else: ?>
        <div class="benefit-meta">No fixed application deadline</div>
      <?php endif; endif; ?>
      <?php if (!empty($opts['show_release']) && !empty($row['release_period'])): ?>
        <div class="benefit-meta">Benefit release: <?= e($row['release_period']) ?></div>
      <?php endif; ?>
      <?php
        // Pickup is only meaningful for approved rows (mirrors the admin
        // beneficiaries list, which renders a dash for everything else).
        // Existing rows keep the DB default 'unclaimed' without any pickup
        // transaction behind it, so showing "Unclaimed" there would falsely
        // imply the barangay confirmed non-pickup.
        if (!empty($opts['show_pickup']) && ($row['claim_status'] ?? '') === 'approved'):
      ?>
        <?php if (($row['pickup_status'] ?? 'unclaimed') === 'claimed'): ?>
          <div class="benefit-meta">Pickup Status: Claimed<?= !empty($row['claimed_at']) ? ' on ' . e(date('F j, Y', strtotime($row['claimed_at']))) : '' ?></div>
        <?php else: ?>
          <div class="benefit-meta">Pickup Status: Unclaimed</div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($opts['blocked_note'])): ?>
        <div class="benefit-desc" style="color:#9A2C2C; font-weight:500; margin-top:6px;"><?= e($opts['blocked_note']) ?></div>
      <?php elseif (empty($opts['show_apply'])): ?>
        <div class="benefit-desc" style="color:#0B2E5C; font-weight:500;"><?= $isSelf ? 'You are' : e($memberName) . ' is' ?> listed as a beneficiary of this program.</div>
      <?php endif; ?>
      <?php if (!empty($opts['show_qualified_on']) && !empty($row['_evaluated_at'])): ?>
        <div class="benefit-meta">Qualified on <?= e(date('F j, Y', strtotime($row['_evaluated_at']))) ?></div>
      <?php endif; ?>
      <?php if (!empty($opts['show_requirements']) && $entry && !empty($entry['match'])): ?>
        <?php if (!empty($fails)): ?>
          <details class="why-check">
            <summary>Requirements checked (<?= count($fails) ?> not met)</summary>
            <?php $criteriaTable($fails, 'FAIL'); ?>
          </details>
        <?php endif; ?>
        <?php if (!empty($passes)): ?>
          <details class="why-check">
            <summary>Meets the requirements (<?= count($passes) ?>)</summary>
            <?php $criteriaTable($passes, 'PASS'); ?>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
};

$sectionEmpty = static function (string $text): void {
    ?>
    <div class="benefit-none"><?= e($text) ?></div>
    <?php
};

?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>My Benefits - Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
/* Kept inline (in addition to assets/css/style.css) so the status-detail
   popup always renders and behaves correctly even if the shared
   stylesheet is out of date or fails to load on a given deployment.
   Values match the shared token set in assets/css/style.css. */
.status-badge-clickable {
  border: none;
  cursor: pointer;
  font-family: inherit;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 28px;
}
.status-new-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #C0392B;
  flex-shrink: 0;
}
.why-check {
  margin-top: 10px;
  background: #F4F1EA;
  border: 1px solid #E3DED0;
  border-radius: 10px;
}
.why-check > summary {
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: #0B2E5C;
  padding: 9px 12px;
  user-select: none;
}
.why-check-all > summary { color: #3C5470; }
.why-check > summary:focus-visible { outline: 2px solid #1B5FA8; outline-offset: 1px; border-radius: 8px; }
.why-list { margin: 0; padding: 0 12px 10px 28px; font-size: 13px; color: #16233A; }
.why-list li { margin: 3px 0; }
.why-member { margin-top: 12px; }
.why-member:first-child { margin-top: 4px; }
.why-pass-ok { color: #2B5F1F; font-weight: 600; }
.why-pass-no { color: #9A2C2C; font-weight: 600; }
.why-blocked {
  margin-left: 6px;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 20px;
  background: #FCEBEB;
  color: #9A2C2C;
  vertical-align: 1px;
}
.event-modal-backdrop {
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(11, 46, 92, 0.55);
  z-index: 999;
  align-items: center;
  justify-content: center;
  padding: clamp(12px, 3vw, 20px);
}
.event-modal-backdrop.is-open { display: flex; }
.event-modal {
  background: #fff;
  border-radius: 16px;
  max-width: min(480px, calc(100vw - 40px));
  width: 100%;
  max-height: 86vh;
  overflow-y: auto;
  padding: 20px 22px 24px;
  position: relative;
  box-shadow: 0 32px 64px -24px rgba(6, 14, 28, 0.5);
}
.event-modal-close {
  position: absolute;
  top: 12px;
  right: 14px;
  border: none;
  background: none;
  font-size: 24px;
  line-height: 1;
  color: var(--muted, #3C5470);
  cursor: pointer;
  min-width: 32px;
  min-height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.benefit-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
}
.benefit-summary-item {
  background: #fff;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 12px;
  padding: 14px 18px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 3px;
  font-size: 15px;
  color: #3C5470;
  flex: 1 1 0;
  min-width: min(160px, 100%);
  max-width: 240px;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.85), 0 0 0 1px rgba(214, 225, 238, 0.6), 0 12px 32px -18px rgba(11, 46, 92, 0.2);
}
.benefit-summary-count { font-size: 26px; font-weight: 700; font-family: 'IBM Plex Mono', ui-monospace, Menlo, Consolas, monospace; letter-spacing: -0.02em; line-height: 1.15; }
.sum-eligible .benefit-summary-count { color: #2B5F1F; }
.sum-existing .benefit-summary-count { color: #2B5F1F; }
.sum-pending .benefit-summary-count { color: #7A4E10; }
.sum-approved .benefit-summary-count { color: #0B2E5C; }
.sum-rejected .benefit-summary-count { color: #C0392B; }
.sum-retracted .benefit-summary-count { color: #9A6B13; }
.sum-period .benefit-summary-count { color: #8A5A00; }
.sum-notqual .benefit-summary-count { color: #C0392B; }
.status-existing { background: #E8F5E9; color: #2B5F1F; border: 1.5px solid #A5D6A7; }
.status-source { background: #EEF3FA; color: #0B2E5C; border: 1.5px solid #C9D9EE; font-style: italic; }
.benefit-none {
  background: #fff;
  border: 1px dashed var(--border, #D6E1EE);
  border-radius: 12px;
  padding: 16px 18px;
  margin-top: 12px;
  font-size: 15px;
  color: #3C5470;
}
.benefit-meta { font-size: 13px; font-weight: 600; color: #3C5470; margin-top: 8px; }
.criteria-table { width: calc(100% - 24px); margin: 0 12px 12px; border-collapse: collapse; font-size: 13px; }
/* Inside a Not-qualified program block the table sits directly on the card,
   so it takes the card's own padding instead of the details-box inset. */
.nq-program > .criteria-table { width: 100%; margin: 6px 0 0; }
.criteria-table th {
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #3C5470;
  border-bottom: 1px solid #E3DED0;
  padding: 7px 8px;
}
.criteria-table td { padding: 8px; border-bottom: 1px solid #F0EBDE; vertical-align: top; color: #16233A; }
.criteria-table tr:last-child td { border-bottom: none; }
.criteria-reason { display: block; font-size: 12px; font-weight: 400; color: #3C5470; }
.nq-member-head { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.nq-member-name { font-size: 16px; font-weight: 700; color: #16233A; }
.nq-program { border-top: 1px solid #EFEAE0; padding-top: 12px; margin-top: 12px; }
.nq-program:first-child { border-top: none; padding-top: 0; margin-top: 0; }
.nq-program-name { font-size: 15px; font-weight: 600; color: #0B2E5C; }
.nq-reason { font-size: 14px; color: #9A2C2C; margin: 4px 0 0; }
.nq-date { font-size: 12px; color: #3C5470; margin-top: 6px; }
.nq-collapsed { margin-top: 8px; }
.benefit-card {
  background: #fff;
  border: 1px solid var(--border, #D6E1EE);
  border-radius: 12px;
  padding: 16px 18px;
  margin-top: 12px;
}
.benefit-card:first-child { margin-top: 0; }
.benefit-card-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}
.benefit-name { font-size: 17px; font-weight: 600; color: #16233A; }
.benefit-member { font-size: 14px; color: #3C5470; margin-top: 2px; }
.benefit-desc { font-size: 15px; color: #3C5470; margin-top: 8px; }
.benefit-card-actions {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}
.btn-apply {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  background: #E8A93D;
  color: #4A2F04;
  font-weight: 700;
  font-size: 15px;
  padding: 6px 6px 6px 20px;
  border-radius: 999px;
  text-decoration: none;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 8px 16px -10px rgba(232, 169, 61, 0.6);
  transition: background-color 0.4s cubic-bezier(0.32, 0.72, 0, 1), transform 0.4s cubic-bezier(0.32, 0.72, 0, 1);
}
.btn-apply::after {
  content: '';
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: rgba(74, 47, 4, 0.14) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234A2F04' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12h13'/%3E%3Cpath d='m13 6 6 6-6 6'/%3E%3C/svg%3E") center / 12px no-repeat;
  transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1);
}
.btn-apply:hover { background: #C98E24; transform: translateY(-1px); }
.btn-apply:hover::after { transform: translateX(2px); }
.btn-apply:active { transform: translateY(0) scale(0.97); }
.btn-apply:active::after { transform: none; }
.benefit-empty {
  text-align: center;
  padding: 44px 24px;
  margin-top: 12px;
  background: #fff;
  border: 1px dashed var(--border, #D6E1EE);
  border-radius: 16px;
}
.benefit-empty-circle {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  margin: 0 auto 14px;
  background: #E8A93D;
  color: #4A2F04;
  font-size: 28px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.benefit-empty h3 { font-size: 18px; color: #16233A; margin: 0; }
.benefit-empty p {
  color: #3C5470;
  font-size: 15px;
  max-width: 540px;
  margin: 8px auto 0;
  line-height: 1.5;
}
/* Official decision stamps - the rubber stamp of a barangay document */
.stamp {
  display: inline-block;
  padding: 4px 11px 3px;
  border: 1.5px solid currentColor;
  border-radius: 5px;
  font-family: 'IBM Plex Mono', ui-monospace, Menlo, Consolas, monospace;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  white-space: nowrap;
  transform: rotate(-2deg);
  box-shadow: inset 0 0 0 2px #fff, inset 0 0 0 3.5px currentColor;
  background: #fff;
}
.stamp-approved { color: #2B5F1F; }
.stamp-rejected { color: #9A2C2C; }
.stamp-retracted { color: #8A5A00; }
@media (prefers-reduced-motion: reduce) { .stamp { transform: none; } }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar-start.php'; ?>

<div class="dashboard">
  <section class="dashboard-welcome">
    <h1>Welcome, <?= e($_SESSION['full_name']) ?></h1>
    <p style="color:#3C5470; font-size: 16px; margin-top:4px;">Track and apply for your household's benefits here.</p>
    <?php if ($successMessage): ?>
      <div class="alert alert-success"><?= e($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
      <div class="alert alert-error"><?= e($errorMessage) ?></div>
    <?php endif; ?>
  </section>

  <?php $hasAnyEntry = !empty($beneficiaryRows) || !empty($notQualified) || !empty($householdExplain['explain']); ?>
  <?php if ($hasAnyEntry): ?>
    <div class="benefit-summary">
      <?php $di = 0; foreach ($summaryCounts as $key => $count): ?>
        <?php if ($count > 0): ?>
          <div class="benefit-summary-item <?= e($summaryStyles[$key]['class']) ?> reveal" style="--d:<?= 100 + $di++ * 80 ?>ms">
            <div class="benefit-summary-count"><?= $count ?></div>
            <div><?= e($summaryStyles[$key]['label']) ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$hasAnyEntry): ?>
  <section class="dashboard-section">
    <h2>Your household's benefits</h2>
      <div class="benefit-empty">
        <div class="benefit-empty-circle">&#10003;</div>
        <h3>No open benefits for your household right now</h3>
        <p>When someone in your household qualifies for a program, it appears here automatically with an Apply button - no need to register again. New programs are announced as the barangay opens them, and your household is re-checked whenever census details change, so keep your household's information up to date.</p>
      </div>
  </section>
  <?php else: ?>

  <section class="dashboard-section">
    <h2>Qualified to apply</h2>
    <p class="muted-note">Programs your household matched that are open for applications right now.</p>
    <?php if (empty($sections['apply'])): ?>
      <?php $sectionEmpty('No programs are open for you to apply to right now. Anything your household qualifies for appears here automatically - including an Apply button - as soon as the barangay opens it.'); ?>
    <?php else: ?>
      <div class="benefit-list">
        <?php foreach ($sections['apply'] as $row): ?>
          <?php $renderCard($row, [
              'show_apply'       => $row['_apply_blocked'] === null,
              'blocked_note'     => $row['_apply_blocked'],
              'show_requirements' => true,
              'show_deadline'    => true,
          ]); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="dashboard-section">
    <h2>Already a beneficiary</h2>
    <p class="muted-note">Benefits your household is already enrolled in - no application needed.</p>
    <?php if (empty($sections['beneficiary'])): ?>
      <?php $sectionEmpty('Nobody in your household is enrolled in a benefit yet.'); ?>
    <?php else: ?>
      <div class="benefit-list">
        <?php foreach ($sections['beneficiary'] as $row): ?>
          <?php $renderCard($row, [
              'show_source'       => true,
              'show_qualified_on' => true,
              'show_requirements' => true,
              'show_release'      => true,
              'show_pickup'       => true,
          ]); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <section class="dashboard-section">
    <h2>Qualified &mdash; application period ended</h2>
    <p class="muted-note">Your household still meets these programs' requirements, but the barangay is no longer accepting applications for them.</p>
    <?php if (empty($sections['period'])): ?>
      <?php $sectionEmpty('None right now - every program your household qualifies for is still open for applications.'); ?>
    <?php else: ?>
      <div class="benefit-list">
        <?php foreach ($sections['period'] as $row): ?>
          <?php $renderCard($row, [
              'badge'             => 'Qualified · Application Period Ended',
              'blocked_note'      => $row['_apply_blocked'],
              'show_qualified_on' => true,
              'show_requirements' => true,
          ]); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="dashboard-section">
    <h2>Not qualified</h2>
    <p class="muted-note">Programs your household does not qualify for right now, with the exact requirement that is not met. Judged from the census information your household has on file - update it on the profile page and this is re-checked automatically.</p>
    <?php if (empty($notQualified)): ?>
      <?php $sectionEmpty($householdBlocked
          ? 'Automatic benefit checks for your household are paused because the barangay flagged a data error. Bring the corrected record to the barangay hall and it will be re-checked right away.'
          : 'Your household meets every program the barangay currently checks.'); ?>
    <?php else: ?>
      <div class="benefit-list">
        <?php foreach ($notQualified as $group): ?>
          <div class="benefit-card">
            <div class="nq-member-head">
              <span class="nq-member-name"><?= e($group['name']) ?></span>
              <?php if ($group['age'] !== null): ?>
                <span class="muted-note">age <?= (int) $group['age'] ?></span>
              <?php endif; ?>
            </div>
            <?php foreach ($group['programs'] as $prog): ?>
              <?php
                $rows   = $criteriaRows($prog);
                $fails  = array_values(array_filter($rows, fn($r) => ($r['result'] ?? '') === 'FAIL'));
                $passes = array_values(array_filter($rows, fn($r) => ($r['result'] ?? '') === 'PASS'));
                $reasons = array_values(array_unique(array_filter(array_map(fn($r) => trim($r['reason']), $fails))));
              ?>
              <div class="nq-program">
                <div class="nq-program-name"><?= e($prog['name']) ?></div>
                <p class="nq-reason"><strong>Reason:</strong> <?= e($reasons !== [] ? implode('; ', $reasons) : 'Does not meet this program&rsquo;s requirements.') ?></p>
                <?php $criteriaTable($fails, 'FAIL'); ?>
                <?php if (!empty($passes)): ?>
                  <details class="why-check nq-collapsed">
                    <summary>Other requirements met (<?= count($passes) ?>)</summary>
                    <?php $criteriaTable($passes, 'PASS'); ?>
                  </details>
                <?php endif; ?>
                <div class="nq-date"><strong>Date evaluated:</strong> <?= e(date('F j, Y')) ?> (re-checked live each time this page is opened)</div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="dashboard-section">
    <h2>Applications &amp; decisions</h2>
    <p class="muted-note">Applications your household has submitted, and the barangay's decisions on them.</p>
    <?php if (empty($sections['decisions'])): ?>
      <?php $sectionEmpty('No applications submitted and no decisions yet.'); ?>
    <?php else: ?>
      <div class="benefit-list">
        <?php foreach ($sections['decisions'] as $row): ?>
          <?php
            $status     = $statusLabels[$row['claim_status']] ?? ['label' => $row['claim_status'], 'class' => ''];
            $isSelf     = ((int) $row['member_id']) === $myMemberId;
            $memberName = $row['first_name'] . ' ' . $row['last_name'];
          ?>
          <div class="benefit-card">
            <div class="benefit-card-head">
              <div>
                <div class="benefit-name"><?= e($row['program_name']) ?></div>
                <div class="benefit-member">For: <?= $isSelf ? 'You' : e($memberName) ?></div>
              </div>
              <div class="benefit-card-actions">
                <button type="button" class="status-badge <?= e($status['class']) ?> status-badge-clickable" onclick="openEventModal(<?= (int) $row['beneficiary_id'] ?>)">
                  <?= e($status['label']) ?><?= $row['is_new'] ? ' <span class="status-new-dot" title="New"></span>' : '' ?>
                </button>
              </div>
            </div>
            <?php if (!empty($row['description'])): ?>
              <div class="benefit-desc"><?= e($row['description']) ?></div>
            <?php endif; ?>
            <?php if ($row['claim_status'] === 'pending'): ?>
              <div class="benefit-desc" style="color:#7A4E10; font-weight:500;">Your application was submitted and is waiting for the barangay to review it. You will see the decision here.</div>
            <?php endif; ?>
          </div>

          <div class="event-modal-backdrop" id="event-modal-<?= (int) $row['beneficiary_id'] ?>" onclick="if (event.target === this) closeEventModal(<?= (int) $row['beneficiary_id'] ?>)">
            <div class="event-modal">
              <button type="button" class="event-modal-close" onclick="closeEventModal(<?= (int) $row['beneficiary_id'] ?>)">&times;</button>
              <h2><?= e($row['program_name']) ?></h2>
              <p class="muted-note"><?= $isSelf ? 'You' : e($memberName) ?></p>
              <span class="status-badge <?= e($status['class']) ?>"><?= e($status['label']) ?></span>
              <?php if (!empty($row['reviewed_at'])): ?>
                <p class="muted-note" style="margin-top:12px;">Decided on <?= e(date('F j, Y', strtotime($row['reviewed_at']))) ?></p>
              <?php endif; ?>
              <?php if ($row['claim_status'] === 'rejected'): ?>
                <p style="margin-top:10px; font-size: 16px;">
                  <strong>Reason:</strong>
                  <?= !empty($row['rejection_reason']) ? nl2br(e($row['rejection_reason'])) : '<span class="muted-note">No reason was provided.</span>' ?>
                </p>
              <?php elseif ($row['claim_status'] === 'retracted'): ?>
                <p style="margin-top:10px; font-size: 16px;">
                  <strong>Reason:</strong>
                  <?= !empty($row['rejection_reason']) ? nl2br(e($row['rejection_reason'])) : '<span class="muted-note">No reason was provided.</span>' ?>
                </p>
                <p class="muted-note" style="margin-top:8px;">This happened automatically because someone in your household no longer meets this program's requirements. Update your household's census details (such as date of birth or income) if they should qualify again - eligibility is re-checked automatically.</p>
              <?php else: ?>
                <p style="margin-top:10px; font-size: 16px;">Your application has been approved by the barangay admin. Please wait for further announcement.</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($householdExplain['explain'])): ?>
  <section class="dashboard-section">
    <details class="why-check why-check-all">
        <summary>Eligibility explained for this household</summary>
        <p class="muted-note" style="margin-top:8px;">Reasons are judged from the census information your household has on file. If it looks wrong, update your household's details on the profile page - eligibility is re-checked automatically after every change.</p>
        <?php if ($householdBlocked): ?>
          <p class="muted-note" style="margin-top:8px;">Automatic benefit updates for your household are paused because the barangay flagged a data error. Bring the corrected record to the barangay hall and it will be re-checked right away.</p>
        <?php endif; ?>
        <?php foreach ($householdExplain['explain'] as $mid => $entry): ?>
          <div class="why-member">
            <strong><?= e($entry['name']) ?></strong>
            <?= $entry['age'] !== null ? ' &middot; age ' . (int) $entry['age'] : '' ?>
            <?php if ($entry['blocked']): ?><span class="why-blocked">data check paused</span><?php endif; ?>
            <ul class="why-list">
              <?php foreach ($entry['programs'] as $prog): ?>
                <li>
                  <span class="why-pass-<?= $prog['match'] ? 'ok' : 'no' ?>"><?= $prog['match'] ? 'Qualifies' : 'Does not qualify' ?></span>
                  for <?= e($prog['name']) ?>
                  <?php if ($prog['match']): ?>
                    <?php
                      $passes = array_values(array_filter($prog['checks'], fn($c) => str_starts_with($c, 'PASS')));
                      if (!empty($passes)): ?> - <?= e(implode('; ', $passes)) ?><?php endif;
                    ?>
                  <?php else: ?>
                    <?php
                      $fails = array_values(array_filter($prog['checks'], fn($c) => str_starts_with($c, 'FAIL')));
                      if (!empty($fails)): ?> - <?= e(implode('; ', array_map(fn($c) => substr($c, 5), $fails))) ?><?php endif;
                    ?>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>
  </section>

  <section class="dashboard-section">
    <h2>Upcoming events</h2>
    <?php if (empty($upcomingEvents)): ?>
      <p class="muted-note">No upcoming events posted yet.</p>
    <?php else: ?>
      <ul class="event-list">
        <?php foreach ($upcomingEvents as $event): ?>
          <li><strong><?= e(date('M j, Y', strtotime($event['event_date']))) ?></strong> - <?= e($event['title']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <a href="events.php" class="link-more">See all events &rarr;</a>
  </section>
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
</script>

<?php include __DIR__ . '/partials/sidebar-end.php'; ?>
</body>
</html>
