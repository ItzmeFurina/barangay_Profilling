<?php
require_once __DIR__ . '/../../shared/functions.php';

/**
 * Renders one household-member fieldset. $index may be an int (real row) or
 * the string '__INDEX__' (JS template).
 */
function render_member_fieldset($index, array $data, bool $isFirst): void {
    $v = function ($key) use ($data) {
        return isset($data[$key]) ? e((string) $data[$key]) : '';
    };
    $checked = function ($key) use ($data) {
        return !empty($data[$key]) ? 'checked' : '';
    };
    $selected = function ($key, $value) use ($data) {
        return (($data[$key] ?? '') === $value) ? 'selected' : '';
    };
    $headValue = $data['_head'] ?? ($isFirst ? '0' : '');
    $isHeadSelected = ((string) $headValue === (string) $index) ? ' is-checked' : '';
    $memberLabel = $isFirst ? 'You (account holder)' : 'Household member';
    $situationOptions = [
        'is_pwd'         => 'Person with disability (PWD)',
        'is_solo_parent' => 'Solo parent',
        'is_ofw'         => 'OFW or OFW dependent',
        'is_osy'         => 'Out-of-school youth',
        'is_ip'          => 'Indigenous person',
        'is_unemployed'  => 'Unemployed',
        'is_employed'    => 'Employed',
        'is_student'     => 'Student',
    ];
    ?>
    <fieldset class="member-fieldset" data-index="<?= is_int($index) ? $index : '' ?>">
      <legend class="visually-hidden"><?= e($memberLabel) ?></legend>
      <div class="member-fieldset-head">
        <span class="member-number-chip member-number"><?= is_int($index) ? $index + 1 : '' ?></span>
        <span class="member-legend-name"><?= e($memberLabel) ?></span>
        <?php if ($isFirst): ?>
          <span class="member-you-chip">You</span>
        <?php endif; ?>
        <?php if (!$isFirst): ?>
          <span class="member-remove-wrap">
            <button type="button" class="remove-member-btn">Remove</button>
          </span>
        <?php endif; ?>
      </div>
      <div class="member-fieldset-body">
        <div class="member-grid">
          <div>
            <label>Last name <span class="req" title="Required">*</span></label>
            <input type="text" name="members[<?= $index ?>][last_name]" value="<?= $v('last_name') ?>" required>
          </div>
          <div>
            <label>First name <span class="req" title="Required">*</span></label>
            <input type="text" name="members[<?= $index ?>][first_name]" value="<?= $v('first_name') ?>" required>
          </div>
          <div>
            <label>Middle name</label>
            <input type="text" name="members[<?= $index ?>][middle_name]" value="<?= $v('middle_name') ?>">
          </div>
          <div>
            <label>Ext. (Jr., Sr., etc.)</label>
            <input type="text" name="members[<?= $index ?>][suffix]" value="<?= $v('suffix') ?>">
          </div>
          <div>
            <label>Place of birth <span class="req" title="Required">*</span></label>
            <input type="text" name="members[<?= $index ?>][place_of_birth]" value="<?= $v('place_of_birth') ?>" required>
          </div>
          <div>
            <label>Date of birth <span class="req" title="Required">*</span></label>
            <input type="date" name="members[<?= $index ?>][birthdate]" value="<?= $v('birthdate') ?>" required>
          </div>
          <div>
            <label>Sex <span class="req" title="Required">*</span></label>
            <select name="members[<?= $index ?>][sex]" required>
              <option value="">Select</option>
              <option value="Male" <?= $selected('sex', 'Male') ?>>Male</option>
              <option value="Female" <?= $selected('sex', 'Female') ?>>Female</option>
            </select>
          </div>
          <div>
            <label>Civil status <span class="req" title="Required">*</span></label>
            <select name="members[<?= $index ?>][civil_status]" required>
              <option value="">Select</option>
              <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled'] as $cs): ?>
                <option value="<?= $cs ?>" <?= $selected('civil_status', $cs) ?>><?= $cs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Citizenship <span class="req" title="Required">*</span></label>
            <input type="text" name="members[<?= $index ?>][citizenship]" value="<?= e((string) ($data['citizenship'] ?? 'Filipino')) ?>" required>
          </div>
          <div>
            <label>Occupation <span class="req" title="Required">*</span></label>
            <input type="text" name="members[<?= $index ?>][occupation]" value="<?= $v('occupation') ?>" placeholder="Type N/A if none" required>
          </div>
        </div>

        <fieldset class="situation-group">
          <legend>Check all that apply</legend>
          <div class="situation-grid">
            <?php foreach ($situationOptions as $key => $label): ?>
              <label class="situation-option<?= !empty($data[$key]) ? ' is-checked' : '' ?>">
                <input type="checkbox" name="members[<?= $index ?>][<?= $key ?>]" value="1" <?= $checked($key) ?>>
                <span><?= e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <label class="head-radio-card<?= $isHeadSelected ?>">
          <input type="radio" name="household_head_index" value="<?= $index ?>" <?= ((string) $headValue === (string) $index) ? 'checked' : '' ?>>
          <span class="head-radio-text">
            <strong>Head of household</strong>
            <small>Select who is the head of this household.</small>
          </span>
        </label>
      </div>
    </fieldset>
    <?php
}

