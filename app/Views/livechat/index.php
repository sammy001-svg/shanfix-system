<?php
/**
 * The live chat desk.
 *
 * Three columns: the queue, the conversation, and who we are talking to.
 * The third column is the one that makes this worth having over a bare
 * message list — whoever answers can see the page the visitor is on and
 * whether they are already a customer, before typing a word.
 *
 * The queue puts waiting conversations first, oldest at the top, because
 * the person who has waited longest is the person about to give up.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$c    = $conversation;
$tabs = [
    'active'  => ['Open',    $counts['active']],
    'waiting' => ['Waiting', $counts['waiting']],
    'mine'    => ['Mine',    $counts['mine']],
    'closed'  => ['Closed',  null],
];

/** Keeps the department filter when switching tabs. */
$tabUrl = static function (string $key) use ($deptFilter): string {
    $q = ['show' => $key] + ($deptFilter > 0 ? ['department' => $deptFilter] : []);
    return url('/livechat?' . http_build_query($q));
};

$ago = static function (?string $when): string {
    if (!$when) {
        return '';
    }

    $mins = max(0, (int) floor((time() - strtotime($when)) / 60));

    return match (true) {
        $mins < 1    => 'just now',
        $mins < 60   => $mins . 'm',
        $mins < 1440 => floor($mins / 60) . 'h',
        default      => floor($mins / 1440) . 'd',
    };
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Live chat</h1>
    <div class="page-head__sub">
      <?php if ($atTheDesk): ?>
        <span class="badge badge--green"><span class="badge__dot"></span> Desk is open</span>
      <?php else: ?>
        <span class="badge badge--amber">Outside office hours — visitors are told so</span>
      <?php endif; ?>
      <?php if ($counts['overdue'] > 0): ?>
        <span class="badge badge--red"><?= (int) $counts['overdue'] ?> waiting over 5 minutes</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if ($canManage): ?>
      <a class="btn btn--outline" href="<?= e(url('/livechat/departments')) ?>">
        <?= icon('users') ?> Departments
      </a>
    <?php endif; ?>
  </div>
</div>

<?php // The desk keeps itself current: new messages in the open
      // conversation appear as they arrive, and the queue refreshes when
      // something new comes in. data-* hands the script what it needs. ?>
<div class="chat lc<?= $c ? '' : ' lc--empty' ?>" data-desk
     data-poll="<?= e(url('/livechat/poll')) ?>"
     data-active="<?= (int) $counts['active'] ?>"
     data-waiting="<?= (int) $counts['waiting'] ?>">

  <?php // ── The queue ─────────────────────────────────────────────── ?>
  <div class="chat__list">
    <div class="chat__list-head">
      <?php foreach ($tabs as $key => [$label, $n]): ?>
        <a class="lc__tab<?= $show === $key ? ' is-on' : '' ?>" href="<?= e($tabUrl($key)) ?>">
          <?= e($label) ?><?php if ($n): ?> <span class="lc__tab-n"><?= (int) $n ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (count($departments) > 1): ?>
      <div class="chat__search">
        <select class="input" onchange="location.href=this.value">
          <option value="<?= e(url('/livechat?show=' . $show)) ?>">All departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= e(url('/livechat?show=' . $show . '&department=' . $d['id'])) ?>"
                    <?= $deptFilter === (int) $d['id'] ? 'selected' : '' ?>>
              <?= e($d['name']) ?><?= $d['waiting'] > 0 ? ' (' . (int) $d['waiting'] . ' waiting)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="chat__scroll">
      <?php if (!$conversations): ?>
        <div class="chat__empty">
          <?= icon('message') ?>
          <p class="text-sm text-muted">Nothing here.</p>
        </div>
      <?php else: ?>
        <?php foreach ($conversations as $row): ?>
          <a class="conv<?= $c && (int) $c['id'] === (int) $row['id'] ? ' is-active' : '' ?><?= $row['status'] === 'waiting' ? ' conv--waiting' : '' ?>"
             href="<?= e(url('/livechat/' . $row['id'] . '?show=' . $show)) ?>">
            <div class="conv__meta">
              <div class="conv__name">
                <?= e($row['visitor_name'] ?: 'Visitor ' . $row['ref']) ?>
                <?php if ($row['client_name']): ?>
                  <span class="badge badge--navy text-xs"><?= e($row['client_name']) ?></span>
                <?php endif; ?>
              </div>
              <div class="conv__preview"><?= e(mb_substr((string) $row['last_message'], 0, 60)) ?></div>
              <div class="lc__row-foot">
                <?= e($row['department'] ?: 'No department') ?>
                <?php if ($row['agent']): ?>
                  &middot; <?= e($row['agent']) ?>
                <?php elseif ($row['status'] === 'waiting'): ?>
                  &middot; <span class="lc__unclaimed">nobody yet</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="conv__right">
              <span class="conv__time"><?= e($ago($row['last_visitor_at'] ?: $row['created_at'])) ?></span>
              <?php if ($row['unread'] > 0): ?>
                <span class="conv__unread"><?= (int) $row['unread'] ?></span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php // ── The conversation ──────────────────────────────────────── ?>
  <?php if (!$c): ?>
    <div class="chat__panel">
      <div class="chat__empty">
        <?= icon('message') ?>
        <h2 class="card__title">Nobody selected</h2>
        <p class="text-sm text-muted">
          Pick a conversation on the left. Anyone waiting is at the top.
        </p>
      </div>
    </div>
  <?php else: ?>
    <div class="chat__panel" data-conversation="<?= (int) $c['id'] ?>">
      <div class="chat__head">
        <div style="flex:1;min-width:0">
          <div class="chat__head-name">
            <?= e($c['visitor_name'] ?: 'Visitor ' . $c['ref']) ?>
            <span class="lc__ref"><?= e($c['ref']) ?></span>
          </div>
          <div class="chat__head-sub">
            <?= e($c['department_name'] ?: 'No department') ?>
            <?php if ($c['agent_name']): ?>
              &middot; with <?= e($c['agent_name']) ?>
            <?php endif; ?>
            <?php if ($c['status'] === 'closed'): ?>
              &middot; <span class="badge badge--grey">Closed</span>
            <?php elseif ($c['status'] === 'waiting'): ?>
              &middot; <span class="badge badge--amber">Waiting</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($c['status'] !== 'closed' && !$c['assigned_user_id']): ?>
          <form method="post" action="<?= e(url('/livechat/' . $c['id'] . '/claim')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--primary btn--sm"><?= icon('check') ?> Take it</button>
          </form>
        <?php endif; ?>

        <?php if ($c['status'] === 'closed'): ?>
          <form method="post" action="<?= e(url('/livechat/' . $c['id'] . '/reopen')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--outline btn--sm">Reopen</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= e(url('/livechat/' . $c['id'] . '/close')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--ghost btn--sm">Close</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="chat__messages" id="lcMessages">
        <?php foreach ($messages as $m): ?>
          <?php
            $mine  = $m['sender'] === 'staff';
            $class = 'lc__msg lc__msg--' . $m['sender'] . ((int) $m['is_note'] === 1 ? ' lc__msg--note' : '');
          ?>
          <div class="<?= e($class) ?>" data-mid="<?= (int) $m['id'] ?>">
            <?php if ($m['sender'] !== 'system'): ?>
              <div class="lc__msg-who">
                <?= e($m['sender_name'] ?: ($mine ? 'Us' : 'Visitor')) ?>
                <?php if ((int) $m['is_note'] === 1): ?>
                  <span class="lc__note-tag">private note</span>
                <?php endif; ?>
                <span class="lc__msg-at"><?= e(date('H:i', strtotime($m['created_at']))) ?></span>
              </div>
            <?php endif; ?>
            <div class="lc__msg-body"><?= e($m['body']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($c['status'] !== 'closed'): ?>
        <form class="chat__composer" method="post" id="lcReply"
              action="<?= e(url('/livechat/' . $c['id'] . '/reply')) ?>">
          <?= csrf_field() ?>

          <?php if ($canned): ?>
            <select class="input lc__canned" aria-label="Saved replies">
              <option value="">Saved replies…</option>
              <?php foreach ($canned as $k): ?>
                <option value="<?= e($k['body']) ?>"><?= e($k['title']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>

          <textarea class="input" name="message" rows="2" id="lcBody"
                    placeholder="Type your reply. Shift+Enter for a new line." required></textarea>

          <div class="lc__composer-foot">
            <?php // A note is for whoever picks this up next. It is never
                  // sent to the visitor, which is worth saying on the
                  // button rather than in a tooltip nobody opens. ?>
            <label class="check">
              <input type="checkbox" name="note" value="1">
              <span class="check__text">Private note — the visitor will not see this</span>
            </label>
            <button class="btn btn--primary"><?= icon('send') ?> Send</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <?php // ── Who we are talking to ───────────────────────────────── ?>
    <aside class="lc__side">
      <div class="lc__side-block">
        <div class="lc__side-label">Who</div>
        <div class="lc__side-value"><?= e($c['visitor_name'] ?: 'Did not say') ?></div>
        <?php if ($c['visitor_email']): ?>
          <a class="lc__side-link" href="mailto:<?= e($c['visitor_email']) ?>"><?= e($c['visitor_email']) ?></a>
        <?php endif; ?>
        <?php if ($c['visitor_phone']): ?>
          <a class="lc__side-link" href="tel:<?= e($c['visitor_phone']) ?>"><?= e($c['visitor_phone']) ?></a>
        <?php endif; ?>
      </div>

      <?php if ($c['client']): ?>
        <div class="lc__side-block">
          <div class="lc__side-label">Already a customer</div>
          <a class="lc__side-value" href="<?= e(url('/clients/' . $c['client']['id'])) ?>">
            <?= e($c['client']['name']) ?>
          </a>
          <div class="lc__side-muted"><?= e($c['client']['client_code']) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($c['page_url']): ?>
        <div class="lc__side-block">
          <div class="lc__side-label">Reading</div>
          <a class="lc__side-link" href="<?= e($c['page_url']) ?>" target="_blank" rel="noopener">
            <?= e($c['page_title'] ?: $c['page_url']) ?>
          </a>
        </div>
      <?php endif; ?>

      <div class="lc__side-block">
        <div class="lc__side-label">Waiting time</div>
        <div class="lc__side-value">
          <?php if ($c['waited'] !== null): ?>
            Answered in <?= e($c['waited'] < 60 ? $c['waited'] . 's' : round($c['waited'] / 60) . ' min') ?>
          <?php else: ?>
            <span class="lc__unclaimed">Still waiting — <?= e($ago($c['created_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($c['rating']): ?>
        <div class="lc__side-block">
          <div class="lc__side-label">They rated this</div>
          <div class="lc__side-value"><?= str_repeat('★', (int) $c['rating']) ?><?= str_repeat('☆', 5 - (int) $c['rating']) ?></div>
          <?php if ($c['rating_comment']): ?>
            <div class="lc__side-muted"><?= e($c['rating_comment']) ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($c['status'] !== 'closed'): ?>
        <div class="lc__side-block">
          <div class="lc__side-label">Move it</div>

          <form method="post" action="<?= e(url('/livechat/' . $c['id'] . '/transfer')) ?>" class="mb-8">
            <?= csrf_field() ?>
            <select class="input" name="department_id" onchange="this.form.submit()">
              <?php foreach ($allDepartments as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $c['department_id'] ? 'selected' : '' ?>>
                  <?= e($d['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>

          <?php if ($colleagues): ?>
            <form method="post" action="<?= e(url('/livechat/' . $c['id'] . '/hand')) ?>">
              <?= csrf_field() ?>
              <select class="input" name="user_id" onchange="this.form.submit()">
                <option value="">Hand to a colleague…</option>
                <?php foreach ($colleagues as $u): ?>
                  <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>

          <?php if ($c['assigned_user_id'] && (int) $c['assigned_user_id'] === (int) $me['id']): ?>
            <form method="post" action="<?= e(url('/livechat/' . $c['id'] . '/release')) ?>" class="mt-8">
              <?= csrf_field() ?>
              <button class="btn btn--ghost btn--sm">Put back in the queue</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </aside>
  <?php endif; ?>
</div>
