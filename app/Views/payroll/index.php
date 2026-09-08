<?php
/**
 * Payroll, month by month.
 *
 * The warning at the top is the most important thing on the page until
 * somebody clears it: nothing here knows a tax rate of its own, and a
 * wrong one is invisible on screen and very visible on a payslip.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$monthName = static function (string $p): string {
    $t = strtotime($p . '-01');

    return $t ? date('F Y', $t) : $p;
};

$runBadge = static fn(string $s): array => match ($s) {
    'draft'    => ['badge--grey',  'Draft'],
    'approved' => ['badge--blue',  'Approved'],
    'paid'     => ['badge--green', 'Paid'],
    'closed'   => ['badge--green', 'Closed'],
    default    => ['badge--grey',  ucfirst($s)],
};

$payableTotal = 0.0;
foreach ($payable as $p) { $payableTotal += (float) $p['basic_salary']; }
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Payroll</h1>
    <div class="page-head__sub">Gross to net, month by month</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= url('/payroll/rates') ?>">
      <?= icon('sliders') ?> Tax rates
    </a>
    <a class="btn btn--ghost" href="<?= url('/staff') ?>">
      <?= icon('users') ?> Staff
    </a>
  </div>
</div>

<?php // Said on every payroll page until an actual person has checked the
      // figures against KRA. Approving a run is refused until they have. ?>
<?php if (!$rates['confirmed']): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>The tax rates have not been checked by anybody yet.</strong>
      Every band, rate and ceiling was seeded with the figures believed
      current when this was built, and they change — SHIF replaced NHIF,
      the housing levy arrived, and what comes off before PAYE changed with
      it. Go through
      <a href="<?= url('/payroll/rates') ?>">the rates page</a> with your
      accountant or against KRA, and confirm them there. A payroll cannot
      be approved until you have.
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= url('/payroll') ?>" class="row-form">
      <div class="field mb-0" style="max-width:220px">
        <label class="label" for="period">Month</label>
        <input class="input" type="month" id="period" name="period" value="<?= e($period) ?>">
      </div>
      <button class="btn btn--outline" type="submit">Show</button>
    </form>
  </div>
</div>

<div class="stat-grid mb-16">
  <div class="stat">
    <div class="stat__value"><?= count($payable) ?></div>
    <div class="stat__label">To be paid for <?= e($monthName($period)) ?></div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= e(money($payableTotal, false)) ?></div>
    <div class="stat__label">Basic between them</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= $existing ? e(label_of((string) $existing['status'])) : 'Not run' ?></div>
    <div class="stat__label">This month</div>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div class="card__title"><?= e($monthName($period)) ?></div>
    <?php if (Auth::can('payroll.run') && !$existing && $payable): ?>
      <form method="post" action="<?= url('/payroll') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <button class="btn btn--primary btn--sm" type="submit">
          <?= icon('layers') ?> Work it out
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($existing): ?>
    <div class="card__body">
      <p class="text-sm mb-0">
        <?php [$cls, $label] = $runBadge((string) $existing['status']); ?>
        <span class="badge <?= e($cls) ?>"><?= e($label) ?></span>
        <?= e($monthName($period)) ?> has already been worked out —
        <a href="<?= url('/payroll/' . (int) $existing['id']) ?>"><strong>open it</strong></a>.
      </p>
    </div>
  <?php elseif (!$payable): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('users') ?></div>
      <div class="card__title mt-8">Nobody was employed that month</div>
      <p class="text-sm text-muted mb-0">
        Anyone who started after it, or left before it, is left out.
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th style="width:180px">Job</th>
            <th style="width:140px">Paid by</th>
            <th style="width:140px" class="num">Basic</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payable as $p): ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/staff/' . (int) $p['id']) ?>"><?= e($p['name']) ?></a>
                <div class="text-xs text-muted"><?= e($p['employee_number']) ?></div>
              </td>
              <td class="text-sm text-muted"><?= e($p['job_title'] ?: '—') ?></td>
              <td class="text-sm">
                <?php if ($p['pay_method']): ?>
                  <?= e(label_of((string) $p['pay_method'])) ?>
                <?php else: ?>
                  <span class="badge badge--amber">Not set</span>
                <?php endif; ?>
              </td>
              <td class="num fw-600"><?= e(money($p['basic_salary'], false)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head">
    <div class="card__title">Runs</div>
    <span class="text-xs text-muted"><?= count($runs) ?></span>
  </div>

  <?php if (!$runs): ?>
    <div class="card__body text-sm text-muted">Nothing has been run yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:150px">Month</th>
            <th style="width:130px">State</th>
            <th style="width:80px" class="num">People</th>
            <th style="width:140px" class="num">Gross</th>
            <th style="width:140px" class="num">PAYE</th>
            <th style="width:140px" class="num">Net</th>
            <th>Worked out</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($runs as $r): ?>
            <?php [$cls, $label] = $runBadge((string) $r['status']); ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/payroll/' . (int) $r['id']) ?>">
                  <?= e($monthName((string) $r['period'])) ?>
                </a>
              </td>
              <td><span class="badge <?= e($cls) ?>"><?= e($label) ?></span></td>
              <td class="num text-muted"><?= (int) $r['employee_count'] ?></td>
              <td class="num"><?= e(money($r['total_gross'], false)) ?></td>
              <td class="num text-muted"><?= e(money($r['total_paye'], false)) ?></td>
              <td class="num fw-700"><?= e(money($r['total_net'], false)) ?></td>
              <td class="text-sm text-muted">
                <?= e(fdate($r['created_at'])) ?>
                <?php if ($r['created_name']): ?>&middot; <?= e($r['created_name']) ?><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
