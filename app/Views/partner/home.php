<?php
/**
 * The partner overview.
 *
 * Same shape as the client one, answering the question they came with:
 * what am I owed. Everything else on the page is a route to the detail
 * behind that figure.
 */
require_once APP_PATH . '/Views/partials/icons.php';
require_once APP_PATH . '/Views/partials/partner_bits.php';

use App\Services\Partners;

$due    = (float) $summary['due'];
$owed   = $due > 0.009;
$rate   = (float) $me['default_rate'];
$rateOf = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';

$currency = (string) ($me['payout_currency'] ?? Partners::baseCurrency());
$abroad   = !Partners::isLocal((string) ($me['country'] ?? 'KE'));
?>

<div class="portal-wrap">

  <?php // Who they are to us, said the same way we say it on our own
        // side of the desk — the same monogram, the same tier pill. A
        // partner should not have to wonder whether the "Silver" we
        // mentioned on the phone is the Silver on their dashboard. ?>
  <div class="pnr-hero">
    <div class="pnr-hero__who">
      <?= partner_mark($me, 'lg') ?>

      <div class="pnr-hero__text">
        <h1 class="pnr-hero__name">Hello, <?= e(explode(' ', trim($me['name']))[0]) ?>.</h1>

        <div class="pnr-hero__sub">
          <?php if ($me['company']): ?>
            Signed in as <strong><?= e($me['company']) ?></strong> &middot;
          <?php endif; ?>
          Your rate is <strong><?= e($rateOf($rate)) ?></strong>
          unless a service says otherwise.
        </div>

        <div class="pnr-hero__tags">
          <?= partner_tier_pill((string) ($me['tier'] ?? 'registered')) ?>

          <?php if (!empty($me['partner_type'])): ?>
            <span class="badge badge--grey"><?= e(ucfirst((string) $me['partner_type'])) ?></span>
          <?php endif; ?>

          <?php // Only worth saying where it is not the obvious answer. ?>
          <?php if ($abroad || $currency !== Partners::baseCurrency()): ?>
            <span class="badge badge--blue">Paid in <?= e($currency) ?></span>
          <?php endif; ?>

          <?php if (!empty($me['partner_code'])): ?>
            <span class="badge badge--grey"><?= e($me['partner_code']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php // What they have made, with the context line. A new partner
          // seeing three zeroes should be able to tell that is the
          // truth rather than a page that failed to load. ?>
    <div class="pnr-metrics">
      <?= partner_metric(
            money($summary['due'], false),
            'Waiting for you',
            $owed ? 'Paid out next month' : 'Nothing outstanding',
            'owed',
            url('/partners/earnings')
          ) ?>
      <?= partner_metric(
            money($summary['paid'], false),
            'Paid to you',
            $summary['paid'] > 0.009 ? 'Across every payout so far' : 'Nothing sent yet',
            'paid',
            url('/partners/earnings?show=paid')
          ) ?>
      <?= partner_metric(
            (string) (int) $summary['clients'],
            ((int) $summary['clients']) === 1 ? 'Customer' : 'Customers',
            ((int) $summary['clients']) > 0
                ? 'Yours for as long as they stay'
                : 'Introduce your first',
            'people',
            url('/partners/customers')
          ) ?>
    </div>

    <?php // How close the next tier is. The same bar an administrator
          // sees, from the same figure, so the two never disagree. ?>
    <div style="grid-column:1/-1">
      <?= partner_climb((float) ($billed12m ?? 0), (string) ($me['tier'] ?? 'registered')) ?>
    </div>
  </div>

  <?php // What we owe them. Navy in both themes, like the client's balance:
        // this is the number they came to see. ?>
  <?php if ($owed): ?>
    <section class="portal-balance">
      <div class="portal-balance__body">
        <div class="portal-balance__label">Waiting to be paid out</div>
        <div class="portal-balance__figure"><?= e(money($due)) ?></div>
        <div class="portal-balance__note">
          Earned on <?= (int) $summary['invoices'] ?>
          invoice<?= $summary['invoices'] === 1 ? '' : 's' ?> your customers have paid.
        </div>
      </div>
      <div class="portal-balance__act">
        <a class="btn btn--light btn--lg" href="<?= url('/partners/earnings') ?>">
          See the detail
        </a>
      </div>
    </section>
  <?php else: ?>
    <section class="portal-balance portal-balance--clear">
      <div class="portal-balance__body">
        <span class="portal-balance__tick"><?= icon('check-circle') ?></span>
        <div>
          <div class="portal-balance__figure portal-balance__figure--sm">
            <?= $summary['paid'] > 0.009 ? 'Nothing outstanding' : 'Nothing earned yet' ?>
          </div>
          <div class="portal-balance__note">
            <?= $summary['paid'] > 0.009
              ? 'Everything you have earned has been paid out.'
              : 'Commission appears here as soon as one of your customers pays us.' ?>
          </div>
        </div>
      </div>
      <div class="portal-balance__act">
        <a class="btn btn--outline" href="<?= url('/partners/services') ?>">See what we do</a>
      </div>
    </section>
  <?php endif; ?>


  <div class="portal-cols">
    <section class="portal-card portal-card--flush">
      <header class="portal-card__head">
        <h2 class="portal-card__title">Recently earned</h2>
        <a class="portal-card__more" href="<?= url('/partners/earnings') ?>">
          All earnings <?= icon('chevron-right') ?>
        </a>
      </header>

      <?php if (!$recent): ?>
        <div class="portal-empty portal-empty--inline">
          <p class="mb-0 text-sm text-muted">
            Nothing yet. Commission is earned the moment one of your customers
            pays an invoice — not when we raise it.
          </p>
        </div>
      <?php else: ?>
        <ul class="portal-list">
          <?php foreach ($recent as $c): ?>
            <li>
              <span class="portal-list__row">
                <span class="portal-list__main">
                  <span class="portal-list__title"><?= e($c['client_name']) ?></span>
                  <span class="portal-list__meta">
                    <?= e($c['doc_number']) ?> &middot; <?= e(fdate($c['created_at'])) ?>
                    &middot; <?= e($rateOf((float) $c['rate'])) ?>
                  </span>
                </span>
                <span class="portal-list__side">
                  <span class="portal-list__amount"><?= e(money($c['amount'], false)) ?></span>
                  <span class="badge badge--<?= $c['status'] === 'paid' ? 'green' : 'amber' ?>">
                    <?= $c['status'] === 'paid' ? 'Paid out' : 'Owed to you' ?>
                  </span>
                </span>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <div class="portal-side">
      <?php if ($customers): ?>
        <section class="portal-card portal-card--flush">
          <header class="portal-card__head">
            <h2 class="portal-card__title">Your best customers</h2>
            <a class="portal-card__more" href="<?= url('/partners/customers') ?>">
              All <?= icon('chevron-right') ?>
            </a>
          </header>
          <ul class="portal-list portal-list--tight">
            <?php foreach ($customers as $c): ?>
              <li>
                <span class="portal-list__row">
                  <span class="portal-list__main">
                    <span class="portal-list__title"><?= e($c['name']) ?></span>
                    <span class="portal-list__meta">
                      Yours since <?= e(fdate($c['partner_linked_at'])) ?>
                    </span>
                  </span>
                  <span class="portal-list__side">
                    <span class="portal-list__amount"><?= e(money($c['earned'], false)) ?></span>
                  </span>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <section class="portal-card">
        <h2 class="portal-card__title mb-12">Earn more</h2>
        <div class="portal-acts">
          <a class="portal-act" href="<?= url('/partners/refer') ?>">
            <?= icon('user-plus') ?>
            <span>
              <strong>Introduce a customer</strong>
              <em>Tell us who they are and we take it from there</em>
            </span>
          </a>
          <a class="portal-act" href="<?= url('/partners/services') ?>">
            <?= icon('package') ?>
            <span>
              <strong>What we do</strong>
              <em>Our services, our prices, and what each pays you</em>
            </span>
          </a>
        </div>
      </section>
    </div>
  </div>

  <p class="portal-help">
    Questions about a payout? Call us on
    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $company['phone'])) ?>"><?= e($company['phone']) ?></a>
    or email <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>.
  </p>
</div>
