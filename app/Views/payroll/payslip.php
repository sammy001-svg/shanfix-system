<?php
/**
 * One payslip, to print or hand over.
 *
 * Every figure is read from the payslip row rather than worked out again.
 * That is the whole point of storing them: if this page recomputed the
 * numbers, last March's payslip would quietly change the next time the law
 * did, and the copy in somebody's file would stop matching ours.
 *
 * The tax working is shown in full, because a payslip somebody cannot
 * check is one they have to take on trust.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$monthName = static function (string $p): string {
    $t = strtotime($p . '-01');

    return $t ? date('F Y', $t) : $p;
};

$logoPath   = $company['logo'] ? url('files/' . $company['logo']) : null;
$allowances = array_values(array_filter($lines, static fn(array $l): bool => $l['kind'] === 'allowance'));
$deductions = array_values(array_filter($lines, static fn(array $l): bool => $l['kind'] !== 'allowance'));
?>

<div class="print-bar no-print">
  <a class="btn btn--outline btn--sm" href="<?= url('/payroll/' . (int) $slip['run_id']) ?>">
    <?= icon('arrow-left') ?> Back
  </a>
  <button class="btn btn--primary btn--sm" type="button" onclick="window.print()">
    <?= icon('printer') ?> Print
  </button>
  <span class="text-sm text-muted">
    Use your browser's “Save as PDF” option in the print dialog to produce a PDF.
  </span>
</div>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <?php if ($logoPath): ?>
        <img class="doc-head__logo" src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>">
      <?php endif; ?>
      <div class="doc-head__company"><?= e($company['name']) ?></div>
      <div class="doc-head__lines">
        <?php if ($company['address']): ?><?= e($company['address']) ?><br><?php endif; ?>
        <?php if ($company['phone']): ?><?= e($company['phone']) ?><br><?php endif; ?>
        <?php if ($company['email']): ?><?= e($company['email']) ?><br><?php endif; ?>
        <?php if ($company['kra_pin']): ?>PIN: <?= e($company['kra_pin']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-head__right">
      <div class="doc-head__type">Payslip</div>
      <div class="doc-head__no"><?= e($monthName((string) $slip['period'])) ?></div>
      <div class="doc-head__dates">
        <?php if ($slip['paid_at']): ?>
          <strong>Paid:</strong> <?= e(fdate($slip['paid_at'])) ?><br>
        <?php endif; ?>
        <?php if ($slip['ref']): ?>
          <strong>Ref:</strong> <?= e($slip['ref']) ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <section class="doc-parties">
    <div class="doc-party">
      <div class="doc-party__label">Paid to</div>
      <div class="doc-party__name"><?= e($slip['employee_name']) ?></div>
      <div class="doc-party__lines">
        <?= e($slip['employee_number']) ?><br>
        <?php if ($slip['job_title']): ?><?= e($slip['job_title']) ?><br><?php endif; ?>
        <?php if ($slip['kra_pin']): ?>KRA PIN: <?= e($slip['kra_pin']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-party">
      <div class="doc-party__label">Paid by</div>
      <div class="doc-party__lines">
        <?php if ($slip['pay_method']): ?>
          <?= e(label_of((string) $slip['pay_method'])) ?><br>
          <?php if ($slip['destination'] && $slip['pay_method'] !== 'cash'): ?>
            <?= e($slip['destination']) ?>
          <?php endif; ?>
        <?php else: ?>
          Not recorded
        <?php endif; ?>
      </div>
    </div>
  </section>

  <table class="doc-table">
    <thead>
      <tr>
        <th>Earnings</th>
        <th class="num" style="width:170px">Amount</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Basic salary</td>
        <td class="num"><?= e(money($slip['basic'], false)) ?></td>
      </tr>
      <?php foreach ($allowances as $l): ?>
        <tr>
          <td>
            <?= e($l['name']) ?>
            <?php if ((int) $l['taxable'] !== 1): ?>
              <span class="text-muted">(not taxed)</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= e(money($l['amount'], false)) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td class="fw-700">Gross pay</td>
        <td class="num fw-700"><?= e(money($slip['gross'], false)) ?></td>
      </tr>
    </tbody>
  </table>

  <table class="doc-table">
    <thead>
      <tr>
        <th>Deductions</th>
        <th class="num" style="width:170px">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$deductions): ?>
        <tr><td colspan="2" class="text-muted">Nothing withheld</td></tr>
      <?php endif; ?>
      <?php foreach ($deductions as $l): ?>
        <tr>
          <td><?= e($l['name']) ?></td>
          <td class="num"><?= e(money($l['amount'], false)) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td class="fw-700">Total deductions</td>
        <td class="num fw-700"><?= e(money($slip['total_deductions'], false)) ?></td>
      </tr>
    </tbody>
  </table>

  <table class="doc-table">
    <thead>
      <tr>
        <th>How the tax was worked out</th>
        <th class="num" style="width:170px"></th>
      </tr>
    </thead>
    <tbody>
      <tr><td>Pay subject to tax</td><td class="num"><?= e(money($slip['taxable_pay'], false)) ?></td></tr>
      <tr><td>Tax on that</td><td class="num"><?= e(money($slip['paye_gross'], false)) ?></td></tr>
      <tr>
        <td>Less personal relief</td>
        <td class="num">&minus;<?= e(money($slip['personal_relief'], false)) ?></td>
      </tr>
      <?php if ((float) $slip['insurance_relief'] > 0.004): ?>
        <tr>
          <td>Less insurance relief</td>
          <td class="num">&minus;<?= e(money($slip['insurance_relief'], false)) ?></td>
        </tr>
      <?php endif; ?>
      <tr>
        <td class="fw-700">PAYE withheld</td>
        <td class="num fw-700"><?= e(money($slip['paye'], false)) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="doc-totals">
    <div class="doc-totals__inner">
      <div class="doc-totals__row"><span>Gross pay</span><span><?= e(money($slip['gross'], false)) ?></span></div>
      <div class="doc-totals__row"><span>Deductions</span><span>&minus;<?= e(money($slip['total_deductions'], false)) ?></span></div>
      <div class="doc-totals__row doc-totals__row--grand">
        <span>Net pay</span><span><?= e(money($slip['net_pay'])) ?></span>
      </div>
    </div>
  </div>

  <footer class="doc-foot">
    <p>
      For <?= e($monthName((string) $slip['period'])) ?>. On top of the above,
      <?= e($company['name']) ?> also pays
      <?= e(money($slip['employer_nssf'], false)) ?> in NSSF and
      <?= e(money($slip['employer_housing'], false)) ?> in housing levy as the
      employer's share. These are not deducted from you.
    </p>
  </footer>
</div>
