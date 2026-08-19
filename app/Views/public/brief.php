<?php
require_once APP_PATH . '/Views/partials/icons.php';

$company = $company ?? [];
$who     = trim((string) ($request['contact_person'] ?: $request['client_name']));
$first   = explode(' ', $who)[0] ?? $who;
$briefName = strtolower(\App\Services\JobBrief::TYPES[$request['brief_type']]);
?>

<div class="doc-sheet">

  <div class="doc-head">
    <div class="doc-head__company">
      <?php if (!empty($company['logo_path'])): ?>
        <img src="<?= url('/brand/logo') ?>" alt="<?= e($company['company_name'] ?? 'Logo') ?>" style="max-height:52px">
      <?php else: ?>
        <div class="fw-600"><?= e($company['company_name'] ?? 'Shanfix Technology') ?></div>
      <?php endif; ?>
    </div>
    <div class="doc-head__right">
      <div class="doc-head__tag">Job brief</div>
      <div class="text-sm text-muted"><?= e($request['reference']) ?></div>
    </div>
  </div>

  <?php if ($done): ?>

    <div class="card">
      <div class="card__body text-center">
        <div class="text-green" style="font-size:34px;line-height:1"><?= icon('check-circle') ?></div>
        <div class="card__title mt-8">Thank you<?= $first !== '' ? ', ' . e($first) : '' ?>.</div>
        <p class="text-sm mt-8">
          We have your <?= e($briefName) ?> brief and someone will be in touch shortly.
        </p>
        <p class="text-xs text-muted mb-0">
          Sent <?= e(date('j M Y \a\t H:i', strtotime((string) $request['submitted_at']))) ?>.
          If something needs changing, please call us<?= !empty($company['company_phone']) ? ' on ' . e($company['company_phone']) : '' ?>
          rather than filling this in again.
        </p>
      </div>
    </div>

    <?php // Shown back so they can check it reads the way they meant it. ?>
    <div class="card mt-16">
      <div class="card__head">
        <div>
          <div class="card__title">What you told us</div>
          <div class="card__sub">This is what we are working from</div>
        </div>
      </div>
      <div class="card__body">
        <dl class="dl dl--stacked">
          <?php foreach ($fields as $field): ?>
            <?php $a = trim((string) ($answers[$field['key']] ?? '')); ?>
            <?php if ($a === '') { continue; } ?>
            <dt><?= e($field['label']) ?></dt>
            <dd><?= nl2br(e($a)) ?></dd>
          <?php endforeach; ?>
        </dl>

        <?php if ($files): ?>
          <div class="fw-600 mb-4 mt-16">What you attached</div>
          <ul class="plain-list">
            <?php foreach ($files as $f): ?>
              <li>
                <?= icon('paperclip') ?> <?= e($f['original_name']) ?>
                <span class="text-xs text-muted"><?= e(human_bytes((int) $f['bytes'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title"><?= e($heading) ?></div>
          <div class="card__sub">For <?= e($request['client_name']) ?></div>
        </div>
      </div>
      <div class="card__body">
        <p class="text-sm"><?= e($blurb) ?></p>
        <p class="text-sm text-muted mb-0">
          None of this is binding — it is so we understand what you want before
          we quote. Leave anything you are unsure about blank and we will talk
          it through.
        </p>
      </div>
    </div>

    <form method="post" action="<?= url('/brief/' . $request['public_token']) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="card mt-16">
        <div class="card__body">
          <?php require APP_PATH . '/Views/partials/brief_fields.php'; ?>
        </div>
      </div>

      <div class="card mt-16">
        <div class="card__head">
          <?= icon('paperclip') ?>
          <div>
            <div class="card__title">Anything you can send us</div>
            <div class="card__sub">Optional, but it saves a lot of back and forth</div>
          </div>
        </div>
        <div class="card__body">
          <p class="text-sm text-muted">
            A logo, photos, a document with your wording, an example of something
            you like — whatever you already have.
          </p>

          <div class="field">
            <input class="input" type="file" name="attachments[]" multiple
                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
            <span class="field-hint">
              Images, PDFs, Word and Excel documents, or a zip. You can pick
              several at once, up to <?= (int) $maxMb ?>MB each.
            </span>
          </div>

          <?php if ($files): ?>
            <p class="text-sm text-muted mb-0">
              Already received: <?= e(implode(', ', array_column($files, 'original_name'))) ?>
            </p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mt-16">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            Send this to <?= e($company['company_name'] ?? 'us') ?>
          </button>
          <p class="text-xs text-muted text-center mt-8 mb-0">
            Questions marked <span class="req">*</span> are the ones we cannot start without.
          </p>
        </div>
      </div>
    </form>

  <?php endif; ?>

  <div class="text-xs text-muted text-center mt-16">
    <?= e($company['company_name'] ?? 'Shanfix Technology') ?>
    <?php if (!empty($company['company_phone'])): ?> &middot; <?= e($company['company_phone']) ?><?php endif; ?>
    <?php if (!empty($company['company_email'])): ?> &middot; <?= e($company['company_email']) ?><?php endif; ?>
  </div>

</div>
