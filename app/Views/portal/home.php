<?php
/**
 * The overview.
 *
 * The page a client lands on, so it answers what they came for in the order
 * they care about it: what is owed, what is waiting on them, what happens
 * next. The balance panel is the only thing on the page allowed to shout.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$owing  = (float) $summary['owing'];
$owes   = $owing > 0.009;
$payDoc = $summary['pay_invoice'] ?? null;

// What a status means to the person reading it, rather than to us.
$tone = static fn(string $s): string => match ($s) {
    'paid', 'accepted'                 => 'green',
    'overdue'                          => 'red',
    'partial'                          => 'amber',
    'rejected', 'expired', 'cancelled' => 'grey',
    default                            => 'navy',
};
?>

<div class="portal-wrap">

  <div class="portal-hello">
    <h1 class="portal-h1">Hello, <?= e(explode(' ', trim($me['name']))[0]) ?>.</h1>
    <?php if ($me['client_name']): ?>
      <p class="portal-lede">
        You are signed in to <strong><?= e($me['client_name']) ?></strong>.
      </p>
    <?php endif; ?>
  </div>

  <?php // The balance, and the one thing worth doing about it. Navy in both
        // themes, because this is the brand's moment on the page and it
        // should not change character when somebody switches to light. ?>
  <?php if ($owes): ?>
    <section class="portal-balance">
      <div class="portal-balance__body">
        <div class="portal-balance__label">Outstanding balance</div>
        <div class="portal-balance__figure"><?= e(money($owing)) ?></div>
        <div class="portal-balance__note">
          Across <?= (int) $summary['unpaid'] ?> invoice<?= $summary['unpaid'] === 1 ? '' : 's' ?><?php
            if ($summary['oldest_days'] !== null && $summary['oldest_days'] > 0): ?> &middot; oldest raised <?= (int) $summary['oldest_days'] ?> days ago<?php endif; ?>
        </div>
      </div>

      <div class="portal-balance__act">
        <?php if ($canPay && $payDoc): ?>
          <a class="btn btn--primary btn--lg" href="<?= url('/portal/invoices/' . (int) $payDoc['id']) ?>">
            <?= icon('smartphone') ?> Pay by M-Pesa
          </a>
          <div class="portal-balance__hint">
            Starts with <?= e($payDoc['doc_number']) ?>, the oldest one open.
          </div>
        <?php else: ?>
          <a class="btn btn--light btn--lg" href="<?= url('/portal/invoices') ?>">
            See your invoices
          </a>
        <?php endif; ?>
      </div>
    </section>
  <?php else: ?>
    <section class="portal-balance portal-balance--clear">
      <div class="portal-balance__body">
        <span class="portal-balance__tick"><?= icon('check-circle') ?></span>
        <div>
          <div class="portal-balance__figure portal-balance__figure--sm">You are all paid up</div>
          <div class="portal-balance__note">Nothing is outstanding on your account.</div>
        </div>
      </div>
      <?php // Outline, not btn--light: the paid-up panel is a green tint,
            // which is light in the light theme, and a translucent white
            // button on it would disappear. ?>
      <div class="portal-balance__act">
        <a class="btn btn--outline" href="<?= url('/portal/invoices') ?>">See your invoices</a>
      </div>
    </section>
  <?php endif; ?>

  <?php // Pending proof alert — the one action that needs their response before work can continue. ?>
  <?php if ($pendingProofs > 0): ?>
    <div class="portal-alert-action" id="pending-proofs-alert">
      <div class="portal-alert-action__icon"><?= icon('image') ?></div>
      <div class="portal-alert-action__body">
        <div class="fw-600">
          <?= (int) $pendingProofs ?> proof<?= $pendingProofs === 1 ? '' : 's' ?> waiting for your approval
        </div>
        <div class="text-sm text-muted">Look them over and let us know if you are happy to proceed.</div>
      </div>
      <a class="btn btn--primary" href="<?= url('/portal/jobs') ?>">
        Review <?= icon('arrow-right') ?>
      </a>
    </div>
  <?php endif; ?>

  <?php // Pending briefs alert ?>
  <?php if ($pendingBriefs > 0): ?>
    <div class="portal-alert-action portal-alert-action--blue" id="pending-briefs-alert">
      <div class="portal-alert-action__icon"><?= icon('file-text') ?></div>
      <div class="portal-alert-action__body">
        <div class="fw-600">
          <?= (int) $pendingBriefs ?> brief<?= $pendingBriefs === 1 ? '' : 's' ?> waiting for your input
        </div>
        <div class="text-sm text-muted">We need some details from you before we can start quoting.</div>
      </div>
      <a class="btn btn--outline" href="<?= url('/portal/briefs') ?>">
        Open <?= icon('arrow-right') ?>
      </a>
    </div>
  <?php endif; ?>

  <div class="portal-stats">
    <a class="portal-stat" href="<?= url('/portal/quotations') ?>">
      <span class="portal-stat__figure"><?= (int) $summary['quotations'] ?></span>
      <span class="portal-stat__label">Quotations</span>
      <?php if ($summary['open_quotes'] > 0): ?>
        <span class="portal-stat__note"><?= (int) $summary['open_quotes'] ?> waiting on you</span>
      <?php endif; ?>
    </a>

    <a class="portal-stat" href="<?= url('/portal/invoices') ?>">
      <span class="portal-stat__figure"><?= (int) $summary['invoices'] ?></span>
      <span class="portal-stat__label">Invoices</span>
    </a>

    <?php if ($activeJobs > 0): ?>
      <a class="portal-stat" href="<?= url('/portal/jobs') ?>">
        <span class="portal-stat__figure"><?= (int) $activeJobs ?></span>
        <span class="portal-stat__label">Active jobs</span>
        <?php if ($pendingProofs > 0): ?>
          <span class="portal-stat__note"><?= (int) $pendingProofs ?> need your approval</span>
        <?php endif; ?>
      </a>
    <?php endif; ?>

    <a class="portal-stat" href="<?= url('/portal/statement') ?>">
      <span class="portal-stat__figure portal-stat__figure--icon"><?= icon('file-text') ?></span>
      <span class="portal-stat__label">Statement</span>
      <span class="portal-stat__note">Everything billed and paid</span>
    </a>
  </div>

  <div class="portal-cols">
    <section class="portal-card portal-card--flush">
      <header class="portal-card__head">
        <h2 class="portal-card__title">Recent</h2>
        <a class="portal-card__more" href="<?= url('/portal/invoices') ?>">
          All invoices <?= icon('chevron-right') ?>
        </a>
      </header>

      <?php if (!$recent): ?>
        <div class="portal-empty portal-empty--inline">
          <p class="mb-0 text-sm text-muted">
            Nothing yet. Quotations and invoices will appear here as we raise them.
          </p>
        </div>
      <?php else: ?>
        <ul class="portal-list">
          <?php foreach ($recent as $d): ?>
            <?php
              $isInv = $d['doc_type'] === 'invoice';
              $href  = url('/portal/' . ($isInv ? 'invoices' : 'quotations') . '/' . (int) $d['id']);
            ?>
            <li>
              <a class="portal-list__row" href="<?= e($href) ?>">
                <span class="portal-list__main">
                  <span class="portal-list__title"><?= e($d['title'] ?: ($isInv ? 'Invoice' : 'Quotation')) ?></span>
                  <span class="portal-list__meta"><?= e($d['doc_number']) ?> &middot; <?= e(fdate($d['issue_date'])) ?></span>
                </span>
                <span class="portal-list__side">
                  <span class="portal-list__amount"><?= e(money($d['total'], false)) ?></span>
                  <span class="badge badge--<?= e($tone((string) $d['status'])) ?>"><?= e(label_of((string) $d['status'])) ?></span>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <div class="portal-side">
      <?php if ($renewals): ?>
        <section class="portal-card portal-card--flush">
          <header class="portal-card__head">
            <h2 class="portal-card__title">Coming up</h2>
            <a class="portal-card__more" href="<?= url('/portal/services') ?>">
              All renewals <?= icon('chevron-right') ?>
            </a>
          </header>
          <ul class="portal-list portal-list--tight">
            <?php foreach ($renewals as $s): ?>
              <?php
                $days = (int) $s['days_away'];
                $late = $days < 0;
                $soon = $days >= 0 && $days <= 14;
              ?>
              <li>
                <span class="portal-list__row">
                  <span class="portal-list__main">
                    <span class="portal-list__title"><?= e($s['name']) ?></span>
                    <span class="portal-list__meta">
                      <?= e(money($s['amount'], false)) ?> <?= e(\App\Services\Renewals::cyclePhrase($s['billing_cycle'])) ?>
                    </span>
                  </span>
                  <span class="portal-list__side">
                    <span class="badge badge--<?= $late ? 'red' : ($soon ? 'amber' : 'grey') ?>">
                      <?= $late ? abs($days) . 'd overdue' : ($days === 0 ? 'Today' : 'In ' . $days . 'd') ?>
                    </span>
                    <span class="portal-list__meta"><?= e(fdate($s['next_renewal_date'])) ?></span>
                  </span>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($recentNotifs): ?>
        <section class="portal-card portal-card--flush">
          <header class="portal-card__head">
            <h2 class="portal-card__title">Recent activity</h2>
            <a class="portal-card__more" href="<?= url('/portal/notifications') ?>">
              All <?= icon('chevron-right') ?>
            </a>
          </header>
          <ul class="portal-list portal-list--tight">
            <?php foreach ($recentNotifs as $n): ?>
              <li>
                <?php if ($n['link']): ?>
                  <a class="portal-list__row" href="<?= url(e($n['link'])) ?>">
                <?php else: ?>
                  <span class="portal-list__row">
                <?php endif; ?>
                  <span class="portal-list__main">
                    <span class="portal-list__title"><?= e($n['title']) ?></span>
                    <?php if ($n['body']): ?>
                      <span class="portal-list__meta"><?= e(str_excerpt((string) $n['body'], 60)) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="portal-list__side">
                    <span class="text-xs text-muted"><?= e(fdate($n['created_at'])) ?></span>
                    <?php if (!$n['read_at']): ?>
                      <span class="portal-notif-dot" title="New"></span>
                    <?php endif; ?>
                  </span>
                <?php if ($n['link']): ?>
                  </a>
                <?php else: ?>
                  </span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <section class="portal-card">
        <h2 class="portal-card__title mb-12">Anything else?</h2>
        <div class="portal-acts">
          <a class="portal-act" href="<?= url('/portal/catalogue') ?>">
            <?= icon('package') ?>
            <span>
              <strong>Ask for a price</strong>
              <em>Pick from what we do and we will quote you</em>
            </span>
          </a>
          <a class="portal-act" href="<?= url('/portal/uploads') ?>">
            <?= icon('paperclip') ?>
            <span>
              <strong>Send us artwork</strong>
              <em>Logos, print-ready files, photographs</em>
            </span>
          </a>
          <a class="portal-act" href="<?= url('/portal/requests') ?>">
            <?= icon('message') ?>
            <span>
              <strong>Your requests</strong>
              <em>What you have asked us, and where it got to</em>
            </span>
          </a>
          <a class="portal-act" href="<?= url('/portal/support') ?>">
            <?= icon('message-circle') ?>
            <span>
              <strong>Send us a message</strong>
              <em>Ask anything — we reply here in the portal</em>
            </span>
          </a>
        </div>
      </section>
    </div>
  </div>

  <p class="portal-help">
    Something not right? Call us on
    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $company['phone'])) ?>"><?= e($company['phone']) ?></a>
    or email <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>.
  </p>
</div>
