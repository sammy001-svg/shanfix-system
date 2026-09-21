<?php
/**
 * One job card, as the client sees it.
 *
 * Production notes and artwork files are deliberately excluded. The client
 * sees the title, stage history, what is being made, and the due date.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$stageLabel = static fn(string $s): string => match ($s) {
    'pending'     => 'Received',
    'artwork'     => 'Artwork in progress',
    'proof_sent'  => 'Proof sent for your approval',
    'approved'    => 'Proof approved — ready for press',
    'production'  => 'In production',
    'finishing'   => 'Finishing',
    'ready'       => 'Ready for collection / delivery',
    'delivered'   => 'Delivered',
    'on_hold'     => 'On hold',
    default       => ucfirst(str_replace('_', ' ', $s)),
};

$stageTone = static fn(string $s): string => match ($s) {
    'ready'      => 'green',
    'delivered'  => 'grey',
    'on_hold'    => 'amber',
    'proof_sent' => 'amber',
    default      => 'navy',
};

$stageOrder = [
    'pending', 'artwork', 'proof_sent', 'approved',
    'production', 'finishing', 'ready', 'delivered',
];
$currentIdx = array_search($job['stage'], $stageOrder, true);
?>

<div class="portal-wrap">
  <p class="portal-back">
    <a href="<?= url('/portal/jobs') ?>">
      <?= icon('arrow-left') ?> All jobs
    </a>
  </p>

  <section class="portal-card portal-doc">
    <div class="portal-doc__id">
      <div class="portal-doc__kind">
        Job
        <span class="badge badge--<?= e($stageTone((string) $job['stage'])) ?>">
          <?= e($stageLabel((string) $job['stage'])) ?>
        </span>
      </div>
      <h1 class="portal-doc__number"><?= e($job['job_number']) ?></h1>
      <p class="portal-doc__title"><?= e($job['title']) ?></p>
      <div class="portal-doc__dates">
        Raised <?= e(fdate($job['created_at'])) ?>
        <?php if ($job['due_date']): ?>
          &middot; due <?= e(fdate($job['due_date'])) ?>
        <?php endif; ?>
        <?php if ($job['doc_number']): ?>
          &middot; from <?= e($job['doc_number']) ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php // Stage progress track — only the stages the client understands. ?>
  <?php if ($job['stage'] !== 'on_hold'): ?>
    <section class="portal-card">
      <h2 class="portal-card__title mb-12">Progress</h2>
      <div class="portal-stages">
        <?php foreach ($stageOrder as $i => $stage): ?>
          <?php
            $done    = $currentIdx !== false && $i <= $currentIdx;
            $current = $job['stage'] === $stage;
          ?>
          <div class="portal-stage <?= $done ? 'portal-stage--done' : '' ?> <?= $current ? 'portal-stage--current' : '' ?>">
            <div class="portal-stage__dot">
              <?= $done ? icon('check') : '' ?>
            </div>
            <div class="portal-stage__label"><?= e(match($stage) {
              'pending'    => 'Received',
              'artwork'    => 'Artwork',
              'proof_sent' => 'Proof',
              'approved'   => 'Approved',
              'production' => 'Production',
              'finishing'  => 'Finishing',
              'ready'      => 'Ready',
              'delivered'  => 'Delivered',
              default      => $stage,
            }) ?></div>
          </div>
          <?php if ($i < count($stageOrder) - 1): ?>
            <div class="portal-stage__line <?= ($currentIdx !== false && $i < $currentIdx) ? 'portal-stage__line--done' : '' ?>"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php else: ?>
    <div class="alert alert--warning">
      <?= icon('pause-circle') ?>
      <div class="alert__body">This job is on hold. Call us on <?= e($company['phone']) ?> if you have questions.</div>
    </div>
  <?php endif; ?>

  <?php // Proof approval — only visible when waiting on the client. ?>
  <?php if ($proofs): ?>
    <section class="portal-card portal-proof" id="proof-section">
      <h2 class="portal-card__title mb-4">Your proof is ready</h2>
      <p class="text-sm text-muted mb-16">
        Look through the proof below, then let us know if you are happy with it or if you need changes.
      </p>

      <?php foreach ($proofs as $pf): ?>
        <div class="portal-proof__file">
          <img class="portal-proof__img"
               src="<?= url('/portal/proof-file/' . (int) $pf['id']) ?>"
               alt="<?= e($pf['file_name']) ?>"
               loading="lazy">
          <div class="text-xs text-muted mt-4 text-center"><?= e($pf['file_name']) ?></div>
        </div>
      <?php endforeach; ?>

      <form method="post" action="<?= url('/portal/jobs/' . (int) $job['id'] . '/proof') ?>"
            class="portal-proof__form" id="proof-form">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="proof-feedback">Notes (optional)</label>
          <textarea class="input" id="proof-feedback" name="feedback" rows="3" maxlength="500"
                    placeholder="Anything we should know about changes needed…"></textarea>
        </div>

        <div class="portal-proof__btns">
          <button class="btn btn--primary btn--lg" type="submit" name="proof_action" value="approve"
                  id="approve-proof-btn">
            <?= icon('check') ?> Approve — go to production
          </button>
          <button class="btn btn--outline" type="submit" name="proof_action" value="changes"
                  id="request-changes-btn">
            <?= icon('edit') ?> Request changes
          </button>
        </div>

        <p class="text-xs text-muted mb-0 mt-8">
          Approving is a confirmation that the artwork is correct. We will proceed to production
          immediately after your approval.
        </p>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($items): ?>
    <section class="portal-card portal-card--flush">
      <header class="portal-card__head">
        <h2 class="portal-card__title">What is being made</h2>
      </header>
      <ul class="portal-list portal-list--tight">
        <?php foreach ($items as $it): ?>
          <li>
            <span class="portal-list__row">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($it['description']) ?></span>
              </span>
              <span class="portal-list__side">
                <span class="text-sm text-muted">
                  <?= e(rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.')) ?>
                  <?= $it['unit'] ? e($it['unit']) : '' ?>
                </span>
                <?php if ($it['is_done']): ?>
                  <span class="badge badge--green ml-8">Done</span>
                <?php endif; ?>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($history): ?>
    <section class="portal-card portal-card--flush">
      <header class="portal-card__head">
        <h2 class="portal-card__title">Timeline</h2>
      </header>
      <ul class="portal-list portal-list--tight">
        <?php foreach (array_reverse($history) as $h): ?>
          <li>
            <span class="portal-list__row">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($stageLabel((string) $h['to_stage'])) ?></span>
              </span>
              <span class="portal-list__side">
                <span class="text-sm text-muted"><?= e(fdate($h['created_at'])) ?></span>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <p class="portal-help">
    Something not right? Call us on
    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $company['phone'])) ?>"><?= e($company['phone']) ?></a>
    or email <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>.
  </p>
</div>
