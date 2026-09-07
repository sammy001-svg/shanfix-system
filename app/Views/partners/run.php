<?php
/**
 * The monthly commission run.
 *
 * Commission is paid monthly, so this is the screen the payment is
 * actually made from: one month, everybody who is owed something, and the
 * details needed to pay them. Grouped by the month it was EARNED in — the
 * month the customer paid us — which is the month both sides reconcile
 * against.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$monthName = static function (string $p): string {
    $t = strtotime($p . '-01');

    return $t ? date('F Y', $t) : $p;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Commission run</h1>
    <div class="page-head__sub">
      What each partner is owed for <?= e($monthName($period)) ?>
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= url('/partners-admin') ?>">
      <?= icon('users') ?> All partners
    </a>
  </div>
</div>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= url('/partners-admin/runs') ?>" class="row-form">
      <div class="field mb-0" style="max-width:240px">
        <label class="label" for="period">Month</label>
        <select class="select" id="period" name="period" onchange="this.form.submit()">
          <?php if (!$periods): ?>
            <option value="<?= e($period) ?>"><?= e($monthName($period)) ?></option>
          <?php endif; ?>
          <?php foreach ($periods as $p): ?>
            <option value="<?= e($p) ?>" <?= $p === $period ? 'selected' : '' ?>>
              <?= e($monthName($p)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <noscript><button class="btn btn--outline" type="submit">Show</button></noscript>
    </form>
  </div>
</div>

<div class="stat-grid mb-16">
  <div class="stat <?= $due > 0.009 ? 'stat--amber' : '' ?>">
    <div class="stat__value"><?= e(money($due, false)) ?></div>
    <div class="stat__label">Still to pay</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__value"><?= e(money($paid, false)) ?></div>
    <div class="stat__label">Already paid</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__value"><?= count($rows) ?></div>
    <div class="stat__label">Partners in this month</div>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="card">
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('receipt') ?></div>
      <div class="card__title mt-8">Nothing earned in <?= e($monthName($period)) ?></div>
      <p class="text-sm text-muted mb-0">
        Commission lands in the month the customer pays us, not the month we
        invoice them.
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Partner</th>
            <th style="width:120px">Code</th>
            <th style="width:150px">KRA PIN</th>
            <th style="width:140px">Pay to</th>
            <th style="width:130px" class="num">Owed</th>
            <th style="width:130px" class="num">Paid</th>
            <?php if (Auth::can('partners.pay')): ?>
              <th style="width:250px">Settle this month</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/partners-admin/' . (int) $p['id']) ?>">
                  <?= e($p['company'] ?: $p['name']) ?>
                </a>
                <div class="table__muted"><?= e($p['name']) ?></div>
              </td>
              <td class="text-xs"><?= e($p['partner_code'] ?: '—') ?></td>
              <td class="text-xs">
                <?php if ($p['kra_pin']): ?>
                  <?= e($p['kra_pin']) ?>
                <?php else: ?>
                  <?php // Without one we cannot account for the payment
                        // properly, so it is flagged here rather than found
                        // out at the moment of paying. ?>
                  <span class="badge badge--amber">Not on file</span>
                <?php endif; ?>
              </td>
              <td class="text-xs"><?= e($p['phone']) ?></td>
              <td class="num <?= (float) $p['due'] > 0.009 ? 'fw-700' : 'text-muted' ?>">
                <?= e(money($p['due'], false)) ?>
              </td>
              <td class="num text-muted"><?= e(money($p['paid'], false)) ?></td>

              <?php if (Auth::can('partners.pay')): ?>
                <td>
                  <?php if ((float) $p['due'] > 0.009): ?>
                    <form method="post"
                          action="<?= url('/partners-admin/' . (int) $p['id'] . '/payout') ?>"
                          class="row-form row-form--tight">
                      <?= csrf_field() ?>
                      <input type="hidden" name="period" value="<?= e($period) ?>">
                      <input class="input input--sm" type="text" name="payout_ref" required
                             maxlength="80" placeholder="Payment reference"
                             aria-label="Payment reference for <?= e($p['name']) ?>">
                      <button class="btn btn--primary btn--sm" type="submit">Paid</button>
                    </form>
                  <?php else: ?>
                    <span class="text-xs text-muted">Settled</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
