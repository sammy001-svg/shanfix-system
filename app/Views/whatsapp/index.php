<?php
require_once APP_PATH . '/Views/partials/icons.php';

$filterUrl = static fn(string $show): string =>
    url('/whatsapp') . query_string(['show' => $show, 'c' => null]);

/** A one-line summary of the last message, whatever type it was. */
$preview = static function (array $c): string {
    if ($c['last_type'] && $c['last_type'] !== 'text' && !$c['last_body']) {
        return ucfirst($c['last_type']);
    }

    return str_excerpt($c['last_body'] ?? '', 46) ?: '—';
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>WhatsApp</h1>
    <div class="page-head__sub">
      <?php if ($connected): ?>
        The company WhatsApp<?= $number ? ' · ' . e($number) : '' ?> — one inbox, everyone answers.
      <?php else: ?>
        Not connected yet.
      <?php endif; ?>
    </div>
  </div>
  <?php if (can('whatsapp.send') && $connected): ?>
    <div class="page-head__actions">
      <button class="btn btn--outline" type="button" data-modal-open="wa-start">
        <?= icon('plus') ?> New conversation
      </button>
    </div>
  <?php endif; ?>
</div>

<?php if (!$connected): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('message') ?></div>
      <div class="empty__title">WhatsApp is not connected</div>
      <p class="empty__text">
        This uses Meta's official WhatsApp Business API, so the number stays
        registered and cannot be banned for automation. An administrator
        connects it once under
        <?php if (can('settings.manage')): ?>
          <a href="<?= url('/settings?tab=messaging') ?>">Settings → Messaging</a>.
        <?php else: ?>
          Settings → Messaging.
        <?php endif; ?>
      </p>
    </div>
  </div>
<?php else: ?>

<div class="wa">
  <aside class="wa__list">
    <div class="wa__search">
      <form method="get" action="<?= url('/whatsapp') ?>">
        <input type="hidden" name="show" value="<?= e($filters['show']) ?>">
        <input class="input" type="search" name="q" value="<?= e($filters['search']) ?>"
               placeholder="Search name or number" data-debounce-submit>
      </form>
    </div>

    <div class="wa__filters">
      <?php foreach (['open' => 'Open', 'unread' => 'Unread', 'mine' => 'Mine', 'closed' => 'Closed'] as $key => $label): ?>
        <a class="wa__filter <?= $filters['show'] === $key ? 'is-on' : '' ?>" href="<?= e($filterUrl($key)) ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="wa__threads">
      <?php if (!$conversations): ?>
        <div class="wa__none">
          Nothing here yet. Conversations appear as soon as somebody messages
          the company number.
        </div>
      <?php endif; ?>

      <?php foreach ($conversations as $c): ?>
        <a class="wa__thread <?= $active && (int) $active['id'] === (int) $c['id'] ? 'is-on' : '' ?>"
           href="<?= url('/whatsapp') . query_string(['c' => $c['id']]) ?>">
          <span class="wa__avatar"><?= e(initials($c['display_name'] ?: $c['wa_id'])) ?></span>
          <span class="wa__thread-body">
            <span class="wa__thread-top">
              <span class="wa__who"><?= e($c['display_name'] ?: '+' . $c['wa_id']) ?></span>
              <span class="wa__when"><?= e($c['last_message_at'] ? time_ago($c['last_message_at']) : '') ?></span>
            </span>
            <span class="wa__thread-bottom">
              <span class="wa__preview">
                <?php if ($c['last_direction'] === 'out'): ?><span class="wa__you">You:</span> <?php endif; ?>
                <?= e($preview($c)) ?>
              </span>
              <?php if ((int) $c['unread_count'] > 0): ?>
                <span class="wa__badge"><?= (int) $c['unread_count'] ?></span>
              <?php endif; ?>
            </span>
            <?php if ($c['client_name']): ?>
              <span class="wa__client"><?= icon('users') ?> <?= e($c['client_name']) ?></span>
            <?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <section class="wa__panel">
    <?php if (!$active): ?>
      <div class="wa__empty">
        <?= icon('message') ?>
        <div>Pick a conversation to read it.</div>
      </div>
    <?php else: ?>
      <header class="wa__head">
        <span class="wa__avatar"><?= e(initials($active['display_name'] ?: $active['wa_id'])) ?></span>
        <div class="wa__head-text">
          <div class="wa__head-name"><?= e($active['display_name'] ?: '+' . $active['wa_id']) ?></div>
          <div class="wa__head-sub">
            +<?= e($active['wa_id']) ?>
            <?php if ($active['client_name']): ?>
              · <a href="<?= url('/clients/' . $active['client_id']) ?>"><?= e($active['client_name']) ?></a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (can('whatsapp.send')): ?>
          <form method="post" action="<?= url('/whatsapp/' . $active['id'] . '/close') ?>">
            <?= csrf_field() ?>
            <button class="btn btn--ghost btn--sm" type="submit">
              <?= $active['status'] === 'open' ? 'Close' : 'Reopen' ?>
            </button>
          </form>
        <?php endif; ?>
      </header>

      <div class="wa__messages" data-wa-messages
           data-poll-url="<?= e(url('/whatsapp/' . $active['id'] . '/poll')) ?>"
           data-last="<?= (int) ($messages ? end($messages)['id'] : 0) ?>">
        <?php foreach ($messages as $m): ?>
          <div class="wa__msg wa__msg--<?= $m['direction'] === 'out' ? 'out' : 'in' ?>">
            <div class="wa__bubble">
              <?php if ($m['msg_type'] !== 'text' && !$m['body']): ?>
                <span class="wa__attach"><?= icon('archive') ?> <?= e(ucfirst($m['msg_type'])) ?></span>
              <?php else: ?>
                <?= nl2br(e((string) $m['body'])) ?>
              <?php endif; ?>
              <span class="wa__meta">
                <?php if ($m['direction'] === 'out' && $m['sender']): ?>
                  <?= e(explode(' ', $m['sender'])[0]) ?> ·
                <?php endif; ?>
                <?= e(date('H:i', strtotime($m['wa_timestamp'] ?: $m['created_at']))) ?>
                <?php if ($m['direction'] === 'out'): ?>
                  · <span class="wa__status wa__status--<?= e($m['status']) ?>"><?= e($m['status']) ?></span>
                <?php endif; ?>
              </span>
              <?php if ($m['error']): ?>
                <span class="wa__error"><?= e($m['error']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (can('whatsapp.send')): ?>
        <?php if ($windowOpen): ?>
          <form class="wa__compose" data-wa-form
                data-url="<?= e(url('/whatsapp/' . $active['id'] . '/send')) ?>">
            <?= csrf_field() ?>
            <textarea class="textarea" name="body" rows="1" required
                      placeholder="Write a message…" data-wa-input></textarea>
            <button class="btn btn--primary" type="submit"><?= icon('send') ?></button>
          </form>
          <div class="wa__window">
            <?php
              // Meta's rule, said plainly. Someone about to type deserves to
              // know how long they have rather than discovering it from a
              // rejection.
              $hours = intdiv($windowLeft, 60);
            ?>
            You can reply freely for another
            <?= $hours > 0 ? $hours . ' hour' . ($hours === 1 ? '' : 's') : $windowLeft . ' minutes' ?>.
          </div>
        <?php else: ?>
          <div class="wa__closed">
            <?= icon('alert-triangle') ?>
            <div>
              <strong>More than 24 hours since they last wrote.</strong>
              WhatsApp does not allow a typed reply now — only a template Meta has
              approved. The conversation reopens the moment they message again.
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<?php if (can('whatsapp.send')): ?>
  <div class="modal-backdrop" id="wa-start">
    <div class="modal">
      <form method="post" action="<?= url('/whatsapp/start') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Start a conversation</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <div class="field">
            <label class="label" for="wa-phone">Phone number</label>
            <input class="input" type="tel" id="wa-phone" name="phone" required placeholder="07XX XXX XXX">
          </div>
          <div class="field">
            <label class="label" for="wa-name">Name</label>
            <input class="input" type="text" id="wa-name" name="name" placeholder="Optional">
          </div>
          <div class="alert alert--warning mb-0">
            <?= icon('info') ?>
            <div class="alert__body text-sm">
              WhatsApp only lets a business start a conversation with an approved
              template. Until they reply, a typed message will be refused.
            </div>
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Open thread</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php endif; ?>
