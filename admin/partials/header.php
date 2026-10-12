<style>
/* Kept inline (in addition to assets/css/style.css) so the top header
   always renders at full size even if the external stylesheet on this
   server is out of date or wasn't uploaded with the rest of the page -
   this has happened more than once on this deployment. Values match
   the resident portal's header.php exactly (same badge, same brand,
   same pill, same nav sizes) so both portals' entry points look
   consistent; only the nav links differ (the admin portal has no
   About page, so it shows just Log in and Register). */
.site-header {
  margin: clamp(6px, 1vw, 14px) auto 0;
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
@media (max-width: 640px) {
  .site-header { border-radius: 26px; width: calc(100% - 24px); margin-top: clamp(6px, 1vw, 14px); padding: 14px 16px; }
  .site-nav a { font-size: 15px; padding: 9px 14px; }
}
</style>
<!-- Pre-login top bar for the admin portal, sharing the same pill
     design as the resident portal (same badge, same brand name, same
     nav sizing) so both portals' entry points look consistent. Nav
     shows the admin portal's own public pages: Log in and Register. -->
<header class="site-header">
  <div class="site-header-inner">
    <a href="login.php" class="brand">
      <span class="brand-badge"><img src="../images/logo.png" alt="Barangay 420 logo"></span>
      <span class="brand-name">Barangay 420</span>
    </a>
    <nav class="site-nav">
      <a href="login.php" class="<?= ($activePage ?? '') === 'login' ? 'site-nav-active' : '' ?>">Log in</a>
      <a href="create-account.php" class="<?= ($activePage ?? '') === 'create-account' ? 'site-nav-active' : '' ?>">Create account</a>
    </nav>
  </div>
</header>
