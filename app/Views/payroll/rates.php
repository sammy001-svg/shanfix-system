<?php
/**
 * The tax law, as settings.
 *
 * This page exists because nothing in the payroll code knows a rate.
 * Kenyan payroll changed three times in three years — NHIF became SHIF,
 * the housing levy arrived, NSSF moved to two tiers on a rising ceiling,
 * and what comes off before PAYE changed with the Tax Laws (Amendment)
 * Act 2024. Anything written into the code would have been wrong within a
 * year, silently, on somebody's wages.
 *
 * The confirmation box at the bottom is not a formality: a payroll cannot
 * be approved until somebody has ticked it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$canEdit = Auth::can('payroll.approve');
?>

<p class="text-sm mb-12"><a href="<?= url('/payroll') ?>">&larr; Payroll</a></p>

<div class="page-head">
  <div class="page-head__text">
    <h1>Tax rates and bands</h1>
    <div class="page-head__sub">
      What the payroll calculation uses. All of it, and nothing else.
    </div>
  </div>
</div>

<?php if (!$rates['confirmed']): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>Nobody has checked these yet.</strong>
      They were seeded with the figures believed current when this was
      built, which is a starting point and not authority. Go through them
      with your accountant or against KRA, correct anything that has moved,
      then tick the box at the bottom. Until you do, no payroll can be
      approved.
    </div>
  </div>
<?php else: ?>
  <div class="alert alert--success">
    <?= icon('check-circle') ?>
    <div class="alert__body">
      These have been checked and confirmed. Untick the box at the bottom if
      you want them looked at again before the next run.
    </div>
  </div>
<?php endif; ?>

<?php if (!$canEdit): ?>
  <div class="card">
    <div class="card__body text-sm text-muted">
      You can read these. Changing a tax rate changes what everybody is
      paid, so it sits with whoever can approve a payroll.
    </div>
  </div>
<?php endif; ?>

<form method="post" action="<?= url('/payroll/rates') ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head">
      <div class="card__title">PAYE</div>
      <span class="text-xs text-muted">Monthly</span>
    </div>
    <div class="card__body">
      <div class="field">
        <label class="label" for="payroll_paye_bands">The bands</label>
        <textarea class="input" id="payroll_paye_bands" name="payroll_paye_bands" rows="7"
                  <?= $canEdit ? '' : 'readonly' ?>><?= e($raw['bands']) ?></textarea>
        <span class="field-hint">
          A list, in order. Each entry gives the ceiling it runs up to and
          the rate on the slice below it; the last has
          <code>"upto": null</code> because it has no ceiling. The whole of
          a salary is never taxed at the top rate — only the part above the
          previous ceiling is.
        </span>
      </div>

      <div class="form-grid">
        <div class="field">
          <label class="label" for="payroll_personal_relief">Personal relief, monthly</label>
          <input class="input" type="number" step="0.01" min="0"
                 id="payroll_personal_relief" name="payroll_personal_relief"
                 value="<?= e((string) $rates['personal_relief']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <span class="field-hint">Taken off the tax, not off pay. It cannot make the tax negative.</span>
        </div>
        <div class="field">
          <label class="label" for="payroll_insurance_relief_rate">Insurance relief</label>
          <div class="input-group">
            <input class="input" type="number" step="0.01" min="0"
                   id="payroll_insurance_relief_rate" name="payroll_insurance_relief_rate"
                   value="<?= e((string) $rates['insurance_rate']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
            <span class="input-group__addon">%</span>
          </div>
          <span class="field-hint">Of premiums recorded as a deduction with "insurance" in the name.</span>
        </div>
        <div class="field">
          <label class="label" for="payroll_insurance_relief_cap">Insurance relief cap</label>
          <input class="input" type="number" step="0.01" min="0"
                 id="payroll_insurance_relief_cap" name="payroll_insurance_relief_cap"
                 value="<?= e((string) $rates['insurance_cap']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">NSSF</div></div>
    <div class="card__body">
      <label class="check">
        <input type="checkbox" name="payroll_nssf_enabled" value="1"
               <?= $rates['nssf_on'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
        <span class="check__text"><strong>Deduct NSSF</strong></span>
      </label>

      <div class="form-grid">
        <div class="field">
          <label class="label" for="payroll_nssf_rate">Rate</label>
          <div class="input-group">
            <input class="input" type="number" step="0.01" min="0"
                   id="payroll_nssf_rate" name="payroll_nssf_rate"
                   value="<?= e((string) $rates['nssf_rate']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
            <span class="input-group__addon">%</span>
          </div>
        </div>
        <div class="field">
          <label class="label" for="payroll_nssf_lel">Lower limit — tier I</label>
          <input class="input" type="number" step="1" min="0"
                 id="payroll_nssf_lel" name="payroll_nssf_lel"
                 value="<?= e((string) $rates['nssf_lel']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
        </div>
        <div class="field">
          <label class="label" for="payroll_nssf_uel">Upper limit — tier II</label>
          <input class="input" type="number" step="1" min="0"
                 id="payroll_nssf_uel" name="payroll_nssf_uel"
                 value="<?= e((string) $rates['nssf_uel']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <span class="field-hint">Nothing above this attracts it. The employer pays the same again.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">Health insurance</div></div>
    <div class="card__body">
      <label class="check">
        <input type="checkbox" name="payroll_shif_enabled" value="1"
               <?= $rates['shif_on'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
        <span class="check__text"><strong>Deduct SHIF</strong></span>
      </label>

      <div class="form-grid">
        <div class="field">
          <label class="label" for="payroll_shif_rate">Rate on gross</label>
          <div class="input-group">
            <input class="input" type="number" step="0.01" min="0"
                   id="payroll_shif_rate" name="payroll_shif_rate"
                   value="<?= e((string) $rates['shif_rate']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
            <span class="input-group__addon">%</span>
          </div>
        </div>
        <div class="field">
          <label class="label" for="payroll_shif_min">The least anybody pays</label>
          <input class="input" type="number" step="0.01" min="0"
                 id="payroll_shif_min" name="payroll_shif_min"
                 value="<?= e((string) $rates['shif_min']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <span class="field-hint">A floor, not a ceiling — there is no upper limit on this one.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">Housing levy</div></div>
    <div class="card__body">
      <label class="check">
        <input type="checkbox" name="payroll_housing_enabled" value="1"
               <?= $rates['housing_on'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
        <span class="check__text"><strong>Deduct the housing levy</strong></span>
      </label>

      <div class="form-grid">
        <div class="field">
          <label class="label" for="payroll_housing_rate">Employee's share</label>
          <div class="input-group">
            <input class="input" type="number" step="0.01" min="0"
                   id="payroll_housing_rate" name="payroll_housing_rate"
                   value="<?= e((string) $rates['housing_rate']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
            <span class="input-group__addon">%</span>
          </div>
        </div>
        <div class="field">
          <label class="label" for="payroll_housing_employer_rate">Employer's share</label>
          <div class="input-group">
            <input class="input" type="number" step="0.01" min="0"
                   id="payroll_housing_employer_rate" name="payroll_housing_employer_rate"
                   value="<?= e((string) $rates['housing_employer']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
            <span class="input-group__addon">%</span>
          </div>
          <span class="field-hint">Our cost, not their deduction.</span>
        </div>
      </div>
    </div>
  </div>

  <?php // The setting most likely to be wrong, and the one that moves
        // everybody's tax when it is. ?>
  <div class="card">
    <div class="card__head">
      <div class="card__title">What comes off before PAYE</div>
    </div>
    <div class="card__body">
      <div class="field">
        <label class="label" for="payroll_pre_tax_deductions">Deducted from taxable pay</label>
        <input class="input" type="text" id="payroll_pre_tax_deductions" name="payroll_pre_tax_deductions"
               value="<?= e($raw['pre_tax']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
        <span class="field-hint">
          A list of any of <code>"nssf"</code>, <code>"shif"</code> and
          <code>"housing"</code>. The Tax Laws (Amendment) Act 2024 made SHIF
          and the levy deductible from taxable pay, where before they were
          reliefs against the tax itself. If your accountant says otherwise
          for the period you are running, change this and nothing else — it
          is the single line here that moves everybody's tax.
        </span>
      </div>

      <div class="field" style="max-width:220px">
        <label class="label" for="payroll_round_dp">Round to</label>
        <select class="select" id="payroll_round_dp" name="payroll_round_dp" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="0" <?= (int) $rates['dp'] === 0 ? 'selected' : '' ?>>Whole shillings</option>
          <option value="2" <?= (int) $rates['dp'] === 2 ? 'selected' : '' ?>>Shillings and cents</option>
        </select>
      </div>
    </div>
  </div>

  <?php if ($canEdit): ?>
    <div class="card">
      <div class="card__body">
        <label class="check">
          <input type="checkbox" name="payroll_rates_confirmed" value="1"
                 <?= $rates['confirmed'] ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>I have checked these against KRA and they are right for the
            period being run.</strong>
            No payroll can be approved until this is ticked. Untick it when
            the law changes, so the next person is warned.
          </span>
        </label>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit">
            <?= icon('check') ?> Save these rates
          </button>
          <span class="text-xs text-muted">
            They apply to the next run worked out. A payroll already
            calculated keeps the figures it was calculated with.
          </span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</form>
