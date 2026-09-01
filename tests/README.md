# Tests

Integration tests. They drive a running copy of the system over HTTP and
check what landed in the database — nothing here mocks anything, because
the bugs this system actually has are of the kind mocks hide: a permission
that does not bite, a total that rounds the wrong way, a reminder sent
twice, a backup that cannot be restored.

## Running them

The system has to be running first:

```bash
php -S 127.0.0.1:8000 -t public dev-server.php
```

Then, from the project root:

```bash
./tests/run.sh              # everything
./tests/run.sh chat backup  # only those
```

Each suite can also be run on its own — `bash tests/chat_test.sh` — which
is what you want while fixing one thing, because the output is every
assertion rather than a tally.

## What they need

A **disposable** database. The suites create users, delete rows and
truncate tables; they will destroy anything they are pointed at.
`config.sh` refuses to run unless the database name contains `test`, `ci`
or `dev`, which is a guard rail and not a guarantee — do not defeat it.

Set it up the same way a deployment is:

```bash
mysql -u root -e "CREATE DATABASE shanfix_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root shanfix_test < database/schema.sql
mysql -u root shanfix_test < database/seed.sql
php migrate.php
```

Point `config/config.php` at it before running anything.

## Configuring

Everything is an environment variable with a sensible default, so the
suites run on another machine without being edited:

| Variable | Default | |
|---|---|---|
| `SHANFIX_URL` | `http://127.0.0.1:8000` | where the system is served |
| `SHANFIX_DB` | `shanfix_test` | the database to work against |
| `SHANFIX_DB_USER` | `root` | |
| `SHANFIX_DB_PASS` | *(none)* | |
| `MYSQL_BIN` | found on `PATH`, else the XAMPP path | the mysql client |
| `SHANFIX_ADMIN` | `admin@shanfix.co.ke` | the account they sign in as |
| `SHANFIX_ADMIN_PASS` | `Shanfix@2026` | |

```bash
SHANFIX_URL=http://localhost:8080 SHANFIX_DB=shanfix_ci ./tests/run.sh
```

## The suites

| | |
|---|---|
| `smoke_test.sh` | signing in, every main page, signing out |
| `roles_test.sh` | what each role may reach, and what several roles add up to |
| `approval_test.sh` | who may delete, and a price waiting for an administrator |
| `leads_test.sh` | the pipeline, and the actions that move a lead along it |
| `letters_test.sh` | company letters, and what reaches the printed page |
| `jobs_test.sh` | production: stages, files, assignment, the job sheet |
| `services_test.sh` | the service catalogue and what it costs |
| `renewals_test.sh` | subscriptions renewing and invoicing themselves |
| `meetings_test.sh` | scheduling, the room, reminders before it starts |
| `images_test.sh` | uploads, resizing, and what happens to a bad file |
| `notify_test.sh` | the message queue: sending, retrying, chasing once only |
| `whatsapp_test.sh` | the WhatsApp inbox and its 24-hour window |
| `paylink_test.sh` | public payment links and the STK push |
| `webhook_test.sh` | KopoKopo callbacks, including forged ones |
| `remember_test.sh` | staying signed in, and the token rotating |
| `chat_test.sh` | channel membership, and who gets told about a message |
| `backup_test.sh` | taking a copy, and proving it restores |
| `portal_test.sh` | the client portal: the three ways in, and what it refuses |
| `brief_test.sh` | asking a client what they want, and getting it back |
| `deploy_test.sh` | the address the site answers on, and what a push does |

`crawl.sh` is separate: it walks every GET route in `routes.php` as every
role and reports anything that is not a page or a redirect. It is looking
for 500s, so it needs no assertions of its own. Run it after any change
that touches a view or a controller.

## The one thing these suites cannot check

`deploy_test.sh` reads `.htaccess` and asserts the rules are present. It
cannot tell you Apache *obeys* them, because the dev server the rest of
the suites talk to is PHP's, which ignores `.htaccess` entirely. Those
rules are the only thing standing between the open web and
`config/config.php`, so read them against a real Apache after any change:

```sh
# A replica of the served layout, with no per-folder .htaccess at all,
# so the root rules are on their own.
RIG=/c/xampp/htdocs/_shanfix_rig
mkdir -p "$RIG"/{public,app/Core,config,database,storage,.well-known}
cp .htaccess public/.htaccess "$RIG"/ ...
```

Point a vhost at it with `AllowOverride All`, then check that
`/config/config.php`, `/database/seed.sql` and `/.git/config` are all
refused, that `/upgrade.php` runs, and that `/.well-known/...` is served
— that last one is certificate renewal, and it fails silently.

## Writing another one

Source `config.sh`, use its `eq` / `ne` / `has` and its `signin` / `post`
/ `page` helpers, and end with `report`. Two habits worth keeping:

**Scope what you count.** `SELECT COUNT(*) FROM notifications` climbs on
every run once another suite leaves a backlog behind. Count the rows this
suite caused — `WHERE entity_id = $INV` — or the number drifts and the
assertion starts failing for reasons that have nothing to do with the
code.

**Pin what you depend on.** A suite that assumes SMS is switched off
passes until another suite switches it on. Set the settings your
assertions rest on rather than inheriting whatever the last run left.
