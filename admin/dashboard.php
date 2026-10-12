<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

require_admin_login();
no_cache();
$activePage = 'dashboard';

$pdo = get_db_connection();
$successMessage = flash('success');

// Optional month/year filter for the statistics: "All time" (no query
// parameters) is the default; a year alone shows the whole year, and
// year+month narrows down to a single month. Counts below reflect records
// created (or active) inside the chosen period - snapshots like the sex
// and age distributions always show the current state instead.
[$filterYear, $filterMonth] = export_period_from_query($_GET);
if ($filterYear !== null && $filterYear > (int) date('Y')) {
    $filterYear = null;
    $filterMonth = null;
}

function filter_period_bounds(?int $year, ?int $month): array
{
    if ($year === null) {
        return [null, null];
    }
    $m = $month ?? 1;
    $start = sprintf('%04d-%02d-01', $year, $m);
    $end = ($month === null || $month === 12)
        ? sprintf('%04d-01-01', $year + 1)
        : sprintf('%04d-%02d-01', $year, $month + 1);
    return [$start, $end];
}

[$filterStart, $filterEnd] = filter_period_bounds($filterYear, $filterMonth);
$filterActive = $filterYear !== null;
$filterLabel = '';
if ($filterActive) {
    [$filterLabel] = export_period_label($filterYear, $filterMonth);
}
$chartYear = $filterYear ?? (int) date('Y');
$currentMonth = $filterMonth ?? (int) date('n');
$periodIsPast = $filterActive && $filterEnd !== null && $filterEnd <= date('Y-m-d');

