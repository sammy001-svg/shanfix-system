<?php
/**
 * @var array  $artwork
 * @var array  $files
 * @var array  $events
 * @var array  $designers
 * @var array  $statuses
 * @var string $shareLink
 */
require_once APP_PATH . '/Views/partials/icons.php';

$badge = match ($artwork['status']) {
    'approved', 'completed' => 'badge--green',
    'proof_sent'            => 'badge--navy',
    'changes_requested'     => 'badge--amber',
    'cancelled'             => 'badge--red',
    default                 => 'badge--grey',
};

$proofs   = array_values(array_filter($files, fn($f) => $f['file_type'] === 'proof'));
$others   = array_values(array_filter($files, fn($f) => $f['file_type'] !== 'proof'));
$latest   = $proofs[0] ?? null;
$canSend  = $latest !== null && can('artwork.design')
            && !in_array($artwork['status'], ['completed', 'cancelled'], true);
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($artwork['title']) ?></h1>
    <div class="page-head__sub">
      <?= e($artwork['request_number']) ?>
      · <a href="<?= url('/clients/' . $artwork['client_id']) ?>"><?= e($artwork['client_name']) ?></a>
      <?php if ($artwork['designer_name']): ?> · <?= e($artwork['designer_name']) ?><?php endif; ?>
      <?php if ($artwork['due_date']): ?> · due <?= e(fdate($artwork['due_date'])) ?><?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <span class="badge <?= $badge ?>"><?= e($statuses[$artwork['status']] ?? $artwork['status']) ?></span>

    <?php if ($artwork['status'] === 'approved' && can('artwork.manage')): ?>
      <form method="post" action="<?= url('/artwork/' . $artwork['id'] . '/production') ?>"
            style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn--primary" type="submit">
          <?= icon('printer') ?> Send to production
        </button>
      </form>
    <?php endif; ?>

    <?php if ($artwork['job_id']): ?>
      <a class="btn btn--outline" href="<?= url('/jobs/' . $artwork['job_id']) ?>">
        <?= icon('printer') ?> <?= e($artwork['job_number']) ?>
      </a>
    <?php endif; ?>

    <?php if (can('artwork.manage') && !$artwork['job_id']): ?>
      <a class="btn btn--outline" href="<?= url('/artwork/' . $artwork['id'] . '/edit') ?>">
        <?= icon('edit') ?> Edit
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="grid-sidebar">
  <div>

    <?php if ($artwork['brief'] || $artwork['specs']): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">The brief</div></div>
        <div class="card__body">
          <?php if ($artwork['brief']): ?>
            <p style="white-space:pre-wrap"><?= e($artwork['brief']) ?></p>
          <?php endif; ?>
          <?php if ($artwork['specs']): ?>
            <p class="text-sm text-muted mb-0"><strong>Specs:</strong> <?= e($artwork['specs']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <?= icon('image') ?>
        <div>
          <div class="card__title">Files</div>
          <div class="card__sub">Proofs are what the client sees; everything else is internal</div>
        </div>
      </div>

      <?php if (can('artwork.manage')): ?>
        <div class="card__body" style="border-bottom:1px solid var(--border)">
          <form method="post" action="<?= url('/artwork/' . $artwork['id'] . '/upload') ?>"
                enctype="multipart/form-data" class="form-grid form-grid--3">
            <?= csrf_field() ?>
            <div class="field">
              <label class="label" for="file">File</label>
              <input class="input" type="file" id="file" name="file" required>
            </div>
            <div class="field">
              <label class="label" for="file_type">Type</label>
              <select class="input" id="file_type" name="file_type">
                <?php if (can('artwork.design')): ?>
                  <option value="proof">Proof — for the client</option>
                  <option value="final">Final artwork</option>
                <?php endif; ?>
                <option value="reference">Reference from the client</option>
                <option value="draft">Working draft</option>
              </select>
            </div>
            <div class="field">
              <label class="label" for="notes">Note</label>
              <input class="input" id="notes" name="notes" maxlength="500"
                     placeholder="Shown to the client on a proof">
            </div>
            <div class="field field--full">
              <button class="btn btn--outline" type="submit"><?= icon('plus') ?> Upload</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if (!$files): ?>
        <div class="card__body">
          <div class="empty">
            <div class="empty__title">Nothing uploaded</div>
            <p class="empty__text">Upload the client's reference material or your first proof.</p>
          </div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>File</th><th style="width:110px">Type</th><th style="width:110px">Status</th><th style="width:130px">Added</th></tr>
            </thead>
            <tbody>
              <?php foreach ($files as $f): ?>
                <tr>
                  <td>
                    <a class="fw-600" href="<?= url('files/' . $f['file_path']) ?>"
                       target="_blank" rel="noopener"><?= e($f['file_name']) ?></a>
                    <div class="text-xs text-muted">
                      v<?= (int) $f['version'] ?>
                      · <?= e(number_format($f['file_size'] / 1024, 0)) ?> KB
                      · <?= e($f['uploaded_by_name'] ?: 'System') ?>
                    </div>
                    <?php if ($f['client_feedback']): ?>
                      <div class="alert alert--warning mt-8 mb-0" style="padding:8px 11px">
                        <?= icon('message') ?>
                        <div class="alert__body text-sm">
                          <strong>Client said:</strong> <?= e($f['client_feedback']) ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-sm"><?= e(label_of($f['file_type'])) ?></td>
                  <td>
                    <?php if ($f['file_type'] === 'proof'): ?>
                      <span class="badge <?= $f['status'] === 'approved' ? 'badge--green'
                          : ($f['status'] === 'rejected' ? 'badge--red' : 'badge--grey') ?>">
                        <?= e(label_of($f['status'])) ?>
                      </span>
                      <?php if ($f['decided_via'] === 'client'): ?>
                        <div class="text-xs text-muted mt-4">by the client</div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-sm text-muted"><?= e(time_ago($f['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head"><?= icon('activity') ?><div><div class="card__title">History</div></div></div>
      <?php if (!$events): ?>
        <div class="card__body"><p class="text-muted mb-0">Nothing yet.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <?php foreach ($events as $ev): ?>
                <tr>
                  <td>
                    <?= e($ev['note']) ?>
                    <div class="text-xs text-muted">
                      <?= e($ev['user_name'] ?: 'The client') ?> · <?= e(time_ago($ev['created_at'])) ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php
    $threadType  = 'artwork';
    $threadId    = (int) $artwork['id'];
    $threadTitle = $artwork['request_number'];
    require APP_PATH . '/Views/partials/thread.php';
    ?>

  </div>

  <aside>
    <?php if ($canSend): ?>
      <div class="card">
        <div class="card__head"><div><div class="card__title">Send for approval</div></div></div>
        <div class="card__body">
          <form method="post" action="<?= url('/artwork/' . $artwork['id'] . '/send') ?>">
            <?= csrf_field() ?>
            <label class="check">
              <input type="checkbox" name="channels[]" value="email" checked>
              <span class="check__text"><span>Email</span></span>
            </label>
            <label class="check">
              <input type="checkbox" name="channels[]" value="sms" checked>
              <span class="check__text"><span>SMS</span></span>
            </label>
            <button class="btn btn--primary btn--block mt-12" type="submit">
              <?= icon('send') ?> Send proof v<?= (int) $latest['version'] ?>
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($shareLink !== ''): ?>
      <div class="card">
        <div class="card__head"><div><div class="card__title">Client link</div></div></div>
        <div class="card__body">
          <input class="input" style="font-size:12.5px" value="<?= e($shareLink) ?>"
                 readonly onfocus="this.select()">
          <span class="field-hint">
            Anyone holding this link can approve the artwork — send it only to the client.
          </span>
          <?php if ($artwork['viewed_at']): ?>
            <p class="text-xs text-muted mt-8 mb-0">
              Opened <?= e(time_ago($artwork['viewed_at'])) ?>
            </p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (can('artwork.assign')): ?>
      <div class="card">
        <div class="card__head"><div><div class="card__title">Allocate</div></div></div>
        <div class="card__body">
          <form method="post" action="<?= url('/artwork/' . $artwork['id'] . '/assign') ?>">
            <?= csrf_field() ?>
            <div class="field">
              <select class="input" name="assigned_to">
                <option value="">Choose a designer…</option>
                <?php foreach ($designers as $d): ?>
                  <option value="<?= (int) $d['id'] ?>"
                          <?= (int) $artwork['assigned_to'] === (int) $d['id'] ? 'selected' : '' ?>>
                    <?= e($d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn--outline btn--block" type="submit">
              <?= icon('user') ?> Allocate
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($artwork['status'] === 'proof_sent' && can('artwork.manage')): ?>
      <div class="card">
        <div class="card__head"><div><div class="card__title">Client answered by phone?</div></div></div>
        <div class="card__body">
          <form method="post" action="<?= url('/artwork/' . $artwork['id'] . '/decide') ?>"
                style="margin-bottom:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="decision" value="approved">
            <button class="btn btn--primary btn--block" type="submit">
              <?= icon('check') ?> They approved it
            </button>
          </form>
          <form method="post" action="<?= url('/artwork/' . $artwork['id'] . '/decide') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="decision" value="rejected">
            <div class="field">
              <textarea class="textarea" name="client_feedback" rows="3"
                        placeholder="What they want changed"></textarea>
            </div>
            <button class="btn btn--outline btn--block" type="submit">They want changes</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
