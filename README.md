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
| **Proposals → Quotations → Invoices → Receipts** | One document engine. Convert a quote to an invoice in a click, issue a receipt once paid. A4 print/PDF view with your logo |
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

## Statements of account

**Statement** on any client page shows their whole account: every invoice raised
and every payment received in date order, with a running balance, and an ageing
summary of what is still owed.

| Bucket | Meaning |
|---|---|
| Not yet due | Invoiced, but the due date has not passed |
| 1 – 30 days | Just overdue — usually a reminder is enough |
| 31 – 60 days | Needs chasing |
| 61 – 90 days | Needs a phone call |
| Over 90 days | Highlighted in red; treat as at risk |

An invoice with no due date ages from its issue date — no terms means due on
issue, not "never overdue".

Print it, or press **Client link** to get a share link the client opens with no
login, exactly like a shared invoice. The link shows their full history and is
generated the first time you open the statement, so a client you have never sent
one to has no link that could leak.

Narrow the period with `?from=2026-01-01&to=2026-03-31` on the URL; anything
before the start date is folded into a single *balance brought forward* line so
the figures still add up.

Drafts and cancelled invoices never appear, and receipts are left out
deliberately — a receipt acknowledges a payment that is already on the statement
as a credit, so including it would show the money twice.

### Sending statements

**Send now** on the statement page emails or texts it, on whichever channels you
tick. The message carries the balance and the ageing breakdown, so the client can
see where they stand without opening anything — the link is there for the detail.
The page shows when you last sent it and whether the client has opened it.

For the monthly run, set **Statement day** in Settings → Email &amp; SMS. On that
day of each month, every active client carrying a balance is sent their statement
automatically. Set it to `0` to switch the run off.

Each client is sent one statement per calendar month. The lock is keyed by month
rather than by date, so if cron misses its day — server down, or outside the
sending window — it catches up on the next run instead of skipping the month or
sending twice.

> SMS is off by default for statements while email is on, since a statement is a
> document rather than a one-line alert. Turn SMS on per event in Settings if you
> want the balance texted too.

---

## Stock and sales

Inventory lines on an invoice move real stock. Add *500 blue pens* to an invoice
and issue it, and the pen count drops by 500 — with a movement recorded against
that invoice, so the ledger explains every change.

| When | What happens |
|---|---|
| Invoice issued | Stock goes out, cost price captured on the line |
| Invoice still a draft | Nothing moves — nothing has been sold yet |
| Issued invoice edited | Everything is put back, then taken out again at the new quantities |
| Invoice cancelled or deleted | Stock is returned |
| Quotation or receipt | Never moves stock |

That last row matters: a quotation is an offer, not a sale, and a receipt is a
copy of an invoice that already moved the goods. Counting either would take the
same stock out twice.

**Overselling is allowed, not blocked.** If the count says 20 and you invoice 30,
the sale goes through and the count goes to −10 with a warning. A wrong count
should not stop you invoicing a customer standing at the counter — but a negative
number is visible and gets fixed, where a silently blocked sale does not. You are
also warned when an item drops to its reorder level.

**Cost is captured at the moment of sale**, on the invoice line, not read from the
item later. Margin worked out next year then uses the price that applied when the
goods actually left, which is the only figure that means anything.

> Invoices raised **before** this was added are not back-dated. Doing so would
> double-count against the manual adjustments that were keeping the counts honest
> until now. Take a stock count when you deploy it and adjust once.

---


---

## Buying: suppliers and purchase orders

Stock used to arrive only through a manual adjustment, and `cost_price` was
whatever somebody typed — so every margin figure rested on a guess. Purchasing
replaces that with a real trail.

**Suppliers** hold contact details, KRA PIN and payment terms. A supplier you
have bought from is retired rather than deleted, so old orders keep their name.

**Raise a purchase order**, mark it *ordered* when it goes to the supplier, then
book the goods in when they arrive. Only an order that has been placed can
receive goods — a draft is still a working document.

### What happens when goods arrive

