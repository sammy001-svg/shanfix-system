<?php
/**
 * Application routes.
 *
 * Middleware:
 *   auth                  signed-in users only
 *   guest                 signed-out users only
 *   csrf                  verify the CSRF token (all POSTs except webhooks)
 *   permission:<ability>  see App\Core\Auth::PERMISSIONS
 *
 * @var \App\Core\App $app
 */

use App\Controllers\ArtworkController;
use App\Controllers\BackupController;
use App\Controllers\BulkSmsAdminController;
use App\Controllers\BulkSmsApiController;
use App\Controllers\BulkSmsTrafficController;
use App\Controllers\BulkSmsWebhookController;
use App\Controllers\AuthController;
use App\Controllers\CatalogueImageController;
use App\Controllers\ChatController;
use App\Controllers\ClientController;
use App\Controllers\DashboardController;
use App\Controllers\DeliveryNoteController;
use App\Controllers\DocumentController;
use App\Controllers\EmployeeController;
use App\Controllers\EquipmentController;
use App\Controllers\ExpenseController;
use App\Controllers\InventoryController;
use App\Controllers\JobController;
use App\Controllers\JobRequestController;
use App\Controllers\LetterController;
use App\Controllers\JobFileController;
use App\Controllers\LiveChatApiController;
use App\Controllers\NewsletterController;
use App\Controllers\MailController;
use App\Controllers\TestimonialController;
use App\Controllers\LiveChatController;
use App\Controllers\LiveChatAdminController;
use App\Controllers\LeadController;
use App\Controllers\MeetingController;
use App\Controllers\NotificationController;
use App\Controllers\PartnerAuthController;
use App\Controllers\PartnerAdminController;
use App\Controllers\PartnerController;
use App\Controllers\PartnerSmsController;
use App\Controllers\PayoutController;
use App\Controllers\PayrollController;
use App\Controllers\PaymentController;
use App\Controllers\PublicDocumentController;
use App\Controllers\PublicMeetingController;
use App\Controllers\PublicArtworkController;
use App\Controllers\PublicJobRequestController;
use App\Controllers\PortalAuthController;
use App\Controllers\PortalController;
use App\Controllers\PortalRequestController;
use App\Controllers\PortalSmsController;
use App\Controllers\PortalSupportController;
use App\Controllers\PublicProofController;
use App\Controllers\PublicStatementController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\PwaController;
use App\Controllers\ReminderController;
use App\Controllers\ReportController;
use App\Controllers\ServiceController;
use App\Controllers\StaffNotificationController;
use App\Controllers\SubscriptionController;
use App\Controllers\SettingsController;
use App\Controllers\SupplierController;
use App\Controllers\SmsCampaignController;
use App\Controllers\ThreadController;
use App\Controllers\UserController;
use App\Controllers\WhatsAppController;
use App\Controllers\WhatsAppWebhookController;
use App\Core\Request;
use App\Core\Response;

$r = $app->router();

// ---------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------
// Three kinds of account sign in here and none of them works in the
// others' form, so the front door asks which you are. Somebody already
// signed in is sent straight to whichever of the three they are in.
// The company website sits in front of all this and owns the front door,
// so the chooser needs a path of its own — /signin is what the site's
// header points at. The bare / stays registered because it is still the
// front door wherever the system is deployed without the site beside it.
$r->get('/',       [AuthController::class, 'choose']);
$r->get('/signin', [AuthController::class, 'choose']);
$r->get('/login',             [AuthController::class, 'showLogin'], ['guest']);
$r->post('/login',            [AuthController::class, 'login'],     ['guest', 'csrf']);
$r->get('/login/otp',         [AuthController::class, 'showOtp'],   ['guest']);
$r->post('/login/otp',        [AuthController::class, 'verifyOtp'], ['guest', 'csrf']);
$r->post('/login/otp/resend', [AuthController::class, 'resendOtp'], ['guest', 'csrf']);
$r->post('/logout',           [AuthController::class, 'logout'],    ['csrf']);

// KopoKopo webhook — no session, no CSRF. Authenticated by HMAC signature.
$r->post('/webhooks/kopokopo', [PaymentController::class, 'kopokopoCallback']);

// WhatsApp webhook. Meta calls GET once to verify the endpoint, then POSTs
// every message and delivery receipt. No session and no CSRF — the caller
// is Meta, and what proves it is the signature on the body.
$r->get('/webhooks/whatsapp',  [WhatsAppWebhookController::class, 'verify']);
$r->post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

// The Bulk SMS developer API — customers' own software sending through
// us. The addresses and the JSON are the old platform's, because live
// customers have them in their code. No session and no CSRF: the caller
// is a program holding a key, checked against a stored hash.
foreach ([
    ['sendsms',  'send'],
    ['bulksend', 'bulk'],
    ['balance',  'balance'],
    ['status',   'status'],
    ['messages', 'messages'],
] as [$endpoint, $action]) {
    foreach (['/api/v1/' . $endpoint, '/api/v1/' . $endpoint . '.php'] as $path) {
        $r->get($path,     [BulkSmsApiController::class, $action]);
        $r->post($path,    [BulkSmsApiController::class, $action]);
        $r->options($path, [BulkSmsApiController::class, 'preflight']);
    }
}

// Live chat with people on the website — the widget on site/ talks to
// this. Public by nature: no session and no CSRF, because the caller has
// not signed in to anything. The conversation's own token stands in for
// a session, and every action here is rate limited per address.
$r->get ('/api/chat/hello', [LiveChatApiController::class, 'hello']);
$r->post('/api/chat/start', [LiveChatApiController::class, 'start']);
$r->post('/api/chat/send',  [LiveChatApiController::class, 'send']);
$r->get ('/api/chat/poll',  [LiveChatApiController::class, 'poll']);
$r->post('/api/chat/close', [LiveChatApiController::class, 'close']);
$r->post('/api/chat/rate',  [LiveChatApiController::class, 'rate']);
$r->post('/api/chat/contact', [LiveChatApiController::class, 'contact']);

// The newsletter box in the website footer, and the page its unsubscribe
// link opens. Public and sessionless: signing up needs only an address,
// leaving needs only the token in the person's own link. Rate limited in
// the controller instead of CSRF, which would protect nothing here.
$r->post('/api/newsletter/subscribe', [NewsletterController::class, 'subscribe']);
$r->get ('/newsletter/unsubscribe',   [NewsletterController::class, 'showUnsubscribe']);
$r->post('/newsletter/unsubscribe',   [NewsletterController::class, 'unsubscribe']);

// Bulk SMS delivery reports from Onfon. Public: the caller is Onfon's
// server, optionally proving itself with ?token=. The .php address is the
// one the old platform used and is what is typed into the Onfon portal.
foreach (['/webhooks/sms-dlr', '/webhooks/sms-dlr.php'] as $dlr) {
    $r->get($dlr,  [BulkSmsWebhookController::class, 'dlr']);
    $r->post($dlr, [BulkSmsWebhookController::class, 'dlr']);
}

// ---------------------------------------------------------------------
// Installable app
//
// Served by PHP, not as files under assets/. A service worker may only
// control paths below its own URL, so it has to answer from the root —
// and the flat cPanel build moves the document root, which a static file
// would not survive.
// ---------------------------------------------------------------------
$r->get('/manifest.webmanifest', [PwaController::class, 'manifest']);
// The connectivity probe. No auth, no session, no body — the only thing
// it tells the browser is that a reply arrived, which is the one fact
// navigator.onLine gets wrong.
$r->head('/up', [PwaController::class, 'up']);

$r->get('/sw.js',                [PwaController::class, 'serviceWorker']);
$r->get('/icon-{size}.png',      [PwaController::class, 'icon']);
$r->get('/offline',              [PwaController::class, 'offline']);

// What to keep for offline reading. Needs a session — it is about this
// user's own work.
$r->get('/offline/precache', [PwaController::class, 'precache'], ['auth']);

// Client-facing document view. No login: the 48-char token is the credential.
$r->get('/view/{token}', [PublicDocumentController::class, 'show']);

// The same page on a short URL, for SMS. The full link costs 79 of the 160
// characters in a text, which turns routine messages into two billable parts.
$r->get('/v/{token}', [PublicDocumentController::class, 'show']);

// Paying an invoice from that page. No login — the token is the credential,
// exactly as for viewing it. CSRF still applies: the form is served by us,
// so a POST that did not come from it has no business here.
$r->post('/view/{token}/pay',       [PublicDocumentController::class, 'pay'], ['csrf']);
$r->get('/view/{token}/pay/status', [PublicDocumentController::class, 'payStatus']);
$r->post('/v/{token}/pay',          [PublicDocumentController::class, 'pay'], ['csrf']);
$r->get('/v/{token}/pay/status',    [PublicDocumentController::class, 'payStatus']);

// Accepting an agreement from the share link. No login — the token is the
// credential, as for viewing it. CSRF still applies: we served the form.
$r->post('/view/{token}/accept', [PublicDocumentController::class, 'accept'], ['csrf']);
$r->post('/v/{token}/accept',    [PublicDocumentController::class, 'accept'], ['csrf']);

// Joining a meeting from a shared link. No login: the token is the
// credential, as with a shared invoice. A guest gives their name at the
// door so the minutes record who was actually in the room.
$r->get('/join/{token}',              [PublicMeetingController::class, 'lobby']);
$r->post('/join/{token}',             [PublicMeetingController::class, 'enter'], ['csrf']);
$r->get('/join/{token}/room',         [PublicMeetingController::class, 'room']);
$r->get('/join/{token}/notes',        [PublicMeetingController::class, 'pollNotes']);
$r->post('/join/{token}/notes',       [PublicMeetingController::class, 'postNote'], ['csrf']);
$r->get('/join/{token}/signals',      [PublicMeetingController::class, 'signals']);
$r->post('/join/{token}/signal',      [PublicMeetingController::class, 'signal'], ['csrf']);

