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

  <?php // ---- Where they are ---------------------------------------------
        // First of the practical questions, because the two below bend
        // round the answer: a national ID is a thing an applicant here
        // has, and the ways we can pay somebody depend on where they are.
        $chosen = (string) old('country', $home); ?>
  <?php if (!empty($international)): ?>
    <div class="field">
      <label class="label" for="country">Country</label>
      <div class="input-icon">
        <?= icon('globe') ?>
        <select class="select" id="country" name="country" required>
          <?php foreach ($countries as $code => $name): ?>
            <option value="<?= e($code) ?>" <?= $chosen === $code ? 'selected' : '' ?>>
              <?= e($name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <span class="field-hint">
        We work with partners beyond Kenya. Where you are decides how we
        can pay you, and in what currency.
      </span>
    </div>

    <div class="field">
      <label class="label" for="city">City</label>
      <input class="input" type="text" id="city" name="city"
             value="<?= old('city') ?>" maxlength="90">
    </div>
  <?php else: ?>
    <input type="hidden" name="country" value="<?= e($home) ?>">
  <?php endif; ?>

  <?php // Only asked of applicants here. Requiring a Kenyan national ID
        // of somebody in Kampala asks for a document that does not
        // exist, and there is no answer that gets them past it. ?>
  <div class="field" id="idNumberField"<?= $chosen === $home ? '' : ' hidden' ?>>
    <label class="label" for="id_number">ID number</label>
    <div class="input-icon">
      <?= icon('shield') ?>
      <input class="input <?= isset($errors['id_number']) ? 'has-error' : '' ?>"
             type="text" id="id_number" name="id_number" value="<?= old('id_number') ?>"
             maxlength="30" inputmode="numeric">
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

  <div class="field" id="kraField"<?= $chosen === $home ? '' : ' hidden' ?>>
    <label class="label" for="kra_pin">
      KRA PIN <span class="text-muted">(optional for now)</span>
    </label>
    <input class="input" type="text" id="kra_pin" name="kra_pin"
           value="<?= old('kra_pin') ?>" maxlength="30">
    <span class="field-hint">We need this before we can pay you, but not to consider you.</span>
  </div>

  <?php // Everywhere else has its own name for the same number, and
        // asking for it by that name is the difference between a form
        // that has thought about you and one that has not. ?>
  <div class="field" id="taxIdField"<?= $chosen === $home ? ' hidden' : '' ?>>
    <label class="label" for="tax_id">
      <span id="taxIdLabel">Tax number</span>
      <span class="text-muted">(optional for now)</span>
    </label>
    <input class="input" type="text" id="tax_id" name="tax_id"
           value="<?= old('tax_id') ?>" maxlength="40">
    <span class="field-hint">
      Whatever your own tax authority calls it. Needed for the paperwork
      that goes with paying you, not to consider you.
    </span>
  </div>

  <div class="field">
    <label class="label" for="pay_method">How you would like to be paid</label>
    <select class="select <?= isset($errors['pay_method']) ? 'has-error' : '' ?>"
            id="pay_method" name="pay_method">
      <option value="" <?= $payMethod === '' ? 'selected' : '' ?>>Tell us later</option>
      <?php foreach ($payMethods as $code => $label): ?>
        <?php // Every method is in the list and the ones that do not
              // apply are hidden, rather than the list being rebuilt:
              // without scripts they all show and the server still only
              // accepts the ones that work for the chosen country. ?>
        <option value="<?= e($code) ?>" <?= $payMethod === $code ? 'selected' : '' ?>
                data-local="<?= in_array($code, $localOnly, true) ? '1' : '0' ?>">
          <?= e($label) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?= error_for($errors ?? [], 'pay_method') ?>
    <span class="field-hint">
      Fill in only the one you chose. You can leave this until later, but
      we cannot pay you until we have it.
    </span>
  </div>

  <div class="field pay-only" data-for="mpesa">
    <label class="label" for="pay_phone">
      M-Pesa number <span class="text-muted">(if different from above)</span>
    </label>
    <input class="input <?= isset($errors['pay_phone']) ? 'has-error' : '' ?>"
           type="tel" id="pay_phone" name="pay_phone" value="<?= old('pay_phone') ?>"
           maxlength="30" inputmode="tel" placeholder="07XX XXX XXX">
    <?= error_for($errors ?? [], 'pay_phone') ?>
  </div>

  <div class="field pay-only" data-for="wise paypal">
    <label class="label" for="pay_email">Account address</label>
    <input class="input <?= isset($errors['pay_email']) ? 'has-error' : '' ?>"
           type="email" id="pay_email" name="pay_email" value="<?= old('pay_email') ?>"
           maxlength="160">
    <?= error_for($errors ?? [], 'pay_email') ?>
    <span class="field-hint">The address that account is registered to.</span>
  </div>

  <div class="field pay-only" data-for="bank wire">
    <label class="label" for="bank_name">Bank</label>
    <input class="input" type="text" id="bank_name" name="bank_name"
           value="<?= old('bank_name') ?>" maxlength="120">
  </div>

  <div class="field pay-only" data-for="bank wire">
    <label class="label" for="bank_branch">Branch</label>
    <input class="input" type="text" id="bank_branch" name="bank_branch"
           value="<?= old('bank_branch') ?>" maxlength="120">
  </div>

  <div class="field pay-only" data-for="bank wire">
    <label class="label" for="bank_account_name">Account name</label>
    <input class="input" type="text" id="bank_account_name" name="bank_account_name"
           value="<?= old('bank_account_name') ?>" maxlength="160">
    <span class="field-hint">Exactly as your bank has it.</span>
  </div>

  <div class="field pay-only" data-for="bank wire">
    <label class="label" for="bank_account_no">Account number</label>
    <input class="input <?= isset($errors['bank_account_no']) ? 'has-error' : '' ?>"
           type="text" id="bank_account_no" name="bank_account_no"
           value="<?= old('bank_account_no') ?>" maxlength="40">
    <?= error_for($errors ?? [], 'bank_account_no') ?>
  </div>

  <div class="field pay-only" data-for="wire">
    <label class="label" for="bank_iban">IBAN</label>
    <input class="input" type="text" id="bank_iban" name="bank_iban"
           value="<?= old('bank_iban') ?>" maxlength="40">
    <span class="field-hint">If your country uses one. Otherwise the account number above.</span>
  </div>

  <div class="field pay-only" data-for="wire">
    <label class="label" for="bank_swift">SWIFT / BIC</label>
    <input class="input <?= isset($errors['bank_swift']) ? 'has-error' : '' ?>"
           type="text" id="bank_swift" name="bank_swift"
           value="<?= old('bank_swift') ?>" maxlength="20">
    <?= error_for($errors ?? [], 'bank_swift') ?>
    <span class="field-hint">Your bank's code. A transfer cannot be addressed without it.</span>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Send my application
    <?= icon('arrow-right') ?>
  </button>
</form>

<?php // The form follows the country: ask for a Kenyan ID only of a
      // Kenyan applicant, name the tax number the way their own
      // authority does, and offer only the ways we can actually send
      // money to where they are.
      //
      // Progressive. With scripts off every field is visible and the
      // form still submits — which is why the server validates the
      // country-dependent rules as well, rather than trusting this.
      //
      // The nonce is not optional: there is a Content-Security-Policy on
      // every response and an inline script without it is dropped in
      // silence, which looks exactly like a script that does nothing. ?>
<script nonce="<?= e(csp_nonce()) ?>">
  (function () {
    var country = document.getElementById('country');
    var method  = document.getElementById('pay_method');
    var home    = <?= json_encode($home) ?>;
    var labels  = <?= json_encode(array_map(
                        static fn($c) => \App\Services\Partners::taxLabel($c),
                        array_combine(array_keys($countries), array_keys($countries))
                     )) ?>;

    function show(el, on) { if (el) { el.hidden = !on; } }

    function syncCountry() {
      var here = !country || country.value === home;

      show(document.getElementById('idNumberField'), here);
      show(document.getElementById('kraField'), here);
      show(document.getElementById('taxIdField'), !here);

      var label = document.getElementById('taxIdLabel');
      if (label && country) { label.textContent = labels[country.value] || 'Tax number'; }

      // Only the methods that work from where we bank to where they are.
      if (method) {
        var current = method.value;
        Array.prototype.forEach.call(method.options, function (opt) {
          if (opt.value === '') { return; }
          var ok = here ? opt.dataset.local === '1' : opt.dataset.local !== '1';
          opt.hidden = !ok;
          opt.disabled = !ok;
          if (!ok && opt.value === current) { method.value = ''; }
        });
      }

      syncMethod();
    }

    function syncMethod() {
      var chosen = method ? method.value : '';
      Array.prototype.forEach.call(document.querySelectorAll('.pay-only'), function (set) {
        var list = (set.getAttribute('data-for') || '').split(' ');
        set.hidden = list.indexOf(chosen) === -1;
      });
    }

    if (country) { country.addEventListener('change', syncCountry); }
    if (method)  { method.addEventListener('change', syncMethod); }

    syncCountry();
  })();
</script>

<p class="login__help">
  <a href="<?= url('/partners/login') ?>"><?= icon('arrow-left') ?> Back to partner sign in</a>
</p>
