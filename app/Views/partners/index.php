<?php
/**
 * The partner list.
 *
 * Two filters down the page rather than one: the status tabs, which say
 * where a partner is in the process, and a row of place filters, which
 * say where in the world they are. They compose — "waiting, in Uganda"
 * is a question somebody asks — so they are separate controls rather
 * than one long list of tabs.
 */
require_once APP_PATH . '/Views/partials/icons.php';
require_once APP_PATH . '/Views/partials/partner_bits.php';

use App\Services\Partners;

$tabs = [
  ''          => ['All',         $counts['all']],
  'pending'   => ['Waiting',     $counts['pending']],
  'active'    => ['Active',      $counts['active']],
  'suspended' => ['Suspended',   $counts['suspended']],
  'rejected'  => ['Turned down', $counts['rejected']],
];

$tone = static fn(string $s): string => match ($s) {
  'active'    => 'green',
  'pending'   => 'amber',
  'suspended' => 'red',
  default     => 'grey',
};

/* A link that keeps whatever else is already filtered. Without this,
   picking a country throws away the status you were looking at. */
$keep = static function (array $change) use ($status, $mine, $country, $tier, $type): string {
    $q = array_filter([
        'status'  => $status,
        'mine'    => $mine ? '1' : '',
        'country' => $country,
        'tier'    => $tier,
        'type'    => $type,
    ] + [], static fn($v) => $v !== '' && $v !== null);

    foreach ($change as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }

    return url('/partners-admin' . ($q ? '?' . http_build_query($q) : ''));
};

$flag = static fn(?string $code): string => Partners::countryName($code);
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Partners</h1>
    <div class="page-head__sub">
      People who bring us customers, wherever they are, and what we owe them
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= url('/partners-admin/tiers') ?>">
      <?= icon('award') ?> Tiers
    </a>
    <a class="btn btn--ghost" href="<?= url('/partners-admin/fx') ?>">
      <?= icon('globe') ?> Exchange rates
    </a>
    <a class="btn btn--ghost" href="<?= url('/partners-admin/runs') ?>">
      <?= icon('calendar') ?> Commission run
    </a>
    <?php if (\App\Core\Auth::can('partners.create')): ?>
      <a class="btn btn--primary" href="<?= url('/partners-admin/new') ?>">
        <?= icon('user-plus') ?> Register a partner
      </a>
    <?php endif; ?>
  </div>
</div>

<?php // ---- Things that will stop a payout, said before the run ---------
      // A rate nobody has entered holds a line silently in the middle of
      // a payout run. It is cheap to say so here instead. ?>
<?php if ($missingFx): ?>
  <div class="alert alert--warning mb-16">
    <div>
      <strong>No exchange rate on file for
        <?= e(implode(', ', $missingFx)) ?>.</strong>
      Partners paid in <?= count($missingFx) === 1 ? 'it' : 'them' ?> cannot
      be paid until there is one — the run would hold their lines rather
      than guess a rate.
      <a href="<?= url('/partners-admin/fx') ?>">Enter the rate</a>.
    </div>
  </div>
<?php endif; ?>

