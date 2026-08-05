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

use App\Controllers\AuthController;
use App\Controllers\ChatController;
use App\Controllers\ClientController;
use App\Controllers\DashboardController;
use App\Controllers\DeliveryNoteController;
use App\Controllers\DocumentController;
use App\Controllers\ExpenseController;
use App\Controllers\InventoryController;
use App\Controllers\JobController;
use App\Controllers\JobFileController;
use App\Controllers\LeadController;
use App\Controllers\NotificationController;
use App\Controllers\PaymentController;
use App\Controllers\PublicDocumentController;
use App\Controllers\PublicProofController;
use App\Controllers\ReminderController;
use App\Controllers\ReportController;
use App\Controllers\ServiceController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Core\Request;
use App\Core\Response;

$r = $app->router();

// ---------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------
$r->get('/',       fn() => Response::to('/dashboard'));
$r->get('/login',  [AuthController::class, 'showLogin'], ['guest']);
$r->post('/login', [AuthController::class, 'login'],     ['guest', 'csrf']);
$r->post('/logout', [AuthController::class, 'logout'],   ['csrf']);

// KopoKopo webhook — no session, no CSRF. Authenticated by HMAC signature.
$r->post('/webhooks/kopokopo', [PaymentController::class, 'kopokopoCallback']);

// Client-facing document view. No login: the 48-char token is the credential.
$r->get('/view/{token}', [PublicDocumentController::class, 'show']);

// The same page on a short URL, for SMS. The full link costs 79 of the 160
// characters in a text, which turns routine messages into two billable parts.
$r->get('/v/{token}', [PublicDocumentController::class, 'show']);

// Client-facing proof approval. Also no login — the token is the credential.
// The client sees the artwork and approves it or asks for changes, which
// moves the job exactly as a staff-recorded decision does.
$r->get('/proof/{token}',          [PublicProofController::class, 'show']);
$r->get('/proof/{token}/file',     [PublicProofController::class, 'file']);
$r->post('/proof/{token}/decide',  [PublicProofController::class, 'decide'], ['csrf']);

