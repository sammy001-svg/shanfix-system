<?php
/**
 * A partner's own details.
 *
 * The email address and the commission rate are deliberately not here.
 * The address is what they sign in with and what their approval was sent
 * to; the rate is what we agreed to pay them. Neither is theirs to change
 * from in here, and both are shown so they can see what they are.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$rateOf = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
$noPin  = trim((string) $me['kra_pin']) === '';
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your details</h1>
    <p class="portal-lede">
      What we hold about you, and what we need before we can pay you.
    </p>
  </div>

  <?php // Without a PIN we cannot account for the payment, so the payment
        // does not happen. Said here, where the person who knows it is. ?>
  <?php if ($noPin): ?>
    <div class="alert alert--warning">
      <?= icon('alert-triangle') ?>
      <div class="alert__body">
        <strong>We need your KRA PIN before we can pay you.</strong>
        Commission carries on adding up either way — it is the payment that
        waits, not the earning.
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-cols">
    <section class="portal-card">
      <h2 class="portal-card__title mb-12">About you</h2>

      <form method="post" action="<?= url('/partners/account') ?>">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="name">Your name</label>
          <div class="input-icon">
            <?= icon('user') ?>
            <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>"
                   type="text" id="name" name="name" required maxlength="140"
                   value="<?= e(old('name', $me['name'])) ?>">
          </div>
          <?= error_for($errors ?? [], 'name') ?>
        </div>

        <div class="field">
          <label class="label" for="company">Your business</label>
          <div class="input-icon">
            <?= icon('briefcase') ?>
            <input class="input" type="text" id="company" name="company" maxlength="180"
                   value="<?= e(old('company', (string) $me['company'])) ?>">
          </div>
        </div>

        <div class="field">
          <label class="label" for="phone">Phone number</label>
          <div class="input-icon">
            <?= icon('phone') ?>
            <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>"
                   type="tel" id="phone" name="phone" required maxlength="30"
                   inputmode="tel" value="<?= e(old('phone', $me['phone'])) ?>">
          </div>
          <?= error_for($errors ?? [], 'phone') ?>
          <span class="field-hint">Where your codes are sent.</span>
        </div>

        <div class="field">
          <label class="label" for="kra_pin">KRA PIN</label>
          <div class="input-icon">
            <?= icon('file-text') ?>
            <input class="input <?= $noPin ? 'has-error' : '' ?>"
                   type="text" id="kra_pin" name="kra_pin" maxlength="30"
                   placeholder="A000000000X"
                   value="<?= e(old('kra_pin', (string) $me['kra_pin'])) ?>">
          </div>
          <?= error_for($errors ?? [], 'kra_pin') ?>
          <span class="field-hint">We need this to pay you.</span>
        </div>

        <button class="btn btn--primary btn--block" type="submit">Save</button>
      </form>
    </section>

    <div class="portal-side">
      <section class="portal-card">
        <h2 class="portal-card__title mb-12">Your agreement</h2>
        <dl class="portal-facts">
          <dt>Signing in as</dt>
          <dd><?= e($me['email']) ?></dd>

          <dt>Your rate</dt>
          <dd><?= e($rateOf((float) $me['default_rate'])) ?> <span class="text-muted">unless a service says otherwise</span></dd>

          <?php if ($me['partner_code']): ?>
            <dt>Your reference</dt>
            <dd><?= e($me['partner_code']) ?></dd>
          <?php endif; ?>

          <dt>Paid</dt>
          <dd>Monthly, once your customers have paid us</dd>

          <?php // Shown but not editable, on purpose: anyone who got into
                // this account could otherwise send the money elsewhere.
                // Read-only still matters — it is how a wrong digit gets
                // spotted before payment day rather than after. ?>
          <dt>Paid into</dt>
          <dd>
            <?php if (($me['pay_method'] ?? '') === 'mpesa'): ?>
              M-Pesa &middot; <?= e($me['pay_phone'] ?: $me['phone']) ?>
            <?php elseif (($me['pay_method'] ?? '') === 'bank' && !empty($me['bank_account_no'])): ?>
              <?= e($me['bank_name'] ?: 'Your bank') ?>
              <?php if (!empty($me['bank_branch'])): ?>
                &middot; <?= e($me['bank_branch']) ?>
              <?php endif; ?>
              <div class="text-xs text-muted">
                <?= e($me['bank_account_name'] ?: '') ?>
                &middot; <?= e($me['bank_account_no']) ?>
              </div>
            <?php else: ?>
              <span class="text-muted">
                Nothing on file yet — tell us where to send it
              </span>
            <?php endif; ?>
          </dd>
        </dl>
        <p class="text-xs text-muted mb-0 mt-12">
          To change the address you sign in with, your rate, or where your
          money is paid, call us on <?= e($company['phone']) ?>. We do not
          let payment details be changed from this screen, so that nobody
          who got into your account could send your money elsewhere.
        </p>
      </section>

      <section class="portal-card">
        <h2 class="portal-card__title mb-12">Change your password</h2>

        <form method="post" action="<?= url('/partners/account/password') ?>">
          <?= csrf_field() ?>

          <div class="field">
            <label class="label" for="current_password">Current password</label>
            <input class="input <?= isset($errors['current_password']) ? 'has-error' : '' ?>"
                   type="password" id="current_password" name="current_password"
                   required autocomplete="current-password">
            <?= error_for($errors ?? [], 'current_password') ?>
          </div>

          <div class="field">
            <label class="label" for="new_password">New password</label>
            <input class="input <?= isset($errors['new_password']) ? 'has-error' : '' ?>"
                   type="password" id="new_password" name="new_password"
                   required minlength="8" autocomplete="new-password">
            <?= error_for($errors ?? [], 'new_password') ?>
            <span class="field-hint">At least 8 characters.</span>
          </div>

          <div class="field">
            <label class="label" for="new_password_confirm">Type it again</label>
            <input class="input <?= isset($errors['new_password_confirm']) ? 'has-error' : '' ?>"
                   type="password" id="new_password_confirm" name="new_password_confirm"
                   required minlength="8" autocomplete="new-password">
            <?= error_for($errors ?? [], 'new_password_confirm') ?>
          </div>

          <button class="btn btn--outline btn--block" type="submit">Change it</button>
        </form>
      </section>
    </div>
  </div>
</div>
