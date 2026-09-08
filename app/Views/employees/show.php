<?php
/**
 * One person.
 *
 * Three tabs: who they are and what they hold, what they are paid on top
 * of their basic, and the record itself. The payslips sit under Pay
 * because that is what somebody is looking for when they open it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$canManage = Auth::can('hr.manage');

$tone = static fn(string $s): string => match ($s) {
    'active'    => 'green',
    'on_leave'  => 'blue',
    'suspended' => 'amber',
    default     => 'grey',
};

$tabUrl = static fn(string $k): string =>
    url('/staff/' . (int) $employee['id'] . ($k !== 'overview' ? '?tab=' . $k : ''));

$active = array_values(array_filter($items, static fn(array $i): bool => (int) $i['is_active'] === 1));

$allowances = 0.0;
$deductions = 0.0;

foreach ($active as $i) {
    if ($i['kind'] === 'allowance') { $allowances += (float) $i['amount']; }
    else                            { $deductions += (float) $i['amount']; }
}
?>

<p class="text-sm mb-12"><a href="<?= url('/staff') ?>">&larr; Staff</a></p>

<div class="card partner-head">
  <div class="partner-head__id">
    <div class="partner-head__name">
      <h1><?= e($employee['name']) ?></h1>
      <span class="badge badge--<?= e($tone((string) $employee['status'])) ?>">
        <?= e(label_of((string) $employee['status'])) ?>
      </span>
      <?php // Nobody can be put on a PAYE return without one, and it is
            // always missing at the wrong moment. ?>
      <?php if (trim((string) $employee['kra_pin']) === '' && $employee['status'] !== 'left'): ?>
        <span class="badge badge--amber">No KRA PIN</span>
      <?php endif; ?>
    </div>

    <dl class="partner-head__facts">
      <dt>Number</dt><dd><?= e($employee['employee_number']) ?></dd>

      <?php if ($employee['job_title']): ?>
        <dt>Job</dt><dd><?= e($employee['job_title']) ?></dd>
      <?php endif; ?>

      <?php if ($employee['department']): ?>
        <dt>Department</dt><dd><?= e($employee['department']) ?></dd>
      <?php endif; ?>

      <dt>Terms</dt><dd><?= e(label_of((string) $employee['employment_type'])) ?></dd>

      <?php if ($employee['started_on']): ?>
        <dt>Started</dt><dd><?= e(fdate($employee['started_on'])) ?></dd>
      <?php endif; ?>

      <?php if ($employee['ended_on']): ?>
        <dt>Last day</dt>
        <dd><?= e(fdate($employee['ended_on'])) ?>
          <?php if ($employee['end_reason']): ?>
            <span class="text-muted">— <?= e($employee['end_reason']) ?></span>
          <?php endif; ?>
        </dd>
      <?php endif; ?>

      <?php if ($employee['phone']): ?>
        <dt>Phone</dt>
        <dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $employee['phone'])) ?>"><?= e($employee['phone']) ?></a></dd>
      <?php endif; ?>

      <?php if ($employee['email']): ?>
        <dt>Email</dt><dd><a href="mailto:<?= e($employee['email']) ?>"><?= e($employee['email']) ?></a></dd>
      <?php endif; ?>
    </dl>
  </div>

  <div class="partner-head__money">
    <div class="stat">
      <div class="stat__value"><?= e(money($employee['basic_salary'], false)) ?></div>
      <div class="stat__label">Basic, monthly</div>
    </div>
    <div class="stat stat--green">
      <div class="stat__value"><?= e(money($employee['basic_salary'] + $allowances - $deductions, false)) ?></div>
      <div class="stat__label">Before tax</div>
    </div>
    <div class="stat stat--navy">
      <div class="stat__value"><?= count($equipment) ?></div>
      <div class="stat__label">Machines held</div>
    </div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $tab === 'overview' ? 'is-active' : '' ?>" href="<?= e($tabUrl('overview')) ?>">Overview</a>
    <a class="tab <?= $tab === 'pay' ? 'is-active' : '' ?>" href="<?= e($tabUrl('pay')) ?>">
      Pay
      <?php if ($active): ?><span class="tab__count"><?= count($active) ?></span><?php endif; ?>
    </a>
    <?php if ($canManage): ?>
      <a class="tab <?= $tab === 'details' ? 'is-active' : '' ?>" href="<?= e($tabUrl('details')) ?>">Details</a>
    <?php endif; ?>
  </nav>
</div>

<?php if ($tab === 'overview'): ?>

  <div class="card">
    <div class="card__head"><div class="card__title">For the returns</div></div>
    <div class="table-wrap">
      <table class="table">
        <tbody>
          <tr><th style="width:220px">ID number</th><td><?= e($employee['id_number'] ?: '—') ?></td></tr>
          <tr><th>KRA PIN</th><td><?= e($employee['kra_pin'] ?: '—') ?></td></tr>
          <tr><th>NSSF number</th><td><?= e($employee['nssf_number'] ?: '—') ?></td></tr>
          <tr><th>Health insurance</th><td><?= e($employee['shif_number'] ?: '—') ?></td></tr>
          <tr>
            <th>Paid by</th>
            <td>
              <?php if ($employee['pay_method'] === 'mpesa'): ?>
                M-Pesa &middot; <?= e($employee['pay_phone'] ?: $employee['phone'] ?: '—') ?>
              <?php elseif ($employee['pay_method'] === 'bank'): ?>
                <?= e($employee['bank_name'] ?: 'Bank') ?>
                <?= $employee['bank_branch'] ? '&middot; ' . e($employee['bank_branch']) : '' ?>
                <div class="text-xs text-muted">
                  <?= e($employee['bank_account_name'] ?: '') ?>
                  <?= $employee['bank_account_no'] ? '&middot; ' . e($employee['bank_account_no']) : '' ?>
                </div>
              <?php elseif ($employee['pay_method'] === 'cash'): ?>
                Cash
              <?php else: ?>
                <span class="badge badge--amber">Not set — they cannot be paid</span>
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($employee['kin_name']): ?>
    <div class="card">
      <div class="card__head"><div class="card__title">Next of kin</div></div>
      <div class="card__body text-sm">
        <?= e($employee['kin_name']) ?>
        <?php if ($employee['kin_relationship']): ?>
          <span class="text-muted">(<?= e($employee['kin_relationship']) ?>)</span>
        <?php endif; ?>
        <?php if ($employee['kin_phone']): ?>
          &middot; <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $employee['kin_phone'])) ?>"><?= e($employee['kin_phone']) ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($equipment): ?>
    <div class="card">
      <div class="card__head"><div class="card__title">Machines they are answerable for</div></div>
      <div class="table-wrap">
        <table class="table">
          <tbody>
            <?php foreach ($equipment as $eq): ?>
              <tr>
                <td>
                  <a class="fw-600" href="<?= url('/equipment/' . (int) $eq['id']) ?>"><?= e($eq['name']) ?></a>
                  <div class="text-xs text-muted"><?= e($eq['asset_code']) ?></div>
                </td>
                <td style="width:150px"><?= e(label_of((string) $eq['status'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($employee['notes']): ?>
    <div class="card">
      <div class="card__head"><div class="card__title">Notes</div></div>
      <div class="card__body text-sm" style="white-space:pre-line"><?= e($employee['notes']) ?></div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php if ($tab === 'pay'): ?>

  <div class="card">
    <div class="card__head">
      <div class="card__title">Allowances and deductions</div>
      <span class="text-xs text-muted">On top of, or out of, the basic</span>
    </div>

    <?php if (!$items): ?>
      <div class="card__body text-sm text-muted">
        Nothing yet — they are paid their basic and nothing else.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>What it is</th>
              <th style="width:120px">Kind</th>
              <th style="width:130px" class="num">Amount</th>
              <th style="width:120px">Taxed</th>
              <th style="width:170px">Runs</th>
              <?php if ($canManage): ?><th style="width:110px"></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $i): ?>
              <tr class="<?= (int) $i["is_active"] === 1 ? "" : "text-muted" ?>">
                <td>
                  <span class="fw-600"><?= e($i['name']) ?></span>
                  <?php if ((int) $i['is_active'] !== 1): ?>
                    <span class="badge badge--grey">Ended</span>
                  <?php endif; ?>
                  <?php if ($i['note']): ?>
                    <div class="text-xs text-muted"><?= e($i['note']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-sm"><?= $i['kind'] === 'allowance' ? 'Allowance' : 'Deduction' ?></td>
                <td class="num fw-600"><?= e(money($i['amount'], false)) ?></td>
                <td class="text-sm text-muted">
                  <?= $i['kind'] === 'allowance' ? ((int) $i['taxable'] === 1 ? 'Yes' : 'No') : '—' ?>
                </td>
                <td class="text-xs text-muted">
                  <?= (int) $i['recurring'] === 1 ? 'Every month' : 'Once' ?>
                  <?php if ($i['starts_on'] || $i['ends_on']): ?>
                    <div>
                      <?= $i['starts_on'] ? e(fdate($i['starts_on'])) : '…' ?>
                      &ndash;
                      <?= $i['ends_on'] ? e(fdate($i['ends_on'])) : '…' ?>
                    </div>
                  <?php endif; ?>
                </td>
                <?php if ($canManage): ?>
                  <td>
                    <?php if ((int) $i['is_active'] === 1): ?>
                      <form method="post"
                            action="<?= url('/staff/' . (int) $employee['id'] . '/pay-items/' . (int) $i['id'] . '/end') ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--sm" type="submit">End it</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
      <div class="card__body">
        <?php // Ended rather than deleted: a payslip already issued was
              // worked out with it on, and the record of why should live. ?>
        <form method="post" action="<?= url('/staff/' . (int) $employee['id'] . '/pay-items') ?>">
          <?= csrf_field() ?>
          <div class="form-grid">
            <div class="field">
              <label class="label" for="pi_name">What it is</label>
              <input class="input" type="text" id="pi_name" name="name" required maxlength="120"
                     placeholder="House allowance, salary advance…">
            </div>
            <div class="field">
              <label class="label" for="pi_kind">Kind</label>
              <select class="select" id="pi_kind" name="kind">
                <option value="allowance">Allowance — paid on top</option>
                <option value="deduction">Deduction — taken out</option>
              </select>
            </div>
            <div class="field">
              <label class="label" for="pi_amount">Amount</label>
              <input class="input" type="number" step="0.01" min="0" id="pi_amount" name="amount" required value="0">
            </div>
            <div class="field">
              <label class="label" for="pi_starts">From</label>
              <input class="input" type="date" id="pi_starts" name="starts_on">
            </div>
            <div class="field">
              <label class="label" for="pi_ends">Until</label>
              <input class="input" type="date" id="pi_ends" name="ends_on">
              <span class="field-hint">Leave empty for no end.</span>
            </div>
            <div class="field">
              <label class="label" for="pi_note">Note</label>
              <input class="input" type="text" id="pi_note" name="note" maxlength="255">
            </div>
          </div>

          <label class="check">
            <input type="checkbox" name="taxable" value="1" checked>
            <span class="check__text">
              <strong>Taxed</strong> — allowances only. Untick for something
              paid without being added to taxable pay.
            </span>
          </label>

          <label class="check">
            <input type="checkbox" name="recurring" value="1" checked>
            <span class="check__text"><strong>Every month</strong> until it is ended</span>
          </label>

          <div class="form-actions">
            <button class="btn btn--primary" type="submit"><?= icon('plus') ?> Add it</button>
            <span class="text-xs text-muted">It applies from the next run worked out, not to one already calculated.</span>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">Payslips</div></div>
    <?php if (!$payslips): ?>
      <div class="card__body text-sm text-muted">
        None yet. They appear here once a payroll has been worked out.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:150px">Month</th>
              <th style="width:140px" class="num">Gross</th>
              <th style="width:140px" class="num">Deductions</th>
              <th style="width:140px" class="num">Net</th>
              <th style="width:120px">State</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payslips as $s): ?>
              <?php $t = strtotime($s['period'] . '-01'); ?>
              <tr>
                <td class="fw-600"><?= e($t ? date('F Y', $t) : $s['period']) ?></td>
                <td class="num"><?= e(money($s['gross'], false)) ?></td>
                <td class="num text-muted"><?= e(money($s['total_deductions'], false)) ?></td>
                <td class="num fw-700"><?= e(money($s['net_pay'], false)) ?></td>
                <td>
                  <span class="badge badge--<?= $s['status'] === 'paid' ? 'green' : ($s['status'] === 'held' ? 'amber' : 'grey') ?>">
                    <?= e(label_of((string) $s['status'])) ?>
                  </span>
                </td>
                <td class="text-right">
                  <a class="btn btn--ghost btn--sm" href="<?= url('/payroll/payslip/' . (int) $s['id']) ?>">
                    <?= icon('printer') ?> Payslip
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php if ($tab === 'details' && $canManage): ?>
  <?php require APP_PATH . '/Views/employees/form.php'; ?>
<?php endif; ?>
