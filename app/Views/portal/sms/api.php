<?php
/**
 * The developer page: the customer's key, and how to use it.
 *
 * The examples are the real addresses with their own client id already
 * filled in, so they can be copied straight into a terminal.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$smsTab = 'api';
$clientId = $account['api_client_id'] ?: 'YOUR_CLIENT_ID';
$sender   = $senders[0]['sender_id'] ?? 'YOUR_SENDER_ID';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <?php if ($newKey): ?>
    <div class="alert alert--success">
      <?= icon('key') ?>
      <div class="alert__body">
        <strong>Copy your key now.</strong> We keep only a fingerprint of it,
        so it cannot be shown again — if you lose it, issue another.
        <div class="field mt-8 mb-0">
          <label class="label">API key</label>
          <input class="input input--code" readonly data-select-on-focus value="<?= e($newKey['api_key']) ?>">
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Your credentials</div></div>

    <?php if ($account['api_key_hash']): ?>
      <div class="field">
        <label class="label">Client ID</label>
        <input class="input input--code" readonly data-select-on-focus value="<?= e($account['api_client_id']) ?>">
      </div>
      <div class="field">
        <label class="label">API key</label>
        <input class="input input--code" readonly value="sk_live_…<?= e($account['api_key_hint']) ?>">
        <span class="field-hint">
          Issued <?= e(fdate($account['api_key_created_at'])) ?>.
          <?= $account['api_last_used_at'] ? 'Last used ' . e(time_ago($account['api_last_used_at'])) . '.' : 'Not used yet.' ?>
        </span>
      </div>
    <?php else: ?>
      <p class="text-sm text-muted">
        No key yet. Issue one to send messages from your own website or
        software — order confirmations, reminders, one-time codes.
      </p>
    <?php endif; ?>

    <div class="row-form">
      <form method="post" action="<?= url($base . '/api/key') ?>"
            <?= $account['api_key_hash'] ? 'data-confirm="Issue a new key? Anything using the current one stops working straight away."' : '' ?>>
        <?= csrf_field() ?>
        <button class="btn btn--primary" type="submit">
          <?= icon('key') ?> <?= $account['api_key_hash'] ? 'Issue a new key' : 'Issue a key' ?>
        </button>
      </form>
      <?php if ($account['api_key_hash']): ?>
        <form method="post" action="<?= url($base . '/api/key/revoke') ?>"
              data-confirm="Revoke this key? Anything using it will be refused.">
          <?= csrf_field() ?>
          <button class="btn btn--ghost" type="submit">Revoke</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Sending one message</div></div>
    <p class="text-sm text-muted">
      Send your client id and key as headers. Everything answers JSON, and
      anything other than <span class="code">"success": true</span> carries
      an <span class="code">error</span> saying why.
    </p>
    <pre class="code" style="white-space:pre-wrap;overflow-x:auto">curl -X POST <?= e($base) ?>/sendsms.php \
  -H "X-Client-Id: <?= e($clientId) ?>" \
  -H "X-Api-Key: YOUR_API_KEY" \
  -d "to=0712345678" \
  -d "sender_id=<?= e($sender) ?>" \
  -d "message=Your order is ready for collection."</pre>
    <pre class="code" style="white-space:pre-wrap">{
  "success": true,
  "message_id": 12345,
  "units_charged": 1,
  "remaining_units": "<?= e(number_format((float) $account['sms_units'], 2, '.', '')) ?>"
}</pre>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Sending to many at once</div></div>
    <p class="text-sm text-muted">
      Up to 1,000 numbers a call, as a comma-separated list or a JSON array.
      Ten of these a minute; single sends, sixty.
    </p>
    <pre class="code" style="white-space:pre-wrap;overflow-x:auto">curl -X POST <?= e($base) ?>/bulksend.php \
  -H "X-Client-Id: <?= e($clientId) ?>" \
  -H "X-Api-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"to":["0712345678","0722000111"],"sender_id":"<?= e($sender) ?>","message":"Hello"}'</pre>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">The rest</div></div>
    <div class="table-wrap">
      <table class="table table--compact">
        <thead><tr><th style="width:190px">Address</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td class="code">GET /balance.php</td><td class="text-sm">How many units you have left.</td></tr>
          <tr><td class="code">GET /status.php</td><td class="text-sm">What happened to one message — <span class="code">message_id</span>, or <span class="code">message_ids</span> for up to 200 at once.</td></tr>
          <tr><td class="code">GET /messages.php</td><td class="text-sm">Your message log, paged: <span class="code">page</span>, <span class="code">per_page</span>, <span class="code">from</span>, <span class="code">to</span>, <span class="code">status</span>.</td></tr>
        </tbody>
      </table>
    </div>
    <p class="text-sm text-muted mt-8">
      Every address also answers without the <span class="code">.php</span>,
      and the key may be sent as <span class="code">client_id</span> and
      <span class="code">api_key</span> fields instead of headers if that is
      easier for your library.
    </p>
  </div>

  <div class="portal-card portal-card--quiet">
    <div class="text-sm">
      <div class="fw-600 mb-8">Keeping your key safe</div>
      <p class="text-muted mb-0">
        Your key can spend your units, so keep it on your server and never
        in a web page or a phone app, where anyone can read it. If it does
        get out, issue a new one here — the old one stops working the moment
        you do.
      </p>
    </div>
  </div>
</div>
