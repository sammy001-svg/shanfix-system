<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<h1 class="login__title">Ask us for access</h1>
<p class="login__intro">
  If we have only ever had your phone number, tell us who you are and we
  will check our records. When it matches, we will text you a code on that
  number.
</p>

<form method="post" action="<?= url('/portal/request-access') ?>">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="full_name">Your full name</label>
    <div class="input-icon">
      <?= icon('user') ?>
      <input class="input <?= isset($errors['full_name']) ? 'has-error' : '' ?>"
             type="text" id="full_name" name="full_name"
             value="<?= old('full_name') ?>" required maxlength="140" autofocus
             placeholder="As it appears on our invoices to you">
    </div>
    <?= error_for($errors ?? [], 'full_name') ?>
  </div>

  <div class="field">
    <label class="label" for="phone">The phone number we have for you</label>
    <div class="input-icon">
      <?= icon('phone') ?>
      <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>"
             type="tel" id="phone" name="phone"
             value="<?= old('phone') ?>" required maxlength="30"
             inputmode="tel" autocomplete="tel"
             placeholder="07XX XXX XXX">
    </div>
    <?= error_for($errors ?? [], 'phone') ?>
    <span class="field-hint">
      It has to be a number already on your account — that is what proves it is you.
    </span>
  </div>

  <div class="field">
    <label class="label" for="email">
      An email address for the account <span class="text-muted">(optional)</span>
    </label>
    <div class="input-icon">
      <?= icon('mail') ?>
      <input class="input" type="email" id="email" name="email"
             value="<?= old('email') ?>" maxlength="160"
             autocomplete="email" inputmode="email">
    </div>
  </div>

  <div class="field">
    <label class="label" for="note">Anything else <span class="text-muted">(optional)</span></label>
    <input class="input" type="text" id="note" name="note"
           value="<?= old('note') ?>" maxlength="255">
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Send the request
    <?= icon('arrow-right') ?>
  </button>
</form>

<p class="login__help">
  <a href="<?= url('/portal/login') ?>"><?= icon('arrow-left') ?> Back to sign in</a>
</p>
