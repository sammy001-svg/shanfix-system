<?php
/**
 * Job tracking — the client's view of what is being produced.
 *
 * Production notes and internal details are never passed in here; the
 * controller deliberately omits them. What the client sees is: the job
 * name, what stage it is at, and when it is due.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$stageLabel = static fn(string $s): string => match ($s) {
    'pending'     => 'Received',
    'artwork'     => 'Artwork',
    'proof_sent'  => 'Proof sent',
    'approved'    => 'Approved',
    'production'  => 'In production',
    'finishing'   => 'Finishing',
    'ready'       => 'Ready for collection',
    'delivered'   => 'Delivered',
    'on_hold'     => 'On hold',
    default       => ucfirst(str_replace('_', ' ', $s)),
};

$stageTone = static fn(string $s): string => match ($s) {
    'ready'      => 'green',
    'delivered'  => 'grey',
    'on_hold'    => 'amber',
    'proof_sent' => 'amber',
    default      => 'navy',
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your jobs</h1>
    <p class="portal-lede">
      Track everything we are working on for you. Click any job for more detail.
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('briefcase') ?></div>
      <div class="card__title mt-8">Nothing in production</div>
      <p class="text-sm text-muted mb-0">
        When we start work on a job for you, it will appear here so you can follow its progress.
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $j): ?>
          <li>
            <a class="portal-list__row" href="<?= url('/portal/jobs/' . (int) $j['id']) ?>">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($j['title']) ?></span>
                <span class="portal-list__meta">
                  <?= e($j['job_number']) ?>
                  <?php if ($j['doc_number']): ?> &middot; <?= e($j['doc_number']) ?><?php endif; ?>
                  <?php if ($j['due_date']): ?> &middot; due <?= e(fdate($j['due_date'])) ?><?php endif; ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="badge badge--<?= e($stageTone((string) $j['stage'])) ?>">
                  <?= e($stageLabel((string) $j['stage'])) ?>
                </span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <p class="portal-help">
    Question about a job? Call us on
    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $company['phone'])) ?>"><?= e($company['phone']) ?></a>
    or email <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>.
  </p>
</div>
