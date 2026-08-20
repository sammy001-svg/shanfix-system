<?php
require_once APP_PATH . '/Views/partials/icons.php';

$me = auth();

$isOnline = static fn(?string $seen): bool =>
    $seen !== null && (time() - strtotime($seen)) < 300;

$channels = array_values(array_filter($conversations, static fn($c) => $c['type'] === 'channel'));
$dms      = array_values(array_filter($conversations, static fn($c) => $c['type'] === 'dm'));

// Colleagues who don't already have a DM open.
$existingDmNames = array_column($dms, 'other_name');
$startable = array_values(array_filter(
    $colleagues,
    static fn($u) => !in_array($u['name'], $existingDmNames, true)
));

$lastDay = null;
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Team Chat</h1>
    <div class="page-head__sub">Direct messages and channels for the Shanfix team.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/chat/search') ?>">
      <?= icon('search') ?> Search
    </a>
    <button class="btn btn--outline" type="button" data-modal-open="new-channel">
      <?= icon('hash') ?> New channel
    </button>
    <button class="btn btn--primary" type="button" data-modal-open="new-dm">
      <?= icon('message') ?> New message
    </button>
  </div>
</div>

<div class="chat <?= $conversationId ? '' : 'show-list' ?>">

  <div class="chat__list">
    <div class="chat__list-head">
      <?= icon('inbox') ?>
      <strong style="font-size:14px">Conversations</strong>
    </div>

    <div class="chat__scroll">
      <?php if ($channels): ?>
        <div class="chat__section-label">Channels</div>
        <?php foreach ($channels as $c): ?>
          <a class="conv <?= (int) $c['id'] === $conversationId ? 'is-active' : '' ?>"
             href="<?= url('/chat/' . $c['id']) ?>">
            <span class="conv__hash">#</span>
            <span class="conv__meta">
              <span class="conv__name"><?= e($c['name']) ?></span>
              <span class="conv__preview">
                <?php if ($c['last_body'] || $c['last_attachment']): ?>
                  <?= e($c['last_author'] ? explode(' ', $c['last_author'])[0] . ': ' : '') ?><?= e($c['last_body'] ?: '📎 ' . $c['last_attachment']) ?>
                <?php else: ?>
                  No messages yet
                <?php endif; ?>
              </span>
            </span>
            <span class="conv__right">
              <?php if ($c['last_at']): ?>
                <span class="conv__time"><?= e(time_ago($c['last_at'])) ?></span>
              <?php endif; ?>
              <?php if ((int) $c['unread'] > 0): ?>
                <span class="conv__unread"><?= (int) $c['unread'] > 99 ? '99+' : (int) $c['unread'] ?></span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($dms): ?>
        <div class="chat__section-label">Direct messages</div>
        <?php foreach ($dms as $c): ?>
          <a class="conv <?= (int) $c['id'] === $conversationId ? 'is-active' : '' ?>"
             href="<?= url('/chat/' . $c['id']) ?>">
            <span class="avatar" style="background:<?= e($c['other_color'] ?: '#0C2B4A') ?>">
              <?= e(initials($c['other_name'] ?? '?')) ?>
            </span>
            <span class="conv__meta">
              <span class="conv__name">
                <?= e($c['other_name'] ?? 'Unknown') ?>
                <?php if ($isOnline($c['other_seen'])): ?>
                  <span class="badge__dot" style="background:var(--green-500);display:inline-block"></span>
                <?php endif; ?>
              </span>
              <span class="conv__preview">
                <?= $c['last_body'] || $c['last_attachment']
                    ? e($c['last_body'] ?: '📎 ' . $c['last_attachment'])
                    : 'No messages yet' ?>
              </span>
            </span>
            <span class="conv__right">
              <?php if ($c['last_at']): ?>
                <span class="conv__time"><?= e(time_ago($c['last_at'])) ?></span>
              <?php endif; ?>
              <?php if ((int) $c['unread'] > 0): ?>
                <span class="conv__unread"><?= (int) $c['unread'] > 99 ? '99+' : (int) $c['unread'] ?></span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($startable): ?>
        <div class="chat__section-label">Start a chat</div>
        <?php foreach ($startable as $u): ?>
          <a class="conv" href="<?= url('/chat/with/' . $u['id']) ?>">
            <span class="avatar" style="background:<?= e($u['avatar_color']) ?>">
              <?= e(initials($u['name'])) ?>
            </span>
            <span class="conv__meta">
              <span class="conv__name"><?= e($u['name']) ?></span>
              <span class="conv__preview"><?= e($u['job_title'] ?: label_of($u['role'])) ?></span>
            </span>
            <span class="conv__right">
              <?php if ($isOnline($u['last_seen_at'])): ?>
                <span class="badge badge--green text-xs">Online</span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!$conversations && !$startable): ?>
        <div class="empty" style="padding:28px 16px">
          <div class="empty__title">No colleagues yet</div>
          <p class="empty__text">Add team members under Users &amp; Roles to start chatting.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$conversation): ?>
    <div class="chat__panel">
      <div class="chat__empty">
        <div>
          <div class="empty__icon" style="margin:0 auto 14px"><?= icon('message') ?></div>
          <div class="empty__title">Pick a conversation</div>
          <p class="empty__text">
            Choose a channel or colleague on the left, or start something new.
          </p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="chat__panel"
         id="chat-panel"
         data-conversation-id="<?= (int) $conversationId ?>"
         data-last-id="<?= (int) $lastId ?>"
         data-poll-url="<?= url('/chat/poll') ?>">

      <div class="chat__head">
        <?php if ($conversation['type'] === 'channel'): ?>
          <span class="conv__hash">#</span>
        <?php else: ?>
          <span class="avatar" style="background:<?= e($conversation['other_color'] ?? '#0C2B4A') ?>">
            <?= e(initials($conversation['display_name'])) ?>
          </span>
        <?php endif; ?>

        <div class="flex-1">
          <div class="chat__head-name"><?= e($conversation['display_name']) ?></div>
          <div class="chat__head-sub">
            <?php if ($conversation['type'] === 'channel'): ?>
              <?= count($members) ?> member(s)
              <?= $conversation['description'] ? ' · ' . e($conversation['description']) : '' ?>
            <?php else: ?>
              <?= $isOnline($conversation['other_seen'] ?? null)
                  ? '<span class="text-green">Online</span>'
                  : 'Last seen ' . e(time_ago($conversation['other_seen'] ?? null)) ?>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($conversation['type'] === 'channel'): ?>
          <div class="dropdown">
            <button class="icon-btn" type="button" data-dropdown><?= icon('more') ?></button>
            <div class="dropdown__menu">
              <?php foreach ($members as $m): ?>
                <span class="dropdown__item" style="cursor:default">
                  <span class="avatar avatar--sm" style="background:<?= e($m['avatar_color']) ?>">
                    <?= e(initials($m['name'])) ?>
                  </span>
                  <span class="flex-1"><?= e($m['name']) ?></span>

                  <?php if (!empty($canModerate) && (int) $m['id'] !== (int) $conversation['created_by']): ?>
                    <?php // Removing somebody is a click here rather than a trip to a settings page. ?>
                    <form method="post"
                          action="<?= url('/chat/' . $conversationId . '/members/' . $m['id'] . '/remove') ?>"
                          data-confirm="Remove <?= e($m['name']) ?> from #<?= e($conversation['name']) ?>?"
                          style="display:inline">
                      <?= csrf_field() ?>
                      <button class="icon-btn icon-btn--sm" type="submit" title="Remove from channel">
                        <?= icon('x') ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>

              <?php if (!empty($canModerate)): ?>
                <div class="dropdown__divider"></div>
                <button class="dropdown__item" type="button" data-modal-open="add-members">
                  <?= icon('plus') ?> Add people
                </button>
              <?php endif; ?>

              <div class="dropdown__divider"></div>
              <form method="post" action="<?= url('/chat/' . $conversationId . '/leave') ?>"
                    data-confirm="Leave #<?= e($conversation['name']) ?>?">
                <?= csrf_field() ?>
                <button class="dropdown__item dropdown__item--danger" type="submit">
                  <?= icon('log-out') ?> Leave channel
                </button>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="chat__messages" id="chat-messages">
        <?php if (!$messages): ?>
          <div class="chat__empty">
            <div>
              <div class="empty__title">No messages yet</div>
              <p class="empty__text">Say hello — this is the start of the conversation.</p>
            </div>
          </div>
        <?php endif; ?>

        <?php foreach ($messages as $m):
            $day = date('Y-m-d', strtotime($m['created_at']));
            $mine = (int) $m['user_id'] === (int) $me['id'];
        ?>
          <?php if ($day !== $lastDay): ?>
            <?php $lastDay = $day; ?>
            <div class="chat__day">
              <span>
                <?php
                  if ($day === date('Y-m-d')) {
                      echo 'Today';
                  } elseif ($day === date('Y-m-d', strtotime('-1 day'))) {
                      echo 'Yesterday';
                  } else {
                      echo e(fdate($m['created_at']));
                  }
                ?>
              </span>
            </div>
          <?php endif; ?>

          <div class="msg <?= $mine ? 'msg--mine' : '' ?>">
            <span class="avatar avatar--sm" style="background:<?= e($m['avatar_color']) ?>">
              <?= e(initials($m['author'])) ?>
            </span>
            <div class="msg__bubble">
              <div class="msg__author"><?= e($m['author']) ?></div>
              <?php if ($m['body']): ?>
                <div class="msg__body"><?= \App\Services\Mentions::highlight(e($m['body']), $members ?? []) ?></div>
              <?php endif; ?>
              <?php if ($m['attachment_path']): ?>
                <a class="msg__file" href="<?= url('files/' . $m['attachment_path']) ?>"
                   target="_blank" rel="noopener">
                  <?= icon('paperclip') ?> <?= e($m['attachment_name'] ?: 'Attachment') ?>
                </a>
              <?php endif; ?>
              <div class="msg__time"><?= e(date('H:i', strtotime($m['created_at']))) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="chat__composer">
        <form id="chat-form" method="post" action="<?= url('/chat/send') ?>"
              enctype="multipart/form-data" data-no-guard>
          <?= csrf_field() ?>
          <input type="hidden" name="conversation_id" value="<?= (int) $conversationId ?>">

          <label class="icon-btn" for="chat-file" title="Attach a file" style="flex:0 0 auto">
            <?= icon('paperclip') ?>
          </label>
          <input class="hidden" type="file" id="chat-file" name="attachment">

          <textarea class="textarea" id="chat-input" name="body" rows="1"
                    placeholder="Write a message… (Enter to send, Shift+Enter for a new line)"></textarea>

          <button class="btn btn--primary" type="submit" data-no-guard>
            <?= icon('send') ?> Send
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="modal-backdrop" id="new-channel">
  <div class="modal">
    <form method="post" action="<?= url('/chat/channels') ?>">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="modal__title">Create a channel</div>
        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
      </div>
      <div class="modal__body">
        <div class="field mb-12">
          <label class="label" for="channel_name">Channel name <span class="req">*</span></label>
          <div class="input-group">
            <span class="input-group__addon input-group__addon--pre">#</span>
            <input class="input" id="channel_name" name="name" required maxlength="60"
                   placeholder="production">
          </div>
          <span class="field-hint">Letters, numbers and dashes. e.g. production, sales, web-projects.</span>
        </div>

        <div class="field mb-12">
          <label class="label" for="channel_desc">What is it for?</label>
          <input class="input" id="channel_desc" name="description" maxlength="255"
                 placeholder="e.g. Print jobs in production this week">
        </div>

        <div class="field">
          <label class="label">Add members</label>
          <div style="max-height:190px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r);padding:10px">
            <?php foreach ($colleagues as $u): ?>
              <label class="check mb-8">
                <input type="checkbox" name="members[]" value="<?= (int) $u['id'] ?>">
                <span class="check__text">
                  <strong><?= e($u['name']) ?></strong>
                  <span><?= e($u['job_title'] ?: label_of($u['role'])) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
            <?php if (!$colleagues): ?>
              <p class="text-sm text-muted mb-0">No other team members yet.</p>
            <?php endif; ?>
          </div>
          <span class="field-hint">You are added automatically.</span>
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit"><?= icon('hash') ?> Create channel</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="new-dm">
  <div class="modal modal--sm">
    <div class="modal__head">
      <div class="modal__title">Start a direct message</div>
      <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <div class="modal__body" style="max-height:420px;overflow-y:auto">
      <?php if (!$colleagues): ?>
        <p class="text-sm text-muted mb-0">There are no other team members yet.</p>
      <?php else: ?>
        <?php foreach ($colleagues as $u): ?>
          <a class="conv" href="<?= url('/chat/with/' . $u['id']) ?>" style="border-radius:var(--r)">
            <span class="avatar" style="background:<?= e($u['avatar_color']) ?>">
              <?= e(initials($u['name'])) ?>
            </span>
            <span class="conv__meta">
              <span class="conv__name"><?= e($u['name']) ?></span>
              <span class="conv__preview"><?= e($u['job_title'] ?: label_of($u['role'])) ?></span>
            </span>
            <span class="conv__right">
              <?php if ($isOnline($u['last_seen_at'])): ?>
                <span class="badge badge--green text-xs">Online</span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($conversation && $conversation['type'] === 'channel' && !empty($canModerate)): ?>
  <?php
    // Only people not already in it — offering somebody who is already a
    // member just produces a silent no-op and looks broken.
    $memberIds = array_map(static fn($m) => (int) $m['id'], $members);
    $canAdd    = array_values(array_filter(
        $colleagues,
        static fn($u) => !in_array((int) $u['id'], $memberIds, true)
    ));
  ?>
  <div class="modal-backdrop" id="add-members">
    <div class="modal">
      <form method="post" action="<?= url('/chat/' . $conversationId . '/members') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Add people to #<?= e($conversation['name']) ?></div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <?php if (!$canAdd): ?>
            <p class="text-sm text-muted mb-0">Everyone on the team is already in this channel.</p>
          <?php else: ?>
            <div class="checkgrid">
              <?php foreach ($canAdd as $u): ?>
                <label class="check-row">
                  <input type="checkbox" name="user_ids[]" value="<?= (int) $u['id'] ?>">
                  <span>
                    <strong><?= e($u['name']) ?></strong>
                    <span class="text-xs text-muted d-block"><?= e($u['job_title'] ?: label_of($u['role'])) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="text-xs text-muted mt-8">
              They are emailed to say they have been added, and can read the
              channel's history from the moment they join.
            </div>
          <?php endif; ?>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <?php if ($canAdd): ?>
            <button class="btn btn--primary" type="submit">Add to channel</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
