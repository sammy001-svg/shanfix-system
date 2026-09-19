<?php
/**
 * The SMS platform's own navigation, across the top of every page in it.
 *
 * @var string $section which tab is current
 */
use App\Core\Auth;
use App\Core\Database;

$pendingSenders = (int) Database::scalar("SELECT COUNT(*) FROM bulk_sender_ids WHERE status = 'pending'", [], 0);
$pendingSales   = (int) Database::scalar(
    "SELECT COUNT(*) FROM bulk_purchases p JOIN bulk_accounts s ON s.id = p.seller_account_id
      WHERE p.status = 'pending' AND s.owner_type = 'house'", [], 0);

$tabs = [
    'overview'  => ['/bulk-sms',            'grid',          'Overview',   0],
    'accounts'  => ['/bulk-sms/accounts',   'users',         'Accounts',   0],
    'campaigns' => ['/bulk-sms/campaigns',  'send',          'Campaigns',  0],
    'messages'  => ['/bulk-sms/messages',   'activity',      'Delivery reports', 0],
    'senders'   => ['/bulk-sms/sender-ids', 'shield',        'Sender IDs', $pendingSenders],
    'purchases' => ['/bulk-sms/purchases',  'credit-card',   'Purchases',  $pendingSales],
    'plans'     => ['/bulk-sms/plans',      'layers',        'Price plans', 0],
];

if (Auth::can('bulksms.settings')) {
    $tabs['settings'] = ['/bulk-sms/settings', 'settings', 'Gateway', 0];
}
?>
<div class="card mb-16">
  <nav class="tabs">
    <?php foreach ($tabs as $key => [$href, $ico, $label, $count]): ?>
      <a class="tab <?= ($section ?? '') === $key ? 'is-active' : '' ?>" href="<?= url($href) ?>">
        <?= icon($ico) ?> <?= e($label) ?>
        <?php if ($count > 0): ?><span class="tab__count"><?= $count ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</div>
