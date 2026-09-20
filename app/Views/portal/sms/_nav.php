<?php
/**
 * The SMS section's own tabs, inside the customer's portal.
 *
 * @var string $smsTab which one is current
 * @var array  $account
 */
use App\Services\BulkSms\Present;

$smsTabs = [
    'home' => ['',           'Send'],
    'campaigns' => ['/campaigns', 'Campaigns'],
    'contacts' => ['/contacts',  'Contacts'],
    'reports' => ['/reports',   'Delivery'],
    'senders' => ['/senders',   'Sender IDs'],
    'buy' => ['/buy',       'Buy units'],
    'api' => ['/api',       'API'],
];

// A partner is a reseller as well as a sender, so their tabs carry what
// a client's do not: the clients they supply, the payments they have to
// confirm, and what they charge.
if (($account['owner_type'] ?? '') === 'partner') {
    $smsTabs['clients'] = ['/clients', 'My clients'];
    $smsTabs['sales']   = ['/sales',   'Their payments'];
    $smsTabs['pricing'] = ['/pricing', 'My prices'];
}

// How many of their clients' payments are waiting on them. Nobody checks
// a page that never says there is something to do.
$smsWaiting = ($account['owner_type'] ?? '') === 'partner'
    ? (int) \App\Core\Database::scalar(
        "SELECT COUNT(*) FROM bulk_purchases WHERE seller_account_id = :s AND status = 'pending'",
        ['s' => $account['id']], 0)
    : 0;
?>
<div class="portal-hello">
  <h1 class="portal-h1">Bulk SMS</h1>
  <p class="portal-lede">
    You have <strong><?= e(Present::units($account['sms_units'])) ?></strong>
    unit<?= abs((float) $account['sms_units'] - 1) < 0.001 ? '' : 's' ?> left.
    <a href="<?= url($base . '/buy') ?>">Buy more</a>
  </p>
</div>

<nav class="portal-tabs" aria-label="Bulk SMS">
  <?php foreach ($smsTabs as $key => [$href, $label]): ?>
    <a class="portal-tab <?= ($smsTab ?? '') === $key ? 'is-active' : '' ?>" href="<?= url($base . $href) ?>">
      <?= e($label) ?>
      <?php if ($key === 'sales' && $smsWaiting > 0): ?><span class="portal-tab__count"><?= $smsWaiting ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($account['status'] !== 'active'): ?>
  <div class="alert alert--warning">
    <?= icon('lock') ?>
    <div class="alert__body">
      Sending is paused on your account. Please get in touch and we will sort it out.
    </div>
  </div>
<?php endif; ?>
