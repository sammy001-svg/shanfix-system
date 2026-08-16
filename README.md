# Shanfix Technology — Business Management System

A complete business management system for a printing, branding and software company.
Built on plain PHP 8 and MySQL with **no Composer dependencies**, so it deploys to
cPanel by uploading files.

---

## What it does

| Module | Covers |
|---|---|
| **Dashboard** | Cash collected, outstanding balances, overdue invoices, hot deals, low stock, your follow-ups |
| **Leads** | Kanban pipeline (New → Contacted → Qualified → Proposal → Negotiation → Won/Lost), activity logging, follow-up reminders, one-click conversion to a client |
| **Clients** | Register clients, then raise quotations, invoices and receipts from their profile; full billing history and outstanding balance |
| **Quotations → Invoices → Receipts** | One document engine. Convert a quote to an invoice in a click, issue a receipt once paid. A4 print/PDF view with your logo |
| **Production** | Job cards from an invoice in one click. Board across Queued → Artwork → Proof Sent → Approved → In Production → Finishing → Ready → Delivered. Artwork/proof uploads with versioning, recorded client approval, shop-floor checklist, printable job card, per-job costing |
| **Delivery Notes** | Raised from a job card, printed with a signature line. Confirming receipt closes the job automatically |
| **Email & SMS** | Send quotations and invoices to clients, payment confirmations, automatic overdue chasing, and "your order is ready" texts. Every send is logged with its delivery status |
| **Client links** | Each document gets an unguessable link the client can open with no login, print, or save as PDF. You can see when they first opened it |
| **Inventory** | Stock items, cost vs selling price, margin, stock movement ledger, reorder alerts |
| **Services** | Rate card for web development, custom software, design, branding, marketing — fixed, hourly, monthly or quoted-per-project |
| **Payments** | M-Pesa STK Push via KopoKopo, plus manual bank/cash/cheque entry, reversals |
| **Expenses** | Categorised costs with receipt attachments, input VAT tracking, job costing |
| **Reports** | Income vs expenses, gross profit, receivables ageing, top clients, best sellers, VAT position, sales-team performance, CSV statement export |
| **Team Chat** | Direct messages and channels, file attachments, unread badges |
| **Admin** | Users and roles, company settings, document numbering, VAT defaults, categories, audit trail |

**Brand:** navy `#08203A` and green `#14874E`. Solid colours only — no gradients anywhere.

---

## Requirements

- PHP **8.0+** (tested on 8.2) with `pdo_mysql`, `mbstring`, `openssl`, `json`, `curl`, `fileinfo`
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (standard on cPanel)
- HTTPS — required for KopoKopo callbacks

---

## Installing on cPanel

### 1. Upload

The web root must point at `public/`, and everything else must sit **above** it:

```
/home/youruser/
├── shanfix/              <- upload the whole project here
│   ├── app/
│   ├── config/
│   ├── database/
│   ├── storage/
│   ├── routes.php
│   └── install.php
└── public_html/          <- contents of public/ go here
    ├── index.php
    ├── .htaccess
    └── assets/
```

**Simplest approach:** upload the whole folder to `/home/youruser/shanfix/`, then in
cPanel → *Domains* set the subdomain's document root to `/home/youruser/shanfix/public`.

If you cannot change the document root, copy the contents of `public/` into
`public_html/` and edit the first line of `public_html/index.php` to point at
wherever `app/bootstrap.php` lives.

### 2. Create the database

cPanel → *MySQL Databases*: create a database and a user, and grant that user
**All Privileges**. Note the name, user and password.

### 3. Run the installer

Over SSH:

```bash
cd ~/shanfix
php install.php
```

It checks requirements, writes `config/config.php`, generates the encryption key,
imports the schema and seed data, and lets you set the admin password.

**No SSH?** Do it manually:

1. Copy `config/config.sample.php` to `config/config.php` and fill in the database block.
2. Generate a key and paste it into `security.app_key`:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
3. In phpMyAdmin, import in this order:
   `database/schema.sql` → `database/seed.sql` → every file in `database/migrations/`.

### Upgrading an existing installation

New features ship as migrations. After uploading updated files:

```bash
php migrate.php --status   # see what is pending
php migrate.php            # apply it
```

Migrations are recorded in a `migrations` table, so running them twice is safe.

### 4. Sign in

```
Email:    admin@shanfix.co.ke
Password: Shanfix@2026
```

**Change this password immediately** under *My Profile*.

### 5. Configure

