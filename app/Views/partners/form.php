<?php
/**
 * Registering a partner ourselves.
 *
 * Some are signed up over a table rather than through the website, and
 * they should not have to go and fill in the public form afterwards. What
 * they still do themselves is set their password, from a code — nobody
 * here ever knows it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$val = static fn(string $k, string $d = ''): string => (string) old($k, $d);
?>

<p class="text-sm mb-12">
  <a href="<?= url('/partners-admin') ?>">&larr; All partners</a>
</p>

<div class="page-head">
  <div class="page-head__text">
    <h1>Register a partner</h1>
    <div class="page-head__sub">
      Somebody you have signed up directly, rather than one who applied
    </div>
  </div>
</div>

<form method="post" action="<?= url('/partners-admin/new') ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head"><div class="card__title">Who they are</div></div>
    <div class="card__body">
      <div class="form-grid">
        <?php // Three parts rather than one box, because that is how a name
              // appears on an ID and a KRA certificate — the documents it
              // has to match when money moves. ?>
        <div class="field">
          <label class="label" for="first_name">First name <span class="req">*</span></label>
          <input class="input <?= isset($errors['first_name']) ? 'has-error' : '' ?>"
                 type="text" id="first_name" name="first_name" required maxlength="60"
                 value="<?= e($val('first_name')) ?>" autofocus>
          <?= error_for($errors ?? [], 'first_name') ?>
        </div>

        <div class="field">
          <label class="label" for="middle_name">Second name</label>
          <input class="input" type="text" id="middle_name" name="middle_name" maxlength="60"
                 value="<?= e($val('middle_name')) ?>">
          <span class="field-hint">Not everyone uses one.</span>
        </div>

        <div class="field">
          <label class="label" for="last_name">Third name <span class="req">*</span></label>
          <input class="input <?= isset($errors['last_name']) ? 'has-error' : '' ?>"
                 type="text" id="last_name" name="last_name" required maxlength="60"
                 value="<?= e($val('last_name')) ?>">
          <?= error_for($errors ?? [], 'last_name') ?>
        </div>

        <div class="field">
          <label class="label" for="id_number">ID number</label>
          <input class="input" type="text" id="id_number" name="id_number" maxlength="30"
                 value="<?= e($val('id_number')) ?>">
        </div>

        <div class="field">
          <label class="label" for="occupation">Occupation</label>
          <input class="input" type="text" id="occupation" name="occupation" maxlength="120"
                 value="<?= e($val('occupation')) ?>"
                 placeholder="Architect, supplies dealer, events organiser…">
        </div>

        <div class="field">
          <label class="label" for="company">Business</label>
          <input class="input" type="text" id="company" name="company" maxlength="180"
                 value="<?= e($val('company')) ?>">
        </div>

        <div class="field">
          <label class="label" for="office_location">Office location</label>
          <input class="input" type="text" id="office_location" name="office_location" maxlength="200"
                 value="<?= e($val('office_location')) ?>"
                 placeholder="Town, building, floor">
        </div>

        <div class="field">
          <label class="label" for="email">Email address <span class="req">*</span></label>
          <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
                 type="email" id="email" name="email" required maxlength="160"
                 value="<?= e($val('email')) ?>">
          <?= error_for($errors ?? [], 'email') ?>
          <span class="field-hint">This is what they will sign in with.</span>
        </div>

        <div class="field">
          <label class="label" for="phone">Phone <span class="req">*</span></label>
          <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>"
                 type="tel" id="phone" name="phone" required maxlength="30"
                 value="<?= e($val('phone')) ?>" placeholder="07XX XXX XXX">
          <?= error_for($errors ?? [], 'phone') ?>
        </div>

        <div class="field">
          <label class="label" for="kra_pin">KRA PIN</label>
          <input class="input" type="text" id="kra_pin" name="kra_pin" maxlength="30"
                 value="<?= e($val('kra_pin')) ?>">
          <span class="field-hint">
            Needed before a partner in Kenya can be paid. One abroad gives
            their own country's tax number below instead.
          </span>
        </div>
      </div>
    </div>
  </div>

  <?php // ---- Where in the world, and on what terms ---------------------
        // Its own card rather than more fields in the one above: these are
        // the answers that decide how somebody can be paid and what has to
        // be withheld, and they are easy to leave at the default by
        // accident when they are buried among the name fields. ?>
  <div class="card">
    <div class="card__head">
      <div class="card__title">Where they are, and on what terms</div>
      <p class="text-xs text-muted mb-0">
        The country decides how we can pay them, in what currency, and
        what the tax position is — so it is worth getting right now
        rather than on payment day.
      </p>
    </div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="country">Country <span class="req">*</span></label>
          <select class="select" id="country" name="country" required>
            <?php foreach ($countries as $code => $name): ?>
              <option value="<?= e($code) ?>"
                      <?= $val('country', $home) === $code ? 'selected' : '' ?>>
                <?= e($name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label" for="city">City</label>
          <input class="input" type="text" id="city" name="city" maxlength="90"
                 value="<?= e($val('city')) ?>">
        </div>

        <div class="field">
          <label class="label" for="partner_type">Type</label>
          <select class="select" id="partner_type" name="partner_type">
            <?php foreach (\App\Services\Partners::TYPES as $code => $desc): ?>
              <option value="<?= e($code) ?>"
                      <?= $val('partner_type', 'referral') === $code ? 'selected' : '' ?>>
                <?= e($desc) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label" for="tier">Tier</label>
          <select class="select" id="tier" name="tier">
            <?php foreach ($tiers as $code => $t): ?>
              <option value="<?= e($code) ?>"
                      <?= $val('tier', 'registered') === $code ? 'selected' : '' ?>>
                <?= e($t['name']) ?>
                (<?= e(rtrim(rtrim(number_format((float) $t['default_rate'], 2), '0'), '.')) ?>%)
              </option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint">Nearly everybody starts at Registered.</span>
        </div>

        <div class="field">
          <label class="label" for="territory">Territory</label>
          <input class="input" type="text" id="territory" name="territory" maxlength="140"
                 value="<?= e($val('territory')) ?>" placeholder="e.g. Uganda and Rwanda">
          <span class="field-hint">Blank means no restriction.</span>
        </div>

        <div class="field">
          <label class="label" for="payout_currency">Paid in</label>
          <select class="select" id="payout_currency" name="payout_currency">
            <?php foreach ($currencies as $code => $label): ?>
              <option value="<?= e($code) ?>"
                      <?= $val('payout_currency', $base) === $code ? 'selected' : '' ?>>
                <?= e($code) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint">
            Commission is always earned in <?= e($base) ?>; this is what it
            is converted to when it goes out.
          </span>
        </div>

        <div class="field">
          <label class="label" for="tax_id">Tax number</label>
          <input class="input" type="text" id="tax_id" name="tax_id" maxlength="40"
                 value="<?= e($val('tax_id')) ?>">
          <span class="field-hint">
            Whatever their own authority calls it — TIN, VAT number, EIN.
          </span>
        </div>

        <div class="field">
          <label class="label" for="tax_residency">Tax residency</label>
          <select class="select" id="tax_residency" name="tax_residency">
            <option value="resident"
                    <?= $val('tax_residency', 'resident') === 'resident' ? 'selected' : '' ?>>
              Resident for Kenyan tax
            </option>
            <option value="non_resident"
                    <?= $val('tax_residency') === 'non_resident' ? 'selected' : '' ?>>
              Non-resident
            </option>
          </select>
          <span class="field-hint">
            Not simply their country: a Kenyan-registered company owned
            from abroad is still resident.
          </span>
        </div>

        <div class="field">
          <label class="label" for="default_rate">Commission rate</label>
          <div class="input-group">
            <input class="input" type="number" id="default_rate" name="default_rate"
                   step="0.5" min="0" max="100"
                   value="<?= e($val('default_rate')) ?>">
            <span class="input-group__addon">%</span>
          </div>
          <span class="field-hint">
            Leave it empty to use the rate their tier carries. Used where a
            service does not carry its own.
          </span>
        </div>

        <div class="field">
          <label class="label" for="agreement_signed_at">Agreement signed</label>
          <input class="input" type="date" id="agreement_signed_at" name="agreement_signed_at"
                 value="<?= e($val('agreement_signed_at')) ?>">
        </div>

        <div class="field">
          <label class="label" for="agreement_expires_at">Agreement runs until</label>
          <input class="input" type="date" id="agreement_expires_at" name="agreement_expires_at"
                 value="<?= e($val('agreement_expires_at')) ?>">
          <span class="field-hint">Blank means it does not expire.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">Looking after them</div></div>
    <div class="card__body">
      <div class="field">
        <label class="label" for="account_manager_id">Account manager</label>
        <select class="select" id="account_manager_id" name="account_manager_id">
          <option value="">Nobody yet</option>
          <?php foreach ($managers as $m): ?>
            <option value="<?= (int) $m['id'] ?>"
              <?= $val('account_manager_id') === (string) $m['id'] ? 'selected' : '' ?>>
              <?= e($m['name']) ?> (<?= e(label_of((string) $m['role'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <span class="field-hint">
          Their contact point here. It is a relationship, not an authority —
          being assigned does not let somebody approve, pay or register a
          partner.
        </span>
      </div>

      <div class="field">
        <label class="label" for="pitch">Who they sell to</label>
        <textarea class="input" id="pitch" name="pitch" rows="3"><?= e($val('pitch')) ?></textarea>
      </div>

      <div class="field">
        <label class="label" for="notes">Internal notes</label>
        <textarea class="input" id="notes" name="notes" rows="3"><?= e($val('notes')) ?></textarea>
        <span class="field-hint">Never shown to the partner.</span>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn--primary" type="submit">
      <?= icon('check') ?> Register them
    </button>
    <a class="btn btn--ghost" href="<?= url('/partners-admin') ?>">Cancel</a>
  </div>

  <p class="text-xs text-muted mt-8">
    They will be sent a link to set their own password. Nobody here ever sees it.
  </p>
</form>
