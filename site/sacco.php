<?php
/**
 * Sacco Pro — the savings and credit system we build and run.
 *
 * The point of this page is the Request a demo button on it. Everything
 * above that button exists to get somebody to press it, and everything
 * below is there for the ones who need convincing first.
 *
 * The form posts to api/demo-request.php, which keeps the request in the
 * site's own table and pushes it into the business system, where an
 * administrator reads it and sends back a login.
 *
 * The menu has pointed at software-solution.php#sacco for some time. No
 * such anchor exists on that page, so the link has been going nowhere;
 * it points here now.
 */
$pageSEO = [
    'title'       => 'Sacco Pro | SACCO Management System in Kenya - Shanfix Technology',
    'description' => 'Sacco Pro is a complete SACCO management system for Kenyan savings and credit societies — members, savings, loans, dividends, M-Pesa collections and SASRA-ready reporting. Request a free demo.',
    'keywords'    => 'SACCO management system Kenya, sacco software, savings and credit software, SASRA reporting, loan management Kenya, Sacco Pro',
    'canonical'   => '{{base}}/sacco.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"SoftwareApplication","name":"Sacco Pro","applicationCategory":"BusinessApplication","operatingSystem":"Web","description":"SACCO management system for Kenyan savings and credit societies: members, savings, loans, dividends, M-Pesa collections and SASRA-ready reporting.","offers":{"@type":"Offer","availability":"https://schema.org/InStock"},"provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"{{base}}/"},"areaServed":{"@type":"Country","name":"Kenya"},"url":"{{base}}/sacco.php"}',
];
include 'includes/header.php';

/* What it does, grouped the way a sacco manager thinks about the job
   rather than the way the software is built. */
$modules = [
    [
        'icon'  => 'users',
        'title' => 'Members',
        'body'  => 'Registration, KYC documents, next of kin, share capital and statements. Every member has one record and one history, from the day they joined.',
        'points' => ['Member onboarding and KYC', 'Share capital tracking', 'Statements on demand', 'Dormant and exited members'],
    ],
    [
        'icon'  => 'piggy',
        'title' => 'Savings & deposits',
        'body'  => 'Multiple products with their own rules — ordinary, fixed, holiday, school fees — each with its own interest basis and withdrawal terms.',
        'points' => ['Any number of savings products', 'Interest worked out per product', 'Standing orders and check-off', 'Withdrawal approval trail'],
    ],
    [
        'icon'  => 'loan',
        'title' => 'Loans',
        'body'  => 'From application to final repayment: appraisal, guarantors, committee approval, disbursement, schedules and arrears — with the rules enforced rather than remembered.',
        'points' => ['Appraisal against savings multiples', 'Guarantor exposure checked', 'Reducing balance or flat rate', 'Arrears ageing and provisioning'],
    ],
    [
        'icon'  => 'mpesa',
        'title' => 'M-Pesa & bank',
        'body'  => 'Paybill collections posted to the right member automatically, and bank statements reconciled against what the books say.',
        'points' => ['Paybill and till collections', 'Automatic member matching', 'B2C disbursement', 'Bank reconciliation'],
    ],
    [
        'icon'  => 'chart',
        'title' => 'Dividends & interest',
        'body'  => 'Work out dividends on share capital and interest on deposits, run them past the board, and post them once everybody agrees.',
        'points' => ['Dividend on share capital', 'Interest on deposits', 'Run, review, then post', 'Member-by-member breakdown'],
    ],
    [
        'icon'  => 'shield',
        'title' => 'Reporting & SASRA',
        'body'  => 'The returns a regulated sacco has to file, and the management accounts the board asks for, out of the same figures.',
        'points' => ['SASRA-shaped returns', 'Trial balance and P&L', 'Loan classification', 'Full audit trail'],
    ],
];

$icons = [
    'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'piggy' => '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    'loan'  => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
    'mpesa' => '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
    'chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'shield'=> '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
];

/* The objections, answered. Written as the questions a treasurer
   actually asks on the first call. */
