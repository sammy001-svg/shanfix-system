<?php
/**
 * Reusable "Get Started" CTA section for service landing pages.
 *
 * Usage:
 *   $ctaService = 'Web Hosting';   // pre-fills the support ticket subject
 *   include 'includes/service_cta.php';
 *
 * The $ctaService variable is optional; defaults to "this service".
 */
// There is one client portal now and it belongs to the system, so there
// is no site session to ask about and no second "are you logged in?"
// branch. Everyone gets the same two ways in: ask us for a quote, or
// sign in to the portal.
require_once __DIR__ . '/brand.php';

$_brand    = site_brand();
$_ctaLabel = htmlspecialchars($ctaService ?? 'this service');
?>

<section style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%); padding: 80px 0; margin-top: 60px; position: relative; overflow: hidden;">
    <!-- Background decoration -->
    <div style="position:absolute; top:-60px; right:-60px; width:300px; height:300px; background:radial-gradient(circle, rgba(99,102,241,0.15) 0%, transparent 70%); border-radius:50%;"></div>
    <div style="position:absolute; bottom:-80px; left:-40px; width:250px; height:250px; background:radial-gradient(circle, rgba(34,197,94,0.1) 0%, transparent 70%); border-radius:50%;"></div>

    <div class="container" style="position:relative; z-index:1; text-align:center; max-width:800px; margin:0 auto;">
        <span style="display:inline-block; background:rgba(99,102,241,0.2); color:#a5b4fc; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:2px; padding:6px 18px; border-radius:20px; margin-bottom:20px; border:1px solid rgba(99,102,241,0.3);">
            Ready to Get Started?
        </span>

        <h2 style="color:#f8fafc; font-size:clamp(1.8rem, 4vw, 2.8rem); font-weight:800; margin: 0 0 16px; line-height:1.2;">
            Let's build something <span style="background: linear-gradient(135deg,#6366f1,#22c55e); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;">great together</span>
        </h2>

        <p style="color:#94a3b8; font-size:1.05rem; line-height:1.7; margin:0 0 40px;">
            Talk to our team about <?= $_ctaLabel ?>. We'll assess your needs and get you a tailored quote — no commitment required.
        </p>

        <div style="display:flex; gap:16px; justify-content:center; flex-wrap:wrap;">
            <a href="contact.php?service=<?= urlencode($ctaService ?? '') ?>" style="display:inline-flex; align-items:center; gap:10px; background:linear-gradient(135deg,#6366f1,#4f46e5); color:#fff; text-decoration:none; font-weight:700; font-size:1rem; padding:16px 36px; border-radius:50px; box-shadow:0 8px 32px rgba(99,102,241,0.4); transition:all 0.3s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Get a Free Quote
            </a>
            <a href="/portal/login" style="display:inline-flex; align-items:center; gap:10px; background:transparent; color:#e2e8f0; text-decoration:none; font-weight:600; font-size:0.95rem; padding:16px 32px; border-radius:50px; border:1.5px solid rgba(255,255,255,0.2); transition:all 0.3s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Existing Client? Log In
            </a>

            <a href="tel:<?= htmlspecialchars($_brand['phone_tel']) ?>" style="display:inline-flex; align-items:center; gap:10px; background:rgba(34,197,94,0.15); color:#22c55e; text-decoration:none; font-weight:600; font-size:0.95rem; padding:16px 28px; border-radius:50px; border:1.5px solid rgba(34,197,94,0.3); transition:all 0.3s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Call Us Now
            </a>
        </div>

        <!-- Trust badges -->
        <div style="display:flex; justify-content:center; gap:32px; margin-top:48px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:8px; color:#64748b; font-size:0.82rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Response within 24 hours
            </div>
            <div style="display:flex; align-items:center; gap:8px; color:#64748b; font-size:0.82rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                No commitment required
            </div>
            <div style="display:flex; align-items:center; gap:8px; color:#64748b; font-size:0.82rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                M-PESA payments accepted
            </div>
        </div>
    </div>
</section>
