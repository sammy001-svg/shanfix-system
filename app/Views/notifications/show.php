<?php
require_once APP_PATH . '/Views/partials/icons.php';

$n       = $notification;
$isEmail = $n['channel'] === 'email';
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/notifications') ?>">Messages</a> <span>/</span> #<?= (int) $n['id'] ?>
    </div>
    <h1>
      <?= e(label_of($n['event'])) ?>
      <span class="badge <?= match ($n['status']) {
          'sent'   => 'badge--green',
          'queued' => 'badge--amber',
          'failed' => 'badge--red',
          default  => 'badge--grey',
      } ?>" style="vertical-align:middle;margin-left:6px"><?= e(label_of($n['status'])) ?></span>
    </h1>
    <div class="page-head__sub">
      <?= e(strtoupper($n['channel'])) ?> to <?= e($n['recipient']) ?>
      <?= $n['recipient_name'] ? ' · ' . e($n['recipient_name']) : '' ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (in_array($n['status'], ['failed', 'queued'], true)): ?>
      <form method="post" action="<?= url('/notifications/' . $n['id'] . '/retry') ?>" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn--primary" type="submit"><?= icon('refresh') ?> Send again</button>
      </form>
      <form method="post" action="<?= url('/notifications/' . $n['id'] . '/cancel') ?>" style="display:inline"
            data-confirm="Cancel this message so it is never sent?">
        <?= csrf_field() ?>
        <button class="btn btn--outline" type="submit"><?= icon('x') ?> Cancel</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($n['last_error']): ?>
  <div class="alert alert--error">
    <?= icon('x-circle') ?>
    <div class="alert__body">
      <strong>Last error:</strong> <?= e($n['last_error']) ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <?= icon($isEmail ? 'mail' : 'message') ?>
        <div>
          <div class="card__title"><?= $isEmail ? 'Email preview' : 'Message text' ?></div>
          <?php if ($n['subject']): ?>
            <div class="card__sub"><?= e($n['subject']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isEmail): ?>
        <div class="card__body" style="background:var(--surface-2)">
          <!-- Rendered inside a sandboxed frame: the stored HTML is our own,
               but the frame guarantees it cannot touch this page. -->
          <iframe
            srcdoc="<?= e($n['body']) ?>"
            sandbox=""
            style="width:100%;height:640px;border:1px solid var(--border);border-radius:var(--r);background:#fff"
            title="Email preview"></iframe>
        </div>
      <?php else: ?>
        <div class="card__body">
          <div style="max-width:340px;margin:0 auto">
            <div class="msg msg--mine" style="justify-content:flex-end">
              <div class="msg__bubble">
                <div class="msg__body"><?= e($n['body']) ?></div>
              </div>
            </div>
          </div>
          <p class="text-xs text-muted text-center mt-12 mb-0">
            <?= mb_strlen((string) $n['body']) ?> characters ·
            <?= \App\Services\Sms::parts((string) $n['body']) ?> SMS credit(s)
          </p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card__head"><div class="card__title">Delivery</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Channel</dt><dd><?= e(strtoupper($n['channel'])) ?></dd>
          <dt>Event</dt><dd><?= e(label_of($n['event'])) ?></dd>
          <dt>Recipient</dt><dd class="truncate"><?= e($n['recipient']) ?></dd>
          <?php if ($n['client_name']): ?>
            <dt>Client</dt>
            <dd><a href="<?= url('/clients/' . $n['client_id']) ?>"><?= e($n['client_name']) ?></a></dd>
          <?php endif; ?>
          <dt>Status</dt>
          <dd><span class="badge <?= match ($n['status']) {
              'sent'   => 'badge--green',
              'queued' => 'badge--amber',
              'failed' => 'badge--red',
              default  => 'badge--grey',
          } ?>"><?= e(label_of($n['status'])) ?></span></dd>
          <dt>Attempts</dt><dd><?= (int) $n['attempts'] ?></dd>
          <dt>Queued</dt><dd><?= e(fdatetime($n['created_at'])) ?></dd>
          <?php if ($n['sent_at']): ?>
            <dt>Sent</dt><dd><?= e(fdatetime($n['sent_at'])) ?></dd>
          <?php endif; ?>
          <?php if ($n['provider_ref']): ?>
            <dt>Gateway ref</dt><dd><code class="text-xs"><?= e($n['provider_ref']) ?></code></dd>
          <?php endif; ?>
          <?php if ($n['cost'] !== null): ?>
            <dt>Cost</dt><dd><?= e(money($n['cost'])) ?></dd>
          <?php endif; ?>
          <dt>Triggered by</dt><dd><?= e($n['sent_by'] ?: 'System') ?></dd>
        </dl>
      </div>
    </div>

    <?php if ($n['entity_type'] === 'document' && $n['entity_id']): ?>
      <?php
        $linked = \App\Core\Database::first(
            'SELECT id, doc_type, doc_number, total, status FROM documents WHERE id = :id',
            ['id' => $n['entity_id']]
        );
      ?>
      <?php if ($linked): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Related document</div></div>
          <div class="card__body--flush">
            <?php $path = $linked['doc_type'] === 'quotation' ? '/quotations/'
                        : ($linked['doc_type'] === 'invoice' ? '/invoices/' : '/receipts/'); ?>
            <a class="conv" href="<?= url($path . $linked['id']) ?>">
              <span class="conv__hash"><?= icon('file-text') ?></span>
              <span class="conv__meta">
                <span class="conv__name"><?= e($linked['doc_number']) ?></span>
                <span class="conv__preview"><?= e(label_of($linked['doc_type'])) ?></span>
              </span>
              <span class="conv__right">
                <span class="badge <?= status_badge($linked['status']) ?>"><?= e(label_of($linked['status'])) ?></span>
                <span class="conv__time"><?= e(money_short($linked['total'])) ?></span>
              </span>
            </a>
          </div>
        </div>
      <?php endif; ?>
    <?php elseif ($n['entity_type'] === 'job' && $n['entity_id']): ?>
      <div class="card">
        <div class="card__body">
          <a class="btn btn--outline btn--block" href="<?= url('/jobs/' . $n['entity_id']) ?>">
            <?= icon('printer') ?> Open job card
          </a>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