// Short form for SMS.
$r->get('/p/{token}', [PublicProofController::class, 'show']);

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
        $r->post('/inventory/{id}/delete', [InventoryController::class, 'destroy']);
    });

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
        $r->post('/services/{id}/delete', [ServiceController::class, 'destroy']);
    });

    // -- Clients
    $r->group(['permission:clients.view'], function ($r) {
        $r->get('/clients',           [ClientController::class, 'index']);
        $r->get('/clients/export',    [ClientController::class, 'export']);
        $r->get('/clients/create',    [ClientController::class, 'create']);
        $r->get('/clients/{id}',      [ClientController::class, 'show']);
        $r->get('/clients/{id}/edit', [ClientController::class, 'edit']);
    });

    $r->group(['permission:clients.manage', 'csrf'], function ($r) {
        $r->post('/clients',      [ClientController::class, 'store']);
        $r->post('/clients/{id}', [ClientController::class, 'update']);
    });

    $r->post('/clients/{id}/delete', [ClientController::class, 'destroy'], ['permission:clients.delete', 'csrf']);

    // -- Quotations, invoices, receipts
    // The doc type is bound by the route, so it can never come from user input.
    foreach (['quotation' => 'quotations', 'invoice' => 'invoices', 'receipt' => 'receipts'] as $type => $path) {

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

        $r->post("/{$path}/{id}/delete", function (Request $req) use ($type) {
            (new DocumentController())->destroy($req, $type);
        }, ['permission:documents.delete', 'csrf']);
    }

    $r->post('/quotations/{id}/convert', [DocumentController::class, 'convertToInvoice'],
             ['permission:documents.manage', 'csrf']);
    $r->post('/invoices/{id}/receipt',   [DocumentController::class, 'generateReceipt'],
             ['permission:documents.manage', 'csrf']);

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
        $r->post('/jobs/files/{fileId}/delete',[JobFileController::class, 'destroy']);
    });

    $r->post('/jobs/{id}/assign', [JobController::class, 'assign'],  ['permission:jobs.assign', 'csrf']);
    $r->post('/jobs/{id}/delete', [JobController::class, 'destroy'], ['permission:jobs.delete', 'csrf']);

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
        $r->post('/delivery-notes/{id}/delete',   [DeliveryNoteController::class, 'destroy']);
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

    $r->post('/leads/{id}/convert', [LeadController::class, 'convert'], ['permission:clients.manage', 'csrf']);
    $r->post('/leads/{id}/delete',  [LeadController::class, 'destroy'], ['permission:leads.delete', 'csrf']);

    // -- Reminders (available to every signed-in user)
    $r->get('/reminders',  [ReminderController::class, 'index']);
    $r->group(['csrf'], function ($r) {
        $r->post('/reminders',             [ReminderController::class, 'store']);
        $r->post('/reminders/{id}/done',   [ReminderController::class, 'complete']);
        $r->post('/reminders/{id}/reopen', [ReminderController::class, 'reopen']);
        $r->post('/reminders/{id}/delete', [ReminderController::class, 'destroy']);
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
        $r->post('/expenses/{id}/delete', [ExpenseController::class, 'destroy']);
    });

    // -- Messages (email & SMS)
    $r->get('/notifications',      [NotificationController::class, 'index'], ['permission:documents.view']);
    $r->get('/notifications/{id}', [NotificationController::class, 'show'],  ['permission:documents.view']);

    $r->post('/documents/{id}/send', [NotificationController::class, 'sendDocument'],
             ['permission:documents.manage', 'csrf']);
    $r->post('/jobs/{id}/notify-ready', [NotificationController::class, 'sendJobReady'],
             ['permission:jobs.manage', 'csrf']);

    $r->group(['permission:settings.manage', 'csrf'], function ($r) {
        $r->post('/notifications/run',           [NotificationController::class, 'runQueue']);
        $r->post('/notifications/{id}/retry',    [NotificationController::class, 'retry']);
        $r->post('/notifications/{id}/cancel',   [NotificationController::class, 'cancel']);
        $r->post('/settings/messaging',          [SettingsController::class, 'saveMessaging']);
        $r->post('/settings/messaging/test',     [NotificationController::class, 'sendTest']);
    });

    // -- Reports
    $r->get('/reports',           [ReportController::class, 'index'],           ['permission:reports.view']);
    $r->get('/reports/statement', [ReportController::class, 'exportStatement'], ['permission:reports.view']);

    // -- Chat
    $r->group(['permission:chat.use'], function ($r) {
        $r->get('/chat',                [ChatController::class, 'index']);
        $r->get('/chat/poll',           [ChatController::class, 'poll']);
        $r->get('/chat/unread-count',   [ChatController::class, 'unreadCount']);
        $r->get('/chat/with/{userId}',  [ChatController::class, 'openDirect']);
        $r->get('/chat/{id}',           [ChatController::class, 'index']);

        $r->post('/chat/send',            [ChatController::class, 'send'],          ['csrf']);
        $r->post('/chat/channels',        [ChatController::class, 'createChannel'], ['csrf']);
        $r->post('/chat/{id}/leave',      [ChatController::class, 'leaveChannel'],  ['csrf']);
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
        $r->post('/users/{id}/delete', [UserController::class, 'destroy']);
    });

    $r->get('/settings', [SettingsController::class, 'index'], ['permission:settings.manage']);

    $r->group(['permission:settings.manage', 'csrf'], function ($r) {
        $r->post('/settings/company',            [SettingsController::class, 'saveCompany']);
        $r->post('/settings/documents',          [SettingsController::class, 'saveDocuments']);
        $r->post('/settings/payments',           [SettingsController::class, 'savePayments']);
        $r->post('/settings/payments/test',      [SettingsController::class, 'testKopokopo']);
        $r->post('/settings/payments/webhook',   [SettingsController::class, 'subscribeWebhook']);
        $r->post('/settings/categories',         [SettingsController::class, 'storeCategory']);
        $r->post('/settings/categories/{id}/delete', [SettingsController::class, 'destroyCategory']);
    });

    $r->get('/audit', [DashboardController::class, 'audit'], ['permission:audit.view']);
});
