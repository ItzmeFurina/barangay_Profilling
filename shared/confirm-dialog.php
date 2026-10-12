<?php
/**
 * Premium confirmation / prompt dialog, shared by both portals.
 *
 * Renders once per page (hidden), then exposes two promise-based APIs:
 *
 *   brgyConfirm({ title, message, confirmLabel, danger }) -> Promise<boolean>
 *   brgyPrompt ({ title, message, confirmLabel, danger, placeholder })
 *                    -> Promise<string|null>   (null = cancelled, '' not possible)
 *
 * Any <form> carrying the data-confirm attribute gets confirmed
 * automatically (delegated, so dynamically inserted forms work too):
 *   <form data-confirm
 *         data-confirm-title="Delete this event?"
 *         data-confirm-message="This cannot be undone."
 *         data-confirm-label="Delete"
 *         data-confirm-danger>
 *
 * Design: the double-bezel - a machined navy tray holding a cream paper
 * dossier, the same language as the resident dossier modal. Motion is
 * transform/opacity only, custom expo cubic-bezier, staggered entry,
 * Esc / backdrop-click cancel, Tab trapped inside the dialog.
 */
if (defined('BRGY_CONFIRM_DIALOG_LOADED')) {
    return;
}
define('BRGY_CONFIRM_DIALOG_LOADED', true);
?>
<div class="brgy-dialog-backdrop" id="brgy-dialog" role="dialog" aria-modal="true" aria-hidden="true"
     onclick="if (event.target === this &amp;&amp; window.brgyDialogCancel) { brgyDialogCancel(); }">
  <div class="brgy-dialog-shell">
    <div class="brgy-dialog-core">
      <div class="brgy-dialog-icon"><span class="brgy-dialog-icon-inner" id="brgy-dialog-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>
      </span></div>
      <span class="brgy-dialog-eyebrow" id="brgy-dialog-eyebrow">Please confirm</span>
      <h2 class="brgy-dialog-title" id="brgy-dialog-title"></h2>
      <p class="brgy-dialog-message" id="brgy-dialog-message"></p>
      <textarea class="brgy-dialog-input" id="brgy-dialog-input" rows="3" hidden></textarea>
      <p class="brgy-dialog-hint" id="brgy-dialog-hint" hidden></p>
      <div class="brgy-dialog-actions">
        <button type="button" class="brgy-dialog-btn brgy-dialog-cancel" id="brgy-dialog-cancel">Cancel</button>
        <button type="button" class="brgy-dialog-btn brgy-dialog-ok" id="brgy-dialog-ok">
          <span class="brgy-dialog-ok-label" id="brgy-dialog-ok-label">Confirm</span>
          <span class="brgy-dialog-ok-icon" id="brgy-dialog-ok-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>
          </span>
        </button>
      </div>
    </div>
  </div>
</div>

<style>
/* ============ Confirm dialog - double-bezel dossier ============ */
.brgy-dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: 110;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: rgba(6, 14, 28, 0.55);
  -webkit-backdrop-filter: blur(10px);
  backdrop-filter: blur(10px);
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.45s cubic-bezier(0.32, 0.72, 0, 1), visibility 0s linear 0.45s;
}
.brgy-dialog-backdrop.is-open {
  opacity: 1;
  visibility: visible;
  transition: opacity 0.45s cubic-bezier(0.32, 0.72, 0, 1);
}

