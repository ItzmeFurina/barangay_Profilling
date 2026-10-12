<style>
/* ============================================================
   Admin portal — Editorial Luxury auth system
   Kept inline (in addition to assets/css/style.css) so the
   sign-in and registration pages always render correctly even
   if the external stylesheet on this server is out of date.
   These entry points don't depend on that file at all.
   ============================================================ */
:root {
  --r-bg: #F7F2E8;
  --r-ink: #221C13;
  --r-muted: #6B6253;
  --r-dim: #9A8F7C;
  --r-navy: #0B2E5C;
  --r-blue: #1B5FA8;
  --r-gold: #C0841C;
  --r-gold-soft: #E8A93D;
  --r-line: rgba(34, 28, 19, 0.12);
  --r-line-strong: rgba(34, 28, 19, 0.18);
  --r-card: #FFFEFA;
  --r-card-2: #FDFAF3;
  --r-err-bg: #FCEBEB;
  --r-err-line: rgba(154, 44, 44, 0.30);
  --r-err-ink: #9A2C2C;
  --r-ok-bg: #EAF3DE;
  --r-ok-line: rgba(43, 95, 31, 0.30);
  --r-ok-ink: #2B5F1F;
  --ease: cubic-bezier(0.32, 0.72, 0, 1);
  --mono: 'IBM Plex Mono', monospace;
  --sans: 'IBM Plex Sans', sans-serif;
  --serif: 'Fraunces', Georgia, serif;
}
body {
  margin: 0;
  background: var(--r-bg);
  color: var(--r-ink);
  font-family: var(--sans);
  min-height: 100vh;
  min-height: 100dvh;
  overflow-x: hidden;
}
::selection { background: rgba(232, 169, 61, 0.4); color: #221C13; }
a { color: var(--r-gold); text-decoration: none; transition: color 0.4s var(--ease); }
a:hover { color: var(--r-navy); }

/* ---- fixed atmospheric backdrop: warm light + paper grain ---- */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  background:
    radial-gradient(46% 40% at 88% 8%, rgba(232, 169, 61, 0.12) 0%, transparent 62%),
    radial-gradient(50% 46% at 6% 90%, rgba(27, 95, 168, 0.06) 0%, transparent 62%),
    radial-gradient(36% 34% at 70% 88%, rgba(138, 155, 134, 0.09) 0%, transparent 60%);
}
body::after {
  content: '';
  position: fixed;
  inset: 0;
  z-index: 3;
  pointer-events: none;
  opacity: 0.035;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 160 160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='160' height='160' filter='url(%23n)'/%3E%3C/svg%3E");
}

/* ---- split stage ---- */
.auth-stage {
  position: relative;
  z-index: 1;
  max-width: 1180px;
  margin: 0 auto;
  padding: clamp(40px, 5.5vw, 76px) 24px 96px;
  display: grid;
  grid-template-columns: 1.02fr 0.98fr;
  gap: clamp(44px, 6vw, 96px);
  align-items: start;
}
.auth-stage--register { grid-template-columns: 0.82fr 1.18fr; align-items: start; }

