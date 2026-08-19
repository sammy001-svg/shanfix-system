<?php
/**
 * The questions of a brief, rendered as a form.
 *
 * Used by the client's own page and by the version a colleague fills in
 * with them, so the two can never ask different things or store answers
 * under different names.
 *
 * Expects: $fields (from JobBrief::fields), $answers (key => answer).
 */
$answers = $answers ?? [];

// A failed submission comes back with everything still typed in, which on
// a phone is the difference between finishing and giving up.
$valueFor = static function (array $field) use ($answers) {
    $old = old_array('answers');

    if (array_key_exists($field['key'], $old)) {
        return $old[$field['key']];
    }

    return $answers[$field['key']] ?? '';
};

// Checkbox answers are stored as one comma-separated line, so they have
// to be split back apart to know which boxes to tick.
$ticked = static function ($value): array {
    if (is_array($value)) {
        return array_map('trim', $value);
    }

    return array_filter(array_map('trim', explode(',', (string) $value)), static fn($v) => $v !== '');
};
?>

<?php foreach ($fields as $field): ?>
  <?php
    $value    = $valueFor($field);
    $required = !empty($field['required']);
    $id       = 'f_' . $field['key'];
  ?>
  <div class="field">
    <label class="label" for="<?= e($id) ?>">
      <?= e($field['label']) ?>
      <?php if ($required): ?><span class="req" title="We need this one">*</span><?php endif; ?>
    </label>

    <?php if ($field['type'] === 'textarea'): ?>
      <textarea class="textarea" id="<?= e($id) ?>"
                name="answers[<?= e($field['key']) ?>]"
                rows="3"<?= $required ? ' required' : '' ?>><?= e(is_array($value) ? implode(', ', $value) : $value) ?></textarea>

    <?php elseif ($field['type'] === 'select'): ?>
      <select class="select" id="<?= e($id) ?>" name="answers[<?= e($field['key']) ?>]"<?= $required ? ' required' : '' ?>>
        <option value="">Choose one…</option>
        <?php foreach ($field['options'] as $opt): ?>
          <option value="<?= e($opt) ?>" <?= (string) $value === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
        <?php endforeach; ?>
      </select>

    <?php elseif ($field['type'] === 'checks'): ?>
      <?php $on = $ticked($value); ?>
      <div class="checkgrid">
        <?php foreach ($field['options'] as $opt): ?>
          <label class="check-row">
            <input type="checkbox"
                   name="answers[<?= e($field['key']) ?>][]"
                   value="<?= e($opt) ?>"
                   <?= in_array($opt, $on, true) ? 'checked' : '' ?>>
            <span><?= e($opt) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <input class="input" type="text" id="<?= e($id) ?>"
             name="answers[<?= e($field['key']) ?>]"
             value="<?= e(is_array($value) ? implode(', ', $value) : $value) ?>"<?= $required ? ' required' : '' ?>>
    <?php endif; ?>

    <?php if (!empty($field['hint'])): ?>
      <span class="field-hint"><?= e($field['hint']) ?></span>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
