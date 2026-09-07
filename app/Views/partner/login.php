<?php
/**
 * The partner door.
 *
 * Same shell as the other two, with the application form's way in
 * directly underneath — somebody arriving here who is not a partner yet
 * is exactly the person we want applying.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<h1 class="login__title">Partner sign in</h1>
<p class="login__intro">
  What your customers have bought, and what you have earned on it.
</p>

<form method="post" action="<?= url('/partners/login') ?>">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="email">Email address</label>
    <div class="input-icon">
      <?= icon('mail') ?>
      <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
             type="email" id="email" name="email"
             value="<?= old('email') ?>"
             autocomplete="email" inputmode="email"
             required autofocus
             placeholder="The address you applied with">
    </div>
    <?= error_for($errors ?? [], 'email') ?>
  </div>

  <div class="field">
    <label class="label" for="password">Password</label>
    <div class="input-icon">
      <?= icon('lock') ?>
      <input class="input <?= isset($errors['password']) ? 'has-error' : '' ?>"
             type="password" id="password" name="password"
             required autocomplete="current-password"
             placeholder="Enter your password">
      <button class="input-icon__toggle" type="button"
              data-toggle-password="#password"
              aria-label="Show password" aria-pressed="false" tabindex="0">
        <span data-icon-show><?= icon('eye') ?></span>
        <span data-icon-hide hidden><?= icon('eye-off') ?></span>
      </button>
    </div>
    <?= error_for($errors ?? [], 'password') ?>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Sign in
    <?= icon('arrow-right') ?>
  </button>
</form>

<div class="login__switch">
  <div class="login__switch-line"><span>First time, or forgotten your password?</span></div>
  <a class="btn btn--outline btn--block" href="<?= url('/partners/start') ?>">
    <?= icon('key') ?> Set up or reset my access
  </a>
</div>

<?php if ($signupOn): ?>
  <?php // The point of the page for anybody who is not a partner yet. ?>
  <div class="login__switch">
    <div class="login__switch-line"><span>Not a partner yet?</span></div>

    <a class="btn btn--outline btn--block" href="<?= url('/partners/apply') ?>">
      <?= icon('user-plus') ?> Apply to become a partner
    </a>

    <p class="login__switch-help">
      Bring us customers and earn a share of everything they spend with us —
      not just the first job.
    </p>
  </div>
<?php endif; ?>

<p class="login__help">
  <a href="<?= url('/') ?>"><?= icon('arrow-left') ?> Choose a different sign-in</a>
</p>
