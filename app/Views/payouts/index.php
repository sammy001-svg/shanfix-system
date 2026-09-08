<?php
/**
 * Paying partners their commission.
 *
 * Two things on one page: what could go out for a given month, and every
 * run we have ever made. Building a run is deliberately a separate act
 * from paying — the run is what gets checked, approved and handed to the
 * bank, and only what actually lands is recorded as paid.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\Payouts;

$canPay = Auth::can('partners.pay');

$monthName = static function (string $p): string {
    $t = strtotime($p . '-01');

    return $t ? date('F Y', $t) : $p;
};

/** How a run's state reads at a glance. */
$runBadge = static function (string $status): array {
    return match ($status) {
        'draft'    => ['badge--grey',   'Draft'],
        'approved' => ['badge--blue',   'Approved'],
        'sent'     => ['badge--amber',  'With the bank'],
        'closed'   => ['badge--green',  'Closed'],
        default    => ['badge--grey',   ucfirst($status)],
    };
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Commission payouts</h1>
    <div class="page-head__sub">
      What partners are owed, and what we have actually paid them
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= url('/partners-admin/runs') ?>">
      <?= icon('bar-chart') ?> What is owed
    </a>
    <a class="btn btn--ghost" href="<?= url('/partners-admin') ?>">
      <?= icon('users') ?> All partners
    </a>
  </div>
</div>

<?php // ---- Pick a month, see what is waiting ---------------------------- ?>
<div class="card">
  <div class="card__body">
    <form method="get" action="<?= url('/payouts') ?>" class="row-form">
      <div class="field mb-0" style="max-width:240px">
        <label class="label" for="period">Month earned</label>
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
  <div class="stat <?= $owed > 0.009 ? 'stat--amber' : '' ?>">
    <div class="stat__value"><?= e(money($owed, false)) ?></div>
    <div class="stat__label">Waiting to be paid</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= count($waiting) ?></div>
    <div class="stat__label">Partner<?= count($waiting) === 1 ? '' : 's' ?></div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= e($monthName($period)) ?></div>
    <div class="stat__label">Earned in</div>
  </div>
</div>

<?php if ($openRun): ?>
  <?php [$cls, $label] = $runBadge((string) $openRun['status']); ?>
  <div class="card">
    <div class="card__body">
      <p class="text-sm mb-0">
        <span class="badge <?= e($cls) ?>"><?= e($label) ?></span>
        There is already a run for <?= e($monthName($period)) ?>.
        <a href="<?= url('/payouts/' . (int) $openRun['id']) ?>"><strong>Open it</strong></a>
        rather than starting another — a second run for the same month
        would find nothing to pay, because this one is holding it.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php // ---- What would go out ------------------------------------------- ?>
<div class="card">
  <div class="card__head">
    <div class="card__title">Waiting for <?= e($monthName($period)) ?></div>
    <?php if ($canPay && $waiting && !$openRun): ?>
      <form method="post" action="<?= url('/payouts') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <button class="btn btn--primary btn--sm" type="submit">
          <?= icon('layers') ?> Build the run
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$waiting): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('check-circle') ?></div>
      <div class="card__title mt-8">Nothing outstanding</div>
      <p class="text-sm text-muted mb-0">
        Everything earned in <?= e($monthName($period)) ?> has either been
        paid or is already in a run.
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Partner</th>
            <th style="width:150px">Goes to</th>
            <th style="width:90px" class="num">Entries</th>
            <th style="width:140px" class="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($waiting as $row): ?>
            <?php $where = Payouts::destinationFor($row); ?>
            <tr>
              <td>
                <a href="<?= url('/partners-admin/' . (int) $row['id']) ?>" class="fw-600">
                  <?= e($row['company'] ?: $row['name']) ?>
                </a>
                <?php if ($row['partner_code']): ?>
                  <div class="text-xs text-muted"><?= e($row['partner_code']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm">
                <?php if ($where['problem'] !== null): ?>
                  <span class="badge badge--red"><?= e($where['problem']) ?></span>
                <?php else: ?>
                  <span class="text-muted">
                    <?= $where['method'] === 'mpesa' ? 'M-Pesa' : 'Bank' ?>
                    &middot; <?= e($where['destination']) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="num text-muted"><?= (int) $row['entries'] ?></td>
              <td class="num fw-700"><?= e(money($row['amount'], false)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php // ---- Every run we have made -------------------------------------- ?>
<div class="card">
  <div class="card__head">
    <div class="card__title">Runs</div>
    <span class="text-xs text-muted"><?= count($runs) ?></span>
  </div>

  <?php if (!$runs): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('dollar') ?></div>
      <div class="card__title mt-8">No payouts yet</div>
      <p class="text-sm text-muted mb-0">
        Build one for a month above and it will appear here.
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:150px">Month</th>
            <th style="width:140px">State</th>
            <th style="width:90px" class="num">Lines</th>
            <th style="width:150px" class="num">Total</th>
            <th>Built</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($runs as $run): ?>
            <?php [$cls, $label] = $runBadge((string) $run['status']); ?>
            <tr>
              <td>
                <a href="<?= url('/payouts/' . (int) $run['id']) ?>" class="fw-600">
                  <?= e($monthName((string) $run['period'])) ?>
                </a>
              </td>
              <td><span class="badge <?= e($cls) ?>"><?= e($label) ?></span></td>
              <td class="num text-muted"><?= (int) $run['line_count'] ?></td>
              <td class="num fw-700"><?= e(money($run['total'], false)) ?></td>
              <td class="text-sm text-muted">
                <?= e(fdate($run['created_at'])) ?>
                <?php if ($run['created_name']): ?>
                  &middot; <?= e($run['created_name']) ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
