<?php
/**
 * Shared admin layout shell. Every logged-in admin page sets $activePage
 * before including this, so the matching sidebar link highlights.
 * Expects $pdo to already be in scope (every page creates it before
 * including this file).
 */
require_once __DIR__ . '/../../shared/eligibility.php';
$pendingBadgeCount = 0;
if (admin_role() === 'admin') {
    $pendingBadgeCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'pending'")->fetchColumn();
}
maybe_archive_programs($pdo, $_SESSION['staff_user_id'] ?? null);
maybe_run_eligibility_sweep($pdo, $_SESSION['staff_user_id'] ?? null);
$activeProgramsCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM programs
     WHERE status = 'active'
       AND (start_date IS NULL OR start_date <= NOW())
       AND (end_date IS NULL OR end_date >= NOW())"
)->fetchColumn();
// Applications waiting for administrative review - the For Approval badge.
$forApprovalBadgeCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM beneficiaries WHERE claim_status = 'pending'"
)->fetchColumn();
$adminPosition = admin_role() ? ucfirst((string) admin_role()) : 'Admin';
?>
<input type="checkbox" id="sidebar-toggle" class="sidebar-toggle-input" hidden>
<div class="sidebar-mobilebar">
  <a href="dashboard.php" class="sidebar-mobile-brand">
    <span class="admin-brand-badge"><img src="../images/logo.png" alt=""></span>
    <span class="sidebar-brand-name">Barangay 420</span>
  </a>
  <label for="sidebar-toggle" class="sidebar-toggle" aria-label="Open menu">
    <span></span><span></span><span></span>
  </label>
</div>
<label for="sidebar-toggle" class="sidebar-backdrop" aria-hidden="true"></label>
<script>
(function () {
  var input = document.getElementById('sidebar-toggle');
  if (!input) return;
  function sync() { document.body.style.overflow = input.checked ? 'hidden' : ''; }
  input.addEventListener('change', sync);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && input.checked) { input.checked = false; sync(); } });
  var mq = window.matchMedia('(min-width: 761px)');
  function closeOnDesktop() { if (mq.matches) { input.checked = false; sync(); } }
  if (mq.addEventListener) mq.addEventListener('change', closeOnDesktop);
  else if (mq.addListener) mq.addListener(closeOnDesktop);
})();
</script>
<div class="admin-shell">
  <aside class="admin-sidebar">
    <div class="sidebar-brand">
      <span class="admin-brand-badge"><img src="../images/logo.png" alt="Barangay 420 logo"></span>
      <div>
        <div class="sidebar-brand-name">Barangay 420</div>
        <div class="sidebar-brand-sub"><?= e($adminPosition) ?> Portal</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <a href="dashboard.php" class="sidebar-link <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
        <span>Dashboard</span>
      </a>
      <a href="home.php" class="sidebar-link <?= ($activePage ?? '') === 'home' ? 'active' : '' ?>">
        <span>Home</span>
      </a>
      <a href="events.php" class="sidebar-link <?= ($activePage ?? '') === 'events' ? 'active' : '' ?>">
        <span>Events</span>
      </a>
      <?php if (admin_role() === 'admin'): ?>
        <a href="accounts.php" class="sidebar-link <?= ($activePage ?? '') === 'accounts' ? 'active' : '' ?>">
          <span>Accounts</span>
          <?php if ($pendingBadgeCount > 0): ?><span class="sidebar-badge"><?= $pendingBadgeCount ?></span><?php endif; ?>
        </a>
        <a href="programs.php" class="sidebar-link <?= ($activePage ?? '') === 'programs' ? 'active' : '' ?>">
          <span>Programs</span>
          <span class="sidebar-badge sidebar-badge-muted"><?= $activeProgramsCount ?></span>
        </a>
        <a href="beneficiaries.php" class="sidebar-link <?= ($activePage ?? '') === 'beneficiaries' ? 'active' : '' ?>">
          <span>Beneficiaries</span>
        </a>
        <a href="approved-beneficiaries.php" class="sidebar-link <?= ($activePage ?? '') === 'approved-beneficiaries' ? 'active' : '' ?>">
          <span>For Approval</span>
          <?php if ($forApprovalBadgeCount > 0): ?><span class="sidebar-badge sidebar-badge-muted"><?= $forApprovalBadgeCount ?></span><?php endif; ?>
        </a>
        <a href="residents.php" class="sidebar-link <?= ($activePage ?? '') === 'residents' ? 'active' : '' ?>">
          <span>Residents</span>
        </a>
        <a href="logs.php" class="sidebar-link <?= ($activePage ?? '') === 'logs' ? 'active' : '' ?>">
          <span>Logs</span>
        </a>
        <a href="system.php" class="sidebar-link <?= ($activePage ?? '') === 'system' ? 'active' : '' ?>">
          <span>System</span>
        </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-name"><?= e($_SESSION['staff_full_name'] ?? '') ?></div>
      <a href="logout.php" class="sidebar-logout">Log out</a>
    </div>
  </aside>

  <div class="admin-shell-main">
    <main class="admin-main">
