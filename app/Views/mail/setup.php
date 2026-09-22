<?php
/**
 * Connecting one's own mailbox.
 *
 * Says plainly who can see what, because "the system has my email
 * password" is a reasonable thing for somebody to be uneasy about.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$a = $account ?? null;
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Your mailbox</h1>
    <div class="page-head__sub">Read and send your company email from inside the system.</div>
  </div>
  <?php if ($a): ?>
    <div class="page-head__actions">
      <a class="btn btn--primary" href="<?= e(url('/mail')) ?>"><?= icon('inbox') ?> Open my email</a>
    </div>
  <?php endif; ?>
</div>

<?php if (!$serverReady): ?>
  <div class="alert alert--warning mb-16">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      The company mail server has not been set up yet.
      <?php if (can('settings.manage')): ?>
        Fill it in under <a href="<?= e(url('/settings?tab=email')) ?>">Settings → Email server</a> first.
      <?php else: ?>
        Ask an administrator to fill in Settings → Email server.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--2">
  <form class="card" method="post" action="<?= e(url('/mail/setup')) ?>">
    <?= csrf_field() ?>
    <div class="card__head">
      <div class="card__title"><?= $a ? 'Your mailbox' : 'Connect your mailbox' ?></div>
      <?php if ($a && $a['last_ok_at']): ?>
        <span class="badge badge--green">Connected</span>
      <?php endif; ?>
    </div>
    <div class="card__body">
      <?php if ($a && $a['last_error']): ?>
        <div class="alert alert--warning mb-16">
          <?= icon('alert-triangle') ?>
          <div class="alert__body">The mail server stopped accepting your saved password. Enter your current one below.</div>
        </div>
      <?php endif; ?>

      <div class="field">
        <label class="label" for="mb_email">Your email address</label>
        <input class="input" type="email" id="mb_email" name="email" required autocomplete="username"
               value="<?= e($a['email'] ?? ($me['email'] ?? '')) ?>" placeholder="you@shanfixtechnology.com">
      </div>

      <div class="field">
        <label class="label" for="mb_password">Its password</label>
        <input class="input" type="password" id="mb_password" name="password" autocomplete="current-password"
               <?= $a ? '' : 'required' ?> placeholder="<?= $a ? 'Saved — type only to change it' : 'The password you use for this mailbox' ?>">
        <span class="field-hint">The same password you use for webmail in cPanel, Outlook or your phone.</span>
      </div>

      <div class="field">
        <label class="label" for="mb_name">Name people see</label>
        <input class="input" id="mb_name" name="display_name" maxlength="120"
               value="<?= e($a['display_name'] ?? ($me['name'] ?? '')) ?>">
      </div>

      <div class="field">
        <label class="label" for="mb_sig">Signature</label>
        <textarea class="textarea" id="mb_sig" name="signature" rows="4" maxlength="2000"
                  placeholder="Jane Wanjiru&#10;Sales, Shanfix Technology&#10;+254 7XX XXX XXX"><?= e($a['signature'] ?? '') ?></textarea>
        <span class="field-hint">Added to the end of every message you send.</span>
      </div>

      <button class="btn btn--primary" type="submit" <?= $serverReady ? '' : 'disabled' ?>>
        <?= $a ? 'Save' : 'Connect' ?>
      </button>
    </div>
  </form>

  <div class="card">
    <div class="card__head"><div class="card__title">Who can see your email</div></div>
    <div class="card__body text-sm">
      <p><strong>Only you.</strong> Your mailbox opens only when you are signed in as yourself.
        There is no screen anywhere in the system — including for administrators — that opens
        somebody else's email.</p>
      <p>Your password is stored encrypted, and is only ever used to open your own mailbox on the
        company mail server.</p>
      <p>Your mail stays where it is now, on the mail server. Anything you read, send or delete
        here is the same in Outlook, on your phone, or in cPanel webmail.</p>

      <?php if ($a): ?>
        <form method="post" action="<?= e(url('/mail/disconnect')) ?>" class="mt-16"
              onsubmit="return confirm('Disconnect your mailbox? Your saved password is deleted from the system. Your email itself is not touched.')">
          <?= csrf_field() ?>
          <button class="btn btn--ghost btn--sm" type="submit">Disconnect my mailbox</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
