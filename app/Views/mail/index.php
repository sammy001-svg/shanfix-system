<?php
/**
 * The mailbox: folders, the list, and the message being read.
 *
 * Three columns, as every desktop mail client has taught people to
 * expect. The message body is not in this page at all — it is loaded
 * into a sandboxed frame from its own address, so nothing a sender
 * writes can reach this page or the session behind it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$m = $message;
$f = rawurlencode($folder);
$currentLabel = 'Inbox';
foreach ($folders as $fo) {
    if ($fo['name'] === $folder) {
        $currentLabel = $fo['label'];
    }
}

$roleIcon = ['inbox' => 'inbox', 'sent' => 'send', 'drafts' => 'edit', 'trash' => 'trash', 'spam' => 'alert-triangle', 'archive' => 'archive'];

$when = static function (?int $ts): string {
    if (!$ts) {
        return '';
    }
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('H:i', $ts);
    }
    return date('Y', $ts) === date('Y') ? date('j M', $ts) : date('j M Y', $ts);
};

$who = static function (?array $a): string {
    if (!$a) {
        return '(unknown)';
    }
    return $a['name'] !== '' ? $a['name'] : $a['email'];
};

$isSentLike = in_array(\App\Services\Mailbox\ImapClient::role($folder), ['sent', 'drafts'], true);
$listUrl = static fn(array $q = []) => url('/mail?' . http_build_query(array_filter(
    $q + ['folder' => $folder, 'q' => $search], static fn($v) => $v !== '' && $v !== null && $v !== 0
)));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Email</h1>
    <div class="page-head__sub"><?= e($box->email()) ?></div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= e(url('/mail/setup')) ?>"><?= icon('settings') ?> Mailbox settings</a>
    <a class="btn btn--primary" href="<?= e(url('/mail/compose')) ?>"><?= icon('edit') ?> New message</a>
  </div>
</div>

<div class="mb<?= $m ? ' mb--reading' : '' ?>">

  <?php // ── Folders ──────────────────────────────────────────────────── ?>
  <nav class="mb__folders" aria-label="Folders">
    <?php foreach ($folders as $fo): ?>
      <a class="mb__folder<?= $fo['name'] === $folder ? ' is-on' : '' ?>"
         href="<?= e(url('/mail?folder=' . rawurlencode($fo['name']))) ?>">
        <?= icon($roleIcon[$fo['role'] ?? ''] ?? 'layers') ?>
        <span class="mb__folder-name"><?= e($fo['label']) ?></span>
        <?php if ($fo['unseen'] > 0): ?><span class="mb__count"><?= (int) $fo['unseen'] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php // ── The list ─────────────────────────────────────────────────── ?>
  <section class="mb__list">
    <form class="mb__search" method="get" action="<?= e(url('/mail')) ?>">
      <input type="hidden" name="folder" value="<?= e($folder) ?>">
      <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search <?= e($currentLabel) ?>">
    </form>

    <form method="post" action="<?= e(url('/mail/action')) ?>" id="mbBulk" class="mb__bulk" hidden>
      <?= csrf_field() ?>
      <input type="hidden" name="folder" value="<?= e($folder) ?>">
      <span class="mb__bulk-n" data-bulk-count></span>
      <button class="btn btn--ghost btn--sm" name="do" value="read">Mark read</button>
      <button class="btn btn--ghost btn--sm" name="do" value="unread">Mark unread</button>
      <button class="btn btn--ghost btn--sm" name="do" value="delete">Delete</button>
    </form>

    <div class="mb__rows">
      <?php if (!$messages): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('inbox') ?></div>
          <div class="empty__title"><?= $search !== '' ? 'Nothing matches that' : 'Nothing in ' . e($currentLabel) ?></div>
        </div>
      <?php endif; ?>

      <?php foreach ($messages as $row): ?>
        <?php $person = $isSentLike ? ('To: ' . $who($row['to'][0] ?? null)) : $who($row['from']); ?>
        <div class="mb__row<?= $row['seen'] ? '' : ' is-unread' ?><?= $row['uid'] === $uid ? ' is-on' : '' ?>">
          <input type="checkbox" class="mb__pick" value="<?= (int) $row['uid'] ?>" form="mbBulk" name="uids[]"
                 aria-label="Select this message">
          <a class="mb__row-link" href="<?= e($listUrl(['uid' => $row['uid'], 'page' => $page > 1 ? $page : null])) ?>">
            <span class="mb__row-top">
              <span class="mb__from"><?= e($person) ?></span>
              <span class="mb__when"><?= e($when($row['date'])) ?></span>
            </span>
            <span class="mb__subject">
              <?php if ($row['flagged']): ?><span class="mb__flag" title="Flagged">&#9873;</span><?php endif; ?>
              <?= e($row['subject']) ?>
              <?php if ($row['has_attachments']): ?><span class="mb__clip" title="Has attachments"><?= icon('paperclip') ?></span><?php endif; ?>
            </span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="mb__pager">
        <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e($listUrl(['page' => $page - 1])) ?>">&lsaquo; Newer</a><?php endif; ?>
        <span class="text-xs text-muted">Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= (int) $total ?> messages</span>
        <?php if ($page < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= e($listUrl(['page' => $page + 1])) ?>">Older &rsaquo;</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php // ── Reading ──────────────────────────────────────────────────── ?>
  <section class="mb__reader">
    <?php if (!$m): ?>
      <div class="empty">
        <div class="empty__icon"><?= icon('mail') ?></div>
        <div class="empty__title">Nothing selected</div>
        <p class="text-sm text-muted">Choose a message to read it here.</p>
      </div>
    <?php else: ?>
      <?php $base = '/mail/message/' . (int) $m['uid']; ?>
      <div class="mb__toolbar">
        <a class="btn btn--ghost btn--sm mb__back" href="<?= e($listUrl()) ?>">&lsaquo; Back</a>
        <a class="btn btn--outline btn--sm" href="<?= e(url('/mail/compose?mode=reply&folder=' . $f . '&uid=' . (int) $m['uid'])) ?>"><?= icon('arrow-left') ?> Reply</a>
        <a class="btn btn--outline btn--sm" href="<?= e(url('/mail/compose?mode=all&folder=' . $f . '&uid=' . (int) $m['uid'])) ?>">Reply all</a>
        <a class="btn btn--outline btn--sm" href="<?= e(url('/mail/compose?mode=forward&folder=' . $f . '&uid=' . (int) $m['uid'])) ?>"><?= icon('arrow-right') ?> Forward</a>

        <form method="post" action="<?= e(url('/mail/action')) ?>" class="mb__inline">
          <?= csrf_field() ?>
          <input type="hidden" name="folder" value="<?= e($folder) ?>">
          <input type="hidden" name="uids[]" value="<?= (int) $m['uid'] ?>">
          <input type="hidden" name="stay" value="1">
          <button class="btn btn--ghost btn--sm" name="do" value="unread">Mark unread</button>
          <button class="btn btn--ghost btn--sm" name="do" value="delete"><?= icon('trash') ?> Delete</button>
          <select class="input input--sm mb__move" name="to" data-move aria-label="Move to folder">
            <option value="">Move to…</option>
            <?php foreach ($folders as $fo): if ($fo['name'] === $folder) continue; ?>
              <option value="<?= e($fo['name']) ?>"><?= e($fo['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div class="mb__head">
        <h2 class="mb__title"><?= e($m['subject'] !== '' ? $m['subject'] : '(no subject)') ?></h2>
        <div class="mb__meta">
          <span class="mb__avatar"><?= e(mb_strtoupper(mb_substr($who($m['from']), 0, 1))) ?></span>
          <div>
            <div><strong><?= e($m['from']['name'] ?? '') ?></strong>
              <span class="text-muted">&lt;<?= e($m['from']['email'] ?? '') ?>&gt;</span></div>
            <div class="text-xs text-muted">
              To <?= e(implode(', ', array_map($who, $m['to']))) ?>
              <?php if ($m['cc']): ?> · Cc <?= e(implode(', ', array_map($who, $m['cc']))) ?><?php endif; ?>
            </div>
          </div>
          <div class="mb__date text-xs text-muted"><?= e($m['date'] ? date('D j M Y, H:i', $m['date']) : '') ?></div>
        </div>
      </div>

      <?php $files = array_filter($m['attachments'], static fn($a) => !$a['inline']); ?>
      <?php if ($files): ?>
        <div class="mb__files">
          <?php foreach ($m['attachments'] as $i => $a): if ($a['inline']) continue; ?>
            <a class="mb__file" href="<?= e(url($base . '/attachment/' . $i . '?folder=' . $f)) ?>">
              <?= icon('paperclip') ?>
              <span class="mb__file-name"><?= e($a['name']) ?></span>
              <span class="text-xs text-muted"><?= e($a['size'] >= 1048576 ? round($a['size'] / 1048576, 1) . ' MB' : max(1, (int) round($a['size'] / 1024)) . ' KB') ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
        // Counted here from the message already in hand, rather than by
        // asking the mail server a second time.
        $heldBack = $m['html'] !== '' ? \App\Services\Mailbox\HtmlSanitizer::clean($m['html'], false)['blocked'] : 0;
      ?>
      <?php if ($heldBack > 0): ?>
        <div class="mb__images" data-images-note>
          <?= icon('eye-off') ?> Pictures from the internet are hidden, so the sender cannot tell you opened this.
          <button type="button" class="btn btn--ghost btn--sm" data-show-images>Show pictures</button>
        </div>
      <?php endif; ?>

      <?php // No allow-scripts and no allow-same-origin: the message runs no
            // code and cannot reach this page. Links open in a new tab. ?>
      <iframe class="mb__body" data-mail-body
              sandbox="allow-popups allow-popups-to-escape-sandbox"
              referrerpolicy="no-referrer"
              src="<?= e(url($base . '/body?folder=' . $f)) ?>"
              data-images-src="<?= e(url($base . '/body?folder=' . $f . '&images=1')) ?>"
              title="Message"></iframe>
    <?php endif; ?>
  </section>
</div>
