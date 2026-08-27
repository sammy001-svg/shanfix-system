<?php
require_once APP_PATH . '/Views/partials/icons.php';

$asked = [
    'quotation' => 'Asked for a quotation',
    'review'    => 'Asked whether the prices are current',
    'discount'  => 'Asked what could be done on the price',
];

$state = [
    'new'      => ['navy',  'With our team'],
    'seen'     => ['amber', 'Being looked at'],
    'answered' => ['green', 'Answered'],
    'declined' => ['grey',  'Closed'],
];
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">What you have asked us</h1>
    <p class="portal-lede">Price questions you have sent, and what came back.</p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('message') ?></div>
      <div class="card__title mt-8">Nothing asked yet</div>
      <p class="text-sm text-muted">
        Tick things in the catalogue and ask us about the price.
      </p>
      <a class="btn btn--outline" href="<?= url('/portal/catalogue') ?>">Browse what we do</a>
    </div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php [$tone, $label] = $state[$r['status']] ?? ['grey', $r['status']]; ?>
      <div class="portal-card">
        <div class="portal-doc__head">
          <div>
            <div class="fw-600"><?= e($asked[$r['kind']] ?? $r['kind']) ?></div>
            <div class="text-xs text-muted">
              <?= e($r['reference']) ?> · <?= e(fdate($r['created_at'])) ?>
            </div>
          </div>
          <span class="badge badge--<?= e($tone) ?>"><?= e($label) ?></span>
        </div>

        <ul class="plain-list mt-12">
          <?php foreach ($r['items'] as $it): ?>
            <li>
              <?= icon('check') ?>
              <span class="flex-1"><?= e($it['name_snapshot']) ?></span>
              <?php if ((float) $it['price_snapshot'] > 0): ?>
                <span class="text-xs text-muted"><?= e(money($it['price_snapshot'], false)) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($r['note']): ?>
          <p class="text-sm text-muted mt-8">You added: <?= e($r['note']) ?></p>
        <?php endif; ?>

        <?php if ($r['reply_note']): ?>
          <div class="portal-reply">
            <div class="fw-600 mb-4">Our reply</div>
            <p class="text-sm mb-0" style="white-space:pre-line"><?= e($r['reply_note']) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($r['quotation_id'])): ?>
          <a class="btn btn--outline btn--sm mt-12"
             href="<?= url('/portal/quotations/' . (int) $r['quotation_id']) ?>">
            <?= icon('file-text') ?> See quotation <?= e($r['doc_number']) ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
