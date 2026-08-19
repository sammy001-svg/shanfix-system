<?php
require_once APP_PATH . '/Views/partials/icons.php';

$tabUrl = static fn(string $t): string => url('/settings?tab=' . $t);

$latest    = $latest ?? null;
$stale     = $latest === null || (time() - $latest['at']) > ($warnDays * 86400);
$totalSize = array_sum(array_column($backups, 'bytes'));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Backups</h1>
    <div class="page-head__sub">A copy of everything the business has recorded, and how to get it off this server.</div>
  </div>
  <div class="page-head__actions">
    <form method="post" action="<?= url('/settings/backups') ?>">
      <?= csrf_field() ?>
      <button class="btn btn--primary" type="submit"><?= icon('download') ?> Back up now</button>
    </form>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab" href="<?= e($tabUrl('company')) ?>"><?= icon('briefcase') ?> Company</a>
    <a class="tab" href="<?= e($tabUrl('documents')) ?>"><?= icon('file-text') ?> Documents &amp; VAT</a>
    <a class="tab" href="<?= e($tabUrl('payments')) ?>"><?= icon('smartphone') ?> M-Pesa / KopoKopo</a>
    <a class="tab" href="<?= e($tabUrl('messaging')) ?>"><?= icon('send') ?> Email &amp; SMS</a>
    <a class="tab" href="<?= e($tabUrl('categories')) ?>"><?= icon('layers') ?> Categories</a>
    <a class="tab is-active" href="<?= url('/settings/backups') ?>"><?= icon('archive') ?> Backups</a>
  </nav>
</div>

