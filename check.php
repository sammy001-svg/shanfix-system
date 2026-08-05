<?php
/**
 * Shanfix BMS — installation self-check.
 *
 * Open this in a browser after uploading:  https://your-domain/check.php
 *
 * It reports the real document root, whether every required file is where
 * the application expects it, PHP version and extensions, folder
 * permissions, and whether the database credentials actually connect.
 *
 * DELETE THIS FILE once the system is running — it reveals server paths.
 */

$checks = [];
$fail   = 0;
$warn   = 0;

function check(string $name, bool $pass, string $detail = '', bool $warnOnly = false): void
{
    global $checks, $fail, $warn;

    $status = $pass ? 'pass' : ($warnOnly ? 'warn' : 'fail');

    if (!$pass) {
        $warnOnly ? $warn++ : $fail++;
    }

    $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
}

// ---------------------------------------------------------------------
// Where am I?
// ---------------------------------------------------------------------
$here       = __DIR__;
$docRoot    = $_SERVER['DOCUMENT_ROOT'] ?? '(not reported)';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$server     = $_SERVER['SERVER_SOFTWARE'] ?? '(unknown)';

$sameRoot = realpath($docRoot) !== false && realpath($here) === realpath($docRoot);

// ---------------------------------------------------------------------
// Layout detection
// ---------------------------------------------------------------------
$flat     = is_file($here . '/index.php') && is_dir($here . '/app');
$standard = is_dir($here . '/public') && is_file($here . '/public/index.php');

// ---------------------------------------------------------------------
// PHP
// ---------------------------------------------------------------------
check('PHP 8.0 or newer', version_compare(PHP_VERSION, '8.0.0', '>='), 'Running ' . PHP_VERSION);

foreach (['pdo_mysql' => 'database access', 'mbstring' => 'text handling',
          'openssl'   => 'encrypting API secrets', 'json' => 'data encoding'] as $ext => $why) {
    check("Extension: {$ext}", extension_loaded($ext), 'Needed for ' . $why);
}

check('Extension: curl', extension_loaded('curl'),
      'Needed for M-Pesa STK Push. Without it, payments must be recorded by hand.', true);
check('Extension: fileinfo', extension_loaded('fileinfo'),
      'Used to verify uploaded files match their extension.', true);

// ---------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------
$base = $flat ? $here : $here;

check('app/bootstrap.php found',  is_file($base . '/app/bootstrap.php'));
check('app/Core/App.php found',   is_file($base . '/app/Core/App.php'));
check('routes.php found',         is_file($base . '/routes.php'));
check('database/schema.sql found',is_file($base . '/database/schema.sql'));

$assets = $flat ? '/assets/css/app.css' : '/public/assets/css/app.css';
check('Stylesheet found', is_file($base . $assets), $base . $assets);

$hasConfig = is_file($base . '/config/config.php');
check('config/config.php exists', $hasConfig,
      $hasConfig ? '' : 'Copy config/config.sample.php to config/config.php and fill in your database details.');

// ---------------------------------------------------------------------
// Writable folders
// ---------------------------------------------------------------------
foreach (['storage', 'storage/logs', 'storage/uploads'] as $dir) {
    $path = $base . '/' . $dir;

    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }

    check("Writable: {$dir}/", is_dir($path) && is_writable($path),
          is_dir($path) ? '' : 'Folder is missing. Create it and set permissions to 755.');
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------
$dbDetail = '';
$dbOk     = false;
$tables   = 0;
$appKey   = false;

if ($hasConfig) {
    $config = @include $base . '/config/config.php';

    if (!is_array($config) || empty($config['db'])) {
        $dbDetail = 'config.php does not return a valid array with a db block.';
    } else {
        $db = $config['db'];
        // Either source works — config.php wins, else the auto-generated file.
        $appKey = !empty($config['security']['app_key'])
               || is_file($base . '/storage/app.key');

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        $db['host'] ?? 'localhost', $db['port'] ?? 3306, $db['database'] ?? ''),
                $db['username'] ?? '',
                $db['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            $dbOk = true;
            $tables = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
            )->fetchColumn();

            $dbDetail = 'Connected to "' . ($db['database'] ?? '') . '" — ' . $tables . ' table(s)';
        } catch (Throwable $e) {
            $dbDetail = $e->getMessage();
        }
    }
}

check('Database connects', $dbOk, $dbDetail);
check('Schema imported', $dbOk && $tables >= 20,
      $dbOk ? ($tables >= 20 ? '' : "Only {$tables} tables found. Import database/schema.sql, seed.sql, then run migrations.") : '');
check('Encryption key set', $appKey,
      $appKey ? '' : 'None yet — one is generated into storage/app.key the first time a secret is saved. '
                   . 'Set security.app_key in config.php instead if you would rather pin it.', true);

// ---------------------------------------------------------------------
// Rewrite engine
// ---------------------------------------------------------------------
$rewrite = function_exists('apache_get_modules')
    ? in_array('mod_rewrite', apache_get_modules(), true)
    : null;

