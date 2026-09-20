<?php
/**
 * What a partner charges their own clients: a standard price per unit,
 * and bundles of their own.
 *
 * What they pay us is shown beside it, because a margin nobody can see
 * is a margin nobody manages.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

$smsTab = 'pricing';
$resale = $account['resale_unit_price'] !== null ? (float) $account['resale_unit_price'] : null;
$margin = $resale !== null ? $resale - $cost : null;
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Your standard price</div></div>

    <div class="portal-stats">
      <div class="portal-stat">
        <div class="portal-stat__figure">KES <?= e(number_format($cost, 2)) ?></div>
        <div class="portal-stat__label">You pay us</div>
      </div>
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= $resale === null ? '—' : 'KES ' . e(number_format($resale, 2)) ?></div>
        <div class="portal-stat__label">Your clients pay</div>
      </div>
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= $margin === null ? '—' : 'KES ' . e(number_format($margin, 2)) ?></div>
        <div class="portal-stat__label">You keep, per unit</div>
        <?php if ($margin !== null && $margin <= 0): ?>
          <div class="portal-stat__note">You are selling at or below what it costs you</div>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" action="<?= url($base . '/pricing') ?>" class="row-form">
      <?= csrf_field() ?>
      <div class="field mb-0" style="max-width:220px">
        <label class="label" for="resale">Price per unit (KES)</label>
        <input class="input" id="resale" name="resale_unit_price" type="number" step="0.01" min="0"
               value="<?= e($account['resale_unit_price'] ?? '') ?>" placeholder="e.g. 1.20">
      </div>
      <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save</button>
    </form>
    <span class="field-hint">
      Applies to any client without a price of their own. Set one for an
      individual client on <a href="<?= url($base . '/clients') ?>">your clients</a>.
    </span>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Your bundles</div></div>

    <?php if (!$plans): ?>
      <p class="text-sm text-muted">
        No bundles. Without them, your clients buy any number of units at
        your standard price — which is perfectly fine.
      </p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th>Bundle</th>
              <th class="num" style="width:100px">Units</th>
              <th class="num" style="width:110px">Price</th>
              <th class="num" style="width:110px">Per unit</th>
              <th class="num" style="width:110px">You keep</th>
              <th style="width:90px">State</th>
              <th style="width:150px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plans as $p): ?>
              <?php $each = (float) $p['price'] / max(1, (int) $p['units']);
                    $keep = ($each - $cost) * (int) $p['units']; ?>
              <tr>
                <td class="fw-600"><?= e($p['name']) ?><?= $p['is_popular'] ? ' <span class="badge badge--green">Popular</span>' : '' ?></td>
                <td class="num"><?= e(Present::units($p['units'])) ?></td>
                <td class="num"><?= e(money($p['price'])) ?></td>
                <td class="num text-muted"><?= e(number_format($each, 2)) ?></td>
                <td class="num <?= $keep <= 0 ? 'text-danger' : '' ?>"><?= e(money($keep)) ?></td>
                <td><span class="badge <?= $p['is_active'] ? 'badge--green' : 'badge--grey' ?>"><?= $p['is_active'] ? 'On sale' : 'Off' ?></span></td>
                <td>
                  <div class="btn-group">
                    <button class="btn btn--ghost btn--sm" type="button" data-modal-open="rp-<?= (int) $p['id'] ?>"><?= icon('edit') ?></button>
                    <form method="post" action="<?= url($base . '/pricing/plans/' . (int) $p['id'] . '/toggle') ?>">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit"><?= $p['is_active'] ? 'Off' : 'On' ?></button>
                    </form>
                    <form method="post" action="<?= url($base . '/pricing/plans/' . (int) $p['id'] . '/delete') ?>"
                          data-confirm="Delete this bundle?">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('trash') ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="mt-8">
      <?php $plan = null; $planBase = $base . '/pricing/plans'; include APP_PATH . '/Views/bulksms/admin/_plan_form.php'; ?>
    </div>
  </div>

  <?php if ($housePlans): ?>
    <div class="portal-card portal-card--quiet">
      <div class="portal-card__head"><div class="portal-card__title">What we sell you</div></div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Bundle</th><th class="num">Units</th><th class="num">Price</th><th class="num">Per unit</th></tr></thead>
          <tbody>
            <?php foreach ($housePlans as $h): ?>
              <tr>
                <td><?= e($h['name']) ?></td>
                <td class="num"><?= e(Present::units($h['units'])) ?></td>
                <td class="num"><?= e(money($h['price'])) ?></td>
                <td class="num text-muted"><?= e(number_format((float) $h['price'] / max(1, (int) $h['units']), 2)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-sm text-muted mt-8 mb-0">
        Buying a bigger bundle lowers what a unit costs you, which is where
        your margin comes from. <a href="<?= url($base . '/buy') ?>">Buy units</a>.
      </p>
    </div>
  <?php endif; ?>
</div>

<?php foreach ($plans as $plan): ?>
  <div class="modal-backdrop" id="rp-<?= (int) $plan['id'] ?>">
    <div class="modal modal--sm">
      <div class="modal__head">
        <div class="card__title">Edit <?= e($plan['name']) ?></div>
        <button class="modal__close" type="button" data-modal-close>&times;</button>
      </div>
      <div class="modal__body">
        <?php $planBase = $base . '/pricing/plans'; include APP_PATH . '/Views/bulksms/admin/_plan_form.php'; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
