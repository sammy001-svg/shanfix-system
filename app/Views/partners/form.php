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
          <span class="field-hint">Needed before they can be paid.</span>
        </div>

        <div class="field">
          <label class="label" for="default_rate">Commission rate</label>
          <div class="input-group">
            <input class="input" type="number" id="default_rate" name="default_rate"
                   step="0.5" min="0" max="100"
                   value="<?= e($val('default_rate', rtrim(rtrim(number_format($rate, 2), '0'), '.'))) ?>">
            <span class="input-group__addon">%</span>
          </div>
          <span class="field-hint">Used where a service does not carry its own rate.</span>
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
