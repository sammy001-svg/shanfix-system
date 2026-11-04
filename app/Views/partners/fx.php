<?php
/**
 * The rates a partner payout abroad is converted at.
 *
 * Entered by hand on purpose. There is no outbound API call to rely on
 * from a cPanel box, and the rate that matters is the one the bank
 * actually gave us on the day — which no public feed knows.
 *
 * A rate is dated, and a payout uses the newest rate on or before the
 * day the run was built. So entering a rate never changes a payment
 * that has already gone: it only decides the next one.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\Partners;

$canSet = \App\Core\Auth::can('partners.pay');
$num    = static fn($v): string => rtrim(rtrim(number_format((float) $v, 6), '0'), '.');
?>

<p class="text-sm mb-12">
  <a href="<?= url('/partners-admin') ?>">&larr; All partners</a>
</p>

<div class="page-head">
  <div class="page-head__text">
    <h1>Exchange rates</h1>
    <div class="page-head__sub">
      What one unit of each currency costs in <?= e($base) ?>, for paying
      partners outside the country
    </div>
  </div>
</div>

<?php if ($missing): ?>
  <div class="alert alert--warning mb-16">
    <div>
      <strong>Nothing on file for <?= e(implode(', ', $missing)) ?>.</strong>
      There are active partners set to be paid in
      <?= count($missing) === 1 ? 'it' : 'them' ?>, and a payout run will
      hold their lines rather than invent a rate.
    </div>
  </div>
<?php endif; ?>

<div class="grid-2 mb-16">

  <?php // ---- What is in force now --------------------------------------
        // The newest rate per currency, which is what the next run will
        // use. Everything else is history and sits below. ?>
  <div class="card">
    <div class="card__head">
      <h3 class="card__title">In force</h3>
      <p class="text-xs text-muted mb-0">The rate the next payout run will use</p>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Currency</th>
            <th class="num" style="width:150px">1 unit = <?= e($base) ?></th>
            <th style="width:110px">From</th>
            <th style="width:150px">Source</th>
          </tr>
        </thead>
        <tbody>
          <?php $any = false; ?>
          <?php foreach ($currencies as $code => $label): ?>
            <?php $row = $latest[$code] ?? null; $any = true; ?>
            <tr>
              <td class="fw-700"><?= e($code) ?></td>
              <?php if ($row): ?>
                <td class="num"><?= e($num($row['rate'])) ?></td>
                <td class="text-xs"><?= e(fdate($row['as_of'])) ?></td>
                <td class="text-xs text-muted">
                  <?= e($row['source'] ?: '—') ?>
                  <?php if ($row['set_by_name']): ?>
                    <div class="table__muted"><?= e($row['set_by_name']) ?></div>
                  <?php endif; ?>
                </td>
              <?php else: ?>
                <td class="num text-muted">—</td>
                <td colspan="2" class="text-xs">
                  <span class="badge badge--amber">Not set</span>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$any): ?>
            <tr>
              <td colspan="4" class="text-sm text-muted">
                No payout currencies besides <?= e($base) ?> are switched on.
                Add them in Settings under <code>partner_payout_currencies</code>.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php // ---- Entering one ----------------------------------------------
        // Dated, so entering today's rate is a new row rather than an
        // edit of yesterday's. Re-entering the same day corrects it. ?>
  <div class="card">
    <div class="card__head">
      <h3 class="card__title">Enter a rate</h3>
      <p class="text-xs text-muted mb-0">
        Dated, so a correction to today does not disturb last month
      </p>
    </div>
    <div class="card__body">
      <?php if (!$canSet): ?>
        <p class="text-sm text-muted mb-0">
          Only finance can set a rate, because it changes what leaves the
          account. You can see what is in force above.
        </p>
      <?php else: ?>
        <form method="post" action="<?= url('/partners-admin/fx') ?>">
          <?= csrf_field() ?>
          <div class="form-grid">
            <div class="field">
              <label class="label" for="currency">Currency</label>
              <select class="select" id="currency" name="currency" required>
                <?php foreach ($currencies as $code => $label): ?>
                  <option value="<?= e($code) ?>"><?= e($code) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="label" for="rate">1 unit in <?= e($base) ?></label>
              <input class="input" type="text" inputmode="decimal" id="rate" name="rate"
                     placeholder="e.g. 129.50" required>
              <p class="field-hint">
                What we pay for one unit. For USD this is around 129, not 0.0077.
              </p>
            </div>
            <div class="field">
              <label class="label" for="as_of">Applies from</label>
              <input class="input" type="date" id="as_of" name="as_of"
                     value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="field">
              <label class="label" for="source">Where it came from</label>
              <input class="input" type="text" id="source" name="source" maxlength="90"
                     placeholder="e.g. KCB telegraphic transfer rate">
            </div>
          </div>
          <div class="form-actions">
            <button class="btn btn--primary" type="submit">
              <?= icon('check-circle') ?> Save rate
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php // ---- Who is waiting on this ------------------------------------- ?>
<?php if ($waiting): ?>
  <div class="card mb-16">
    <div class="card__head">
      <h3 class="card__title">Partners paid in each currency</h3>
    </div>
    <div class="card__body">
      <div class="chip-row">
        <?php foreach ($waiting as $row): ?>
          <span class="chip">
            <?= e($row['currency']) ?>
            <span class="chip__count"><?= (int) $row['partners'] ?></span>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php // ---- History -----------------------------------------------------
      // Kept and shown because a payout that went out months ago was
      // converted at one of these, and somebody will eventually ask
      // which. ?>
<?php if ($history): ?>
  <div class="card">
    <div class="card__head">
      <h3 class="card__title">Rates we have used</h3>
      <p class="text-xs text-muted mb-0">
        A payout keeps the rate it was built at, so this is the record
        behind any payment already made
      </p>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px">From</th>
            <th style="width:100px">Currency</th>
            <th class="num" style="width:150px">1 unit = <?= e($base) ?></th>
            <th>Source</th>
            <th style="width:150px">Entered by</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $row): ?>
            <tr>
              <td class="text-xs"><?= e(fdate($row['as_of'])) ?></td>
              <td class="fw-700"><?= e($row['currency']) ?></td>
              <td class="num"><?= e($num($row['rate'])) ?></td>
              <td class="text-xs text-muted"><?= e($row['source'] ?: '—') ?></td>
              <td class="text-xs text-muted"><?= e($row['set_by_name'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
