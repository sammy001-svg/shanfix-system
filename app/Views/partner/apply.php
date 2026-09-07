<?php
/**
 * Asking to become a partner.
 *
 * The terms are stated before the form rather than after it, because
 * somebody deciding whether to fill this in wants to know what they get
 * and when they get it.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<h1 class="login__title">Become a partner</h1>
<p class="login__intro">
  Bring us customers and earn a share of everything they spend — the first
  job and every one after it.
</p>

<div class="apply-terms">
  <div class="apply-terms__row">
    <span class="apply-terms__figure"><?= e(rtrim(rtrim(number_format($rate, 2), '0'), '.')) ?>%</span>
    <span class="apply-terms__label">
      to start, on what your customers spend. Some services pay more.
    </span>
  </div>
  <?php if (trim((string) $terms) !== ''): ?>
    <p class="apply-terms__note"><?= e($terms) ?></p>
  <?php endif; ?>
</div>

<form method="post" action="<?= url('/partners/apply') ?>">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="name">Your name</label>
    <div class="input-icon">
      <?= icon('user') ?>
      <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>"
             type="text" id="name" name="name" value="<?= old('name') ?>"
             required maxlength="140" autofocus>
    </div>
    <?= error_for($errors ?? [], 'name') ?>
  </div>

  <div class="field">
    <label class="label" for="company">
      Your business <span class="text-muted">(optional)</span>
    </label>
    <div class="input-icon">
      <?= icon('briefcase') ?>
      <input class="input" type="text" id="company" name="company"
             value="<?= old('company') ?>" maxlength="180">
    </div>
  </div>

  <div class="field">
    <label class="label" for="email">Email address</label>
    <div class="input-icon">
      <?= icon('mail') ?>
      <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
             type="email" id="email" name="email" value="<?= old('email') ?>"
             required autocomplete="email" inputmode="email">
    </div>
    <?= error_for($errors ?? [], 'email') ?>
    <span class="field-hint">This is what you will sign in with.</span>
  </div>

  <div class="field">
    <label class="label" for="phone">Phone number</label>
    <div class="input-icon">
      <?= icon('phone') ?>
      <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>"
             type="tel" id="phone" name="phone" value="<?= old('phone') ?>"
             required maxlength="30" inputmode="tel" autocomplete="tel"
             placeholder="07XX XXX XXX">
    </div>
    <?= error_for($errors ?? [], 'phone') ?>
  </div>

  <div class="field">
    <label class="label" for="pitch">Who do you sell to?</label>
    <textarea class="input <?= isset($errors['pitch']) ? 'has-error' : '' ?>"
              id="pitch" name="pitch" rows="4" required
              placeholder="The kind of businesses you deal with, and what you think they would need from us."><?= old('pitch') ?></textarea>
    <?= error_for($errors ?? [], 'pitch') ?>
  </div>

  <div class="field">
    <label class="label" for="kra_pin">
      KRA PIN <span class="text-muted">(optional)</span>
    </label>
    <input class="input" type="text" id="kra_pin" name="kra_pin"
           value="<?= old('kra_pin') ?>" maxlength="30">
    <span class="field-hint">We need this before we can pay you, but not to consider you.</span>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Send my application
    <?= icon('arrow-right') ?>
  </button>
</form>

<p class="login__help">
  <a href="<?= url('/partners/login') ?>"><?= icon('arrow-left') ?> Back to partner sign in</a>
</p>
