<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$pending = $partner['status'] === 'pending';

// Only needed for the read-only branch, where there is no picker to
// read the name off.
$managerName = $partner['account_manager_id']
    ? (string) \App\Core\Database::scalar(
        'SELECT name FROM users WHERE id = :u',
        ['u' => (int) $partner['account_manager_id']],
        ''
      )
    : '';
$rateOf  = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';

$tone = static fn(string $s): string => match ($s) {
  'active'    => 'green',
  'pending'   => 'amber',
  'suspended' => 'red',
  default     => 'grey',
};
?>

<p class="text-sm mb-12">
  <a href="<?= url('/partners-admin') ?>">&larr; All partners</a>
</p>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($partner['company'] ?: $partner['name']) ?></h1>
    <div class="page-head__sub">
      <?= e($partner['name']) ?> &middot; <?= e($partner['email']) ?> &middot; <?= e($partner['phone']) ?>
      <?php if ($partner['partner_code']): ?> &middot; <?= e($partner['partner_code']) ?><?php endif; ?>
      <span class="badge badge--<?= e($tone((string) $partner['status'])) ?> ml-8">
        <?= e(label_of((string) $partner['status'])) ?>
      </span>
    </div>
  </div>
</div>

<?php if ($pending): ?>
  <?php // An application waiting on a decision leads the page, because
        // that is the only thing anybody opened it to do. ?>
  <div class="card">
    <div class="card__head"><div class="card__title">This application is waiting on you</div></div>
    <div class="card__body">
      <?php if ($partner['pitch']): ?>
        <div class="fw-600 mb-4">Who they sell to, in their words</div>
        <p class="text-sm" style="white-space:pre-line"><?= e($partner['pitch']) ?></p>
      <?php endif; ?>

      <?php if (Auth::can('partners.manage')): ?>
        <form method="post" action="<?= url('/partners-admin/' . (int) $partner['id'] . '/decide') ?>"
              class="row-form mt-16">
          <?= csrf_field() ?>

          <div class="field mb-0" style="max-width:180px">
            <label class="label" for="default_rate">Their rate</label>
            <div class="input-group">
              <input class="input" type="number" id="default_rate" name="default_rate"
                     step="0.5" min="0" max="100"
                     value="<?= e(rtrim(rtrim(number_format((float) $partner['default_rate'], 2), '0'), '.')) ?>">
              <span class="input-group__addon">%</span>
            </div>
          </div>

          <div class="field mb-0 flex-1">
            <label class="label" for="note">Note <span class="text-muted">(optional)</span></label>
            <input class="input" type="text" id="note" name="note" maxlength="255">
          </div>

          <button class="btn btn--primary" type="submit" name="decision" value="approve">
            <?= icon('check') ?> Approve
          </button>
          <button class="btn btn--outline" type="submit" name="decision" value="reject">
            Turn down
          </button>
        </form>

        <p class="text-xs text-muted mt-8 mb-0">
          Approving sends them a link to set their own password. Nobody here
          ever sees it.
        </p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="stat-grid mb-16">
  <div class="stat <?= $summary['due'] > 0.009 ? 'stat--amber' : '' ?>">
    <div class="stat__value"><?= e(money($summary['due'], false)) ?></div>
    <div class="stat__label">Owed to them</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__value"><?= e(money($summary['paid'], false)) ?></div>
    <div class="stat__label">Paid out</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__value"><?= (int) $summary['clients'] ?></div>
    <div class="stat__label">Customers</div>
  </div>
</div>

<?php // Who looks after them. Shown to everybody, changeable only by
      // somebody allowed to assign — sales are the contact point, not the
      // people who decide who the contact point is. ?>