| | |
|---|---|
| Stock lines | Quantity goes up, and a movement is recorded against the order |
| Non-stock lines | Delivery charges and the like are costed, but nothing goes on a shelf |
| Part deliveries | Enter what actually turned up; the order sits at *partial* until the rest arrives |
| Over-delivery | Capped at what was outstanding, with a warning — a supplier sending extra is a conversation, not a silent stock gain |

### Cost prices look after themselves

Receiving updates `cost_price` as a **weighted average**. Hold 900 pens at KES 10
and take in 100 at KES 20, and the cost becomes KES 11 — not KES 20. A single
delivery at a new price does not rewrite the value of everything already on the
shelf; it moves the average in proportion to how much of each you hold.

That number is what gets stamped onto an invoice line when you sell, so margin is
worked out against what the goods actually cost you.

> Receiving is deliberately separate from paying. The order records what you
> bought and what it cost; recording the money going out is still an expense
> against that supplier.


---

## Proposals and agreements

Two more document types on the same engine as quotations and invoices, so they
share numbering, client links, VAT, printing and the share link.

### Proposal → Quotation

A proposal is the written pitch **and** the price. It opens with your house
headings — introduction, understanding of the requirement, approach, scope, what
is not included, timeline — which you edit once in Settings rather than retyping
on every job. Underneath sit ordinary priced lines, picked from your inventory
and services in the usual way.

Press **Convert to quotation** and the pricing carries straight across. The two
stay linked, so the quotation shows what it came from and the proposal shows what
it became. The narrative does not follow: a quotation is a price, and the case for
the work has already been made.

The quotation then converts to an invoice exactly as before, giving a full chain:

```
Proposal → Quotation → Invoice → Receipt
```

### Purchase agreements

**Draw up agreement** on a proposal or a quotation drafts the contract from what
was actually agreed, so the scope and the price are the ones already discussed
rather than retyped. It starts from a standard set of clauses — parties, services,
fees, client responsibilities, intellectual property, confidentiality, variations,
termination and governing law — with the two company names already filled in.
Edit them to fit the work before sending.

### How a client accepts

Send the agreement and the client opens it on the same kind of share link as an
invoice. They read the clauses, type their full name, tick the confirmation and
accept.

What makes that stand as evidence is not the click but the record of it: **who
typed their name, when, and from which address**, stored against the agreement and
printed alongside the clauses. An agreement already accepted cannot be accepted
again, so a forwarded link or a stale tab cannot produce a second, contradictory
record.

If the client would rather sign on paper, print it — an unaccepted agreement
carries signature blocks for both parties, and those disappear once it has been
accepted online.


---

## Sales: allocation and visibility

### A dashboard for selling

Someone whose job is sales gets their own dashboard instead of the general one:
their open pipeline by stage with values, follow-ups due today or overdue,
proposals and quotations still waiting on a client, and — most usefully — the
leads **going quiet**, meaning nothing has been logged against them for a
fortnight.

Every figure is scoped to leads allocated to that person, so each one can be
opened and acted on. A manager who also holds the sales role keeps the full
dashboard, since they need the whole picture to allocate work.

### Who sees which leads

| Role | Sees |
|---|---|
| Administrator, Manager, Reception | Every lead |
| Sales, Staff | Only leads allocated to them |

Reception is included because they log walk-ins and phone enquiries before
anyone owns them.

The scoping is applied in the query, not filtered afterwards, so a lead someone
may not see never reaches their page or their totals. Opening one by URL is
guarded the same way, and reads as *not found* rather than *forbidden* — there is
no reason to confirm to someone that a lead exists but is not theirs.

### Allocating a lead to several people

A lead has an **owner** — the name in the list, who takes the follow-up
reminders — plus anyone else ticked under *Also working this lead*. A technical
lead can pair with an account manager, someone can cover a colleague on leave,
and a big account can carry two people.

Everyone allocated sees it on their own board and dashboard. The owner is always
included automatically, so the name on the list always belongs to someone who can
actually open it.

### What sales can reach

Sales handles leads, clients, proposals, quotations, invoices, receipts,
agreements, payments, WhatsApp, chat and meetings. They can **see** job cards,
deliveries and recurring services so they can answer a client, but running
production, raising delivery notes and changing a recurring arrangement belong to
production and finance respectively.

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
