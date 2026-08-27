<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="portal-narrow">
  <div class="portal-card">
    <h1 class="portal-h1">Enter your code</h1>
    <p class="portal-lede">
      We sent a <?= (int) $minutes ?>-minute code to <strong><?= e($email) ?></strong>.
      If we have a phone number for you, it went there too.
    </p>

    <form method="post" action="<?= url('/portal/verify') ?>">
      <?= csrf_field() ?>

      <div class="field">
        <label class="label" for="code">The six-digit code</label>
        <?php // A numeric keypad on a phone, and no autocorrect mangling it. ?>
        <input class="input input--code" type="text" id="code" name="code"
               inputmode="numeric" pattern="[0-9]*" maxlength="6" required
               autocomplete="one-time-code" autofocus>
      </div>

      <?php // Only needed if we have never met them; harmless to show. ?>
      <div class="field">
        <label class="label" for="name">Your name</label>
        <input class="input" type="text" id="name" name="name"
               value="<?= old('name') ?>" maxlength="140"
               placeholder="As you would like us to address you">
      </div>

      <div class="field">
        <label class="label" for="password">Choose a password</label>
        <input class="input" type="password" id="password" name="password"
               required minlength="8" autocomplete="new-password">
        <span class="field-hint">At least 8 characters.</span>
      </div>

      <div class="field">
        <label class="label" for="password_confirm">Type it again</label>
        <input class="input" type="password" id="password_confirm" name="password_confirm"
               required minlength="8" autocomplete="new-password">
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Finish setting up</button>
    </form>
  </div>

  <p class="text-xs text-muted text-center mt-16">
    Code not arrived? <a href="<?= url('/portal/start') ?>">Ask for another</a>.
    Check your spam folder first — it often lands there.
  </p>
</div>