// Client-facing proof approval. Also no login — the token is the credential.
// The client sees the artwork and approves it or asks for changes, which
// moves the job exactly as a staff-recorded decision does.
$r->get('/proof/{token}',          [PublicProofController::class, 'show']);
$r->get('/proof/{token}/file',     [PublicProofController::class, 'file']);
$r->post('/proof/{token}/decide',  [PublicProofController::class, 'decide'], ['csrf']);

// Short form for SMS.
$r->get('/p/{token}', [PublicProofController::class, 'show']);

// The job brief a client fills in for us. Same token model: there is no
// client login anywhere in this system, so holding the link is what
// proves it was sent to you.
$r->get('/brief/{token}',  [PublicJobRequestController::class, 'show']);
$r->post('/brief/{token}', [PublicJobRequestController::class, 'submit'], ['csrf']);

// Short form for SMS.
$r->get('/b/{token}', [PublicJobRequestController::class, 'show']);

// ---------------------------------------------------------------------
// ---------------------------------------------------------------------
// Catalogue pictures
// ---------------------------------------------------------------------
// /files is behind the staff guard, which is right for receipts and
// artwork and wrong for the photographs of what we sell: it meant a
// client browsing the catalogue and a partner browsing what they resell
// both saw nothing at all, while the pictures sat in the database.
//
// This route cannot name a file. It takes an image row's own id, looks it
// up in one of the two catalogue image tables, and refuses unless the
// thing it belongs to is still active. There is no path from here to the
// rest of storage.
//
// Signed in as any of the three, because the picture should be no more
// public than the page that shows it.
// The portals ask with a session. The public website has none to offer,
// so it asks the other one — same lookup, same refusals, same confinement
// to storage/uploads. What makes these safe is that neither can name a
// file, not the session one of them requires.
$r->get('/catalogue/image/{kind}/{id}', [CatalogueImageController::class, 'catalogue']);
$r->get('/catalogue/photo/{kind}/{id}', [CatalogueImageController::class, 'photo']);

// The client portal
// ---------------------------------------------------------------------
// A second application with its own guard. 'client_auth' is not 'auth':
// a staff session cannot satisfy one and a client session cannot satisfy
// the other, which is the whole point of the two being separate.
$r->get('/portal/login',           [PortalAuthController::class, 'showLogin']);
$r->post('/portal/login',          [PortalAuthController::class, 'login'], ['csrf']);
$r->post('/portal/logout',         [PortalAuthController::class, 'logout'], ['csrf']);

$r->get('/portal/start',           [PortalAuthController::class, 'showStart']);
$r->post('/portal/start',          [PortalAuthController::class, 'requestCode'], ['csrf']);
$r->get('/portal/verify',          [PortalAuthController::class, 'showVerify']);
$r->post('/portal/verify',         [PortalAuthController::class, 'verify'], ['csrf']);

$r->get('/portal/request-access',  [PortalAuthController::class, 'showRequestAccess']);
$r->post('/portal/request-access', [PortalAuthController::class, 'requestAccess'], ['csrf']);

$r->get('/portal',                 [PortalController::class, 'home'], ['client_auth']);

// ---------------------------------------------------------------------
// The partner portal
// ---------------------------------------------------------------------
// A third application with a third guard. 'partner_auth' is neither
// 'auth' nor 'client_auth': no session satisfies more than one of them,
// which is the whole point of there being three.
$r->get('/partners/login',   [PartnerAuthController::class, 'showLogin']);
$r->post('/partners/login',  [PartnerAuthController::class, 'login'],  ['csrf']);
$r->post('/partners/logout', [PartnerAuthController::class, 'logout'], ['csrf']);

$r->get('/partners/apply',   [PartnerAuthController::class, 'showApply']);
$r->post('/partners/apply',  [PartnerAuthController::class, 'apply'],  ['csrf']);

$r->get('/partners/start',   [PartnerAuthController::class, 'showStart']);
$r->post('/partners/start',  [PartnerAuthController::class, 'requestCode'], ['csrf']);
$r->get('/partners/verify',  [PartnerAuthController::class, 'showVerify']);
$r->post('/partners/verify', [PartnerAuthController::class, 'verify'], ['csrf']);

// Partners, from our side of the desk. Staff routes, staff guard: this is
// the back office for the portal above, not part of it.
$r->group(['auth'], function ($r) {
    $r->get('/partners-admin',              [PartnerAdminController::class, 'index'],  ['permission:partners.view']);

    // Before the {id} route, or "new" and "runs" are read as partner ids.
    $r->get('/partners-admin/new',          [PartnerAdminController::class, 'create'],  ['permission:partners.create']);
    $r->post('/partners-admin/new',         [PartnerAdminController::class, 'store'],   ['csrf', 'permission:partners.create']);
    $r->get('/partners-admin/runs',         [PartnerAdminController::class, 'run'],     ['permission:partners.view']);

    $r->get('/partners-admin/{id}',         [PartnerAdminController::class, 'show'],    ['permission:partners.view']);

    // What this partner earns on each thing. Reading is a view right;
    // moving a rate commits us to paying differently, so that is not.
    $r->get('/partners-admin/{id}/rates',   [PartnerAdminController::class, 'rates'],     ['permission:partners.view']);
    $r->post('/partners-admin/{id}/rates',  [PartnerAdminController::class, 'saveRates'], ['csrf', 'permission:partners.manage']);
    $r->post('/partners-admin/{id}',        [PartnerAdminController::class, 'update'],  ['csrf', 'permission:partners.manage']);
    $r->post('/partners-admin/{id}/decide', [PartnerAdminController::class, 'decide'],  ['csrf', 'permission:partners.manage']);
    $r->post('/partners-admin/{id}/assign', [PartnerAdminController::class, 'assign'],  ['csrf', 'permission:partners.assign']);
    $r->post('/partners-admin/{id}/payout', [PartnerAdminController::class, 'payout'],  ['csrf', 'permission:partners.pay']);

    // Where their money goes. Not 'partners.manage' like the rest of the
    // profile: redirecting a payment is finance's call, not a manager's.
    $r->post('/partners-admin/{id}/pay-details', [PartnerAdminController::class, 'payDetails'], ['csrf', 'permission:partners.pay']);

    // Paying it out. Reading a run is a view right so sales can answer
    // "have they been paid yet"; everything that moves money is not.
    $r->get('/payouts',                     [PayoutController::class, 'index'],      ['permission:partners.view']);
    $r->post('/payouts',                    [PayoutController::class, 'build'],      ['csrf', 'permission:partners.pay']);

    // Before {id}, or "line" is read as a run id.
    $r->post('/payouts/line/{id}/settle',   [PayoutController::class, 'settleLine'],  ['csrf', 'permission:partners.pay']);
    $r->post('/payouts/line/{id}/fail',     [PayoutController::class, 'failLine'],    ['csrf', 'permission:partners.pay']);
    $r->post('/payouts/line/{id}/hold',     [PayoutController::class, 'holdLine'],    ['csrf', 'permission:partners.pay']);
    $r->post('/payouts/line/{id}/admit',    [PayoutController::class, 'admitLine'],   ['csrf', 'permission:partners.pay']);

    $r->get('/payouts/{id}',                [PayoutController::class, 'show'],        ['permission:partners.view']);
    $r->post('/payouts/{id}/approve',       [PayoutController::class, 'approve'],     ['csrf', 'permission:partners.pay']);
    $r->get('/payouts/{id}/export/{kind}',  [PayoutController::class, 'export'],      ['permission:partners.pay']);
    $r->post('/payouts/{id}/sent',          [PayoutController::class, 'sent'],        ['csrf', 'permission:partners.pay']);
    $r->post('/payouts/{id}/settle',        [PayoutController::class, 'settleAll'],   ['csrf', 'permission:partners.pay']);
});

