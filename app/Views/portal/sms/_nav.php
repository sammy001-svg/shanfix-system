<?php
/**
 * The heading every SMS page carries: what the account holds, and the
 * way to buy more.
 *
 * This used to be a row of tabs as well. The sections now live in the
 * sidebar with everything else, so having them here too would be the
 * same menu twice — and two menus disagree the moment one is edited.
 *
 * @var array  $account
 * @var string $base
 */
use App\Services\BulkSms\Present;

$units = (float) $account['sms_units'];

// Below this the page says so plainly rather than waiting for the daily
// warning e-mail, which is no use to somebody looking at the screen now.
$low = $units < 50;
?>
<div class="portal-hello">
  <h1 class="portal-h1"><?= e($title ?? 'Bulk SMS') ?></h1>
  <p class="portal-lede">
    You have <strong><?= e(Present::units($units)) ?></strong>
    unit<?= abs($units - 1) < 0.001 ? '' : 's' ?> left.
    <a href="<?= url($base . '/buy') ?>">Buy more</a>
  </p>
</div>

<?php if ($account['status'] !== 'active'): ?>
  <div class="alert alert--warning">
    <?= icon('lock') ?>
    <div class="alert__body">
      Sending is paused on your account. Please get in touch and we will sort it out.
    </div>
  </div>
<?php elseif ($low): ?>
  <div class="alert alert--info">
    <?= icon('credit-card') ?>
    <div class="alert__body">
      You are down to <?= e(Present::units($units)) ?>.
      <a href="<?= url($base . '/buy') ?>"><strong>Top up</strong></a> to keep sending.
    </div>
  </div>
<?php endif; ?>
