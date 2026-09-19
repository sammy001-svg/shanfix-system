<?php
/**
 * One SMS account: its balance and where it came from, what it pays,
 * its sender IDs and API key, and everything that has moved in and out.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;
use App\Services\BulkSms\Purchases;

$section  = 'accounts';
$canManage = Auth::can('bulksms.manage');
$isPartner = $account['owner_type'] === 'partner';
$id = (int) $account['id'];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($who['name']) ?></h1>
    <div class="page-head__sub">
      <?= $isPartner ? 'Partner — resells SMS to their own clients' : 'Client' ?>
      &middot; buys from <?= $parentWho ? e($parentWho['name']) : 'Shanfix' ?>
      <?php if ($who['url']): ?>
        &middot; <a href="<?= url($who['url']) ?>">Open their record</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= url('/bulk-sms/messages?account=' . $id) ?>"><?= icon('activity') ?> Their messages</a>
    <?php if ($canManage): ?>
      <button class="btn btn--primary" type="button" data-modal-open="move-units"><?= icon('repeat') ?> Give or take units</button>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($account['status'] !== 'active'): ?>
  <div class="alert alert--error">
    <?= icon('lock') ?>
    <div class="alert__body">This account is suspended. Nothing sends from it — not from the portal, and not through the API.</div>
  </div>
<?php endif; ?>

<?php if ($newKey): ?>
  <div class="alert alert--success" id="api">
    <?= icon('key') ?>
    <div class="alert__body">
      <strong>Copy this key now.</strong> Only a fingerprint of it is kept, so it cannot be shown again.
      <div class="grid-2 mt-8">
        <div class="field mb-0">
          <label class="label">Client ID</label>
          <input class="input input--code" readonly data-select-on-focus value="<?= e($newKey['client_id']) ?>">
        </div>
        <div class="field mb-0">
          <label class="label">API key</label>
          <input class="input input--code" readonly data-select-on-focus value="<?= e($newKey['api_key']) ?>">
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="stat-grid mb-16">
  <div class="stat stat--navy">
    <div class="stat__value"><?= e(Present::units($account['sms_units'])) ?></div>
    <div class="stat__label">SMS units</div>
  </div>
  <div class="stat">
    <div class="stat__value">KES <?= e(number_format($price, 2)) ?></div>
    <div class="stat__label">Pays per unit</div>
    <div class="stat__meta"><?= $account['unit_price'] !== null ? 'Their own price' : 'Supplier\'s standard price' ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__value"><?= number_format($totals['sent']) ?></div>
    <div class="stat__label">Sent, last 30 days</div>
    <div class="stat__meta"><?= $totals['rate'] === null ? 'Nothing sent' : e($totals['rate']) . '% delivered' ?></div>
  </div>
  <?php if ($isPartner): ?>
    <div class="stat">
      <div class="stat__value"><?= count($children) ?></div>
      <div class="stat__label">Their clients on SMS</div>
      <div class="stat__meta">
        <?= e(Present::units(array_sum(array_map(static fn(array $c): float => (float) $c['sms_units'], $children)))) ?> units between them
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="grid-sidebar">
  <div>
    <?php if ($isPartner && $children): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Their clients</div></div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Client</th><th class="num" style="width:130px">Units</th><th style="width:110px">State</th></tr></thead>
            <tbody>
              <?php foreach ($children as $c): ?>
                <tr>
                  <td><a href="<?= url('/bulk-sms/accounts/' . (int) $c['id']) ?>"><?= e($c['owner_name']) ?></a></td>
                  <td class="num"><?= e(Present::units($c['sms_units'])) ?></td>
                  <td><span class="badge <?= $c['status'] === 'active' ? 'badge--green' : 'badge--red' ?>"><?= $c['status'] === 'active' ? 'Active' : 'Suspended' ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Every movement of units</div>
          <div class="card__sub">The newest 25. The balance is always the sum of these.</div>
        </div>
      </div>
      <?php if (!$ledger): ?>
        <div class="card__body text-sm text-muted">Nothing has moved in or out yet.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th style="width:150px">When</th><th>What</th><th class="num" style="width:110px">Units</th><th class="num" style="width:120px">Balance</th></tr></thead>
            <tbody>
              <?php foreach ($ledger as $l): ?>
                <?php $amt = (float) $l['amount']; ?>
                <tr>
                  <td class="text-sm text-muted"><?= e(fdatetime($l['created_at'])) ?></td>
                  <td class="text-sm">
                    <span class="fw-600"><?= e(Present::ledgerKind((string) $l['kind'])) ?></span>
                    <?php if ($l['note']): ?><span class="text-muted">— <?= e($l['note']) ?></span><?php endif; ?>
                    <?php if ($l['staff_name']): ?><div class="text-xs text-muted">by <?= e($l['staff_name']) ?></div><?php endif; ?>
                  </td>
                  <td class="num fw-600" style="color:<?= $amt < 0 ? 'var(--red-600)' : 'var(--green-700)' ?>">
                    <?= $amt > 0 ? '+' : '' ?><?= e(Present::units($amt)) ?>
                  </td>
                  <td class="num text-muted"><?= e(Present::units($l['balance_after'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head">
        <div class="card__title">Recent campaigns</div>
        <div class="card__actions"><a class="btn btn--ghost btn--sm" href="<?= url('/bulk-sms/campaigns?q=' . rawurlencode($who['name'])) ?>">All</a></div>
      </div>
      <?php if (!$campaigns): ?>
        <div class="card__body text-sm text-muted">No campaigns yet.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Campaign</th><th style="width:120px">State</th><th class="num" style="width:90px">Sent</th><th class="num" style="width:90px">Failed</th><th style="width:130px">When</th></tr></thead>
            <tbody>
              <?php foreach ($campaigns as $c): ?>
                <?php [$cls, $word] = Present::campaign((string) $c['status']); ?>
                <tr>
                  <td><a href="<?= url('/bulk-sms/campaigns/' . (int) $c['id']) ?>"><?= e($c['name']) ?></a></td>
                  <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
                  <td class="num"><?= number_format((int) $c['sent_count']) ?></td>
                  <td class="num text-muted"><?= number_format((int) $c['failed_count']) ?></td>
                  <td class="text-sm text-muted"><?= e(fdate($c['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head">
        <div class="card__title">Purchases</div>
        <div class="card__actions"><a class="btn btn--ghost btn--sm" href="<?= url('/bulk-sms/purchases') ?>">All purchases</a></div>
      </div>
      <?php if (!$purchases): ?>
        <div class="card__body text-sm text-muted">Nothing bought yet.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th style="width:120px">Date</th><th class="num">Units</th><th class="num">Amount</th><th>How</th><th style="width:100px">State</th></tr></thead>
            <tbody>
              <?php foreach ($purchases as $p): ?>
                <?php [$cls, $word] = Present::purchase((string) $p['status']); ?>
                <tr>
                  <td class="text-sm text-muted"><?= e(fdate($p['created_at'])) ?></td>
                  <td class="num"><?= e(Present::units($p['units'])) ?></td>
                  <td class="num"><?= e(money($p['amount'])) ?></td>
                  <td class="text-sm"><?= e(Purchases::METHODS[$p['method']] ?? $p['method']) ?>
                    <?php if ($p['transaction_ref']): ?><div class="text-xs text-muted"><?= e($p['transaction_ref']) ?></div><?php endif; ?></td>
                  <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card__head"><div class="card__title">Sender IDs</div></div>
      <div class="card__body">
        <?php if (!$senders): ?>
          <p class="text-sm text-muted mb-8">None yet. Nothing can be sent until one is approved.</p>
        <?php else: ?>
          <ul class="check-list mb-8">
            <?php foreach ($senders as $s): ?>
              <?php [$cls, $word] = Present::sender((string) $s['status']); ?>
              <li class="text-sm"><span class="fw-600 code"><?= e($s['sender_id']) ?></span> <span class="badge <?= e($cls) ?>"><?= e($word) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <a class="btn btn--ghost btn--sm" href="<?= url('/bulk-sms/sender-ids?status=all') ?>">Manage sender IDs</a>
      </div>
    </div>

    <?php if ($canManage): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Pricing and warnings</div></div>
        <div class="card__body">
          <form method="post" action="<?= url('/bulk-sms/accounts/' . $id . '/pricing') ?>">
            <?= csrf_field() ?>
            <div class="field">
              <label class="label" for="unit_price">Their price per unit (KES)</label>
              <input class="input" id="unit_price" name="unit_price" type="number" step="0.0001" min="0"
                     value="<?= e($account['unit_price'] ?? '') ?>" placeholder="Supplier's standard price">
              <div class="field-hint">Leave empty to use the standard price. Plans keep their own prices.</div>
            </div>
            <?php if ($isPartner): ?>
              <div class="field">
                <label class="label" for="resale_unit_price">What they charge their clients (KES)</label>
                <input class="input" id="resale_unit_price" name="resale_unit_price" type="number" step="0.0001" min="0"
                       value="<?= e($account['resale_unit_price'] ?? '') ?>" placeholder="Our standard price">
                <div class="field-hint">The partner can change this themselves from their portal.</div>
              </div>
            <?php endif; ?>
            <div class="field">
              <label class="label" for="low_balance_threshold">Warn them below (units)</label>
              <input class="input" id="low_balance_threshold" name="low_balance_threshold" type="number" step="1" min="0"
                     value="<?= e($account['low_balance_threshold'] ?? '') ?>" placeholder="The system-wide setting">
            </div>
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('save') ?> Save</button>
          </form>
        </div>
      </div>

      <?php if (!$isPartner && $partners): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Who they buy from</div></div>
          <div class="card__body">
            <form method="post" action="<?= url('/bulk-sms/accounts/' . $id . '/supplier') ?>"
                  data-confirm="Change who this client buys units from? Their current balance stays theirs.">
              <?= csrf_field() ?>
              <div class="field">
                <select class="select" name="parent_id">
                  <option value="<?= (int) \App\Services\BulkSms\Accounts::house()['id'] ?>">Shanfix (directly)</option>
                  <?php foreach ($partners as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) $account['parent_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (partner)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn--outline btn--sm" type="submit">Change supplier</button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <div class="card" id="api">
        <div class="card__head"><div class="card__title">Developer API</div></div>
        <div class="card__body text-sm">
          <?php if ($account['api_key_hash']): ?>
            <dl class="dl mb-8">
              <dt>Client ID</dt><dd class="code"><?= e($account['api_client_id']) ?></dd>
              <dt>Key</dt><dd class="code">sk_live_…<?= e($account['api_key_hint']) ?></dd>
              <dt>Issued</dt><dd><?= e(fdate($account['api_key_created_at'])) ?></dd>
              <dt>Last used</dt><dd><?= $account['api_last_used_at'] ? e(time_ago($account['api_last_used_at'])) : 'Never' ?></dd>
            </dl>
          <?php else: ?>
            <p class="text-muted">No API key. They can issue one themselves from their portal, or you can here.</p>
          <?php endif; ?>
          <div class="btn-group">
            <form method="post" action="<?= url('/bulk-sms/accounts/' . $id . '/api-key') ?>"
                  <?= $account['api_key_hash'] ? 'data-confirm="Issue a new key? The current one stops working immediately."' : '' ?>>
              <?= csrf_field() ?>
              <button class="btn btn--outline btn--sm" type="submit"><?= icon('key') ?> <?= $account['api_key_hash'] ? 'Replace key' : 'Issue a key' ?></button>
            </form>
            <?php if ($account['api_key_hash']): ?>
              <form method="post" action="<?= url('/bulk-sms/accounts/' . $id . '/api-key/revoke') ?>"
                    data-confirm="Revoke this key? Anything using it will be refused.">
                <?= csrf_field() ?>
                <button class="btn btn--danger-soft btn--sm" type="submit">Revoke</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__body">
          <form method="post" action="<?= url('/bulk-sms/accounts/' . $id . '/status') ?>"
                data-confirm="<?= $account['status'] === 'active' ? 'Suspend this account? Nothing will send from it until it is reactivated.' : 'Reactivate this account?' ?>">
            <?= csrf_field() ?>
            <?php if ($account['status'] === 'active'): ?>
              <button class="btn btn--danger-soft btn--sm btn--block" type="submit"><?= icon('lock') ?> Suspend sending</button>
            <?php else: ?>
              <button class="btn btn--primary btn--sm btn--block" type="submit"><?= icon('check') ?> Reactivate</button>
            <?php endif; ?>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($canManage): ?>
  <div class="modal-backdrop" id="move-units">
    <div class="modal">
      <form method="post" action="<?= url('/bulk-sms/accounts/' . $id . '/units') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Give or take back units</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <p class="text-sm text-muted">
            Units given come out of the house balance, and units taken back
            return to it. To record a sale that was paid for, use
            <a href="<?= url('/bulk-sms/purchases') ?>">Purchases</a> instead,
            so it shows in the sales figures.
          </p>
          <div class="field">
            <label class="check-row"><input type="radio" name="action" value="give" checked> <span>Give units</span></label>
            <label class="check-row"><input type="radio" name="action" value="take"> <span>Take units back</span></label>
          </div>
          <div class="field">
            <label class="label" for="mu-units">Units</label>
            <input class="input" id="mu-units" name="units" type="number" step="0.01" min="0.01" required>
          </div>
          <div class="field">
            <label class="label" for="mu-note">Why</label>
            <input class="input" id="mu-note" name="note" maxlength="255" placeholder="e.g. goodwill after a failed campaign">
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Move the units</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
