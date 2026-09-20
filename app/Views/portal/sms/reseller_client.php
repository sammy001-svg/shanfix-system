<?php
/**
 * One of a reseller's SMS clients.
 *
 * Their balance, what they have bought, every unit that has passed
 * between the two of you, and how much of it they are using.
 *
 * Not here, and nowhere a reseller can reach: a single word of what that
 * client has actually sent. You supply the credit; the correspondence is
 * theirs.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;
use App\Services\BulkSms\Purchases;

$id = (int) $client['id'];
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div>
        <div class="portal-card__title"><?= e($who['name']) ?></div>
        <div class="text-sm text-muted">
          <?= e($contact['contact_person'] ?? '') ?>
          <?php if (!empty($contact['phone'])): ?> · <?= e($contact['phone']) ?><?php endif; ?>
          <?php if (!empty($contact['email'])): ?> · <?= e($contact['email']) ?><?php endif; ?>
        </div>
      </div>
      <a class="btn btn--ghost btn--sm" href="<?= url($base . '/clients') ?>">All my clients</a>
    </div>

    <div class="portal-stats">
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= e(Present::units($client['sms_units'])) ?></div>
        <div class="portal-stat__label">Units they hold</div>
      </div>
      <div class="portal-stat">
        <div class="portal-stat__figure">KES <?= e(number_format($price, 2)) ?></div>
        <div class="portal-stat__label">What they pay you</div>
        <div class="portal-stat__note"><?= $client['unit_price'] !== null ? 'Their own price' : 'Your standard price' ?></div>
      </div>
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= e(money($sold)) ?></div>
        <div class="portal-stat__label">They have paid you</div>
      </div>
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= number_format($usage['sent']) ?></div>
        <div class="portal-stat__label">Messages in 30 days</div>
        <div class="portal-stat__note"><?= e(Present::units($usage['units'])) ?> units used</div>
      </div>
    </div>

    <div class="btn-group">
      <button class="btn btn--primary btn--sm" type="button" data-modal-open="give-one">Give units</button>
      <button class="btn btn--ghost btn--sm" type="button" data-modal-open="price-one">Change their price</button>
      <form method="post" action="<?= url($base . '/clients/' . $id . '/status') ?>"
            data-confirm="<?= $client['status'] === 'active' ? 'Pause their sending?' : 'Let them send again?' ?>">
        <?= csrf_field() ?>
        <button class="btn btn--ghost btn--sm" type="submit">
          <?= $client['status'] === 'active' ? 'Pause them' : 'Let them send' ?>
        </button>
      </form>
    </div>
  </div>

  <div class="portal-cols">
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">Units in and out</div></div>

      <?php if (!$ledger): ?>
        <p class="text-sm text-muted">Nothing has moved yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th style="width:140px">When</th><th>What</th><th class="num" style="width:100px">Units</th></tr></thead>
            <tbody>
              <?php foreach ($ledger as $l): ?>
                <?php $amount = (float) $l['amount']; ?>
                <tr>
                  <td class="text-xs text-muted"><?= e(fdatetime($l['created_at'])) ?></td>
                  <td class="text-sm">
                    <?= e(Present::ledgerKind((string) $l['kind'])) ?>
                    <?php if ($l['note']): ?><div class="text-xs text-muted"><?= e($l['note']) ?></div><?php endif; ?>
                  </td>
                  <td class="num fw-600" style="color:<?= $amount < 0 ? 'var(--red-600)' : 'var(--green-700)' ?>">
                    <?= $amount > 0 ? '+' : '' ?><?= e(Present::units($amount)) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div>
      <div class="portal-card">
        <div class="portal-card__head"><div class="portal-card__title">What they have bought</div></div>

        <?php if (!$purchases): ?>
          <p class="text-sm text-muted">Nothing yet.</p>
        <?php else: ?>
          <ul class="portal-list portal-list--tight">
            <?php foreach ($purchases as $p): ?>
              <?php [$cls, $word] = Present::purchase((string) $p['status']); ?>
              <li class="portal-list__row">
                <span class="portal-list__main">
                  <span class="portal-list__title"><?= e(Present::units($p['units'])) ?> units · <?= e(money($p['amount'])) ?></span>
                  <span class="portal-list__meta">
                    <?= e(fdate($p['created_at'])) ?>
                    · <?= e(Purchases::METHODS[$p['method']] ?? $p['method']) ?>
                    <?php if ($p['transaction_ref']): ?> · <?= e($p['transaction_ref']) ?><?php endif; ?>
                  </span>
                </span>
                <span class="portal-list__side"><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="portal-card">
        <div class="portal-card__head"><div class="portal-card__title">Their sender IDs</div></div>
        <?php if (!$senders): ?>
          <p class="text-sm text-muted">
            None yet. They ask for one in their own portal and our office
            registers it with the networks.
          </p>
        <?php else: ?>
          <ul class="check-list">
            <?php foreach ($senders as $s): ?>
              <?php [$cls, $word] = Present::sender((string) $s['status']); ?>
              <li class="text-sm">
                <span class="code fw-600"><?= e($s['sender_id']) ?></span>
                <span class="badge <?= e($cls) ?>"><?= e($word) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="portal-card portal-card--quiet">
        <div class="text-sm text-muted">
          What this client sends is not shown to you, and cannot be. They
          buy credit from you; what they do with it is between them and the
          people they message.
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="give-one">
  <div class="modal modal--sm">
    <form method="post" action="<?= url($base . '/clients/' . $id . '/units') ?>">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="card__title">Give units to <?= e($who['name']) ?></div>
        <button class="modal__close" type="button" data-modal-close>&times;</button>
      </div>
      <div class="modal__body">
        <p class="text-sm text-muted">
          You have <strong><?= e(Present::units($account['sms_units'])) ?></strong> units.
        </p>
        <div class="field">
          <label class="label" for="go-units">Units</label>
          <input class="input" id="go-units" name="units" type="number" min="1" step="1" required>
        </div>
        <div class="field mb-0">
          <label class="label" for="go-note">Note</label>
          <input class="input" id="go-note" name="note" maxlength="255" placeholder="e.g. paid by M-Pesa, ref SFH7K2LM9P">
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit">Send them across</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="price-one">
  <div class="modal modal--sm">
    <form method="post" action="<?= url($base . '/clients/' . $id . '/price') ?>">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="card__title">What <?= e($who['name']) ?> pays</div>
        <button class="modal__close" type="button" data-modal-close>&times;</button>
      </div>
      <div class="modal__body">
        <div class="field mb-0">
          <label class="label" for="po-price">Price per unit (KES)</label>
          <input class="input" id="po-price" name="unit_price" type="number" step="0.01" min="0"
                 value="<?= e($client['unit_price'] ?? '') ?>" placeholder="Your standard price">
          <span class="field-hint">Leave empty and they pay your standard price.</span>
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>