$questions = [
    ['q' => 'Can it run our existing products, or do we change to suit it?',
     'a' => 'Your products, your rules. Savings and loan products are set up with their own interest basis, terms and limits — we configure them from your by-laws during setup, not the other way round.'],
    ['q' => 'What about the members we already have?',
     'a' => 'We migrate them. Members, share capital, savings balances and running loans come across with their history, and we reconcile the opening balances with your books before you go live.'],
    ['q' => 'Does it handle M-Pesa?',
     'a' => 'Yes — paybill collections post against the right member automatically, and disbursements go out the same way. Unmatched payments go to a holding account for somebody to assign rather than being guessed at.'],
    ['q' => 'Are we covered for SASRA?',
     'a' => 'The regulatory returns come out of the same figures as the management accounts, so what you file and what the board sees agree. We keep the formats current as they change.'],
    ['q' => 'Where does it run, and who holds the data?',
     'a' => 'Hosted by us on servers in the region, or on your own if you would rather. Either way the data is yours, backed up nightly, and you can have an export of it at any time.'],
    ['q' => 'What does support look like after go-live?',
     'a' => 'Training for your staff, a named person to call, and updates included. We are in Kenya, in your timezone, and we answer the phone.'],
];
?>
<link rel="stylesheet" href="<?= site_asset('sacco-modern.css') ?>">

<main class="sacco-page">

    <!-- ── Hero ─────────────────────────────────────────────────── -->
    <section class="sacco-hero">
        <div class="sacco-hero__glow" aria-hidden="true"></div>
        <div class="container sacco-hero__inner">
            <div class="sacco-hero__text">
                <span class="sacco-tag" data-aos="fade-up">Sacco Pro</span>
                <h1 data-aos="fade-up" data-aos-delay="80">
                    The system your <span>SACCO</span> runs on
                </h1>
                <p data-aos="fade-up" data-aos-delay="140">
                    Members, savings, loans, dividends, M-Pesa and the returns
                    you have to file — in one place, built for Kenyan savings
                    and credit societies and run by people you can ring.
                </p>

                <div class="sacco-hero__actions" data-aos="fade-up" data-aos-delay="200">
                    <button type="button" class="sacco-btn sacco-btn--primary" data-demo-open>
                        Request a demo
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none"
                             stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </button>
                    <a href="contact.php?service=Sacco%20Pro" class="sacco-btn sacco-btn--ghost">
                        Talk to us first
                    </a>
                </div>

                <ul class="sacco-hero__reassure" data-aos="fade-up" data-aos-delay="260">
                    <li>Free demo account</li>
                    <li>We migrate your members</li>
                    <li>Support in your timezone</li>
                </ul>
            </div>

            <div class="sacco-hero__panel" data-aos="fade-left" data-aos-delay="200" aria-hidden="true">
                <div class="sacco-panel">
                    <div class="sacco-panel__bar">
                        <span></span><span></span><span></span>
                        <em>Sacco Pro</em>
                    </div>
                    <div class="sacco-panel__body">
                        <div class="sacco-stat"><span>Members</span><strong>4,812</strong></div>
                        <div class="sacco-stat"><span>Deposits</span><strong>KES 612.4M</strong></div>
                        <div class="sacco-stat"><span>Loan book</span><strong>KES 448.9M</strong></div>
                        <div class="sacco-stat sacco-stat--good"><span>In arrears</span><strong>3.1%</strong></div>
                        <div class="sacco-bars">
                            <i style="height:42%"></i><i style="height:61%"></i><i style="height:52%"></i>
                            <i style="height:78%"></i><i style="height:69%"></i><i style="height:88%"></i>
                        </div>
                        <p class="sacco-panel__note">Illustration, not a real society.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── What it does ─────────────────────────────────────────── -->
    <section class="sacco-section">
        <div class="container">
            <div class="sacco-head" data-aos="fade-up">
                <span class="sacco-label">What it covers</span>
                <h2>Everything a sacco does, in one system</h2>
                <p>
                    Not six programs that have to be reconciled on a Friday.
                    One set of figures, one audit trail, one place to look.
                </p>
            </div>

            <div class="sacco-modules">
                <?php foreach ($modules as $i => $m): ?>
                <article class="sacco-module" data-aos="fade-up" data-aos-delay="<?= ($i % 3) * 70 ?>">
                    <span class="sacco-module__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <?= $icons[$m['icon']] ?>
                        </svg>
                    </span>
                    <h3><?= htmlspecialchars($m['title']) ?></h3>
                    <p><?= htmlspecialchars($m['body']) ?></p>
                    <ul>
                        <?php foreach ($m['points'] as $point): ?>
                            <li><?= htmlspecialchars($point) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ── Why it is worth changing ─────────────────────────────── -->
    <section class="sacco-section sacco-section--alt">
        <div class="container sacco-split">
            <div data-aos="fade-right">
                <span class="sacco-label">Why societies move</span>
                <h2>The month-end that takes a day instead of a week</h2>
                <p class="sacco-lede">
                    Most saccos we meet are running on a spreadsheet for savings,
                    a second one for loans, and somebody's memory for the rest.
                    It works until it does not — and it fails at exactly the
                    moment it matters, which is month-end or an audit.
                </p>

                <ul class="sacco-wins">
                    <li>
                        <strong>Interest and dividends worked out once</strong>
                        Not recalculated by hand each year, with a different answer each time.
                    </li>
                    <li>
                        <strong>Arrears you can see coming</strong>
                        Loans ageing into classification before they become a write-off.
                    </li>
                    <li>
                        <strong>A member answered in seconds</strong>
                        Their balance, their loan, their guarantors, without a phone call to the office.
                    </li>
                    <li>
                        <strong>Books that agree with the bank</strong>
                        Because the collections posted themselves when they arrived.
                    </li>
                </ul>
            </div>

            <div class="sacco-cta-card" data-aos="fade-left">
                <h3>See it with your own numbers</h3>
                <p>
                    We will set you up a demo account loaded with sample members,
                    savings and loans so you can click through the month-end you
                    actually run. It costs nothing and takes about a day to
                    arrange.
                </p>
                <button type="button" class="sacco-btn sacco-btn--primary sacco-btn--block" data-demo-open>
                    Request a demo
                </button>
                <p class="sacco-cta-card__note">
                    The login is sent to you by email and text.
                </p>
            </div>
        </div>
    </section>

    <!-- ── How getting started works ────────────────────────────── -->
    <section class="sacco-section">
        <div class="container">
            <div class="sacco-head" data-aos="fade-up">
                <span class="sacco-label">Getting started</span>
                <h2>Four steps, and we do most of them</h2>
            </div>

            <ol class="sacco-steps">
                <li data-aos="fade-up">
                    <span class="sacco-steps__n">1</span>
                    <h3>See it</h3>
                    <p>Ask for a demo. We set up an account and send you the link and login.</p>
                </li>
                <li data-aos="fade-up" data-aos-delay="70">
                    <span class="sacco-steps__n">2</span>
                    <h3>We set it to your by-laws</h3>
                    <p>Your savings and loan products, your interest rules, your approval limits.</p>
                </li>
                <li data-aos="fade-up" data-aos-delay="140">
                    <span class="sacco-steps__n">3</span>
                    <h3>Your members come across</h3>
                    <p>Balances migrated and reconciled against your books before you go live.</p>
                </li>
                <li data-aos="fade-up" data-aos-delay="210">
                    <span class="sacco-steps__n">4</span>
                    <h3>Training, then live</h3>
                    <p>Your staff trained on their own data, with somebody on the end of a phone.</p>
                </li>
            </ol>
        </div>
    </section>

    <!-- ── Questions ────────────────────────────────────────────── -->
    <section class="sacco-section sacco-section--alt">
        <div class="container sacco-narrow">
            <div class="sacco-head" data-aos="fade-up">
                <span class="sacco-label">Before you ask</span>
                <h2>The questions we get on the first call</h2>
            </div>

            <div class="sacco-faq">
                <?php foreach ($questions as $i => $qa): ?>
                <details class="sacco-faq__item" data-aos="fade-up" <?= $i === 0 ? 'open' : '' ?>>
                    <summary><?= htmlspecialchars($qa['q']) ?></summary>
                    <p><?= htmlspecialchars($qa['a']) ?></p>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ── Last word ────────────────────────────────────────────── -->
    <section class="sacco-final">
        <div class="container" data-aos="zoom-in">
            <h2>Have a look before you decide anything</h2>
            <p>
                A demo account, loaded with sample data, yours for a fortnight.
                No cost and no salesman sitting beside you.
            </p>
            <button type="button" class="sacco-btn sacco-btn--light" data-demo-open>
                Request a demo
            </button>
        </div>
    </section>
