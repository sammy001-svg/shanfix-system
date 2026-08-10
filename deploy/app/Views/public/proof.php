<?php
/**
 * Client-facing proof approval.
 *
 * @var array  $proof    job_files row joined to its job and client
 * @var array  $company
 * @var bool   $isImage  true when the proof can be shown inline
 */
require_once APP_PATH . '/Views/partials/icons.php';

$logoPath = $company['logo'] ? url('brand/logo') : null;
$fileUrl  = url('proof/' . $proof['public_token'] . '/file');

$pending  = $proof['status'] === 'pending';
$approved = $proof['status'] === 'approved';
?>

<div class="print-bar no-print" style="justify-content:space-between">
  <div class="flex items-center gap-8">
    <?php if ($logoPath): ?>
      <img src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>" style="height:28px;width:auto">
    <?php else: ?>
      <span class="sidebar__mark" style="width:30px;height:30px;flex-basis:30px;font-size:12px">SF</span>
    <?php endif; ?>
    <span class="fw-600"><?= e($company['name']) ?></span>
  </div>
  <a class="btn btn--outline btn--sm" href="<?= e($fileUrl) ?>" download>
    <?= icon('download') ?> Download
  </a>
</div>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <div class="doc-head__company">Proof for approval</div>
      <div class="doc-head__tag"><?= e($proof['job_title']) ?></div>
    </div>
    <div class="doc-head__right">
      <div class="text-xs uppercase text-muted">Order</div>
      <div class="fw-700"><?= e($proof['job_number']) ?></div>
      <div class="text-sm text-muted">Version <?= (int) $proof['version'] ?></div>
    </div>
  </header>

  <?php if (!$pending): ?>
    <div class="alert <?= $approved ? 'alert--success' : 'alert--warning' ?>">
      <?= icon($approved ? 'check' : 'alert-triangle') ?>
      <div class="alert__body">
        <strong>
          <?= $approved ? 'You approved this proof.' : 'You asked for changes to this proof.' ?>
        </strong>
        <?php if (!empty($proof['approved_at'])): ?>
          <span class="text-muted">on <?= e(fdate($proof['approved_at'])) ?></span>
        <?php endif; ?>
        <?php if ($approved): ?>
          <div class="text-sm mt-4">Your order is in production. We will let you know when it is ready.</div>
        <?php else: ?>
          <div class="text-sm mt-4">
            Our designer is working on a revised version and will send it shortly.
          </div>
          <?php if (!empty($proof['client_feedback'])): ?>
            <div class="text-sm mt-8">
              <span class="text-muted">What you asked for:</span>
              <em><?= nl2br(e($proof['client_feedback'])) ?></em>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- The proof itself -->
  <div style="text-align:center;margin:18px 0;padding:16px;background:var(--surface-2);border-radius:var(--r)">
    <?php if ($isImage): ?>
      <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
        <img src="<?= e($fileUrl) ?>" alt="Proof for <?= e($proof['job_number']) ?>"
             style="max-width:100%;height:auto;border:1px solid var(--border);background:#fff">
      </a>
      <div class="text-xs text-muted mt-8">Tap the image to see it full size</div>
    <?php else: ?>
      <p class="mb-12"><?= icon('file-text') ?></p>
      <p class="fw-600 mb-4"><?= e($proof['file_name']) ?></p>
      <a class="btn btn--primary" href="<?= e($fileUrl) ?>" target="_blank" rel="noopener">
        Open the proof
      </a>
    <?php endif; ?>
  </div>

  <?php if (!empty($proof['notes'])): ?>
    <div class="alert alert--info">
      <?= icon('info') ?>
      <div class="alert__body">
        <strong>A note from us:</strong> <?= nl2br(e($proof['notes'])) ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($pending): ?>
    <div class="card mt-16">
      <div class="card__head">
        <div>
          <div class="card__title">Please check this carefully</div>
          <div class="card__sub">
            Check the spelling, colours, sizes and any phone numbers. Once you approve,
            this is exactly what gets produced.
          </div>
        </div>
      </div>
      <div class="card__body">

        <form method="post" action="<?= url('proof/' . $proof['public_token'] . '/decide') ?>"
              style="margin-bottom:18px">
          <?= csrf_field() ?>
          <input type="hidden" name="decision" value="approved">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('check') ?> Approve — go ahead and produce this
          </button>
        </form>

        <hr>

        <form method="post" action="<?= url('proof/' . $proof['public_token'] . '/decide') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="decision" value="rejected">
          <div class="field">
            <label class="label" for="client_feedback">Need something changed?</label>
            <textarea class="textarea" id="client_feedback" name="client_feedback" rows="4"
                      maxlength="500"
                      placeholder="e.g. Please correct the phone number and make the logo bigger"></textarea>
            <span class="field-hint">Tell us what to change and we will send a new proof.</span>
          </div>
          <button class="btn btn--outline btn--block" type="submit">
            Request changes
          </button>
        </form>

      </div>
    </div>
  <?php endif; ?>

  <div class="text-center text-sm text-muted mt-16">
    Questions? Call us
    <?php if (!empty($company['phone'])): ?>
      on <strong><?= e($company['phone']) ?></strong>
    <?php endif; ?>
    and quote <strong><?= e($proof['job_number']) ?></strong>.
  </div>

</div>