/* Outer shell: the machined navy tray. */
.brgy-dialog-shell {
  width: 100%;
  max-width: 450px;
  padding: 10px;
  border-radius: 30px;
  background:
    radial-gradient(440px 240px at 92% -18%, rgba(232, 169, 61, 0.28), transparent 60%),
    radial-gradient(360px 220px at -6% 108%, rgba(27, 95, 168, 0.42), transparent 58%),
    linear-gradient(160deg, #14396E, #071C38 72%);
  box-shadow: 0 30px 60px -20px rgba(4, 14, 30, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.14);
  transform: translateY(22px) scale(0.96);
  transition: transform 0.55s cubic-bezier(0.32, 0.72, 0, 1);
}
.brgy-dialog-backdrop.is-open .brgy-dialog-shell { transform: translateY(0) scale(1); }

/* Inner core: the cream paper dossier, concentric radius. */
.brgy-dialog-core {
  border-radius: 21px;
  padding: 34px 34px 30px;
  background: linear-gradient(180deg, #FDFAF3, #FBF6EC 58%, #F4ECDC);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.9),
    inset 0 0 0 1px rgba(46, 36, 24, 0.05);
}

/* Staggered entry: each child rises from an invisible box. */
.brgy-dialog-core > * {
  opacity: 0;
  transform: translateY(16px);
  animation: brgyRise 0.7s cubic-bezier(0.32, 0.72, 0, 1) forwards;
  animation-delay: var(--d, 0ms);
}
.brgy-dialog-core.d1 > *:nth-child(1) { --d: 40ms; }
.brgy-dialog-core.d1 > *:nth-child(2) { --d: 120ms; }
.brgy-dialog-core.d1 > *:nth-child(3) { --d: 170ms; }
.brgy-dialog-core.d1 > *:nth-child(4) { --d: 220ms; }
.brgy-dialog-core.d1 > *:nth-child(5) { --d: 270ms; }
.brgy-dialog-core.d1 > *:nth-child(6) { --d: 320ms; }
.brgy-dialog-core.d1 > *:nth-child(7) { --d: 370ms; }
@keyframes brgyRise {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Icon: a gold bezel disc holding a recessed navy disc. */
.brgy-dialog-icon {
  width: 58px;
  height: 58px;
  border-radius: 50%;
  padding: 3px;
  background: linear-gradient(140deg, #E8C37F, #C08A2E);
  box-shadow: 0 8px 20px -8px rgba(192, 138, 46, 0.55);
}
.brgy-dialog-icon-inner {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--navy, #0B2E5C);
  box-shadow: inset 0 2px 7px rgba(6, 14, 28, 0.4);
}
.brgy-dialog-icon-inner svg { width: 24px; height: 24px; stroke: var(--gold, #E8A93D); }
.brgy-dialog-backdrop.is-danger .brgy-dialog-icon { background: linear-gradient(140deg, #E8A08F, #9A2C2C); }
.brgy-dialog-backdrop.is-danger .brgy-dialog-icon-inner { background: #7E2424; }

.brgy-dialog-eyebrow {
  display: inline-block;
  align-self: flex-start;
  margin-top: 22px;
  padding: 5px 12px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: #8A6A1F;
  background: rgba(232, 169, 61, 0.14);
  box-shadow: inset 0 0 0 1px rgba(192, 138, 46, 0.35);
}
.brgy-dialog-backdrop.is-danger .brgy-dialog-eyebrow {
  color: #96413A;
  background: rgba(154, 44, 44, 0.09);
  box-shadow: inset 0 0 0 1px rgba(154, 44, 44, 0.3);
}

.brgy-dialog-title {
  margin: 12px 0 10px;
  font-family: var(--font-display, Georgia, serif);
  font-weight: 600;
  font-size: 25px;
  line-height: 1.15;
  letter-spacing: -0.01em;
  color: var(--navy, #0B2E5C);
  word-break: break-word;
}
.brgy-dialog-message {
  margin: 0;
  font-size: 15px;
  line-height: 1.6;
  color: #57493A;
  word-break: break-word;
}

.brgy-dialog-input {
  width: 100%;
  margin-top: 20px;
  padding: 13px 15px;
  border: 1px solid rgba(46, 36, 24, 0.14);
  border-radius: 13px;
  background: rgba(255, 255, 255, 0.75);
  font-family: var(--font-sans, sans-serif);
  font-size: 14px;
  line-height: 1.5;
  color: #2E2418;
  resize: vertical;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
}
.brgy-dialog-input:focus {
  outline: none;
  border-color: rgba(192, 138, 46, 0.7);
  box-shadow: 0 0 0 3px rgba(232, 169, 61, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.9);
}
.brgy-dialog-hint {
  margin: 8px 0 0;
  font-size: 12px;
  font-weight: 500;
  color: var(--danger, #9A2C2C);
}

.brgy-dialog-actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 12px;
  margin-top: 28px;
}
.brgy-dialog-btn {
  border: 0;
  cursor: pointer;
  font-family: var(--font-sans, sans-serif);
  font-weight: 600;
  font-size: 14px;
  letter-spacing: 0.01em;
  display: inline-flex;
  align-items: center;
  transition: background-color 0.5s cubic-bezier(0.32, 0.72, 0, 1),
              box-shadow 0.5s cubic-bezier(0.32, 0.72, 0, 1),
              transform 0.5s cubic-bezier(0.32, 0.72, 0, 1);
}
.brgy-dialog-btn:active { transform: scale(0.97); }
.brgy-dialog-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(232, 169, 61, 0.4);
}

/* Cancel: quiet hairline pill. */
.brgy-dialog-cancel {
  padding: 12px 20px;
  border-radius: 999px;
  background: transparent;
  color: var(--navy, #0B2E5C);
  box-shadow: inset 0 0 0 1px rgba(11, 46, 92, 0.2);
}
.brgy-dialog-cancel:hover { background: rgba(11, 46, 92, 0.06); }

/* Confirm: navy pill with a nested trailing icon disc. */
.brgy-dialog-ok {
  padding: 8px 8px 8px 22px;
  border-radius: 999px;
  background: var(--navy, #0B2E5C);
  color: #FFFFFF;
  box-shadow: 0 12px 24px -10px rgba(11, 46, 92, 0.55);
}
.brgy-dialog-backdrop.is-danger .brgy-dialog-ok {
  background: var(--danger, #9A2C2C);
  box-shadow: 0 12px 24px -10px rgba(154, 44, 44, 0.55);
}
.brgy-dialog-ok-icon {
  width: 34px;
  height: 34px;
  margin-left: 12px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.14);
  transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1),
              background-color 0.5s cubic-bezier(0.32, 0.72, 0, 1);
}
.brgy-dialog-ok-icon svg { width: 15px; height: 15px; stroke: var(--gold, #E8A93D); transition: stroke 0.5s cubic-bezier(0.32, 0.72, 0, 1); }
.brgy-dialog-ok:hover .brgy-dialog-ok-icon {
  transform: translate(1px, -1px) scale(1.07);
  background: var(--gold, #E8A93D);
}
.brgy-dialog-ok:hover .brgy-dialog-ok-icon svg { stroke: var(--gold-text, #4A2F04); }

/* Invalid-prompt shake - transform only, never layout properties. */
@keyframes brgyShake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-6px); }
  50% { transform: translateX(5px); }
  75% { transform: translateX(-3px); }
}
.brgy-dialog-backdrop.is-shake .brgy-dialog-shell {
  animation: brgyShake 0.45s cubic-bezier(0.32, 0.72, 0, 1);
}

@media (prefers-reduced-motion: reduce) {
  .brgy-dialog-backdrop,
  .brgy-dialog-shell,
  .brgy-dialog-btn,
  .brgy-dialog-ok-icon,
  .brgy-dialog-ok-icon svg,
  .brgy-dialog-core > * {
    transition: none;
    animation: none;
    opacity: 1;
    transform: none;
  }
}

@media (max-width: 640px) {
  .brgy-dialog-backdrop { padding: 16px; }
  .brgy-dialog-shell { border-radius: 24px; padding: 8px; }
  .brgy-dialog-core { border-radius: 17px; padding: 26px 22px 22px; }
  .brgy-dialog-actions { flex-direction: column-reverse; align-items: stretch; }
  .brgy-dialog-btn { justify-content: center; }
}
</style>

<script>
(function () {
  var backdrop  = document.getElementById('brgy-dialog');
  var core      = backdrop.querySelector('.brgy-dialog-core');
  var shell     = backdrop.querySelector('.brgy-dialog-shell');
  var titleEl   = document.getElementById('brgy-dialog-title');
  var msgEl     = document.getElementById('brgy-dialog-message');
  var eyebrowEl = document.getElementById('brgy-dialog-eyebrow');
  var inputEl   = document.getElementById('brgy-dialog-input');
  var hintEl    = document.getElementById('brgy-dialog-hint');
  var okBtn     = document.getElementById('brgy-dialog-ok');
  var okLabel   = document.getElementById('brgy-dialog-ok-label');
  var cancelBtn = document.getElementById('brgy-dialog-cancel');
  var iconCheck = document.getElementById('brgy-dialog-icon');
  var okIcon    = document.getElementById('brgy-dialog-ok-icon');

  var resolveFn = null;
  var mode = null;
  var lastFocused = null;

  function reopenAnimation() {
    core.classList.remove('d1');
    void core.offsetWidth;
    core.classList.add('d1');
  }

  function openDialog(m, opts) {
    mode = m;
    backdrop.classList.remove('is-danger', 'is-shake');
    if (opts.danger) { backdrop.classList.add('is-danger'); }

    titleEl.textContent = opts.title || 'Please confirm';
    msgEl.textContent = opts.message || '';
    okLabel.textContent = opts.confirmLabel || (m === 'prompt' ? 'Submit' : 'Confirm');
    eyebrowEl.textContent = m === 'prompt' ? 'Details required' : 'Please confirm';
    inputEl.hidden = m !== 'prompt';
    hintEl.hidden = true;
    inputEl.value = '';
    if (m === 'prompt') { inputEl.placeholder = opts.placeholder || ''; }

    iconCheck.innerHTML = (m === 'prompt')
      ? '<svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20V4"/><path d="m5.5 10.5 6.5-6.5 6.5 6.5"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>';

    lastFocused = document.activeElement;
    backdrop.setAttribute('aria-hidden', 'false');
    backdrop.classList.add('is-open');
    reopenAnimation();
    cancelBtn.focus();
    if (m === 'prompt') { inputEl.focus(); }

    return new Promise(function (resolve) { resolveFn = resolve; });
  }

  function closeDialog(value) {
    if (resolveFn === null) { return; }
    backdrop.classList.remove('is-open');
    backdrop.setAttribute('aria-hidden', 'true');
    var r = resolveFn;
    resolveFn = null;
    if (lastFocused && lastFocused.focus) { lastFocused.focus(); }
    window.setTimeout(function () { r(value); }, 1);
  }

  window.brgyDialogCancel = function () { closeDialog(null); };

  window.brgyConfirm = function (opts) { return openDialog('confirm', opts || {}); };
  window.brgyPrompt  = function (opts) { return openDialog('prompt',  opts || {}); };

  okBtn.addEventListener('click', function () {
    if (mode === 'prompt') {
      var value = inputEl.value.trim();
      if (value === '') {
        backdrop.classList.remove('is-shake');
        void backdrop.offsetWidth;
        backdrop.classList.add('is-shake');
        hintEl.textContent = 'Please enter a reason - it is shown to the resident.';
        hintEl.hidden = false;
        inputEl.focus();
        return;
      }
      closeDialog(value);
    } else {
      closeDialog(true);
    }
  });

  cancelBtn.addEventListener('click', function () { closeDialog(null); });

  document.addEventListener('keydown', function (e) {
    if (!backdrop.classList.contains('is-open')) { return; }
    if (e.key === 'Escape') { closeDialog(null); return; }
    if (e.key !== 'Tab') { return; }
    var focusables = [cancelBtn, okBtn];
    var first = focusables[0], last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });

  /* Delegated: any form with data-confirm gets confirmed before submit.
     Works for dynamically inserted forms (e.g. the events live feed).

     The confirmed form is re-dispatched with form.requestSubmit(submitter)
     instead of form.submit(): the programmatic submit() silently drops the
     clicked submit button's name/value, which several server handlers rely
     on (e.g. Accounts reads $_POST['action'] straight off the button), so
     with submit() those archives did nothing at all. requestSubmit() keeps
     the real submitter, so the button travels with the POST. */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.getAttribute || !form.hasAttribute('data-confirm')) { return; }

    // Second dispatch from requestSubmit() below - the guard was consumed,
    // let the browser proceed with the native submission.
    if (form.dataset.brgyConfirmed === '1') {
      delete form.dataset.brgyConfirmed;
      return;
    }

    e.preventDefault();
    window.brgyConfirm({
      title:        form.getAttribute('data-confirm-title')  || 'Please confirm',
      message:      form.getAttribute('data-confirm-message') || '',
      confirmLabel: form.getAttribute('data-confirm-label')  || 'Continue',
      danger:       form.hasAttribute('data-confirm-danger'),
    }).then(function (ok) {
      if (!ok) { return; }
      if (form.requestSubmit) {
        form.dataset.brgyConfirmed = '1';
        // requestSubmit() dispatches the second submit event synchronously
        // (when the form passes native validation). Clearing the guard right
        // after it returns closes the race where a native-validation failure
        // would otherwise leave the guard set for a second, letting the very
        // next click submit without any confirmation at all.
        form.requestSubmit(e.submitter || undefined);
        delete form.dataset.brgyConfirmed;
      } else {
        form.submit();
      }
    });
  });
})();
</script>
