<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin();
$activePage = 'logs';

$pdo = get_db_connection();

$roleFilter = $_GET['role'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
if (in_array($roleFilter, ['resident', 'admin', 'staff'], true)) {
    $where = 'WHERE u.role = ?';
    $params[] = $roleFilter;
}
// Legacy rows (logged before the CRUD column existed) have category NULL,
// so a concrete filter never matches them - they only show under "All".
if (in_array($categoryFilter, ['create', 'read', 'update', 'delete', 'validate'], true)) {
    $where .= ($where === '' ? 'WHERE ' : ' AND ') . 'al.category = ?';
    $params[] = $categoryFilter;
}

if (($_GET['export'] ?? '') === 'csv') {
    [$exportYear, $exportMonth] = export_period_from_query($_GET);

    $exportWhere = $where;
    $exportParams = $params;
    if ($exportYear !== null) {
        $exportWhere .= ($exportWhere === '' ? 'WHERE ' : ' AND ') . 'YEAR(al.created_at) = ?';
        $exportParams[] = $exportYear;
    }
    if ($exportMonth !== null) {
        // Must pick WHERE vs AND itself: with no other filter $exportWhere is
        // still '' and a bare "AND MONTH(...)" is a SQL syntax error (500).
        $exportWhere .= ($exportWhere === '' ? 'WHERE ' : ' AND ') . 'MONTH(al.created_at) = ?';
        $exportParams[] = $exportMonth;
    }

    $stmt = $pdo->prepare(
        "SELECT al.category, al.action, al.created_at, u.full_name, u.role
         FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id
         {$exportWhere} ORDER BY al.created_at DESC"
    );
    $stmt->execute($exportParams);
    $allLogs = $stmt->fetchAll();

    [$periodLabel, $fileSuffix] = export_period_label($exportYear, $exportMonth);

    log_activity($pdo, $_SESSION['staff_user_id'], "Exported activity logs ({$periodLabel}) - " . count($allLogs) . ' rows', 'read');

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="activity_logs' . $fileSuffix . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'Who', 'Role', 'Category', 'Action']);
    foreach ($allLogs as $log) {
        fputcsv($out, array_map('csv_guard', [
            date('Y-m-d H:i:s', strtotime($log['created_at'])),
            $log['full_name'] ?? 'Deleted user',
            $log['role'] ? ucfirst($log['role']) : '',
            $log['category'] ?? '',
            $log['action'],
        ]));
    }
    fclose($out);
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id {$where}");
$countStmt->execute($params);
$totalLogs = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLogs / $perPage));

// Per-role tallies for the filter pills (unfiltered, so the counts are
// always stable whatever filter is currently active).
$roleCounts = ['all' => $totalLogs];
foreach (['resident', 'staff', 'admin'] as $r) {
    $roleStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM activity_logs al JOIN users u ON u.user_id = al.user_id WHERE u.role = ?"
    );
    $roleStmt->execute([$r]);
    $roleCounts[$r] = (int) $roleStmt->fetchColumn();
}

// Per-category tallies for the CRUD filter pills (also unfiltered, for
// the same "stable whatever filter is active" reason as the role ones).
$categoryCounts = ['all' => $totalLogs];
foreach (['create', 'read', 'update', 'delete', 'validate'] as $c) {
    $catStmt = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE category = ?');
    $catStmt->execute([$c]);
    $categoryCounts[$c] = (int) $catStmt->fetchColumn();
}

