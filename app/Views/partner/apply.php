<?php
/**
 * Asking to become a partner.
 *
 * The terms are stated before the form rather than after it, because
 * somebody deciding whether to fill this in wants to know what they get
 * and when they get it.
 *
 * It asks for more than it used to. What it asks for is the difference
 * between an expression of interest and something we can act on: a name
 * that matches an ID, a way to reach them, and somewhere to send money.
 * The form is grouped so it still reads as three short asks rather than
 * one long one.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$payMethod = old('pay_method');
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

  <?php // ---- Who you are ---------------------------------------------- ?>
  <p class="apply-group">Who you are</p>

  <div class="field">
    <label class="label" for="first_name">First name</label>
    <div class="input-icon">
      <?= icon('user') ?>
      <input class="input <?= isset($errors['first_name']) ? 'has-error' : '' ?>"
             type="text" id="first_name" name="first_name" value="<?= old('first_name') ?>"
             required maxlength="60" autocomplete="given-name" autofocus>
    </div>
    <?= error_for($errors ?? [], 'first_name') ?>
  </div>

  <div class="field">
    <label class="label" for="middle_name">
      Second name <span class="text-muted">(if you use one)</span>
    </label>
    <input class="input" type="text" id="middle_name" name="middle_name"
           value="<?= old('middle_name') ?>" maxlength="60"
           autocomplete="additional-name">
  </div>

  <div class="field">
    <label class="label" for="last_name">Third name</label>
    <div class="input-icon">
      <?= icon('user') ?>
      <input class="input <?= isset($errors['last_name']) ? 'has-error' : '' ?>"
             type="text" id="last_name" name="last_name" value="<?= old('last_name') ?>"
             required maxlength="60" autocomplete="family-name">
    </div>
    <?= error_for($errors ?? [], 'last_name') ?>
    <span class="field-hint">
      Please give your names as they appear on your ID — we check them
      against it before we pay you.
    </span>
  </div>

  <div class="field">
    <label class="label" for="id_number">ID number</label>
    <div class="input-icon">
      <?= icon('shield') ?>
      <input class="input <?= isset($errors['id_number']) ? 'has-error' : '' ?>"
             type="text" id="id_number" name="id_number" value="<?= old('id_number') ?>"
             required maxlength="30" inputmode="numeric">
    </div>
    <?= error_for($errors ?? [], 'id_number') ?>
  </div>

  <div class="field">
    <label class="label" for="occupation">What you do</label>
    <div class="input-icon">
      <?= icon('briefcase') ?>
      <input class="input <?= isset($errors['occupation']) ? 'has-error' : '' ?>"
             type="text" id="occupation" name="occupation" value="<?= old('occupation') ?>"
             required maxlength="120"
             placeholder="Architect, supplies dealer, events organiser…">
    </div>
    <?= error_for($errors ?? [], 'occupation') ?>
  </div>

  <div class="field">
    <label class="label" for="company">
      Your business <span class="text-muted">(optional)</span>
    </label>
    <input class="input" type="text" id="company" name="company"
           value="<?= old('company') ?>" maxlength="180">
  </div>

  <?php // ---- How we reach you ------------------------------------------ ?>
  <p class="apply-group">How we reach you</p>

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
    <label class="label" for="office_location">Where you work from</label>
    <div class="input-icon">
      <?= icon('map-pin') ?>
      <input class="input <?= isset($errors['office_location']) ? 'has-error' : '' ?>"
             type="text" id="office_location" name="office_location"
             value="<?= old('office_location') ?>" required maxlength="200"
             placeholder="Town, building, floor">
    </div>
    <?= error_for($errors ?? [], 'office_location') ?>
  </div>

  <div class="field">
    <label class="label" for="pitch">Who do you sell to?</label>
    <textarea class="input <?= isset($errors['pitch']) ? 'has-error' : '' ?>"
              id="pitch" name="pitch" rows="4" required
              placeholder="The kind of businesses you deal with, and what you think they would need from us."><?= old('pitch') ?></textarea>
    <?= error_for($errors ?? [], 'pitch') ?>
  </div>

  <?php // ---- Getting paid ----------------------------------------------
        // Asked now rather than at the first payment, because chasing an
        // account number is what holds a payout up. It is a preference,
        // not a promise: staff check it before anything is sent, and it
        // cannot be changed from the portal afterwards. ?>
  <p class="apply-group">Getting paid</p>

  <div class="field">
    <label class="label" for="kra_pin">
      KRA PIN <span class="text-muted">(optional for now)</span>
    </label>
    <input class="input" type="text" id="kra_pin" name="kra_pin"
           value="<?= old('kra_pin') ?>" maxlength="30">
    <span class="field-hint">We need this before we can pay you, but not to consider you.</span>
  </div>

  <div class="field">
    <label class="label" for="pay_method">How you would like to be paid</label>
    <select class="select <?= isset($errors['pay_method']) ? 'has-error' : '' ?>"
            id="pay_method" name="pay_method">
      <option value=""      <?= $payMethod === ''      ? 'selected' : '' ?>>Tell us later</option>
      <option value="mpesa" <?= $payMethod === 'mpesa' ? 'selected' : '' ?>>M-Pesa</option>
      <option value="bank"  <?= $payMethod === 'bank'  ? 'selected' : '' ?>>Bank transfer</option>
    </select>
    <?= error_for($errors ?? [], 'pay_method') ?>
    <span class="field-hint">
      Fill in only the one you chose. You can leave this until later, but
      we cannot pay you until we have it.
    </span>
  </div>

  <div class="field">
    <label class="label" for="pay_phone">
      M-Pesa number <span class="text-muted">(if different from above)</span>
    </label>
    <input class="input <?= isset($errors['pay_phone']) ? 'has-error' : '' ?>"
           type="tel" id="pay_phone" name="pay_phone" value="<?= old('pay_phone') ?>"
           maxlength="30" inputmode="tel" placeholder="07XX XXX XXX">
    <?= error_for($errors ?? [], 'pay_phone') ?>
  </div>

  <div class="field">
    <label class="label" for="bank_name">Bank</label>
    <input class="input" type="text" id="bank_name" name="bank_name"
           value="<?= old('bank_name') ?>" maxlength="120">
  </div>

  <div class="field">
    <label class="label" for="bank_branch">Branch</label>
    <input class="input" type="text" id="bank_branch" name="bank_branch"
           value="<?= old('bank_branch') ?>" maxlength="120">
  </div>

  <div class="field">
    <label class="label" for="bank_account_name">Account name</label>
    <input class="input" type="text" id="bank_account_name" name="bank_account_name"
           value="<?= old('bank_account_name') ?>" maxlength="160">
    <span class="field-hint">Exactly as your bank has it.</span>
  </div>

  <div class="field">
    <label class="label" for="bank_account_no">Account number</label>
    <input class="input <?= isset($errors['bank_account_no']) ? 'has-error' : '' ?>"
           type="text" id="bank_account_no" name="bank_account_no"
           value="<?= old('bank_account_no') ?>" maxlength="40" inputmode="numeric">
    <?= error_for($errors ?? [], 'bank_account_no') ?>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Send my application
    <?= icon('arrow-right') ?>
  </button>
</form>

<p class="login__help">
  <a href="<?= url('/partners/login') ?>"><?= icon('arrow-left') ?> Back to partner sign in</a>
</p>
