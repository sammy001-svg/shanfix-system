<?php
/**
 * What one campaign did, and who it went to.
 *
 * @var array $campaign
 * @var array $recipients
 */
require_once APP_PATH . '/Views/partials/icons.php';

$badge = match ($campaign['status']) {
    'sent'    => 'badge--green',
    'partial' => 'badge--amber',
    'failed'  => 'badge--red',
    default   => 'badge--grey',
};

$skipped = array_values(array_filter($recipients, static fn($r) => $r['status'] === 'skipped'));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($campaign['title']) ?></h1>
    <div class="page-head__sub">
      <?= e($campaign['audience'] ?? '') ?>
      · sent <?= e(fdate($campaign['created_at'], 'd M Y H:i')) ?>
      <?php if ($campaign['sent_by']): ?> by <?= e($campaign['sent_by']) ?><?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <span class="badge <?= $badge ?>"><?= e(label_of($campaign['status'])) ?></span>
    <a class="btn btn--outline" href="<?= url('/sms-campaigns') ?>">All campaigns</a>
  </div>
</div>

<?php if ($campaign['status'] === 'failed' && $campaign['error']): ?>
  <div class="alert alert--error">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>The gateway refused this campaign.</strong>
      <?= e($campaign['error']) ?>
    </div>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--green">
    <div class="stat__label">Delivered to gateway</div>
    <div class="stat__value"><?= number_format((int) $campaign['sent']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Failed</div>
    <div class="stat__value"><?= number_format((int) $campaign['failed']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Skipped</div>
    <div class="stat__value"><?= number_format((int) $campaign['invalid']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Units charged</div>
    <div class="stat__value">
      <?= $campaign['cost'] !== null ? number_format((float) $campaign['cost'], 2) : '—' ?>
    </div>
  </div>
</div>

<div class="grid-2">

  <div class="card">
    <div class="card__head">
      <?= icon('message') ?>
      <div>
        <div class="card__title">What was sent</div>
        <div class="card__sub">
          <?= mb_strlen($campaign['message']) ?> characters
          · <?= (int) $campaign['parts'] ?> credit(s) per recipient
        </div>
      </div>
    </div>
    <div class="card__body">
      <div style="white-space:pre-wrap;padding:14px 16px;background:var(--surface-2);
                  border:1px solid var(--border);border-radius:var(--r);line-height:1.6">
        <?= e($campaign['message']) ?>
      </div>

      <?php if ($campaign['balance_after'] !== null): ?>
        <p class="field-hint mt-12 mb-0">
          Gateway balance afterwards: <strong><?= e($campaign['balance_after']) ?></strong> units.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <?= icon('users') ?>
      <div>
        <div class="card__title">Recipients</div>
        <div class="card__sub"><?= number_format(count($recipients)) ?> on the list</div>
      </div>
    </div>

    <?php if ($skipped): ?>
      <div class="card__body" style="padding-bottom:0">
        <div class="alert alert--warning">
          <?= icon('alert-triangle') ?>
          <div class="alert__body text-sm">
            <strong><?= count($skipped) ?> number(s) were not accepted</strong> by the
            gateway and heard nothing. Worth correcting on the client record.
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-wrap" style="max-height:420px;overflow-y:auto">
      <table class="table">
        <thead>
          <tr><th>Client</th><th style="width:150px">Number</th><th style="width:100px">Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recipients as $r): ?>
            <tr>
              <td>
                <?php if ($r['client_id']): ?>
                  <a href="<?= url('/clients/' . $r['client_id']) ?>"><?= e($r['name']) ?></a>
                <?php else: ?>
                  <?= e($r['name'] ?: '—') ?>
                <?php endif; ?>
              </td>
              <td class="text-muted">+<?= e($r['phone']) ?></td>
              <td>
                <?php if ($r['status'] === 'skipped'): ?>
                  <span class="badge badge--red" title="<?= e($r['reason'] ?? '') ?>">Skipped</span>
                <?php else: ?>
                  <span class="badge badge--grey">Submitted</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<p class="field-hint mt-12">
  “Delivered to gateway” means Shanfix Bulk SMS accepted the message for
  sending. It is not proof the handset received it — the gateway reports
  totals per batch, not a status per number.
</p>
