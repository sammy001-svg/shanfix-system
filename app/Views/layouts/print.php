<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>
<!doctype html>
<!-- Documents stand for sheets of paper, so they are pinned to light: what
     is on screen matches what leaves the printer, and a client opening a
     shared link sees a document rather than a dark interface. -->
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php // The system shares a domain with the company website now, so a
      // crawler that finds its way in would otherwise index the sign-in
      // page, the portals and every URL it can reach from them. None of
      // this belongs in a search result: it is a business system, not
      // publishing. robots.txt asks crawlers not to fetch these; this
      // says not to list them even if a URL turns up elsewhere. ?>
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Document') ?> · <?= e($appName ?? 'Shanfix Technology') ?></title>
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
