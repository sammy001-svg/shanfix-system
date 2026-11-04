<?php
/**
 * The partner programme.
 *
 * Written for one reader: somebody who already sells to businesses and
 * is deciding whether sending them to us is worth their time. They want
 * four things answered before they will fill in a form — what do I get,
 * when do I get it, what do I have to do, and are you going to cut me
 * out once you have met my customer. The page answers them in that
 * order and then asks.
 *
 * The numbers are not written into this page. Tiers, their thresholds,
 * the benefits and the standard rate are read live from the business
 * system, where an administrator edits them under Partners → Tiers. A
 * website holding its own copy is a website that eventually advertises
 * terms we have stopped offering.
 */
$pageSEO = [
    'title'       => 'Partner Programme | Earn Commission With Shanfix Technology',
    'description' => 'Become a Shanfix Technology partner in Kenya or anywhere else. Introduce customers to our business software, printing and branding, and earn commission on every invoice they pay — for as long as they stay. Tiered rates, paid monthly, in your own currency.',
    'keywords'    => 'partner programme Kenya, reseller programme, referral commission Kenya, software reseller Africa, become a partner, affiliate programme Kenya, channel partner East Africa',
    'canonical'   => '{{base}}/partners.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"WebPage","name":"Partner Programme","description":"Introduce customers to Shanfix Technology and earn commission on every invoice they pay.","provider":{"@type":"Organization","name":"Shanfix Technology","url":"{{base}}/"},"url":"{{base}}/partners.php"}',
];
include 'includes/header.php';

require_once __DIR__ . '/includes/system.php';

$programme = system_partner_programme();

$tiers    = $programme['tiers'];
$rate     = $programme['rate'];
$currency = $programme['currency'];
$open     = $programme['open'];
$global   = $programme['international'];

/* Where our partners already are. Said only when there is something
   true to say — a page claiming to be international on the strength of
   nobody is worse than one that does not mention it. */
$markets = $programme['markets'];

/* The top published rate, for the headline. Read from the tiers rather
   than typed, so raising Gold to 20% raises what the page promises. */
$topRate = $rate;
foreach ($tiers as $tier) {
    $topRate = max($topRate, $tier['rate']);
}

$money = static fn(float $n): string => number_format($n);

/* ── What a partner actually does, and what we do ──────────────────── */
$kinds = [
    [
        'code'  => 'referral',
        'title' => 'Refer',
        'body'  => 'You know the business and you make the introduction. '
                 . 'We quote, deliver and support; you earn on it. Nothing '
                 . 'else is asked of you.',
        'for'   => 'Consultants, accountants, architects, anyone with a client list',
    ],
    [
        'code'  => 'reseller',
        'title' => 'Resell',
        'body'  => 'You sell under your own name in your own market, at your '
                 . 'own prices. We build, host and keep it running behind '
                 . 'you, and the customer is yours.',
        'for'   => 'Agencies and IT firms with their own sales team',
    ],
    [
        'code'  => 'implementation',
        'title' => 'Implement',
        'body'  => 'You deploy and train on our systems where you are. We '
                 . 'certify your people, hand over the documentation and '
                 . 'take the engineering.',
        'for'   => 'Systems integrators and local delivery partners',
    ],
    [
        'code'  => 'affiliate',
        'title' => 'Promote',
        'body'  => 'You have an audience rather than a client list. Your '
                 . 'link is tracked, and anybody who arrives through it and '
                 . 'buys is counted as yours.',
        'for'   => 'Publishers, trainers and communities',
    ],
];

/* ── What there is to sell ─────────────────────────────────────────── */
$sell = [
    ['title' => 'Business systems',
     'body'  => 'Sacco Pro, school management, point of sale, ERP, event '
              . 'ticketing and bulk SMS. Sold once and renewed every year '
              . 'after, which is where a partner actually makes their money.',
     'link'  => 'software-solution.php'],
    ['title' => 'Printing and branding',
     'body'  => 'Everything from business cards to vehicle wraps and '
              . 'signage. Fast repeat business: a company that brands its '
              . 'first branch brands the next four.',
     'link'  => 'printing-branding.php'],
    ['title' => 'Websites and software',
     'body'  => 'Corporate sites, web applications, mobile apps and the '
              . 'hosting under them. Bigger single jobs, with support and '
              . 'hosting renewing behind each one.',
     'link'  => 'web-development.php'],
];

