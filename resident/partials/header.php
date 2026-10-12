<style>
/* Kept inline (in addition to assets/css/style.css) so the top header
   always renders at full size even if the external stylesheet on this
   server is out of date or wasn't uploaded with the rest of the page -
   this has happened more than once on this deployment. Values match
   the shared token set in assets/css/style.css. */
.site-header {
  margin: clamp(10px, 1.5vw, 22px) auto 0;
  width: min(1120px, calc(100% - 40px));
  background: rgba(11, 46, 92, 0.92);
  -webkit-backdrop-filter: blur(20px) saturate(1.4);
  backdrop-filter: blur(20px) saturate(1.4);
  border: 1px solid rgba(255, 255, 255, 0.14);
  border-radius: 999px;
  box-shadow: 0 18px 44px -22px rgba(6, 14, 28, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.12);
  padding: 16px 20px;
}
.site-header-inner {
  max-width: 1080px; margin: 0 auto; display: flex; align-items: center;
  justify-content: space-between; flex-wrap: wrap; gap: 14px 18px;
}
.brand { display: flex; align-items: center; gap: 14px; text-decoration: none; }
.brand-badge {
  width: 44px; height: 44px; border-radius: 50%;
  background: #E8A93D;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 17px;
  overflow: hidden;
  box-shadow: inset 0 0 0 2px rgba(255,255,255,0.35);
}
.brand-badge img {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.brand-name { color: #fff; font-weight: 600; font-size: 22px; font-family: 'Fraunces', Georgia, serif; }
.site-nav { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.site-nav a {
  color: #CFE0F5; text-decoration: none; font-size: 15.5px; font-weight: 500;
  padding: 10px 18px; border-radius: 999px;
  transition: background-color 0.35s cubic-bezier(0.32, 0.72, 0, 1), color 0.35s cubic-bezier(0.32, 0.72, 0, 1), transform 0.35s cubic-bezier(0.32, 0.72, 0, 1);
}
.site-nav a:hover { color: #fff; background: rgba(255, 255, 255, 0.08); }
.site-nav a.site-nav-active {
  background: #E8A93D !important;
  color: #4A2F04 !important;
  font-weight: 700;
  font-size: 15.5px;
  padding: 10px 18px;
  border-radius: 999px;
}
.btn-pill.btn-logout {
  background: transparent !important; color: #E8A93D !important;
  border: 1.5px solid rgba(232, 169, 61, 0.6);
  padding: 9px 20px;
}
.btn-pill.btn-logout:hover { background: #E8A93D !important; color: #4A2F04 !important; }
@media (max-width: 640px) {
  .site-header { border-radius: 26px; width: calc(100% - 24px); margin-top: clamp(10px, 1.5vw, 22px); padding: 14px 16px; }
  .site-nav a { font-size: 15px; padding: 9px 14px; }
}
@media (max-width: 480px) {
  .site-nav { gap: 4px; }
  .site-nav a { font-size: 14px; padding: 8px 12px; }
  .brand-name { font-size: 18px; }
}
</style>
<header class="site-header">
  <div class="site-header-inner">
    <a href="index.php" class="brand">
      <span class="brand-badge"><img src="../images/logo.png" alt="Barangay 420 logo"></span>
      <span class="brand-name">Barangay 420</span>
    </a>
    <nav class="site-nav">
      <?php if (resident_is_logged_in()): ?>
        <a href="index.php#programs">Programs</a>
        <a href="events.php">Events</a>
        <a href="index.php#about">About</a>
        <a href="dashboard.php">My Benefits</a>
        <a href="profile.php">My Profile</a>
        <a href="logout.php" class="btn-pill btn-logout">Log out</a>
      <?php else: ?>
        <a href="index.php" class="<?= ($activePage ?? '') === 'about' ? 'site-nav-active' : '' ?>">About</a>
        <a href="login.php" class="<?= ($activePage ?? '') === 'login' ? 'site-nav-active' : '' ?>">Log in</a>
        <a href="create-account.php" class="<?= ($activePage ?? '') === 'create-account' ? 'site-nav-active' : '' ?>">Create account</a>
        <a href="register.php" class="<?= ($activePage ?? '') === 'register' ? 'site-nav-active' : '' ?>">Register</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
