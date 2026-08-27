<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="portal-narrow">
  <div class="portal-card">
    <h1 class="portal-h1">Ask us for access</h1>
    <p class="portal-lede">
      If we have only ever had your phone number, tell us who you are and we
      will check our records. When it matches, we will text you a code on that
      number.
    </p>

    <form method="post" action="<?= url('/portal/request-access') ?>">
      <?= csrf_field() ?>

      <div class="field">
        <label class="label" for="full_name">Your full name</label>
        <input class="input" type="text" id="full_name" name="full_name"
               value="<?= old('full_name') ?>" required maxlength="140" autofocus
               placeholder="As it appears on our invoices to you">
      </div>

      <div class="field">
        <label class="label" for="phone">The phone number we have for you</label>
        <input class="input" type="tel" id="phone" name="phone"
               value="<?= old('phone') ?>" required maxlength="30"
               placeholder="07XX XXX XXX">
        <span class="field-hint">
          It has to be a number already on your account — that is what proves it is you.
        </span>
      </div>

      <div class="field">
        <label class="label" for="email">
          An email address for the account <span class="text-muted">(optional)</span>
        </label>
        <input class="input" type="email" id="email" name="email"
               value="<?= old('email') ?>" maxlength="160">
      </div>

      <div class="field">
        <label class="label" for="note">Anything else <span class="text-muted">(optional)</span></label>
        <input class="input" type="text" id="note" name="note" maxlength="255">
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Send the request</button>
    </form>
  </div>

  <p class="text-xs text-muted text-center mt-16">
    <a href="<?= url('/portal/login') ?>">Back to sign in</a>
  </p>
</div>