/* ── The four questions, answered ──────────────────────────────────── */
$faqs = [
    ['q' => 'When am I actually paid?',
     'a' => 'When your customer pays us, not when we invoice them. We work '
          . 'out what you have earned at the end of each month and pay it '
          . 'the following month against an invoice from you. We never owe '
          . 'you money we have not collected ourselves, and you are never '
          . 'waiting on a debt somebody else refused to settle.'],
    ['q' => 'Is it one commission, or every time they buy?',
     'a' => 'Every time. A customer belongs to the partner who introduced '
          . 'them, not to a single job. You earn on the first invoice and '
          . 'on every one after it, including repeat work years later, for '
          . 'as long as that customer stays with us.'],
    ['q' => 'Will you go round me once you have met my customer?',
     'a' => 'No, and the system is built so that it cannot happen by '
          . 'accident: a customer is tagged to you the day they are '
          . 'registered, and nobody here can re-tag an existing customer to '
          . 'somebody else. Every invoice raised against them earns you '
          . 'commission automatically, whether you were in the room or not.'],
    ['q' => 'Can I see what I am owed?',
     'a' => 'You get your own sign-in. It shows every customer you have '
          . 'introduced, every invoice they have been sent, what each one '
          . 'earned you, and what has been paid out and when. You can print '
          . 'a statement for any month and invoice us against it.'],
    ['q' => 'I am not in Kenya. Does that work?',
     'a' => 'Yes. We pay partners by international transfer, Wise or PayPal '
          . 'in your own currency, at the rate on the day the payment is '
          . 'made — which is shown on your statement alongside what you '
          . 'earned. Tell us where you are when you apply and the rest '
          . 'follows from it.'],
    ['q' => 'Does it cost anything to join?',
     'a' => 'No. There is no fee, no stock to buy and no minimum. If you '
          . 'never introduce anybody, you never owe us anything.'],
    ['q' => 'What do you expect of me?',
     'a' => 'The introduction, and honesty about what we can do. You do not '
          . 'have to demo the product, write the quotation or handle '
          . 'support — though if you want to, there is a tier for that and '
          . 'it pays more.'],
];

/* ── How it goes, start to first payment ───────────────────────────── */
$steps = [
    ['n' => '01', 'title' => 'Apply',
     'body' => 'A short form: who you are, where you are, who you sell to. '
             . 'Ten minutes.'],
    ['n' => '02', 'title' => 'We say yes',
     'body' => 'Somebody reads it and comes back to you. If it fits, you '
             . 'get a sign-in and your own partner code.'],
    ['n' => '03', 'title' => 'Introduce somebody',
     'body' => 'Register the customer in your portal, or just send them to '
             . 'us quoting your code. They are tagged to you from that day.'],
    ['n' => '04', 'title' => 'Get paid',
     'body' => 'The moment they settle an invoice, your share appears in '
             . 'your portal. Paid out the following month.'],
];
?>
<link rel="stylesheet" href="<?= site_asset('partners-programme.css') ?>">