- **Settings → Company** — name, logo, KRA PIN, contacts. These print on every document.
- **Settings → Documents & VAT** — VAT rate (16% default), numbering prefixes, payment terms.
- **Settings → M-Pesa / KopoKopo** — see below.
- **Users & Roles** — add your team.

### 6. Delete `install.php` from the server.

---

## KopoKopo M-Pesa setup

STK Push sends a payment prompt to the client's phone; they enter their M-Pesa PIN
and the payment is recorded against the invoice automatically.

### Get your credentials

KopoKopo dashboard → **API Keys**. You need four values:

| Field | Where it comes from |
|---|---|
| Client ID | API Keys page |
| Client Secret | API Keys page |
| API Key | API Keys page — signs webhooks |
| Till Number | Your KopoKopo till (e.g. `K000000`), not the Safaricom till |

### Configure

1. **Settings → M-Pesa / KopoKopo**
2. Start with **Sandbox**.
3. Paste all four values. Secrets are encrypted with `app_key` before storage.
4. Leave *Callback URL* blank to use `https://yourdomain.co.ke/webhooks/kopokopo`.
5. Tick **Enable M-Pesa STK Push** and save.
6. Click **Test connection** — confirms your Client ID and Secret.
7. Click **Register callback URL** — tells KopoKopo where to send confirmations.

### Test it

Open an unpaid invoice → **Send STK Push**. The page polls for the result and updates
itself when the payment lands.

Switch *Environment* to **Production** once a sandbox payment completes end to end.

### If payments do not confirm

The system polls KopoKopo directly as a fallback, so a lost webhook is not fatal — but
check these:

- Callback URL is **HTTPS** and publicly reachable (not `localhost`, not behind Basic Auth)
- **API Key** is correct — callbacks with a bad signature are rejected with `401`
- `storage/logs/app-YYYY-MM-DD.log` records every KopoKopo request and failure
- Confirm the webhook is registered (Settings → *Register callback URL*)

---

## Email &amp; SMS setup

### Email

**Settings → Email &amp; SMS**. Use a mailbox you create in cPanel → *Email Accounts*:

| Field | Typical cPanel value |
|---|---|
| SMTP host | `mail.yourdomain.co.ke` |
| Port | `587` with STARTTLS, or `465` with SSL |
| Username | the **full** email address |
| Password | that mailbox's password |
| Send from | the same address |

Press **Send test email** — it connects, authenticates and delivers a real message.
If it fails, the exact server reply is shown, which is usually enough to diagnose it.

> Send from an address on your own domain. Using a Gmail or Yahoo address as the
> "from" while sending through your host's server gets messages marked as spam.

#### Staying out of the spam folder

Mail that is correctly formatted still lands in spam if the receiving server
cannot verify that your host is allowed to send for your domain. That is DNS
work, done once, in cPanel → *Zone Editor* (or *Email Deliverability*, which
sets up the first two for you and flags anything missing):

| Record | Why it matters |
|---|---|
| **SPF** | Lists the servers allowed to send for your domain. Without it, Gmail treats the mail as unverified. |
| **DKIM** | Cryptographically signs each message. cPanel → *Email Deliverability* → **Install** generates the key and record. |
| **DMARC** | Tells receivers what to do when SPF/DKIM fail. Start with `v=DMARC1; p=none; rua=mailto:you@yourdomain.co.ke`. |
| **rDNS / PTR** | Your host's job. On shared cPanel it is normally already correct. |

Then check the basics:

- **From** must be on the domain those records cover — `invoices@yourdomain.co.ke`,
  never a Gmail address.
- Send yourself a test, open **Show original** in Gmail, and confirm SPF, DKIM
  and DMARC all read `PASS`.
- A brand-new domain has no sending reputation. Expect the first few days to be
  shaky even with everything set correctly.

### SMS

