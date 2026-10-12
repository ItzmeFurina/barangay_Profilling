<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin();
no_cache();
$activePage = 'beneficiaries';

$pdo = get_db_connection();

// Admin-facing labels only - the underlying claim_status value is
// unchanged everywhere else. The Beneficiaries list shows rows that are
// (or were) on a program's list: 'existing' (imported or auto-enrolled),
// 'approved' (application approved) and 'retracted' (no longer qualified).
// Applications still waiting for a decision live in For Approval.
$adminStatusLabel = [
    'existing'  => 'Existing Beneficiary',
    'approved'  => 'Approved',
    'retracted' => 'No longer qualified',
];

// Age is never stored - calculated from the member's birthdate.
function b_age(?string $birthdate): ?int {
    if (!$birthdate) return null;
    try {
        $dob = new DateTime($birthdate);
        $now = new DateTime('now');
        return (int) $dob->diff($now)->y;
    } catch (Exception $e) {
        return null;
    }
}

// Pickup status editing (moved here from the old Approved / Pickup page).
// Only ever touches approved rows - pickup is meaningless before approval.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update_pickup') {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $beneficiaryId = (int) ($_POST['beneficiary_id'] ?? 0);
        $newPickup = $_POST['new_pickup'] ?? '';
        if (in_array($newPickup, ['unclaimed', 'claimed'], true)) {
            $pidStmt = $pdo->prepare("SELECT program_id FROM beneficiaries WHERE beneficiary_id = ?");
            $pidStmt->execute([$beneficiaryId]);
            $pickupProgramId = (int) ($pidStmt->fetchColumn() ?: 0);
            $pdo->prepare(
                "UPDATE beneficiaries
                 SET pickup_status = ?, claimed_at = " . ($newPickup === 'claimed' ? 'NOW()' : 'NULL') . "
                 WHERE beneficiary_id = ? AND claim_status = 'approved'"
            )->execute([$newPickup, $beneficiaryId]);
            log_activity($pdo, $_SESSION['staff_user_id'], "Marked beneficiary #{$beneficiaryId} as {$newPickup}", 'update');
            flash('success', 'Pickup status updated.');
            if ($pickupProgramId > 0) {
                redirect('beneficiaries.php?program_id=' . $pickupProgramId);
            }
        } else {
            flash('error', 'That is not a valid pickup status.');
        }
        redirect('beneficiaries.php');
    }
    flash('error', 'Your session expired. Please try again.');
    redirect('beneficiaries.php');
}