$r->group(['partner_auth'], function ($r) {
    $r->get('/partners',             [PartnerController::class, 'home']);
    $r->get('/partners/earnings',    [PartnerController::class, 'commissions']);
    $r->get('/partners/customers',   [PartnerController::class, 'customers']);

    // Registering a customer is the one thing a partner writes into the
    // trading side. Before the {id} route, or "new" is read as a customer.
    $r->get('/partners/clients/new',  [PartnerController::class, 'showRegisterClient']);
    $r->post('/partners/clients/new', [PartnerController::class, 'registerClient'], ['csrf']);

    // Read-only. There is deliberately no route here that raises a
    // quotation, an invoice or a payment — that is ours to do.
    $r->get('/partners/customers/{id}', [PartnerController::class, 'client']);
    $r->get('/partners/services',    [PartnerController::class, 'services']);
    $r->get('/partners/upcoming',    [PartnerController::class, 'upcoming']);
    $r->get('/partners/refer',       [PartnerController::class, 'showRefer']);
    $r->post('/partners/refer',      [PartnerController::class, 'refer'], ['csrf']);

    // Their own details. The commission run refuses to pay a partner with
    // no KRA PIN, and this is the only place the person who knows it can
    // put it in.
    $r->get('/partners/account',          [PartnerController::class, 'account']);
    $r->post('/partners/account',         [PartnerController::class, 'updateAccount'], ['csrf']);
    $r->post('/partners/account/password',[PartnerController::class, 'changePassword'], ['csrf']);

    // A month set out so they can invoice us against it, which is what the
    // terms they agreed to actually say happens.
    // -- Bulk SMS: their own sending, and their reselling
    //
    // The sending half is the same code and the same pages the client
    // portal uses — a partner texting their customers is doing what a
    // client does. The reselling half is theirs alone.
    $r->get('/partners/sms',                 [PartnerSmsController::class, 'home']);
    $r->get('/partners/sms/campaigns',       [PartnerSmsController::class, 'campaigns']);
    $r->get('/partners/sms/campaigns/new',   [PartnerSmsController::class, 'newCampaign']);
    $r->get('/partners/sms/campaigns/{id}',  [PartnerSmsController::class, 'campaign']);
    $r->get('/partners/sms/contacts',        [PartnerSmsController::class, 'contacts']);
    $r->get('/partners/sms/senders',         [PartnerSmsController::class, 'senders']);
    $r->get('/partners/sms/buy',             [PartnerSmsController::class, 'buy']);
    $r->get('/partners/sms/buy/{id}/status', [PartnerSmsController::class, 'purchaseStatus']);
    $r->get('/partners/sms/reports',         [PartnerSmsController::class, 'reports']);
    $r->get('/partners/sms/api',             [PartnerSmsController::class, 'api']);
    $r->get('/partners/sms/clients',         [PartnerSmsController::class, 'clients']);
    $r->get('/partners/sms/sales',           [PartnerSmsController::class, 'sales']);
    $r->get('/partners/sms/pricing',         [PartnerSmsController::class, 'pricing']);
    $r->get('/partners/sms/contacts/import',     [PartnerSmsController::class, 'importPage']);
    $r->get('/partners/sms/contacts/export',     [PartnerSmsController::class, 'exportContacts']);
    $r->get('/partners/sms/contacts/template',   [PartnerSmsController::class, 'contactTemplate']);
    $r->get('/partners/sms/groups',              [PartnerSmsController::class, 'groupsPage']);
    $r->get('/partners/sms/templates',           [PartnerSmsController::class, 'templates']);
    $r->get('/partners/sms/scheduled',           [PartnerSmsController::class, 'scheduled']);
    $r->get('/partners/sms/send-file',           [PartnerSmsController::class, 'fileSend']);
    $r->get('/partners/sms/settings',            [PartnerSmsController::class, 'settings']);
    $r->get('/partners/sms/clients/{id}',    [PartnerSmsController::class, 'clientDetail']);
    $r->get('/partners/sms/branding',        [PartnerSmsController::class, 'branding']);

    $r->group(['csrf'], function ($r) {
        $r->post('/partners/sms/send',                  [PartnerSmsController::class, 'send']);
        $r->post('/partners/sms/campaigns',             [PartnerSmsController::class, 'createCampaign']);
        $r->post('/partners/sms/campaigns/{id}/cancel', [PartnerSmsController::class, 'cancelCampaign']);
        $r->post('/partners/sms/contacts',              [PartnerSmsController::class, 'saveContact']);
        $r->post('/partners/sms/contacts/import',       [PartnerSmsController::class, 'importContacts']);
        $r->post('/partners/sms/contacts/{id}/delete',  [PartnerSmsController::class, 'deleteContact']);
        $r->post('/partners/sms/groups',                [PartnerSmsController::class, 'saveGroup']);
        $r->post('/partners/sms/groups/{id}/delete',    [PartnerSmsController::class, 'deleteGroup']);
        $r->post('/partners/sms/templates',             [PartnerSmsController::class, 'saveTemplate']);
        $r->post('/partners/sms/templates/{id}/delete', [PartnerSmsController::class, 'deleteTemplate']);
        $r->post('/partners/sms/senders',               [PartnerSmsController::class, 'requestSender']);
        $r->post('/partners/sms/buy',                   [PartnerSmsController::class, 'startPurchase']);
        $r->post('/partners/sms/api/key',               [PartnerSmsController::class, 'issueKey']);
        $r->post('/partners/sms/api/key/revoke',        [PartnerSmsController::class, 'revokeKey']);
        $r->post('/partners/sms/contacts/move',         [PartnerSmsController::class, 'moveContacts']);
        $r->post('/partners/sms/groups/{id}/empty',     [PartnerSmsController::class, 'emptyGroup']);
        $r->post('/partners/sms/campaigns/{id}/reschedule', [PartnerSmsController::class, 'reschedule']);
        $r->post('/partners/sms/settings',              [PartnerSmsController::class, 'saveSettings']);
        $r->post('/partners/sms/branding',              [PartnerSmsController::class, 'saveBranding']);

        // Reselling.
        $r->post('/partners/sms/clients',                   [PartnerSmsController::class, 'openClient']);
        $r->post('/partners/sms/clients/{id}/units',        [PartnerSmsController::class, 'giveUnits']);
        $r->post('/partners/sms/clients/{id}/price',        [PartnerSmsController::class, 'clientPrice']);
        $r->post('/partners/sms/clients/{id}/status',       [PartnerSmsController::class, 'clientStatus']);
        $r->post('/partners/sms/sales/{id}/approve',        [PartnerSmsController::class, 'approveSale']);
        $r->post('/partners/sms/sales/{id}/decline',        [PartnerSmsController::class, 'declineSale']);
        $r->post('/partners/sms/pricing',                   [PartnerSmsController::class, 'saveResale']);
        $r->post('/partners/sms/pricing/plans',             [PartnerSmsController::class, 'savePlan']);
        $r->post('/partners/sms/pricing/plans/{id}',        [PartnerSmsController::class, 'savePlan']);
        $r->post('/partners/sms/pricing/plans/{id}/toggle', [PartnerSmsController::class, 'togglePlan']);
        $r->post('/partners/sms/pricing/plans/{id}/delete', [PartnerSmsController::class, 'deletePlan']);
    });
    $r->get('/partners/statement/{period}', [PartnerController::class, 'statement']);
});

$r->group(['client_auth'], function ($r) {
    // The doc type is bound by the route, so it can never come from
    // what a client typed.
    $r->get('/portal/quotations', function (Request $req) {
        (new PortalController())->documents($req, 'quotation');
    });
    $r->get('/portal/quotations/{id}', function (Request $req) {
        (new PortalController())->document($req, 'quotation');
    });

    $r->get('/portal/invoices', function (Request $req) {
        (new PortalController())->documents($req, 'invoice');
    });
    $r->get('/portal/invoices/{id}', function (Request $req) {
        (new PortalController())->document($req, 'invoice');
    });

    $r->get('/portal/statement', [PortalController::class, 'statement']);

    $r->get('/portal/services',  [PortalController::class, 'services']);
    $r->get('/portal/catalogue', [PortalController::class, 'catalogue']);
    $r->get('/portal/requests',  [PortalController::class, 'priceRequests']);

    $r->post('/portal/catalogue/ask', [PortalController::class, 'requestPrice'], ['csrf']);

    $r->get('/portal/uploads',  [PortalController::class, 'uploads']);
    $r->post('/portal/uploads', [PortalController::class, 'upload'], ['csrf']);

    // -- Job tracking
    $r->get('/portal/jobs',      [PortalController::class, 'jobs']);
    $r->get('/portal/jobs/{id}', [PortalController::class, 'job']);

    // -- Job detail briefs (sent by staff, filled in by the client via /brief/{token})
    $r->get('/portal/briefs', [PortalController::class, 'jobRequests']);

    // -- Receipts
    $r->get('/portal/receipts',      [PortalController::class, 'receipts']);
    $r->get('/portal/receipts/{id}', [PortalController::class, 'receipt']);

    // -- Profile
    $r->get('/portal/profile',  [PortalController::class, 'profile']);
    $r->post('/portal/profile', [PortalController::class, 'updateProfile'], ['csrf']);

    // -- Quotation acceptance
    $r->post('/portal/quotations/{id}/accept', [PortalController::class, 'acceptQuotation'], ['csrf']);
    $r->post('/portal/quotations/{id}/reject', [PortalController::class, 'rejectQuotation'], ['csrf']);

    // -- Proof approval + file serving
    $r->post('/portal/jobs/{id}/proof',    [PortalController::class, 'approveProof'], ['csrf']);
    $r->get('/portal/proof-file/{id}',     [PortalController::class, 'proofFile']);

    // -- Support messaging
    $r->get('/portal/support',  [PortalController::class, 'support']);
    $r->post('/portal/support', [PortalController::class, 'sendMessage'], ['csrf']);

    // -- Notification feed
    $r->get('/portal/notifications',          [PortalController::class, 'notifications']);
    $r->post('/portal/notifications/read-all',[PortalController::class, 'markNotificationsRead'], ['csrf']);


    // -- Staff-side portal support thread
    $r->get('/portal-support',        [PortalSupportController::class, 'index']);
    $r->get('/portal-support/{id}',   [PortalSupportController::class, 'thread']);
    $r->post('/portal-support/{id}',  [PortalSupportController::class, 'reply'], ['csrf']);

    // -- Bulk SMS, the customer's own side of the platform
    //
    // No account id appears in any of these addresses: the account is
    // resolved from the session, so a customer can only ever see and
    // spend their own.
    $r->get('/portal/sms',                    [PortalSmsController::class, 'home']);
    $r->get('/portal/sms/campaigns',          [PortalSmsController::class, 'campaigns']);
    $r->get('/portal/sms/campaigns/new',      [PortalSmsController::class, 'newCampaign']);
    $r->get('/portal/sms/campaigns/{id}',     [PortalSmsController::class, 'campaign']);
    $r->get('/portal/sms/contacts',           [PortalSmsController::class, 'contacts']);
    $r->get('/portal/sms/senders',            [PortalSmsController::class, 'senders']);
    $r->get('/portal/sms/buy',                [PortalSmsController::class, 'buy']);
    $r->get('/portal/sms/buy/{id}/status',    [PortalSmsController::class, 'purchaseStatus']);
    $r->get('/portal/sms/reports',            [PortalSmsController::class, 'reports']);
    $r->get('/portal/sms/api',                [PortalSmsController::class, 'api']);
    $r->get('/portal/sms/contacts/import',       [PortalSmsController::class, 'importPage']);
    $r->get('/portal/sms/contacts/export',       [PortalSmsController::class, 'exportContacts']);
    $r->get('/portal/sms/contacts/template',     [PortalSmsController::class, 'contactTemplate']);
    $r->get('/portal/sms/groups',                [PortalSmsController::class, 'groupsPage']);
    $r->get('/portal/sms/templates',             [PortalSmsController::class, 'templates']);
    $r->get('/portal/sms/scheduled',             [PortalSmsController::class, 'scheduled']);
    $r->get('/portal/sms/send-file',             [PortalSmsController::class, 'fileSend']);
    $r->get('/portal/sms/settings',              [PortalSmsController::class, 'settings']);

    $r->group(['csrf'], function ($r) {
        $r->post('/portal/sms/send',                 [PortalSmsController::class, 'send']);
        $r->post('/portal/sms/campaigns',            [PortalSmsController::class, 'createCampaign']);
        $r->post('/portal/sms/campaigns/{id}/cancel',[PortalSmsController::class, 'cancelCampaign']);
        $r->post('/portal/sms/contacts',             [PortalSmsController::class, 'saveContact']);
        $r->post('/portal/sms/contacts/import',      [PortalSmsController::class, 'importContacts']);
        $r->post('/portal/sms/contacts/{id}/delete', [PortalSmsController::class, 'deleteContact']);
        $r->post('/portal/sms/groups',               [PortalSmsController::class, 'saveGroup']);
        $r->post('/portal/sms/groups/{id}/delete',   [PortalSmsController::class, 'deleteGroup']);
        $r->post('/portal/sms/templates',            [PortalSmsController::class, 'saveTemplate']);
        $r->post('/portal/sms/templates/{id}/delete',[PortalSmsController::class, 'deleteTemplate']);
        $r->post('/portal/sms/senders',              [PortalSmsController::class, 'requestSender']);
        $r->post('/portal/sms/buy',                  [PortalSmsController::class, 'startPurchase']);
        $r->post('/portal/sms/api/key',              [PortalSmsController::class, 'issueKey']);
        $r->post('/portal/sms/api/key/revoke',       [PortalSmsController::class, 'revokeKey']);
        $r->post('/portal/sms/contacts/move',           [PortalSmsController::class, 'moveContacts']);
        $r->post('/portal/sms/groups/{id}/empty',       [PortalSmsController::class, 'emptyGroup']);
        $r->post('/portal/sms/campaigns/{id}/reschedule', [PortalSmsController::class, 'reschedule']);
        $r->post('/portal/sms/settings',                [PortalSmsController::class, 'saveSettings']);
    });
    $r->post('/portal/invoices/{id}/pay', [PortalController::class, 'pay'], ['csrf']);
    $r->get('/portal/invoices/{id}/pay/status', [PortalController::class, 'payStatus']);
});

