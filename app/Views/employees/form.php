<?php
/**
 * Adding or correcting somebody's record.
 *
 * Used both for a new person and from the Details tab of an existing one,
 * so every field reads from the record where there is one and from what
 * was typed where a save bounced.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$e   = $employee ?? null;
$val = static fn(string $k, string $d = ''): string =>
    (string) old($k, (string) ($e[$k] ?? $d));

$action = $e ? url('/staff/' . (int) $e['id']) : url('/staff/new');
?>

<?php if (!$e): ?>
  <p class="text-sm mb-12"><a href="<?= url('/staff') ?>">&larr; Staff</a></p>

  <div class="page-head">
    <div class="page-head__text">
      <h1>Add someone</h1>
      <div class="page-head__sub">Their record here is separate from any login they may have</div>
    </div>
  </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head"><div class="card__title">Who they are</div></div>
    <div class="card__body">
      <div class="form-grid">
        <?php // Three parts, as on an ID and a KRA certificate — the
              // documents this name has to match when money moves. ?>
        <div class="field">
          <label class="label" for="first_name">First name <span class="req">*</span></label>
          <input class="input <?= isset($errors['first_name']) ? 'has-error' : '' ?>"
                 type="text" id="first_name" name="first_name" required maxlength="60"
                 value="<?= e($val('first_name')) ?>" <?= $e ? '' : 'autofocus' ?>>
          <?= error_for($errors ?? [], 'first_name') ?>
        </div>
        <div class="field">
          <label class="label" for="middle_name">Second name</label>
          <input class="input" type="text" id="middle_name" name="middle_name" maxlength="60"
                 value="<?= e($val('middle_name')) ?>">
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
          <label class="label" for="date_of_birth">Date of birth</label>
          <input class="input" type="date" id="date_of_birth" name="date_of_birth"
                 value="<?= e($val('date_of_birth')) ?>">
        </div>
        <div class="field">
          <label class="label" for="gender">Gender</label>
          <select class="select" id="gender" name="gender">
            <?php foreach (['' => 'Not said', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $val('gender') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label" for="phone">Phone</label>
          <input class="input" type="tel" id="phone" name="phone" maxlength="30"
                 value="<?= e($val('phone')) ?>" placeholder="07XX XXX XXX">
        </div>
        <div class="field">
          <label class="label" for="email">Email</label>
          <input class="input" type="email" id="email" name="email" maxlength="160"
                 value="<?= e($val('email')) ?>">
        </div>
        <div class="field">
          <label class="label" for="town">Town</label>
          <input class="input" type="text" id="town" name="town" maxlength="80"
                 value="<?= e($val('town')) ?>">
        </div>
        <div class="field">
          <label class="label" for="address">Address</label>
          <input class="input" type="text" id="address" name="address" maxlength="255"
                 value="<?= e($val('address')) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <div class="card__title">Next of kin</div>
      <span class="text-xs text-muted">Asked once, so it is there on the day it is needed</span>
    </div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="kin_name">Name</label>
          <input class="input" type="text" id="kin_name" name="kin_name" maxlength="140"
                 value="<?= e($val('kin_name')) ?>">
        </div>
        <div class="field">
          <label class="label" for="kin_phone">Phone</label>
          <input class="input" type="tel" id="kin_phone" name="kin_phone" maxlength="30"
                 value="<?= e($val('kin_phone')) ?>">
        </div>
        <div class="field">
          <label class="label" for="kin_relationship">Relationship</label>
          <input class="input" type="text" id="kin_relationship" name="kin_relationship" maxlength="60"
                 value="<?= e($val('kin_relationship')) ?>" placeholder="Spouse, parent, sibling…">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">The job</div></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="job_title">Job title</label>
          <input class="input" type="text" id="job_title" name="job_title" maxlength="120"
                 value="<?= e($val('job_title')) ?>">
        </div>
        <div class="field">
          <label class="label" for="department">Department</label>
          <input class="input" type="text" id="department" name="department" maxlength="120"
                 value="<?= e($val('department')) ?>" placeholder="Production, design, office…">
        </div>
        <div class="field">
          <label class="label" for="employment_type">Terms</label>
          <select class="select" id="employment_type" name="employment_type">
            <?php foreach (['permanent' => 'Permanent', 'contract' => 'Contract', 'casual' => 'Casual', 'intern' => 'Intern'] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $val('employment_type', 'permanent') === $k ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="started_on">Started</label>
          <input class="input" type="date" id="started_on" name="started_on"
                 value="<?= e($val('started_on')) ?>">
        </div>
        <div class="field">
          <label class="label" for="status">State</label>
          <select class="select" id="status" name="status">
            <?php foreach (['active' => 'Working', 'on_leave' => 'On leave', 'suspended' => 'Suspended', 'left' => 'Left'] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $val('status', 'active') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="ended_on">Last day</label>
          <input class="input" type="date" id="ended_on" name="ended_on"
                 value="<?= e($val('ended_on')) ?>">
          <span class="field-hint">Filling this in marks them as having left, whatever the box above says.</span>
        </div>
        <div class="field">
          <label class="label" for="end_reason">Why they left</label>
          <input class="input" type="text" id="end_reason" name="end_reason" maxlength="255"
                 value="<?= e($val('end_reason')) ?>">
        </div>

        <?php // A login belongs to at most one person, so the picker only
              // offers accounts nobody else has claimed. ?>
        <div class="field">
          <label class="label" for="user_id">Their login</label>
          <select class="select" id="user_id" name="user_id">
            <option value="">No login — they do not use the system</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>"
                <?= (string) $val('user_id') === (string) $u['id'] ? 'selected' : '' ?>>
                <?= e($u['name']) ?> — <?= e($u['email']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint">Only accounts not already attached to somebody else are listed.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <div class="card__title">Pay</div>
      <span class="text-xs text-muted">Allowances and deductions are added on their own tab</span>
    </div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="basic_salary">Basic salary, monthly</label>
          <input class="input" type="number" step="0.01" min="0" id="basic_salary" name="basic_salary"
                 value="<?= e($val('basic_salary', '0')) ?>">
        </div>
        <div class="field">
          <label class="label" for="kra_pin">KRA PIN</label>
          <input class="input" type="text" id="kra_pin" name="kra_pin" maxlength="30"
                 value="<?= e($val('kra_pin')) ?>">
          <span class="field-hint">Needed on the PAYE return.</span>
        </div>
        <div class="field">
          <label class="label" for="nssf_number">NSSF number</label>
          <input class="input" type="text" id="nssf_number" name="nssf_number" maxlength="30"
                 value="<?= e($val('nssf_number')) ?>">
        </div>
        <div class="field">
          <label class="label" for="shif_number">Health insurance number</label>
          <input class="input" type="text" id="shif_number" name="shif_number" maxlength="30"
                 value="<?= e($val('shif_number')) ?>">
        </div>

        <div class="field">
          <label class="label" for="pay_method">Paid by</label>
          <select class="select" id="pay_method" name="pay_method">
            <option value="">Not set</option>
            <?php foreach (['mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'cash' => 'Cash'] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $val('pay_method') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="pay_phone">M-Pesa number</label>
          <input class="input" type="tel" id="pay_phone" name="pay_phone" maxlength="30"
                 value="<?= e($val('pay_phone')) ?>">
          <span class="field-hint">Leave empty to use their contact number.</span>
        </div>
        <div class="field">
          <label class="label" for="bank_name">Bank</label>
          <input class="input" type="text" id="bank_name" name="bank_name" maxlength="120"
                 value="<?= e($val('bank_name')) ?>">
        </div>
        <div class="field">
          <label class="label" for="bank_branch">Branch</label>
          <input class="input" type="text" id="bank_branch" name="bank_branch" maxlength="120"
                 value="<?= e($val('bank_branch')) ?>">
        </div>
        <div class="field">
          <label class="label" for="bank_account_name">Account name</label>
          <input class="input" type="text" id="bank_account_name" name="bank_account_name" maxlength="160"
                 value="<?= e($val('bank_account_name')) ?>">
        </div>
        <div class="field">
          <label class="label" for="bank_account_no">Account number</label>
          <input class="input" type="text" id="bank_account_no" name="bank_account_no" maxlength="40"
                 value="<?= e($val('bank_account_no')) ?>">
        </div>
      </div>

      <div class="field">
        <label class="label" for="notes">Internal notes</label>
        <textarea class="input" id="notes" name="notes" rows="3"><?= e($val('notes')) ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn--primary" type="submit">
      <?= icon('check') ?> <?= $e ? 'Save' : 'Add them' ?>
    </button>
    <a class="btn btn--ghost" href="<?= url($e ? '/staff/' . (int) $e['id'] : '/staff') ?>">Cancel</a>
  </div>
</form>