// Available years for the CSV export period picker.
$logYears = $pdo->query("SELECT DISTINCT YEAR(created_at) AS y FROM activity_logs ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare(
    "SELECT al.category, al.action, al.created_at, u.full_name, u.role
     FROM activity_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     {$where}
     ORDER BY al.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Activity Logs - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
  /* Role filter - independent pills, spaced apart, not a segmented track. */
  .role-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .role-filter-btn {
    display: inline-flex;
    align-items: center;
    padding: 8px 18px;
    border-radius: 999px;
    background: var(--white);
    box-shadow: inset 0 0 0 1px var(--border), 0 2px 8px -4px rgba(11, 46, 92, 0.12);
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--muted);
    text-decoration: none;
    white-space: nowrap;
    transition: color 0.4s var(--ease-expo), background-color 0.4s var(--ease-expo), box-shadow 0.4s var(--ease-expo), transform 0.4s var(--ease-expo);
  }
  .role-filter-btn:hover {
    color: var(--navy);
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: inset 0 0 0 1px var(--border-strong), 0 4px 12px -5px rgba(11, 46, 92, 0.18);
  }
  .role-filter-btn:active { transform: scale(0.97); }
  .role-filter-btn.is-active {
    background: var(--navy);
    color: #FFFFFF;
    box-shadow: 0 4px 12px -4px rgba(11, 46, 92, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.15);
  }
  .role-filter-btn.is-active:hover { color: #FFFFFF; }

  /* Count chip inside each filter pill - tabular numbers so the digits
     don't jiggle while the counts update. */
  .rf-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    margin-left: 7px;
    border-radius: 999px;
    background: rgba(11, 46, 92, 0.08);
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    transition: background-color 0.4s var(--ease-expo), color 0.4s var(--ease-expo);
  }
  .role-filter-btn:hover .rf-count { background: rgba(11, 46, 92, 0.12); color: var(--navy); }
  .role-filter-btn.is-active .rf-count { background: rgba(255, 255, 255, 0.16); color: #FFFFFF; }

  /* Pager: pill page numbers, current page filled navy. */
  .logs-pager { display: flex; gap: 6px; margin-top: 18px; }
  .page-btn {
    min-width: 34px;
    height: 34px;
    padding: 0 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: var(--white);
    box-shadow: inset 0 0 0 1px var(--border);
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    text-decoration: none;
    transition: color 0.4s var(--ease-expo), box-shadow 0.4s var(--ease-expo), transform 0.4s var(--ease-expo), background-color 0.4s var(--ease-expo);
  }
  .page-btn:hover {
    color: var(--navy);
    box-shadow: inset 0 0 0 1px var(--border-strong);
    text-decoration: none;
    transform: translateY(-1px);
  }
  .page-btn:active { transform: scale(0.97); }
  .page-btn.is-current {
    background: var(--navy);
    color: #FFFFFF;
    box-shadow: 0 4px 12px -4px rgba(11, 46, 92, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.15);
  }
  .page-btn.is-current:hover { color: #FFFFFF; }

  /* CRUD category badge colors - reuse the semantic palette. */
  .badge-cat-create { background: var(--success-bg); color: var(--success); }
  .badge-cat-read   { background: rgba(11, 46, 92, 0.08); color: var(--muted); }
  .badge-cat-update { background: var(--warning-bg); color: var(--warning-text); }
  .badge-cat-delete { background: var(--danger-bg); color: var(--danger); }
  .badge-cat-validate { background: rgba(106, 44, 132, 0.10); color: #6A2C84; }

  @media (prefers-reduced-motion: reduce) {
    .role-filter-btn, .rf-count, .page-btn { transition: none; }
  }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>Activity Logs</h1>
  <p class="admin-section-desc">Every login, registration, census, program change, and account action is recorded here - covers residents, staff, and admins alike, not just admin actions.</p>

  <div class="admin-section">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
      <div style="display:flex; flex-direction:column; gap:10px; flex-wrap:wrap;">
        <div class="role-filter" role="group" aria-label="Filter logs by role">
          <a href="?role=&category=<?= e($categoryFilter) ?>" class="role-filter-btn <?= $roleFilter === '' ? 'is-active' : '' ?>">All<span class="rf-count"><?= $roleCounts['all'] ?></span></a>
          <a href="?role=resident&category=<?= e($categoryFilter) ?>" class="role-filter-btn <?= $roleFilter === 'resident' ? 'is-active' : '' ?>">Residents<span class="rf-count"><?= $roleCounts['resident'] ?></span></a>
          <a href="?role=staff&category=<?= e($categoryFilter) ?>" class="role-filter-btn <?= $roleFilter === 'staff' ? 'is-active' : '' ?>">Staff<span class="rf-count"><?= $roleCounts['staff'] ?></span></a>
          <a href="?role=admin&category=<?= e($categoryFilter) ?>" class="role-filter-btn <?= $roleFilter === 'admin' ? 'is-active' : '' ?>">Admin<span class="rf-count"><?= $roleCounts['admin'] ?></span></a>
        </div>
        <div class="role-filter" role="group" aria-label="Filter logs by category">
          <a href="?category=&role=<?= e($roleFilter) ?>" class="role-filter-btn <?= $categoryFilter === '' ? 'is-active' : '' ?>">All<span class="rf-count"><?= $categoryCounts['all'] ?></span></a>
          <a href="?category=create&role=<?= e($roleFilter) ?>" class="role-filter-btn <?= $categoryFilter === 'create' ? 'is-active' : '' ?>">Create<span class="rf-count"><?= $categoryCounts['create'] ?></span></a>
          <a href="?category=read&role=<?= e($roleFilter) ?>" class="role-filter-btn <?= $categoryFilter === 'read' ? 'is-active' : '' ?>">Read<span class="rf-count"><?= $categoryCounts['read'] ?></span></a>
          <a href="?category=update&role=<?= e($roleFilter) ?>" class="role-filter-btn <?= $categoryFilter === 'update' ? 'is-active' : '' ?>">Update<span class="rf-count"><?= $categoryCounts['update'] ?></span></a>
          <a href="?category=delete&role=<?= e($roleFilter) ?>" class="role-filter-btn <?= $categoryFilter === 'delete' ? 'is-active' : '' ?>">Delete<span class="rf-count"><?= $categoryCounts['delete'] ?></span></a>
          <a href="?category=validate&role=<?= e($roleFilter) ?>" class="role-filter-btn <?= $categoryFilter === 'validate' ? 'is-active' : '' ?>">Validate<span class="rf-count"><?= $categoryCounts['validate'] ?></span></a>
        </div>
      </div>
      <span class="muted-note"><?= $totalLogs ?> total entries</span>
      <a href="?role=<?= e($roleFilter) ?>&category=<?= e($categoryFilter) ?>&export=csv" class="logs-export" onclick="document.getElementById('export-modal').classList.add('is-open'); return false;">
        <span>Download CSV</span>
        <span class="logs-export-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/></svg>
        </span>
      </a>
    </div>

    <div class="table-scroll">
    <table class="admin-table" style="table-layout:fixed;">
      <thead>
        <tr><th style="width:160px;">When</th><th style="width:100px;">Category</th><th style="width:180px;">Who</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="4" class="muted-note">No activity recorded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td class="muted-note"><?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
            <td>
              <?php if (empty($log['category'])): ?>
                <span class="muted-note">-</span>
              <?php else: ?>
                <span class="badge badge-cat badge-cat-<?= e($log['category']) ?>"><?= e(ucfirst($log['category'])) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?= e($log['full_name'] ?? 'Deleted user') ?>
              <?php if ($log['role']): ?><div class="muted-note"><?= e(ucfirst($log['role'])) ?></div><?php endif; ?>
            </td>
            <td><?= e($log['action']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="logs-pager" role="navigation" aria-label="Log pages">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?role=<?= e($roleFilter) ?>&category=<?= e($categoryFilter) ?>&page=<?= $p ?>" class="page-btn <?= $p === $page ? 'is-current' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

<?php
$exportConfig = [
    'title' => 'Export activity logs',
    'hint' => 'Pick a year, and optionally a month, to download. The active role and category filters are preserved.',
    'action' => 'logs.php',
    'hidden' => ['export' => 'csv', 'role' => $roleFilter, 'category' => $categoryFilter],
    'fields' => [
        ['label' => 'Year', 'name' => 'year', 'options' => export_year_options($logYears)],
        ['label' => 'Month', 'name' => 'month', 'options' => export_month_options()],
    ],
];
include __DIR__ . '/partials/export-modal.php';
?>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
