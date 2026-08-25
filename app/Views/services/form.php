<?php
require_once APP_PATH . '/Views/partials/icons.php';

$editing = $service !== null;
$action  = $editing ? url('/services/' . $service['id']) : url('/services');

$val = static function (string $key, $fallback = '') use ($service) {
    $old = \App\Core\Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $service[$key] ?? $fallback;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/services') ?>">Services</a> <span>/</span>
      <?= $editing ? e($service['name']) : 'New service' ?>
    </div>
    <h1><?= $editing ? 'Edit service' : 'New service' ?></h1>
    <div class="page-head__sub">Services listed here can be added to quotations and invoices in one click.</div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">Service details</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="name">Service name <span class="req">*</span></label>
              <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>" id="name" name="name"
                     value="<?= e($val('name')) ?>" required maxlength="180"
                     placeholder="e.g. Business Website (up to 5 pages)">
              <?= error_for($errors ?? [], 'name') ?>
            </div>

            <div class="field">
              <label class="label" for="code">Service code <span class="req">*</span></label>
              <input class="input <?= isset($errors['code']) ? 'has-error' : '' ?>" id="code" name="code"
                     value="<?= e($val('code')) ?>" required maxlength="60"
                     placeholder="e.g. SVC-WEB-001" style="text-transform:uppercase">
              <?= error_for($errors ?? [], 'code') ?>
            </div>

            <div class="field">
              <label class="label" for="category_id">Category</label>
              <select class="select" id="category_id" name="category_id">
                <option value="">— None —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) $val('category_id') === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="lead_time">Typical turnaround</label>
              <input class="input" id="lead_time" name="lead_time" value="<?= e($val('lead_time')) ?>"
                     maxlength="80" placeholder="e.g. 2-3 weeks">
            </div>

            <div class="field field--full">
              <label class="label" for="description">
                Description
                <span class="label__hint">— shown as the default line-item text on quotations</span>
              </label>
              <textarea class="textarea" id="description" name="description" rows="4"
                        placeholder="What is included in this service?"><?= e($val('description')) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Pricing</div>
            <div class="card__sub">Choose how this service is charged.</div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--3">
            <div class="field">
              <label class="label" for="pricing_type">Pricing type</label>
              <select class="select" id="pricing_type" name="pricing_type">
                <?php foreach ($pricingTypes as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $val('pricing_type', 'fixed') === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="price">Rate</label>
              <div class="input-group">
                <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
                <input class="input <?= isset($errors['price']) ? 'has-error' : '' ?>" type="number" step="0.01" min="0"
                       id="price" name="price" value="<?= e($val('price', '0.00')) ?>">
              </div>
              <span class="field-hint">Leave at 0 when quoted per project.</span>
              <?= error_for($errors ?? [], 'price') ?>
            </div>

            <div class="field">
              <label class="label" for="unit_label">Unit label</label>
              <input class="input" id="unit_label" name="unit_label" value="<?= e($val('unit_label')) ?>"
                     maxlength="40" placeholder="e.g. per site, per page">
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Availability</div></div>
        <div class="card__body">
          <label class="check">
            <input type="checkbox" name="is_active" value="1" <?= (int) $val('is_active', 1) === 1 ? 'checked' : '' ?>>
            <span class="check__text">
              <strong>Active</strong>
              <span>Only active services appear when building a new quotation.</span>
            </span>
          </label>
        </div>
      </div>

      <?php
        $images    = $images ?? [];
        $maxImages = max(1, (int) setting('service_images_max', 6));
      ?>
      <div class="card">
        <div class="card__head">
          <?= icon('image') ?>
          <div>
            <div class="card__title">Photos</div>
            <div class="card__sub"><?= count($images) ?> of <?= $maxImages ?> used</div>
          </div>
        </div>
        <div class="card__body">
          <?php // Half of what this company sells is a service. Three photos
                // of work already done say more than any description of it. ?>
          <?php if ($images): ?>
            <div class="thumb-grid mb-12">
              <?php foreach ($images as $img): ?>
                <a class="thumb <?= (int) $img['is_primary'] === 1 ? 'thumb--primary' : '' ?>"
                   href="<?= url('files/' . $img['file_path']) ?>"
                   data-gallery="service-form"
                   data-caption="<?= e($img['alt_text'] ?: $img['file_name']) ?>"
                   title="<?= e($img['file_name']) ?>">
                  <img src="<?= url('files/' . ($img['thumb_path'] ?: $img['file_path'])) ?>"
                       alt="<?= e($img['alt_text'] ?: ($service['name'] ?? 'Service photo')) ?>" loading="lazy">
                  <?php if ((int) $img['is_primary'] === 1): ?>
                    <span class="thumb__badge">Main</span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (count($images) < $maxImages): ?>
            <div class="field">
              <label class="label" for="images">
                <?= $images ? 'Add more photos' : 'Photos of this work' ?>
              </label>
              <input class="input" type="file" id="images" name="images[]"
                     accept="image/jpeg,image/png,image/gif,image/webp" multiple
                     data-image-preview="#service-image-preview">
              <span class="field-hint">
                JPG, PNG, GIF or WebP. Select several at once.
                Large photos are resized automatically.
              </span>
            </div>
            <div class="thumb-grid mt-12" id="service-image-preview"></div>
          <?php else: ?>
            <p class="text-sm text-muted mb-0">
              Maximum of <?= $maxImages ?> photos reached. Remove one on the
              <a href="<?= url('/services/' . $service['id']) ?>">service page</a> to add another.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Create service' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= $editing ? url('/services/' . $service['id']) : url('/services') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