/**
 * Shared CSS for the census-style household form. Returns a <style> block so
 * pages can echo it inside <head>. Kept inline (in addition to
 * assets/css/style.css) so the form always renders correctly even if the
 * shared stylesheet is out of date or fails to load on a given deployment.
 */
function census_form_styles(): string {
    ob_start();
    ?>
    <style>
.visually-hidden {
  position: absolute; width: 1px; height: 1px;
  margin: -1px; padding: 0; overflow: hidden;
  clip: rect(0 0 0 0); border: 0; white-space: nowrap;
}
.req { color: #9A2C2C; font-weight: 700; }

.census-card { max-width: 880px; padding: 34px 38px 40px; }
.census-card h1 { font-size: 26px; margin-bottom: 6px; }
.census-card .auth-subtitle { line-height: 1.6; }

.census-section { margin-top: 28px; }
.census-section-head { display: flex; align-items: flex-start; gap: 13px; }
.census-step-badge {
  flex: none; width: 30px; height: 30px; border-radius: 50%;
  background: #0B2E5C; color: #fff;
  font-family: 'Fraunces', Georgia, serif;
  font-size: 17px; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  margin-top: 2px;
}
.census-section-head .section-label {
  margin: 0; padding: 0; border: none; font-size: 19px;
}
.census-section-head .muted-note { margin: 3px 0 0; line-height: 1.5; }

.census-card .member-grid { margin-top: 16px; gap: 14px 12px; }
.census-card .member-grid label { margin-bottom: 5px; font-size: 14.5px; font-weight: 600; }
.census-card .member-grid input, .census-card .member-grid select { font-size: 16px; padding: 12px 13px; }

.member-fieldset {
  border: 1px solid #D6E1EE;
  border-radius: 12px;
  margin-top: 18px;
  background: #fff;
  overflow: hidden;
}
.member-fieldset-head {
  display: flex; align-items: center; gap: 10px;
  background: #EAF2FB;
  border-bottom: 1px solid #D6E1EE;
  padding: 10px 16px;
}
.member-number-chip {
  flex: none; width: 26px; height: 26px; border-radius: 50%;
  background: #0B2E5C; color: #fff;
  font-size: 14px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.member-legend-name { font-size: 16px; font-weight: 600; color: #0B2E5C; }
.member-you-chip {
  background: #E8A93D; color: #4A2F04;
  font-size: 13px; font-weight: 700;
  padding: 3px 10px; border-radius: 999px;
}
.member-remove-wrap { margin-left: auto; }
.remove-member-btn {
  background: #fff; border: 1px solid #D6E1EE; color: #9A2C2C;
  font-size: 14px; font-weight: 600; font-family: inherit;
  padding: 6px 12px; border-radius: 7px; cursor: pointer;
}
.remove-member-btn:hover { background: #FCEBEB; border-color: #E5B8B8; }
.member-fieldset-body { padding: 18px 18px 6px; }

.situation-group {
  border: 1px solid #D6E1EE;
  border-radius: 10px;
  padding: 14px;
  margin-top: 16px;
}
.situation-group legend {
  font-size: 15px; font-weight: 600; color: #0B2E5C;
  padding: 0 5px;
}
.situation-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.situation-option {
  position: relative;
  display: flex; align-items: center; gap: 10px;
  border: 1px solid #D6E1EE;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 15px; color: #16233A;
  cursor: pointer;
  background: #fff;
  transition: background 0.4s cubic-bezier(0.32, 0.72, 0, 1), border-color 0.4s cubic-bezier(0.32, 0.72, 0, 1);
}
.situation-option:hover { border-color: #1B5FA8; }
.situation-option input { width: auto; accent-color: #0B2E5C; flex: none; }
.situation-option.is-checked,
.situation-option:has(input:checked) {
  background: #EAF2FB;
  border-color: #1B5FA8;
  font-weight: 600; color: #0B2E5C;
}

.head-radio-card {
  display: flex; align-items: center; gap: 12px;
  border: 1px dashed #D6E1EE;
  border-radius: 10px;
  padding: 13px 15px;
  margin: 14px 0;
  background: #FAFCFF;
  cursor: pointer;
  transition: background 0.4s cubic-bezier(0.32, 0.72, 0, 1), border-color 0.4s cubic-bezier(0.32, 0.72, 0, 1);
}
.head-radio-card:hover { border-color: #E8A93D; }
.head-radio-card input { width: auto; accent-color: #0B2E5C; flex: none; }
.head-radio-text { display: flex; flex-direction: column; gap: 1px; }
.head-radio-text strong { font-size: 15.5px; color: #0B2E5C; }
.head-radio-text small { font-size: 14px; color: #3C5470; font-weight: 400; }
.head-radio-card.is-checked,
.head-radio-card:has(input:checked) {
  background: #FDF6E7;
  border-color: #E8A93D;
  border-style: solid;
}

#add-member-btn {
  width: 100%;
  margin-top: 18px;
  background: #fff; color: #1B5FA8;
  border: 1px dashed #D6E1EE;
  padding: 12px;
  border-radius: 9px;
  font-size: 15.5px; font-weight: 600; font-family: inherit;
  cursor: pointer;
}
#add-member-btn:hover { background: #EAF2FB; }

.census-actions {
  margin-top: 26px;
  padding-top: 20px;
  border-top: 1px solid #D6E1EE;
}
.census-actions .btn-primary { margin-top: 0; }
.census-note {
  font-size: 14.5px; color: #3C5470;
  margin-top: 12px; text-align: center;
}

@media (max-width: 760px) {
  .census-card { padding: 24px 18px 32px; }
  .member-grid { grid-template-columns: 1fr; }
}
@media (max-width: 560px) {
  .census-card { padding: 20px 14px 28px; }
  .situation-grid { grid-template-columns: 1fr; }
}
    </style>
    <?php
    return ob_get_clean();
}

/**
 * Shared JS for adding / removing / renumbering household-member
 * fieldsets. Returns a <script> block; expects a #members-container,
 * #add-member-btn and #member-template element on the page.
 */
function census_form_scripts(): string {
    ob_start();
    ?>
    <script>
(function () {
  var container = document.getElementById('members-container');
  var template = document.getElementById('member-template');
  var addBtn = document.getElementById('add-member-btn');
  if (!container || !template || !addBtn) { return; }
  var memberCount = document.querySelectorAll('.member-fieldset').length;

  addBtn.addEventListener('click', function () {
    var clone = template.content.cloneNode(true);
    var fieldset = clone.querySelector('.member-fieldset');
    fieldset.dataset.index = memberCount;
    fieldset.querySelectorAll('[name]').forEach(function (el) {
      if (el.name) {
        el.name = el.name.replace('__INDEX__', memberCount);
      }
      if (el.type === 'radio' && el.value === '__INDEX__') {
        el.value = String(memberCount);
      }
    });
    container.appendChild(clone);
    memberCount++;
    renumberMembers();
  });

  container.addEventListener('click', function (e) {
    if (e.target.classList.contains('remove-member-btn')) {
      var fieldset = e.target.closest('.member-fieldset');
      var fields = fieldset.querySelectorAll('input[type="text"], input[type="date"], select');
      var hasData = Array.prototype.some.call(fields, function (el) {
        return el.value.trim() !== '';
      });
      if (!hasData) {
        fieldset.remove();
        renumberMembers();
        return;
      }
      window.brgyConfirm({
        title: 'Remove this household member?',
        message: 'The details entered for this member will be lost.',
        confirmLabel: 'Remove member',
        danger: true,
      }).then(function (ok) {
        if (!ok) { return; }
        fieldset.remove();
        renumberMembers();
      });
    }
  });

  container.addEventListener('change', function (e) {
    if (e.target.type === 'radio' && e.target.name === 'household_head_index') {
      container.querySelectorAll('.head-radio-card').forEach(function (card) {
        var radio = card.querySelector('input[type="radio"]');
        card.classList.toggle('is-checked', !!(radio && radio.checked));
      });
    } else {
      var option = e.target.closest('.situation-option');
      if (option) {
        option.classList.toggle('is-checked', e.target.checked);
      }
    }
  });

  // Occupation and the Employed / Unemployed boxes have to agree with each
  // other, the same way the server validates them. Typing a real job ticks
  // Employed and unt icks Unemployed; "N/A" and the other "none" markers
  // tick Unemployed (no occupation on file). Picking Unemployed clears the
  // job, and picking Employed jumps back to the job field.
  var NO_OCCUPATION = ['na', 'n/a', 'none', 'nil', 'no occupation', 'not applicable', 'n a', '-', '--', '—'];
  function hasRealOccupation(value) {
    var t = (value || '').trim().toLowerCase().replace(/[\s.]/g, '');
    return t !== '' && NO_OCCUPATION.indexOf(t) === -1;
  }
  container.addEventListener('input', function (e) {
    if (!/\[occupation\]$/.test(e.target.name || '')) { return; }
    var fieldset = e.target.closest('.member-fieldset');
    if (!fieldset) { return; }
    var employed = fieldset.querySelector('[name$="[is_employed]"]');
    var unemployed = fieldset.querySelector('[name$="[is_unemployed]"]');
    var realJob = hasRealOccupation(e.target.value);
    if (realJob) {
      if (employed && !employed.checked) { employed.checked = true; }
      if (unemployed && unemployed.checked) { unemployed.checked = false; }
    } else if (e.target.value.trim() !== '') {
      // "N/A" / "none" and friends - no occupation means Unemployed.
      if (unemployed && !unemployed.checked) { unemployed.checked = true; }
      if (employed && employed.checked) { employed.checked = false; }
    }
    if (employed) {
      employed.closest('.situation-option').classList.toggle('is-checked', employed.checked);
    }
    if (unemployed) {
      unemployed.closest('.situation-option').classList.toggle('is-checked', unemployed.checked);
    }
  });

  container.addEventListener('change', function (e) {
    var fieldset = e.target.closest('.member-fieldset');
    if (!fieldset) { return; }
    var occupation = fieldset.querySelector('[name$="[occupation]"]');
    if ((e.target.name || '').indexOf('[is_employed]') !== -1 && e.target.checked) {
      if (occupation) { occupation.focus(); }
    } else if ((e.target.name || '').indexOf('[is_unemployed]') !== -1 && e.target.checked) {
      if (occupation) { occupation.value = ''; }
    }
  });

  function renumberMembers() {
    document.querySelectorAll('.member-fieldset').forEach(function (fs, i) {
      var chip = fs.querySelector('.member-number');
      if (chip) {
        chip.textContent = i + 1;
      }
    });
  }
})();
    </script>
    <?php
    return ob_get_clean();
}