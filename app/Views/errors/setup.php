<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup required · Shanfix Technology</title>
<style>
  body { font: 15px/1.6 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
         background: #F3F6F9; color: #0F1E2E; margin: 0; padding: 48px 20px; }
  .box { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #DDE4EC;
         border-radius: 11px; overflow: hidden; }
  .box__head { background: #08203A; color: #fff; padding: 20px 26px; }
  .box__head h1 { margin: 0; font-size: 19px; }
  .box__head p { margin: 4px 0 0; color: rgba(255,255,255,.7); font-size: 13.5px; }
  .box__body { padding: 26px; }
  ol { margin: 0; padding-left: 20px; }
  li { margin-bottom: 14px; }
  code { background: #F3F6F9; border: 1px solid #DDE4EC; border-radius: 4px;
         padding: 2px 6px; font-family: Consolas, Menlo, monospace; font-size: 13px; }
  pre { background: #08203A; color: #D5EFE0; padding: 13px 15px; border-radius: 7px;
        overflow-x: auto; font-size: 12.5px; }
  .note { background: #EDF8F2; border: 1px solid #D5EFE0; color: #0B5730;
          padding: 12px 15px; border-radius: 7px; font-size: 13.5px; margin-top: 20px; }
</style>
</head>
<body>
<div class="box">
  <div class="box__head">
    <h1>Shanfix Technology — setup required</h1>
    <p>The application has not been configured yet.</p>
  </div>
  <div class="box__body">
    <ol>
      <li>
        Copy <code>config/config.sample.php</code> to <code>config/config.php</code>.
      </li>
      <li>
        Fill in your cPanel MySQL database name, username and password.
      </li>
      <li>
        <em>Optional.</em> Pin your own encryption key in <code>security.app_key</code> —
        otherwise one is generated into <code>storage/app.key</code> when it is first needed:
        <pre>php -r "echo bin2hex(random_bytes(32));"</pre>
      </li>
      <li>
        Import the database schema, then the starter data:
        <pre>database/schema.sql
database/seed.sql</pre>
        Use phpMyAdmin in cPanel, or run <code>php install.php</code> from SSH.
      </li>
      <li>Reload this page.</li>
    </ol>

    <div class="note">
      <strong>Default sign-in:</strong> admin@shanfix.co.ke / Shanfix@2026 —
      change this password immediately after your first login.
    </div>
  </div>
</div>
</body>
</html>
