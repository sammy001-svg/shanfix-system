<?php
/**
 * The gateway: Onfon's keys, how fast we send, and where delivery
 * reports come back to. Admin only — these keys send as the company.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$section = 'settings';
$dlrFull = $dlrUrl . ($values['bulk_sms_dlr_token'] !== '' ? '?token=' . $values['bulk_sms_dlr_token'] : '');
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>SMS gateway</h1>
    <div class="page-head__sub">Onfon Media — the connection every customer's messages go out through</div>
  </div>
  <div class="page-head__actions">
    <form method="post" action="<?= url('/bulk-sms/settings/check') ?>">
      <?= csrf_field() ?>
      <button class="btn btn--outline" type="submit"><?= icon('zap') ?> Test the connection</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($check): ?>
  <div class="alert <?= $check['ok'] ? 'alert--success' : 'alert--error' ?>">
    <?= icon($check['ok'] ? 'check-circle' : 'x-circle') ?>
    <div class="alert__body"><?= e($check['message']) ?></div>
  </div>
<?php endif; ?>

<form method="post" action="<?= url('/bulk-sms/settings') ?>">
  <?= csrf_field() ?>

  <div class="grid-2">
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Onfon keys</div>
          <div class="card__sub">From the Onfon portal, under API settings</div>
        </div>
      </div>
      <div class="card__body">
        <div class="field">
          <label class="label" for="onfon_client_id">Client ID</label>
          <input class="input input--code" id="onfon_client_id" name="onfon_client_id" value="<?= e($values['onfon_client_id']) ?>" autocomplete="off">
        </div>
        <div class="field">
          <label class="label" for="onfon_api_key">API key</label>
          <input class="input input--code" id="onfon_api_key" name="onfon_api_key" type="password" autocomplete="new-password"
                 placeholder="<?= $hasApiKey ? 'Saved — type a new one to replace it' : 'Not set' ?>">
        </div>
        <div class="field">
          <label class="label" for="onfon_access_key">Access key</label>
          <input class="input input--code" id="onfon_access_key" name="onfon_access_key" type="password" autocomplete="new-password"
                 placeholder="<?= $hasAccessKey ? 'Saved — type a new one to replace it' : 'Not set' ?>">
          <div class="field-hint">Both keys are stored encrypted and never shown again after saving.</div>
        </div>
        <label class="check-row">
          <input type="checkbox" name="bulk_sms_enabled" value="1" <?= $values['bulk_sms_enabled'] ? 'checked' : '' ?>>
          <span>Offer SMS in the client and partner portals</span>
        </label>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Delivery reports</div>
          <div class="card__sub">Where Onfon tells us what happened to each message</div>
        </div>
      </div>
      <div class="card__body">
        <div class="field">
          <label class="label">Paste this into the Onfon portal as the delivery report URL</label>
          <input class="input input--code" readonly data-select-on-focus value="<?= e($dlrFull) ?>">
          <div class="field-hint">
            The old address, <span class="code">/webhooks/sms-dlr.php</span>, still works — nothing
            needs changing at Onfon if it already points there.
          </div>
        </div>
        <div class="field mb-0">
          <label class="label" for="bulk_sms_dlr_token">Secret for that URL</label>
          <input class="input input--code" id="bulk_sms_dlr_token" name="bulk_sms_dlr_token" value="<?= e($values['bulk_sms_dlr_token']) ?>" maxlength="64">
          <div class="field-hint">Optional. When set, reports without it are refused — update the URL at Onfon to match.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><div class="card__title">Selling</div></div>
      <div class="card__body">
        <div class="field">
          <label class="label" for="bulk_sms_unit_price">Price per unit for a custom amount (KES)</label>
          <input class="input" id="bulk_sms_unit_price" name="bulk_sms_unit_price" type="number" step="0.0001" min="0.01" value="<?= e($values['bulk_sms_unit_price']) ?>">
          <div class="field-hint">What a direct client or partner pays when they buy a number of units rather than a plan.</div>
        </div>
        <div class="field mb-0">
          <label class="label" for="bulk_sms_low_balance">Warn customers below (units)</label>
          <input class="input" id="bulk_sms_low_balance" name="bulk_sms_low_balance" type="number" step="1" min="0" value="<?= e($values['bulk_sms_low_balance']) ?>">
          <div class="field-hint">One e-mail a day while they are under it. 0 turns warnings off; an account can have its own.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><div class="card__title">Sending</div></div>
      <div class="card__body">
        <div class="grid-2">
          <div class="field">
            <label class="label" for="onfon_batch_delay_ms">Pause between batches (ms)</label>
            <input class="input" id="onfon_batch_delay_ms" name="onfon_batch_delay_ms" type="number" min="0" max="5000" value="<?= e($values['onfon_batch_delay_ms']) ?>">
          </div>
          <div class="field">
            <label class="label" for="bulk_sms_max_workers">Campaigns at once</label>
            <input class="input" id="bulk_sms_max_workers" name="bulk_sms_max_workers" type="number" min="1" max="50" value="<?= e($values['bulk_sms_max_workers']) ?>">
          </div>
        </div>
        <div class="field mb-0">
          <label class="label" for="onfon_pinned_ips">Onfon addresses</label>
          <input class="input input--code" id="onfon_pinned_ips" name="onfon_pinned_ips" value="<?= e($values['onfon_pinned_ips']) ?>">
          <div class="field-hint">
            Used when this server cannot look Onfon up by name. Leave empty
            to rely on the name alone. Change only if Onfon moves.
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__body">
      <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save gateway settings</button>
    </div>
  </div>
</form>
