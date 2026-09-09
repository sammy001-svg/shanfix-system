<?php
require_once 'includes/db_connect.php';

$eventId = (int)($_GET['id'] ?? 0);
$slug    = trim($_GET['slug'] ?? '');

$event        = null;
$ticketTypes  = [];

if ($eventId || $slug) {
    try {
        if ($eventId) {
            $s = $pdo->prepare("SELECT * FROM events WHERE id=? AND status='published'");
            $s->execute([$eventId]);
        } else {
            $s = $pdo->prepare("SELECT * FROM events WHERE slug=? AND status='published'");
            $s->execute([$slug]);
        }
        $event = $s->fetch(PDO::FETCH_ASSOC);
        if ($event) {
            $ts = $pdo->prepare("SELECT * FROM event_ticket_types WHERE event_id=? AND status='active' ORDER BY price ASC");
            $ts->execute([$event['id']]);
            $ticketTypes = $ts->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $event = null; }
}

if (!$event) {
    header('Location: event-ticketing.php');
    exit;
}

$pageSEO = [
    'title'     => 'Buy Tickets — ' . $event['title'] . ' | Shanfix Technology',
    'description' => substr(strip_tags($event['description'] ?? ''), 0, 160),
    'canonical' => '{{base}}/event-book.php?id=' . $event['id'],
];
include 'includes/header.php'; ?>

<link rel="stylesheet" href="<?= site_asset('index.css') ?>">
<style>
.book-page { max-width:1000px; margin:100px auto 60px; padding:0 20px; display:grid; grid-template-columns:1fr 380px; gap:32px; align-items:start; }
.book-card { background:#fff; border-radius:20px; border:1px solid #e9ecef; box-shadow:0 4px 24px rgba(0,0,0,0.06); overflow:hidden; }
.book-event-banner { height:200px; background:linear-gradient(135deg,#0f172a,#1e293b); position:relative; overflow:hidden; }
.book-event-banner img { width:100%; height:100%; object-fit:cover; opacity:0.6; }
.book-event-badge { position:absolute; top:16px; left:16px; background:#22c55e; color:#fff; font-size:0.72rem; font-weight:800; padding:4px 12px; border-radius:20px; text-transform:uppercase; letter-spacing:1px; }
.book-info { padding:28px; }
.book-info h1 { font-size:1.5rem; font-weight:800; color:#1e293b; margin:0 0 12px; }
.book-meta { display:flex; flex-direction:column; gap:8px; margin-bottom:20px; }
.book-meta-row { display:flex; align-items:center; gap:10px; font-size:0.875rem; color:#64748b; }
.book-meta-row i { color:#22c55e; width:16px; }
.book-desc { color:#475569; font-size:0.9rem; line-height:1.7; }

/* Ticket type selector */
.ticket-types { padding:20px 28px 28px; }
.ticket-types h3 { font-size:1rem; font-weight:700; color:#1e293b; margin:0 0 16px; }
.ticket-type-row { border:2px solid #e9ecef; border-radius:14px; padding:16px 18px; margin-bottom:12px; display:flex; align-items:center; gap:16px; transition:border-color .2s; }
.ticket-type-row:hover { border-color:#22c55e; }
.tt-info { flex:1; }
.tt-name { font-weight:700; color:#1e293b; font-size:0.9rem; }
.tt-desc { color:#94a3b8; font-size:0.78rem; margin-top:2px; }
.tt-avail { font-size:0.75rem; color:#94a3b8; margin-top:3px; }
.tt-price { font-weight:800; color:#16a34a; font-size:1.05rem; white-space:nowrap; }
.qty-ctrl { display:flex; align-items:center; gap:8px; }
.qty-btn { width:30px; height:30px; border-radius:8px; border:1px solid #d1d5db; background:#f9fafb; font-size:1rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .15s; }
.qty-btn:hover { background:#e5e7eb; }
.qty-val { font-weight:700; font-size:0.9rem; color:#1e293b; min-width:20px; text-align:center; }

/* Order summary sidebar */
.order-sidebar { position:sticky; top:90px; }
.order-summary { background:#fff; border-radius:20px; border:1px solid #e9ecef; box-shadow:0 4px 24px rgba(0,0,0,0.06); padding:24px; }
.order-summary h3 { font-size:1rem; font-weight:800; color:#1e293b; margin:0 0 18px; }
.os-line { display:flex; justify-content:space-between; font-size:0.875rem; color:#475569; padding:6px 0; }
.os-total { display:flex; justify-content:space-between; font-weight:800; color:#1e293b; font-size:1.05rem; padding:12px 0 0; border-top:2px solid #f1f5f9; margin-top:8px; }
.buyer-form { margin-top:20px; }
.bf-field { margin-bottom:14px; }
.bf-field label { display:block; font-size:0.8rem; font-weight:600; color:#374151; margin-bottom:5px; }
.bf-field input { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:9px; font-size:0.875rem; color:#1e293b; box-sizing:border-box; outline:none; transition:border-color .2s; }
.bf-field input:focus { border-color:#22c55e; }
.btn-book { width:100%; padding:14px; background:linear-gradient(135deg,#16a34a,#22c55e); color:#fff; border:none; border-radius:12px; font-size:0.95rem; font-weight:800; cursor:pointer; transition:opacity .2s; }
.btn-book:hover { opacity:.9; }
.btn-book:disabled { opacity:.5; cursor:not-allowed; }

/* STK waiting */
.stk-pane { text-align:center; padding:20px 0; }
.stk-spin { width:48px; height:48px; border:4px solid #e9ecef; border-top-color:#22c55e; border-radius:50%; animation:sp .8s linear infinite; margin:0 auto 16px; }
@keyframes sp { to { transform:rotate(360deg); } }
.success-pane { text-align:center; padding:20px 0; }
.success-icon-ev { width:60px; height:60px; background:#22c55e; color:#fff; border-radius:50%; font-size:2rem; display:flex; align-items:center; justify-content:center; margin:0 auto 12px; }

@media (max-width: 768px) {
    .book-page { grid-template-columns:1fr; margin-top:80px; }
    .order-sidebar { position:static; }
}
</style>

<div class="book-page">
    <!-- Left: Event info + ticket picker -->
    <div>
        <div class="book-card">
            <div class="book-event-banner">
                <?php if (!empty($event['image_url'])): ?>
                <img src="<?= htmlspecialchars($event['image_url']) ?>" alt="<?= htmlspecialchars($event['title']) ?>">
                <?php endif; ?>
                <span class="book-event-badge"><?= strtoupper($event['organizer'] ?? 'Shanfix Technology') ?></span>
            </div>
            <div class="book-info">
                <h1><?= htmlspecialchars($event['title']) ?></h1>
                <div class="book-meta">
                    <div class="book-meta-row"><i class="fas fa-calendar-alt"></i> <?= date('l, d F Y · H:i', strtotime($event['event_date'])) ?></div>
                    <?php if (!empty($event['venue'])): ?>
                    <div class="book-meta-row"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['venue']) ?><?= !empty($event['venue_address']) ? ' — ' . htmlspecialchars($event['venue_address']) : '' ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($event['description'])): ?>
                <p class="book-desc"><?= nl2br(htmlspecialchars($event['description'])) ?></p>
                <?php endif; ?>
            </div>

            <div class="ticket-types">
                <h3>Select Your Tickets</h3>
                <?php foreach ($ticketTypes as $tt): ?>
                <?php $avail = $tt['capacity'] !== null ? max(0, $tt['capacity'] - $tt['sold_count']) : null; ?>
                <div class="ticket-type-row" id="tt-row-<?= $tt['id'] ?>">
                    <div class="tt-info">
                        <div class="tt-name"><?= htmlspecialchars($tt['name']) ?></div>
                        <?php if (!empty($tt['description'])): ?><div class="tt-desc"><?= htmlspecialchars($tt['description']) ?></div><?php endif; ?>
                        <?php if ($avail !== null): ?><div class="tt-avail"><?= $avail ?> seats left</div><?php endif; ?>
                    </div>
                    <div class="tt-price"><?= $tt['price'] > 0 ? 'KES ' . number_format((float)$tt['price']) : 'FREE' ?></div>
                    <div class="qty-ctrl">
                        <button class="qty-btn" onclick="changeQty(<?= $tt['id'] ?>, -1, <?= (float)$tt['price'] ?>, <?= $avail ?? 9999 ?>)">−</button>
                        <span class="qty-val" id="qty-<?= $tt['id'] ?>">0</span>
                        <button class="qty-btn" onclick="changeQty(<?= $tt['id'] ?>, 1, <?= (float)$tt['price'] ?>, <?= $avail ?? 9999 ?>)">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right: Order summary + checkout form -->
    <div class="order-sidebar">
        <div class="order-summary">
            <h3>Order Summary</h3>
            <div id="osSummary">
                <p style="color:#94a3b8; font-size:0.875rem; text-align:center; padding:10px 0;">Select tickets to continue</p>
            </div>
            <div class="os-total" id="osTotal" style="display:none;">
                <span>Total</span><span id="osTotalAmt">KES 0</span>
            </div>

            <!-- Buyer details form -->
            <div class="buyer-form" id="buyerForm" style="display:none;">
                <div class="bf-field"><label>Full Name *</label><input type="text" id="bf_name" placeholder="John Doe" required></div>
                <div class="bf-field"><label>Email Address *</label><input type="email" id="bf_email" placeholder="john@example.com" required></div>
                <div class="bf-field"><label>Phone Number *</label><input type="tel" id="bf_phone" placeholder="0712 345 678" required></div>
                <div class="bf-field">
                    <label>M-PESA Number *</label>
                    <input type="tel" id="bf_mpesa" placeholder="07XX XXX XXX (Safaricom)" required>
                    <div style="font-size:0.75rem; color:#94a3b8; margin-top:4px;">An STK push will be sent here</div>
                </div>
                <button class="btn-book" id="bookBtn" onclick="submitBooking()">
                    <i class="fas fa-mobile-alt"></i> Pay via M-PESA
                </button>
                <p style="font-size:0.72rem; color:#94a3b8; text-align:center; margin-top:10px;">
                    Secure payment powered by Safaricom M-PESA
                </p>
            </div>

            <!-- STK waiting -->
            <div class="stk-pane" id="stkPane" style="display:none;">
                <div class="stk-spin"></div>
                <p style="font-weight:700; color:#1e293b; margin:0 0 4px;">Waiting for payment…</p>
                <small style="color:#64748b;">Check your phone and enter your M-PESA PIN</small>
                <button onclick="cancelBooking()" style="display:block; margin:16px auto 0; background:none; border:1px solid #e2e8f0; border-radius:8px; padding:8px 20px; color:#64748b; font-size:0.82rem; cursor:pointer;">Cancel</button>
            </div>

            <!-- Success -->
            <div class="success-pane" id="successPane" style="display:none;">
                <div class="success-icon-ev">✓</div>
                <h4 style="color:#166534; margin:0 0 6px;">Payment Confirmed!</h4>
                <p style="color:#4b5563; font-size:0.875rem; margin:0 0 4px;">Your tickets have been booked.</p>
                <p id="successRef" style="font-weight:800; color:#1e293b; font-size:0.9rem;"></p>
                <p style="color:#64748b; font-size:0.78rem;">Check your email for the booking confirmation.</p>
                <a href="event-ticketing.php" style="display:inline-block; margin-top:16px; background:#22c55e; color:#fff; text-decoration:none; border-radius:10px; padding:10px 24px; font-weight:700; font-size:0.875rem;">Browse More Events</a>
            </div>
        </div>
    </div>
</div>

<script>
var _cart   = {}; // {type_id: {name, price, qty, avail}}
var _booking = null;
var _pollTimer = null;
var _eventId = <?= (int)$event['id'] ?>;

function changeQty(typeId, delta, price, avail) {
    if (!_cart[typeId]) _cart[typeId] = { name: document.querySelector('#tt-row-'+typeId+' .tt-name').textContent.trim(), price: price, qty: 0, avail: avail };
    var c = _cart[typeId];
    c.qty = Math.max(0, Math.min(avail, c.qty + delta));
    document.getElementById('qty-'+typeId).textContent = c.qty;
    renderSummary();
}

function renderSummary() {
    var total = 0;
    var lines = [];
    Object.keys(_cart).forEach(function(id) {
        var c = _cart[id];
        if (c.qty > 0) {
            lines.push('<div class="os-line"><span>'+c.name+' ×'+c.qty+'</span><span>KES '+(c.price*c.qty).toLocaleString()+'</span></div>');
            total += c.price * c.qty;
        }
    });
    var summaryEl = document.getElementById('osSummary');
    var totalEl   = document.getElementById('osTotal');
    var formEl    = document.getElementById('buyerForm');
    var totalAmt  = document.getElementById('osTotalAmt');

    if (lines.length === 0) {
        summaryEl.innerHTML = '<p style="color:#94a3b8; font-size:0.875rem; text-align:center; padding:10px 0;">Select tickets to continue</p>';
        totalEl.style.display = 'none';
        formEl.style.display  = 'none';
    } else {
        summaryEl.innerHTML = lines.join('');
        totalEl.style.display = 'flex';
        totalAmt.textContent  = 'KES ' + total.toLocaleString();
        formEl.style.display  = 'block';
    }
}

async function submitBooking() {
    var name  = document.getElementById('bf_name').value.trim();
    var email = document.getElementById('bf_email').value.trim();
    var phone = document.getElementById('bf_phone').value.trim();
    var mpesa = document.getElementById('bf_mpesa').value.trim();
    if (!name||!email||!phone||!mpesa) { alert('Please fill in all required fields.'); return; }

    var tickets = [];
    Object.keys(_cart).forEach(function(id) {
        if (_cart[id].qty > 0) tickets.push({ type_id: parseInt(id), qty: _cart[id].qty });
    });
    if (!tickets.length) { alert('Please select at least one ticket.'); return; }

    var btn = document.getElementById('bookBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

    try {
        var res  = await fetch('api/event-booking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: _eventId, buyer_name: name, buyer_email: email, buyer_phone: phone, mpesa_phone: mpesa, tickets: tickets })
        });
        var data = await res.json();

        if (!data.success) {
            alert(data.message || 'Booking failed. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-mobile-alt"></i> Pay via M-PESA';
            return;
        }

        _booking = data;
        document.getElementById('buyerForm').style.display = 'none';

        if (data.stk_sent && data.booking_id) {
            document.getElementById('stkPane').style.display = 'block';
            startPolling(data.booking_id);
        } else {
            showSuccess(data.reference);
        }
    } catch(err) {
        alert('Connection error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-mobile-alt"></i> Pay via M-PESA';
    }
}

function startPolling(bookingId) {
    var attempts = 0;
    _pollTimer = setInterval(async function() {
        attempts++;
        if (attempts > 40) { clearInterval(_pollTimer); return; }
        try {
            var r = await fetch('api/event-booking.php?poll='+bookingId);
            var d = await r.json();
            if (d.status === 'paid') {
                clearInterval(_pollTimer);
                document.getElementById('stkPane').style.display = 'none';
                showSuccess(_booking.reference);
            } else if (d.status === 'failed') {
                clearInterval(_pollTimer);
                document.getElementById('stkPane').style.display = 'none';
                document.getElementById('buyerForm').style.display = 'block';
                var btn = document.getElementById('bookBtn');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-mobile-alt"></i> Pay via M-PESA';
                alert('Payment was not completed. Please try again.');
            }
        } catch(_) {}
    }, 4000);
}

function cancelBooking() {
    clearInterval(_pollTimer);
    document.getElementById('stkPane').style.display = 'none';
    document.getElementById('buyerForm').style.display = 'block';
    var btn = document.getElementById('bookBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-mobile-alt"></i> Pay via M-PESA';
}

function showSuccess(ref) {
    document.getElementById('osSummary').style.display = 'none';
    document.getElementById('osTotal').style.display   = 'none';
    document.getElementById('successRef').textContent  = 'Booking Ref: ' + ref;
    document.getElementById('successPane').style.display = 'block';
}
</script>

<?php include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
