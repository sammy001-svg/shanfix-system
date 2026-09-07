<?php
/**
 * What we do, and what each of them pays.
 *
 * The catalogue a partner sells from, with their own cut against every
 * line — the whole reason they are looking at it. The rate shown is the
 * one the ledger will actually use, worked out in the controller by the
 * same rule.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$rateOf = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';

$priceLabel = static function (array $s): string {
    if ((float) $s['price'] <= 0.009) {
        return 'Price on request';
    }

    $amount = money($s['price'], false);

    return match ($s['pricing_type']) {
        'hourly'  => $amount . ' / hour',
        'daily'   => $amount . ' / day',
        'monthly' => $amount . ' / month',
        'from'    => 'from ' . $amount,
        'project' => $amount . ' / project',
        default   => $amount,
    };
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">What we do</h1>
    <p class="portal-lede">
      Everything we sell, and what each one earns you. Your rate is
      <strong><?= e($rateOf((float) $me['default_rate'])) ?></strong> unless
      the service carries its own.
    </p>
  </div>

  <form method="get" action="<?= url('/partners/services') ?>" class="mb-16">
    <input class="input" type="search" name="q" value="<?= e($search) ?>"
           placeholder="Search what we do…" aria-label="Search the catalogue"
           data-debounce-submit>
  </form>

  <?php if (!$rows): ?>
    <div class="portal-card portal-empty">
      <span class="portal-empty__icon"><?= icon('package') ?></span>
      <div class="portal-empty__title">
        <?= $search !== '' ? 'Nothing matching that' : 'Nothing to show yet' ?>
      </div>
      <p class="text-sm text-muted mb-0">
        <?= $search !== ''
          ? 'Try a different word, or clear the search.'
          : 'Our catalogue is being set up.' ?>
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $s): ?>
          <li>
            <span class="portal-list__row">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($s['name']) ?></span>
                <?php if ($s['description']): ?>
                  <span class="portal-list__meta"><?= e(str_excerpt($s['description'], 110)) ?></span>
                <?php endif; ?>
                <?php if ($s['lead_time']): ?>
                  <span class="portal-list__meta">
                    <?= icon('clock') ?> <?= e($s['lead_time']) ?>
                  </span>
                <?php endif; ?>
              </span>

              <span class="portal-list__side">
                <span class="portal-list__figures">
                  <span class="portal-list__amount"><?= e($priceLabel($s)) ?></span>
                  <span class="partner-cut">
                    <?php if ($s['your_cut'] !== null): ?>
                      you earn <strong><?= e(money($s['your_cut'], false)) ?></strong>
                      <span class="text-muted">(<?= e($rateOf((float) $s['effective_rate'])) ?>)</span>
                    <?php else: ?>
                      <?= e($rateOf((float) $s['effective_rate'])) ?> of what we quote
                    <?php endif; ?>
                  </span>
                </span>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <p class="portal-help">
    Got somebody who needs one of these?
    <a href="<?= url('/partners/refer') ?>">Introduce them</a>.
  </p>
</div>
