<?php
/**
 * How a reseller's own clients see them.
 *
 * Their clients pay them, not us, so the page where those clients buy
 * units has to say who to pay and how. Everything on this form appears
 * there.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <form method="post" action="<?= url($base . '/branding') ?>">
    <?= csrf_field() ?>

    <div class="portal-card">
      <div class="portal-card__head">
        <div>
          <div class="portal-card__title">Your details, on your clients' pages</div>
          <div class="text-sm text-muted">
            <?= $clients === 0
                ? 'You have no SMS clients yet.'
                : 'Seen by your ' . number_format($clients) . ' SMS client' . ($clients === 1 ? '' : 's') . '.' ?>
          </div>
        </div>
      </div>

      <div class="field">
        <label class="label" for="brand_name">Trade as</label>
        <input class="input" id="brand_name" name="brand_name" maxlength="120"
               value="<?= e($account['brand_name'] ?? '') ?>" placeholder="Your business name">
        <span class="field-hint">
          Shown to your clients as the people their units come from. Leave it
          empty to use the name we have on file for you.
        </span>
      </div>

      <div class="portal-cols">
        <div class="field">
          <label class="label" for="brand_support_phone">Phone they should call</label>
          <input class="input" id="brand_support_phone" name="brand_support_phone" maxlength="30"
                 value="<?= e($account['brand_support_phone'] ?? '') ?>" placeholder="07…">
        </div>
        <div class="field">
          <label class="label" for="brand_support_email">E-mail they should write to</label>
          <input class="input" id="brand_support_email" name="brand_support_email" type="email" maxlength="160"
                 value="<?= e($account['brand_support_email'] ?? '') ?>">
        </div>
      </div>

      <div class="field mb-0">
        <label class="label" for="brand_pay_instructions">How to pay you</label>
        <textarea class="textarea" id="brand_pay_instructions" name="brand_pay_instructions" rows="4" maxlength="2000"
                  placeholder="e.g. M-Pesa Till 123456, name Acme Communications. Send the M-Pesa code with your request."><?= e($account['brand_pay_instructions'] ?? '') ?></textarea>
        <span class="field-hint">
          This is the whole point of the page. Your clients see it when they
          ask to buy units — without it they are told only to "pay as you
          normally do", which helps nobody.
        </span>
      </div>
    </div>

    <div class="portal-card">
      <button class="btn btn--primary btn--block" type="submit"><?= icon('save') ?> Save</button>
    </div>
  </form>

  <div class="portal-card portal-card--quiet">
    <div class="text-sm">
      <div class="fw-600 mb-8">What this does not do</div>
      <p class="text-muted mb-0">
        It does not put your brand over the whole system. Your clients sign
        in at our address, on our pages, and can see they are using
        <?= e(\App\Core\Settings::get('company_name', 'our')) ?> software —
        which is the honest arrangement, and the one that keeps you out of
        the way when their password needs resetting. What it does is make
        sure the moment money changes hands names <em>you</em>, because that
        money is yours.
      </p>
    </div>
  </div>
</div>
