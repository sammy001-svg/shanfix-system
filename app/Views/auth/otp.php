<?php
/**
 * Staff/User OTP Verification view.
 */
require_once APP_PATH . '/Views/partials/icons.php';

// Mask email for display: e.g. a***e@domain.com
$maskedEmail = '';
if (!empty($email) && str_contains($email, '@')) {
    [$local, $domain] = explode('@', $email, 2);
    $len = strlen($local);
    if ($len <= 2) {
        $maskedEmail = $local[0] . '***@' . $domain;
    } else {
        $maskedEmail = $local[0] . str_repeat('*', max(1, $len - 2)) . $local[$len - 1] . '@' . $domain;
    }
}

// Mask phone for display: e.g. +254 7*** ***12
$maskedPhone = '';
if (!empty($phone)) {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) >= 9) {
        $maskedPhone = '+' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 2) . '*** ***' . substr($digits, -2);
    } else {
        $maskedPhone = 'your mobile phone';
    }
}
?>

<h1 class="login__title">Two-Step Verification</h1>
<p class="login__intro">
  Enter the 6-digit OTP code sent to your email
  <?php if ($maskedEmail): ?><strong>(<?= e($maskedEmail) ?>)</strong><?php endif; ?>
  <?php if ($maskedPhone): ?> and SMS <strong>(<?= e($maskedPhone) ?>)</strong><?php endif; ?>.
</p>

<form method="post" action="<?= url('/login/otp') ?>" id="otp-form">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="code">Security Code (6 digits)</label>
    <div class="input-icon">
      <?= icon('shield') ?>
      <input class="input input--code <?= isset($errors['code']) ? 'has-error' : '' ?>"
             type="text" id="code" name="code"
             inputmode="numeric" pattern="[0-9]*" maxlength="6" required
             autocomplete="one-time-code" autofocus
             placeholder="123456"
             style="letter-spacing: 6px; font-size: 1.25rem; font-weight: 700; text-align: center;">
    </div>
    <?= error_for($errors ?? [], 'code') ?>
    <span class="field-hint">Code is valid for <?= (int) $minutes ?> minutes.</span>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Verify & Access System
    <?= icon('arrow-right') ?>
  </button>
</form>

<form method="post" action="<?= url('/login/otp/resend') ?>" style="margin-top: 1.25rem;">
  <?= csrf_field() ?>
  <p class="login__help">
    Didn't receive the OTP code?
    <button type="submit" class="btn btn--link" style="padding: 0; font-weight: 600; text-decoration: underline; background: none; border: none; cursor: pointer; color: var(--color-primary, #0D2B4B);">
      Resend code
    </button>
  </p>
</form>

<p class="login__help" style="margin-top: 0.75rem;">
  <a href="<?= url('/login') ?>"><?= icon('arrow-left') ?> Back to Sign in</a>
</p>
