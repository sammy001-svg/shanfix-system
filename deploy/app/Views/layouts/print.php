<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Document') ?> · <?= e($appName ?? 'Shanfix Technology') ?></title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body style="background:var(--bg);padding:24px 16px">
<?= $content ?>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if (!empty($autoPrint)): ?>
<script src="<?= asset('js/autoprint.js') ?>"></script>
<?php endif; ?>
</body>
</html>
