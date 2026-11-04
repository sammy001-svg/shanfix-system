<?php
/**
 * The tiers, their thresholds, and the benefits we publish for each.
 *
 * Editable here because the benefits are shown on the public programme
 * page: a promise on the website that nobody in the system can change
 * is a promise that goes stale.
 *
 * The promotions list at the top is the point of the page. A tier a
 * partner has outgrown is money we are under-paying somebody who is
 * selling well, and nothing else in the system would ever mention it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\Partners;

$canEdit = \App\Core\Auth::can('partners.manage');
?>

<p class="text-sm mb-12">
  <a href="<?= url('/partners-admin') ?>">&larr; All partners</a>
</p>

<div class="page-head">
  <div class="page-head__text">
    <h1>Partner programme</h1>
    <div class="page-head__sub">
      What the programme offers, what each tier is worth, and what we
      promise on the website
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="/partners.php" target="_blank" rel="noopener">
      <?= icon('external-link') ?> The public page
    </a>
    <a class="btn btn--ghost" href="<?= url('/partners-admin/fx') ?>">
      <?= icon('globe') ?> Exchange rates
    </a>
  </div>
</div>

<?php // ---- The programme itself ---------------------------------------
      // These settings have existed since the module was written with
      // nothing anywhere to edit them, so changing the standard rate or
      // switching withholding on meant opening the database. ?>
<?php if ($canEdit): ?>
  <form method="post" action="<?= url('/partners-admin/programme') ?>">
    <?= csrf_field() ?>

    <div class="card mb-16">
      <div class="card__head">
        <h3 class="card__title">The programme</h3>
        <p class="text-xs text-muted mb-0">
          <?= (int) $activeCount ?> active
          <?= $activeCount === 1 ? 'partner' : 'partners' ?><?php
            if ($nonResident > 0): ?>,
            <?= (int) $nonResident ?> of them non-resident<?php
            endif; ?>.
        </p>
      </div>
      <div class="card__body">

        <div class="form-grid">
          <div class="field">
            <label class="label">Running at all</label>
            <label class="check">
              <input type="checkbox" name="partners_enabled" value="1"
                     <?= $settings['partners_enabled'] ? 'checked' : '' ?>>
              <span class="check__text">
                <strong>The programme is open</strong>
                <span>Switching this off closes the partner portal entirely.</span>
              </span>
            </label>
          </div>

          <div class="field">
            <label class="label">New applications</label>
            <label class="check">
              <input type="checkbox" name="partner_signup_enabled" value="1"
                     <?= $settings['partner_signup_enabled'] ? 'checked' : '' ?>>
              <span class="check__text">
                <strong>Accepting applications</strong>
                <span>
                  Off, and the website page still explains the programme
                  but stops inviting people to apply.
                </span>
              </span>
            </label>
          </div>

          <div class="field">
            <label class="label">Beyond Kenya</label>
            <label class="check">
              <input type="checkbox" name="partner_international_enabled" value="1"
                     <?= $settings['partner_international_enabled'] ? 'checked' : '' ?>>
              <span class="check__text">
                <strong>Take partners from other countries</strong>
                <span>
                  Off, and the application form stops asking for a country
                  and treats everybody as local.
                </span>
              </span>
            </label>
          </div>

          <div class="field">
            <label class="label" for="partner_default_rate">
              Standard rate
              <span class="label__hint">where no tier or service says otherwise</span>
            </label>
            <div class="input-group">
              <input class="input" type="number" step="0.5" min="0" max="100"
                     id="partner_default_rate" name="partner_default_rate"
                     value="<?= e(rtrim(rtrim(number_format($settings['partner_default_rate'], 2), '0'), '.')) ?>">
              <span class="input-group__addon">%</span>
            </div>
          </div>

          <div class="field">
            <label class="label" for="partner_payout_currencies">
              Currencies we pay out in
            </label>
            <input class="input" type="text" id="partner_payout_currencies"
                   name="partner_payout_currencies"
                   value="<?= e($settings['partner_payout_currencies']) ?>">
            <p class="field-hint">
              Three-letter codes, comma separated. Each one is a real
              banking arrangement somebody has to have made, so this is a
              short list rather than every currency there is.
              <?= e(Partners::baseCurrency()) ?> is always included.
            </p>
          </div>
        </div>

        <?php // ---- Withholding ----------------------------------------
              // Its own block, boxed, because this is the one setting on
              // the page that changes what partners actually receive. ?>
        <div class="card mt-16" style="border-left:3px solid var(--amber-700)">
          <div class="card__body">
            <label class="check">
              <input type="checkbox" name="partner_withholding_enabled" value="1"
                     <?= $settings['partner_withholding_enabled'] ? 'checked' : '' ?>>
              <span class="check__text">
                <strong>Withhold tax from partner payouts</strong>
                <span>
                  This changes what partners are paid. It comes off the
                  payment, never off what they earned — the ledger and
                  their statement keep the gross, and the deduction is
                  shown to them as its own line with the rate on it.
                  Nothing already paid is affected.
                </span>
              </span>
            </label>

            <div class="form-grid mt-16">
              <div class="field">
                <label class="label" for="partner_wht_resident">
                  Resident rate
                </label>
                <div class="input-group">
                  <input class="input" type="number" step="0.01" min="0" max="100"
                         id="partner_wht_resident" name="partner_wht_resident"
                         value="<?= e(rtrim(rtrim(number_format($settings['partner_wht_resident'], 2), '0'), '.')) ?>">
                  <span class="input-group__addon">%</span>
                </div>
              </div>
              <div class="field">
                <label class="label" for="partner_wht_non_resident">
                  Non-resident rate
                </label>
                <div class="input-group">
                  <input class="input" type="number" step="0.01" min="0" max="100"
                         id="partner_wht_non_resident" name="partner_wht_non_resident"
                         value="<?= e(rtrim(rtrim(number_format($settings['partner_wht_non_resident'], 2), '0'), '.')) ?>">
                  <span class="input-group__addon">%</span>
                </div>
                <p class="field-hint">
                  A double taxation treaty can give one country a rate of
                  its own. Set that on the partner rather than here —
                  their own rate always wins.
                </p>
              </div>
            </div>
          </div>
        </div>

        <?php // ---- What the website says --------------------------- ?>
        <div class="field mt-16">
          <label class="label" for="partner_programme_headline">
            Headline <span class="label__hint">on the public programme page</span>
          </label>
          <input class="input" type="text" id="partner_programme_headline"
                 name="partner_programme_headline" maxlength="200"
                 value="<?= e($settings['partner_programme_headline']) ?>">
        </div>

        <div class="field">
          <label class="label" for="partner_programme_intro">
            Opening paragraph
          </label>
          <textarea class="input" id="partner_programme_intro"
                    name="partner_programme_intro" rows="3"><?= e($settings['partner_programme_intro']) ?></textarea>
          <p class="field-hint">Written for somebody deciding whether to apply.</p>
        </div>

        <div class="field">
          <label class="label" for="partner_terms">Terms, in one sentence</label>
          <textarea class="input" id="partner_terms"
                    name="partner_terms" rows="2"><?= e($settings['partner_terms']) ?></textarea>
          <p class="field-hint">
            Shown on the website and sent to a partner when they are
            approved, so both say the same thing.
          </p>
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit">
            <?= icon('check-circle') ?> Save the programme
          </button>
        </div>
      </div>
    </div>
  </form>
<?php endif; ?>

<?php // ---- Who has outgrown their tier -------------------------------- ?>
<?php if ($due): ?>
  <div class="card mb-16">
    <div class="card__head">
      <h3 class="card__title">
        <?= count($due) ?>
        <?= count($due) === 1 ? 'partner has' : 'partners have' ?>
        earned a higher tier
      </h3>
      <p class="text-xs text-muted mb-0">
        Worked out from what their customers have been invoiced in the
        last twelve months. Promotion is still a decision — nothing here
        moves on its own.
      </p>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Partner</th>
            <th style="width:130px">Where</th>
            <th style="width:110px">On</th>
            <th style="width:110px">Earned</th>
            <th class="num" style="width:150px">Billed, 12 months</th>
            <th class="num" style="width:120px">Rate would go</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($due as $row): ?>
            <?php $p = $row['partner']; ?>
            <tr>
              <td>
                <a class="table__primary"
                   href="<?= url('/partners-admin/' . (int) $p['id'] . '?tab=details') ?>">
                  <?= e($p['company'] ?: $p['name']) ?>
                </a>
                <div class="table__muted"><?= e($p['partner_code'] ?: $p['email']) ?></div>
              </td>
              <td class="text-xs"><?= e(Partners::countryName((string) $p['country'])) ?></td>
              <td class="text-xs">
                <span class="badge badge--grey">
                  <?= e(Partners::tierName((string) $p['tier'])) ?>
                </span>
              </td>
              <td class="text-xs">
                <span class="badge badge--green"><?= e($row['earned']['name']) ?></span>
              </td>
              <td class="num"><?= e(money($row['billed'], false)) ?></td>
              <td class="num text-xs">
                <?= e(rtrim(rtrim(number_format((float) $p['default_rate'], 2), '0'), '.')) ?>%
                &rarr;
                <strong><?= e(rtrim(rtrim(number_format((float) $row['earned']['default_rate'], 2), '0'), '.')) ?>%</strong>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php // ---- The tiers themselves ---------------------------------------- ?>
<form method="post" action="<?= url('/partners-admin/tiers') ?>">
  <?= csrf_field() ?>

  <?php foreach ($tiers as $code => $tier): ?>
    <div class="card mb-16">
      <div class="card__head">
        <h3 class="card__title">
          <?= e($tier['name']) ?>
          <?php if ((int) $tier['partners'] > 0): ?>
            <span class="badge badge--grey"><?= (int) $tier['partners'] ?> here</span>
          <?php endif; ?>
          <?php if (!$tier['is_public']): ?>
            <span class="badge badge--amber">Not on the website</span>
          <?php endif; ?>
        </h3>
        <p class="text-xs text-muted mb-0">
          <code><?= e($code) ?></code> &middot;
          the code cannot be changed, because partners are filed under it
        </p>
      </div>
      <div class="card__body">
        <div class="form-grid">
          <div class="field">
            <label class="label" for="name_<?= e($code) ?>">Name</label>
            <input class="input" type="text" id="name_<?= e($code) ?>"
                   name="tiers[<?= e($code) ?>][name]" maxlength="60"
                   value="<?= e($tier['name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </div>
          <div class="field">
            <label class="label" for="min_<?= e($code) ?>">
              Reached at
              <span class="label__hint">billed in 12 months, <?= e(Partners::baseCurrency()) ?></span>
            </label>
            <input class="input" type="text" inputmode="decimal" id="min_<?= e($code) ?>"
                   name="tiers[<?= e($code) ?>][min_billed_12m]"
                   value="<?= e(number_format((float) $tier['min_billed_12m'], 0, '.', '')) ?>"
                   <?= $canEdit ? '' : 'disabled' ?>>
          </div>
          <div class="field">
            <label class="label" for="rate_<?= e($code) ?>">
              Commission
              <span class="label__hint">where nothing more specific applies</span>
            </label>
            <div class="input-group">
              <input class="input" type="number" step="0.01" min="0" max="100"
                     id="rate_<?= e($code) ?>" name="tiers[<?= e($code) ?>][default_rate]"
                     value="<?= e(rtrim(rtrim(number_format((float) $tier['default_rate'], 2), '0'), '.')) ?>"
                     <?= $canEdit ? '' : 'disabled' ?>>
              <span class="input-group__addon">%</span>
            </div>
          </div>
          <div class="field">
            <label class="label">On the website</label>
            <label class="check">
              <input type="checkbox" id="pub_<?= e($code) ?>"
                     name="tiers[<?= e($code) ?>][is_public]" value="1"
                     <?= $tier['is_public'] ? 'checked' : '' ?>
                     <?= $canEdit ? '' : 'disabled' ?>>
              <span class="check__text">
                <strong>Publish</strong>
                <span>Show this tier on the partner programme page.</span>
              </span>
            </label>
          </div>
        </div>

        <div class="field mt-8">
          <label class="label" for="ben_<?= e($code) ?>">
            Benefits
            <span class="label__hint">one per line — each becomes a bullet on the website</span>
          </label>
          <textarea class="input" id="ben_<?= e($code) ?>" rows="5"
                    name="tiers[<?= e($code) ?>][benefits]"
                    <?= $canEdit ? '' : 'disabled' ?>><?= e((string) $tier['benefits']) ?></textarea>
          <p class="field-hint">
            Written for somebody deciding whether to apply, so say what
            they get rather than what we do.
          </p>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($canEdit): ?>
    <div class="form-actions">
      <button class="btn btn--primary" type="submit">
        <?= icon('check-circle') ?> Save tiers
      </button>
      <a class="btn btn--ghost" href="/partners.php" target="_blank" rel="noopener">
        <?= icon('external-link') ?> See the programme page
      </a>
    </div>
  <?php endif; ?>
</form>