/* ---- left panel ---- */
.eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  font-family: var(--mono);
  font-size: 10.5px;
  font-weight: 500;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: var(--r-muted);
  margin: 0 0 26px;
}
.eyebrow-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--r-gold-soft);
  box-shadow: 0 0 0 3px rgba(232, 169, 61, 0.22);
  animation: breathe 3.2s var(--ease) infinite;
}
@keyframes breathe {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.45; transform: scale(0.72); }
}
.auth-title {
  font-family: var(--serif);
  font-size: clamp(42px, 5.2vw, 70px);
  line-height: 1.02;
  letter-spacing: -0.02em;
  font-weight: 600;
  color: var(--r-ink);
  margin: 0 0 24px;
}
.auth-title em {
  font-style: italic;
  font-weight: 500;
  background: linear-gradient(100deg, #D99A2B 0%, #C0841C 50%, #A5711B 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
.auth-lede { color: var(--r-muted); font-size: 17px; line-height: 1.65; max-width: 460px; margin: 0; }
.auth-points { list-style: none; margin: 34px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 10px; }
.auth-points li {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  font-size: 13.5px;
  font-weight: 500;
  color: var(--r-muted);
  background: var(--r-card);
  border: 1px solid var(--r-line);
  border-radius: 999px;
  padding: 9px 16px;
  box-shadow: 0 14px 30px -20px rgba(34, 28, 19, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.9);
}
.auth-points svg { width: 14px; height: 14px; color: var(--r-gold); flex-shrink: 0; }

/* ---- rail cards (register) ---- */
.auth-rail { list-style: none; margin: 38px 0 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.rail-card {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  border-radius: 20px;
  padding: 18px 20px;
  background: var(--r-card);
  border: 1px solid var(--r-line);
  border-left: 3px solid var(--r-gold-soft);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.95), 0 22px 44px -26px rgba(34, 28, 19, 0.4);
}
.rail-num {
  font-family: var(--mono);
  font-size: 12px;
  font-weight: 500;
  color: var(--r-gold);
  border: 1px solid rgba(192, 132, 28, 0.35);
  background: rgba(232, 169, 61, 0.12);
  border-radius: 999px;
  padding: 3px 10px;
  flex-shrink: 0;
  margin-top: 2px;
}
.rail-card h3 { margin: 0 0 5px; font-size: 15.5px; font-weight: 600; color: var(--r-ink); letter-spacing: -0.01em; }
.rail-card p { margin: 0; font-size: 13.5px; line-height: 1.55; color: var(--r-muted); }
.rail-note {
  margin: 16px 0 0;
  border-radius: 16px;
  padding: 15px 18px;
  background: var(--r-navy);
  color: #DCE7F6;
  font-size: 13.5px;
  line-height: 1.6;
  border: 1px solid rgba(255, 255, 255, 0.12);
  box-shadow: 0 22px 40px -24px rgba(11, 46, 92, 0.6);
}
.rail-note strong { color: #fff; font-weight: 600; }
.rail-note svg { width: 15px; height: 15px; color: var(--r-gold-soft); vertical-align: -2px; margin-right: 6px; }

/* ---- double-bezel shell ---- */
.auth-shell {
  background: rgba(34, 28, 19, 0.045);
  border: 1px solid rgba(34, 28, 19, 0.1);
  border-radius: 34px;
  padding: 12px;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6), 0 40px 80px -44px rgba(34, 28, 19, 0.4);
}
.auth-core {
  position: relative;
  overflow: hidden;
  background: linear-gradient(180deg, var(--r-card) 0%, var(--r-card-2) 100%);
  border: 1px solid rgba(34, 28, 19, 0.08);
  border-radius: calc(34px - 12px);
  padding: clamp(30px, 3.6vw, 48px);
  box-shadow: inset 0 1px 1px rgba(255, 255, 255, 1), inset 0 -1px 1px rgba(34, 28, 19, 0.05);
}
.auth-core::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, var(--r-navy) 6%, var(--r-gold-soft) 50%, var(--r-navy) 94%);
  pointer-events: none;
}

/* ---- card head ---- */
.auth-head { position: relative; margin-bottom: 30px; }
.auth-glyph {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  background: rgba(232, 169, 61, 0.16);
  border: 1px solid rgba(192, 132, 28, 0.3);
  display: grid;
  place-items: center;
  color: var(--r-gold);
  margin-bottom: 18px;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
}
.auth-glyph svg { width: 19px; height: 19px; }
.auth-head h2 {
  margin: 0 0 8px;
  font-family: var(--serif);
  font-size: 28px;
  font-weight: 600;
  letter-spacing: -0.02em;
  color: var(--r-navy);
}
.auth-subtitle { margin: 0; color: var(--r-muted); font-size: 15px; line-height: 1.55; }

/* ---- fields ---- */
.field { margin-bottom: 20px; }
.field-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--r-navy);
  margin: 0 0 8px;
  letter-spacing: 0.02em;
}
.req { color: #9A2C2C; font-weight: 700; }
.form-input {
  width: 100%;
  box-sizing: border-box;
  background: #fff;
  border: 1px solid var(--r-line-strong);
  border-radius: 15px;
  padding: 15px 17px;
  font-size: 15.5px;
  font-family: inherit;
  color: var(--r-ink);
  outline: none;
  transition: border-color 0.5s var(--ease), box-shadow 0.5s var(--ease), background-color 0.5s var(--ease);
}
.form-input::placeholder { color: rgba(107, 98, 83, 0.45); }
.form-input:hover { border-color: rgba(34, 28, 19, 0.32); }
.form-input:focus {
  border-color: rgba(232, 169, 61, 0.75);
  background: #FFFDF6;
  box-shadow: 0 0 0 4px rgba(232, 169, 61, 0.14), inset 0 0 0 1px rgba(232, 169, 61, 0.3);
}
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.form-section-label {
  font-family: var(--mono);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--r-gold);
  margin: 26px 0 16px;
  padding-top: 20px;
  border-top: 1px dashed rgba(34, 28, 19, 0.16);
}

/* ---- password toggle ---- */
.password-wrap { position: relative; }
.password-wrap .form-input { padding-right: 54px; }
.pw-toggle {
  position: absolute;
  right: 7px;
  top: 50%;
  transform: translateY(-50%);
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: none;
  background: transparent;
  color: var(--r-dim);
  cursor: pointer;
  display: grid;
  place-items: center;
  transition: background-color 0.4s var(--ease), color 0.4s var(--ease);
}
.pw-toggle:hover { background: rgba(232, 169, 61, 0.12); color: var(--r-gold); }
.pw-toggle svg { position: absolute; width: 19px; height: 19px; transition: opacity 0.35s var(--ease); }
.pw-toggle .pw-hide { opacity: 0; }

