<?php
/**
 * Shared CSV export period/requirement picker modal.
 *
 * Expects `$exportConfig` to be set before including:
 *   title   (string) - heading shown in the navy band
 *   hint    (string) - one-line description under the heading
 *   action  (string) - GET endpoint that handles export=csv
 *   hidden  (array)  - extra hidden inputs, name => value (e.g. export => csv)
 *   fields  (array)  - selects; each row:
 *                      ['label' => ..., 'name' => ...,
 *                       'options' => [[value, label], ...], 'full' => bool]
 */
?>
<div class="export-modal-backdrop" id="export-modal" onclick="if (event.target === this) closeExportModal();">
  <div class="export-modal" role="dialog" aria-modal="true" aria-labelledby="export-modal-title">
    <div class="export-modal-card">
      <div class="export-modal-band">
        <span class="export-modal-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/></svg>
        </span>
        <div>
          <h2 id="export-modal-title"><?= e($exportConfig['title']) ?></h2>
          <p><?= e($exportConfig['hint']) ?></p>
        </div>
        <button type="button" class="export-modal-close" onclick="closeExportModal();" aria-label="Close export picker">&times;</button>
      </div>
      <form method="get" action="<?= e($exportConfig['action']) ?>" class="export-modal-body">
        <?php foreach ($exportConfig['hidden'] ?? [] as $key => $value): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
        <?php endforeach; ?>
        <div class="export-modal-fields">
          <?php foreach ($exportConfig['fields'] as $field): ?>
            <div class="<?= !empty($field['full']) ? 'ef-full' : '' ?>">
              <label for="export-<?= e($field['name']) ?>"><?= e($field['label']) ?></label>
              <select id="export-<?= e($field['name']) ?>" name="<?= e($field['name']) ?>">
                <?php foreach ($field['options'] as $opt): ?>
                  <option value="<?= e($opt[0]) ?>"><?= e($opt[1]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="export-modal-footer">
          <button type="button" class="export-cancel" onclick="closeExportModal();">Cancel</button>
          <button type="submit" class="save-btn">
            Download CSV
            <span class="save-btn-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/></svg>
            </span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
  function closeExportModal() {
    var modal = document.getElementById('export-modal');
    if (modal) modal.classList.remove('is-open');
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeExportModal();
  });
</script>