<?php if ($expiring): ?>
  <div class="alert alert--info mb-16">
    <div>
      <strong><?= count($expiring) ?>
      partner <?= count($expiring) === 1 ? 'agreement' : 'agreements' ?>
      <?= count($expiring) === 1 ? 'runs' : 'run' ?> out within 60 days:</strong>
      <?php $names = [];
            foreach ($expiring as $x) {
                $names[] = ($x['company'] ?: $x['name']) . ' (' . fdate($x['agreement_expires_at']) . ')';
            } ?>
      <?= e(implode(', ', array_slice($names, 0, 4))) ?><?= count($names) > 4 ? ', and ' . (count($names) - 4) . ' more' : '' ?>.
      An expired agreement holds the payout, so it is worth renewing before it bites.
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <nav class="tabs">
    <?php foreach ($tabs as $k => [$label, $n]): ?>
      <a class="tab <?= (!$mine && $status === $k) ? 'is-active' : '' ?>"
         href="<?= e($keep(['status' => $k, 'mine' => ''])) ?>">
        <?= e($label) ?>
        <?php if ($n): ?><span class="tab__count"><?= (int) $n ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>

    <?php // Sales are the contact point for named partners, so "mine" is a
          // real filter for them rather than a convenience. ?>
    <a class="tab <?= $mine ? 'is-active' : '' ?>"
       href="<?= e($keep(['mine' => '1', 'status' => ''])) ?>">
      Looked after by me
      <?php if (!empty($counts['mine'])): ?>
        <span class="tab__count"><?= (int) $counts['mine'] ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <?php // ---- Where in the world ---------------------------------------
        // Only shown once there is somebody outside the home country to
        // filter for. Before that it is a control with one option. ?>
  <?php if (!empty($counts['international']) || $country !== '' || $tier !== '' || $type !== ''): ?>
    <div class="card__body" style="border-top:1px solid var(--border);padding:12px 16px">
      <div class="row-form" style="gap:10px;flex-wrap:wrap;align-items:center">

        <span class="text-xs text-muted">Where</span>
        <div class="chip-row">
          <a class="chip <?= $country === '' ? 'is-active' : '' ?>"
             href="<?= e($keep(['country' => ''])) ?>">Everywhere</a>
          <a class="chip <?= $country === $home ? 'is-active' : '' ?>"
             href="<?= e($keep(['country' => $home])) ?>"><?= e($flag($home)) ?></a>
          <a class="chip <?= $country === 'INTL' ? 'is-active' : '' ?>"
             href="<?= e($keep(['country' => 'INTL'])) ?>">
            International
            <span class="chip__count"><?= (int) $counts['international'] ?></span>
          </a>
        </div>

        <span class="text-xs text-muted" style="margin-left:8px">Tier</span>
        <div class="chip-row">
          <a class="chip <?= $tier === '' ? 'is-active' : '' ?>"
             href="<?= e($keep(['tier' => ''])) ?>">Any</a>
          <?php foreach ($tiers as $code => $t): ?>
            <a class="chip <?= $tier === $code ? 'is-active' : '' ?>"
               href="<?= e($keep(['tier' => $code])) ?>"><?= e($t['name']) ?></a>
          <?php endforeach; ?>
        </div>

        <span class="text-xs text-muted" style="margin-left:8px">Type</span>
        <div class="chip-row">
          <a class="chip <?= $type === '' ? 'is-active' : '' ?>"
             href="<?= e($keep(['type' => ''])) ?>">Any</a>
          <?php foreach (Partners::TYPES as $code => $desc): ?>
            <a class="chip <?= $type === $code ? 'is-active' : '' ?>"
               href="<?= e($keep(['type' => $code])) ?>"
               title="<?= e($desc) ?>"><?= e(ucfirst($code)) ?></a>
          <?php endforeach; ?>
        </div>

        <?php // One specific country, for when the chips are not enough.
              // A plain GET form, so the chosen country is in the URL like
              // every other filter on this page. ?>
        <form method="get" action="<?= url('/partners-admin') ?>" class="row-form" style="gap:6px">
          <?php foreach (['status' => $status, 'tier' => $tier, 'type' => $type] as $k => $v): ?>
            <?php if ($v !== ''): ?>
              <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
            <?php endif; ?>
          <?php endforeach; ?>
          <select class="select select--sm" name="country" onchange="this.form.submit()"
                  aria-label="Filter by country">
            <option value="">A country…</option>
            <?php foreach ($countries as $code => $name): ?>
              <option value="<?= e($code) ?>" <?= $country === $code ? 'selected' : '' ?>>
                <?= e($name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="card">
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('users') ?></div>
      <div class="card__title mt-8">Nobody here</div>
      <p class="text-sm text-muted mb-0">
        <?php if ($country !== '' || $tier !== '' || $type !== ''): ?>
          Nothing matches that filter.
          <a href="<?= url('/partners-admin') ?>">Clear it</a>.
        <?php else: ?>
          Applications arrive from the partner sign-in page, and from the
          programme page on the website.
        <?php endif; ?>
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
            <th style="width:150px">Where</th>
            <th style="width:120px">Tier &amp; type</th>
            <th style="width:80px" class="num">Rate</th>
            <th style="width:140px">Looked after by</th>
            <th style="width:90px" class="num">Customers</th>
            <th style="width:130px" class="num">Owed</th>
            <th style="width:110px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <?php $abroad = !Partners::isLocal((string) $p['country']); ?>
            <tr>
              <td>
                <?php // The monogram, ringed in the partner's tier colour.
                      // A list of names is hard to scan; a list of marks
                      // is not, and the ring means the tier is legible
                      // before the tier column is reached. ?>
                <div class="pnr-row">
                  <?= partner_mark($p, 'sm') ?>
                  <div class="pnr-row__text">
                    <a class="pnr-row__name" href="<?= url('/partners-admin/' . (int) $p['id']) ?>">
                      <?= e($p['company'] ?: $p['name']) ?>
                    </a>
                    <div class="pnr-row__meta">
                      <?= e($p['partner_code'] ?: $p['name']) ?> &middot; <?= e($p['email']) ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="text-xs">
                <?= e(Partners::countryName((string) $p['country'])) ?>
                <?php if ($p['city']): ?>
                  <div class="table__muted"><?= e($p['city']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-xs">
                <?= partner_tier_pill((string) $p['tier']) ?>
                <div class="table__muted mt-4"><?= e(ucfirst((string) $p['partner_type'])) ?></div>
              </td>
              <td class="num"><?= e(rtrim(rtrim(number_format((float) $p['default_rate'], 2), '0'), '.')) ?>%</td>
              <td class="text-xs">
                <?php if ($p['manager_name']): ?>
                  <?= e($p['manager_name']) ?>
                <?php else: ?>
                  <span class="text-muted">Nobody</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $p['customers'] ?></td>
              <td class="num <?= (float) $p['due'] > 0.009 ? 'fw-700' : 'text-muted' ?>">
                <?= e(money($p['due'], false)) ?>
                <?php // What they are owed is in KES because that is what
                      // the customer paid. Saying what it leaves as saves
                      // somebody opening the partner to find out. ?>
                <?php if ($abroad && $p['payout_currency'] !== Partners::baseCurrency()): ?>
                  <div class="table__muted">paid in <?= e($p['payout_currency']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge badge--<?= e($tone((string) $p['status'])) ?>">
                  <?= e(label_of((string) $p['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php // ---- The shape of the programme, by country ---------------------
      // Last on the page, because it answers a question somebody asks
      // occasionally and the list above answers the one they came for. ?>
<?php if (count($byCountry) > 1): ?>
  <div class="card mt-16">
    <div class="card__head">
      <h3 class="card__title">Where we are selling</h3>
      <p class="text-xs text-muted mb-0">
        Commission is what each country's partners have earned in total,
        in <?= e(Partners::baseCurrency()) ?>, whatever they were paid in.
      </p>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Country</th>
            <th style="width:100px" class="num">Partners</th>
            <th style="width:90px" class="num">Active</th>
            <th style="width:90px" class="num">Waiting</th>
            <th style="width:140px" class="num">Commission earned</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($byCountry as $row): ?>
            <tr>
              <td>
                <a href="<?= e($keep(['country' => (string) $row['country'], 'tier' => '', 'type' => ''])) ?>">
                  <?= e(Partners::countryName((string) $row['country'])) ?>
                </a>
                <?php if (Partners::isLocal((string) $row['country'])): ?>
                  <span class="badge badge--grey">Home</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $row['partners'] ?></td>
              <td class="num"><?= (int) $row['active'] ?></td>
              <td class="num <?= (int) $row['pending'] > 0 ? 'fw-700' : 'text-muted' ?>">
                <?= (int) $row['pending'] ?>
              </td>
              <td class="num"><?= e(money($row['commission'], false)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
