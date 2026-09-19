<?php
/**
 * What we sell units for: the plans a direct client or a partner picks
 * from. Partners set their own plans for their own clients, in their
 * portal.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;

$section   = 'plans';
$canManage = Auth::can('bulksms.manage');
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>SMS price plans</h1>
    <div class="page-head__sub">
      Bundles of units at a fixed price. A custom amount is charged at
      KES <?= e(number_format($unitPrice, 2)) ?> a unit<?php if (Auth::can('bulksms.settings')): ?>
      (<a href="<?= url('/bulk-sms/settings') ?>">change</a>)<?php endif; ?>.
    </div>
  </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="grid-sidebar">
  <div class="card">
    <?php if (!$plans): ?>
      <div class="empty">
        <div class="empty__icon"><?= icon('layers') ?></div>
        <div class="empty__title">No plans</div>
        <div class="empty__text">Without plans, customers can still buy any number of units at the standard price.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Plan</th>
              <th class="num" style="width:110px">Units</th>
              <th class="num" style="width:120px">Price</th>
              <th class="num" style="width:110px">Per unit</th>
              <th class="num" style="width:80px">Sold</th>
              <th style="width:100px">State</th>
              <?php if ($canManage): ?><th style="width:170px"></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plans as $p): ?>
              <tr>
                <td class="fw-600">
                  <?= e($p['name']) ?>
                  <?php if ($p['is_popular']): ?><span class="badge badge--green">Most popular</span><?php endif; ?>
                </td>
                <td class="num"><?= e(Present::units($p['units'])) ?></td>
                <td class="num"><?= e(money($p['price'])) ?></td>
                <td class="num text-muted"><?= e(number_format((float) $p['price'] / max(1, (int) $p['units']), 2)) ?></td>
                <td class="num text-muted"><?= (int) $p['sold'] ?></td>
                <td><span class="badge <?= $p['is_active'] ? 'badge--green' : 'badge--grey' ?>"><?= $p['is_active'] ? 'On sale' : 'Off' ?></span></td>
                <?php if ($canManage): ?>
                  <td>
                    <div class="btn-group">
                      <button class="btn btn--ghost btn--sm" type="button" data-modal-open="plan-<?= (int) $p['id'] ?>"><?= icon('edit') ?></button>
                      <form method="post" action="<?= url('/bulk-sms/plans/' . (int) $p['id'] . '/toggle') ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--sm" type="submit"><?= $p['is_active'] ? 'Take off sale' : 'Put on sale' ?></button>
                      </form>
                      <form method="post" action="<?= url('/bulk-sms/plans/' . (int) $p['id'] . '/delete') ?>" data-confirm="Delete this plan?">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--sm" type="submit" title="Delete"><?= icon('trash') ?></button>
                      </form>
                    </div>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($canManage): ?>
    <div class="card">
      <div class="card__head"><div class="card__title">Add a plan</div></div>
      <div class="card__body">
        <?php $plan = null; include __DIR__ . '/_plan_form.php'; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
  <?php foreach ($plans as $plan): ?>
    <div class="modal-backdrop" id="plan-<?= (int) $plan['id'] ?>">
      <div class="modal modal--sm">
        <div class="modal__head">
          <div class="card__title">Edit <?= e($plan['name']) ?></div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <?php include __DIR__ . '/_plan_form.php'; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