<div class="card">
  <div class="card__head"><div class="card__title">Looked after by</div></div>
  <div class="card__body">
    <?php if (Auth::can('partners.assign')): ?>
      <form method="post" action="<?= url('/partners-admin/' . (int) $partner['id'] . '/assign') ?>"
            class="row-form">
        <?= csrf_field() ?>
        <div class="field mb-0 flex-1">
          <label class="label" for="account_manager_id">Account manager</label>
          <select class="select" id="account_manager_id" name="account_manager_id">
            <option value="">Nobody</option>
            <?php foreach ($managers as $m): ?>
              <option value="<?= (int) $m['id'] ?>"
                <?= (int) $partner['account_manager_id'] === (int) $m['id'] ? 'selected' : '' ?>>
                <?= e($m['name']) ?> (<?= e(label_of((string) $m['role'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn--outline" type="submit">Save</button>
      </form>
      <p class="text-xs text-muted mt-8 mb-0">
        Their contact point here. Being assigned does not let somebody
        approve an application, move a rate, pay a commission or register a
        partner — those stay where they are.
      </p>
    <?php else: ?>
      <p class="text-sm mb-0">
        <?php if ($partner['account_manager_id']): ?>
          <strong><?= e($managerName ?: 'Somebody') ?></strong>
          <?php if ($partner['assigned_at']): ?>
            <span class="text-muted">since <?= e(fdate($partner['assigned_at'])) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">Nobody is looking after this partner yet.</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php // Commission is paid monthly, so the ledger is read monthly. Each
      // month is settled on its own, which is what lets both sides
      // reconcile against the same figure afterwards. ?>
<div class="card">
  <div class="card__head">
    <div class="card__title">Month by month</div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/partners-admin/runs') ?>">
      <?= icon('calendar') ?> All partners, one month
    </a>
  </div>
  <?php if (!$months): ?>
    <div class="card__body text-sm text-muted">
      Nothing earned yet. Commission lands in the month the customer pays us.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:150px">Month</th>
            <th style="width:90px" class="num">Entries</th>
            <th style="width:140px" class="num">Owed</th>
            <th style="width:140px" class="num">Paid</th>
            <th>Reference</th>
            <?php if (Auth::can('partners.pay')): ?>
              <th style="width:260px">Settle</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($months as $m): ?>
            <?php $t = strtotime($m['period'] . '-01'); ?>
            <tr>
              <td class="fw-600"><?= e($t ? date('F Y', $t) : $m['period']) ?></td>
              <td class="num text-muted"><?= (int) $m['entries'] ?></td>
              <td class="num <?= (float) $m['due'] > 0.009 ? 'fw-700' : 'text-muted' ?>">
                <?= e(money($m['due'], false)) ?>
              </td>
              <td class="num text-muted"><?= e(money($m['paid'], false)) ?></td>
              <td class="text-xs">
                <?= e($m['payout_ref'] ?: '—') ?>
                <?php if ($m['paid_at']): ?>
                  <div class="table__muted"><?= e(fdate($m['paid_at'])) ?></div>
                <?php endif; ?>
              </td>
              <?php if (Auth::can('partners.pay')): ?>
                <td>
                  <?php if ((float) $m['due'] > 0.009): ?>
                    <form method="post"
                          action="<?= url('/partners-admin/' . (int) $partner['id'] . '/payout') ?>"
                          class="row-form row-form--tight">
                      <?= csrf_field() ?>
                      <input type="hidden" name="period" value="<?= e($m['period']) ?>">
                      <input class="input input--sm" type="text" name="payout_ref" required
                             maxlength="80" placeholder="Payment reference"
                             aria-label="Payment reference for <?= e($m['period']) ?>">
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
  <?php endif; ?>
</div>


<?php if (Auth::can('partners.pay') && $summary['due'] > 0.009): ?>
  <div class="card">
    <div class="card__head"><div class="card__title">Settle everything outstanding</div></div>
    <div class="card__body">
      <form method="post" action="<?= url('/partners-admin/' . (int) $partner['id'] . '/payout') ?>"
            class="row-form">
        <?= csrf_field() ?>
        <div class="field mb-0 flex-1">
          <label class="label" for="payout_ref">Payment reference</label>
          <input class="input" type="text" id="payout_ref" name="payout_ref" required
                 maxlength="80" placeholder="M-Pesa code, or the bank transfer reference">
        </div>
        <button class="btn btn--primary" type="submit">
          Mark <?= e(money($summary['due'], false)) ?> as paid
        </button>
      </form>
      <p class="text-xs text-muted mt-8 mb-0">
        Commission is paid monthly, so this is the exception rather than the
        rule: every month still owed, in one payment, against one reference.
        Use the month-by-month table above for a normal run. What has
        already been paid is never touched.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php if (Auth::can('partners.manage')): ?>
  <div class="card">
    <div class="card__head"><div class="card__title">Their details</div></div>
    <div class="card__body">
      <form method="post" action="<?= url('/partners-admin/' . (int) $partner['id']) ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="field">
            <label class="label" for="name">Name</label>
            <input class="input" type="text" id="name" name="name" required maxlength="140"
                   value="<?= e($partner['name']) ?>">
          </div>
          <div class="field">
            <label class="label" for="company">Business</label>
            <input class="input" type="text" id="company" name="company" maxlength="180"
                   value="<?= e($partner['company']) ?>">
          </div>
          <div class="field">
            <label class="label" for="email">Email address</label>
            <input class="input" type="email" id="email" name="email" required maxlength="160"
                   value="<?= e($partner['email']) ?>">
          </div>
          <div class="field">
            <label class="label" for="phone">Phone</label>
            <input class="input" type="tel" id="phone" name="phone" required maxlength="30"
                   value="<?= e($partner['phone']) ?>">
          </div>
          <div class="field">
            <label class="label" for="kra_pin">KRA PIN</label>
            <input class="input" type="text" id="kra_pin" name="kra_pin" maxlength="30"
                   value="<?= e($partner['kra_pin']) ?>">
          </div>
          <div class="field">
            <label class="label" for="rate">Commission rate</label>
            <div class="input-group">
              <input class="input" type="number" id="rate" name="default_rate"
                     step="0.5" min="0" max="100"
                     value="<?= e(rtrim(rtrim(number_format((float) $partner['default_rate'], 2), '0'), '.')) ?>">
              <span class="input-group__addon">%</span>
            </div>
            <span class="field-hint">
              Used where a service does not carry its own rate. Changing it
              does not move commission already earned.
            </span>
          </div>
        </div>

        <div class="field">
          <label class="label" for="notes">Internal notes</label>
          <textarea class="input" id="notes" name="notes" rows="3"><?= e($partner['notes']) ?></textarea>
          <span class="field-hint">Never shown to the partner.</span>
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit">Save</button>

          <?php if ($partner['status'] === 'active'): ?>
            <button class="btn btn--outline" type="submit" formnovalidate
                    formaction="<?= url('/partners-admin/' . (int) $partner['id'] . '/decide') ?>"
                    name="decision" value="suspend">
              Suspend
            </button>
          <?php elseif (in_array($partner['status'], ['suspended', 'rejected'], true)): ?>
            <button class="btn btn--outline" type="submit" formnovalidate
                    formaction="<?= url('/partners-admin/' . (int) $partner['id'] . '/decide') ?>"
                    name="decision" value="restore">
              Let them back in
            </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php // What they actually sell for us, taken from the invoices rather
      // than from anybody's impression of it. ?>
<div class="card">
  <div class="card__head"><div class="card__title">What they are reselling</div></div>
  <?php if (!$reselling): ?>
    <div class="card__body text-sm text-muted">
      Nothing yet. This fills in from invoice lines <strong>picked from the
      service list</strong>. A line typed in by hand is not linked to a
      service, so it cannot appear here — and it cannot carry that service's
      own commission rate either, falling back to the partner's rate
      instead. The commission is still paid; only the rate differs.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Service</th>
            <th style="width:90px" class="num">Rate</th>
            <th style="width:110px" class="num">Customers</th>
            <th style="width:100px" class="num">Invoices</th>
            <th style="width:140px" class="num">Billed</th>
            <th style="width:120px">Last sold</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reselling as $s): ?>
            <?php $rate = $s['commission_rate'] === null
                ? (float) $partner['default_rate']
                : (float) $s['commission_rate']; ?>
            <tr>
              <td><a class="table__primary" href="<?= url('/services/' . (int) $s['id']) ?>"><?= e($s['name']) ?></a></td>
              <td class="num">
                <?= e($rateOf($rate)) ?>
                <?php if ($s['commission_rate'] === null): ?>
                  <div class="table__muted">their rate</div>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $s['customers'] ?></td>
              <td class="num"><?= (int) $s['invoices'] ?></td>
              <td class="num fw-600"><?= e(money($s['billed'], false)) ?></td>
              <td class="text-xs text-muted"><?= e(fdate($s['last_sold'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php // The recurring side. A renewal is future commission with a date on
      // it, which is the most useful thing on this page for whoever looks
      // after the relationship. ?>
<div class="card">
  <div class="card__head"><div class="card__title">Recurring, and when it falls due</div></div>
  <?php if (!$renewals): ?>
    <div class="card__body text-sm text-muted">
      None of their customers is on anything recurring.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Service</th>
            <th>Customer</th>
            <th style="width:150px" class="num">Amount</th>
            <th style="width:130px" class="num">Their cut</th>
            <th style="width:170px">Next due</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($renewals as $r): ?>
            <?php
              $days = $r['days_away'];
              $late = $days !== null && $days < 0;
              $soon = $days !== null && $days >= 0 && $days <= 30;
              $off  = $r['status'] !== 'active';
            ?>
            <tr>
              <td>
                <span class="fw-600"><?= e($r['name']) ?></span>
                <?php if ($off): ?>
                  <span class="badge badge--grey"><?= e(label_of((string) $r['status'])) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <a class="table__primary" href="<?= url('/clients/' . (int) $r['client_id']) ?>">
                  <?= e($r['client_name']) ?>
                </a>
              </td>
              <td class="num">
                <?= e(money($r['amount'], false)) ?>
                <div class="table__muted"><?= e(\App\Services\Renewals::cyclePhrase($r['billing_cycle'])) ?></div>
              </td>
              <td class="num fw-600">
                <?= e(money($r['expected'], false)) ?>
                <div class="table__muted"><?= e($rateOf((float) $r['effective_rate'])) ?></div>
              </td>
              <td>
                <?php if ($r['next_renewal_date']): ?>
                  <?= e(fdate($r['next_renewal_date'])) ?>
                  <?php if (!$off && $days !== null): ?>
                    <span class="badge badge--<?= $late ? 'red' : ($soon ? 'amber' : 'green') ?>">
                      <?= $late
                        ? abs($days) . 'd overdue'
                        : ($days === 0 ? 'Today' : 'in ' . $days . 'd') ?>
                    </span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">No date set</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head"><div class="card__title">Their customers</div></div>
  <?php if (!$customers): ?>
    <div class="card__body text-sm text-muted">
      Nobody is tagged to them yet. Tag a client to a partner on the client's
      own page.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Customer</th>
            <th style="width:140px">Theirs since</th>
            <th style="width:150px" class="num">Earned from them</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $c): ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/clients/' . (int) $c['id']) ?>">
                  <?= e($c['name']) ?>
                </a>
              </td>
              <td class="text-xs text-muted"><?= e(fdate($c['partner_linked_at'])) ?></td>
              <td class="num fw-600"><?= e(money($c['earned'], false)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head"><div class="card__title">Every entry</div></div>
  <?php if (!$commissions): ?>
    <div class="card__body text-sm text-muted">
      Nothing earned yet. Commission appears when one of their customers pays.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px">When</th>
            <th>Customer</th>
            <th style="width:150px">Invoice</th>
            <th style="width:80px" class="num">Rate</th>
            <th style="width:130px" class="num">Amount</th>
            <th style="width:120px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($commissions as $c): ?>
            <tr>
              <td class="text-xs text-muted"><?= e(fdate($c['created_at'])) ?></td>
              <td><?= e($c['client_name']) ?></td>
              <td class="text-xs"><?= e($c['doc_number']) ?></td>
              <td class="num"><?= e($rateOf((float) $c['rate'])) ?></td>
              <td class="num fw-600"><?= e(money($c['amount'], false)) ?></td>
              <td>
                <span class="badge badge--<?= $c['status'] === 'paid' ? 'green' : 'amber' ?>">
                  <?= $c['status'] === 'paid' ? 'Paid' : 'Owed' ?>
                </span>
                <?php if ($c['payout_ref']): ?>
                  <div class="table__muted"><?= e($c['payout_ref']) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