</main>

<!-- ── The demo form ───────────────────────────────────────────── -->
<div class="demo-modal" id="demoModal" hidden>
    <div class="demo-modal__backdrop" data-demo-close></div>

    <div class="demo-modal__panel" role="dialog" aria-modal="true"
         aria-labelledby="demoTitle" aria-describedby="demoBlurb">
        <button type="button" class="demo-modal__x" data-demo-close aria-label="Close">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                 stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <div class="demo-modal__body" id="demoFormWrap">
            <h2 id="demoTitle">Request a Sacco Pro demo</h2>
            <p id="demoBlurb">
                We will set up an account with sample data and send you the link
                and login — by email and by text, so you have it either way.
            </p>

            <form id="demoForm" novalidate>
                <input type="hidden" name="product" value="sacco">
                <input type="hidden" name="source" value="sacco.php">

                <!-- Robots fill in every field they find. People cannot see
                     this one. -->
                <div class="demo-hp" aria-hidden="true">
                    <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <div class="demo-grid">
                    <div class="demo-field">
                        <label for="d_name">Your name <span>*</span></label>
                        <input id="d_name" name="name" required autocomplete="name">
                    </div>

                    <div class="demo-field">
                        <label for="d_role">Your role</label>
                        <input id="d_role" name="role" autocomplete="organization-title"
                               placeholder="e.g. Treasurer">
                    </div>

                    <div class="demo-field demo-field--wide">
                        <label for="d_org">Sacco</label>
                        <input id="d_org" name="organisation" autocomplete="organization">
                    </div>

                    <div class="demo-field">
                        <label for="d_email">Email <span>*</span></label>
                        <input id="d_email" name="email" type="email" required autocomplete="email">
                    </div>

                    <div class="demo-field">
                        <label for="d_phone">Phone <span>*</span></label>
                        <input id="d_phone" name="phone" type="tel" required autocomplete="tel"
                               placeholder="07xx xxx xxx">
                    </div>

                    <div class="demo-field demo-field--wide">
                        <label for="d_members">Roughly how many members</label>
                        <select id="d_members" name="members">
                            <option value="">Prefer not to say</option>
                            <option value="under-500">Under 500</option>
                            <option value="500-2000">500 – 2,000</option>
                            <option value="2000-10000">2,000 – 10,000</option>
                            <option value="over-10000">Over 10,000</option>
                        </select>
                    </div>

                    <div class="demo-field demo-field--wide">
                        <label for="d_message">Anything you particularly want to see</label>
                        <textarea id="d_message" name="message" rows="3"
                                  placeholder="e.g. how check-off and M-Pesa collections post"></textarea>
                    </div>
                </div>

                <p class="demo-error" id="demoError" hidden></p>

                <button type="submit" class="sacco-btn sacco-btn--primary sacco-btn--block" id="demoSubmit">
                    Send the request
                </button>

                <p class="demo-small">
                    We use these details to set up and send you the demo. Nothing else.
                </p>
            </form>
        </div>

        <div class="demo-modal__body demo-done" id="demoDone" hidden>
            <span class="demo-done__tick" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="30" height="30" fill="none"
                     stroke="currentColor" stroke-width="2.4"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
            <h2>That is with us</h2>
            <p id="demoDoneMsg"></p>
            <button type="button" class="sacco-btn sacco-btn--ghost" data-demo-close>Close</button>
        </div>
    </div>
