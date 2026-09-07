<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<h1 class="login__title">Set up your sign-in</h1>
<p class="login__intro">
  Give us the email address you applied with and we will send you a code.
  It works whether this is your first time or you have forgotten your password.
</p>

<form method="post" action="<?= url('/partners/start') ?>">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="email">Your email address</label>
    <div class="input-icon">
      <?= icon('mail') ?>
      <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
             type="email" id="email" name="email"
             value="<?= old('email') ?>" required
             autocomplete="email" inputmode="email" autofocus>
    </div>
    <?= error_for($errors ?? [], 'email') ?>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Send me a code
    <?= icon('arrow-right') ?>
  </button>
</form>

<p class="login__help">
  <a href="<?= url('/partners/login') ?>"><?= icon('arrow-left') ?> Back to partner sign in</a>
</p>