check('URL rewriting available', $rewrite !== false,
      $rewrite === null ? 'Could not be detected from PHP — the link test below is the real answer.' : '', true);

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installation check · Shanfix BMS</title>
<style>
  *{box-sizing:border-box} body{margin:0;background:#F3F6F9;color:#0F1E2E;
    font:14px/1.55 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;padding:24px 16px}
  .wrap{max-width:820px;margin:0 auto}
  .card{background:#fff;border:1px solid #DDE4EC;border-radius:10px;margin-bottom:18px;overflow:hidden}
  .card__head{background:#08203A;color:#fff;padding:18px 22px}
  .card__head h1{margin:0;font-size:19px}
  .card__head p{margin:4px 0 0;color:rgba(255,255,255,.66);font-size:13px}
  .strip{height:4px}
  .body{padding:18px 22px}
  .row{display:flex;gap:12px;align-items:flex-start;padding:9px 0;border-bottom:1px solid #EEF2F6}
  .row:last-child{border-bottom:0}
  .tag{flex:0 0 62px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
    padding:2px 0;border-radius:4px;text-align:center}
  .pass{background:#D5EFE0;color:#0B5730} .fail{background:#FBE3E0;color:#A62A20}
  .warn{background:#FCEFD8;color:#9A5B08}
  .name{font-weight:600;flex:1}
  .detail{color:#5A6B7D;font-size:12.5px;margin-top:2px;word-break:break-word}
  dl{display:grid;grid-template-columns:190px 1fr;gap:7px 14px;margin:0;font-size:13px}
  dt{color:#5A6B7D} dd{margin:0;word-break:break-all}
  code{background:#F3F6F9;border:1px solid #DDE4EC;border-radius:4px;padding:1px 5px;
    font-family:Consolas,Menlo,monospace;font-size:12.5px}
  .banner{padding:16px 22px;font-size:15px;font-weight:600}
  .banner--ok{background:#EDF8F2;color:#0B5730;border-bottom:1px solid #D5EFE0}
  .banner--bad{background:#FDF1F0;color:#A62A20;border-bottom:1px solid #FBE3E0}
  .btn{display:inline-block;background:#14874E;color:#fff;padding:11px 22px;border-radius:6px;
    text-decoration:none;font-weight:600;margin-top:6px}
  .note{background:#FEF8EC;border:1px solid #FCEFD8;color:#9A5B08;padding:12px 15px;
    border-radius:6px;font-size:13px;margin-top:14px}
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <div class="card__head">
      <h1>Shanfix BMS — installation check</h1>
      <p>Run from <?= $e($here) ?></p>
    </div>
    <div class="strip" style="background:<?= $fail ? '#A62A20' : '#14874E' ?>"></div>

    <?php if ($fail === 0): ?>
      <div class="banner banner--ok">
        Everything checks out<?= $warn ? ' — with ' . $warn . ' warning(s) below' : '' ?>.
      </div>
      <div class="body">
        <a class="btn" href="<?= $e(rtrim(dirname($scriptName), '/\\') . '/') ?>">Open the system &rarr;</a>
        <div class="note">
          Delete <code>check.php</code> and <code>install.php</code> from the server now.
          This page reveals server paths.
        </div>
      </div>
    <?php else: ?>
      <div class="banner banner--bad"><?= $fail ?> problem(s) need fixing — see below.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head" style="background:#123A61"><h1 style="font-size:15px">Where the server thinks it is</h1></div>
    <div class="body">
      <dl>
        <dt>Web server</dt><dd><?= $e($server) ?></dd>
        <dt>Document root</dt><dd><code><?= $e($docRoot) ?></code></dd>
        <dt>This file is in</dt><dd><code><?= $e($here) ?></code></dd>
        <dt>Script path</dt><dd><code><?= $e($scriptName) ?></code></dd>
        <dt>Layout detected</dt>
        <dd>
          <?php if ($flat): ?>
            Flat — <code>index.php</code> is beside this file. No document root change needed.
          <?php elseif ($standard): ?>
            Standard — the app is in <code>public/</code>.
            The document root <strong>must</strong> point at
            <code><?= $e($here . DIRECTORY_SEPARATOR . 'public') ?></code>
          <?php else: ?>
            <span style="color:#A62A20">Could not find <code>index.php</code> or <code>app/</code> beside this file — the upload may be incomplete.</span>
          <?php endif; ?>
        </dd>
      </dl>

      <?php if (!$sameRoot && $flat): ?>
        <div class="note">
          This file is not in the document root the server reported. If the system does not
          load at the bare domain, the subdomain is pointing somewhere else — set its
          Document Root to <code><?= $e($here) ?></code>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head" style="background:#123A61"><h1 style="font-size:15px">Checks</h1></div>
    <div class="body">
      <?php foreach ($checks as $c): ?>
        <div class="row">
          <span class="tag <?= $c['status'] ?>"><?= $c['status'] === 'pass' ? 'OK' : ($c['status'] === 'warn' ? 'Warn' : 'Fail') ?></span>
          <span class="name">
            <?= $e($c['name']) ?>
            <?php if ($c['detail'] !== ''): ?>
              <div class="detail"><?= $e($c['detail']) ?></div>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head" style="background:#123A61"><h1 style="font-size:15px">Clean URL test</h1></div>
    <div class="body">
      <p style="margin:0 0 10px;color:#5A6B7D">
        This link works only if URL rewriting is active. If it 404s, the
        <code>.htaccess</code> file is missing or being ignored by your host.
      </p>
      <a class="btn" href="<?= $e(rtrim(dirname($scriptName), '/\\') . '/login') ?>">Test /login &rarr;</a>
    </div>
  </div>

</div>
</body>
</html>
