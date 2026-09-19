<?php
/**
 * Every purchase of units — ours to direct clients and partners, and
 * partners' to their own clients — with the ones waiting on us first.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;
use App\Services\BulkSms\Purchases;

$section   = 'purchases';
$canManage = Auth::can('bulksms.manage');
$houseId   = (int) $house['id'];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>SMS purchases</h1>
    <div class="page-head__sub">Units bought — by M-Pesa, by bank, at the counter, or given free</div>
  </div>
  <?php if ($canManage): ?>
    <div class="page-head__actions">
      <button class="btn btn--primary" type="button" data-modal-open="record-sale"><?= icon('plus') ?> Record a sale</button>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="stat-grid mb-16">
  <div class="stat stat--green">
    <div class="stat__value"><?= e(money($revenue)) ?></div>
    <div class="stat__label">Our SMS sales this month</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__value"><?= e(Present::units($house['sms_units'])) ?></div>
    <div class="stat__label">Units left to sell</div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <?php foreach (['' => 'All', 'pending' => 'Waiting', 'completed' => 'Paid', 'failed' => 'Not paid'] as $key => $label): ?>
      <a class="tab <?= $status === $key ? 'is-active' : '' ?>" href="<?= url('/bulk-sms/purchases' . ($key !== '' ? '?status=' . $key : '')) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('credit-card') ?></div>
      <div class="empty__title">No purchases here</div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:130px">Date</th>
            <th>Buyer</th>
            <th style="width:150px">Sold by</th>
            <th class="num" style="width:110px">Units</th>
            <th class="num" style="width:130px">Amount</th>
            <th>How</th>
            <th style="width:110px">State</th>
            <?php if ($canManage): ?><th style="width:180px"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <?php [$cls, $word] = Present::purchase((string) $p['status']);
                  $ours = (int) $p['seller_account_id'] === $houseId; ?>
            <tr>
              <td class="text-sm text-muted"><?= e(fdatetime($p['created_at'])) ?></td>
              <td>
                <a class="fw-600" href="<?= url('/bulk-sms/accounts/' . (int) $p['account_id']) ?>"><?= e($p['buyer_name']) ?></a>
                <div class="text-xs text-muted"><?= $p['buyer_type'] === 'partner' ? 'Partner' : 'Client' ?><?= $p['channel'] !== 'sms' ? ' · ' . e(ucfirst($p['channel'])) . ' wallet' : '' ?></div>
              </td>
              <td class="text-sm"><?= $ours ? 'Shanfix' : e($p['seller_name'] ?? 'Partner') ?></td>
              <td class="num"><?= e(Present::units($p['units'])) ?></td>
              <td class="num fw-600"><?= e(money($p['amount'])) ?></td>
              <td class="text-sm">
                <?= e(Purchases::METHODS[$p['method']] ?? $p['method']) ?>
                <?php if ($p['transaction_ref']): ?><div class="text-xs text-muted code"><?= e($p['transaction_ref']) ?></div><?php endif; ?>
                <?php if ($p['failure_reason']): ?><div class="text-xs text-muted"><?= e($p['failure_reason']) ?></div><?php endif; ?>
              </td>
              <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
              <?php if ($canManage): ?>
                <td>
                  <?php if ($p['status'] === 'pending' && $ours): ?>
                    <div class="btn-group">
                      <form method="post" action="<?= url('/bulk-sms/purchases/' . (int) $p['id'] . '/complete') ?>"
                            data-confirm="Confirm the money has arrived and hand over <?= e(Present::units($p['units'])) ?> units?">
                        <?= csrf_field() ?>
                        <button class="btn btn--primary btn--sm" type="submit">Paid</button>
                      </form>
                      <form method="post" action="<?= url('/bulk-sms/purchases/' . (int) $p['id'] . '/fail') ?>"
                            data-confirm="Mark this as not paid? No units will move.">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--sm" type="submit">Not paid</button>
                      </form>
                    </div>
                  <?php elseif ($p['status'] === 'pending'): ?>
                    <span class="text-xs text-muted">The partner approves this</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php include APP_PATH . '/Views/partials/pagination.php'; ?>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
  <div class="modal-backdrop" id="record-sale">
    <div class="modal">
      <form method="post" action="<?= url('/bulk-sms/purchases') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Record a sale</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <p class="text-sm text-muted">
            For money that reached us some other way than the portal. The
            units go across as soon as you save. Only accounts that buy from
            us directly are listed — a partner's client buys from the partner.
          </p>
          <div class="field">
            <label class="label" for="rs-account">Account</label>
            <select class="select" id="rs-account" name="account_id" required>
              <option value="">Choose…</option>
              <?php foreach ($accountsList as $a): ?>
                <option value="<?= (int) $a['id'] ?>"><?= e($a['owner_name']) ?><?= $a['owner_type'] === 'partner' ? ' (partner)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="rs-plan">Plan</label>
            <select class="select" id="rs-plan" name="plan_id">
              <option value="">A custom number of units</option>
              <?php foreach ($plans as $pl): ?>
                <option value="<?= (int) $pl['id'] ?>"><?= e($pl['name']) ?> — <?= e(Present::units($pl['units'])) ?> units for <?= e(money($pl['price'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grid-2">
            <div class="field">
              <label class="label" for="rs-units">Units</label>
              <input class="input" id="rs-units" name="units" type="number" step="1" min="1" placeholder="If no plan">
            </div>
            <div class="field">
              <label class="label" for="rs-amount">Amount paid (KES)</label>
              <input class="input" id="rs-amount" name="amount" type="number" step="0.01" min="0" placeholder="Worked out if empty">
            </div>
          </div>
          <div class="grid-2">
            <div class="field">
              <label class="label" for="rs-method">How it was paid</label>
              <select class="select" id="rs-method" name="method">
                <option value="bank">Bank transfer</option>
                <option value="manual_mpesa">M-Pesa to our till</option>
                <option value="cash">Cash</option>
                <option value="complimentary">Complimentary — no money</option>
              </select>
            </div>
            <div class="field">
              <label class="label" for="rs-ref">Reference</label>
              <input class="input" id="rs-ref" name="transaction_ref" maxlength="100" placeholder="M-Pesa code or bank ref">
            </div>
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Record and hand over</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
