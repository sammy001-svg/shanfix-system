<?php
/**
 * Shanfix Technology BMS — database upgrade, from a browser.
 *
 * WHY THIS EXISTS
 * migrate.php only runs from a command line. Plenty of cPanel plans have no
 * Terminal, which leaves no way at all to apply a migration — so an upgrade
 * pulled through Git Version Control lands with its database changes missing,
 * and features fail in ways that look like unrelated bugs.
 *
 * SECURITY
 * Reaching this page shows nothing. Applying anything requires an
 * administrator's email and password, checked by the same code as the sign-in
 * screen, including its lockout after repeated failures. It is safe to leave
 * in place, but deleting it once the system is upgraded is tidier.
 *
 * The page carries its own styling because the whole point is that it works
 * when the rest of the system does not.
 */

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Session;

Config::load(CONFIG_PATH . '/config.php');

// App::boot() normally does this; this page deliberately does not boot the
// full application, so it has to set the clock itself or every timestamp it
// touches comes out in UTC.
date_default_timezone_set(Config::get('app.timezone', 'Africa/Nairobi'));

Database::connect(Config::get('db'));
Session::start();

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$error   = null;
$result  = null;
$signedIn = false;

// The migrations table may not exist yet, and the connection itself may be
// misconfigured — neither should render a blank page.
try {
    $migrator = new Migrator();
    $pending  = $migrator->pending();
    $applied  = $migrator->applied();
} catch (\Throwable $e) {
    $migrator = null;
    $pending  = [];
    $applied  = [];
    $error    = 'Could not read the database: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $migrator !== null) {
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $attempt = Auth::attempt($email, $password, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    if (!$attempt['ok']) {
        $error = $attempt['message'];
    } elseif (($attempt['user']['role'] ?? '') !== 'admin') {
        $error = 'Only an administrator can apply database upgrades.';
    } else {
        $signedIn = true;
        $result   = $migrator->migrate();
        $pending  = $migrator->pending();
        $applied  = $migrator->applied();
    }
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Database upgrade · Shanfix</title>
<style>
  :root { --navy:#0D2B4B; --navy-deep:#08203A; --green:#1B7A47; --ink:#12212F;
          --muted:#5A6B7D; --line:#DCE3EA; --paper:#FFF; --ground:#F3F6F9;
          --red:#B3261E; --amber:#8A5A00; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--ground); color:var(--ink);
         font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  .wrap { max-width:640px; margin:48px auto; padding:0 20px; }
  .card { background:var(--paper); border:1px solid var(--line); border-radius:12px;
          padding:28px; margin-bottom:20px; }
  h1 { margin:0 0 4px; font-size:21px; color:var(--navy); }
  .sub { color:var(--muted); margin:0 0 24px; font-size:14px; }
  h2 { font-size:14px; text-transform:uppercase; letter-spacing:.04em;
       color:var(--muted); margin:0 0 12px; }
  ul { list-style:none; margin:0; padding:0; }
  li { padding:9px 0; border-bottom:1px solid var(--line); font-size:14px;
       display:flex; justify-content:space-between; gap:12px; align-items:center; }
  li:last-child { border-bottom:0; }
  code { font-family:ui-monospace,Consolas,monospace; font-size:13px; word-break:break-all; }
  .tag { font-size:12px; padding:2px 9px; border-radius:20px; white-space:nowrap; }
  .tag--done { background:#E6F2EB; color:var(--green); }
  .tag--todo { background:#FDF3E0; color:var(--amber); }
  label { display:block; font-weight:600; font-size:13px; margin:14px 0 5px; }
  input { width:100%; padding:10px 12px; border:1px solid var(--line);
          border-radius:8px; font-size:15px; background:var(--paper); color:var(--ink); }
  input:focus { outline:2px solid var(--green); outline-offset:-1px; border-color:var(--green); }
  button { width:100%; margin-top:20px; padding:12px; background:var(--green);
           color:#fff; border:0; border-radius:8px; font-size:15px;
           font-weight:600; cursor:pointer; }
  button:hover { background:#166139; }
  .note { padding:12px 14px; border-radius:8px; font-size:14px; margin-bottom:18px; }
  .note--err { background:#FDECEA; color:var(--red); }
  .note--ok  { background:#E6F2EB; color:var(--green); }
  .note--dim { background:var(--ground); color:var(--muted); }
  .foot { color:var(--muted); font-size:13px; text-align:center; }
  a { color:var(--green); }
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1>Database upgrade</h1>
    <p class="sub">Applies any database changes that came with the latest code.</p>

    <?php if ($error !== null): ?>
      <div class="note note--err"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
      <?php if ($result['applied'] === [] && $result['failed'] === null): ?>
        <div class="note note--ok">Already up to date — there was nothing to apply.</div>
      <?php else: ?>
        <?php if ($result['applied'] !== []): ?>
          <div class="note note--ok">
            Applied <?= count($result['applied']) ?> migration<?= count($result['applied']) === 1 ? '' : 's' ?>.
          </div>
        <?php endif; ?>
        <?php if ($result['failed'] !== null): ?>
          <div class="note note--err">
            <strong><?= $e($result['failed']) ?></strong> failed, so nothing after it was applied.<br>
            <?= $e($result['error']) ?>
            <br><br><code><?= $e($result['statement']) ?></code>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($migrator !== null): ?>
      <h2><?= count($pending) ?> pending, <?= count($applied) ?> applied</h2>
      <ul>
        <?php foreach ($migrator->all() as $file): ?>
          <?php $name = basename($file); $done = in_array($name, $applied, true); ?>
          <li>
            <code><?= $e($name) ?></code>
            <span class="tag <?= $done ? 'tag--done' : 'tag--todo' ?>">
              <?= $done ? 'applied' : 'pending' ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php if ($migrator !== null && $pending !== []): ?>
    <div class="card">
      <h2>Sign in as an administrator to apply</h2>
      <form method="post" autocomplete="off">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required
               value="<?= $e($_POST['email'] ?? '') ?>">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
        <button type="submit">Apply <?= count($pending) ?> migration<?= count($pending) === 1 ? '' : 's' ?></button>
      </form>
    </div>
  <?php elseif ($migrator !== null): ?>
    <div class="card">
      <div class="note note--dim" style="margin:0">
        Nothing to do. You can delete <code>upgrade.php</code> from the server.
      </div>
    </div>
  <?php endif; ?>

  <p class="foot">Shanfix Technology · Business Management System</p>
</div>
</body>
</html>
