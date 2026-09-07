<?php
/**
 * What we do, as a catalogue a partner can sell from.
 *
 * Cards with pictures rather than a list of names, because this is the
 * page somebody opens in front of a customer. Services and products
 * together, since a partner sells both and does not think of them as two
 * different systems.
 *
 * Every card carries what the partner earns on it, which is the whole
 * reason they are looking rather than reading the client-facing one.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$rateOf = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';

$priceLabel = static function (array $s): string {
    if ((float) $s['price'] <= 0.009) {
        return 'Price on request';
    }

    $amount = money($s['price'], false);

    return match ($s['pricing_type'] ?? 'fixed') {
        'hourly'  => $amount . ' / hour',
        'daily'   => $amount . ' / day',
        'monthly' => $amount . ' / month',
        'from'    => 'from ' . $amount,
        'project' => $amount . ' / project',
        default   => $amount,
    };
};

/** One card, whether it is something we do or something we sell. */
$card = static function (array $it) use ($rateOf, $priceLabel): void {
    $img = $it['image'] ?? null;
    ?>
    <article class="cat-card cat-card--<?= e($it['kind']) ?> <?= $img ? '' : 'cat-card--nopic' ?>">
      <div class="cat-card__figure">
        <?php if ($img): ?>
          <img src="<?= url('/catalogue/image/' . e($it['kind']) . '/' . (int) $img['id']) ?>?size=thumb"
               alt="<?= e($img['alt_text'] ?: $it['name']) ?>" loading="lazy">
        <?php else: ?>
          <?php // No picture is a normal state, not a broken one — a great
                // many services never have one. A mark beats a grey box
                // with a torn-image icon in it. ?>
          <span class="cat-card__mark">
            <?= icon($it['kind'] === 'service' ? 'layers' : 'package') ?>
          </span>
        <?php endif; ?>

        <span class="cat-card__kind">
          <?= $it['kind'] === 'service' ? 'Service' : 'Product' ?>
        </span>
      </div>

      <div class="cat-card__body">
        <h3 class="cat-card__name"><?= e($it['name']) ?></h3>

        <?php if (!empty($it['description'])): ?>
          <p class="cat-card__desc"><?= e(str_excerpt((string) $it['description'], 96)) ?></p>
        <?php endif; ?>

        <?php if (!empty($it['lead_time'])): ?>
          <p class="cat-card__lead"><?= icon('clock') ?> <?= e($it['lead_time']) ?></p>
        <?php endif; ?>
      </div>

      <footer class="cat-card__foot">
        <span class="cat-card__price"><?= e($priceLabel($it)) ?></span>

        <span class="cat-card__cut">
          <?php if ($it['your_cut'] !== null): ?>
            you earn <strong><?= e(money($it['your_cut'], false)) ?></strong>
            <span class="text-muted">(<?= e($rateOf((float) $it['effective_rate'])) ?>)</span>
          <?php else: ?>
            <?= e($rateOf((float) $it['effective_rate'])) ?> of what we quote
          <?php endif; ?>
        </span>
      </footer>
    </article>
    <?php
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">What we do</h1>
    <p class="portal-lede">
      Everything we sell, and what each one earns you. Your rate is
      <strong><?= e($rateOf((float) $me['default_rate'])) ?></strong> unless
      a service carries its own.
    </p>
  </div>

  <form method="get" action="<?= url('/partners/services') ?>" class="mb-16">
    <input class="input" type="search" name="q" value="<?= e($search) ?>"
           placeholder="Search what we do…" aria-label="Search the catalogue"
           data-debounce-submit>
  </form>

  <?php if (!$services && !$products): ?>
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

    <?php if ($services): ?>
      <h2 class="cat-heading">Services <span><?= count($services) ?></span></h2>
      <div class="cat-grid">
        <?php foreach ($services as $s): ?><?php $card($s); ?><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($products): ?>
      <h2 class="cat-heading">Products <span><?= count($products) ?></span></h2>
      <div class="cat-grid">
        <?php foreach ($products as $p): ?><?php $card($p); ?><?php endforeach; ?>
      </div>

      <?php // Products carry no rate of their own, so they always pay the
            // partner's. Said once here rather than repeated on every card. ?>
      <p class="text-xs text-muted mt-8">
        Products pay your own rate of <?= e($rateOf((float) $me['default_rate'])) ?>.
        Only services can carry a rate of their own.
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <p class="portal-help">
    Got somebody who needs one of these?
    <a href="<?= url('/partners/refer') ?>">Introduce them</a>.
  </p>
</div>