// CSV export filters come from the export picker popup - it deliberately
// ignores the table's toolbar state, so a report can be downloaded while
// the table shows something completely different.
if (($_GET['export'] ?? '') === 'csv') {
    $exportProgram = (int) ($_GET['export_program'] ?? 0);
    $exportStatus  = $_GET['export_status'] ?? '';
    [$exportYear, $exportMonth] = export_period_from_query($_GET);

    $where = ['hm.is_archived = FALSE'];
    $params = [];
    if ($exportProgram > 0) {
        $where[] = 'b.program_id = ?';
        $params[] = $exportProgram;
    }
    if (in_array($exportStatus, ['qualified', 'pending', 'approved', 'rejected', 'retracted', 'existing'], true)) {
        $where[] = 'b.claim_status = ?';
        $params[] = $exportStatus;
    }
    if ($exportYear !== null || $exportMonth !== null) {
        // Each status is dated by a different milestone - the same rule the
        // dashboard uses. "Qualified" rows usually have no applied_at at all,
        // so a period filter must not assume every row was applied for.
        $exportDateColumns = [
            'qualified' => 'b.qualified_at',
            'pending'   => 'b.applied_at',
            'approved'  => 'b.reviewed_at',
            'rejected'  => 'b.reviewed_at',
            'retracted' => 'b.qualified_at',
            'existing'  => 'b.qualified_at',
        ];
        $cols = ($exportStatus !== '' && isset($exportDateColumns[$exportStatus]))
            ? [$exportDateColumns[$exportStatus]]
            : array_values(array_unique($exportDateColumns));
        $periodParts = [];
        foreach ($cols as $col) {
            $sub = [];
            if ($exportYear !== null) {
                $sub[] = "YEAR($col) = ?";
                $params[] = $exportYear;
            }
            if ($exportMonth !== null) {
                $sub[] = "MONTH($col) = ?";
                $params[] = $exportMonth;
            }
            if ($sub) {
                $periodParts[] = '(' . implode(' AND ', $sub) . ')';
            }
        }
        $where[] = '(' . implode(' OR ', $periodParts) . ')';
    }

    $exportSql = "SELECT b.beneficiary_id, b.claim_status, b.qualified_at, b.reviewed_at,
                         b.applicant_full_name, b.applicant_contact_number, b.id_document_path, b.applied_at,
                         b.source_type, b.pickup_status,
                         hm.first_name, hm.last_name, hm.birthdate,
                         h.house_number, h.street, h.barangay, h.city,
                         p.name AS program_name, p.program_type, p.category, p.application_mode,
                         bi.file_name AS import_file_name,
                         COALESCE(
                           u_direct.contact_number,
                           (SELECT u2.contact_number FROM residents r2 JOIN users u2 ON u2.user_id = r2.user_id
                            WHERE r2.household_id = h.household_id LIMIT 1)
                         ) AS household_contact_number
                  FROM beneficiaries b
                  JOIN household_members hm ON hm.member_id = b.member_id
                  JOIN households h ON h.household_id = hm.household_id
                  JOIN programs p ON p.program_id = b.program_id
                  LEFT JOIN users u_direct ON u_direct.user_id = hm.linked_user_id
                  LEFT JOIN beneficiary_imports bi ON bi.import_id = b.import_batch_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY hm.last_name ASC, hm.first_name ASC";

    $stmt = $pdo->prepare($exportSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    [$periodLabel, $fileSuffix] = export_period_label($exportYear, $exportMonth);

    log_activity($pdo, $_SESSION['staff_user_id'], "Exported beneficiaries list ({$periodLabel}) - " . count($rows) . ' rows', 'read');

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="beneficiaries' . $fileSuffix . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Contact', 'Program', 'Category', 'Mode', 'Source', 'Import File', 'Status', 'Date Added']);
    foreach ($rows as $r) {
        $dateAdded = $r['reviewed_at'] ?: $r['qualified_at'];
        fputcsv($out, array_map('csv_guard', [
            $r['first_name'] . ' ' . $r['last_name'],
            $r['applicant_contact_number'] ?: ($r['household_contact_number'] ?: 'No contact on file'),
            $r['program_name'],
            $r['category'] ?: ucfirst($r['program_type']),
            ($r['application_mode'] ?? 'application_required') === 'no_application_required' ? 'No Application Required' : 'Application Required',
            ($r['source_type'] ?? 'eligibility_engine') === 'existing_beneficiary_list' ? 'Imported' : 'System',
            $r['import_file_name'] ?? '',
            $adminStatusLabel[$r['claim_status']] ?? ucfirst($r['claim_status']),
            $dateAdded ? date('Y-m-d', strtotime($dateAdded)) : '',
        ]));
    }
    fclose($out);
    exit;
}

$programId = (int) ($_GET['program_id'] ?? 0);

if ($programId <= 0) {
    // ── Landing: one card per program ───────────────────────────────────
    // The count is "current beneficiaries" - people actually on the list
    // right now (existing or approved) whose member record isn't archived.
    $cards = $pdo->query(
        "SELECT p.program_id, p.name, p.description, p.category, p.application_mode, p.status,
                (SELECT COUNT(*)
                   FROM beneficiaries b
                   JOIN household_members hm ON hm.member_id = b.member_id
                  WHERE b.program_id = p.program_id
                    AND b.claim_status IN ('existing', 'approved')
                    AND hm.is_archived = FALSE) AS current_count
         FROM programs p
         ORDER BY p.name ASC"
    )->fetchAll();
    $totalOnList = (int) array_sum(array_column($cards, 'current_count'));
} else {
    // ── Program detail: that program's beneficiary list ─────────────────
    $programStmt = $pdo->prepare("SELECT program_id, name, description, application_mode, status FROM programs WHERE program_id = ?");
    $programStmt->execute([$programId]);
    $program = $programStmt->fetch();
    if (!$program) {
        flash('error', 'That program was not found.');
        redirect('beneficiaries.php');
    }

    // Rows that ARE (or were) on this program's list. Applications waiting
    // for a decision belong to For Approval, and rejected applicants never
    // join the beneficiary list - both are deliberately excluded here.
    // Everything is loaded once and filtered client-side (same approach as
    // the Accounts page). ORDER BY matches the default Sort (Name A-Z).
    $listSql = "SELECT b.beneficiary_id, b.program_id, b.claim_status, b.qualified_at, b.reviewed_at,
                       b.applicant_full_name, b.applicant_contact_number, b.id_document_path, b.applied_at,
                       b.rejection_reason, b.source_type, b.import_batch_id, b.pickup_status,
                       hm.first_name, hm.last_name, hm.member_id, hm.birthdate,
                       h.house_number, h.street, h.barangay, h.city,
                       p.name AS program_name, p.program_type, p.application_mode,
                       bi.file_name AS import_file_name, bi.imported_at,
                       COALESCE(
                         u_direct.contact_number,
                         (SELECT u2.contact_number FROM residents r2 JOIN users u2 ON u2.user_id = r2.user_id
                          WHERE r2.household_id = h.household_id LIMIT 1)
                       ) AS household_contact_number
                FROM beneficiaries b
                JOIN household_members hm ON hm.member_id = b.member_id
                JOIN households h ON h.household_id = hm.household_id
                JOIN programs p ON p.program_id = b.program_id
                LEFT JOIN users u_direct ON u_direct.user_id = hm.linked_user_id
                LEFT JOIN beneficiary_imports bi ON bi.import_id = b.import_batch_id
                WHERE b.program_id = ?
                  AND b.claim_status IN ('existing', 'approved', 'retracted')
                  AND hm.is_archived = FALSE
                ORDER BY hm.last_name ASC, hm.first_name ASC";

    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute([$programId]);
    $beneficiaries = $listStmt->fetchAll();
}

// Years with any beneficiary milestone date, for the export picker's Year
// select - qualified rows carry qualified_at, not applied_at.
$exportYears = $pdo->query(
    "SELECT DISTINCT y FROM (
         SELECT YEAR(qualified_at) AS y FROM beneficiaries WHERE qualified_at IS NOT NULL
         UNION SELECT YEAR(applied_at) FROM beneficiaries WHERE applied_at IS NOT NULL
         UNION SELECT YEAR(reviewed_at) FROM beneficiaries WHERE reviewed_at IS NOT NULL
     ) AS years ORDER BY y DESC"
)->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Beneficiaries - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div class="h1-group">
      <h1>Beneficiaries</h1>
      <?php if ($programId <= 0): ?>
        <span class="page-ledger"><b><?= count($cards) ?></b> programs &middot; <b><?= $totalOnList ?></b> people on lists</span>
      <?php endif; ?>
    </div>
    <a href="beneficiaries.php?export=csv" class="logs-export" onclick="document.getElementById('export-modal').classList.add('is-open'); return false;">
      <span>Download CSV</span>
      <span class="logs-export-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/></svg>
      </span>
    </a>
  </div>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if ($msg = flash('error')): ?>
    <div class="alert alert-error"><?= e($msg) ?></div>
  <?php endif; ?>