<main class="pp-page">

    <!-- ── Hero ─────────────────────────────────────────────────── -->
    <section class="pp-hero">
        <div class="pp-hero__glow" aria-hidden="true"></div>
        <div class="container pp-hero__inner">
            <div class="pp-hero__text">
                <span class="pp-tag" data-aos="fade-up">Partner Programme</span>

                <h1 data-aos="fade-up" data-aos-delay="80">
                    Bring us the customer.<br>
                    <span>Earn on everything they ever spend.</span>
                </h1>

                <p data-aos="fade-up" data-aos-delay="140">
                    <?= htmlspecialchars($programme['intro'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <div class="pp-hero__actions" data-aos="fade-up" data-aos-delay="200">
                    <?php if ($open): ?>
                        <a href="/partners/apply" class="pp-btn pp-btn--primary" rel="nofollow">
                            Become a partner
                            <svg viewBox="0 0 24 24" width="17" height="17" fill="none"
                                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                            </svg>
                        </a>
                    <?php else: ?>
                        <a href="contact.php?service=Partner%20Programme" class="pp-btn pp-btn--primary">
                            Talk to us about partnering
                        </a>
                    <?php endif; ?>
                    <a href="#how" class="pp-btn pp-btn--ghost">How it works</a>
                </div>

                <ul class="pp-hero__reassure" data-aos="fade-up" data-aos-delay="260">
                    <li>No joining fee</li>
                    <li>Paid monthly</li>
                    <li><?= $global ? 'Anywhere in the world' : 'Across Kenya' ?></li>
                </ul>
            </div>

            <?php // The three numbers somebody is actually weighing up,
                  // and the top rate among them is read from the tiers
                  // rather than typed here. ?>
            <div class="pp-hero__figures" data-aos="fade-left" data-aos-delay="200">
                <div class="pp-fig">
                    <strong><?= htmlspecialchars(rtrim(rtrim(number_format($topRate, 1), '0'), '.'), ENT_QUOTES, 'UTF-8') ?>%</strong>
                    <span>Top commission rate</span>
                </div>
                <div class="pp-fig">
                    <strong>Every invoice</strong>
                    <span>Not just the first one</span>
                </div>
                <div class="pp-fig">
                    <strong>Monthly</strong>
                    <span>Paid in your own currency</span>
                </div>
            </div>
        </div>
    </section>

    <?php // ---- Where we already have partners -----------------------
          // Only when there is something true to say. A page claiming to
          // be international on the strength of nobody is worse than one
          // that does not mention it. ?>
    <?php if ($global && count($markets) > 1): ?>
        <section class="pp-markets">
            <div class="container">
                <span class="pp-markets__label">Partners working with us in</span>
                <ul class="pp-markets__list">
                    <?php foreach ($markets as $market): ?>
                        <li><?= htmlspecialchars($market, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <!-- ── The deal, in one line each ───────────────────────────── -->
    <section class="pp-section" id="how">
        <div class="container">
            <div class="pp-head" data-aos="fade-up">
                <span class="pp-label">The arrangement</span>
                <h2>Three things that make this worth your time</h2>
                <p>
                    Most referral schemes pay once, pay late, and quietly
                    stop paying when the introduction gets old. This one is
                    built the other way round, and the system enforces it
                    rather than somebody remembering to.
                </p>
            </div>

            <div class="pp-deal">
                <article class="pp-deal__item" data-aos="fade-up">
                    <div class="pp-deal__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                    </div>
                    <h3>The customer is yours, permanently</h3>
                    <p>
                        A customer is tagged to the partner who introduced
                        them — not to the job. Every invoice we ever raise
                        against that customer earns you commission, including
                        repeat work years later. Nobody here can re-tag an
                        existing customer to somebody else; the system refuses.
                    </p>
                </article>

                <article class="pp-deal__item" data-aos="fade-up" data-aos-delay="80">
                    <div class="pp-deal__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>
                        </svg>
                    </div>
                    <h3>You earn when they pay, not when we invoice</h3>
                    <p>
                        Commission lands the day the money does, and a part
                        payment earns a proportional part. It means we never
                        promise you a figure we have not collected — and it
                        means a customer who drags their feet is our problem
                        to chase, not a number that sits in your portal
                        going nowhere.
                    </p>
                </article>

                <article class="pp-deal__item" data-aos="fade-up" data-aos-delay="160">
                    <div class="pp-deal__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <rect x="3" y="4" width="18" height="16" rx="2"/>
                            <path d="M3 10h18"/><path d="M8 15h5"/>
                        </svg>
                    </div>
                    <h3>You can see the whole ledger</h3>
                    <p>
                        Your own sign-in shows every customer you introduced,
                        every invoice they were sent, what each one earned
                        you, and what has been paid out. Print a statement for
                        any month and invoice us against it. You are never
                        asking us what you are owed.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- ── Ways to partner ──────────────────────────────────────── -->
    <section class="pp-section pp-section--alt">
        <div class="container">
            <div class="pp-head" data-aos="fade-up">
                <span class="pp-label">Four ways in</span>
                <h2>However much of it you want to do</h2>
                <p>
                    Some partners make an introduction and leave the rest to
                    us. Others sell, deploy and support under their own name.
                    Both are worth having, and they are not paid the same.
                </p>
            </div>

            <div class="pp-kinds">
                <?php foreach ($kinds as $i => $kind): ?>
                    <article class="pp-kind" data-aos="fade-up" data-aos-delay="<?= $i * 70 ?>">
                        <h3><?= htmlspecialchars($kind['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= htmlspecialchars($kind['body'], ENT_QUOTES, 'UTF-8') ?></p>
                        <span class="pp-kind__for">
                            <?= htmlspecialchars($kind['for'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php // ---- Tiers ------------------------------------------------
          // Straight from the system. If an administrator switches a tier
          // off or changes what it promises, this changes with it. ?>
    <?php if ($tiers): ?>
        <section class="pp-section" id="tiers">
            <div class="container">
                <div class="pp-head" data-aos="fade-up">
                    <span class="pp-label">Tiers</span>
                    <h2>The more you bring, the more of it you keep</h2>
                    <p>
                        Everybody starts at the first tier. You move up on
                        what your customers have actually been invoiced over
                        the last twelve months — not on a target somebody set
                        you, and not on a promise made at signing.
                    </p>
                </div>

                <div class="pp-tiers">
                    <?php foreach ($tiers as $i => $tier): ?>
                        <?php $top = $i === count($tiers) - 1; ?>
                        <article class="pp-tier <?= $top ? 'pp-tier--top' : '' ?>"
                                 data-aos="fade-up" data-aos-delay="<?= $i * 70 ?>">
                            <?php if ($top): ?>
                                <span class="pp-tier__flag">Top tier</span>
                            <?php endif; ?>

                            <h3><?= htmlspecialchars($tier['name'], ENT_QUOTES, 'UTF-8') ?></h3>

                            <div class="pp-tier__rate">
                                <strong><?= htmlspecialchars(rtrim(rtrim(number_format($tier['rate'], 1), '0'), '.'), ENT_QUOTES, 'UTF-8') ?>%</strong>
                                <span>commission</span>
                            </div>

                            <p class="pp-tier__from">
                                <?php if ($tier['from'] <= 0): ?>
                                    Where everybody starts
                                <?php else: ?>
                                    From <?= htmlspecialchars($currency . ' ' . $money($tier['from']), ENT_QUOTES, 'UTF-8') ?>
                                    introduced in 12 months
                                <?php endif; ?>
                            </p>

                            <?php if ($tier['benefits']): ?>
                                <ul class="pp-tier__list">
                                    <?php foreach ($tier['benefits'] as $benefit): ?>
                                        <li><?= htmlspecialchars($benefit, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="pp-tiers__note" data-aos="fade-up">
                    Rates above are the standard ones for each tier. Some
                    services carry a higher rate of their own, and a tier
                    never reduces what a service already pays.
                </p>
            </div>
        </section>
    <?php endif; ?>

    <!-- ── What there is to sell ────────────────────────────────── -->
    <section class="pp-section pp-section--alt">
        <div class="container">
            <div class="pp-head" data-aos="fade-up">
                <span class="pp-label">What you are selling</span>
                <h2>Three lines of work, all of them repeat business</h2>
            </div>

            <div class="pp-sell">
                <?php foreach ($sell as $i => $item): ?>
                    <article class="pp-sell__item" data-aos="fade-up" data-aos-delay="<?= $i * 80 ?>">
                        <h3><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= htmlspecialchars($item['body'], ENT_QUOTES, 'UTF-8') ?></p>
                        <a href="<?= htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') ?>" class="pp-sell__link">
                            See what we do
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none"
                                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                            </svg>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ── How it goes ──────────────────────────────────────────── -->
    <section class="pp-section">
        <div class="container">
            <div class="pp-head" data-aos="fade-up">
                <span class="pp-label">Getting started</span>
                <h2>From applying to your first payment</h2>
            </div>

            <ol class="pp-steps">
                <?php foreach ($steps as $i => $step): ?>
                    <li class="pp-step" data-aos="fade-up" data-aos-delay="<?= $i * 70 ?>">
                        <span class="pp-step__n"><?= htmlspecialchars($step['n'], ENT_QUOTES, 'UTF-8') ?></span>
                        <h3><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= htmlspecialchars($step['body'], ENT_QUOTES, 'UTF-8') ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <?php // ---- International ----------------------------------------
          // Its own section because it is the question an overseas reader
          // has from the first line, and a sentence buried in the FAQ
          // does not answer it. ?>
    <?php if ($global): ?>
        <section class="pp-section pp-section--alt" id="international">
            <div class="container pp-intl">
                <div class="pp-intl__text" data-aos="fade-up">
                    <span class="pp-label">Outside Kenya</span>
                    <h2>We pay partners wherever they are</h2>
                    <p>
                        We are a Kenyan company and most of our partners are
                        here, but nothing about the arrangement depends on
                        that. If you are somewhere else, this is what changes
                        and what does not.
                    </p>
                </div>

                <div class="pp-intl__grid">
                    <div class="pp-intl__card" data-aos="fade-up">
                        <h3>Paid in your currency</h3>
                        <p>
                            By international transfer, Wise or PayPal. Your
                            statement shows what you earned in
                            <?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?>,
                            the rate used, and what reached your account — so
                            the two figures never need explaining.
                        </p>
                    </div>
                    <div class="pp-intl__card" data-aos="fade-up" data-aos-delay="70">
                        <h3>Your own tax position</h3>
                        <p>
                            We ask for your tax number by the name your own
                            authority uses, not ours. Where Kenyan law
                            requires something to be withheld and remitted on
                            your behalf, it is shown on your statement as a
                            separate line, and we give you the certificate
                            for it.
                        </p>
                    </div>
                    <div class="pp-intl__card" data-aos="fade-up" data-aos-delay="140">
                        <h3>A territory of your own</h3>
                        <p>
                            Partners selling seriously in one market can have
                            it written into the agreement, so we are not
                            putting somebody else into the same room a month
                            later.
                        </p>
                    </div>
                    <div class="pp-intl__card" data-aos="fade-up" data-aos-delay="210">
                        <h3>Work in your hours</h3>
                        <p>
                            We hold your time zone against your account and
                            use it. Delivery and support are run from Nairobi
                            — close enough to most of Africa and the Gulf to
                            be the same working day.
                        </p>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- ── Questions ────────────────────────────────────────────── -->
    <section class="pp-section" id="questions">
        <div class="container">
            <div class="pp-head" data-aos="fade-up">
                <span class="pp-label">Before you apply</span>
                <h2>The things people ask us first</h2>
            </div>

            <div class="pp-faq">
                <?php foreach ($faqs as $i => $faq): ?>
                    <details class="pp-faq__item" data-aos="fade-up" data-aos-delay="<?= min($i, 4) * 50 ?>"
                             <?= $i === 0 ? 'open' : '' ?>>
                        <summary>
                            <?= htmlspecialchars($faq['q'], ENT_QUOTES, 'UTF-8') ?>
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </summary>
                        <p><?= htmlspecialchars($faq['a'], ENT_QUOTES, 'UTF-8') ?></p>
                    </details>
                <?php endforeach; ?>
            </div>

            <?php if (trim($programme['terms']) !== ''): ?>
                <?php // The terms as the system holds them, so what the
                      // website promises and what a partner is actually
                      // told on approval are the same sentence. ?>
                <p class="pp-terms" data-aos="fade-up">
                    <strong>In short:</strong>
                    <?= htmlspecialchars($programme['terms'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ── Last word ────────────────────────────────────────────── -->
    <section class="pp-final">
        <div class="container" data-aos="zoom-in">
            <h2>You already know who needs this</h2>
            <p>
                If you deal with businesses, you have met four of them this
                month. Introduce one and find out what it is worth.
            </p>
            <?php if ($open): ?>
                <a href="/partners/apply" class="pp-btn pp-btn--light" rel="nofollow">
                    Become a partner
                </a>
                <p class="pp-final__note">
                    Takes ten minutes. No fee, no stock, no minimum.
                    <a href="/partners/login" rel="nofollow">Already a partner? Sign in</a>.
                </p>
            <?php else: ?>
                <a href="contact.php?service=Partner%20Programme" class="pp-btn pp-btn--light">
                    Talk to us
                </a>
                <p class="pp-final__note">
                    We are not taking applications through the website at the
                    moment, but we would still like to hear from you.
                </p>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