// A client's own statement of account, on the same token model.
$r->get('/statement/{token}', [PublicStatementController::class, 'show']);
$r->get('/s/{token}',         [PublicStatementController::class, 'show']);

// Client-facing artwork review. On /review rather than /artwork so it cannot
// swallow the staff module's /artwork/{id}. No login: the token is the credential.
$r->get('/review/{token}',         [PublicArtworkController::class, 'show']);
$r->get('/review/{token}/file',    [PublicArtworkController::class, 'file']);
$r->post('/review/{token}/decide', [PublicArtworkController::class, 'decide'], ['csrf']);
$r->get('/a/{token}',              [PublicArtworkController::class, 'show']);

// The company logo, served without a login.
//
// Everything else under /files needs a session, but a client opening their
// invoice on a share link is not signed in — and an emailed document is
// fetched by the mail client with no session at all. The logo is public
// branding, so it gets its own unauthenticated route rather than opening up
// the whole uploads folder.
$r->get('/brand/logo', function () {
    $logo = (string) \App\Core\Settings::get('company_logo', '');

    if ($logo === '' || str_contains($logo, '..')) {
        throw new \App\Core\HttpException(404, 'No logo has been uploaded.');
    }

    $full = realpath(STORAGE_PATH . '/' . $logo);
    $root = realpath(STORAGE_PATH . '/uploads/logos');

    if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) {
        throw new \App\Core\HttpException(404, 'Logo file is missing.');
    }

    $mime = 'image/png';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $full) ?: $mime;
        finfo_close($finfo);
    }

    // Only ever serve real images from here.
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        throw new \App\Core\HttpException(404, 'Logo file is not an image.');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    // Cache briefly so a printed document does not refetch it per page.
    header('Cache-Control: public, max-age=3600');
    readfile($full);
    exit;
});

// The sign-in background, also public — the page that uses it is the one
// page nobody is signed in on. Same narrow contract as the logo: one
// setting-controlled path, images only, nothing else reachable.
$r->get('/brand/login-bg', function () {
    $file = (string) \App\Core\Settings::get('login_background', '');

    if ($file === '' || str_contains($file, '..')) {
        throw new \App\Core\HttpException(404, 'No background has been set.');
    }

    $full = realpath(STORAGE_PATH . '/' . $file);
    $root = realpath(STORAGE_PATH . '/uploads/branding');

    if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) {
        throw new \App\Core\HttpException(404, 'Background file is missing.');
    }

    $mime = 'image/jpeg';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $full) ?: $mime;
        finfo_close($finfo);
    }

    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        throw new \App\Core\HttpException(404, 'Background file is not an image.');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    // Long cache: the URL carries the file's timestamp, so a replacement
    // gets a new URL rather than waiting for this to expire.
    header('Cache-Control: public, max-age=604800');
    readfile($full);
    exit;
});

// Serve uploaded files through PHP.
//
// The URL is deliberately NOT /storage/... — in the flat cPanel build the
// storage/ folder physically sits in the web root, so Apache answers that
// path itself (and denies it) before the front controller ever runs. /files
// matches no real directory, so it always reaches this route.
//
// {path*} matches across slashes, e.g. uploads/receipts/abc123.pdf
$r->get('/files/{path*}', function (Request $request) {
    $relative = (string) $request->param('path');

    // Reject traversal before touching the filesystem.
    if (str_contains($relative, '..') || str_contains($relative, "\0")) {
        throw new \App\Core\HttpException(400, 'Invalid file path.');
    }

    $full = realpath(STORAGE_PATH . '/' . $relative);
    $root = realpath(STORAGE_PATH . '/uploads');

    if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) {
        throw new \App\Core\HttpException(404, 'File not found.');
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $full) ?: $mime;
        finfo_close($finfo);
    }

    // Only render inline for types that cannot carry script.
    $inlineSafe = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $disposition = in_array($mime, $inlineSafe, true) ? 'inline' : 'attachment';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($full) . '"');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}, ['auth']);