</div>

<script>
/* The demo form. Opened from any button marked data-demo-open, closed
   by the backdrop, the X, or Escape — all three, because a dialog that
   traps somebody is worse than no dialog. */
(function () {
    var modal  = document.getElementById('demoModal');
    var form   = document.getElementById('demoForm');
    var wrap   = document.getElementById('demoFormWrap');
    var done   = document.getElementById('demoDone');
    var msg    = document.getElementById('demoDoneMsg');
    var err    = document.getElementById('demoError');
    var submit = document.getElementById('demoSubmit');

    if (!modal || !form) { return; }

    var lastFocused = null;

    function open() {
        lastFocused = document.activeElement;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        var first = document.getElementById('d_name');
        if (first) { setTimeout(function () { first.focus(); }, 60); }
    }

    function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
        if (lastFocused && lastFocused.focus) { lastFocused.focus(); }
    }

    document.querySelectorAll('[data-demo-open]').forEach(function (b) {
        b.addEventListener('click', open);
    });
    document.querySelectorAll('[data-demo-close]').forEach(function (b) {
        b.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { close(); }
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        var data = {};
        new FormData(form).forEach(function (v, k) { data[k] = typeof v === 'string' ? v.trim() : v; });

        if (!data.name || !data.email || !data.phone) {
            err.textContent = 'We need your name, email and phone — the login goes to both.';
            err.hidden = false;
            return;
        }

        err.hidden = true;
        submit.disabled = true;
        var original = submit.textContent;
        submit.textContent = 'Sending…';

        try {
            var res = await fetch('api/demo-request.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(data)
            });
            var out = await res.json();

            if (out.success) {
                msg.textContent = out.message;
                wrap.hidden = true;
                done.hidden = false;
            } else {
                err.textContent = out.message || 'Something went wrong. Please try again.';
                err.hidden = false;
            }
        } catch (ex) {
            err.textContent = 'We could not reach the server. Please check your connection and try again.';
            err.hidden = false;
        }

        submit.disabled = false;
        submit.textContent = original;
    });
}());
</script>

<?php include 'includes/footer.php'; ?>
