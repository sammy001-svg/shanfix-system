<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>
<!doctype html>
<!-- Documents stand for sheets of paper, so they are pinned to light: what
     is on screen matches what leaves the printer, and a client opening a
     shared link sees a document rather than a dark interface. -->
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php
  // Pages on this layout are no longer read-only — a guest in a meeting
  // posts notes and signalling from here, and the scripts read the token
  // from this tag. Without it every such request is rejected as forged.
?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? 'Document') ?> · <?= e(setting('company_name', 'Shanfix Technology')) ?></title>
<?= css_tag() ?>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body style="background:var(--bg);padding:24px 16px">
<?php
  // Clients act on this page now — they can pay an invoice from it — so it
  // has to be able to tell them what happened. Without this, "check your
  // phone" and "that number is not valid" both vanish silently.
  require_once APP_PATH . '/Views/partials/icons.php';
?>
<?php if (!empty($flashes)): ?>
  <div class="no-print" style="max-width:840px;margin:0 auto 14px">
    <?php foreach ($flashes as $flash): ?>
      <div class="alert alert--<?= e($flash['type']) ?>">
        <?= icon(match ($flash['type']) {
            'success' => 'check-circle',
            'error'   => 'x-circle',
            'warning' => 'alert-triangle',
            default   => 'info',
        }) ?>
        <div class="alert__body"><?= e($flash['message']) ?></div>
        <button class="alert__close" type="button" aria-label="Dismiss">&times;</button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?= $content ?>
<?= js_tag() ?>
<?php if (!empty($autoPrint)): ?>
<script src="<?= asset('js/autoprint.js') ?>"></script>
<?php endif; ?>
</body>
</html>