// ---------------------------------------------------------------------
// Authenticated
// ---------------------------------------------------------------------
$r->group(['auth'], function ($r) {

    // -- Dashboard, search, profile
    $r->get('/dashboard', [DashboardController::class, 'index']);
    $r->get('/search',    [DashboardController::class, 'search']);
    $r->get('/search/quick', [DashboardController::class, 'quickSearch']);

    $r->get('/profile',           [AuthController::class, 'profile']);
    $r->post('/profile',          [AuthController::class, 'updateProfile'],  ['csrf']);
    $r->post('/profile/password', [AuthController::class, 'changePassword'], ['csrf']);

    // -- Inventory
    $r->group(['permission:inventory.view'], function ($r) {
        $r->get('/inventory',        [InventoryController::class, 'index']);
        $r->get('/inventory/export', [InventoryController::class, 'export']);
        $r->get('/inventory/create', [InventoryController::class, 'create']);
        $r->get('/inventory/{id}',      [InventoryController::class, 'show']);
        $r->get('/inventory/{id}/edit', [InventoryController::class, 'edit']);
    });

    $r->group(['permission:inventory.manage', 'csrf'], function ($r) {
        $r->post('/inventory',             [InventoryController::class, 'store']);
        $r->post('/inventory/{id}',        [InventoryController::class, 'update']);
        $r->post('/inventory/{id}/stock',  [InventoryController::class, 'adjustStock']);
        $r->post('/inventory/{id}/delete', [InventoryController::class, 'destroy'], ['permission:records.delete']);

        // Product photos
        $r->post('/inventory/{id}/images',                  [InventoryController::class, 'uploadImages']);
        $r->post('/inventory/images/{imageId}/delete',      [InventoryController::class, 'deleteImage'], ['permission:records.delete']);
        $r->post('/inventory/images/{imageId}/primary',     [InventoryController::class, 'setPrimaryImage']);
    });

    // -- The people who work here
    //
    // -- Staff, HR & Payroll
    // Behind hr.* rather than a manager's permission: these records carry
    // what people earn, their ID numbers and their bank accounts.
    $r->get('/hr',                    [\App\Controllers\HrDashboardController::class, 'index']);
    $r->get('/staff',                 [EmployeeController::class, 'index'],  ['permission:hr.view']);
    // Before {id}, or "new" is read as a person.
    $r->get('/staff/new',             [EmployeeController::class, 'create'], ['permission:hr.manage']);
    $r->post('/staff/new',            [EmployeeController::class, 'store'],  ['csrf', 'permission:hr.manage']);
    $r->get('/staff/{id}',            [EmployeeController::class, 'show'],   ['permission:hr.view']);
    $r->post('/staff/{id}',           [EmployeeController::class, 'update'], ['csrf', 'permission:hr.manage']);
    $r->post('/staff/{id}/pay-items', [EmployeeController::class, 'addPayItem'],    ['csrf', 'permission:hr.manage']);
    $r->post('/staff/{id}/pay-items/{item}/end',
                                      [EmployeeController::class, 'removePayItem'], ['csrf', 'permission:hr.manage']);

    // -- Payroll
    //
    // Working one out, approving it and paying it are three different
    // authorities on purpose. Whoever decides what everybody is paid
    // should not be the only person who has looked at it.
    $r->get('/payroll',                  [PayrollController::class, 'index'],     ['permission:payroll.view']);
    // Before {id}, or "rates" is read as a run.
    $r->get('/payroll/rates',            [PayrollController::class, 'rates'],     ['permission:payroll.view']);
    $r->post('/payroll/rates',           [PayrollController::class, 'saveRates'], ['csrf', 'permission:payroll.approve']);
    $r->get('/payroll/payslip/{id}',     [PayrollController::class, 'payslip'],   ['permission:payroll.view']);
    $r->post('/payroll',                 [PayrollController::class, 'build'],     ['csrf', 'permission:payroll.run']);
    $r->get('/payroll/{id}',             [PayrollController::class, 'show'],      ['permission:payroll.view']);
    $r->post('/payroll/{id}/approve',    [PayrollController::class, 'approve'],   ['csrf', 'permission:payroll.approve']);
    $r->post('/payroll/{id}/paid',       [PayrollController::class, 'markPaid'],  ['csrf', 'permission:payroll.pay']);
    $r->get('/payroll/{id}/export/{kind}', [PayrollController::class, 'export'],  ['permission:payroll.pay']);
    $r->post('/payroll/{id}/discard',    [PayrollController::class, 'destroy'],   ['csrf', 'permission:payroll.run']);

    // -- The machines
    //
    // Nearly everyone may look one up: whether the laminator is working
    // decides what can be promised today. Changing the register is not.
    $r->get('/equipment',              [EquipmentController::class, 'index'],  ['permission:equipment.view']);
    $r->get('/equipment/new',          [EquipmentController::class, 'create'], ['permission:equipment.manage']);
    $r->post('/equipment/new',         [EquipmentController::class, 'store'],  ['csrf', 'permission:equipment.manage']);
    $r->get('/equipment/{id}',         [EquipmentController::class, 'show'],   ['permission:equipment.view']);
    $r->post('/equipment/{id}',        [EquipmentController::class, 'update'], ['csrf', 'permission:equipment.manage']);
    $r->post('/equipment/{id}/service', [EquipmentController::class, 'logService'], ['csrf', 'permission:equipment.manage']);

    // -- Suppliers and purchasing
    //
    // The other half of inventory: stock arrives here at a real cost price
    // rather than through a hand-typed adjustment.
    $r->group(['permission:purchases.view'], function ($r) {
        $r->get('/suppliers',             [SupplierController::class, 'index']);
        $r->get('/suppliers/create',      [SupplierController::class, 'create']);
        $r->get('/suppliers/{id}',        [SupplierController::class, 'show']);
        $r->get('/suppliers/{id}/edit',   [SupplierController::class, 'edit']);

        $r->get('/purchase-orders',           [PurchaseOrderController::class, 'index']);
        $r->get('/purchase-orders/create',    [PurchaseOrderController::class, 'create']);
        $r->get('/purchase-orders/{id}',      [PurchaseOrderController::class, 'show']);
        $r->get('/purchase-orders/{id}/edit', [PurchaseOrderController::class, 'edit']);
    });

    $r->group(['permission:purchases.manage', 'csrf'], function ($r) {
        $r->post('/suppliers',                    [SupplierController::class, 'store']);
        $r->post('/suppliers/{id}',               [SupplierController::class, 'update']);
        $r->post('/purchase-orders',              [PurchaseOrderController::class, 'store']);
        $r->post('/purchase-orders/{id}',         [PurchaseOrderController::class, 'update']);
        $r->post('/purchase-orders/{id}/status',  [PurchaseOrderController::class, 'updateStatus']);
    });

    // Receiving is production's job as much as finance's.
    $r->post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive'],
             ['permission:purchases.receive', 'csrf']);

    $r->post('/suppliers/{id}/delete',        [SupplierController::class, 'destroy'],
             ['permission:records.delete', 'permission:purchases.delete', 'csrf']);
    $r->post('/purchase-orders/{id}/delete',  [PurchaseOrderController::class, 'destroy'],
             ['permission:records.delete', 'permission:purchases.delete', 'csrf']);

    // -- Services
    $r->group(['permission:services.view'], function ($r) {
        $r->get('/services',           [ServiceController::class, 'index']);
        $r->get('/services/create',    [ServiceController::class, 'create']);
        $r->get('/services/{id}',      [ServiceController::class, 'show']);
        $r->get('/services/{id}/edit', [ServiceController::class, 'edit']);
    });

    $r->group(['permission:services.manage', 'csrf'], function ($r) {
        $r->post('/services',             [ServiceController::class, 'store']);
        $r->post('/services/{id}',        [ServiceController::class, 'update']);
        $r->post('/services/{id}/delete', [ServiceController::class, 'destroy'], ['permission:records.delete']);

        // Past work shown as an example of a service.
        $r->post('/services/{id}/examples',              [ServiceController::class, 'linkJob']);
        $r->post('/services/{id}/examples/{job}/remove', [ServiceController::class, 'unlinkJob']);

        $r->post('/services/{id}/images/{imageId}/delete',  [ServiceController::class, 'deleteImage'], ['permission:records.delete']);
        $r->post('/services/{id}/images/{imageId}/primary', [ServiceController::class, 'setPrimaryImage']);
    });

    // -- Clients
    $r->group(['permission:clients.view'], function ($r) {
        $r->get('/clients',           [ClientController::class, 'index']);
        $r->get('/clients/export',    [ClientController::class, 'export']);
        $r->get('/clients/create',    [ClientController::class, 'create']);
        $r->get('/clients/{id}',           [ClientController::class, 'show']);
        $r->get('/clients/{id}/statement', [ClientController::class, 'statement']);
        $r->get('/clients/{id}/edit',      [ClientController::class, 'edit']);
    });

    $r->group(['permission:clients.manage', 'csrf'], function ($r) {
        $r->post('/clients',      [ClientController::class, 'store']);
        $r->post('/clients/{id}', [ClientController::class, 'update']);
    });

    $r->post('/clients/{id}/statement/send', [ClientController::class, 'sendStatement'],
             ['permission:documents.manage', 'csrf']);

    $r->post('/clients/{id}/delete', [ClientController::class, 'destroy'], ['permission:records.delete', 'permission:clients.delete', 'csrf']);

    // -- Proposals, quotations, invoices, receipts and agreements
    // The doc type is bound by the route, so it can never come from user input.
    foreach ([
        'proposal'  => 'proposals',
        'quotation' => 'quotations',
        'invoice'   => 'invoices',
        'receipt'   => 'receipts',
        'agreement' => 'agreements',
    ] as $type => $path) {

        $r->get("/{$path}", function (Request $req) use ($type) {
            (new DocumentController())->index($req, $type);
        }, ['permission:documents.view']);

        $r->get("/{$path}/create", function (Request $req) use ($type) {
            (new DocumentController())->create($req, $type);
        }, ['permission:documents.manage']);

        $r->get("/{$path}/{id}", function (Request $req) use ($type) {
            (new DocumentController())->show($req, $type);
        }, ['permission:documents.view']);

        $r->get("/{$path}/{id}/print", function (Request $req) use ($type) {
            (new DocumentController())->print($req, $type);
        }, ['permission:documents.view']);

        $r->get("/{$path}/{id}/edit", function (Request $req) use ($type) {
            (new DocumentController())->edit($req, $type);
        }, ['permission:documents.manage']);

        $r->post("/{$path}", function (Request $req) use ($type) {
            (new DocumentController())->store($req, $type);
        }, ['permission:documents.manage', 'csrf']);

        $r->post("/{$path}/{id}", function (Request $req) use ($type) {
            (new DocumentController())->update($req, $type);
        }, ['permission:documents.manage', 'csrf']);

        $r->post("/{$path}/{id}/status", function (Request $req) use ($type) {
            (new DocumentController())->updateStatus($req, $type);
        }, ['permission:documents.manage', 'csrf']);

        $r->post("/{$path}/{id}/duplicate", function (Request $req) use ($type) {
            (new DocumentController())->duplicate($req, $type);
        }, ['permission:documents.manage', 'csrf']);

        // Approving is an administrator's job; the check is in the
        // controller so the message explains why rather than just refusing.
        $r->post("/{$path}/{id}/approve", function (Request $req) use ($type) {
            (new DocumentController())->approve($req, $type);
        }, ['permission:documents.view', 'csrf']);

        $r->post("/{$path}/{id}/send-back", function (Request $req) use ($type) {
            (new DocumentController())->sendBack($req, $type);
        }, ['permission:documents.view', 'csrf']);

        $r->post("/{$path}/{id}/delete", function (Request $req) use ($type) {
            (new DocumentController())->destroy($req, $type);
        }, ['permission:records.delete', 'permission:documents.delete', 'csrf']);
    }

    $r->post('/quotations/{id}/convert', [DocumentController::class, 'convertToInvoice'],
             ['permission:documents.manage', 'csrf']);
    $r->post('/invoices/{id}/receipt',   [DocumentController::class, 'generateReceipt'],
             ['permission:documents.manage', 'csrf']);

    // Proposal → Quotation, and the agreement a client signs. Both keep the
    // parent link, so each document shows what it came from.
    $r->post('/proposals/{id}/convert',  [DocumentController::class, 'convertToQuotation'],
             ['permission:documents.manage', 'csrf']);
    $r->post('/proposals/{id}/agreement',  [DocumentController::class, 'generateAgreement'],
             ['permission:documents.manage', 'csrf']);
    $r->post('/quotations/{id}/agreement', [DocumentController::class, 'generateAgreement'],
             ['permission:documents.manage', 'csrf']);

    // -- Artwork: a design request through to approved artwork
    //
    // Registered before the {id} routes so /artwork/create is not read as
    // a request for the artwork numbered "create".
    $r->group(['permission:artwork.view'], function ($r) {
        $r->get('/artwork',           [ArtworkController::class, 'index']);
        $r->get('/artwork/create',    [ArtworkController::class, 'create']);
        $r->get('/artwork/{id}',      [ArtworkController::class, 'show']);
        $r->get('/artwork/{id}/edit', [ArtworkController::class, 'edit']);
    });

    $r->group(['permission:artwork.manage', 'csrf'], function ($r) {
        $r->post('/artwork',                 [ArtworkController::class, 'store']);
        $r->post('/artwork/{id}',            [ArtworkController::class, 'update']);
        $r->post('/artwork/{id}/upload',     [ArtworkController::class, 'upload']);
        $r->post('/artwork/{id}/decide',     [ArtworkController::class, 'decide']);
        $r->post('/artwork/{id}/production', [ArtworkController::class, 'pushToProduction']);
    });

    $r->post('/artwork/{id}/assign', [ArtworkController::class, 'assign'],
             ['permission:artwork.assign', 'csrf']);
    $r->post('/artwork/{id}/send',   [ArtworkController::class, 'sendProof'],
             ['permission:artwork.design', 'csrf']);
    $r->post('/artwork/{id}/delete', [ArtworkController::class, 'destroy'],
             ['permission:records.delete', 'permission:artwork.delete', 'csrf']);

    // -- The bell: what the system needs to tell whoever is signed in.
    // Any signed-in user has alerts, so this needs no permission of its own.
    $r->get('/alerts',           [StaffNotificationController::class, 'index']);
    $r->get('/alerts/{id}/open', [StaffNotificationController::class, 'open']);
    $r->post('/alerts/read-all', [StaffNotificationController::class, 'readAll'], ['csrf']);

    // -- Production job cards
    $r->group(['permission:jobs.view'], function ($r) {
        $r->get('/jobs',           [JobController::class, 'index']);
        $r->get('/jobs/create',    [JobController::class, 'create']);
        $r->get('/jobs/{id}',      [JobController::class, 'show']);
        $r->get('/jobs/{id}/edit', [JobController::class, 'edit']);
        $r->get('/jobs/{id}/print',[JobController::class, 'printCard']);
    });

    $r->group(['permission:jobs.manage', 'csrf'], function ($r) {
        $r->post('/jobs',                            [JobController::class, 'store']);
        $r->post('/jobs/{id}',                       [JobController::class, 'update']);
        $r->post('/jobs/{id}/stage',                 [JobController::class, 'moveStage']);
        $r->post('/jobs/{id}/note',                  [JobController::class, 'addNote']);
        $r->post('/jobs/{id}/items/{itemId}/toggle', [JobController::class, 'toggleItem']);

        $r->post('/jobs/{id}/files',           [JobFileController::class, 'upload']);
        $r->post('/jobs/files/{fileId}/decide',[JobFileController::class, 'decide']);
        $r->post('/jobs/files/{fileId}/delete',[JobFileController::class, 'destroy'], ['permission:records.delete']);
    });

    $r->post('/jobs/{id}/assign', [JobController::class, 'assign'],  ['permission:jobs.assign', 'csrf']);
    $r->post('/jobs/{id}/delete', [JobController::class, 'destroy'], ['permission:records.delete', 'permission:jobs.delete', 'csrf']);

    // Raise a job card straight from an invoice or quotation
    $r->post('/documents/{id}/job', [JobController::class, 'createFromDocument'],
             ['permission:jobs.manage', 'csrf']);

    // -- Delivery notes
    $r->group(['permission:delivery.view'], function ($r) {
        $r->get('/delivery-notes',             [DeliveryNoteController::class, 'index']);
        $r->get('/delivery-notes/{id}',        [DeliveryNoteController::class, 'show']);
        $r->get('/delivery-notes/{id}/print',  [DeliveryNoteController::class, 'print']);
    });

    $r->group(['permission:delivery.manage', 'csrf'], function ($r) {
        $r->post('/jobs/{id}/delivery-note',      [DeliveryNoteController::class, 'createFromJob']);
        $r->post('/delivery-notes/{id}',          [DeliveryNoteController::class, 'update']);
        $r->post('/delivery-notes/{id}/delete',   [DeliveryNoteController::class, 'destroy'], ['permission:records.delete']);
    });

    // -- Leads
    $r->group(['permission:leads.view'], function ($r) {
        $r->get('/leads',           [LeadController::class, 'index']);
        $r->get('/leads/create',    [LeadController::class, 'create']);
        $r->get('/leads/{id}',      [LeadController::class, 'show']);
        $r->get('/leads/{id}/edit', [LeadController::class, 'edit']);
    });

    $r->group(['permission:leads.manage', 'csrf'], function ($r) {
        $r->post('/leads',                [LeadController::class, 'store']);
        $r->post('/leads/{id}',           [LeadController::class, 'update']);
        $r->post('/leads/{id}/stage',     [LeadController::class, 'moveStage']);
        $r->post('/leads/{id}/activity',  [LeadController::class, 'logActivity']);
        $r->post('/leads/{id}/reminder',  [LeadController::class, 'addReminder']);
    });

    // A lead becomes a quotation or proposal without anyone retyping it.
    $r->post('/leads/{id}/document', [LeadController::class, 'raiseDocument'],
             ['permission:documents.manage', 'csrf']);

    $r->post('/leads/{id}/convert', [LeadController::class, 'convert'], ['permission:clients.manage', 'csrf']);
    $r->post('/leads/{id}/delete',  [LeadController::class, 'destroy'], ['permission:records.delete', 'permission:leads.delete', 'csrf']);

    // -- Reminders (available to every signed-in user)
    $r->get('/reminders',  [ReminderController::class, 'index']);
    $r->group(['csrf'], function ($r) {
        $r->post('/reminders',              [ReminderController::class, 'store']);
        $r->post('/reminders/{id}/done',    [ReminderController::class, 'complete']);
        $r->post('/reminders/{id}/snooze',  [ReminderController::class, 'snooze']);
        $r->post('/reminders/{id}/reopen',  [ReminderController::class, 'reopen']);
        $r->post('/reminders/{id}/delete',  [ReminderController::class, 'destroy'], ['permission:records.delete']);
    });

    // -- Payments
    $r->get('/payments',            [PaymentController::class, 'index'],  ['permission:payments.view']);
    $r->get('/payments/create',     [PaymentController::class, 'create'], ['permission:payments.manage']);
    $r->post('/payments',           [PaymentController::class, 'store'],  ['permission:payments.manage', 'csrf']);
    $r->post('/payments/{id}/reverse', [PaymentController::class, 'reverse'], ['permission:payments.manage', 'csrf']);

    $r->post('/payments/stk',       [PaymentController::class, 'sendStk'],   ['permission:payments.stk', 'csrf']);
    $r->get('/payments/stk/status', [PaymentController::class, 'stkStatus'], ['permission:payments.stk']);

    // -- Expenses
    $r->get('/expenses',        [ExpenseController::class, 'index'],  ['permission:expenses.view']);
    $r->get('/expenses/export', [ExpenseController::class, 'export'], ['permission:expenses.view']);
    $r->get('/expenses/create', [ExpenseController::class, 'create'], ['permission:expenses.manage']);
    $r->get('/expenses/{id}/edit', [ExpenseController::class, 'edit'], ['permission:expenses.manage']);

    $r->group(['permission:expenses.manage', 'csrf'], function ($r) {
        $r->post('/expenses',             [ExpenseController::class, 'store']);
        $r->post('/expenses/{id}',        [ExpenseController::class, 'update']);
        $r->post('/expenses/{id}/delete', [ExpenseController::class, 'destroy'], ['permission:records.delete']);
    });

    // -- WhatsApp (shared company inbox)
    $r->get('/whatsapp',                   [WhatsAppController::class, 'index'],  ['permission:whatsapp.view']);
    $r->get('/whatsapp/unread',            [WhatsAppController::class, 'unread'], ['permission:whatsapp.view']);
    $r->get('/whatsapp/{id}/poll',         [WhatsAppController::class, 'poll'],   ['permission:whatsapp.view']);

    $r->group(['permission:whatsapp.send', 'csrf'], function ($r) {
        $r->post('/whatsapp/start',        [WhatsAppController::class, 'start']);
        $r->post('/whatsapp/{id}/send',    [WhatsAppController::class, 'send']);
        $r->post('/whatsapp/{id}/close',   [WhatsAppController::class, 'close']);
        $r->post('/whatsapp/{id}/client',  [WhatsAppController::class, 'assignClient']);
    });

    // -- Meetings
    $r->get('/meetings',             [MeetingController::class, 'index'],  ['permission:meetings.view']);
    $r->get('/meetings/create',      [MeetingController::class, 'create'], ['permission:meetings.manage']);
    $r->get('/meetings/{id}',        [MeetingController::class, 'show'],   ['permission:meetings.view']);
    $r->get('/meetings/{id}/edit',   [MeetingController::class, 'edit'],   ['permission:meetings.manage']);
    $r->get('/meetings/{id}/room',   [MeetingController::class, 'room'],   ['permission:meetings.view']);

    // Polled while a meeting is running. No CSRF on the GETs — they read.
    $r->get('/meetings/{id}/notes',   [MeetingController::class, 'pollNotes'], ['permission:meetings.view']);
    $r->get('/meetings/{id}/signals', [MeetingController::class, 'signals'],   ['permission:meetings.view']);

    $r->group(['permission:meetings.manage', 'csrf'], function ($r) {
        $r->post('/meetings',                [MeetingController::class, 'store']);
        $r->post('/meetings/{id}',           [MeetingController::class, 'update']);
        $r->post('/meetings/{id}/start',     [MeetingController::class, 'start']);
        $r->post('/meetings/{id}/end',       [MeetingController::class, 'end']);
        $r->post('/meetings/{id}/cancel',    [MeetingController::class, 'cancel']);
        $r->post('/meetings/{id}/minutes',   [MeetingController::class, 'saveMinutes']);
        $r->post('/meetings/{id}/notes',     [MeetingController::class, 'postNote']);
        $r->post('/meetings/{id}/signal',    [MeetingController::class, 'signal']);
    });

    $r->post('/meetings/{id}/delete', [MeetingController::class, 'destroy'],
             ['permission:records.delete', 'permission:meetings.delete', 'csrf']);

    // -- Recurring services (websites, hosting, retainers)
    $r->get('/subscriptions',             [SubscriptionController::class, 'index'],  ['permission:subscriptions.view']);
    $r->get('/subscriptions/create',      [SubscriptionController::class, 'create'], ['permission:subscriptions.manage']);
    $r->get('/subscriptions/{id}',        [SubscriptionController::class, 'show'],   ['permission:subscriptions.view']);
    $r->get('/subscriptions/{id}/edit',   [SubscriptionController::class, 'edit'],   ['permission:subscriptions.manage']);

    $r->group(['permission:subscriptions.manage', 'csrf'], function ($r) {
        $r->post('/subscriptions',              [SubscriptionController::class, 'store']);
        $r->post('/subscriptions/{id}',         [SubscriptionController::class, 'update']);
        $r->post('/subscriptions/{id}/invoice', [SubscriptionController::class, 'invoiceNow']);
        $r->post('/subscriptions/{id}/delete',  [SubscriptionController::class, 'destroy'], ['permission:records.delete']);
    });

    // -- Messages (email & SMS)
    $r->get('/notifications',      [NotificationController::class, 'index'], ['permission:documents.view']);
    $r->get('/notifications/{id}', [NotificationController::class, 'show'],  ['permission:documents.view']);

    $r->post('/documents/{id}/send', [NotificationController::class, 'sendDocument'],
             ['permission:documents.manage', 'csrf']);
    $r->post('/jobs/{id}/notify-ready', [NotificationController::class, 'sendJobReady'],
             ['permission:jobs.manage', 'csrf']);

    // -- Bulk SMS campaigns
    //
    // Separate from the message log: a campaign spends real credit across
    // the whole client list, so it sits behind its own permission.
    $r->get('/sms-campaigns',      [SmsCampaignController::class, 'index'],  ['permission:sms.campaign']);
    $r->get('/sms-campaigns/new',  [SmsCampaignController::class, 'create'], ['permission:sms.campaign']);
    $r->get('/sms-campaigns/{id}', [SmsCampaignController::class, 'show'],   ['permission:sms.campaign']);

    $r->post('/sms-campaigns/preview', [SmsCampaignController::class, 'preview'],
             ['permission:sms.campaign', 'csrf']);
    $r->post('/sms-campaigns',         [SmsCampaignController::class, 'send'],
             ['permission:sms.campaign', 'csrf']);

    // -- The SMS platform
    //
    // Customers and partners sending their own texts, as the office sees
    // it. Each action checks its own permission (bulksms.view, .manage,
    // .approve, .settings); the router only needs to know which are reads.
    $r->get('/bulk-sms',                        [BulkSmsAdminController::class, 'index'],       ['permission:bulksms.view']);
    $r->get('/bulk-sms/accounts',               [BulkSmsAdminController::class, 'accounts'],    ['permission:bulksms.view']);
    $r->get('/bulk-sms/accounts/{id}',          [BulkSmsAdminController::class, 'show'],        ['permission:bulksms.view']);
    $r->get('/bulk-sms/purchases',              [BulkSmsAdminController::class, 'purchases'],   ['permission:bulksms.view']);
    $r->get('/bulk-sms/plans',                  [BulkSmsAdminController::class, 'plans'],       ['permission:bulksms.view']);
    $r->get('/bulk-sms/settings',               [BulkSmsAdminController::class, 'settings'],    ['permission:bulksms.settings']);
    $r->get('/bulk-sms/campaigns',              [BulkSmsTrafficController::class, 'campaigns'], ['permission:bulksms.view']);
    $r->get('/bulk-sms/campaigns/{id}',         [BulkSmsTrafficController::class, 'campaign'],  ['permission:bulksms.view']);
    $r->get('/bulk-sms/messages',               [BulkSmsTrafficController::class, 'messages'],  ['permission:bulksms.view']);
    $r->get('/bulk-sms/sender-ids',             [BulkSmsTrafficController::class, 'senders'],   ['permission:bulksms.view']);
    $r->get('/bulk-sms/sender-ids/{id}/{which}', [BulkSmsTrafficController::class, 'senderFile'], ['permission:bulksms.view']);

    $r->group(['permission:bulksms.view', 'csrf'], function ($r) {
        $r->post('/bulk-sms/sync',                     [BulkSmsAdminController::class, 'sync']);
        $r->post('/bulk-sms/accounts',                 [BulkSmsAdminController::class, 'open']);
        $r->post('/bulk-sms/accounts/{id}/units',      [BulkSmsAdminController::class, 'units']);
        $r->post('/bulk-sms/accounts/{id}/pricing',    [BulkSmsAdminController::class, 'pricing']);
        $r->post('/bulk-sms/accounts/{id}/status',     [BulkSmsAdminController::class, 'status']);
        $r->post('/bulk-sms/accounts/{id}/supplier',   [BulkSmsAdminController::class, 'supplier']);
        $r->post('/bulk-sms/accounts/{id}/api-key',    [BulkSmsAdminController::class, 'apiKey']);
        $r->post('/bulk-sms/accounts/{id}/api-key/revoke', [BulkSmsAdminController::class, 'revokeKey']);
        $r->post('/bulk-sms/purchases',                [BulkSmsAdminController::class, 'recordPurchase']);
        $r->post('/bulk-sms/purchases/{id}/complete',  [BulkSmsAdminController::class, 'completePurchase']);
        $r->post('/bulk-sms/purchases/{id}/fail',      [BulkSmsAdminController::class, 'failPurchase']);
        $r->post('/bulk-sms/plans',                    [BulkSmsAdminController::class, 'savePlan']);
        $r->post('/bulk-sms/plans/{id}',               [BulkSmsAdminController::class, 'savePlan']);
        $r->post('/bulk-sms/plans/{id}/toggle',        [BulkSmsAdminController::class, 'togglePlan']);
        $r->post('/bulk-sms/plans/{id}/delete',        [BulkSmsAdminController::class, 'deletePlan']);
        $r->post('/bulk-sms/settings',                 [BulkSmsAdminController::class, 'saveSettings']);
        $r->post('/bulk-sms/settings/check',           [BulkSmsAdminController::class, 'check']);
        $r->post('/bulk-sms/campaigns/{id}/cancel',    [BulkSmsTrafficController::class, 'cancel']);
        $r->post('/bulk-sms/campaigns/{id}/retry',     [BulkSmsTrafficController::class, 'retry']);
        $r->post('/bulk-sms/sender-ids',               [BulkSmsTrafficController::class, 'addSender']);
        $r->post('/bulk-sms/sender-ids/whitelist',    [BulkSmsTrafficController::class, 'whitelistSenders']);
        $r->post('/bulk-sms/sender-ids/{id}/assign',   [BulkSmsTrafficController::class, 'assignSender']);
        $r->post('/bulk-sms/sender-ids/{id}/unassign', [BulkSmsTrafficController::class, 'unassignSender']);
        $r->post('/bulk-sms/sender-ids/{id}/decide',   [BulkSmsTrafficController::class, 'decideSender']);
        $r->post('/bulk-sms/sender-ids/{id}/delete',   [BulkSmsTrafficController::class, 'deleteSender']);
    });

    $r->group(['permission:settings.manage', 'csrf'], function ($r) {
        $r->post('/notifications/run',           [NotificationController::class, 'runQueue']);
        $r->post('/notifications/{id}/retry',    [NotificationController::class, 'retry']);
        $r->post('/notifications/{id}/cancel',   [NotificationController::class, 'cancel']);
        $r->post('/settings/messaging',          [SettingsController::class, 'saveMessaging']);
        $r->post('/settings/meetings',           [SettingsController::class, 'saveMeetings']);
        $r->post('/settings/email',              [SettingsController::class, 'saveMailServer']);
        $r->post('/settings/messaging/test',     [NotificationController::class, 'sendTest']);

        // -- Backups
        $r->post('/settings/backups',                 [BackupController::class, 'create']);
        $r->post('/settings/backups/schedule',        [BackupController::class, 'save']);
        $r->post('/settings/backups/{name}/verify',   [BackupController::class, 'verify']);
        $r->post('/settings/backups/{name}/delete',   [BackupController::class, 'delete'], ['permission:records.delete']);
    });

    // Downloading is a GET so the browser can save it directly; it changes
    // nothing, and a POST would need a form per row.
    $r->get('/settings/backups',                  [BackupController::class, 'index'],    ['permission:settings.manage']);
    $r->get('/settings/backups/{name}/download',  [BackupController::class, 'download'], ['permission:settings.manage']);

    // -- Job detail requests
    $r->get('/requests',      [JobRequestController::class, 'index'], ['permission:requests.view']);
    $r->get('/requests/{id}', [JobRequestController::class, 'show'],  ['permission:requests.view']);
    $r->get('/requests/{id}/files/{fileId}', [JobRequestController::class, 'download'], ['permission:requests.view']);

    $r->group(['permission:requests.manage', 'csrf'], function ($r) {
        $r->post('/requests',             [JobRequestController::class, 'store']);
        $r->post('/requests/{id}/send',   [JobRequestController::class, 'send']);
        $r->post('/requests/{id}/status', [JobRequestController::class, 'status']);
        $r->post('/requests/{id}/fill',   [JobRequestController::class, 'saveFill']);
    });

    $r->get('/requests/{id}/fill', [JobRequestController::class, 'fill'], ['permission:requests.manage']);

    // -- Company letters
    $r->get('/letters',            [LetterController::class, 'index'],  ['permission:letters.view']);
    $r->get('/letters/create',     [LetterController::class, 'create'], ['permission:letters.manage']);
    $r->get('/letters/{id}',       [LetterController::class, 'show'],   ['permission:letters.view']);
    $r->get('/letters/{id}/edit',  [LetterController::class, 'edit'],   ['permission:letters.manage']);
    $r->get('/letters/{id}/print', [LetterController::class, 'print'],  ['permission:letters.view']);

    $r->group(['permission:letters.manage', 'csrf'], function ($r) {
        $r->post('/letters',                [LetterController::class, 'store']);
        $r->post('/letters/{id}',           [LetterController::class, 'update']);
        $r->post('/letters/{id}/status',    [LetterController::class, 'status']);
        $r->post('/letters/{id}/duplicate', [LetterController::class, 'duplicate']);
        $r->post('/letters/{id}/delete',    [LetterController::class, 'destroy'], ['permission:records.delete']);
    });

    // -- Portal access requests, decided by an administrator
    $r->get('/portal-requests', [PortalRequestController::class, 'index'], ['permission:clients.manage']);

    $r->group(['permission:clients.manage', 'csrf'], function ($r) {
        $r->post('/portal-requests/{id}/approve', [PortalRequestController::class, 'approve']);
        $r->post('/portal-requests/{id}/reject',  [PortalRequestController::class, 'reject']);
    });

    // -- Reports
    $r->get('/reports',               [ReportController::class, 'index'],           ['permission:reports.view']);
    $r->get('/reports/ageing',        [ReportController::class, 'ageing'],          ['permission:reports.view']);
    $r->get('/reports/ageing/export', [ReportController::class, 'ageingExport'],    ['permission:reports.view']);
    $r->get('/reports/statement',     [ReportController::class, 'exportStatement'], ['permission:reports.view']);

    // -- Live chat with website visitors
    //
    // The permission opens the desk; which departments somebody belongs
    // to decides what is on it. Departments are managed separately, by
    // whoever runs the place rather than whoever works the queue.
    $r->group(['permission:livechat.manage'], function ($r) {
        $r->get ('/livechat/departments',                  [LiveChatAdminController::class, 'index']);
        $r->post('/livechat/departments',                  [LiveChatAdminController::class, 'save'],     ['csrf']);
        $r->post('/livechat/departments/{id}/staff',       [LiveChatAdminController::class, 'setStaff'], ['csrf']);
        $r->post('/livechat/departments/{id}/delete',      [LiveChatAdminController::class, 'delete'],   ['csrf']);
        $r->post('/livechat/settings',                     [LiveChatAdminController::class, 'saveSettings'], ['csrf']);
    });

    $r->group(['permission:livechat.use'], function ($r) {
        $r->get ('/livechat',                   [LiveChatController::class, 'index']);
        $r->get ('/livechat/poll',              [LiveChatController::class, 'poll']);
        $r->get ('/livechat/waiting',           [LiveChatController::class, 'waitingCount']);
        $r->get ('/livechat/{id}',              [LiveChatController::class, 'show']);

        $r->post('/livechat/{id}/reply',        [LiveChatController::class, 'reply'],     ['csrf']);
        $r->post('/livechat/{id}/claim',        [LiveChatController::class, 'claim'],     ['csrf']);
        $r->post('/livechat/{id}/release',      [LiveChatController::class, 'release'],   ['csrf']);
        $r->post('/livechat/{id}/hand',         [LiveChatController::class, 'handTo'],    ['csrf']);
        $r->post('/livechat/{id}/transfer',     [LiveChatController::class, 'transfer'],  ['csrf']);
        $r->post('/livechat/{id}/close',        [LiveChatController::class, 'close'],     ['csrf']);
        $r->post('/livechat/{id}/reopen',       [LiveChatController::class, 'reopen'],    ['csrf']);
    });

    // -- Each member of staff's own mailbox
    //
    // No route here takes a user id: every one works on the signed-in
    // person's own mailbox, which is the whole of its privacy model.
    $r->group(['permission:mail.use'], function ($r) {
        $r->get ('/mail',                                [MailController::class, 'index']);
        $r->get ('/mail/setup',                          [MailController::class, 'setup']);
        $r->get ('/mail/compose',                        [MailController::class, 'compose']);
        $r->get ('/mail/unread',                         [MailController::class, 'unread']);
        $r->get ('/mail/message/{uid}/body',             [MailController::class, 'body']);
        $r->get ('/mail/message/{uid}/attachment/{index}', [MailController::class, 'attachment']);

        $r->post('/mail/setup',      [MailController::class, 'connect'],    ['csrf']);
        $r->post('/mail/disconnect', [MailController::class, 'disconnect'], ['csrf']);
        $r->post('/mail/send',       [MailController::class, 'send'],       ['csrf']);
        $r->post('/mail/action',     [MailController::class, 'action'],     ['csrf']);
    });

    // -- Client testimonials shown on the website
    $r->group(['permission:testimonials.manage'], function ($r) {
        $r->get ('/testimonials',             [TestimonialController::class, 'index']);
        $r->post('/testimonials',             [TestimonialController::class, 'save'],   ['csrf']);
        $r->post('/testimonials/{id}/toggle', [TestimonialController::class, 'toggle'], ['csrf']);
        $r->post('/testimonials/{id}/delete', [TestimonialController::class, 'delete'], ['csrf']);
    });

    // -- Newsletter subscribers from the website footer
    $r->group(['permission:newsletter.view'], function ($r) {
        $r->get ('/newsletter',             [NewsletterController::class, 'index']);
        $r->get ('/newsletter/export',      [NewsletterController::class, 'export']);
        $r->post('/newsletter/{id}/remove', [NewsletterController::class, 'remove'], ['csrf']);
    });

    // -- Chat
    // Discussion attached to a job, client, document or artwork request.
    $r->post('/threads/post', [ThreadController::class, 'post'], ['permission:chat.use', 'csrf']);

    $r->group(['permission:chat.use'], function ($r) {
        $r->get('/chat',                [ChatController::class, 'index']);
        $r->get('/chat/poll',           [ChatController::class, 'poll']);
        $r->get('/chat/search',         [ChatController::class, 'search']);
        $r->get('/chat/unread-count',   [ChatController::class, 'unreadCount']);
        $r->get('/chat/with/{userId}',  [ChatController::class, 'openDirect']);
        $r->get('/chat/{id}',           [ChatController::class, 'index']);

        $r->post('/chat/send',            [ChatController::class, 'send'],          ['csrf']);
        $r->post('/chat/channels',        [ChatController::class, 'createChannel'], ['csrf']);
        $r->post('/chat/{id}/leave',      [ChatController::class, 'leaveChannel'],  ['csrf']);

        // Who is in a channel. Guarded inside the controller rather than
        // by middleware, because the channel's creator may do this too
        // and that is not something a role check can express.
        $r->post('/chat/{id}/members',                 [ChatController::class, 'addMember'],    ['csrf']);
        $r->post('/chat/{id}/members/{userId}/remove', [ChatController::class, 'removeMember'], ['csrf']);
        $r->post('/chat/message/{id}/delete', [ChatController::class, 'deleteMessage'], ['csrf']);
    });

    // -- Administration
    $r->group(['permission:users.view'], function ($r) {
        $r->get('/users',           [UserController::class, 'index']);
        $r->get('/users/create',    [UserController::class, 'create']);
        $r->get('/users/{id}/edit', [UserController::class, 'edit']);
    });

    $r->group(['permission:users.manage', 'csrf'], function ($r) {
        $r->post('/users',             [UserController::class, 'store']);
        $r->post('/users/{id}',        [UserController::class, 'update']);
        $r->post('/users/{id}/toggle', [UserController::class, 'toggleActive']);
        $r->post('/users/{id}/delete', [UserController::class, 'destroy'], ['permission:records.delete']);
    });

    $r->get('/settings', [SettingsController::class, 'index'], ['permission:settings.manage']);

    $r->group(['permission:settings.manage', 'csrf'], function ($r) {
        $r->post('/settings/company',            [SettingsController::class, 'saveCompany']);
        $r->post('/settings/documents',          [SettingsController::class, 'saveDocuments']);
        $r->post('/settings/payments',           [SettingsController::class, 'savePayments']);
        $r->post('/settings/payments/test',      [SettingsController::class, 'testKopokopo']);
        $r->post('/settings/payments/webhook',   [SettingsController::class, 'subscribeWebhook']);
        $r->post('/settings/categories',         [SettingsController::class, 'storeCategory']);
        $r->post('/settings/categories/{id}/delete', [SettingsController::class, 'destroyCategory'], ['permission:records.delete']);
    });

    $r->get('/audit',        [DashboardController::class, 'audit'],       ['permission:audit.view']);
    $r->get('/audit/export', [DashboardController::class, 'auditExport'], ['permission:audit.view']);
});
