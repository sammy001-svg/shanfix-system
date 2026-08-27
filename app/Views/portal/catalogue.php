<?php
require_once APP_PATH . '/Views/partials/icons.php';

// "from 5,000" reads differently from "5,000", and the difference matters
// to somebody deciding whether to ask.
$priceLabel = static function (array $s): string {
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
      Tick anything you are interested in and ask us about it — a quotation,
      a check that the price is current, or what we can do on the price for
      the lot.
    </p>
  </div>

  <form method="get" action="<?= url('/portal/catalogue') ?>" class="mb-16">
    <input class="input" type="search" name="q" value="<?= e($search) ?>"
           placeholder="Search what we do…" aria-label="Search the catalogue"
           data-debounce-submit>
  </form>

  <?php if (!$services && !$inventory): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('package') ?></div>
      <div class="card__title mt-8">
        <?= $search !== '' ? 'Nothing matching that' : 'Nothing to show yet' ?>
      </div>
      <p class="text-sm text-muted mb-0">
        <?= $search !== '' ? 'Try a different word, or clear the search.' : 'Our catalogue is being set up.' ?>
      </p>
    </div>
  <?php else: ?>

    <form method="post" action="<?= url('/portal/catalogue/ask') ?>">
      <?= csrf_field() ?>

      <?php if ($services): ?>
        <div class="portal-cat__heading">Services</div>
        <div class="portal-cat">
          <?php foreach ($services as $s): ?>
            <label class="portal-cat__item">
              <input type="checkbox" name="items[]" value="service:<?= (int) $s['id'] ?>">
              <span class="portal-cat__body">
                <span class="portal-cat__name"><?= e($s['name']) ?></span>
                <?php if ($s['description']): ?>
                  <span class="portal-cat__desc"><?= e(str_excerpt($s['description'], 120)) ?></span>
                <?php endif; ?>
                <?php if ($s['lead_time']): ?>
                  <span class="portal-cat__meta"><?= icon('clock') ?> <?= e($s['lead_time']) ?></span>
                <?php endif; ?>
              </span>
              <?php if ($showPrices): ?>
                <span class="portal-cat__price"><?= e($priceLabel($s)) ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($inventory): ?>
        <div class="portal-cat__heading mt-16">Products</div>
        <div class="portal-cat">
          <?php foreach ($inventory as $it): ?>
            <label class="portal-cat__item">
              <input type="checkbox" name="items[]" value="inventory:<?= (int) $it['id'] ?>">
              <span class="portal-cat__body">
                <span class="portal-cat__name"><?= e($it['name']) ?></span>
                <?php if ($it['description']): ?>
                  <span class="portal-cat__desc"><?= e(str_excerpt($it['description'], 120)) ?></span>
                <?php endif; ?>
              </span>
              <?php if ($showPrices): ?>
                <span class="portal-cat__price">
                  <?= e(money($it['selling_price'], false)) ?>
                  <?php if ($it['unit']): ?><span class="portal-cat__unit">/ <?= e($it['unit']) ?></span><?php endif; ?>
                </span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php // Sticks to the bottom of the screen, because the things being
            // ticked are usually above the fold and the ask is below it. ?>
      <div class="portal-ask">
        <div class="field mb-0">
          <label class="label" for="kind">What would you like?</label>
          <select class="select" id="kind" name="kind">
            <option value="quotation">A quotation for these</option>
            <option value="review">Are these prices still current?</option>
            <option value="discount">What can you do on the price?</option>
          </select>
        </div>

        <div class="field mb-0 flex-1">
          <label class="label" for="note">Anything to add <span class="text-muted">(optional)</span></label>
          <input class="input" type="text" id="note" name="note" maxlength="500"
                 placeholder="Quantities, sizes, when you need it…">
        </div>

        <button class="btn btn--primary btn--lg" type="submit">Ask us</button>
      </div>
    </form>
  <?php endif; ?>
</div>
