<?php
require_once APP_PATH . '/Views/partials/icons.php';

$s      = $statement;
$owing  = (float) $s['closing'];
$ageing = $s['ageing'];

$buckets = [
    'current' => 'Not yet due',
    '1_30'    => '1–30 days',
    '31_60'   => '31–60 days',
    '61_90'   => '61–90 days',
    '90_plus' => 'Over 90 days',
];
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your statement</h1>
    <p class="portal-lede">
      Everything invoiced and everything paid, in the order it happened.
      <?php // fdate() answers an em dash for an empty date, and an em dash
            // is truthy, so the old fallback never fired and the page read
            // "— to 07 Sep". Test the date, not what fdate made of it. ?>
      <?php if ($s['from'] || $s['to']): ?>
        <?= $s['from'] ? e(fdate($s['from'])) : 'From the beginning' ?>
        to <?= $s['to'] ? e(fdate($s['to'])) : 'today' ?>.
      <?php endif; ?>
    </p>
  </div>

  <?php // The controller has always accepted a range; there was no way to
        // ask for one. "What did we spend with them last quarter" is a
        // normal thing for a client to want. ?>
  <form class="portal-range" method="get" action="<?= url('/portal/statement') ?>">
    <div class="field mb-0">
      <label class="label" for="from">From</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($askedFrom) ?>">
    </div>
    <div class="field mb-0">
      <label class="label" for="to">To</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($askedTo) ?>">
    </div>
    <button class="btn btn--outline" type="submit">Show</button>
    <?php if ($askedFrom !== '' || $askedTo !== ''): ?>
      <a class="btn btn--ghost" href="<?= url('/portal/statement') ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="portal-grid">
    <div class="portal-tile">
      <div class="portal-tile__figure"><?= e(money($s['invoiced'], false)) ?></div>
      <div class="portal-tile__label">Invoiced</div>
    </div>
    <div class="portal-tile">
      <div class="portal-tile__figure"><?= e(money($s['paid'], false)) ?></div>
      <div class="portal-tile__label">Paid</div>
    </div>
    <div class="portal-tile <?= $owing > 0.009 ? 'portal-tile--owing' : '' ?>">
      <div class="portal-tile__figure"><?= e(money($owing, false)) ?></div>
      <div class="portal-tile__label">Balance</div>
    </div>
  </div>

  <?php if ($owing > 0.009 && $s['ageing_total'] > 0.009): ?>
    <div class="portal-card mt-16">
      <div class="fw-600 mb-8">How the balance is aged</div>
      <div class="portal-ageing">
        <?php foreach ($buckets as $key => $label): ?>
          <?php $amount = (float) ($ageing[$key] ?? 0); ?>
          <?php if ($amount <= 0.009) { continue; } ?>
          <div class="portal-ageing__cell">
            <div class="portal-ageing__figure <?= $key === '90_plus' ? 'text-red' : '' ?>">
              <?= e(money($amount, false)) ?>
            </div>
            <div class="portal-ageing__label"><?= e($label) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-card portal-card--flush mt-16">
    <?php if (!$s['rows']): ?>
      <div class="portal-empty">
        <span class="portal-empty__icon"><?= icon('file-text') ?></span>
        <div class="portal-empty__title">Nothing on your account yet</div>
        <p class="text-sm text-muted mb-0">Invoices and payments will appear here.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:104px">Date</th>
              <th>Detail</th>
              <th style="width:118px" class="num">Charged</th>
              <th style="width:118px" class="num">Paid</th>
              <th style="width:126px" class="num">Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php if (abs((float) $s['opening']) > 0.009): ?>
              <tr>
                <td class="text-xs text-muted">—</td>
                <td class="text-muted">Brought forward</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num fw-600"><?= e(money($s['opening'], false)) ?></td>
              </tr>
            <?php endif; ?>

            <?php foreach ($s['rows'] as $row): ?>
              <tr>
                <td class="text-xs text-muted"><?= e(fdate($row['date'])) ?></td>
                <td>
                  <?php if ($row['type'] === 'invoice' && !empty($row['link_id'])): ?>
                    <a class="table__primary" href="<?= url('/portal/invoices/' . (int) $row['link_id']) ?>">
                      <?= e($row['ref']) ?>
                    </a>
                  <?php else: ?>
                    <span class="fw-600"><?= e($row['ref']) ?></span>
                  <?php endif; ?>
                  <div class="table__muted"><?= e(str_excerpt($row['description'], 48)) ?></div>
                </td>
                <td class="num"><?= (float) $row['debit'] > 0.009 ? e(money($row['debit'], false)) : '—' ?></td>
                <td class="num"><?= (float) $row['credit'] > 0.009 ? e(money($row['credit'], false)) : '—' ?></td>
                <td class="num fw-600"><?= e(money($row['balance'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($owing > 0.009): ?>
    <p class="text-sm text-muted mt-16">
      Anything here that does not look right? Reply to any of our emails or call
      us on <?= e($company['phone']) ?> and we will go through it with you.
    </p>
  <?php endif; ?>
</div>
