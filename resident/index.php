<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';

$activePage = 'about';

if (resident_is_logged_in()) {
    require_census_completed();
}

$pdo = get_db_connection();
$info = $pdo->query('SELECT * FROM barangay_info WHERE info_id = 1')->fetch() ?: [];
$officials = $pdo->query('SELECT * FROM barangay_officials ORDER BY display_order ASC, full_name ASC')->fetchAll();

$loggedIn = resident_is_logged_in();

/** Renders the actual About content - identical either way, just wrapped differently below. */
function render_about_content(array $info, array $officials): void {
    ?>
    <section class="home-heading" style="--d:0.05s">
      <h1>Barangay <em>420</em></h1>
      <?php if (!empty($info['tagline'])): ?>
        <p><?= e($info['tagline']) ?></p>
      <?php endif; ?>
      <div class="home-cta">
        <?php if (resident_is_logged_in()): ?>
          <a href="dashboard.php" class="btn-accent">View my benefits
            <span class="btn-accent-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13"/><path d="m13 6 6 6-6 6"/></svg></span>
          </a>
          <a href="events.php" class="btn-ghost">Upcoming events</a>
        <?php else: ?>
          <a href="register.php" class="btn-accent">Create your account
            <span class="btn-accent-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13"/><path d="m13 6 6 6-6 6"/></svg></span>
          </a>
          <a href="login.php" class="btn-ghost">Already registered? Log in</a>
        <?php endif; ?>
      </div>
    </section>

    <section id="about" class="programs-preview about-section" style="--d:0.15s">
      <div class="about-head">
        <h2>About <em>Barangay 420</em></h2>
        <?php if (!empty($info['about_text'])): ?>
          <p class="about-lead"><?= e($info['about_text']) ?></p>
        <?php endif; ?>
      </div>
      <div class="about-info-grid">
        <?php if (!empty($info['address'])): ?>
          <div class="about-info-card">
            <span class="about-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6.5-5.1-6.5-10.4A6.5 6.5 0 0 1 12 4a6.5 6.5 0 0 1 6.5 6.6C18.5 15.9 12 21 12 21Z"/><circle cx="12" cy="10.5" r="2.2"/></svg></span>
            <div><strong>Address</strong><span class="about-info-value"><?= e($info['address']) ?></span></div>
          </div>
        <?php endif; ?>
        <?php if (!empty($info['office_hours'])): ?>
          <div class="about-info-card">
            <span class="about-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
            <div><strong>Office hours</strong><span class="about-info-value"><?= e($info['office_hours']) ?></span></div>
          </div>
        <?php endif; ?>
        <?php if (!empty($info['contact_number'])): ?>
          <div class="about-info-card">
            <span class="about-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
            <div><strong>Contact number</strong><span class="about-info-value"><?= e($info['contact_number']) ?></span></div>
          </div>
        <?php endif; ?>
        <?php if (!empty($info['contact_email'])): ?>
          <div class="about-info-card">
            <span class="about-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2.5"/><path d="m22 7-10 6L2 7"/></svg></span>
            <div><strong>Contact email</strong><span class="about-info-value"><?= e($info['contact_email']) ?></span></div>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="steps-band" style="--d:0.2s">
      <div class="steps-band-head">
        <h2>How it works</h2>
        <p>Three steps between you and your household's benefits.</p>
      </div>
      <div class="steps-grid">
        <article class="step-card">
          <span class="step-num">01</span>
          <h3>Create your account</h3>
          <p>Register in a couple of minutes with your details and a copy of your barangay ID.</p>
        </article>
        <article class="step-card">
          <span class="step-num">02</span>
          <h3>Complete the census</h3>
          <p>Tell us about your household &mdash; every member, one record. Your first login takes you there.</p>
        </article>
        <article class="step-card">
          <span class="step-num">03</span>
          <h3>Apply for benefits</h3>
          <p>Your household is matched against every active program automatically, so you can apply in one click.</p>
        </article>
      </div>
    </section>

    <?php if (!empty($officials)): ?>
    <section class="programs-preview officials-section" style="--d:0.25s">
      <div class="officials-head">
        <h2>Barangay Officials</h2>
        <p>Your duly elected and appointed public servants.</p>
      </div>
      <?php
      $captain = null; $row2 = []; $kagawads = []; $sk = null;
      foreach ($officials as $o) {
          $pos = strtolower(trim($o['position']));
          if (str_contains($pos, 'punong') || str_contains($pos, 'captain')) {
              $captain = $o;
          } elseif ($pos === 'secretary' || $pos === 'treasurer') {
              $row2[] = $o;
          } elseif (str_contains($pos, 'sk')) {
              $sk = $o;
          } else {
              $kagawads[] = $o;
          }
      }
      $row3 = array_slice($kagawads, 0, 4);
      $row4 = array_slice($kagawads, 4);
      if ($sk) { $row4[] = $sk; }
      $rows = array_filter([$captain ? [$captain] : [], $row2, $row3, $row4]);
      ?>
      <div class="org-tree">
        <?php $first = true; foreach ($rows as $row): ?>
          <?php if (!$first): ?><div class="org-stem"></div><?php endif; ?>
          <div class="org-row<?= $first ? '' : ' org-row-children' ?>">
            <?php foreach ($row as $o): ?>
              <?php $isCaptain = $captain && $o['official_id'] === $captain['official_id']; ?>
              <div class="org-node">
                <?php if ($isCaptain): ?>
                  <div class="org-captain-frame">
                    <?php if ($o['photo_path']): ?>
                      <img src="assets/officials/<?= e($o['photo_path']) ?>" alt="<?= e($o['full_name']) ?>">
                    <?php else: ?>
                      <div class="org-captain-initials"><?= e(official_initials($o['full_name'])) ?></div>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <?php if ($o['photo_path']): ?>
                    <img src="assets/officials/<?= e($o['photo_path']) ?>" alt="<?= e($o['full_name']) ?>" class="org-photo">
                  <?php else: ?>
                    <div class="org-photo org-initials"><?= e(official_initials($o['full_name'])) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
                <div class="org-name<?= $isCaptain ? ' org-name-captain' : '' ?>"><?= e($o['full_name']) ?></div>
                <div class="org-position"><?= $isCaptain ? 'Punong Barangay ( Chairman )' : e($o['position']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php $first = false; endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Barangay 420</title>
<?php include __DIR__ . '/partials/head.php'; ?>
<style>
/* About page craft - kept inline so the page always renders correctly
   even if the external stylesheet is out of date. Values match the
   shared token set in assets/css/style.css. */
.org-tree { margin-top: 44px; text-align: center; }
.officials-head { text-align: center; margin-bottom: 8px; }
.officials-head h2 { margin-bottom: 8px; }
.officials-head p { color: #3C5470; font-size: 15.5px; margin: 0; }
.org-row { display: flex; justify-content: center; align-items: flex-start; gap: clamp(12px, 2vw, 22px); flex-wrap: wrap; position: relative; }
.org-row-children { padding-top: 28px; }
.org-row-children::before { content: ''; position: absolute; top: 0; left: 6%; right: 6%; height: 2px; background: #C9A25A; }
.org-stem { width: 2px; height: 28px; background: #C9A25A; margin: 0 auto; }
.org-node {
  position: relative; text-align: center; min-width: 0;
  background: #fff;
  border: 1px solid #E3DED0;
  border-radius: 18px;
  padding: 24px 20px 20px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 14px 30px -20px rgba(11,46,92,0.25);
  transition: transform 0.5s cubic-bezier(0.32,0.72,0,1), box-shadow 0.5s cubic-bezier(0.32,0.72,0,1);
}
.org-node:hover { transform: translateY(-4px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 22px 40px -22px rgba(11,46,92,0.32); }
.org-node::before { content: ''; position: absolute; top: -28px; left: 50%; width: 2px; height: 28px; background: #C9A25A; }
.org-captain-frame {
  width: clamp(100px, 12vw, 128px);
  aspect-ratio: 128 / 148;
  border-radius: 14px;
  border: 2.5px solid #E8A93D; background: #fff;
  box-shadow: 0 0 0 4px #FDF0DA, 0 10px 24px -14px rgba(11,46,92,0.4);
  margin: 0 auto 14px; padding: 6px;
  transform: rotate(-1.5deg);
  transition: transform 0.5s cubic-bezier(0.32,0.72,0,1);
  overflow: hidden;
}
.org-node:hover .org-captain-frame { transform: rotate(0deg); }
.org-captain-frame img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; display: block; }
.org-captain-initials { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: #0B2E5C; color: #E8A93D; font-family: 'Fraunces', serif; font-size: clamp(28px, 4vw, 36px); font-weight: 600; border-radius: 8px; }
.org-photo { width: clamp(60px, 8vw, 80px); aspect-ratio: 1; border-radius: 50%; border: 2.5px solid #E8A93D; object-fit: cover; margin: 0 auto 12px; display: block; }
.org-initials { display: flex; align-items: center; justify-content: center; background: #0B2E5C; color: #E8A93D; font-family: 'Fraunces', serif; font-weight: 600; font-size: clamp(18px, 3vw, 24px); }
.org-name { font-size: 15px; font-weight: 600; color: #16233A; }
.org-name-captain { font-size: 18px; font-weight: 700; }
.org-position { font-size: 13px; color: #3C5470; margin-top: 2px; }

/* Hero: serif italic gold accent + CTA row */
.home-heading h1 em, .about-head h2 em { font-style: italic; color: #C98E24; font-weight: 500; }
.home-cta { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 24px; }
.home-cta .btn-accent { margin-top: 0; }
.btn-ghost {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 12px 26px; border-radius: 999px;
  background: #fff; color: #0B2E5C; font-weight: 600; font-size: 15.5px;
  text-decoration: none; border: 1px solid #E3DED0;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 8px 18px -12px rgba(11,46,92,0.25);
  transition: transform 0.45s cubic-bezier(0.32,0.72,0,1), box-shadow 0.45s cubic-bezier(0.32,0.72,0,1), border-color 0.45s cubic-bezier(0.32,0.72,0,1);
}
.btn-ghost::after {
  content: '';
  flex: none;
  width: 14px; height: 14px;
  background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230B2E5C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12h13'/%3E%3Cpath d='m13 6 6 6-6 6'/%3E%3C/svg%3E") center / contain no-repeat;
  opacity: 0.55;
  transition: transform 0.5s cubic-bezier(0.32,0.72,0,1), opacity 0.4s cubic-bezier(0.32,0.72,0,1);
}
.btn-ghost:hover { transform: translateY(-2px); border-color: #C9C3B0; box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 14px 26px -14px rgba(11,46,92,0.3); }
.btn-ghost:hover::after { transform: translateX(3px); opacity: 1; }
.btn-ghost:active { transform: scale(0.98); }

/* About: editorial split + info cards */
.about-head { display: grid; grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.5fr); gap: 44px; align-items: start; margin-top: 6px; }
.about-head h2 { font-size: clamp(28px, 3.4vw, 40px); line-height: 1.06; letter-spacing: -0.015em; margin: 0; }
.about-lead { font-size: 18px; line-height: 1.75; color: #3C5470; margin: 0; }
.about-info-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 30px; }
.about-info-card {
  display: flex; gap: 13px; align-items: flex-start;
  background: #fff; border: 1px solid #E3DED0; border-radius: 16px; padding: 16px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.85), 0 10px 22px -16px rgba(11,46,92,0.2);
  transition: transform 0.45s cubic-bezier(0.32,0.72,0,1), box-shadow 0.45s cubic-bezier(0.32,0.72,0,1);
}
.about-info-card:hover { transform: translateY(-3px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.85), 0 18px 32px -20px rgba(11,46,92,0.28); }
.about-info-icon {
  flex: none; width: 38px; height: 38px; border-radius: 12px;
  background: #FDF0DA; color: #4A2F04;
  display: flex; align-items: center; justify-content: center;
  box-shadow: inset 0 0 0 1px rgba(232,169,61,0.28);
  transition: transform 0.5s cubic-bezier(0.32,0.72,0,1);
}
.about-info-card:hover .about-info-icon { transform: translateY(-2px) rotate(-4deg); }
.about-info-icon svg { width: 17px; height: 17px; }
.about-info-card strong { display: block; font-size: 11.5px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: #6B7C93; }
.about-info-value { display: block; margin-top: 4px; font-size: 15px; font-weight: 500; color: #16233A; line-height: 1.45; }

/* How it works */
.steps-band { max-width: 1080px; margin: 0 auto; padding: 8px 24px 72px; }
.steps-band-head { text-align: center; margin-bottom: 30px; }
.steps-band-head h2 { font-size: clamp(26px, 3.2vw, 36px); margin-bottom: 8px; }
.steps-band-head p { color: #3C5470; font-size: 16px; margin: 0; }
.steps-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.step-card {
  background: #fff; border: 1px solid #E3DED0; border-radius: 20px; padding: 30px 26px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.85), 0 14px 30px -20px rgba(11,46,92,0.22);
  transition: transform 0.5s cubic-bezier(0.32,0.72,0,1), box-shadow 0.5s cubic-bezier(0.32,0.72,0,1);
}
.step-card:hover { transform: translateY(-4px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.85), 0 22px 40px -22px rgba(11,46,92,0.3); }
.step-num {
  display: inline-flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 50%;
  background: #0B2E5C; color: #E8A93D;
  font-family: 'IBM Plex Mono', ui-monospace, Menlo, monospace; font-size: 13.5px; font-weight: 600;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.14), 0 8px 16px -8px rgba(11,46,92,0.5);
  transition: transform 0.5s cubic-bezier(0.32,0.72,0,1);
}
.step-card:hover .step-num { transform: rotate(-6deg) scale(1.07); }
.step-card h3 { font-size: 17px; color: #0B2E5C; margin: 16px 0 8px; }
.step-card p { font-size: 14.5px; color: #3C5470; line-height: 1.6; margin: 0; }

@media (max-width: 900px) { .about-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 760px) {
  .about-head { grid-template-columns: 1fr; gap: 18px; }
  .steps-grid { grid-template-columns: 1fr; }
  .org-node { flex: 0 0 auto; }
}
@media (max-width: 560px) { .about-info-grid { grid-template-columns: 1fr; } }
@media (max-width: 480px) {
  .about-info-grid { grid-template-columns: 1fr; }
  .steps-grid { grid-template-columns: 1fr; }
  .org-tree { overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 8px; }
  .org-row { flex-wrap: nowrap; min-width: max-content; gap: 16px; }
}
</style>
<?php if (!$loggedIn): ?>
<?php include __DIR__ . '/partials/auth-inline-style.php'; ?>
<style>
/* The About page's card is wider than the login/register card - same
   look (white, gold top rule, rounded), just sized for more content. */
.about-card { max-width: 1080px; }
/* home-heading/programs-preview are normally full-page sections with
   their own outer spacing - inside the card here, the card's own
   padding already handles that, so strip the sections' own margins to
   avoid doubled-up whitespace. */
.about-card .home-heading, .about-card .programs-preview,
.about-card .steps-band { max-width: none; margin: 0; padding: 0 0 20px; }
.about-card .home-heading:first-child { padding-top: 0; }
/* The barangay name leads the card like a document heading: large
   Fraunces, tight leading, a gold rule beneath - the same "hall band"
   line that runs under every navy header across the site. */
.about-card .home-heading h1 {
  font-size: clamp(36px, 6vw, 52px);
  line-height: 1.05;
  letter-spacing: -0.015em;
  padding-bottom: 18px;
  border-bottom: 3px solid #E8A93D;
  margin-bottom: 18px;
}
.about-card .home-heading p { font-size: 17px; }
</style>
<?php endif; ?>
</head>
<body>
<?php if ($loggedIn): ?>
  <!-- Logged-in residents get the sidebar, same as every other page
       inside the portal - the sidebar only ever appears once there's an
       account to navigate around with. -->
  <?php include __DIR__ . '/partials/sidebar-start.php'; ?>
    <?php render_about_content($info, $officials); ?>
  <?php include __DIR__ . '/partials/sidebar-end.php'; ?>
<?php else: ?>
  <!-- Logged-out visitors get the same top bar + centered card as the
       login/register pages, not the sidebar - there's nowhere to
       navigate to yet, so a sidebar would be mostly empty and out of
       place next to a public page. -->
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="auth-page">
    <div class="auth-card about-card">
      <?php render_about_content($info, $officials); ?>
    </div>
  </main>
  <?php include __DIR__ . '/partials/footer.php'; ?>
<?php endif; ?>
</body>
</html>
