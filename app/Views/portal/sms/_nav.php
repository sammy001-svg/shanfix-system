<?php
/**
 * The SMS section's own tabs, inside the customer's portal.
 *
 * @var string $smsTab which one is current
 * @var array  $account
 */
use App\Services\BulkSms\Present;

$smsTabs = [
    'home'      => ['/portal/sms',           'Send'],
    'campaigns' => ['/portal/sms/campaigns', 'Campaigns'],
    'contacts'  => ['/portal/sms/contacts',  'Contacts'],
    'reports'   => ['/portal/sms/reports',   'Delivery'],
    'senders'   => ['/portal/sms/senders',   'Sender IDs'],
    'buy'       => ['/portal/sms/buy',       'Buy units'],
    'api'       => ['/portal/sms/api',       'API'],
];
?>
<div class="portal-hello">
  <h1 class="portal-h1">Bulk SMS</h1>
  <p class="portal-lede">
    You have <strong><?= e(Present::units($account['sms_units'])) ?></strong>
    unit<?= abs((float) $account['sms_units'] - 1) < 0.001 ? '' : 's' ?> left.
    <a href="<?= url('/portal/sms/buy') ?>">Buy more</a>
  </p>
</div>

<nav class="portal-tabs" aria-label="Bulk SMS">
  <?php foreach ($smsTabs as $key => [$href, $label]): ?>
    <a class="portal-tab <?= ($smsTab ?? '') === $key ? 'is-active' : '' ?>" href="<?= url($href) ?>"><?= e($label) ?></a>
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
