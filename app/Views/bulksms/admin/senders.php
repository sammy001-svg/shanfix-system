<?php
/**
 * Sender IDs: the names messages arrive from, which the networks have to
 * approve. Waiting ones first — a customer cannot send until theirs is
 * through.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;

$section    = 'senders';
$canApprove = Auth::can('bulksms.approve');
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Sender IDs</h1>
    <div class="page-head__sub">Register each with Onfon, then approve it here so the customer can use it</div>
  </div>
  <?php if ($canApprove): ?>
    <div class="page-head__actions">
      <button class="btn btn--primary" type="button" data-modal-open="add-sender"><?= icon('plus') ?> Add a registered sender ID</button>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
  <nav class="tabs">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Not approved', 'all' => 'All'] as $key => $label): ?>
      <a class="tab <?= $status === $key ? 'is-active' : '' ?>" href="<?= url('/bulk-sms/sender-ids?status=' . $key) ?>">
        <?= e($label) ?>
        <?php if ($key !== 'all' && !empty($counts[$key])): ?><span class="tab__count"><?= (int) $counts[$key] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('shield') ?></div>
      <div class="empty__title"><?= $status === 'pending' ? 'Nothing waiting' : 'None here' ?></div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:140px">Sender ID</th>
            <th>Account</th>
            <th>What it is for</th>
            <th style="width:150px">Papers</th>
            <th style="width:180px">State</th>
            <?php if ($canApprove): ?><th style="width:200px"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $s): ?>
            <?php [$cls, $word] = Present::sender((string) $s['status']); ?>
            <tr>
              <td class="fw-700 code"><?= e($s['sender_id']) ?></td>
              <td class="text-sm">
                <a href="<?= url('/bulk-sms/accounts/' . (int) $s['account_id']) ?>"><?= e($s['owner_name']) ?></a>
                <div class="text-xs text-muted"><?= $s['owner_type'] === 'partner' ? 'Partner' : 'Client' ?> · asked <?= e(fdate($s['created_at'])) ?></div>
              </td>
              <td class="text-sm text-muted"><?= e(mb_strimwidth((string) $s['purpose'], 0, 120, '…')) ?></td>
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
                <span class="badge <?= e($cls) ?>"><?= e($word) ?></span>
                <?php if ($s['reject_reason']): ?><div class="text-xs text-muted"><?= e($s['reject_reason']) ?></div><?php endif; ?>
                <?php if ($s['decided_name']): ?><div class="text-xs text-muted">by <?= e($s['decided_name']) ?></div><?php endif; ?>
              </td>
              <?php if ($canApprove): ?>
                <td>
                  <div class="btn-group">
                    <?php if ($s['status'] !== 'approved'): ?>
                      <form method="post" action="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/decide') ?>"
                            data-confirm="Has Onfon registered <?= e($s['sender_id']) ?>? The customer can send from it as soon as you approve.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="approve">
                        <button class="btn btn--primary btn--sm" type="submit">Approve</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($s['status'] === 'pending'): ?>
                      <button class="btn btn--ghost btn--sm" type="button" data-modal-open="reject-<?= (int) $s['id'] ?>">Decline</button>
                    <?php endif; ?>
                    <?php if ($s['status'] !== 'pending'): ?>
                      <form method="post" action="<?= url('/bulk-sms/sender-ids/' . (int) $s['id'] . '/delete') ?>"
                            data-confirm="Remove <?= e($s['sender_id']) ?> from this account? It will no longer be able to send from it.">
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

  <div class="modal-backdrop" id="add-sender">
    <div class="modal">
      <form method="post" action="<?= url('/bulk-sms/sender-ids') ?>">
        <?= csrf_field() ?>
        <div class="modal__head">
          <div class="card__title">Add a sender ID that is already registered</div>
          <button class="modal__close" type="button" data-modal-close>&times;</button>
        </div>
        <div class="modal__body">
          <p class="text-sm text-muted">
            For one the office registered with Onfon on a customer's behalf.
            It is approved straight away, so make sure Onfon has it first.
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