<?php if ($programId <= 0): ?>
  <!-- ── Program cards ─────────────────────────────────────────────── -->
  <div class="admin-section">
    <h2>Programs (<?= count($cards) ?>)</h2>
    <p class="muted-note" style="margin-top:-4px; margin-bottom:16px;">Open a program to see its beneficiaries - approved, imported, and system-detected residents all live in the same list.</p>
    <?php if (empty($cards)): ?>
      <p class="table-empty">No programs have been created yet. Create one on the Programs page to start a list.</p>
    <?php else: ?>
      <div class="program-card-grid">
        <?php foreach ($cards as $i => $card): ?>
          <a class="program-card reveal" style="--d: <?= min($i, 7) * 65 ?>ms" href="beneficiaries.php?program_id=<?= (int) $card['program_id'] ?>">
            <p class="pc-eyebrow"><?= e($card['category'] ?: (($card['application_mode'] ?? 'application_required') === 'no_application_required' ? 'No application required' : 'Application required')) ?></p>
            <p class="pc-title"><?= e($card['name']) ?></p>
            <p class="pc-desc"><?= $card['description'] !== null && $card['description'] !== '' ? e($card['description']) : '<span class="muted-note">No description provided.</span>' ?></p>
            <div class="pc-count">
              <strong><?= (int) $card['current_count'] ?></strong>
              <span>current beneficiar<?= (int) $card['current_count'] === 1 ? 'y' : 'ies' ?></span>
            </div>
            <span class="pc-open">Open beneficiary list &rarr;</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- ── Program beneficiary list ──────────────────────────────────── -->
  <p style="margin-bottom: 6px;"><a href="beneficiaries.php">&larr; All programs</a></p>

  <div class="admin-section">
    <h2><?= e($program['name']) ?><span class="h2-count"><span id="beneficiaryCount"><?= count($beneficiaries) ?></span><small>on this list</small></span></h2>
    <p class="muted-note" style="margin-top:-4px; margin-bottom:16px;">
      <span class="mode-tag<?= ($program['application_mode'] ?? 'application_required') === 'no_application_required' ? ' mode-tag-noapp' : '' ?>"><?= ($program['application_mode'] ?? 'application_required') === 'no_application_required' ? 'No application required' : 'Application required' ?></span>
      approved, imported and system-detected beneficiaries on one list.
    </p>

    <div class="filter-bar">
      <div class="filter-search">
        <label for="beneficiary-search">Search</label>
        <div class="search-field">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="21" y1="21" x2="16.5" y2="16.5"></line>
          </svg>
          <input type="text" id="beneficiary-search" placeholder="Resident name or ID..." autocomplete="off">
        </div>
      </div>
      <div>
        <label for="beneficiary-source">Source</label>
        <select id="beneficiary-source">
          <option value="">All Sources</option>
          <option value="existing_beneficiary_list">Imported</option>
          <option value="eligibility_engine">System-Detected</option>
        </select>
      </div>
      <div>
        <label for="beneficiary-status">Beneficiary Status</label>
        <select id="beneficiary-status">
          <option value="">All Statuses</option>
          <option value="existing">Existing Beneficiary</option>
          <option value="approved">Approved</option>
          <option value="retracted">No Longer Qualified</option>
        </select>
      </div>
      <div>
        <label for="beneficiary-sort">Sort</label>
        <select id="beneficiary-sort">
          <option value="name-az">Name (A&ndash;Z)</option>
          <option value="name-za">Name (Z&ndash;A)</option>
          <option value="age-asc">Age (Youngest)</option>
          <option value="age-desc">Age (Oldest)</option>
          <option value="added-new">Date Added (Newest)</option>
          <option value="added-old">Date Added (Oldest)</option>
        </select>
      </div>
    </div>

    <div class="table-scroll">
    <table class="admin-table" id="beneficiaries-table">
      <thead><tr><th>Name</th><th>Age</th><th>Contact</th><th>Source</th><th>Status</th><th>Date Added</th><th>Pickup</th><th>Action</th></tr></thead>
      <tbody id="beneficiariesBody">
        <?php foreach ($beneficiaries as $b): ?>
          <?php
            $age = b_age($b['birthdate']);
            // "Date Added" = when the resident entered THIS list: approval
            // time for approved applications, row-creation time (import or
            // automatic enrollment) for everything else.
            $dateAdded = $b['reviewed_at'] ?: $b['qualified_at'];
          ?>
          <tr data-name="<?= e(strtolower($b['first_name'] . ' ' . $b['last_name'])) ?>"
              data-id="<?= (int) $b['member_id'] ?>"
              data-age="<?= $age !== null ? (int) $age : '' ?>"
              data-added="<?= $dateAdded ? (int) strtotime($dateAdded) : 0 ?>"
              data-source="<?= e($b['source_type'] ?? 'eligibility_engine') ?>"
              data-status="<?= e($b['claim_status']) ?>">
            <td>
              <?= e($b['first_name'] . ' ' . $b['last_name']) ?><span class="cell-id">#<?= (int) $b['member_id'] ?></span>
              <?php if (!empty($b['applicant_full_name']) && $b['applicant_full_name'] !== trim($b['first_name'] . ' ' . $b['last_name'])): ?>
                <div class="muted-note">Applied as: <?= e($b['applicant_full_name']) ?></div>
              <?php endif; ?>
            </td>
            <td class="cell-mono"><?= $age !== null ? (int) $age : '<span class="muted-note">&mdash;</span>' ?></td>
            <td><?= $b['applicant_contact_number'] ? e($b['applicant_contact_number']) : ($b['household_contact_number'] ? e($b['household_contact_number']) : '<span class="muted-note">No contact on file</span>') ?></td>
            <td>
              <?php if (($b['source_type'] ?? 'eligibility_engine') === 'existing_beneficiary_list'): ?>
                <span class="src-tag">Imported</span><?php if (!empty($b['import_file_name'])): ?><div class="src-file"><?= e($b['import_file_name']) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="src-tag">System-detected</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $statusBadgeClass = match($b['claim_status']) {
                  'approved'  => 'badge-active',
                  'existing'  => 'badge-neutral',
                  'retracted' => 'badge-rejected',
                  default     => 'badge-pending',
                };
              ?>
              <span class="badge <?= $statusBadgeClass ?>"><?= e($adminStatusLabel[$b['claim_status']] ?? ucfirst($b['claim_status'])) ?></span>
              <?php if ($b['claim_status'] === 'retracted' && !empty($b['rejection_reason'])): ?>
                <div class="muted-note" style="max-width:180px; margin-top:4px;"><?= e($b['rejection_reason']) ?></div>
              <?php endif; ?>
            </td>
            <td class="muted-note cell-mono"><?= $dateAdded ? e(date('M j, Y', strtotime($dateAdded))) : '&mdash;' ?></td>
            <td>
              <?php if ($b['claim_status'] === 'approved'): ?>
                <form method="POST" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="form" value="update_pickup">
                  <input type="hidden" name="beneficiary_id" value="<?= (int) $b['beneficiary_id'] ?>">
                  <select name="new_pickup" onchange="this.form.submit()">
                    <option value="claimed" <?= $b['pickup_status'] === 'claimed' ? 'selected' : '' ?>>Claimed</option>
                    <option value="unclaimed" <?= $b['pickup_status'] === 'unclaimed' ? 'selected' : '' ?>>Unclaimed</option>
                  </select>
                </form>
              <?php else: ?>
                <span class="muted-note">&mdash;</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="view-beneficiary.php?id=<?= (int) $b['beneficiary_id'] ?>" class="btn-review">Review</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($beneficiaries)): ?>
          <tr><td colspan="8" class="table-empty">Nobody is on this list yet. Approvals, imports, and eligibility matches all land here.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php
