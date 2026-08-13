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
<title><?= e($title ?? 'Document') ?> · <?= e(setting('company_name', 'Shanfix Technology')) ?></title>
<?= css_tag() ?>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body style="background:var(--bg);padding:24px 16px">
<?= $content ?>
<?= js_tag() ?>
<?php if (!empty($autoPrint)): ?>
<script src="<?= asset('js/autoprint.js') ?>"></script>
<?php endif; ?>
</body>
</html>
