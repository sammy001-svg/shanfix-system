<?php
/**
 * Portal notifications — the full activity feed.
 *
 * Opened by clicking the bell icon. Everything is marked read as soon as
 * the page loads (done in the controller before rendering). Items that were
 * unread when the page was fetched are highlighted briefly.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$eventIcon = static fn(string $e): string => match ($e) {
    'job_stage_changed'       => 'briefcase',
    'proof_approved'          => 'check-circle',
    'proof_changes_requested' => 'edit',
    'invoice_issued'          => 'receipt',
    'payment_received'        => 'credit-card',
    'quotation_issued'        => 'file-text',
    'quotation_accepted'      => 'thumbs-up',
    'support_reply'           => 'message-circle',
    default                   => 'bell',
};
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Notifications</h1>
    <p class="portal-lede">
      Everything that has changed on your account.
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('bell') ?></div>
      <div class="card__title mt-8">Nothing yet</div>
      <p class="text-sm text-muted mb-0">
        When something changes — a job moves stage, an invoice is issued, a proof is ready —
        it will appear here.
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $n): ?>
          <li>
            <?php if ($n['link']): ?>
              <a class="portal-list__row" href="<?= url(e($n['link'])) ?>">
            <?php else: ?>
              <span class="portal-list__row">
            <?php endif; ?>
              <span class="portal-notif__icon"><?= icon($eventIcon((string) $n['event'])) ?></span>
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($n['title']) ?></span>
                <?php if ($n['body']): ?>
                  <span class="portal-list__meta"><?= e($n['body']) ?></span>
                <?php endif; ?>
              </span>
              <span class="portal-list__side">
                <span class="text-xs text-muted"><?= e(fdate($n['created_at'])) ?></span>
              </span>
            <?php if ($n['link']): ?>
              </a>
            <?php else: ?>
              </span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