SMS goes through our own platform, [Shanfix Bulk SMS](https://sms.shanfixtechnology.com).
Sign in there and open **API** to copy the two credentials:

| Field | Where it comes from |
|---|---|
| Client ID | API page — identifies the account |
| API key | API page — keep it secret, it is encrypted at rest here |
| Sender ID | One of your **approved** sender IDs, e.g. `SHANFIX` |

An unapproved sender ID is rejected by the gateway, so register it in the portal
first. Once saved, **Check credentials &amp; balance** on the settings page confirms
the connection and shows your remaining units without spending one.

SMS is billed in units per 160 characters — the settings page shows the length and
credit count for each template as you edit it.

### Bulk SMS campaigns

**Bulk SMS** in the sidebar sends one message to a whole group of clients — a price
change, a holiday closure, a promotion. Administrators and managers only, since a
campaign spends real credit across your entire client list.

Choose an audience — all active clients, companies or individuals only, anyone with
an unpaid balance, or anyone invoiced in the last 90 days — then press **Check
recipients &amp; cost**. Nothing is sent yet. You get the exact recipient count, the
credits per message, the total, and your live gateway balance, with a warning if the
balance will not cover it. Only then does the send button appear.

| Detail | Behaviour |
|---|---|
| Duplicates | A client listed twice under different formats (`0712…` and `+254712…`) is texted and billed once |
| Bad numbers | Anything that is not a valid Kenyan number is dropped before sending and listed as skipped |
| Double submit | Refreshing the confirmation page cannot send the campaign twice |
| Curly quotes | Flagged before you send — a single `’` pasted from Word cuts the limit from 160 characters to 70 and doubles the bill |

Every campaign is kept with its message, audience, counts, units charged and the
full recipient list, so you can answer "did Acme get that message?" later.

> "Delivered to gateway" means Shanfix Bulk SMS accepted it for sending. It is not
> proof the handset received it — the gateway reports totals per batch, not a
> status per number.

### Scheduled sending

Messages send immediately when you press a button. Overdue reminders and retries
need a cron job — cPanel → *Cron Jobs*, every 5 minutes:

```
/usr/local/bin/php /home/YOURUSER/shanfix/cron.php >/dev/null 2>&1
```

Run `php cron.php --verbose` by hand to watch what it does.

### What clients receive

An email containing the document itself — line items, totals, how to pay — plus a
button linking to a page they can open with no login and save as PDF. Nothing else
from your system is reachable from that link.

SMS carries a short version of the same link (`/v/…` rather than `/view/…`), which
keeps routine texts inside one 160-character credit instead of two.

Every attempt is recorded under **Messages**, with the failure reason if it did not
arrive, and a retry button.

### Proof approval

Moving a job to **Proof sent** emails and texts the client a link to the proof
itself. They open it with no login, check it, and either **Approve** or **Request
changes** with a note saying what to fix:

- Approving moves the job to *Approved* and clears it for production.
- Requesting changes sends it back to *Artwork* with their comments attached to
  the proof, ready for the designer.

Either way the decision is on record, and the job card shows whether it came from
the client online or was entered by a member of staff. The link is also shown on
the job card so you can resend it by WhatsApp — treat it as confidential, since
anyone holding it can approve the proof.

If no proof is waiting for approval, moving the job to *Proof sent* will say so
rather than asking the client to approve something that does not exist.

---

## Installing it as an app

The system is a PWA, so it installs on a phone, tablet or desktop and opens in
its own window with no browser chrome.

| Device | How |
|---|---|
| Android / Chrome | Menu → **Install app** (or the prompt that appears) |
| iPhone / iPad | Share → **Add to Home Screen** |
| Windows / Mac | The install icon in the address bar |

The launcher icon is generated from your uploaded logo, so it carries your own
branding with nothing extra to upload. **This needs HTTPS** — browsers refuse to
install a PWA or run a service worker over plain HTTP.

### Working offline

Pages you have opened stay readable with no signal, and the system pre-loads your
open jobs and recent clients when you sign in, so the job cards you need on the
shop floor are there even if you have not opened them that day.

A strip along the bottom tells you the connection is down and how many changes
are waiting on the device.

**What you can do offline:**

- Move a job between production stages
- Tick items off the production checklist
- Add notes to a job
- Record a client's proof decision

Each one is saved on the device and sent automatically the moment you are back on
the network — you do not have to remember to do anything.

**What still needs a connection:** anything that touches money or allocates a
document number — payments, invoices, quotations, receipts and delivery notes.
This is deliberate. Those actions take the next number in a sequence and move an
invoice balance, so two devices working offline would both claim the same invoice
number and a replayed payment could be banked twice. They stay online-only rather
than risk your books.

> **Shared devices:** signing out clears the cached pages and any unsent changes
> from that device. Sync before you hand the tablet over.

If a queued change cannot be sent — the job was deleted while you were offline,
say — it is discarded and you are told, rather than retried silently forever.

---

## Roles

| Role | Access |
|---|---|
| **Administrator** | Everything, including users and settings |
| **Manager** | All operations; no user or settings administration |
| **Finance** | Invoicing, payments, expenses, reports |
| **Sales** | Leads, clients, quotations, invoices |
| **Production** | Job cards, artwork, stages, delivery notes. **Cannot see job margins** or any finance module |
| **Staff** | Read-only across modules, plus team chat |

---

## Day-to-day flow

```
Lead registered  →  activities logged  →  follow-up reminders
      ↓ won
   Client  →  Quotation  →  (convert)  →  Invoice  →  STK Push / payment  →  Receipt
                                            ↓ raise job card
        Queued → Artwork → Proof Sent → Approved → In Production
                                            → Finishing → Ready → Delivered
                                                     ↓
                                            Delivery Note (signed)
```

- Quotations auto-expire based on your validity setting; converting one marks it accepted.
- Invoices become `overdue` automatically once past the due date with a balance.
- Marking an invoice paid by hand is deliberately not possible — record a payment instead,
  so the ledger always reconciles.
- Deleting anything with money attached archives it rather than erasing it.

### Production rules worth knowing

- **A job cannot reach the press with an unapproved proof.** Moving to *In Production*
  while a proof is still pending is blocked. There is a "proceed anyway" override for
  when the client approved verbally — ticking it is recorded in the job history.
- **Rejecting a proof sends the job back to Artwork** with the client's feedback attached,
  so the designer sees exactly what to change.
- **Proofs are versioned per job** (v1, v2, v3…) and approved proofs cannot be deleted by
  production staff — they are the record of client sign-off.
- **Confirming a delivery note closes the job card**, stamps `delivered_at`, and writes the
  recipient's name into the job history.
- **Job margins exclude VAT on both sides.** VAT you charge the client is owed to KRA and
  VAT you pay on materials is reclaimable, so neither counts as profit on the job.
- Uploaded files are checked so the contents match the extension — a text file renamed
  `.pdf` is rejected.

---

## Structure

```
app/
├── Core/            Router, Database, Auth, View, Session, Csrf, Crypto, Validator…
├── Controllers/     One per module
├── Services/        DocumentCalculator, PaymentPoster, KopoKopo
└── Views/           Plain PHP templates
config/              config.php (yours) + config.sample.php
database/
├── schema.sql       Base tables
├── seed.sql         Starter categories, services, stock
└── migrations/      Incremental changes, applied by migrate.php
public/              Web root — index.php, .htaccess, assets
storage/             logs/ and uploads/ (must be writable)
routes.php           Every route and its permissions
install.php          CLI installer — delete after use
migrate.php          Applies pending migrations
dev-server.php       Local development only; unused in production
```

---

## Security notes

- Every form is CSRF-protected; every query uses prepared statements.
- All view output is HTML-escaped.
- Passwords are bcrypt; 5 failed logins trigger a 15-minute lockout.
- KopoKopo secrets are AES-256-GCM encrypted at rest.
- Webhooks are authenticated by HMAC-SHA256 signature and are replay-safe.
- Uploads live outside the web root and are served through a permission-checked route
  with a path-traversal guard.
- A Content-Security-Policy blocks inline and third-party scripts.

**Keep `config/config.php` out of version control** — it holds your database password and
`app_key`. Changing `app_key` makes stored KopoKopo secrets unreadable; you would need to
re-enter them.

---

## Backups

Back up **both**:

```bash
mysqldump -u USER -p DATABASE > shanfix-$(date +%F).sql
tar -czf shanfix-storage-$(date +%F).tar.gz storage/uploads/
```

`storage/uploads/` holds your logo, expense receipts and chat attachments — these are not
in the database.

---

## Notes on what is and is not included

- **PDFs** are produced through the browser's print dialog ("Save as PDF") from the A4 print
  view. No PDF library is bundled, which keeps the system Composer-free.
- **Emails carry the document in the body, not as a PDF attachment.** Since no PDF library is
  bundled, the client gets a fully formatted invoice in the email plus a link to view and save
  it as PDF themselves. This renders correctly in Gmail, Outlook and Apple Mail, and avoids the
  spam filtering that attachments often attract.
- **Chat** uses AJAX polling every few seconds, not websockets — shared cPanel hosting cannot
  hold long-lived socket connections.
- **STK Push cannot be tested locally.** It needs a public HTTPS callback URL, so test it on
  the deployed site.

### Known gaps, not yet built

Two things worth knowing before you rely on the figures:

- **Stock is not decremented automatically.** Invoicing 50 branded t-shirts does not reduce
  inventory — the ledger only moves when someone adjusts it by hand. Stock levels, low-stock
  alerts and the stock valuation will drift from reality until this is added.
- **No recurring billing.** The monthly retainer services in the catalogue have to be invoiced
  manually every month.