/* ---- upload dropzone ---- */
.upload-zone {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  text-align: center;
  padding: 26px 20px 24px;
  border: 1.5px dashed rgba(34, 28, 19, 0.24);
  border-radius: 18px;
  cursor: pointer;
  background: #fff;
  transition: border-color 0.5s var(--ease), background-color 0.5s var(--ease), box-shadow 0.5s var(--ease);
}
.upload-zone:hover { border-color: rgba(232, 169, 61, 0.7); background: #FFFDF4; box-shadow: 0 14px 30px -22px rgba(34, 28, 19, 0.3); }
.upload-zone.has-file { border-style: solid; border-color: rgba(192, 132, 28, 0.55); background: #FFFCF1; }
.upload-zone input[type="file"] { display: none; }
.upload-icon {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: rgba(232, 169, 61, 0.16);
  border: 1px solid rgba(192, 132, 28, 0.3);
  display: grid;
  place-items: center;
  color: var(--r-gold);
  margin-bottom: 4px;
  transition: transform 0.5s var(--ease);
}
.upload-zone:hover .upload-icon { transform: translateY(-2px) scale(1.04); }
.upload-icon svg { width: 20px; height: 20px; }
.upload-label { font-size: 15px; font-weight: 600; color: var(--r-ink); }
.upload-sub { font-size: 12px; color: var(--r-muted); font-family: var(--mono); }
.upload-chip {
  margin-top: 10px;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12.5px;
  font-family: var(--mono);
  color: var(--r-gold);
  background: rgba(232, 169, 61, 0.14);
  border: 1px solid rgba(192, 132, 28, 0.35);
  border-radius: 999px;
  padding: 6px 14px;
}

/* ---- submit button ---- */
.btn-submit {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  width: 100%;
  margin-top: 28px;
  padding: 6px 6px 6px 26px;
  border: none;
  border-radius: 999px;
  background: var(--r-navy);
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18), 0 20px 42px -18px rgba(11, 46, 92, 0.55);
  transition: transform 0.55s var(--ease), box-shadow 0.55s var(--ease), background-color 0.55s var(--ease);
}
.btn-submit:hover { background: var(--r-blue); transform: translateY(-1px); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18), 0 26px 52px -18px rgba(11, 46, 92, 0.6); }
.btn-submit:active { transform: scale(0.98); }
.btn-orb {
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  border-radius: 50%;
  background: rgba(232, 169, 61, 0.22);
  color: var(--r-gold-soft);
  display: grid;
  place-items: center;
  transition: transform 0.55s var(--ease);
}
.btn-orb svg { width: 18px; height: 18px; }
.btn-submit:hover .btn-orb { transform: translateX(3px) translateY(-1px) scale(1.05); }

/* ---- alerts ---- */
.alert { margin: 0 0 22px; border-radius: 16px; padding: 13px 17px; font-size: 14px; line-height: 1.55; }
.alert ul { margin: 0; padding-left: 18px; }
.alert li { margin: 3px 0; }
.alert-error { background: var(--r-err-bg); border: 1px solid var(--r-err-line); color: var(--r-err-ink); }
.alert-success { background: var(--r-ok-bg); border: 1px solid var(--r-ok-line); color: var(--r-ok-ink); }

/* ---- footnotes ---- */
.auth-switch { margin: 24px 0 0; text-align: center; color: var(--r-muted); font-size: 15px; }
.auth-switch a { font-weight: 600; }
.auth-note { margin: 12px 0 0; text-align: center; color: var(--r-dim); font-size: 12.5px; font-family: var(--mono); }

/* ---- scroll reveals ---- */
.auth-reveal { transition: opacity 0.9s var(--ease), transform 0.9s var(--ease), filter 0.9s var(--ease); transition-delay: var(--d, 0ms); }
html.js .auth-reveal { opacity: 0; transform: translateY(28px); filter: blur(9px); will-change: transform, opacity; }
html.js .auth-reveal.is-in { opacity: 1; transform: translateY(0); filter: blur(0); will-change: auto; }

/* ---- responsive ---- */
@media (max-width: 940px) {
  .auth-stage, .auth-stage--register { grid-template-columns: 1fr; gap: 40px; }
  .auth-lede { max-width: 520px; }
  .auth-rail .rail-card { transform: none !important; margin-bottom: 0 !important; }
}
@media (max-width: 480px) {
  .form-row-2 { grid-template-columns: 1fr; }
  .auth-core { padding: 26px 20px; }
  .auth-shell { border-radius: 28px; padding: 10px; }
  .auth-core { border-radius: calc(28px - 10px); }
}
/* ---- forgot password link ---- */
.auth-forgot { text-align: right; margin: -8px 0 18px; font-size: 14px; }
.auth-forgot a { color: var(--r-gold); font-weight: 500; }
.auth-forgot a:hover { color: var(--r-navy); }

@media (prefers-reduced-motion: reduce) {
  html.js .auth-reveal { opacity: 1; transform: none; filter: none; transition: none; }
  .eyebrow-dot { animation: none; }
}
</style>
