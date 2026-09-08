<?php
/**
 * One month's payroll.
 *
 * The order of the buttons is the order of the work: check it, have
 * somebody else approve it, download the file, pay it, say it has gone.
 * Working it out and approving it are different authorities on purpose.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$status    = (string) $run['status'];
$monthName = static function (string $p): string {
    $t = strtotime($p . '-01');

    return $t ? date('F Y', $t) : $p;
};

$badge = match ($status) {
    'draft'    => ['badge--grey',  'Draft'],
    'approved' => ['badge--blue',  'Approved'],
    'paid'     => ['badge--green', 'Paid'],
    'closed'   => ['badge--green', 'Closed'],
    default    => ['badge--grey',  ucfirst($status)],
};

$byMethod = ['mpesa' => 0, 'bank' => 0, 'cash' => 0, '' => 0];

foreach ($payslips as $s) {
    if ($s['status'] !== 'pending') { continue; }
    $byMethod[(string) $s['pay_method']] = ($byMethod[(string) $s['pay_method']] ?? 0) + 1;
}
?>

<p class="text-sm mb-12"><a href="<?= url('/payroll') ?>">&larr; Payroll</a></p>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($monthName((string) $run['period'])) ?></h1>
    <div class="page-head__sub">
      <span class="badge <?= e($badge[0]) ?>"><?= e($badge[1]) ?></span>
      <?php if ($run['created_name']): ?>
        &middot; worked out by <?= e($run['created_name']) ?> on <?= e(fdate($run['created_at'])) ?>
      <?php endif; ?>
      <?php if ($run['approved_name']): ?>
        &middot; approved by <?= e($run['approved_name']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="stat-grid mb-16">
  <div class="stat">
    <div class="stat__value"><?= e(money($run['total_gross'], false)) ?></div>
    <div class="stat__label">Gross</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= e(money($run['total_paye'], false)) ?></div>
    <div class="stat__label">PAYE</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= e(money($run['total_nssf'] + $run['total_shif'] + $run['total_housing'], false)) ?></div>
    <div class="stat__label">NSSF, SHIF and levy</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__value"><?= e(money($run['total_net'], false)) ?></div>
    <div class="stat__label">Net to pay</div>
  </div>
</div>

<?php // What the business owes on top of wages. Kept apart because it is
      // our cost rather than anybody's deduction, and the returns ask for
      // the two separately. ?>
<div class="card">
  <div class="card__head"><div class="card__title">What this costs the business</div></div>
  <div class="table-wrap">
    <table class="table">
      <tbody>
        <tr><th style="width:280px">Wages</th><td class="num"><?= e(money($run['total_gross'], false)) ?></td></tr>
        <tr><th>NSSF, employer's share</th><td class="num"><?= e(money($run['total_employer_nssf'], false)) ?></td></tr>
        <tr><th>Housing levy, employer's share</th><td class="num"><?= e(money($run['total_employer_housing'], false)) ?></td></tr>
        <tr class="fw-700">
          <th>Total</th>
          <td class="num">
            <?= e(money($run['total_gross'] + $run['total_employer_nssf'] + $run['total_employer_housing'], false)) ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<?php // ---- What to do next ---------------------------------------------- ?>
<?php if ($status === 'draft' || $status === 'approved'): ?>
  <div class="card">
    <div class="card__body">
      <?php if ($status === 'draft'): ?>
        <p class="text-sm">
          Check the payslips below. Approving does not pay anybody — it says
          the figures are right and the money can go out.
        </p>

        <?php if (!$rates['confirmed']): ?>
          <div class="alert alert--warning">
            <?= icon('alert-triangle') ?>
            <div class="alert__body">
              The tax rates have not been confirmed, so this cannot be
              approved yet. Check them on
              <a href="<?= url('/payroll/rates') ?>">the rates page</a> first.
            </div>
          </div>
        <?php endif; ?>

        <div class="row-form">
          <?php if (Auth::can('payroll.approve')): ?>
            <form method="post" action="<?= url('/payroll/' . (int) $run['id'] . '/approve') ?>">
              <?= csrf_field() ?>
              <button class="btn btn--primary" type="submit">
                <?= icon('check-circle') ?> Approve <?= e(money($run['total_net'], false)) ?>
              </button>
            </form>
          <?php else: ?>
            <span class="text-sm text-muted">
              Somebody who can approve a payroll has to look at this before it goes out.
            </span>
          <?php endif; ?>

          <?php if (Auth::can('payroll.run')): ?>
            <form method="post" action="<?= url('/payroll/' . (int) $run['id'] . '/discard') ?>">
              <?= csrf_field() ?>
              <button class="btn btn--ghost" type="submit">
                Throw it away and start again
              </button>
            </form>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <p class="text-sm">
          Download the file for each method, pay it, then say it has gone.
        </p>
        <div class="row-form">
          <?php if (Auth::can('payroll.pay')): ?>
            <?php foreach (['mpesa' => 'M-Pesa', 'bank' => 'Bank'] as $k => $label): ?>
              <?php if (($byMethod[$k] ?? 0) > 0): ?>
                <a class="btn btn--outline" href="<?= url('/payroll/' . (int) $run['id'] . '/export/' . $k) ?>">
                  <?= icon('download') ?> <?= e($label) ?> file (<?= (int) $byMethod[$k] ?>)
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if (($byMethod['cash'] ?? 0) > 0): ?>
          <p class="text-xs text-muted mt-8">
            <?= (int) $byMethod['cash'] ?> paid in cash — no file for those.
          </p>
        <?php endif; ?>

        <?php if (($byMethod[''] ?? 0) > 0): ?>
          <p class="text-xs mt-8">
            <span class="badge badge--amber"><?= (int) $byMethod[''] ?> with no payment method</span>
            Set one on their record and work the month out again.
          </p>
        <?php endif; ?>

        <?php if (Auth::can('payroll.pay')): ?>
          <hr class="rule">
          <form method="post" action="<?= url('/payroll/' . (int) $run['id'] . '/paid') ?>" class="row-form">
            <?= csrf_field() ?>
            <div class="field mb-0" style="max-width:280px">
              <label class="label" for="ref">Payment reference</label>
              <input class="input" type="text" id="ref" name="ref" required maxlength="80"
                     placeholder="The bank's batch number">
            </div>
            <button class="btn btn--primary" type="submit">
              <?= icon('check') ?> It has been paid
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php // ---- The payslips --------------------------------------------------- ?>
<div class="card">
  <div class="card__head">
    <div class="card__title">Payslips</div>
    <span class="text-xs text-muted"><?= count($payslips) ?></span>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th style="width:120px" class="num">Gross</th>
          <th style="width:110px" class="num">NSSF</th>
          <th style="width:110px" class="num">SHIF</th>
          <th style="width:110px" class="num">Levy</th>
          <th style="width:120px" class="num">PAYE</th>
          <th style="width:130px" class="num">Net</th>
          <th style="width:100px">State</th>
          <th style="width:110px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payslips as $s): ?>
          <tr>
            <td>
              <a class="fw-600" href="<?= url('/staff/' . (int) $s['employee_id']) ?>">
                <?= e($s['employee_name']) ?>
              </a>
              <div class="text-xs text-muted">
                <?= e($s['employee_number']) ?>
                <?php if (!$s['kra_pin']): ?>
                  &middot; <span class="badge badge--amber">No PIN</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="num"><?= e(money($s['gross'], false)) ?></td>
            <td class="num text-muted"><?= e(money($s['nssf'], false)) ?></td>
            <td class="num text-muted"><?= e(money($s['shif'], false)) ?></td>
            <td class="num text-muted"><?= e(money($s['housing_levy'], false)) ?></td>
            <td class="num"><?= e(money($s['paye'], false)) ?></td>
            <td class="num fw-700"><?= e(money($s['net_pay'], false)) ?></td>
            <td>
              <span class="badge badge--<?= $s['status'] === 'paid' ? 'green' : ($s['status'] === 'held' ? 'amber' : 'grey') ?>">
                <?= e(label_of((string) $s['status'])) ?>
              </span>
            </td>
            <td class="text-right">
              <a class="btn btn--ghost btn--sm" href="<?= url('/payroll/payslip/' . (int) $s['id']) ?>">
                <?= icon('printer') ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="fw-700">
          <td>Total</td>
          <td class="num"><?= e(money($run['total_gross'], false)) ?></td>
          <td class="num"><?= e(money($run['total_nssf'], false)) ?></td>
          <td class="num"><?= e(money($run['total_shif'], false)) ?></td>
          <td class="num"><?= e(money($run['total_housing'], false)) ?></td>
          <td class="num"><?= e(money($run['total_paye'], false)) ?></td>
          <td class="num"><?= e(money($run['total_net'], false)) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
