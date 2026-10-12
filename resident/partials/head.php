<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/css/style.css">
<script>
document.addEventListener('DOMContentLoaded', function () {
  /* Scroll choreography - IntersectionObserver adds .is-in to cards as
     they enter the viewport. The hidden state only applies under html.js. */
  (function () {
    document.documentElement.classList.add('js');
    var SEL = '.home-heading,.hero,.steps,.programs-preview,.auth-card,.auth-reveal,.dashboard-section,.event-card,.program-card,.member-fieldset,.benefit-card,.benefit-empty,.org-row,.profile-hero,.census-section,.about-card,.stat-card,.stat-card-compact,.steps-band';
    var els = document.querySelectorAll(SEL);
    function showAll() {
      for (var i = 0; i < els.length; i++) els[i].classList.add('is-in');
    }
    if (!('IntersectionObserver' in window) || !els.length) { showAll(); return; }
    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          entries[i].target.classList.add('is-in');
          io.unobserve(entries[i].target);
        }
      }
    }, { rootMargin: '0px 0px -7% 0px', threshold: 0.06 });
    for (var i = 0; i < els.length; i++) io.observe(els[i]);
    setTimeout(showAll, 4000);
  })();
  /* Ledger count-up - figures tick up once as they enter the viewport. */
  (function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) return;
    var els = document.querySelectorAll('.stat-number, .benefit-summary-count');
    if (!els.length) return;
    var started = false;
    function run() {
      if (started) return;
      started = true;
      for (var i = 0; i < els.length; i++) {
        (function (el) {
          var m = el.textContent.match(/[\d,]+/);
          if (!m) return;
          var target = parseInt(m[0].replace(/,/g, ''), 10);
          if (isNaN(target)) return;
          var t0 = performance.now(), dur = 900;
          function tick(now) {
            var p = Math.min(1, (now - t0) / dur);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased).toLocaleString();
            if (p < 1) requestAnimationFrame(tick);
          }
          requestAnimationFrame(tick);
        })(els[i]);
      }
    }
    if (!('IntersectionObserver' in window)) { run(); return; }
    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) { run(); io.disconnect(); return; }
      }
    }, { threshold: 0.3 });
    io.observe(els[0]);
  })();
});
</script>
