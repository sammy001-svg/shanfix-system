<?php
/**
 * A partner's SMS clients: what each holds, what each pays, and giving
 * them more.
 *
 * What a client sends is deliberately absent. A reseller supplies the
 * credit; the correspondence is the client's own.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

$smsTab = 'clients';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <?php if ($waiting): ?>
    <div class="alert alert--warning">
      <?= icon('credit-card') ?>
      <div class="alert__body">
        <?= count($waiting) ?> of your clients
        <?= count($waiting) === 1 ? 'is' : 'are' ?> waiting for you to confirm a payment.
        <a href="<?= url($base . '/sales') ?>"><strong>Look at them</strong></a>.
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Clients you supply</div>
      <?php if ($without): ?>
        <button class="btn btn--outline btn--sm" type="button" data-modal-open="open-sms">
          <?= icon('plus') ?> Start SMS for a client
        </button>
      <?php endif; ?>
    </div>

    <?php if (!$clients): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('users') ?></div>
        <div class="portal-empty__title">No SMS clients yet</div>
        <p class="text-sm text-muted">
          Your clients buy units from you at your price. You buy from us at
          <strong>KES <?= e(number_format($price, 2)) ?></strong> a unit;
          what you charge is up to you.
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th>Client</th>
              <th class="num" style="width:110px">Units</th>
              <th class="num" style="width:110px">Their price</th>
              <th class="num" style="width:100px">Sent (30d)</th>
              <th style="width:110px">State</th>
              <th style="width:200px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($clients as $c): ?>
              <tr>
                <td>
                  <span class="fw-600"><?= e($c['client_name']) ?></span>
                  <div class="text-xs text-muted">
                    <?= e($c['phone'] ?: $c['email'] ?: '') ?>
                    <?php if ($c['last_sent']): ?> · last sent <?= e(time_ago($c['last_sent'])) ?><?php endif; ?>
                  </div>
                </td>
                <td class="num fw-600"><?= e(Present::units($c['sms_units'])) ?></td>
                <td class="num text-sm">
                  <?= $c['unit_price'] !== null
                      ? e(number_format((float) $c['unit_price'], 2))
                      : '<span class="text-muted">standard</span>' ?>
                </td>
                <td class="num text-muted"><?= number_format((int) $c['sent_30']) ?></td>
                <td>
                  <span class="badge <?= $c['status'] === 'active' ? 'badge--green' : 'badge--red' ?>">
                    <?= $c['status'] === 'active' ? 'Active' : 'Paused' ?>
                  </span>
                </td>
                <td>
                  <div class="btn-group">
                    <button class="btn btn--primary btn--sm" type="button" data-modal-open="give-<?= (int) $c['id'] ?>">Give units</button>
                    <button class="btn btn--ghost btn--sm" type="button" data-modal-open="price-<?= (int) $c['id'] ?>">Price</button>
                    <form method="post" action="<?= url($base . '/clients/' . (int) $c['id'] . '/status') ?>"
                          data-confirm="<?= $c['status'] === 'active' ? 'Pause their sending?' : 'Let them send again?' ?>">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit">
                        <?= $c['status'] === 'active' ? icon('lock') : icon('check') ?>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="portal-card portal-card--quiet">
    <div class="text-sm">
      <div class="fw-600 mb-8">How this works</div>
      <p class="text-muted mb-0">
        Units you give a client come out of your own balance, so top up
        before a big hand-over. When one of them asks to buy, it appears
        under <a href="<?= url($base . '/sales') ?>">their payments</a> for
        you to confirm — the money goes to you, so only you can say it
        arrived. You cannot see what they send; that is theirs.
      </p>
    </div>
  </div>
</div>

<?php foreach ($clients as $c): ?>
  <div class="modal-backdrop" id="give-<?= (int) $c['id'] ?>">
    <div class="modal modal--sm">
      <form method="post" action="<?= url($base . '/clients/' . (int) $c['id'] . '/units') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Give units to <?= e($c['client_name']) ?></div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <p class="text-sm text-muted">
            You have <strong><?= e(Present::units($account['sms_units'])) ?></strong> units.
            They have <strong><?= e(Present::units($c['sms_units'])) ?></strong>.
          </p>
          <div class="field">
            <label class="label" for="gu-<?= (int) $c['id'] ?>">Units</label>
            <input class="input" id="gu-<?= (int) $c['id'] ?>" name="units" type="number" min="1" step="1" required>
          </div>
          <div class="field mb-0">
            <label class="label" for="gn-<?= (int) $c['id'] ?>">Note</label>
            <input class="input" id="gn-<?= (int) $c['id'] ?>" name="note" maxlength="255" placeholder="e.g. paid by M-Pesa, ref SFH7K2LM9P">
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Send them across</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-backdrop" id="price-<?= (int) $c['id'] ?>">
    <div class="modal modal--sm">
      <form method="post" action="<?= url($base . '/clients/' . (int) $c['id'] . '/price') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">What <?= e($c['client_name']) ?> pays</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <div class="field mb-0">
            <label class="label" for="pu-<?= (int) $c['id'] ?>">Price per unit (KES)</label>
            <input class="input" id="pu-<?= (int) $c['id'] ?>" name="unit_price" type="number" step="0.01" min="0"
                   value="<?= e($c['unit_price'] ?? '') ?>" placeholder="Your standard price">
            <span class="field-hint">
              Leave empty and they pay whatever your standard price is.
              You pay KES <?= e(number_format($price, 2)) ?> a unit.
            </span>
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($without): ?>
  <div class="modal-backdrop" id="open-sms">
    <div class="modal modal--sm">
      <form method="post" action="<?= url($base . '/clients') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Start SMS for a client</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <div class="field mb-0">
            <label class="label" for="oc-client">Which client</label>
            <select class="select" id="oc-client" name="client_id" required>
              <option value="">Choose…</option>
              <?php foreach ($without as $w): ?>
                <option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">
              They can then sign in to their own portal and send. You supply
              their units.
            </span>
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Open it</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
