<?php
/**
 * The client's view of their artwork.
 *
 * @var array      $artwork
 * @var array|null $proof
 * @var array      $company
 * @var bool       $isImage
 * @var array      $history
 */
require_once APP_PATH . '/Views/partials/icons.php';

$logoPath = $company['logo'] ? url('brand/logo') : null;
$token    = $artwork['public_token'];
$fileUrl  = url('review/' . $token . '/file');

$waiting  = $artwork['status'] === 'proof_sent';
$approved = in_array($artwork['status'], ['approved', 'completed'], true);
$changes  = $artwork['status'] === 'changes_requested';
?>

<div class="print-bar no-print" style="justify-content:space-between">
  <div class="flex items-center gap-8">
    <?php if ($logoPath): ?>
      <img src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>" style="height:28px;width:auto">
    <?php else: ?>
      <span class="sidebar__mark" style="width:30px;height:30px;flex-basis:30px;font-size:12px">SF</span>
    <?php endif; ?>
    <div>
      <div class="fw-600"><?= e($company['name']) ?></div>
      <?php if ($company['phone']): ?>
        <div class="text-xs text-muted"><?= e($company['phone']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($proof): ?>
    <a class="btn btn--outline btn--sm" href="<?= e($fileUrl) ?>" download>
      <?= icon('download') ?> Download
    </a>
  <?php endif; ?>
</div>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <div class="doc-head__company">Artwork for your approval</div>
      <div class="doc-head__tag"><?= e($artwork['title']) ?></div>
      <?php if ($artwork['specs']): ?>
        <div class="doc-head__lines"><?= e($artwork['specs']) ?></div>
      <?php endif; ?>
    </div>
    <div class="doc-head__right">
      <div class="text-xs uppercase text-muted">Reference</div>
      <div class="fw-700"><?= e($artwork['request_number']) ?></div>
      <?php if ($proof): ?>
        <div class="text-sm text-muted">Version <?= (int) $proof['version'] ?></div>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($approved): ?>
    <div class="alert alert--success">
      <?= icon('check-circle') ?>
      <div class="alert__body">
        <strong>You approved this artwork.</strong>
        <?php if ($artwork['approved_name']): ?>
          <?= e($artwork['approved_name']) ?>,
        <?php endif; ?>
        <?php if ($artwork['approved_at']): ?>
          <?= e(fdate($artwork['approved_at'], 'd M Y \a\t H:i')) ?>.
        <?php endif; ?>
        <div class="text-sm mt-4">It is now with our production team.</div>
      </div>
    </div>
  <?php elseif ($changes): ?>
    <div class="alert alert--warning">
      <?= icon('alert-triangle') ?>
      <div class="alert__body">
        <strong>You asked for changes.</strong>
        Our designer is working on a revised version and will send it shortly.
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$proof): ?>
    <div class="empty" style="padding:40px 0">
      <div class="empty__title">Nothing to show yet</div>
      <p class="empty__text">
        The artwork is still being prepared. We will let you know the moment
        it is ready.
      </p>
    </div>
  <?php else: ?>
    <div style="text-align:center;margin:18px 0;padding:16px;background:var(--surface-2);border-radius:var(--r)">
      <?php if ($isImage): ?>
        <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
          <img src="<?= e($fileUrl) ?>" alt="Artwork for <?= e($artwork['request_number']) ?>"
               style="max-width:100%;height:auto;border:1px solid var(--border);background:#fff">
        </a>
        <div class="text-xs text-muted mt-8">Tap the image to see it full size</div>
      <?php else: ?>
        <p class="mb-12"><?= icon('file-text') ?></p>
        <p class="fw-600 mb-4"><?= e($proof['file_name']) ?></p>
        <a class="btn btn--primary" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
          Open the artwork
        </a>
      <?php endif; ?>
    </div>

    <?php if ($proof['notes']): ?>
      <div class="alert alert--info">
        <?= icon('info') ?>
        <div class="alert__body">
          <strong>A note from our designer:</strong> <?= nl2br(e($proof['notes'])) ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($waiting && $proof): ?>
    <div class="card mt-16">
      <div class="card__head">
        <div>
          <div class="card__title">Please check this carefully</div>
          <div class="card__sub">
            Check the spelling, the colours, the sizes and any phone numbers.
            Once you approve, this is exactly what gets produced.
          </div>
        </div>
      </div>
      <div class="card__body">

        <form method="post" action="<?= url('review/' . $token . '/decide') ?>" style="margin-bottom:18px">
          <?= csrf_field() ?>
          <input type="hidden" name="decision" value="approved">

          <div class="field">
            <label class="label" for="approved_name">Your name</label>
            <input class="input" id="approved_name" name="approved_name" maxlength="160" required
                   value="<?= e($artwork['client_contact'] ?? '') ?>">
          </div>

          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('check') ?> Approve — go ahead and produce this
          </button>
        </form>

        <hr>

        <form method="post" action="<?= url('review/' . $token . '/decide') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="decision" value="rejected">
          <div class="field">
            <label class="label" for="client_feedback">Need something changed?</label>
            <textarea class="textarea" id="client_feedback" name="client_feedback" rows="4"
                      maxlength="500"
                      placeholder="e.g. Please correct the phone number and make the logo bigger"></textarea>
            <span class="field-hint">Tell us what to change and we will send a new version.</span>
          </div>
          <button class="btn btn--outline btn--block" type="submit">Request changes</button>
        </form>

      </div>
    </div>
  <?php endif; ?>

  <?php if ($history): ?>
    <div style="margin-top:22px">
      <div class="text-xs uppercase fw-700 text-muted mb-8">Earlier versions</div>
      <div class="doc-table-wrap">
      <table class="doc-table">
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td>Version <?= (int) $h['version'] ?></td>
              <td><?= e(label_of($h['status'])) ?></td>
              <td><?= $h['decided_at'] ? e(fdate($h['decided_at'])) : '' ?></td>
              <td><?= e($h['client_feedback'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endif; ?>

  <footer class="doc-foot">
    Questions? Call us
    <?php if ($company['phone']): ?>on <strong><?= e($company['phone']) ?></strong><?php endif; ?>
    and quote <strong><?= e($artwork['request_number']) ?></strong>.
  </footer>

</div>
