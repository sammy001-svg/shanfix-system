<?php
/**
 * Client testimonials for the website.
 *
 * What is switched on here is what the website shows, on the homepage,
 * "Who we are" and "Our work". With nothing switched on, those sections
 * simply do not appear.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$t = $editing ?? [];
$live = count(array_filter($testimonials, static fn(array $r): bool => (int) $r['is_active'] === 1));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Testimonials</h1>
    <div class="page-head__sub">
      What clients say about us, as shown on the website.
      <?= $live ? $live . ' showing.' : 'None showing yet, so the website has no testimonials section.' ?>
    </div>
  </div>
</div>

<div class="alert alert--info mb-16">
  <?= icon('info') ?>
  <div class="alert__body">
    Only publish a quote the client has agreed to. It appears under their
    name on a public page. Add it hidden if you are still waiting for
    their permission.
  </div>
</div>

<div class="grid grid--2">
  <div class="card">
    <div class="card__head">
      <div class="card__title"><?= $t ? 'Edit testimonial' : 'Add a testimonial' ?></div>
      <?php if ($t): ?><a class="btn btn--ghost btn--sm" href="<?= e(url('/testimonials')) ?>">Cancel</a><?php endif; ?>
    </div>
    <form class="card__body" method="post" action="<?= e(url('/testimonials')) ?>">
      <?= csrf_field() ?>
      <?php if ($t): ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><?php endif; ?>

      <div class="field">
        <label class="label" for="t_quote">What they said <span class="req">*</span></label>
        <textarea class="textarea" id="t_quote" name="quote" rows="4" maxlength="1200" required
                  placeholder="In their own words, without the quotation marks."><?= e($t['quote'] ?? '') ?></textarea>
      </div>

      <div class="form-grid form-grid--2">
        <div class="field">
          <label class="label" for="t_author">Name <span class="req">*</span></label>
          <input class="input" id="t_author" name="author" maxlength="120" required value="<?= e($t['author'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="label" for="t_role">Role</label>
          <input class="input" id="t_role" name="role" maxlength="120" value="<?= e($t['role'] ?? '') ?>" placeholder="e.g. Operations Manager">
        </div>
        <div class="field">
          <label class="label" for="t_company">Company</label>
          <input class="input" id="t_company" name="company" maxlength="160" value="<?= e($t['company'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="label" for="t_rating">Stars</label>
          <select class="input" id="t_rating" name="rating">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <option value="<?= $i ?>" <?= (int) ($t['rating'] ?? 5) === $i ? 'selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="t_order">Order</label>
          <input class="input" type="number" id="t_order" name="sort_order" min="0" value="<?= (int) ($t['sort_order'] ?? 0) ?>">
          <span class="field-hint">Lower comes first.</span>
        </div>
      </div>

      <label class="check mb-8">
        <input type="checkbox" name="is_active" value="1" <?= !$t || (int) $t['is_active'] === 1 ? 'checked' : '' ?>>
        <span class="check__text">Show on the website</span>
      </label>

      <button class="btn btn--primary" type="submit"><?= $t ? 'Save' : 'Add testimonial' ?></button>
    </form>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">All testimonials</div></div>

    <?php if (!$testimonials): ?>
      <div class="empty">
        <div class="empty__icon"><?= icon('star') ?></div>
        <div class="empty__title">None yet</div>
        <p class="text-sm text-muted">Add a client's words on the left and they will appear on the website.</p>
      </div>
    <?php else: ?>
      <div class="card__body">
        <?php foreach ($testimonials as $r): ?>
          <div class="tm-row<?= (int) $r['is_active'] === 1 ? '' : ' tm-row--off' ?>">
            <div class="tm-row__stars"><?= str_repeat('★', (int) $r['rating']) ?></div>
            <p class="tm-row__quote">“<?= e(mb_strimwidth($r['quote'], 0, 180, '…')) ?>”</p>
            <div class="tm-row__who">
              <strong><?= e($r['author']) ?></strong>
              <?php if ($r['role'] || $r['company']): ?>
                <span class="text-muted"> · <?= e(trim(($r['role'] ?? '') . ($r['role'] && $r['company'] ? ', ' : '') . ($r['company'] ?? ''))) ?></span>
              <?php endif; ?>
              <?php if ((int) $r['is_active'] !== 1): ?><span class="badge badge--grey">Hidden</span><?php endif; ?>
            </div>
            <div class="tm-row__actions">
              <a class="btn btn--ghost btn--sm" href="<?= e(url('/testimonials?edit=' . $r['id'])) ?>">Edit</a>
              <form method="post" action="<?= e(url('/testimonials/' . $r['id'] . '/toggle')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit"><?= (int) $r['is_active'] === 1 ? 'Hide' : 'Show' ?></button>
              </form>
              <form method="post" action="<?= e(url('/testimonials/' . $r['id'] . '/delete')) ?>"
                    onsubmit="return confirm('Remove this testimonial?')">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit" style="color:var(--red-700)">Remove</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
