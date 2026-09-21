<?php
/**
 * Profile and password management.
 *
 * Two forms, one page. The name/phone section and the password section
 * use different hidden action fields so the server knows which to act on.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$old = fn(string $k, mixed $fallback = ''): mixed => Session::getOld($k, $fallback);
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your profile</h1>
    <p class="portal-lede">
      Your contact details and password.
    </p>
  </div>

  <div class="portal-cols">
    <div>
      <?php // Contact details ?>
      <section class="portal-card">
        <h2 class="portal-card__title mb-16">Your details</h2>
        <form method="post" action="<?= url('/portal/profile') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="details">

          <div class="field">
            <label class="label" for="name">Your name</label>
            <input class="input" type="text" id="name" name="name" required maxlength="160"
                   value="<?= e($old('name', $me['name'])) ?>"
                   autocomplete="name">
          </div>

          <div class="field">
            <label class="label" for="phone">Phone</label>
            <input class="input" type="tel" id="phone" name="phone" maxlength="30"
                   value="<?= e($old('phone', $me['phone'] ?? '')) ?>"
                   autocomplete="tel">
            <span class="field-hint">
              Your M-Pesa number. We use this when sending you a payment prompt.
            </span>
          </div>

          <div class="field">
            <label class="label">Email</label>
            <div class="input input--ro"><?= e($me['email']) ?></div>
            <span class="field-hint">
              Your sign-in address. To change it, contact us — it is verified before anything can be changed.
            </span>
          </div>

          <button class="btn btn--primary" type="submit" id="save-profile-btn">
            <?= icon('check') ?> Save changes
          </button>
        </form>
      </section>
    </div>

    <div class="portal-side">
      <?php // Password change ?>
      <section class="portal-card">
        <h2 class="portal-card__title mb-16">Change password</h2>
        <form method="post" action="<?= url('/portal/profile') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">

          <div class="field">
            <label class="label" for="current_password">Current password</label>
            <input class="input" type="password" id="current_password"
                   name="current_password" required autocomplete="current-password">
          </div>

          <div class="field">
            <label class="label" for="new_password">New password</label>
            <input class="input" type="password" id="new_password"
                   name="new_password" required minlength="8"
                   autocomplete="new-password">
            <span class="field-hint">At least 8 characters.</span>
          </div>

          <div class="field">
            <label class="label" for="confirm_password">Confirm new password</label>
            <input class="input" type="password" id="confirm_password"
                   name="confirm_password" required minlength="8"
                   autocomplete="new-password">
          </div>

          <button class="btn btn--outline" type="submit" id="change-password-btn">
            <?= icon('lock') ?> Change password
          </button>
        </form>
      </section>

      <section class="portal-card portal-card--quiet">
        <div class="fw-600 mb-4">Account info</div>
        <p class="text-sm text-muted mb-0">
          Signed in as <strong><?= e($me['email']) ?></strong>
          <?php if ($me['client_name']): ?>
            <br>Account: <?= e($me['client_name']) ?>
          <?php endif; ?>
        </p>
      </section>
    </div>
  </div>
</div>
