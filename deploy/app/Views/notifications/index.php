<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Messages</h1>
    <div class="page-head__sub">Every email and SMS the system has tried to send, and what happened.</div>
  </div>
  <div class="page-head__actions">
    <form method="post" action="<?= url('/notifications/run') ?>" style="display:inline">
      <?= csrf_field() ?>
      <button class="btn btn--outline" type="submit"><?= icon('refresh') ?> Run queue now</button>
    </form>
    <a class="btn btn--primary" href="<?= url('/settings?tab=messaging') ?>">
      <?= icon('settings') ?> Messaging settings
    </a>
  </div>
</div>

<?php if (!$emailOn && !$smsOn): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      Neither email nor SMS is switched on.
      <a href="<?= url('/settings?tab=messaging') ?>">Configure them in Settings</a> to start
      sending documents to clients.
    </div>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--green">
    <div class="stat__label">Sent</div>
    <div class="stat__value"><?= number_format((int) $summary['sent']) ?></div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Waiting</div>
    <div class="stat__value"><?= (int) $summary['queued'] ?></div>
    <div class="stat__meta">Goes out on the next cron run</div>
  </div>
  <div class="stat <?= (int) $summary['failed'] > 0 ? 'stat--red' : 'stat--grey' ?>">
    <div class="stat__label">Failed</div>
    <div class="stat__value"><?= (int) $summary['failed'] ?></div>
    <div class="stat__meta">After all retries</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">SMS spend</div>
    <div class="stat__value"><?= e(money_short($summary['sms_cost'])) ?></div>
    <div class="stat__meta">As reported by the gateway</div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/notifications') ?>">
    <div class="field" style="min-width:230px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Recipient or subject" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="channel">Channel</label>
      <select class="select" id="channel" name="channel" data-auto-submit>
        <option value="">Both</option>
        <option value="email" <?= $filters['channel'] === 'email' ? 'selected' : '' ?>>Email</option>
        <option value="sms"   <?= $filters['channel'] === 'sms'   ? 'selected' : '' ?>>SMS</option>
      </select>
    </div>

    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status" data-auto-submit>
        <option value="">All</option>
        <?php foreach (['queued', 'sent', 'failed', 'cancelled'] as $s): ?>
          <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(label_of($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/notifications') ?>">Clear</a>
  </form>

  <?php if (!$notifications): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('send') ?></div>
      <div class="empty__title">Nothing sent yet</div>
      <p class="empty__text">
        Emails and texts to clients will be listed here with their delivery status.
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>When</th><th>Channel</th><th>Event</th><th>To</th>
            <th>Subject / message</th><th>Status</th><th class="actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($notifications as $n): ?>
            <tr>
              <td class="text-sm" style="white-space:nowrap">
                <?= e(fdate($n['created_at'], 'd M H:i')) ?>
                <?php if ($n['sent_at']): ?>
                  <div class="table__muted">sent <?= e(time_ago($n['sent_at'])) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $n['channel'] === 'email' ? 'badge--navy' : 'badge--green' ?>">
                  <?= e(strtoupper($n['channel'])) ?>
                </span>
              </td>
              <td class="text-sm text-muted"><?= e(label_of($n['event'])) ?></td>
              <td class="text-sm">
                <div class="truncate" style="max-width:180px"><?= e($n['recipient']) ?></div>
                <?php if ($n['client_name']): ?>
                  <div class="table__muted"><?= e($n['client_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm">
                <?= e(str_excerpt($n['subject'] ?: strip_tags((string) $n['body']), 52)) ?>
              </td>
              <td>
                <span class="badge <?= match ($n['status']) {
                    'sent'      => 'badge--green',
                    'queued'    => 'badge--amber',
                    'failed'    => 'badge--red',
                    default     => 'badge--grey',
                } ?>"><?= e(label_of($n['status'])) ?></span>
                <?php if ((int) $n['attempts'] > 1): ?>
                  <div class="table__muted"><?= (int) $n['attempts'] ?> attempts</div>
                <?php endif; ?>
                <?php if ($n['last_error']): ?>
                  <div class="table__muted text-red" title="<?= e($n['last_error']) ?>">
                    <?= e(str_excerpt($n['last_error'], 30)) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url('/notifications/' . $n['id']) ?>" title="View">
                  <?= icon('eye') ?>
                </a>
                <?php if (in_array($n['status'], ['failed', 'queued'], true)): ?>
                  <form method="post" action="<?= url('/notifications/' . $n['id'] . '/retry') ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <button class="btn btn--outline btn--sm" type="submit" title="Try again">
                      <?= icon('refresh') ?>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($notifications) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
