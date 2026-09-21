<?php
/**
 * Portal support — the client's message thread with the team.
 *
 * One scroll of bubbles, newest at the bottom. The reply box sticks to
 * the bottom on desktop and scrolls off on mobile, which is the expected
 * shape for a chat interface.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Support</h1>
    <p class="portal-lede">
      Send us a message and we will reply here. For anything urgent,
      call us on <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $company['phone'])) ?>"><?= e($company['phone']) ?></a>.
    </p>
  </div>

  <section class="portal-card portal-card--flush portal-thread" id="thread-card">
    <?php if (!$messages): ?>
      <div class="portal-empty">
        <span class="portal-empty__icon"><?= icon('message-circle') ?></span>
        <p class="portal-empty__title">Start the conversation</p>
        <p class="text-sm text-muted mb-0">
          Ask us anything — a question about a job, an invoice, or something new you need.
        </p>
      </div>
    <?php else: ?>
      <div class="portal-bubbles" id="portal-bubbles">
        <?php foreach ($messages as $msg): ?>
          <?php $isClient = $msg['sender'] === 'client'; ?>
          <div class="portal-bubble <?= $isClient ? 'portal-bubble--client' : 'portal-bubble--staff' ?>">
            <div class="portal-bubble__body"><?= nl2br(e($msg['body'])) ?></div>
            <div class="portal-bubble__meta">
              <?php if (!$isClient && $msg['staff_name']): ?>
                <?= e($msg['staff_name']) ?> &middot;
              <?php endif; ?>
              <?= e(fdate($msg['created_at'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form class="portal-reply" method="post" action="<?= url('/portal/support') ?>" id="portal-reply-form">
      <?= csrf_field() ?>
      <textarea class="portal-reply__input" id="support-message" name="body"
                rows="3" maxlength="3000" required
                placeholder="Type your message here…"></textarea>
      <button class="btn btn--primary" type="submit" id="send-message-btn">
        <?= icon('send') ?> Send
      </button>
    </form>
  </section>

  <p class="text-xs text-muted mt-8 text-center">
    We aim to reply within one business day. For the fastest response, include your job or invoice number.
  </p>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
  // Scroll the bubble list to the bottom on load.
  (function () {
    var bubbles = document.getElementById('portal-bubbles');
    if (bubbles) { bubbles.scrollTop = bubbles.scrollHeight; }
  })();
</script>
