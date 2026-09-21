<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($client['name']) ?></h1>
    <div class="page-head__sub">Portal support thread</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/portal-support') ?>"><?= icon('arrow-left') ?> All threads</a>
    <a class="btn btn--outline" href="<?= url('/clients/' . (int) $client['id']) ?>"><?= icon('user') ?> Client record</a>
  </div>
</div>

<div class="card" style="max-width:780px">
  <div class="support-thread">
    <?php if (!$messages): ?>
      <div class="empty">
        <div class="empty__icon"><?= icon('message-circle') ?></div>
        <div class="empty__title">No messages yet</div>
      </div>
    <?php else: ?>
      <div class="support-bubbles" id="support-bubbles">
        <?php foreach ($messages as $msg): ?>
          <?php $isClient = $msg['sender'] === 'client'; ?>
          <div class="support-bubble <?= $isClient ? 'support-bubble--client' : 'support-bubble--staff' ?>">
            <div class="support-bubble__body"><?= nl2br(e($msg['body'])) ?></div>
            <div class="support-bubble__meta">
              <?= $isClient ? e($client['name']) : e($msg['staff_name'] ?? 'Staff') ?>
              &middot; <?= e(fdate($msg['created_at'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form class="support-reply" method="post" action="<?= url('/portal-support/' . (int) $client['id']) ?>">
      <?= csrf_field() ?>
      <textarea class="input" name="body" id="staff-reply" rows="4" required maxlength="3000"
                placeholder="Write your reply…" style="resize:vertical;width:100%"></textarea>
      <div class="mt-8 flex gap-8">
        <button class="btn btn--primary" type="submit" id="send-reply-btn"><?= icon('send') ?> Send reply</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
  (function () {
    var el = document.getElementById('support-bubbles');
    if (el) { el.scrollTop = el.scrollHeight; }
  })();
</script>
