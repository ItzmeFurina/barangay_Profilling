<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin();
no_cache();
$activePage = 'approved-beneficiaries';

$pdo = get_db_connection();

// For Approval holds submitted applications still awaiting a decision
// (claim_status = 'pending'). Approved beneficiaries live on the
// Beneficiaries page, one list per program; the eligibility engine never
// puts a no-application resident here.
$pendingStatusLabel = 'Pending review';

// CSV export filters come from the export picker popup - it deliberately
// ignores the table's toolbar state, so a report can be downloaded while
// the table shows something completely different.
if (($_GET['export'] ?? '') === 'csv') {
    $exportProgram = (int) ($_GET['export_program'] ?? 0);
    [$exportYear, $exportMonth] = export_period_from_query($_GET);

    $where = ["b.claim_status = 'pending'", 'hm.is_archived = FALSE'];
    $params = [];
    if ($exportProgram > 0) {
        $where[] = 'b.program_id = ?';
        $params[] = $exportProgram;
    }
    if ($exportYear !== null) {
        $where[] = 'YEAR(b.applied_at) = ?';
        $params[] = $exportYear;
    }
    if ($exportMonth !== null) {
        $where[] = 'MONTH(b.applied_at) = ?';
        $params[] = $exportMonth;
    }

    $exportSql = "SELECT b.beneficiary_id, b.applied_at, b.applicant_full_name, b.applicant_contact_number,
                         hm.first_name, hm.last_name,
                         h.house_number, h.street, h.barangay, h.city,
                         p.name AS program_name, p.category,
                         COALESCE(bd.doc_count, 0) AS uploaded_docs,
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
                  LEFT JOIN (SELECT beneficiary_id, COUNT(*) AS doc_count
                               FROM benefit_documents GROUP BY beneficiary_id) bd
                         ON bd.beneficiary_id = b.beneficiary_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY b.applied_at DESC, hm.last_name ASC";

    $stmt = $pdo->prepare($exportSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    [$periodLabel, $fileSuffix] = export_period_label($exportYear, $exportMonth);

    log_activity($pdo, $_SESSION['staff_user_id'], "Exported applications for approval ({$periodLabel}) - " . count($rows) . ' rows', 'read');

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="for_approval' . $fileSuffix . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Contact', 'Address', 'Program', 'Date Applied', 'Status', 'Documents Uploaded']);
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_guard', [
            $r['first_name'] . ' ' . $r['last_name'],
            $r['applicant_contact_number'] ?: ($r['household_contact_number'] ?: 'No contact on file'),
            format_household_address($r),
            $r['program_name'],
            $r['applied_at'] ? date('Y-m-d', strtotime($r['applied_at'])) : '',
            $pendingStatusLabel,
            (int) $r['uploaded_docs'],
        ]));
    }
    fclose($out);
    exit;
}

$programId = (int) ($_GET['program_id'] ?? 0);

if ($programId <= 0) {
    // ── Landing: one card per program that has applications waiting ─────
    $cards = $pdo->query(
        "SELECT p.program_id, p.name, p.description, p.category, p.application_mode,
                COUNT(b.beneficiary_id) AS pending_count
         FROM programs p
         JOIN beneficiaries b ON b.program_id = p.program_id AND b.claim_status = 'pending'
         JOIN household_members hm ON hm.member_id = b.member_id AND hm.is_archived = FALSE
         GROUP BY p.program_id, p.name, p.description, p.category, p.application_mode
         ORDER BY p.name ASC"
    )->fetchAll();
    $headerPending = (int) array_sum(array_column($cards, 'pending_count'));
} else {
    // ── Program detail: that program's applications awaiting review ─────
    $programStmt = $pdo->prepare("SELECT program_id, name, description, application_mode FROM programs WHERE program_id = ?");
    $programStmt->execute([$programId]);
    $program = $programStmt->fetch();
    if (!$program) {
        flash('error', 'That program was not found.');
        redirect('approved-beneficiaries.php');
    }

    // How many documents this program requires - one number shared by
    // every row in the list, so Requirements reads "2 of 3 uploaded".
    $reqStmt = $pdo->prepare("SELECT COUNT(*) FROM program_documents WHERE program_id = ?");
    $reqStmt->execute([$programId]);
    $requiredDocs = (int) $reqStmt->fetchColumn();

    // Submitted applications only. Loaded once, filtered client-side (the
    // same approach as the Beneficiaries list).
    $listSql = "SELECT b.beneficiary_id, b.program_id, b.claim_status, b.applied_at,
                       b.applicant_full_name, b.applicant_contact_number,
                       hm.first_name, hm.last_name, hm.member_id,
                       h.house_number, h.street, h.barangay, h.city,
                       p.name AS program_name, p.application_mode,
                       COALESCE(bd.doc_count, 0) AS uploaded_docs,
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
                LEFT JOIN (SELECT beneficiary_id, COUNT(*) AS doc_count
                             FROM benefit_documents GROUP BY beneficiary_id) bd
                       ON bd.beneficiary_id = b.beneficiary_id
                WHERE b.program_id = ?
                  AND b.claim_status = 'pending'
                  AND hm.is_archived = FALSE
                ORDER BY hm.last_name ASC, hm.first_name ASC";

    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute([$programId]);
    $applications = $listStmt->fetchAll();
    $headerPending = count($applications);
}

