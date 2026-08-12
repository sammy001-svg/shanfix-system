<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? 'Sign in') ?> · <?= e($appName ?? 'Shanfix Technology') ?></title>
<?= css_tag() ?>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body>
<?= $content ?>
<?= js_tag() ?>
</body>
</html>
