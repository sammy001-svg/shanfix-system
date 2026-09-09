<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>
<!doctype html>
<html lang="en">
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
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? 'Sign in') ?> · <?= e($appName ?? 'Shanfix Technology') ?></title>
<?php // Applies the saved theme before anything paints — see layouts/app.php. ?>
<script nonce="<?= e(csp_nonce()) ?>">
  (function () {
    try {
      var saved = localStorage.getItem('shanfix-theme');
      if (saved === 'light' || saved === 'dark') {
        document.documentElement.setAttribute('data-theme', saved);
      }
    } catch (e) {}
  })();
</script>
<?= css_tag() ?>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body>
<?= $content ?>
<?= js_tag() ?>
</body>
</html>