// Years with submitted applications, for the export picker's Year select.
$exportYears = $pdo->query(
    "SELECT DISTINCT YEAR(applied_at) AS y FROM beneficiaries
     WHERE claim_status = 'pending' AND applied_at IS NOT NULL ORDER BY y DESC"
)->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>For Approval - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div class="h1-group">
      <h1>For Approval</h1>
      <span class="stamp stamp-awaiting h1-stamp"><span id="applicationCount"><?= $headerPending ?></span> pending</span>
    </div>
    <a href="approved-beneficiaries.php?export=csv" class="logs-export" onclick="document.getElementById('export-modal').classList.add('is-open'); return false;">
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
    <h2>Programs with applications (<?= count($cards) ?>)</h2>
    <p class="muted-note" style="margin-top:-4px; margin-bottom:16px;">Open a program to review and decide the applications waiting for approval.</p>
    <?php if (empty($cards)): ?>
      <p class="table-empty">Nothing waiting for approval. Programs appear here the moment a resident submits an application.</p>
    <?php else: ?>
      <div class="program-card-grid">
        <?php foreach ($cards as $i => $card): ?>
          <a class="program-card program-card-pending reveal" style="--d: <?= min($i, 7) * 65 ?>ms" href="approved-beneficiaries.php?program_id=<?= (int) $card['program_id'] ?>">
            <p class="pc-eyebrow"><?= e($card['category'] ?: (($card['application_mode'] ?? 'application_required') === 'no_application_required' ? 'No application required' : 'Application required')) ?></p>
            <p class="pc-title"><?= e($card['name']) ?></p>
            <p class="pc-desc"><?= $card['description'] !== null && $card['description'] !== '' ? e($card['description']) : '<span class="muted-note">No description provided.</span>' ?></p>
            <div class="pc-count">
              <strong><?= (int) $card['pending_count'] ?></strong>
              <span>For Approval</span>
            </div>
            <span class="pc-open">Review applications &rarr;</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- ── Program applications awaiting review ──────────────────────── -->
  <p style="margin-bottom: 6px;"><a href="approved-beneficiaries.php">&larr; All programs</a></p>

  <div class="admin-section">
    <h2><?= e($program['name']) ?></h2>
    <p class="muted-note" style="margin-top:-4px; margin-bottom:16px;">
      Applications submitted and waiting for an administrative decision.
    </p>

    <div class="filter-bar">
      <div class="filter-search">
        <label for="application-search">Search</label>
        <div class="search-field">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="21" y1="21" x2="16.5" y2="16.5"></line>
          </svg>
          <input type="text" id="application-search" placeholder="Resident name or ID..." autocomplete="off">
        </div>
      </div>
      <div>
        <label for="application-status">Application Status</label>
        <select id="application-status">
          <option value="">All Statuses</option>
          <option value="pending">Pending Review</option>
        </select>
      </div>
      <div>
        <label for="application-docs">Requirements</label>
        <select id="application-docs">
          <option value="">All</option>
          <option value="complete">Complete</option>
          <option value="missing">Missing Documents</option>
        </select>
      </div>
      <div>
        <label for="application-sort">Sort</label>
        <select id="application-sort">
          <option value="name-az">Name (A&ndash;Z)</option>
          <option value="name-za">Name (Z&ndash;A)</option>
          <option value="applied-new">Date Applied (Newest)</option>
          <option value="applied-old">Date Applied (Oldest)</option>
        </select>
      </div>
    </div>

    <div class="table-scroll">
    <table class="admin-table" id="applications-table">
      <thead><tr><th>Name</th><th>Date Applied</th><th>Status</th><th>Requirements</th><th>Action</th></tr></thead>
      <tbody id="applicationsBody">
        <?php foreach ($applications as $a): ?>
          <?php
            $uploaded = (int) $a['uploaded_docs'];
            $docsComplete = $requiredDocs === 0 || $uploaded >= $requiredDocs;
          ?>
          <tr data-name="<?= e(strtolower($a['first_name'] . ' ' . $a['last_name'])) ?>"
              data-id="<?= (int) $a['member_id'] ?>"
              data-applied="<?= $a['applied_at'] ? (int) strtotime($a['applied_at']) : 0 ?>"
              data-status="<?= e($a['claim_status']) ?>"
              data-docs="<?= $docsComplete ? 'complete' : 'missing' ?>">
            <td>
              <?= e($a['first_name'] . ' ' . $a['last_name']) ?><span class="cell-id">#<?= (int) $a['member_id'] ?></span>
              <?php if (!empty($a['applicant_full_name']) && $a['applicant_full_name'] !== trim($a['first_name'] . ' ' . $a['last_name'])): ?>
                <div class="muted-note">Applied as: <?= e($a['applicant_full_name']) ?></div>
              <?php endif; ?>
            </td>
            <td class="muted-note cell-mono"><?= $a['applied_at'] ? e(date('M j, Y g:i A', strtotime($a['applied_at']))) : '&mdash;' ?></td>
            <td><span class="badge badge-pending"><?= e($pendingStatusLabel) ?></span></td>
            <td>
              <?php if ($requiredDocs === 0): ?>
                <span class="muted-note">None required</span>
              <?php elseif ($docsComplete): ?>
                <span class="badge badge-active">Complete</span>
                <div class="muted-note cell-mono" style="margin-top:4px;"><?= $uploaded ?> of <?= $requiredDocs ?> uploaded</div>
              <?php else: ?>
                <span class="badge badge-pending badge-ledger"><?= $uploaded ?> of <?= $requiredDocs ?> uploaded</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="view-beneficiary.php?id=<?= (int) $a['beneficiary_id'] ?>" class="btn-review">Review</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($applications)): ?>
          <tr><td colspan="5" class="table-empty">No applications waiting. Submitted applications appear here as soon as a resident applies.</td></tr>
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
    'title' => 'Export applications for approval',
    'hint' => 'Pick the program and application period to download. The table above is never affected.',
    'action' => 'approved-beneficiaries.php',
    'hidden' => ['export' => 'csv'],
    'fields' => [
        ['label' => 'Program', 'name' => 'export_program', 'options' => $exportProgramOptions, 'full' => true],
        ['label' => 'Year', 'name' => 'year', 'options' => export_year_options($exportYears)],
        ['label' => 'Month', 'name' => 'month', 'options' => export_month_options()],
    ],
];
include __DIR__ . '/partials/export-modal.php';
?>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
<script>
(function () {
  var body = document.getElementById('applicationsBody');
  if (!body) return;
  var searchInput = document.getElementById('application-search');
  var statusSelect = document.getElementById('application-status');
  var docsSelect = document.getElementById('application-docs');
  var sortSelect = document.getElementById('application-sort');
  var countEl = document.getElementById('applicationCount');
  if (!searchInput || !statusSelect || !docsSelect || !sortSelect || !countEl) return;

  var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-name]'));
  var total = rows.length;
  var emptyRow = null;
  // An empty queue is rendered server-side with its own invitation copy -
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
    var status = statusSelect.value;
    var docs = docsSelect.value;
    var sort = sortSelect.value;

    var matched = rows.filter(function (r) {
      if (query) {
        var byName = r.dataset.name.indexOf(query) !== -1;
        var byId = digits && r.dataset.id.indexOf(query) !== -1;
        if (!byName && !byId) return false;
      }
      if (status && r.dataset.status !== status) return false;
      if (docs && r.dataset.docs !== docs) return false;
      return true;
    });

    matched.sort(function (a, b) {
      switch (sort) {
        case 'name-za':     return compareStrings(b.dataset.name, a.dataset.name);
        case 'applied-new': return compareNumbers(b.dataset.applied, a.dataset.applied);
        case 'applied-old': return compareNumbers(a.dataset.applied, b.dataset.applied);
        default:            return compareStrings(a.dataset.name, b.dataset.name);
      }
    });

    while (body.firstChild) body.removeChild(body.firstChild);
    for (var i = 0; i < matched.length; i++) body.appendChild(matched[i]);

    var filtered = !!(query || status || docs || sort !== 'name-az');
    countEl.textContent = filtered ? matched.length + ' of ' + total : total;

    if (matched.length === 0) {
      if (!emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.innerHTML = '<td colspan="5" class="table-empty">No applications match your search or filter criteria.</td>';
      }
      body.appendChild(emptyRow);
    } else if (emptyRow) {
      emptyRow.remove();
      emptyRow = null;
    }
  }

  searchInput.addEventListener('input', applyFilters);
  statusSelect.addEventListener('change', applyFilters);
  docsSelect.addEventListener('change', applyFilters);
  sortSelect.addEventListener('change', applyFilters);
  applyFilters();
})();
</script>
</body>
</html>
