<?php
/**
 * Sender IDs: the names messages arrive from.
 *
 * Three different things share this page, and the tabs keep them apart:
 *
 *   Waiting   a customer has asked for a name; the networks have to
 *             register it before it can be approved.
 *   Held      names we have registered with Onfon and not yet given to
 *             anybody. Stock.
 *   In use    a name, and the customer who may send under it.
 *
 * Giving one out is the whole point: the engine checks a sender against
 * the account asking to use it, so until a name is mapped to an account
 * nobody can send under it.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;

$section    = 'senders';
$canApprove = Auth::can('bulksms.approve');
$pool       = $status === 'pool';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Sender IDs</h1>
    <div class="page-head__sub">
      Registered with the networks through Onfon, then given to the customer who sends under them
    </div>
  </div>
  <?php if ($canApprove): ?>
    <div class="page-head__actions">
      <button class="btn btn--outline" type="button" data-modal-open="whitelist">
        <?= icon('layers') ?> Record what we hold
      </button>
      <button class="btn btn--primary" type="button" data-modal-open="add-sender">
        <?= icon('plus') ?> Add one for a customer
      </button>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
  <nav class="tabs">
    <?php foreach ([
        'pending'  => 'Waiting on the networks',
        'pool'     => 'Held by us',
        'approved' => 'In use',
        'rejected' => 'Not approved',
        'all'      => 'All',
    ] as $key => $label): ?>
      <a class="tab <?= $status === $key ? 'is-active' : '' ?>" href="<?= url('/bulk-sms/sender-ids?status=' . $key) ?>">
        <?= e($label) ?>
        <?php if (!empty($counts[$key])): ?><span class="tab__count"><?= (int) $counts[$key] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($pool): ?>
    <div class="card__body" style="padding-bottom:0">
      <p class="text-sm text-muted mb-0">
        Names we have registered with Onfon and nobody is using yet. Pick the
        customer each one belongs to and they can send under it straight away
        — no waiting, because the registration is already done.
      </p>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('shield') ?></div>
      <div class="empty__title">
        <?= match ($status) {
            'pending'  => 'Nothing waiting',
            'pool'     => 'No sender IDs in stock',
            'approved' => 'None in use yet',
            default    => 'None here',
        } ?>
      </div>
      <?php if ($pool && $canApprove): ?>
        <div class="empty__text">
          Paste the list from the Onfon portal with <strong>Record what we hold</strong>
          and they can be handed out in one click each.
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:140px">Sender ID</th>
            <th><?= $pool ? 'Give it to' : 'Account' ?></th>
            <?php if (!$pool): ?><th>What it is for</th><?php endif; ?>
            <th style="width:140px">Papers</th>
            <th style="width:170px">State</th>
            <?php if ($canApprove): ?><th style="width:190px"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $s): ?>
            <?php [$cls, $word] = Present::sender((string) $s['status']);
                  $held = $s['account_id'] === null; ?>
            <tr>
              <td class="fw-700 code"><?= e($s['sender_id']) ?></td>

              <td class="text-sm">
                <?php if ($held && $canApprove): ?>
                  <?php // The mapping, right where the name is. ?>
                  <form method="post" action="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/assign') ?>" class="row-form row-form--tight">
                    <?= csrf_field() ?>
                    <select class="select input--sm" name="account_id" required style="max-width:280px">
                      <option value="">Choose a customer…</option>
                      <?php foreach ($accountsList as $a): ?>
                        <option value="<?= (int) $a['id'] ?>">
                          <?= e($a['owner_name']) ?><?= $a['owner_type'] === 'partner' ? ' (partner)' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn--primary btn--sm" type="submit">Give</button>
                  </form>
                <?php elseif ($held): ?>
                  <span class="text-muted">Nobody yet</span>
                <?php else: ?>
                  <a href="<?= url('/bulk-sms/accounts/' . (int) $s['account_id']) ?>"><?= e($s['owner_name']) ?></a>
                  <div class="text-xs text-muted">
                    <?= $s['owner_type'] === 'partner' ? 'Partner' : 'Client' ?>
                    &middot; <?= $s['source'] === 'office' ? 'given by us' : 'asked ' . e(fdate($s['created_at'])) ?>
                  </div>
                <?php endif; ?>
              </td>

              <?php if (!$pool): ?>
                <td class="text-sm text-muted"><?= e(mb_strimwidth((string) $s['purpose'], 0, 110, '…')) ?></td>
              <?php endif; ?>

              <td class="text-sm">
                <?php if ($s['application_letter']): ?>
                  <a href="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/letter') ?>"><?= icon('paperclip') ?> Letter</a><br>
                <?php endif; ?>
                <?php if ($s['registration_cert']): ?>
                  <a href="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/certificate') ?>"><?= icon('paperclip') ?> Certificate</a>
                <?php endif; ?>
                <?php if (!$s['application_letter'] && !$s['registration_cert']): ?><span class="text-muted">—</span><?php endif; ?>
              </td>

              <td>
                <?php if ($held && $s['status'] === 'approved'): ?>
                  <span class="badge badge--blue">Held by us</span>
                <?php else: ?>
                  <span class="badge <?= e($cls) ?>"><?= e($word) ?></span>
                <?php endif; ?>
                <?php if ($s['reject_reason']): ?><div class="text-xs text-muted"><?= e($s['reject_reason']) ?></div><?php endif; ?>
                <?php if ($s['decided_name']): ?><div class="text-xs text-muted">by <?= e($s['decided_name']) ?></div><?php endif; ?>
              </td>

              <?php if ($canApprove): ?>
                <td>
                  <div class="btn-group">
                    <?php if ($s['status'] === 'pending'): ?>
                      <form method="post" action="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/decide') ?>"
                            data-confirm="Has Onfon registered <?= e($s['sender_id']) ?>? The customer can send from it as soon as you approve.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="approve">
                        <button class="btn btn--primary btn--sm" type="submit">Approve</button>
                      </form>
                      <button class="btn btn--ghost btn--sm" type="button" data-modal-open="reject-<?= (int) $s['id'] ?>">Decline</button>
                    <?php elseif (!$held): ?>
                      <form method="post" action="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/unassign') ?>"
                            data-confirm="Take <?= e($s['sender_id']) ?> back? They will not be able to send under it, and it stays in stock for somebody else.">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--sm" type="submit">Take back</button>
                      </form>
                    <?php endif; ?>

                    <?php if ($s['status'] !== 'pending'): ?>
                      <form method="post" action="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/delete') ?>"
                            data-confirm="Remove <?= e($s['sender_id']) ?> from the system? This does not unregister it at Onfon.">
                        <?= csrf_field() ?>
                        <button class="btn btn--ghost btn--sm" type="submit" title="Remove"><?= icon('trash') ?></button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($canApprove): ?>
  <?php foreach ($rows as $s): ?>
    <?php if ($s['status'] !== 'pending') continue; ?>
    <div class="modal-backdrop" id="reject-<?= (int) $s['id'] ?>">
      <div class="modal modal--sm">
        <form method="post" action="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/decide') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="decision" value="reject">
          <div class="modal__head">
            <div class="card__title">Decline <?= e($s['sender_id']) ?></div>
            <button class="modal__close" type="button" data-modal-close>&times;</button>
          </div>
          <div class="modal__body">
            <div class="field mb-0">
              <label class="label" for="rr-<?= (int) $s['id'] ?>">Why — the customer is told this</label>
              <textarea class="textarea" id="rr-<?= (int) $s['id'] ?>" name="reason" rows="3" maxlength="255" required
                        placeholder="e.g. The networks need a letter on company letterhead."></textarea>
            </div>
          </div>
          <div class="modal__foot">
            <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
            <button class="btn btn--danger" type="submit">Decline it</button>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <?php // Everything we hold at Onfon, in one paste. ?>
  <div class="modal-backdrop" id="whitelist">
    <div class="modal">
      <form method="post" action="<?= url('/bulk-sms/sender-ids/whitelist') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Record the sender IDs we hold</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <p class="text-sm text-muted">
            Copy the sender names from the Onfon portal and paste them here,
            one per line. They go into stock — registered, belonging to
            nobody — and you give each one to a customer when they need it.
          </p>
          <div class="field mb-0">
            <label class="label" for="wl-list">Sender IDs</label>
            <textarea class="textarea input--code" id="wl-list" name="sender_ids" rows="10" required
                      placeholder="SHANFIX&#10;SHANFIXSMS&#10;INFO&#10;ALERTS"></textarea>
            <div class="field-hint">
              Anything already known is left alone, so pasting the whole list
              again is safe. Names the networks would refuse are listed back
              to you rather than added.
            </div>
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Record them</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-backdrop" id="add-sender">
    <div class="modal">
      <form method="post" action="<?= url('/bulk-sms/sender-ids') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Add a sender ID for a customer</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <p class="text-sm text-muted">
            For a name already registered with Onfon that this customer should
            be able to send under. It works straight away, so make sure Onfon
            has it first.
          </p>
          <div class="field">
            <label class="label" for="as-account">Account</label>
            <select class="select" id="as-account" name="account_id" required>
              <option value="">Choose…</option>
              <?php foreach ($accountsList as $a): ?>
                <option value="<?= (int) $a['id'] ?>"><?= e($a['owner_name']) ?><?= $a['owner_type'] === 'partner' ? ' (partner)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="as-sender">Sender ID</label>
            <input class="input input--code" id="as-sender" name="sender_id" maxlength="11" required placeholder="Exactly as registered">
            <div class="field-hint">3 to 11 characters. Capitals matter to the networks.</div>
          </div>
          <div class="field mb-0">
            <label class="label" for="as-purpose">Note</label>
            <input class="input" id="as-purpose" name="purpose" maxlength="255">
          </div>
        </div>
        <div class="modal__foot">
          <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
          <button class="btn btn--primary" type="submit">Add it</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
