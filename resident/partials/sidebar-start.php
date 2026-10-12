<?php
/**
 * Shared resident layout shell - the left-hand sidebar version of the
 * old top-nav header.php, matching the staff/admin portal's layout so
 * residents and staff get the same navigation pattern. Every page that
 * uses this sets $activePage first so the matching sidebar link
 * highlights, the same convention admin/partials/layout-start.php uses.
 */
?>
<style>
/* Kept inline (in addition to assets/css/style.css) so the sidebar always
   renders and behaves correctly even if the shared stylesheet is out of
   date or fails to load on a given deployment. Values match the shared
   token set in assets/css/style.css. */
.resident-shell { display: flex; min-height: 100vh; min-height: 100dvh; }
.resident-sidebar {
  flex: 0 0 248px;
  background: #0B2E5C;
  display: flex;
  flex-direction: column;
  padding: 20px 14px;
  position: sticky;
  top: 0;
  height: 100vh;
}
.resident-sidebar .sidebar-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 8px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.12);
  margin-bottom: 16px;
  text-decoration: none;
}
.sidebar-brand-badge {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #E8A93D;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
  box-shadow: inset 0 0 0 2px rgba(255,255,255,0.35);
}
.sidebar-brand-badge img {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.resident-sidebar .sidebar-brand-name { color: #fff; font-size: 18px; font-weight: 600; font-family: 'Fraunces', Georgia, serif; }
.resident-sidebar .sidebar-brand-sub { color: #B7CBE8; font-size: 12px; letter-spacing: 0.14em; text-transform: uppercase; margin-top: 1px; }
.resident-sidebar .sidebar-nav { display: flex; flex-direction: column; gap: 2px; flex: 1; }
.resident-sidebar .sidebar-link {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: #CFE0F5;
  text-decoration: none;
  font-size: 15.5px;
  font-weight: 500;
  padding: 11px 14px;
  border-radius: 8px;
  transition: background-color 0.25s cubic-bezier(0.32, 0.72, 0, 1), color 0.25s cubic-bezier(0.32, 0.72, 0, 1), transform 0.35s cubic-bezier(0.32, 0.72, 0, 1);
}
.resident-sidebar .sidebar-link::before {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  width: 3px;
  height: 60%;
  border-radius: 0 3px 3px 0;
  background: #E8A93D;
  transform: translateY(-50%) scaleY(0);
  opacity: 0;
  transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1), opacity 0.3s cubic-bezier(0.32, 0.72, 0, 1);
}
.resident-sidebar .sidebar-link:hover { background: rgba(255,255,255,0.07); color: #fff; transform: translateX(2px); }
.resident-sidebar .sidebar-link.active { background: rgba(255,255,255,0.08); color: #fff; }
.resident-sidebar .sidebar-link.active::before { opacity: 1; transform: translateY(-50%) scaleY(1); }
.resident-sidebar .sidebar-footer { border-top: 1px solid rgba(255,255,255,0.12); padding-top: 16px; padding-bottom: 14px; margin-top: 16px; }
.resident-sidebar .sidebar-name { color: #fff; font-size: 15.5px; margin-bottom: 10px; }
.resident-sidebar .sidebar-logout {
  color: #E8A93D;
  font-size: 15px;
  font-weight: 700;
  text-decoration: none;
  border: 1.5px solid rgba(232, 169, 61, 0.55);
  border-radius: 10px;
  padding: 10px 16px;
  display: block;
  text-align: center;
  transition: background-color 0.25s cubic-bezier(0.32, 0.72, 0, 1), color 0.25s cubic-bezier(0.32, 0.72, 0, 1), border-color 0.25s cubic-bezier(0.32, 0.72, 0, 1);
}
.resident-sidebar .sidebar-logout:hover { background: #E8A93D; border-color: #E8A93D; color: #4A2F04; }
.resident-sidebar .sidebar-logout-accent {
  display: inline-block;
  background: #E8A93D;
  color: #4A2F04 !important;
  font-weight: 700;
  font-size: 16px;
  padding: 12px 22px;
  border-radius: 10px;
}
.resident-shell-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.resident-main { flex: 1; }
.home-heading { max-width: 1400px; margin: 0 auto; padding: 40px 24px 8px; }
.home-heading h1 { font-size: clamp(34px, 5vw, 50px); line-height: 1.04; letter-spacing: -0.015em; }
.home-heading p { color: #3C5470; font-size: 17.5px; max-width: 560px; line-height: 1.6; }
.sidebar-mobilebar { display: none; }
.sidebar-backdrop { display: none; }
@media (max-width: 1024px) {
  .resident-sidebar { flex: 0 0 200px; }
  .resident-sidebar .sidebar-link { font-size: 14.5px; padding: 10px 12px; }
  .resident-sidebar .sidebar-brand-name { font-size: 16px; }
}
@media (max-width: 760px) {
  .sidebar-mobilebar {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    background: #0B2E5C;
    position: sticky; top: 0;
    z-index: 92;
  }
  .sidebar-mobile-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
  .sidebar-mobile-brand .sidebar-brand-badge { width: 34px; height: 34px; }
  .sidebar-mobile-brand .sidebar-brand-name { font-size: 16px; }
  .sidebar-toggle {
    margin-left: auto;
    display: inline-flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 5px;
    width: 42px; height: 42px;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.18);
    cursor: pointer;
  }
  .sidebar-toggle span {
    display: block; width: 18px; height: 2px; border-radius: 2px;
    background: #E8A93D;
    transition: transform 0.45s cubic-bezier(0.32, 0.72, 0, 1), opacity 0.35s cubic-bezier(0.32, 0.72, 0, 1);
  }
  .sidebar-toggle-input:checked ~ .sidebar-mobilebar .sidebar-toggle span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
  .sidebar-toggle-input:checked ~ .sidebar-mobilebar .sidebar-toggle span:nth-child(2) { opacity: 0; }
  .sidebar-toggle-input:checked ~ .sidebar-mobilebar .sidebar-toggle span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
  .sidebar-backdrop {
    display: block;
    position: fixed; inset: 0;
    z-index: 89;
    background: rgba(6,14,28,0.55);
    -webkit-backdrop-filter: blur(10px);
    backdrop-filter: blur(10px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.45s cubic-bezier(0.32, 0.72, 0, 1);
    cursor: default;
  }
  .sidebar-toggle-input:checked ~ .sidebar-backdrop { opacity: 1; pointer-events: auto; }
  .resident-shell { flex-direction: column; }
  .resident-sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: min(320px, 86vw);
    height: 100dvh;
    z-index: 90;
    transform: translateX(-104%);
    transition: transform 0.55s cubic-bezier(0.32, 0.72, 0, 1);
    box-shadow: 24px 0 60px -28px rgba(6,14,28,0.6);
    padding-top: 84px;
  }
  .sidebar-toggle-input:checked ~ .resident-shell .resident-sidebar { transform: translateX(0); }
  .resident-sidebar .sidebar-brand { display: none; }
  .resident-sidebar .sidebar-link::before { display: none; }
  .resident-sidebar .sidebar-link.active { background: rgba(255,255,255,0.1); }
  .resident-sidebar .sidebar-link {
    opacity: 0;
    transform: translateY(16px);
    transition: opacity 0.5s cubic-bezier(0.32, 0.72, 0, 1), transform 0.5s cubic-bezier(0.32, 0.72, 0, 1), background-color 0.25s cubic-bezier(0.32, 0.72, 0, 1), color 0.25s cubic-bezier(0.32, 0.72, 0, 1);
  }
  .sidebar-toggle-input:checked ~ .resident-shell .resident-sidebar .sidebar-link { opacity: 1; transform: translateY(0); }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(1) { transition-delay: 0.06s; }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(2) { transition-delay: 0.12s; }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(3) { transition-delay: 0.18s; }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(4) { transition-delay: 0.24s; }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(5) { transition-delay: 0.3s; }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(6) { transition-delay: 0.36s; }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(7) { transition-delay: 0.42s; }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-nav .sidebar-link:nth-child(8) { transition-delay: 0.48s; }
  .sidebar-footer {
    opacity: 0;
    transition: opacity 0.4s cubic-bezier(0.32, 0.72, 0, 1);
  }
  .sidebar-toggle-input:checked ~ .resident-shell .sidebar-footer { opacity: 1; transition-delay: 0.5s; }
}
.reveal { animation: rise 0.9s cubic-bezier(0.32, 0.72, 0, 1) both; animation-delay: var(--d, 0s); }
@keyframes rise {
  from { opacity: 0; transform: translateY(22px); filter: blur(4px); }
  to { opacity: 1; transform: translateY(0); filter: none; }
}
@media (prefers-reduced-motion: reduce) {
  .reveal { animation: none; }
  .sidebar-toggle span, .sidebar-backdrop, .resident-sidebar { transition: none; }
  .resident-sidebar .sidebar-link { opacity: 1; transform: none; }
}
</style>
<input type="checkbox" id="sidebar-toggle" class="sidebar-toggle-input" hidden>
<div class="sidebar-mobilebar">
  <a href="index.php" class="sidebar-mobile-brand">
    <span class="sidebar-brand-badge"><img src="../images/logo.png" alt=""></span>
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
<div class="resident-shell">
  <aside class="resident-sidebar">
    <a href="index.php" class="sidebar-brand">
      <span class="sidebar-brand-badge"><img src="../images/logo.png" alt="Barangay 420 logo"></span>
      <div>
        <div class="sidebar-brand-name">Barangay 420</div>
        <div class="sidebar-brand-sub">Resident Portal</div>
      </div>
    </a>

    <nav class="sidebar-nav">
      <?php if (!empty($pendingCensus)): ?>
        <a href="census.php" class="sidebar-link active">
          <span>Household census</span>
        </a>
      <?php else: ?>
      <a href="index.php" class="sidebar-link <?= ($activePage ?? '') === 'about' ? 'active' : '' ?>">
        <span>About</span>
      </a>
      <?php if (resident_is_logged_in()): ?>
        <a href="events.php" class="sidebar-link <?= ($activePage ?? '') === 'events' ? 'active' : '' ?>">
          <span>Events</span>
        </a>
        <a href="dashboard.php" class="sidebar-link <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
          <span>My Benefits</span>
        </a>
        <a href="profile.php" class="sidebar-link <?= ($activePage ?? '') === 'profile' ? 'active' : '' ?>">
          <span>My Profile</span>
        </a>
      <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <?php if (resident_is_logged_in()): ?>
        <div class="sidebar-name"><?= e($_SESSION['full_name'] ?? '') ?></div>
        <a href="logout.php" class="sidebar-logout">Log out</a>
      <?php else: ?>
        <a href="login.php" class="sidebar-logout sidebar-logout-accent">Log in</a>
      <?php endif; ?>
    </div>
  </aside>

  <div class="resident-shell-main">
    <main class="resident-main">
