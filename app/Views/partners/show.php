<?php
/**
 * One partner, from our side of the desk.
 *
 * This page had nine full-width cards stacked down it — an approval
 * decision, a payment, an edit form and four tables, all at the same
 * weight — so finding any one of them meant scrolling past the other
 * eight. It is now a header that says who this is and what we owe them,
 * and tabs for the rest.
 *
 * The tab lives in the URL rather than in JavaScript, so each one is a
 * link somebody can send to the person who needs to look at it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\Partners;

$pending = $partner['status'] === 'pending';
$rateOf  = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';

// Only needed where there is no picker to read the name off.
$managerName = $partner['account_manager_id']
    ? (string) \App\Core\Database::scalar(
        'SELECT name FROM users WHERE id = :u',
        ['u' => (int) $partner['account_manager_id']],
        ''
      )
    : '';

$tone = static fn(string $s): string => match ($s) {
  'active'    => 'green',
  'pending'   => 'amber',
  'suspended' => 'red',
  default     => 'grey',
};

$tabUrl = static fn(string $k): string =>
    url('/partners-admin/' . (int) $partner['id'] . ($k !== 'overview' ? '?tab=' . $k : ''));

// What is stopping them being paid, from the one place that knows. This
// used to be "no KRA PIN", which was the only answer the module had and
// is an answer a partner in Dubai can never satisfy.
$blocked = $blockers !== [] && $partner['status'] === 'active';

$countryName = Partners::countryName((string) $partner['country']);
$currency    = (string) $partner['payout_currency'];
$crossRate   = $currency !== Partners::baseCurrency() ? $fxRate : null;
?>

<p class="text-sm mb-12">
  <a href="<?= url('/partners-admin') ?>">&larr; All partners</a>
</p>

<?php // Who this is, what we owe them, and who looks after them — before
      // any of the detail, because those are the three things somebody
      // opens a partner to find out. ?>
<div class="card partner-head">
  <div class="partner-head__id">
    <div class="partner-head__name">
      <h1><?= e($partner['company'] ?: $partner['name']) ?></h1>
      <span class="badge badge--<?= e($tone((string) $partner['status'])) ?>">
        <?= e(label_of((string) $partner['status'])) ?>
      </span>
      <?php // Where they are, next to the name. On a page that now holds
            // partners on three continents, this is part of who they are
            // rather than a detail further down. ?>
      <?php if (!$isLocal): ?>
        <span class="badge badge--blue"><?= e($countryName) ?></span>
      <?php endif; ?>

      <span class="badge badge--<?= $partner['tier'] === 'gold' ? 'amber' : 'grey' ?>">
        <?= e(Partners::tierName((string) $partner['tier'])) ?>
      </span>

      <?php if ($blocked): ?>
        <?php // Said where the person who chases it will see it, and said
              // as the actual reason rather than as a guess. ?>
        <span class="badge badge--amber">Cannot be paid</span>
      <?php endif; ?>
    </div>

    <dl class="partner-head__facts">
      <?php if ($partner['company']): ?>
        <dt>Contact</dt><dd><?= e($partner['name']) ?></dd>
      <?php endif; ?>

      <?php if (!empty($partner['occupation'])): ?>
        <dt>Occupation</dt><dd><?= e($partner['occupation']) ?></dd>
      <?php endif; ?>

      <?php // Shown next to the name on purpose: these are the two things
            // checked against each other before money is sent. ?>
      <?php if (!empty($partner['id_number'])): ?>
        <dt>ID number</dt><dd><?= e($partner['id_number']) ?></dd>
      <?php endif; ?>

      <dt>Country</dt>
      <dd>
        <?= e($countryName) ?>
        <?php if (!empty($partner['city'])): ?>
          &middot; <?= e($partner['city']) ?>
        <?php endif; ?>
        <?php if ($isLocal): ?>
          <span class="text-muted">(home market)</span>
        <?php endif; ?>
      </dd>

      <?php if (!empty($partner['office_location'])): ?>
        <dt>Works from</dt><dd><?= e($partner['office_location']) ?></dd>
      <?php endif; ?>

      <?php if (!empty($partner['territory'])): ?>
        <dt>Territory</dt><dd><?= e($partner['territory']) ?></dd>
      <?php endif; ?>

      <?php // Only worth showing when it is not ours, which is when
            // somebody is about to ring them at the wrong hour. ?>
      <?php if (!empty($partner['timezone']) && $partner['timezone'] !== date_default_timezone_get()): ?>
        <dt>Their time</dt>
        <dd>
          <?php try {
                  $theirs = new DateTime('now', new DateTimeZone((string) $partner['timezone']));
                  echo e($theirs->format('H:i')) . ' <span class="text-muted">'
                     . e($partner['timezone']) . '</span>';
                } catch (Exception $e) {
                  echo e((string) $partner['timezone']);
                } ?>
        </dd>
      <?php endif; ?>

      <dt>Type</dt>
      <dd>
        <?= e(ucfirst((string) $partner['partner_type'])) ?>
        <span class="text-muted">
          <?php // The labels read "Referral — introduces customers to us",
                // and the tier badge above already says the first half.
                $desc  = (string) (Partners::TYPES[$partner['partner_type']] ?? '');
                $parts = explode('—', $desc, 2);
                echo e(trim($parts[1] ?? $desc)); ?>
        </span>
      </dd>

      <dt>Email</dt>
      <dd><a href="mailto:<?= e($partner['email']) ?>"><?= e($partner['email']) ?></a></dd>

      <dt>Phone</dt>
      <dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $partner['phone'])) ?>"><?= e($partner['phone']) ?></a></dd>

      <?php if ($partner['partner_code']): ?>
        <dt>Reference</dt><dd><?= e($partner['partner_code']) ?></dd>
      <?php endif; ?>

      <dt>Rate</dt>
      <dd><?= e($rateOf((float) $partner['default_rate'])) ?>
        <span class="text-muted">unless a service carries its own</span></dd>

      <dt>Looked after by</dt>
      <dd>
        <?php if ($managerName !== ''): ?>
          <?= e($managerName) ?>
          <?php if ($partner['assigned_at']): ?>
            <span class="text-muted">since <?= e(fdate($partner['assigned_at'])) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">Nobody yet</span>
        <?php endif; ?>
      </dd>

      <dt>With us since</dt>
      <dd><?= e(fdate($partner['created_at'])) ?></dd>
    </dl>
  </div>

  <div class="partner-head__money">
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
</div>

<?php // ---- Everything stopping a payment, in one list ------------------
      // Above the tabs for the same reason the pending decision is: it is
      // work waiting on somebody. The whole list rather than the first
      // problem, so it can be fixed in one conversation instead of four
      // trips round the payout run. ?>
<?php if ($blocked): ?>
  <div class="alert alert--warning mb-16">
    <div>
      <strong>
        <?= count($blockers) === 1 ? 'One thing is' : count($blockers) . ' things are' ?>
        stopping <?= e($partner['name']) ?> being paid<?= $summary['due'] > 0.009
          ? ', and they are owed ' . e(money($summary['due'], false)) : '' ?>.
      </strong>
      <ul class="mb-0 mt-4">
        <?php foreach ($blockers as $problem): ?>
          <li><?= e($problem) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="text-xs mb-0 mt-8">
        A payout run puts them in as a held line with the first of these
        written on it, so nothing is lost — but nothing is sent either.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php // ---- Worth chasing, but it does not stop the money --------------
      // Kept apart from the list above so that "cannot be paid" stays
      // true when it is said. A partner with no tax number on file is
      // paid perfectly well; somebody should still go and get it. ?>
<?php if ($warnings && $partner['status'] === 'active'): ?>
  <div class="alert alert--info mb-16">
    <div>
      <strong>Worth chasing.</strong>
      None of this stops a payment going out.
      <ul class="mb-0 mt-4">
        <?php foreach ($warnings as $warning): ?>
          <li><?= e($warning) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<?php // An application waiting on a decision is the only thing on this
      // page that is actually waiting on somebody, so it sits above the
      // tabs where it cannot be scrolled past. ?>
<?php if ($pending): ?>
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

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $tab === 'overview' ? 'is-active' : '' ?>" href="<?= e($tabUrl('overview')) ?>">
      Overview
    </a>
    <a class="tab <?= $tab === 'money' ? 'is-active' : '' ?>" href="<?= e($tabUrl('money')) ?>">
      Commission
      <?php if (!empty($months)): ?><span class="tab__count"><?= count($months) ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $tab === 'customers' ? 'is-active' : '' ?>" href="<?= e($tabUrl('customers')) ?>">
      Customers
      <?php if (!empty($customers)): ?><span class="tab__count"><?= count($customers) ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $tab === 'entries' ? 'is-active' : '' ?>" href="<?= e($tabUrl('entries')) ?>">
      Every entry
    </a>
    <?php // Its own page rather than a tab: it is a long form with a search
          // over it, and it is where a negotiation gets written down. ?>
    <a class="tab" href="<?= url('/partners-admin/' . (int) $partner['id'] . '/rates') ?>">
      What they earn
      <?php if (!empty($rateCount)): ?><span class="tab__count"><?= (int) $rateCount ?></span><?php endif; ?>
    </a>
    <?php if (Auth::can('partners.manage') || Auth::can('partners.assign')): ?>
      <a class="tab <?= $tab === 'details' ? 'is-active' : '' ?>" href="<?= e($tabUrl('details')) ?>">
        Details
      </a>
    <?php endif; ?>
  </nav>
</div>

<?php // Overview: what they sell for us and what renews, which is what
      // whoever looks after the relationship came to see. ?>
<?php if ($tab === 'overview'): ?>

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

<?php endif; ?>

<?php // The money, month by month, and the exception that settles the lot. ?>
<?php if ($tab === 'money'): ?>

<?php // ---- Where the money goes ----------------------------------------
      // First on the tab because a payment with nowhere to go is the one
      // thing that stops a run, and it is invisible until payment day. ?>
<?php
$payMethod = (string) ($partner['pay_method'] ?? '');

// Asked of the one place that knows what each method needs, rather than
// re-derived here. The view used to work this out itself and only knew
// about M-Pesa and a Kenyan bank.
$payProblems = Partners::destinationBlockers($partner);
$payReady    = $payMethod !== '' && $payProblems === [];

// A field set is shown for the chosen method and hidden for the others.
// Hidden rather than absent, so switching method does not lose what has
// already been typed into the form.
$shows = static fn(array $methods): string
    => in_array($payMethod, $methods, true) ? '' : ' hidden';
?>
<div class="card">
  <div class="card__head">
    <div class="card__title">How they are paid</div>
    <?php if (!$payReady): ?>
      <span class="badge badge--red">Cannot be paid yet</span>
    <?php else: ?>
      <span class="badge badge--green"><?= e(Partners::payMethodLabel($payMethod)) ?></span>
      <?php if ($currency !== Partners::baseCurrency()): ?>
        <span class="badge badge--blue">in <?= e($currency) ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if (!Auth::can('partners.pay')): ?>
    <div class="card__body text-sm">
      <?php if (!$payReady): ?>
        <span class="text-muted">
          <?= $payProblems ? e($payProblems[0]) . '.' : 'No payment details on file.' ?>
          They cannot be included in a run. Finance sets these.
        </span>
      <?php else: ?>
        <?= e(Partners::payMethodLabel($payMethod)) ?>
        <?php if ($payMethod === 'mpesa'): ?>
          &middot; <?= e($partner['pay_phone'] ?: $partner['phone']) ?>
        <?php elseif (in_array($payMethod, ['wise', 'paypal'], true)): ?>
          &middot; <?= e($partner['pay_email']) ?>
        <?php else: ?>
          &middot; <?= e($partner['bank_name'] ?: 'Bank') ?>
          <div class="text-xs text-muted mt-4">
            <?= e($partner['bank_account_name'] ?: '') ?>
            <?php // Never the full number to somebody without the pay
                  // right: they can see that a destination exists, not
                  // what it is. ?>
            <?php $acc = (string) ($partner['bank_iban'] ?: $partner['bank_account_no']); ?>
            <?php if ($acc !== ''): ?>
              &middot; &hellip;<?= e(substr($acc, -4)) ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="text-xs text-muted mt-4">Paid in <?= e($currency) ?>.</div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card__body">
      <form method="post" action="<?= url('/partners-admin/' . (int) $partner['id'] . '/pay-details') ?>"
            id="payForm">
        <?= csrf_field() ?>

        <div class="grid-2">
          <div class="field">
            <label class="label" for="pay_method">Method</label>
            <select class="select" id="pay_method" name="pay_method">
              <option value="">Not set — cannot be paid</option>
              <?php // Only what works from where we bank to where they
                    // are. M-Pesa reaches Kenyan numbers and nowhere
                    // else; a Kenyan bank file cannot address an account
                    // in Dubai. Offering either abroad would just produce
                    // a payment that fails days later. ?>
              <?php foreach ($payMethods as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $payMethod === $code ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="field-hint">
              <?= e($countryName) ?>, so these are the ways we can send it.
            </div>
          </div>

          <div class="field">
            <label class="label" for="payout_currency">Paid in</label>
            <select class="select" id="payout_currency" name="payout_currency">
              <?php foreach ($currencies as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $currency === $code ? 'selected' : '' ?>>
                  <?= e($code) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="field-hint">
              <?php if ($crossRate !== null): ?>
                Converted at <?= e(Partners::baseCurrency()) ?>
                <?= e(rtrim(rtrim(number_format((float) $crossRate, 6), '0'), '.')) ?>
                per <?= e($currency) ?>, as at the day a run is built.
                <a href="<?= url('/partners-admin/fx') ?>">Rates</a>.
              <?php elseif ($currency !== Partners::baseCurrency()): ?>
                <span class="text-red">
                  No rate on file for <?= e($currency) ?> — a run will hold
                  their line. <a href="<?= url('/partners-admin/fx') ?>">Enter one</a>.
                </span>
              <?php else: ?>
                Commission is earned in <?= e(Partners::baseCurrency()) ?>, so
                nothing is converted.
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php // ---- M-Pesa ------------------------------------------- ?>
        <div class="field pay-fields" data-for="mpesa"<?= $shows(['mpesa']) ?>>
          <label class="label" for="pay_phone">M-Pesa number</label>
          <input class="input" type="text" id="pay_phone" name="pay_phone" maxlength="30"
                 value="<?= e($partner['pay_phone'] ?? '') ?>"
                 placeholder="<?= e($partner['phone']) ?>">
          <div class="field-hint">
            Leave it empty to use their contact number,
            <?= e($partner['phone']) ?>. Money often goes to a different
            line from the one they answer.
          </div>
        </div>

        <?php // ---- Wallets ------------------------------------------ ?>
        <div class="field pay-fields" data-for="wise paypal"<?= $shows(['wise', 'paypal']) ?>>
          <label class="label" for="pay_email">Payment address</label>
          <input class="input" type="email" id="pay_email" name="pay_email" maxlength="160"
                 value="<?= e($partner['pay_email'] ?? '') ?>"
                 placeholder="<?= e($partner['email']) ?>">
          <div class="field-hint">
            The address the wallet is registered to, which is often not
            the one they write to us from.
          </div>
        </div>

        <?php // ---- A bank, local or not ----------------------------- ?>
        <div class="pay-fields" data-for="bank wire"<?= $shows(['bank', 'wire']) ?>>
          <div class="grid-2">
            <div class="field">
              <label class="label" for="bank_name">Bank</label>
              <input class="input" type="text" id="bank_name" name="bank_name" maxlength="120"
                     value="<?= e($partner['bank_name'] ?? '') ?>">
            </div>
            <div class="field">
              <label class="label" for="bank_branch">Branch</label>
              <input class="input" type="text" id="bank_branch" name="bank_branch" maxlength="120"
                     value="<?= e($partner['bank_branch'] ?? '') ?>">
            </div>
            <div class="field">
              <label class="label" for="bank_account_name">Account name</label>
              <input class="input" type="text" id="bank_account_name" name="bank_account_name" maxlength="160"
                     value="<?= e($partner['bank_account_name'] ?? '') ?>">
              <div class="field-hint">As the bank holds it, which is not always the trading name.</div>
            </div>
            <div class="field">
              <label class="label" for="bank_account_no">Account number</label>
              <input class="input" type="text" id="bank_account_no" name="bank_account_no" maxlength="40"
                     value="<?= e($partner['bank_account_no'] ?? '') ?>">
            </div>
          </div>
        </div>

        <?php // ---- What only a wire needs --------------------------- ?>
        <div class="pay-fields" data-for="wire"<?= $shows(['wire']) ?>>
          <div class="grid-2">
            <div class="field">
              <label class="label" for="bank_swift">
                SWIFT / BIC
                <span class="label__hint">required — the wire cannot be addressed without it</span>
              </label>
              <input class="input" type="text" id="bank_swift" name="bank_swift" maxlength="20"
                     style="text-transform:uppercase"
                     value="<?= e($partner['bank_swift'] ?? '') ?>">
            </div>
            <div class="field">
              <label class="label" for="bank_iban">IBAN</label>
              <input class="input" type="text" id="bank_iban" name="bank_iban" maxlength="40"
                     style="text-transform:uppercase"
                     value="<?= e($partner['bank_iban'] ?? '') ?>">
              <div class="field-hint">
                Used in preference to the account number where there is one.
              </div>
            </div>
            <div class="field">
              <label class="label" for="bank_routing">
                Routing code
                <span class="label__hint">ABA, sort code, IFSC</span>
              </label>
              <input class="input" type="text" id="bank_routing" name="bank_routing" maxlength="40"
                     value="<?= e($partner['bank_routing'] ?? '') ?>">
            </div>
            <div class="field">
              <label class="label" for="bank_country">Where the account is</label>
              <select class="select" id="bank_country" name="bank_country">
                <option value="">Same as the partner</option>
                <?php foreach ($countries as $code => $name): ?>
                  <option value="<?= e($code) ?>"
                          <?= ($partner['bank_country'] ?? '') === $code ? 'selected' : '' ?>>
                    <?= e($name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-hint">Not always where the partner is.</div>
            </div>
          </div>
          <div class="field">
            <label class="label" for="bank_address">Bank address</label>
            <input class="input" type="text" id="bank_address" name="bank_address" maxlength="200"
                   value="<?= e($partner['bank_address'] ?? '') ?>">
            <div class="field-hint">
              The branch address a correspondent bank asks for on the instruction.
            </div>
          </div>
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit">
            <?= icon('check') ?> Save payment details
          </button>
          <span class="text-xs text-muted">
            The partner cannot change these themselves, on purpose.
          </span>
        </div>
      </form>

      <?php // Only the fields the chosen method needs. Progressive: with
            // no JavaScript every field set is visible and the form still
            // saves, which is why the server checks the method too.
            //
            // The nonce is not optional: there is a Content-Security-Policy
            // on every response, and an inline script without it is dropped
            // in silence, which looks exactly like a script that does
            // nothing. ?>
      <script nonce="<?= e(csp_nonce()) ?>">
        (function () {
          var form = document.getElementById('payForm');
          if (!form) { return; }

          var select = form.querySelector('#pay_method');
          var sets   = form.querySelectorAll('.pay-fields');

          function sync() {
            var chosen = select.value;
            sets.forEach(function (set) {
              var methods = (set.getAttribute('data-for') || '').split(' ');
              set.hidden = methods.indexOf(chosen) === -1;
            });
          }

          select.addEventListener('change', sync);
          sync();
        })();
      </script>
    </div>
  <?php endif; ?>
</div>

<?php // ---- What is held back ------------------------------------------
      // Shown whenever withholding is on, including at zero, because
      // "nothing is withheld from this one" is a decision somebody made
      // and should be able to see. ?>
<?php if ($whtOn): ?>
  <div class="card">
    <div class="card__head">
      <div class="card__title">Withholding</div>
      <span class="badge badge--<?= $whtRate > 0 ? 'amber' : 'grey' ?>">
        <?= e(rtrim(rtrim(number_format($whtRate, 2), '0'), '.')) ?>%
      </span>
    </div>
    <div class="card__body text-sm">
      <?php if ($whtRate > 0): ?>
        <p class="mb-4">
          <?= e(rtrim(rtrim(number_format($whtRate, 2), '0'), '.')) ?>% is
          deducted from each payout and remitted to KRA on their behalf.
          They still earn the gross — it is on their statement — and the
          payout shows both figures.
        </p>
        <p class="text-xs text-muted mb-0">
          <?= $partner['withholding_rate'] !== null
              ? 'This is a rate set for this partner specifically, which is what a double taxation treaty needs.'
              : 'From the ' . ($partner['tax_residency'] === 'non_resident' ? 'non-resident' : 'resident')
                . ' rate in settings. A rate can be set for this partner alone on the Details tab.' ?>
        </p>
      <?php else: ?>
        <p class="mb-0 text-muted">
          Nothing is withheld from this partner. They are paid the gross.
        </p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

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

<?php // ---- What we have actually sent them -----------------------------
      // The table above says what is owed and what has been marked paid.
      // This says where the money went, which is the question asked when
      // somebody rings to say they have not received it. ?>
<?php if (!empty($payouts)): ?>
  <div class="card">
    <div class="card__head">
      <div class="card__title">Payments to them</div>
      <a class="btn btn--ghost btn--sm" href="<?= url('/payouts') ?>">
        <?= icon('dollar') ?> All payouts
      </a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:140px">Month</th>
            <th style="width:140px" class="num">Amount</th>
            <th style="width:150px">Sent to</th>
            <th style="width:150px">State</th>
            <th>Reference</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payouts as $pay): ?>
            <?php
            $t = strtotime($pay['period'] . '-01');
            [$payCls, $payLabel] = match ((string) $pay['status']) {
                'pending' => ['badge--grey',  'Waiting'],
                'sent'    => ['badge--amber', 'With the bank'],
                'settled' => ['badge--green', 'Paid'],
                'failed'  => ['badge--red',   'Did not go through'],
                'held'    => ['badge--navy',  'Held'],
                default   => ['badge--grey',  ucfirst((string) $pay['status'])],
            };
            ?>
            <tr>
              <td>
                <a href="<?= url('/payouts/' . (int) $pay['run_id']) ?>" class="fw-600">
                  <?= e($t ? date('F Y', $t) : $pay['period']) ?>
                </a>
              </td>
              <td class="num fw-700"><?= e(money($pay['amount'], false)) ?></td>
              <td class="text-sm text-muted">
                <?php if ($pay['method']): ?>
                  <?= $pay['method'] === 'mpesa' ? 'M-Pesa' : 'Bank' ?>
                  <div class="text-xs"><?= e($pay['destination']) ?></div>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= e($payCls) ?>"><?= e($payLabel) ?></span>
                <?php if (!empty($pay['failure_reason'])): ?>
                  <div class="text-xs text-muted mt-4"><?= e($pay['failure_reason']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-xs">
                <?= e($pay['ref'] ?: '—') ?>
                <?php if ($pay['settled_at']): ?>
                  <div class="table__muted"><?= e(fdate($pay['settled_at'])) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

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
        For money that went out some other way — cash in hand, or a transfer
        made outside the monthly file. It records a payment per month owed,
        against this one reference, so it reconciles exactly as a run does.
        Anything already in an open run is left alone, and what has been
        paid is never touched. The usual route is
        <a href="<?= url('/payouts') ?>">the monthly payout</a>.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php if ($tab === 'customers'): ?>

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

<?php endif; ?>

<?php if ($tab === 'entries'): ?>

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

<?php endif; ?>

<?php // Editing, kept away from a page people mostly come to read. ?>
<?php if ($tab === 'details'): ?>

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

<?php if (Auth::can('partners.manage')): ?>
  <div class="card">
    <div class="card__head"><div class="card__title">Their details</div></div>
    <div class="card__body">
      <form method="post" action="<?= url('/partners-admin/' . (int) $partner['id']) ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
          <?php // Three parts rather than one box: this is the name that
                // has to match an ID and a KRA certificate when money
                // moves. The display name is written from them. ?>
          <div class="field">
            <label class="label" for="first_name">First name</label>
            <input class="input" type="text" id="first_name" name="first_name" required maxlength="60"
                   value="<?= e($partner['first_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="middle_name">Second name</label>
            <input class="input" type="text" id="middle_name" name="middle_name" maxlength="60"
                   value="<?= e($partner['middle_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="last_name">Third name</label>
            <input class="input" type="text" id="last_name" name="last_name" required maxlength="60"
                   value="<?= e($partner['last_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="id_number">ID number</label>
            <input class="input" type="text" id="id_number" name="id_number" maxlength="30"
                   value="<?= e($partner['id_number'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="occupation">Occupation</label>
            <input class="input" type="text" id="occupation" name="occupation" maxlength="120"
                   value="<?= e($partner['occupation'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="company">Business</label>
            <input class="input" type="text" id="company" name="company" maxlength="180"
                   value="<?= e($partner['company']) ?>">
          </div>
          <div class="field">
            <label class="label" for="office_location">Office location</label>
            <input class="input" type="text" id="office_location" name="office_location" maxlength="200"
                   value="<?= e($partner['office_location'] ?? '') ?>">
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
            <label class="label" for="country">Country</label>
            <select class="select" id="country" name="country">
              <?php foreach ($countries as $code => $name): ?>
                <option value="<?= e($code) ?>" <?= $partner['country'] === $code ? 'selected' : '' ?>>
                  <?= e($name) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">
              Decides how they can be paid and what we have to withhold.
            </span>
          </div>
          <div class="field">
            <label class="label" for="city">City</label>
            <input class="input" type="text" id="city" name="city" maxlength="90"
                   value="<?= e($partner['city'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="timezone">Time zone</label>
            <select class="select" id="timezone" name="timezone">
              <option value="">Not set</option>
              <?php foreach (timezone_identifiers_list() as $tz): ?>
                <option value="<?= e($tz) ?>" <?= ($partner['timezone'] ?? '') === $tz ? 'selected' : '' ?>>
                  <?= e($tz) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">So nobody rings them at three in the morning.</span>
          </div>
          <div class="field">
            <label class="label" for="territory">Territory</label>
            <input class="input" type="text" id="territory" name="territory" maxlength="140"
                   value="<?= e($partner['territory'] ?? '') ?>"
                   placeholder="e.g. Uganda and Rwanda">
            <span class="field-hint">Where they may sell. Blank means no restriction.</span>
          </div>
          <div class="field">
            <label class="label" for="partner_type">Type</label>
            <select class="select" id="partner_type" name="partner_type">
              <?php foreach (Partners::TYPES as $code => $desc): ?>
                <option value="<?= e($code) ?>"
                        <?= $partner['partner_type'] === $code ? 'selected' : '' ?>>
                  <?= e($desc) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="tier">Tier</label>
            <select class="select" id="tier" name="tier">
              <?php foreach ($tiers as $code => $t): ?>
                <option value="<?= e($code) ?>" <?= $partner['tier'] === $code ? 'selected' : '' ?>>
                  <?= e($t['name']) ?>
                  (<?= e(rtrim(rtrim(number_format((float) $t['default_rate'], 2), '0'), '.')) ?>%)
                </option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">
              <?php // The tier they have earned against the one they are on.
                    // Nothing moves on its own: a partner can be left at a
                    // tier they have outgrown while a contract is sorted
                    // out, and a quiet year does not demote anybody. ?>
              <?php if ($earnedTier && $earnedTier['code'] !== $partner['tier']): ?>
                <strong>Has earned <?= e($earnedTier['name']) ?></strong> on
                <?= e(money($billed12m, false)) ?> billed in twelve months.
              <?php else: ?>
                <?= e(money($billed12m, false)) ?> billed in the last twelve months.
              <?php endif; ?>
            </span>
          </div>
          <div class="field">
            <label class="label" for="kra_pin">
              KRA PIN
              <?php if (!$isLocal): ?>
                <span class="label__hint">only if they have one</span>
              <?php endif; ?>
            </label>
            <input class="input" type="text" id="kra_pin" name="kra_pin" maxlength="30"
                   value="<?= e($partner['kra_pin']) ?>">
          </div>
          <div class="field">
            <label class="label" for="tax_id"><?= e($taxLabel) ?></label>
            <input class="input" type="text" id="tax_id" name="tax_id" maxlength="40"
                   value="<?= e($partner['tax_id'] ?? '') ?>">
            <span class="field-hint">
              What the authority where they are calls their tax number.
            </span>
          </div>
          <div class="field">
            <label class="label" for="tax_residency">Tax residency</label>
            <select class="select" id="tax_residency" name="tax_residency">
              <option value="resident" <?= $partner['tax_residency'] === 'resident' ? 'selected' : '' ?>>
                Resident for Kenyan tax
              </option>
              <option value="non_resident" <?= $partner['tax_residency'] === 'non_resident' ? 'selected' : '' ?>>
                Non-resident
              </option>
            </select>
            <span class="field-hint">
              <?php // Not the same question as the country, which is why it
                    // is asked separately: a Kenyan-registered company owned
                    // from abroad is resident, and a Kenyan who has moved
                    // away is not. ?>
              Not simply their country — a Kenyan-registered company owned
              from abroad is still resident.
            </span>
          </div>
          <div class="field">
            <label class="label" for="withholding_rate">
              Withholding
              <span class="label__hint">blank = the rate for their residency</span>
            </label>
            <div class="input-group">
              <input class="input" type="number" id="withholding_rate" name="withholding_rate"
                     step="0.01" min="0" max="100"
                     value="<?= $partner['withholding_rate'] === null ? '' : e(rtrim(rtrim(number_format((float) $partner['withholding_rate'], 2), '0'), '.')) ?>"
                     placeholder="<?= e(rtrim(rtrim(number_format($whtRate, 2), '0'), '.')) ?>">
              <span class="input-group__addon">%</span>
            </div>
            <span class="field-hint">
              Set it only where a treaty gives this partner a rate of its
              own. A typed 0 means withhold nothing, which is not the same
              as leaving it blank.
            </span>
          </div>
          <div class="field">
            <label class="label" for="agreement_ref">Agreement reference</label>
            <input class="input" type="text" id="agreement_ref" name="agreement_ref" maxlength="60"
                   value="<?= e($partner['agreement_ref'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="agreement_signed_at">Signed on</label>
            <input class="input" type="date" id="agreement_signed_at" name="agreement_signed_at"
                   value="<?= e($partner['agreement_signed_at'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label" for="agreement_expires_at">Runs until</label>
            <input class="input" type="date" id="agreement_expires_at" name="agreement_expires_at"
                   value="<?= e($partner['agreement_expires_at'] ?? '') ?>">
            <span class="field-hint">
              Blank means it does not expire. An expired agreement holds
              the payout rather than paying against nothing.
            </span>
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

<?php endif; ?>
