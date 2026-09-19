<?php
/**
 * Every SMS account: clients and partners, what they hold and who
 * supplies them.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;

$section = 'accounts';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>SMS accounts</h1>
    <div class="page-head__sub">Everyone who can send through the platform, and what they hold</div>
  </div>
  <?php if (Auth::can('bulksms.manage')): ?>
    <div class="page-head__actions">
      <button class="btn btn--primary" type="button" data-modal-open="open-account">
        <?= icon('plus') ?> Open an account
      </button>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= url('/bulk-sms/accounts') ?>" class="row-form">
      <div class="field mb-0" style="flex:1;min-width:220px">
        <label class="label" for="q">Search</label>
        <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or API client id">
      </div>
      <div class="field mb-0" style="max-width:200px">
        <label class="label" for="type">Kind</label>
        <select class="select" id="type" name="type">
          <option value="">Clients and partners</option>
          <option value="client"  <?= $type === 'client' ? 'selected' : '' ?>>Clients</option>
          <option value="partner" <?= $type === 'partner' ? 'selected' : '' ?>>Partners (resellers)</option>
        </select>
      </div>
      <button class="btn btn--outline" type="submit"><?= icon('search') ?> Find</button>
    </form>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('users') ?></div>
      <div class="empty__title">No SMS accounts <?= $q !== '' || $type !== '' ? 'match that' : 'yet' ?></div>
      <div class="empty__text">
        An account opens itself the first time a client or partner uses SMS
        from their portal. Open one here to give units or add a sender ID first.
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Account</th>
            <th style="width:110px">Kind</th>
            <th style="width:170px">Buys from</th>
            <th style="width:130px" class="num">Units</th>
            <th style="width:150px">Last sent</th>
            <th style="width:110px">State</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $a): ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/bulk-sms/accounts/' . (int) $a['id']) ?>"><?= e($a['owner_name'] ?? '—') ?></a>
                <?php if ($a['api_client_id']): ?>
                  <div class="text-xs text-muted"><?= icon('code') ?> <?= e($a['api_client_id']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $a['owner_type'] === 'partner' ? 'badge--navy' : 'badge--grey' ?>">
                  <?= $a['owner_type'] === 'partner' ? 'Partner' : 'Client' ?>
                </span>
              </td>
              <td class="text-sm text-muted">
                <?= $a['parent_type'] === 'partner' ? e($a['parent_name']) : 'Shanfix' ?>
              </td>
              <td class="num fw-700"><?= e(Present::units($a['sms_units'])) ?></td>
              <td class="text-sm text-muted"><?= $a['last_sent'] ? e(time_ago($a['last_sent'])) : 'Never' ?></td>
              <td>
                <?php if ($a['status'] === 'active'): ?>
                  <span class="badge badge--green">Active</span>
                <?php else: ?>
                  <span class="badge badge--red">Suspended</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php include APP_PATH . '/Views/partials/pagination.php'; ?>
  <?php endif; ?>
</div>

<?php if (Auth::can('bulksms.manage')): ?>
  <?php
    $clients  = \App\Core\Database::all(
        "SELECT c.id, c.name FROM clients c
          WHERE c.status = 'active'
            AND NOT EXISTS (SELECT 1 FROM bulk_accounts a WHERE a.owner_type = 'client' AND a.owner_id = c.id)
          ORDER BY c.name LIMIT 2000");
    $partners = \App\Core\Database::all(
        "SELECT p.id, COALESCE(NULLIF(p.company,''), p.name) AS name FROM partners p
          WHERE p.status = 'active'
            AND NOT EXISTS (SELECT 1 FROM bulk_accounts a WHERE a.owner_type = 'partner' AND a.owner_id = p.id)
          ORDER BY name");
  ?>
  <div class="modal-backdrop" id="open-account">
    <div class="modal">
      <div class="modal__head">
        <div class="card__title">Open an SMS account</div>
        <button class="modal__close" type="button" data-modal-close>&times;</button>
      </div>
      <div class="modal__body">
        <p class="text-sm text-muted">
          A client introduced by a partner will buy their units from that
          partner. Everybody else buys from us.
        </p>

        <form method="post" action="<?= url('/bulk-sms/accounts') ?>" class="mb-16">
          <?= csrf_field() ?>
          <input type="hidden" name="owner_type" value="client">
          <div class="field">
            <label class="label" for="oa-client">A client</label>
            <select class="select" id="oa-client" name="owner_id" required>
              <option value="">Choose…</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn--primary btn--sm" type="submit">Open for this client</button>
        </form>

        <form method="post" action="<?= url('/bulk-sms/accounts') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="owner_type" value="partner">
          <div class="field">
            <label class="label" for="oa-partner">Or a partner</label>
            <select class="select" id="oa-partner" name="owner_id" required>
              <option value="">Choose…</option>
              <?php foreach ($partners as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn--outline btn--sm" type="submit">Open for this partner</button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>
