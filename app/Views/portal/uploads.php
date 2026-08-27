<?php
require_once APP_PATH . '/Views/partials/icons.php';

$state = [
    'new'      => ['navy',  'With our team'],
    'seen'     => ['amber', 'Being looked at'],
    'attached' => ['green', 'On your job'],
];
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Send us your artwork</h1>
    <p class="portal-lede">
      Logos, print-ready files, photographs, a document to be typeset —
      anything we need to do the work. It lands with our team straight away.
    </p>
  </div>

  <?php if (!$enabled): ?>
    <div class="alert alert--warning">
      <?= icon('alert-triangle') ?>
      <div class="alert__body">
        Sending files is switched off at the moment. Please email them to
        <?= e($company['email']) ?> and we will pick them up there.
      </div>
    </div>
  <?php else: ?>
    <div class="portal-card">
      <form method="post" action="<?= url('/portal/uploads') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="files">Choose your files</label>
          <input class="input" type="file" id="files" name="files[]" multiple required
                 accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
          <span class="field-hint">
            Images, PDFs, Word and Excel documents, or a zip. Several at once
            is fine, up to <?= (int) $maxMb ?>MB each. For anything larger,
            put it in a zip or send us a link.
          </span>
        </div>

        <div class="field">
          <label class="label" for="note">What is it for?</label>
          <input class="input" type="text" id="note" name="note" maxlength="500"
                 placeholder="e.g. Banner artwork for the Westlands expo, 3m x 1m">
          <span class="field-hint">
            One line saves a phone call. Sizes, quantities, when you need it.
          </span>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">
          <?= icon('paperclip') ?> Send these to us
        </button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($rows): ?>
    <div class="portal-card">
      <div class="fw-600 mb-8">What you have sent</div>
      <ul class="plain-list">
        <?php foreach ($rows as $r): ?>
          <?php [$tone, $label] = $state[$r['status']] ?? ['grey', $r['status']]; ?>
          <li>
            <?= icon('paperclip') ?>
            <span class="flex-1">
              <?= e($r['original_name']) ?>
              <span class="text-xs text-muted d-block">
                <?= e(human_bytes((int) $r['bytes'])) ?>
                · <?= e(fdate($r['created_at'])) ?>
                <?php if ($r['note']): ?> · <?= e(str_excerpt($r['note'], 40)) ?><?php endif; ?>
              </span>
            </span>
            <span class="badge badge--<?= e($tone) ?>"><?= e($label) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
