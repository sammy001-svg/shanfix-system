<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<h1 class="login__title">Enter your code</h1>
<p class="login__intro">
  We sent a <?= (int) $minutes ?>-minute code to <strong><?= e($email) ?></strong>.
  If we have a phone number for you, it went there too.
</p>

<form method="post" action="<?= url('/portal/verify') ?>">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="code">The six-digit code</label>
    <?php // A numeric keypad on a phone, and no autocorrect mangling it. ?>
    <input class="input input--code <?= isset($errors['code']) ? 'has-error' : '' ?>"
           type="text" id="code" name="code"
           inputmode="numeric" pattern="[0-9]*" maxlength="6" required
           autocomplete="one-time-code" autofocus>
    <?= error_for($errors ?? [], 'code') ?>
  </div>

  <?php // Only needed if we have never met them; harmless to show. ?>
  <div class="field">
    <label class="label" for="name">Your name</label>
    <div class="input-icon">
      <?= icon('user') ?>
      <input class="input" type="text" id="name" name="name"
             value="<?= old('name') ?>" maxlength="140"
             placeholder="As you would like us to address you">
    </div>
  </div>

  <div class="field">
    <label class="label" for="password">Choose a password</label>
    <div class="input-icon">
      <?= icon('lock') ?>
      <input class="input <?= isset($errors['password']) ? 'has-error' : '' ?>"
             type="password" id="password" name="password"
             required minlength="8" autocomplete="new-password">
      <button class="input-icon__toggle" type="button"
              data-toggle-password="#password"
              aria-label="Show password" aria-pressed="false" tabindex="0">
        <span data-icon-show><?= icon('eye') ?></span>
        <span data-icon-hide hidden><?= icon('eye-off') ?></span>
      </button>
    </div>
    <?= error_for($errors ?? [], 'password') ?>
    <span class="field-hint">At least 8 characters.</span>
  </div>

  <div class="field">
    <label class="label" for="password_confirm">Type it again</label>
    <div class="input-icon">
      <?= icon('lock') ?>
      <input class="input" type="password" id="password_confirm" name="password_confirm"
             required minlength="8" autocomplete="new-password">
    </div>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Finish setting up
    <?= icon('arrow-right') ?>
  </button>
</form>

<p class="login__help">
  Code not arrived? <a href="<?= url('/portal/start') ?>">Ask for another</a>.
  Check your spam folder first — it often lands there.
</p>