$currentYear = (int) date('Y');
$filterYears = array_values(array_unique(array_merge(
    [$currentYear],
    array_map('intval', $pdo->query('SELECT DISTINCT YEAR(created_at) y FROM household_members WHERE created_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)),
    array_map('intval', $pdo->query('SELECT DISTINCT YEAR(created_at) y FROM users WHERE created_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)),
    array_map('intval', $pdo->query('SELECT DISTINCT YEAR(event_date) y FROM events WHERE event_date IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)),
    array_map('intval', $pdo->query('SELECT DISTINCT YEAR(reviewed_at) y FROM beneficiaries WHERE reviewed_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN))
)));
$filterYears = array_values(array_filter($filterYears, fn($y) => $y <= $currentYear));
rsort($filterYears);
if (empty($filterYears)) {
    $filterYears = [$currentYear];
}

$totalEvents = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
$upcomingEventsCount = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn();
$pastEventsCount = $totalEvents - $upcomingEventsCount;
$upcomingEvents = $pdo->query("SELECT title, event_date FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5")->fetchAll();

if (admin_role() === 'admin') {
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'pending'")->fetchColumn();
    $activeProgramsForStat = (int) $pdo->query(
        "SELECT COUNT(*) FROM programs
         WHERE status = 'active'
           AND (start_date IS NULL OR start_date <= NOW())
           AND (end_date IS NULL OR end_date >= NOW())"
    )->fetchColumn();
    $totalHouseholds = (int) $pdo->query('SELECT COUNT(DISTINCT hm.household_id) FROM household_members hm WHERE hm.is_archived = FALSE')->fetchColumn();
    // Applications waiting for administrative review right now - always a
    // live total, because the For Approval page shows every pending row
    // regardless of the dashboard's period filter.
    $forApprovalCount = (int) $pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE claim_status = 'pending'")->fetchColumn();

    // One pass over household_members for every demographic figure, rather
    // than a dozen separate COUNT queries. The period filter narrows every
    // figure to residents who were active inside the chosen period.
    $memberWhere = 'is_archived = FALSE' . ($filterActive ? ' AND created_at >= ? AND created_at < ?' : '');
    $memberParams = $filterActive ? [$filterStart, $filterEnd] : [];

    $statsStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_residents,
            SUM(sex = 'Male') AS male_count,
            SUM(sex = 'Female') AS female_count,
            SUM(sex IS NULL OR sex = '') AS unspecified_count,
            SUM(birthdate IS NOT NULL AND TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) >= 60) AS senior_count,
            SUM(is_solo_parent = 1) AS solo_parent_count,
            SUM(is_ofw = 1) AS ofw_count,
            SUM(is_pwd = 1) AS pwd_count,
            SUM(is_osy = 1) AS osy_count,
            SUM(is_ip = 1) AS ip_count,
            SUM(is_unemployed = 1) AS unemployed_count,
            SUM(is_employed = 1) AS employed_count,
            SUM(is_student = 1) AS student_count
         FROM household_members
         WHERE $memberWhere"
    );
    $statsStmt->execute($memberParams);
    $stats = $statsStmt->fetch();

    // Stat-card numbers. With a period filter these become "records created
    // (or active) in that period"; the demographic charts below are built
    // from $stats/$ageGroups, which use the same $memberWhere so they stay
    // in sync with the filter too.
    $residentCount       = (int) $stats['total_residents'];
    $householdCount      = (int) $totalHouseholds;
    $pendingAccountCount = (int) $pendingCount;
    $accountsCardLabel   = 'Accounts awaiting approval';
    $activeProgramCount  = (int) $activeProgramsForStat;
    $eventsCardCount     = (int) $upcomingEventsCount;
    $eventsCardLink      = 'events.php?filter=upcoming';
    $eventsCardLabel     = 'Upcoming events';

    if ($filterActive) {
        $countInPeriod = static function (string $sql) use ($pdo, $filterStart, $filterEnd): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$filterStart, $filterEnd]);
            return (int) $stmt->fetchColumn();
        };
        $residentCount       = $countInPeriod("SELECT COUNT(*) FROM household_members WHERE is_archived = FALSE AND created_at >= ? AND created_at < ?");
        $householdCount      = $countInPeriod("SELECT COUNT(DISTINCT hm.household_id) FROM household_members hm WHERE hm.is_archived = FALSE AND hm.created_at >= ? AND hm.created_at < ?");
        if ($periodIsPast) {
            $pendingAccountCount = $countInPeriod("SELECT COUNT(*) FROM users WHERE account_status = 'active' AND created_at >= ? AND created_at < ?");
            $accountsCardLabel   = 'Accounts approved in ' . $filterLabel;
        } else {
            $pendingAccountCount = $countInPeriod("SELECT COUNT(*) FROM users WHERE account_status = 'pending' AND created_at >= ? AND created_at < ?");
        }
        $activeProgramCount  = $countInPeriod("SELECT COUNT(*) FROM programs WHERE status = 'active' AND created_at >= ? AND created_at < ?");
        $eventsCardCount     = $countInPeriod("SELECT COUNT(*) FROM events WHERE event_date >= ? AND event_date < ?");
        $eventsCardLink      = 'events.php';
        $eventsCardLabel     = 'Events in ' . $filterLabel;
    }

    $demographics = [
        'Seniors (60+)'        => (int) $stats['senior_count'],
        'PWD'                   => (int) $stats['pwd_count'],
        'Solo parent'           => (int) $stats['solo_parent_count'],
        'OFW'                   => (int) $stats['ofw_count'],
        'Out-of-school youth'   => (int) $stats['osy_count'],
        'Indigenous person'     => (int) $stats['ip_count'],
        'Unemployed'            => (int) $stats['unemployed_count'],
        'Employed'              => (int) $stats['employed_count'],
        'Student'               => (int) $stats['student_count'],
    ];
    $maxDemo = max(array_merge([1], array_values($demographics)));

    $ageGroups = [];
    $ageConditions = [
        '0 - 17'  => 'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) < 18',
        '18 - 29' => 'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 18 AND 29',
        '30 - 44' => 'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 30 AND 44',
        '45 - 59' => 'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 45 AND 59',
    ];
    foreach ($ageConditions as $label => $condition) {
        $ageStmt = $pdo->prepare("SELECT COUNT(*) FROM household_members WHERE birthdate IS NOT NULL AND $condition AND $memberWhere");
        $ageStmt->execute($memberParams);
        $ageGroups[$label] = (int) $ageStmt->fetchColumn();
    }
    $ageGroups['60+'] = (int) $stats['senior_count'];
    $maxAge = max(array_merge([1], array_values($ageGroups)));

    $claimCounts = ['qualified' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    if ($filterActive) {
        $claimStmt = $pdo->prepare(
            "SELECT claim_status, COUNT(*) AS c FROM beneficiaries
             WHERE (claim_status = 'qualified' AND qualified_at >= ? AND qualified_at < ?)
                OR (claim_status = 'pending' AND applied_at >= ? AND applied_at < ?)
                OR (claim_status IN ('approved','rejected') AND reviewed_at >= ? AND reviewed_at < ?)
             GROUP BY claim_status"
        );
        $claimStmt->execute([$filterStart, $filterEnd, $filterStart, $filterEnd, $filterStart, $filterEnd]);
        foreach ($claimStmt->fetchAll() as $row) {
            $claimCounts[$row['claim_status']] = (int) $row['c'];
        }
        $pickupStmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries WHERE pickup_status = 'claimed' AND claim_status = 'approved' AND claimed_at >= ? AND claimed_at < ?");
        $pickupStmt->execute([$filterStart, $filterEnd]);
        $claimedPickup = (int) $pickupStmt->fetchColumn();
    } else {
        foreach ($pdo->query('SELECT claim_status, COUNT(*) AS c FROM beneficiaries GROUP BY claim_status')->fetchAll() as $row) {
            $claimCounts[$row['claim_status']] = (int) $row['c'];
        }
        $claimedPickup = (int) $pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE pickup_status = 'claimed'")->fetchColumn();
    }
    $claimTotal = max(1, array_sum($claimCounts));

    $streetStmt = $pdo->prepare(
    'SELECT h.street, COUNT(DISTINCT h.household_id) AS c
     FROM households h
     JOIN household_members hm ON hm.household_id = h.household_id
     WHERE ' . $memberWhere . '
     GROUP BY h.street ORDER BY c DESC'
);
    $streetStmt->execute($memberParams);
    $streetRows = $streetStmt->fetchAll();
    $maxStreet = max(array_merge([1], array_column($streetRows, 'c')));

    $maleCount = (int) $stats['male_count'];
    $femaleCount = (int) $stats['female_count'];
    $unspecifiedCount = (int) $stats['unspecified_count'];
    $sexTotal = $maleCount + $femaleCount + $unspecifiedCount;
    if ($sexTotal > 0) {
        $malePct = round($maleCount / $sexTotal * 100);
        $femalePct = round($femaleCount / $sexTotal * 100);
        $unspecifiedPct = 100 - $malePct - $femalePct;
    } else {
        $malePct = $femalePct = $unspecifiedPct = 0;
    }
    $maleDeg = round($malePct * 3.6);
    $femaleDeg = round($femalePct * 3.6);
    $donutMiddleStop = $maleDeg + $femaleDeg;
    $donutGradient = $sexTotal > 0
        ? "var(--blue) 0deg {$maleDeg}deg, var(--gold) {$maleDeg}deg {$donutMiddleStop}deg" . ($unspecifiedCount > 0 ? ", var(--muted) {$donutMiddleStop}deg 360deg" : '')
        : 'var(--muted) 0deg 360deg';
}

// Events per month for the selected year (defaults to the current year)
// and new resident accounts for the same year - shared by both views.
$monthCounts = array_fill(1, 12, 0);
$monthStmt = $pdo->prepare('SELECT MONTH(event_date) AS m, COUNT(*) AS c FROM events WHERE YEAR(event_date) = ? GROUP BY MONTH(event_date)');
$monthStmt->execute([$chartYear]);
foreach ($monthStmt->fetchAll() as $row) {
    $monthCounts[(int) $row['m']] = (int) $row['c'];
}
$maxMonthCount = max(array_merge([1], array_values($monthCounts)));
$monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$regMonthCounts = array_fill(1, 12, 0);
$regStmt = $pdo->prepare("SELECT MONTH(created_at) AS m, COUNT(*) AS c FROM users WHERE role = 'resident' AND YEAR(created_at) = ? GROUP BY MONTH(created_at)");
$regStmt->execute([$chartYear]);
foreach ($regStmt->fetchAll() as $row) {
    $regMonthCounts[(int) $row['m']] = (int) $row['c'];
}
$maxRegMonth = max(array_merge([1], array_values($regMonthCounts)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Dashboard - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>Welcome, <?= e($_SESSION['staff_full_name']) ?></h1>
  <?php if ($successMessage): ?>
    <div class="alert alert-success"><?= e($successMessage) ?></div>
  <?php endif; ?>

  <?php if (admin_role() === 'admin'): ?>

    <form method="GET" action="dashboard.php" class="filter-bar" style="justify-content:flex-end;">
      <div>
        <label for="filter-year">Year</label>
        <select id="filter-year" name="year">
          <?php foreach (export_year_options($filterYears) as $y): ?>
            <option value="<?= e((string) $y[0]) ?>" <?= $filterYear !== null && $filterYear === (int) $y[0] ? 'selected' : '' ?>><?= e($y[1]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filter-month">Month</label>
        <select id="filter-month" name="month">
          <?php foreach (export_month_options() as $m): ?>
            <option value="<?= e((string) $m[0]) ?>" <?= $filterMonth !== null && $filterMonth === (int) $m[0] ? 'selected' : '' ?>><?= e($m[1]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit">Apply</button>
      <?php if ($filterActive): ?>
        <a href="dashboard.php" class="btn-archive" style="height:44px; display:inline-flex; align-items:center; padding:0 20px;">Reset</a>
      <?php endif; ?>
    </form>

    <?php if ($filterActive): ?>
      <div style="background:var(--warning-bg); color:var(--warning-text); padding:12px 16px; border-radius:10px; font-size:15px; margin:14px 0 0;">
        Showing statistics for <strong><?= e($filterLabel) ?></strong> &mdash; the numbers below count only records created (or active) in that period. <a href="dashboard.php" style="color:inherit; font-weight:600;">View all time</a>
      </div>
    <?php endif; ?>

    <div class="stat-grid" style="margin-top:18px;">
      <a href="residents.php" class="reveal" style="text-decoration:none; --d:60ms">
        <div class="stat-card stat-accent-blue">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
          <div class="stat-number"><?= $residentCount ?></div>
          <div class="stat-label">Registered residents</div>
        </div>
      </a>
      <a href="residents.php" class="reveal" style="text-decoration:none; --d:120ms">
        <div class="stat-card stat-accent-navy">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg></span>
          <div class="stat-number"><?= $householdCount ?></div>
          <div class="stat-label">Households</div>
        </div>
      </a>
      <a href="accounts.php" class="reveal" style="text-decoration:none; --d:180ms">
        <div class="stat-card stat-accent-gold">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
          <div class="stat-number"><?= $pendingAccountCount ?></div>
          <div class="stat-label"><?= e($accountsCardLabel) ?></div>
        </div>
      </a>
      <a href="programs.php" class="reveal" style="text-decoration:none; --d:240ms">
        <div class="stat-card stat-accent-green">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/></svg></span>
          <div class="stat-number"><?= $activeProgramCount ?></div>
          <div class="stat-label">Active programs</div>
        </div>
      </a>
      <a href="approved-beneficiaries.php" class="reveal" style="text-decoration:none; --d:300ms">
        <div class="stat-card stat-accent-gold">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.5 2.5 2.5 5-6"/></svg></span>
          <div class="stat-number"><?= $forApprovalCount ?></div>
          <div class="stat-label">For Approval</div>
        </div>
      </a>
      <a href="<?= e($eventsCardLink) ?>" class="reveal" style="text-decoration:none; --d:360ms">
        <div class="stat-card stat-accent-blue">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="2.5"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M3.5 10.5h17"/></svg></span>
          <div class="stat-number"><?= $eventsCardCount ?></div>
          <div class="stat-label"><?= e($eventsCardLabel) ?></div>
        </div>
      </a>
    </div>

    <div class="dash-grid">
      <div class="admin-section reveal" style="--d:440ms">
        <h2>Population by sex<?= $filterActive ? ' - ' . e($filterLabel) : '' ?></h2>
        <div class="donut-wrap">
          <div class="donut" style="background: conic-gradient(<?= $donutGradient ?>);">
            <div class="donut-center">
              <div class="donut-total"><?= $sexTotal ?></div>
              <div class="donut-total-label">residents</div>
            </div>
          </div>
          <div class="donut-legend">
            <span><span class="split-bar-swatch" style="background:var(--blue);"></span>Male - <?= $maleCount ?> (<?= $malePct ?>%)</span>
            <span><span class="split-bar-swatch" style="background:var(--gold);"></span>Female - <?= $femaleCount ?> (<?= $femalePct ?>%)</span>
            <?php if ($unspecifiedCount > 0): ?>
              <span><span class="split-bar-swatch" style="background:var(--muted);"></span>Unspecified - <?= $unspecifiedCount ?> (<?= $unspecifiedPct ?>%)</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="admin-section reveal" style="--d:500ms">
        <h2>Age distribution<?= $filterActive ? ' - ' . e($filterLabel) : '' ?></h2>
        <div class="bar-chart">
          <?php foreach ($ageGroups as $label => $count): ?>
            <div class="bar-row">
              <div class="bar-row-label"><?= e($label) ?></div>
              <div class="bar-track">
                <?php if ($count > 0): ?>
                  <div class="bar-fill" style="width:<?= max(2, round($count / $maxAge * 100)) ?>%; background:var(--navy);"></div>
                <?php endif; ?>
              </div>
              <div class="bar-row-value<?= $count === 0 ? ' bar-row-value-zero' : '' ?>"><?= $count ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="dash-grid">
      <div class="admin-section">
        <h2>Benefit applications<?= $filterActive ? ' - ' . e($filterLabel) : '' ?></h2>
        <div class="stack-track">
          <?php if ($claimTotal > 1): ?>
            <div class="stack-seg" style="width:<?= round($claimCounts['approved'] / $claimTotal * 100) ?>%; background:var(--success);"><?= $claimCounts['approved'] > 0 ? $claimCounts['approved'] : '' ?></div>
            <div class="stack-seg" style="width:<?= round($claimCounts['pending'] / $claimTotal * 100) ?>%; background:var(--gold); color:var(--gold-text);"><?= $claimCounts['pending'] > 0 ? $claimCounts['pending'] : '' ?></div>
            <div class="stack-seg" style="width:<?= round($claimCounts['qualified'] / $claimTotal * 100) ?>%; background:var(--blue);"><?= $claimCounts['qualified'] > 0 ? $claimCounts['qualified'] : '' ?></div>
            <div class="stack-seg" style="width:<?= round($claimCounts['rejected'] / $claimTotal * 100) ?>%; background:var(--danger);"><?= $claimCounts['rejected'] > 0 ? $claimCounts['rejected'] : '' ?></div>
          <?php endif; ?>
        </div>
        <div class="stack-legend">
          <span><span class="split-bar-swatch" style="background:var(--success);"></span>Approved - <?= $claimCounts['approved'] ?></span>
          <span><span class="split-bar-swatch" style="background:var(--gold);"></span>Pending - <?= $claimCounts['pending'] ?></span>
          <span><span class="split-bar-swatch" style="background:var(--blue);"></span>Qualified - <?= $claimCounts['qualified'] ?></span>
          <span><span class="split-bar-swatch" style="background:var(--danger);"></span>Rejected - <?= $claimCounts['rejected'] ?></span>
        </div>
        <p class="muted-note" style="margin-top:14px;"><?= $claimedPickup ?> of <?= $claimCounts['approved'] ?> approved benefit(s) already picked up.</p>
      </div>

      <div class="admin-section reveal" style="--d:560ms">
        <h2>Households by street<?= $filterActive ? ' - ' . e($filterLabel) : '' ?></h2>
        <?php if (empty($streetRows)): ?>
          <p class="muted-note">No households recorded yet.</p>
        <?php else: ?>
          <div class="bar-chart">
            <?php foreach ($streetRows as $row): ?>
              <div class="bar-row">
                <div class="bar-row-label"><?= e($row['street']) ?></div>
                <div class="bar-track">
                  <?php if ((int) $row['c'] > 0): ?>
                    <div class="bar-fill" style="width:<?= max(2, round($row['c'] / $maxStreet * 100)) ?>%; background:var(--gold);"></div>
                  <?php endif; ?>
                </div>
                <div class="bar-row-value"><?= (int) $row['c'] ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-section reveal" style="--d:620ms">
      <h2>Demographics<?= $filterActive ? ' - ' . e($filterLabel) : '' ?></h2>
      <div class="bar-chart">
        <?php foreach ($demographics as $label => $count): ?>
          <div class="bar-row">
            <div class="bar-row-label"><?= e($label) ?></div>
            <div class="bar-track">
              <?php if ($count > 0): ?>
                <div class="bar-fill" style="width:<?= max(2, round($count / $maxDemo * 100)) ?>%;"></div>
              <?php endif; ?>
            </div>
            <div class="bar-row-value<?= $count === 0 ? ' bar-row-value-zero' : '' ?>"><?= $count ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="dash-grid">
      <div class="admin-section reveal" style="--d:680ms">
        <h2>Events in <?= (int) $chartYear ?>, by month</h2>
        <div class="month-chart">
          <?php foreach ($monthLabels as $i => $label): $m = $i + 1; $count = $monthCounts[$m]; ?>
            <div class="month-chart-col">
              <?php if ($count > 0): ?><div class="month-chart-count"><?= $count ?></div><?php endif; ?>
              <?php if ($count > 0): ?>
                <div class="month-chart-bar <?= $m === $currentMonth ? 'month-chart-bar-current' : '' ?>" style="height:<?= max(3, round($count / $maxMonthCount * 100)) ?>%;"></div>
              <?php else: ?>
                <div class="month-chart-bar month-chart-bar-empty<?= $m === $currentMonth ? ' month-chart-bar-current-empty' : '' ?>"></div>
              <?php endif; ?>
              <div class="month-chart-label"><?= $label ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="admin-section reveal" style="--d:740ms">
        <h2>New resident accounts</h2>
        <div class="month-chart">
          <?php foreach ($monthLabels as $i => $label): $m = $i + 1; $count = $regMonthCounts[$m]; ?>
            <div class="month-chart-col">
              <?php if ($count > 0): ?><div class="month-chart-count"><?= $count ?></div><?php endif; ?>
              <?php if ($count > 0): ?>
                <div class="month-chart-bar <?= $m === $currentMonth ? 'month-chart-bar-current' : '' ?>" style="height:<?= max(3, round($count / $maxRegMonth * 100)) ?>%;"></div>
              <?php else: ?>
                <div class="month-chart-bar month-chart-bar-empty<?= $m === $currentMonth ? ' month-chart-bar-current-empty' : '' ?>"></div>
              <?php endif; ?>
              <div class="month-chart-label"><?= $label ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  <?php else: ?>

    <div class="stat-grid" style="margin-top:18px;">
      <a href="events.php" class="reveal" style="text-decoration:none; --d:60ms">
        <div class="stat-card stat-accent-navy">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="2.5"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M3.5 10.5h17"/></svg></span>
          <div class="stat-number"><?= $totalEvents ?></div>
          <div class="stat-label">Total events</div>
        </div>
      </a>
      <a href="events.php?filter=upcoming" class="reveal" style="text-decoration:none; --d:120ms">
        <div class="stat-card stat-accent-blue">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="2.5"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M3.5 10.5h17"/><path d="M15 15.5h-6"/></svg></span>
          <div class="stat-number"><?= $upcomingEventsCount ?></div>
          <div class="stat-label">Upcoming events</div>
        </div>
      </a>
      <a href="events.php?filter=past" class="reveal" style="text-decoration:none; --d:180ms">
        <div class="stat-card stat-accent-gold">
          <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 2.64-6.36"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg></span>
          <div class="stat-number"><?= $pastEventsCount ?></div>
          <div class="stat-label">Past events</div>
        </div>
      </a>
    </div>

    <div class="dash-grid">
      <div class="admin-section reveal" style="--d:260ms">
        <h2>Events in <?= (int) $chartYear ?>, by month</h2>
        <div class="month-chart">
          <?php foreach ($monthLabels as $i => $label): $m = $i + 1; $count = $monthCounts[$m]; ?>
            <div class="month-chart-col">
              <?php if ($count > 0): ?><div class="month-chart-count"><?= $count ?></div><?php endif; ?>
              <?php if ($count > 0): ?>
                <div class="month-chart-bar <?= $m === $currentMonth ? 'month-chart-bar-current' : '' ?>" style="height:<?= max(3, round($count / $maxMonthCount * 100)) ?>%;"></div>
              <?php else: ?>
                <div class="month-chart-bar month-chart-bar-empty<?= $m === $currentMonth ? ' month-chart-bar-current-empty' : '' ?>"></div>
              <?php endif; ?>
              <div class="month-chart-label"><?= $label ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="admin-section reveal" style="--d:320ms">
        <h2>Upcoming events</h2>
        <?php if (empty($upcomingEvents)): ?>
          <p class="muted-note">No upcoming events posted yet.</p>
        <?php else: ?>
          <ul class="event-list">
            <?php foreach ($upcomingEvents as $ev): ?>
              <li>
                <span class="event-date-badge"><?= e(date('M j', strtotime($ev['event_date']))) ?></span>
                <span class="event-text"><?= e($ev['title']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>

<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
