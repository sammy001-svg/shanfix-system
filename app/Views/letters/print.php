<?php
require_once APP_PATH . '/Views/partials/icons.php';

$logoPath = $company['logo'] ? url('files/' . $company['logo']) : null;

// Address block: the parts that were filled in, each on its own line, in
// the order an envelope would carry them.
$addressLines = array_values(array_filter([
    $letter['recipient_title'],
    $letter['recipient_name'],
    $letter['recipient_org'],
]));
?>

<div class="print-bar no-print">
  <a class="btn btn--outline btn--sm" href="<?= url('/letters/' . $letter['id']) ?>">
    <?= icon('arrow-left') ?> Back
  </a>
  <a class="btn btn--primary btn--sm" href="<?= url('/letters/' . $letter['id'] . '/print?auto=1') ?>">
    <?= icon('printer') ?> Print
  </a>
  <span class="text-sm text-muted">
    Use your browser's “Save as PDF” option in the print dialog to produce a PDF.
  </span>
</div>

<div class="doc-sheet letter">

  <?php // Drawn from the company settings rather than stored on the
        // letter, so correcting a phone number corrects every letter and
        // not only the ones written afterwards. ?>
  <header class="letter__head">
    <div>
      <?php if ($logoPath): ?>
        <img class="letter__logo" src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>">
      <?php endif; ?>
      <div class="letter__company"><?= e($company['name']) ?></div>
      <?php if ($company['tagline']): ?>
        <div class="letter__tagline"><?= e($company['tagline']) ?></div>
      <?php endif; ?>
    </div>

    <div class="letter__contact">
      <?php if ($company['address']): ?><?= nl2br(e($company['address'])) ?><br><?php endif; ?>
      <?php if ($company['phone']): ?><?= e($company['phone']) ?><br><?php endif; ?>
      <?php if ($company['email']): ?><?= e($company['email']) ?><br><?php endif; ?>
      <?php if ($company['website']): ?><?= e($company['website']) ?><?php endif; ?>
    </div>
  </header>

  <div class="letter__rule"></div>

  <div class="letter__meta">
    <span><?= e($letter['reference']) ?></span>
    <span><?= e(date('j F Y', strtotime((string) $letter['letter_date']))) ?></span>
  </div>

  <div class="letter__to">
    <?php foreach ($addressLines as $line): ?>
      <?= e($line) ?><br>
    <?php endforeach; ?>
    <?php if ($letter['recipient_address']): ?>
      <?= nl2br(e($letter['recipient_address'])) ?>
    <?php endif; ?>
  </div>

  <p class="letter__salutation"><?= e($letter['salutation']) ?>,</p>

  <p class="letter__subject"><?= e($letter['subject']) ?></p>

  <?php // The writer's own paragraphing is what they intended, so blank
        // lines are kept as paragraph breaks rather than collapsed. ?>
  <div class="letter__body">
    <?php foreach (preg_split('/\n\s*\n/', trim((string) $letter['body'])) as $para): ?>
      <p><?= nl2br(e(trim($para))) ?></p>
    <?php endforeach; ?>
  </div>

  <div class="letter__sign">
    <p class="letter__closing"><?= e($letter['closing']) ?>,</p>

    <?php // Room for a pen. ?>
    <div class="letter__space"></div>

    <p class="letter__signatory">
      <strong><?= e($letter['signatory_name']) ?></strong>
      <?php if ($letter['signatory_title']): ?>
        <br><?= e($letter['signatory_title']) ?>
      <?php endif; ?>
      <br><?= e($company['name']) ?>
    </p>
  </div>

  <?php if ($vision !== ''): ?>
    <footer class="letter__foot">
      <?= e($vision) ?>
    </footer>
  <?php endif; ?>

</div>
