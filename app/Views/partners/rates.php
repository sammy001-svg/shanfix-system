<?php
/**
 * What one partner earns on each thing we sell.
 *
 * Partners are not all on the same terms, so a rate belongs to a pair —
 * this partner and this thing — rather than to the thing alone. The page
 * shows where each rate is coming from as well as what it is, because
 * "10%" means something different when it is their default from when
 * somebody sat down and agreed it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$rateOf  = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
$canEdit = Auth::can('partners.manage');

/** One row of the table, service or product alike. */
$row = static function (array $it) use ($rateOf, $canEdit): void {
    $key = $it['item_type'] . ':' . (int) $it['id'];
    ?>
    <tr>
      <td>
        <span class="fw-600"><?= e($it['name']) ?></span>
      </td>

      <td class="num text-muted">
        <?= (float) $it['price'] > 0.009 ? e(money($it['price'], false)) : '—' ?>
      </td>

      <td class="num">
        <?php if ($it['commission_rate'] !== null): ?>
          <?= e($rateOf((float) $it['commission_rate'])) ?>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>

      <td>
        <?php if ($canEdit): ?>
          <div class="input-group input-group--sm">
            <input class="input" type="number" step="0.5" min="0" max="100"
                   name="rate[<?= e($key) ?>]"
                   value="<?= $it['override'] === null ? '' : e(rtrim(rtrim(number_format((float) $it['override'], 2), '0'), '.')) ?>"
                   placeholder="—"
                   aria-label="Commission for <?= e($it['name']) ?>">
            <span class="input-group__addon">%</span>
          </div>
        <?php else: ?>
          <?= $it['override'] === null ? '<span class="text-muted">—</span>' : e($rateOf((float) $it['override'])) ?>
        <?php endif; ?>
      </td>

      <td class="num fw-700"><?= e($rateOf((float) $it['effective'])) ?></td>

      <td class="text-xs text-muted">
        <?= e($it['source']) ?>
      </td>
    </tr>
    <?php
};
?>

<p class="text-sm mb-12">
  <a href="<?= url('/partners-admin/' . (int) $partner['id']) ?>">
    &larr; <?= e($partner['company'] ?: $partner['name']) ?>
  </a>
</p>

<div class="page-head">
  <div class="page-head__text">
    <h1>What they earn</h1>
    <div class="page-head__sub">
      <?= e($partner['company'] ?: $partner['name']) ?> &middot;
      default <?= e($rateOf((float) $partner['default_rate'])) ?>
      <?php if ($setCount > 0): ?>
        &middot; <?= (int) $setCount ?> rate<?= $setCount === 1 ? '' : 's' ?> agreed separately
      <?php endif; ?>
    </div>
  </div>
</div>

<?php // Where a rate comes from, said once, because the table's last
      // column is meaningless without it. ?>
<div class="card">
  <div class="card__body">
    <p class="text-sm mb-0">
      A rate is looked for in three places, and the first one found wins:
      <strong>this partner on this thing</strong>, then
      <strong>the service's own rate</strong>, then
      <strong>their default of <?= e($rateOf((float) $partner['default_rate'])) ?></strong>.
      Leave a box empty to fall through to the next one; enter <strong>0</strong>
      to say they earn nothing on it. Changing a rate applies to commission
      earned from now on — what is already earned keeps the rate it was
      earned at.
    </p>
  </div>
</div>

<form method="get" action="<?= url('/partners-admin/' . (int) $partner['id'] . '/rates') ?>">
  <div class="card">
    <div class="filters">
      <div class="field mb-0 flex-1">
        <label class="label" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
               placeholder="Find a service or product…">
      </div>
      <div class="field mb-0">
        <label class="check">
          <input type="checkbox" name="set" value="1" <?= $onlySet ? 'checked' : '' ?>>
          <span class="check__text"><strong>Only what is agreed separately</strong></span>
        </label>
      </div>
      <button class="btn btn--outline" type="submit">Show</button>
      <?php if ($search !== '' || $onlySet): ?>
        <a class="btn btn--ghost" href="<?= url('/partners-admin/' . (int) $partner['id'] . '/rates') ?>">Clear</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<form method="post" action="<?= url('/partners-admin/' . (int) $partner['id'] . '/rates') ?>">
  <?= csrf_field() ?>

  <?php if (!$services && !$products): ?>
    <div class="card">
      <div class="card__body text-center">
        <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('dollar') ?></div>
        <div class="card__title mt-8">
          <?= $onlySet ? 'Nothing agreed separately' : 'Nothing matching that' ?>
        </div>
        <p class="text-sm text-muted mb-0">
          <?= $onlySet
            ? 'This partner is on their default everywhere. Untick the filter to set one.'
            : 'Try a different word, or clear the search.' ?>
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ([['Services', $services], ['Products', $products]] as [$heading, $list]): ?>
    <?php if (!$list) { continue; } ?>
    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= e($heading) ?></div>
        <span class="text-xs text-muted"><?= count($list) ?></span>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= $heading === 'Services' ? 'Service' : 'Product' ?></th>
              <th style="width:130px" class="num">Price</th>
              <th style="width:110px" class="num">Service rate</th>
              <th style="width:150px">This partner</th>
              <th style="width:110px" class="num">They earn</th>
              <th style="width:130px">From</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $it): ?><?php $row($it); ?><?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($canEdit && ($services || $products)): ?>
    <div class="form-actions">
      <button class="btn btn--primary" type="submit">
        <?= icon('check') ?> Save these rates
      </button>
      <span class="text-xs text-muted">
        Only what is on this page is saved, so a search or a filter cannot
        wipe the rows it is hiding.
      </span>
    </div>
  <?php endif; ?>
</form>
