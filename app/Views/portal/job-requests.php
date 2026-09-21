<?php
/**
 * Job detail request briefs — sent by staff for the client to fill in.
 *
 * Only shows briefs raised against this client. The actual form is at
 * /brief/{token} (public, no login), which is what the emailed link
 * points to. This page just lets the client see what is outstanding.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$briefLabel = static fn(string $t): string => match ($t) {
    'design'   => 'Design brief',
    'website'  => 'Website brief',
    'software' => 'Software brief',
    default    => ucfirst($t) . ' brief',
};

$statusLabel = static fn(string $s): string => match ($s) {
    'draft'     => 'Not sent yet',
    'sent'      => 'Waiting for you',
    'opened'    => 'Opened — please fill it in',
    'submitted' => 'Submitted — thank you',
    'actioned'  => 'Dealt with',
    default     => ucfirst($s),
};

$statusTone = static fn(string $s): string => match ($s) {
    'sent', 'opened' => 'amber',
    'submitted'      => 'navy',
    'actioned'       => 'green',
    default          => 'grey',
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your briefs</h1>
    <p class="portal-lede">
      When we need more detail about a job, we send you a brief to fill in.
      Anything outstanding appears here.
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('clipboard') ?></div>
      <div class="card__title mt-8">No briefs sent yet</div>
      <p class="text-sm text-muted mb-0">
        When we need the details of a new job from you, the form will appear here.
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $r): ?>
          <li>
            <?php if (in_array($r['status'], ['sent', 'opened'], true)): ?>
              <?php // Waiting on the client — link directly to the brief. ?>
              <a class="portal-list__row" href="<?= url('/portal/briefs') ?>">
            <?php else: ?>
              <span class="portal-list__row">
            <?php endif; ?>
              <span class="portal-list__main">
                <span class="portal-list__title">
                  <?= e($briefLabel((string) $r['brief_type'])) ?>
                  <?php if ($r['title']): ?> — <?= e($r['title']) ?><?php endif; ?>
                </span>
                <span class="portal-list__meta">
                  <?= e($r['reference']) ?> &middot; <?= e(fdate($r['created_at'])) ?>
                  <?php if ($r['submitted_at'] && $r['status'] !== 'draft'): ?>
                    &middot; submitted <?= e(fdate($r['submitted_at'])) ?>
                  <?php endif; ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="badge badge--<?= e($statusTone((string) $r['status'])) ?>">
                  <?= e($statusLabel((string) $r['status'])) ?>
                </span>
              </span>
            <?php if (in_array($r['status'], ['sent', 'opened'], true)): ?>
              </a>
            <?php else: ?>
              </span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <p class="text-sm text-muted mt-8 px-4">
      We send the link by email and SMS. Open it from the message we sent you to fill in the form.
    </p>
  <?php endif; ?>
</div>