<?php if ($stale): ?>
  <div class="alert alert--error">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <?php if ($latest === null): ?>
        <strong>There is no backup of this system.</strong>
        Everything the business has quoted, invoiced, been paid and is owed exists
        in one place. Take one now, then download it.
      <?php else: ?>
        <strong>The most recent backup is from <?= e(date('j M Y', $latest['at'])) ?>.</strong>
        Either the scheduled task has stopped running, or nothing has run it yet.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid-sidebar">
  <div>

    <div class="card">
      <div class="card__head">
        <?= icon('archive') ?>
        <div>
          <div class="card__title">Copies on the server</div>
          <div class="card__sub">
            <?= count($backups) ?> backup<?= count($backups) === 1 ? '' : 's' ?>,
            <?= e(human_bytes($totalSize)) ?> in total
          </div>
        </div>
      </div>

      <?php if (!$backups): ?>
        <div class="card__body">
          <p class="text-sm text-muted mb-0">Nothing yet. Use <strong>Back up now</strong> above.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Taken</th>
                <th style="width:110px">Size</th>
                <th style="width:100px">Files</th>
                <th style="width:280px"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($backups as $i => $b): ?>
                <tr>
                  <td>
                    <strong><?= e(date('j M Y', $b['at'])) ?></strong>
                    <span class="text-xs text-muted d-block">
                      <?= e(date('H:i', $b['at'])) ?>
                      <?= $i === 0 ? ' — most recent' : '' ?>
                    </span>
                  </td>
                  <td><?= e(human_bytes($b['bytes'])) ?></td>
                  <td>
                    <?= $b['uploads']
                        ? '<span class="badge badge--green">included</span>'
                        : '<span class="text-xs text-muted">database only</span>' ?>
                  </td>
                  <td class="text-right">
                    <?php // Getting it off the server is the point, so it leads. ?>
                    <a class="btn btn--sm btn--primary"
                       href="<?= url('/settings/backups/' . $b['name'] . '/download') ?>">
                      <?= icon('download') ?> Database
                    </a>

                    <?php if ($b['uploads']): ?>
                      <a class="btn btn--sm btn--ghost"
                         href="<?= url('/settings/backups/' . $b['name'] . '/download?part=uploads') ?>">
                        Files
                      </a>
                    <?php endif; ?>

                    <form method="post" action="<?= url('/settings/backups/' . $b['name'] . '/verify') ?>" style="display:inline">
                      <?= csrf_field() ?>
                      <button class="btn btn--sm btn--ghost" type="submit" title="Read it back and check it is complete">
                        Check
                      </button>
                    </form>

                    <?php if (count($backups) > 1): ?>
                      <form method="post" action="<?= url('/settings/backups/' . $b['name'] . '/delete') ?>"
                            data-confirm="Delete the backup from <?= e(date('j M Y H:i', $b['at'])) ?>?"
                            style="display:inline">
                        <?= csrf_field() ?>
                        <button class="btn btn--sm btn--danger" type="submit"><?= icon('trash') ?></button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head">
        <?= icon('refresh') ?>
        <div>
          <div class="card__title">Putting one back</div>
          <div class="card__sub">Worth reading before you need it</div>
        </div>
      </div>
      <div class="card__body">
        <p class="text-sm">
          The database file is ordinary gzipped SQL. In cPanel, open
          <strong>phpMyAdmin</strong>, choose the database, then <strong>Import</strong>
          and select the <code>.sql.gz</code> file — it accepts the compressed file as it is.
        </p>
        <p class="text-sm">
          If you have shell access, the file's own header carries the command:
        </p>
        <pre class="code">gunzip &lt; shanfix-YYYY-MM-DD_HHMMSS.sql.gz | mysql -u USER -p DATABASE</pre>
        <p class="text-sm">
          The restore drops and recreates each table as it goes, so it replaces
          what is there rather than merging into it. The files archive unzips
          into <code>storage/uploads</code>.
        </p>
        <p class="text-sm text-muted mb-0">
          <strong>Check</strong> on a backup reads the whole file back and confirms it is
          complete. It is worth doing on the copy you keep, because the day a backup
          turns out to be truncated is always the worst possible day to find out.
        </p>
      </div>
    </div>

  </div>

  <div>
    <div class="card">
      <div class="card__head">
        <?= icon('clock') ?>
        <div>
          <div class="card__title">Schedule</div>
        </div>
      </div>
      <form method="post" action="<?= url('/settings/backups/schedule') ?>">
        <?= csrf_field() ?>
        <div class="card__body">

          <div class="field">
            <label class="check">
              <input type="checkbox" name="backup_enabled" value="1"
                     <?= setting('backup_enabled', '1') === '1' ? 'checked' : '' ?>>
              <span>
                <strong>Back up automatically</strong>
                <span class="field-hint">Runs from the same scheduled task that sends reminders.</span>
              </span>
            </label>
          </div>

          <div class="field">
            <label class="check">
              <input type="checkbox" name="backup_uploads" value="1"
                     <?= setting('backup_uploads', '1') === '1' ? 'checked' : '' ?>>
              <span>
                <strong>Include uploaded files</strong>
                <span class="field-hint">
                  Artwork, proofs and job files. Without these a restored system
                  has every record but none of the attachments.
                </span>
              </span>
            </label>
          </div>

          <div class="field">
            <label class="label" for="backup_every_hours">How often</label>
            <input class="input" type="number" min="1" max="168"
                   id="backup_every_hours" name="backup_every_hours"
                   value="<?= e(setting('backup_every_hours', '24')) ?>">
            <span class="field-hint">Hours between copies. 24 is once a day.</span>
          </div>

          <div class="field">
            <label class="label" for="backup_keep">Keep</label>
            <input class="input" type="number" min="1" max="60"
                   id="backup_keep" name="backup_keep"
                   value="<?= e(setting('backup_keep', '7')) ?>">
            <span class="field-hint">
              How many to hold on the server. Older ones are deleted — hosting
              sells a fixed amount of disk, and a backup that fills it takes the
              website down with it.
            </span>
          </div>

          <div class="field">
            <label class="label" for="backup_warn_days">Warn after</label>
            <input class="input" type="number" min="1" max="90"
                   id="backup_warn_days" name="backup_warn_days"
                   value="<?= e(setting('backup_warn_days', '3')) ?>">
            <span class="field-hint">
              Days without a successful backup before the administrators are
              emailed. One that quietly stopped is worse than none, because
              everybody carries on believing there is one.
            </span>
          </div>

        </div>
        <div class="card__foot">
          <button class="btn btn--primary" type="submit">Save schedule</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card__head">
        <?= icon('alert-triangle') ?>
        <div>
          <div class="card__title">Keep one elsewhere</div>
        </div>
      </div>
      <div class="card__body">
        <p class="text-sm mb-0">
          These copies sit on the same server as the system they protect. That
          covers a bad upgrade or a deleted record, but not the account itself
          being lost. Download the most recent one regularly and keep it
          somewhere else — a laptop, an external drive, or cloud storage.
        </p>
      </div>
    </div>
  </div>
</div>