$exportProgramOptions = [['', 'All programs']];
$allPrograms = $pdo->query('SELECT program_id, name FROM programs ORDER BY name ASC')->fetchAll();
foreach ($allPrograms as $p) {
    $exportProgramOptions[] = [(string) (int) $p['program_id'], $p['name']];
}
$exportConfig = [
    'title' => 'Export beneficiaries',
    'hint' => 'Pick the program, status, and period to download. The table above is never affected.',
    'action' => 'beneficiaries.php',
    'hidden' => ['export' => 'csv'],
    'fields' => [
        ['label' => 'Program', 'name' => 'export_program', 'options' => $exportProgramOptions, 'full' => true],
        ['label' => 'Status', 'name' => 'export_status', 'options' => [
            ['', 'All statuses'],
            ['qualified', 'Pending (not yet applied)'],
            ['pending', 'Pending review'],
            ['approved', 'Approved'],
            ['rejected', 'Rejected'],
            ['retracted', 'No longer qualified'],
            ['existing', 'Existing beneficiary'],
        ]],
        ['label' => 'Year', 'name' => 'year', 'options' => export_year_options($exportYears)],
        ['label' => 'Month', 'name' => 'month', 'options' => export_month_options()],
    ],
];
include __DIR__ . '/partials/export-modal.php';
?>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
<script>
(function () {
  var body = document.getElementById('beneficiariesBody');
  if (!body) return;
  var searchInput = document.getElementById('beneficiary-search');
  var sourceSelect = document.getElementById('beneficiary-source');
  var statusSelect = document.getElementById('beneficiary-status');
  var sortSelect = document.getElementById('beneficiary-sort');
  var countEl = document.getElementById('beneficiaryCount');
  if (!searchInput || !sourceSelect || !statusSelect || !sortSelect || !countEl) return;

  var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-name]'));
  var total = rows.length;
  var emptyRow = null;
  // An empty list is rendered server-side with its own invitation copy -
  // nothing to filter, so leave that row alone.
  if (!total) return;

  function compareStrings(a, b) {
    return a < b ? -1 : (a > b ? 1 : 0);
  }

  function compareNumbers(a, b) {
    var na = parseFloat(a), nb = parseFloat(b);
    if (isNaN(na)) na = 0;
    if (isNaN(nb)) nb = 0;
    return na - nb;
  }

  function applyFilters() {
    var query = searchInput.value.trim().toLowerCase();
    var digits = /^\d+$/.test(query);
    var source = sourceSelect.value;
    var status = statusSelect.value;
    var sort = sortSelect.value;

    var matched = rows.filter(function (r) {
      if (query) {
        var byName = r.dataset.name.indexOf(query) !== -1;
        var byId = digits && r.dataset.id.indexOf(query) !== -1;
        if (!byName && !byId) return false;
      }
      if (source && r.dataset.source !== source) return false;
      if (status && r.dataset.status !== status) return false;
      return true;
    });

    matched.sort(function (a, b) {
      switch (sort) {
        case 'name-za':   return compareStrings(b.dataset.name, a.dataset.name);
        case 'age-asc':   return compareNumbers(a.dataset.age, b.dataset.age);
        case 'age-desc':  return compareNumbers(b.dataset.age, a.dataset.age);
        case 'added-new': return compareNumbers(b.dataset.added, a.dataset.added);
        case 'added-old': return compareNumbers(a.dataset.added, b.dataset.added);
        default:          return compareStrings(a.dataset.name, b.dataset.name);
      }
    });

    while (body.firstChild) body.removeChild(body.firstChild);
    for (var i = 0; i < matched.length; i++) body.appendChild(matched[i]);

    var filtered = !!(query || source || status || sort !== 'name-az');
    countEl.textContent = filtered ? matched.length + ' of ' + total : total;

    if (matched.length === 0) {
      if (!emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.innerHTML = '<td colspan="8" class="table-empty">No beneficiaries match your search or filter criteria.</td>';
      }
      body.appendChild(emptyRow);
    } else if (emptyRow) {
      emptyRow.remove();
      emptyRow = null;
    }
  }

  searchInput.addEventListener('input', applyFilters);
  sourceSelect.addEventListener('change', applyFilters);
  statusSelect.addEventListener('change', applyFilters);
  sortSelect.addEventListener('change', applyFilters);
  applyFilters();
})();
</script>
</body>
</html>
