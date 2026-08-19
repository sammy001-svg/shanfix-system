<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\KopoKopo;
use App\Services\Notifier;

/**
 * The client-facing view of a quotation, invoice or receipt.
 *
 * Reached from an email link with an unguessable token — no account, no
 * login. Shows nothing beyond the document itself: no internal notes, no
 * costs, no other clients.
 *
 * It is read-only with one exception: an unpaid invoice can be paid from
 * here by M-Pesa. That turns a link sent by SMS into a payment, without
 * anyone at the office having to be at a desk. See pay() for the limits
 * that keep an open endpoint from becoming a way to pester phone numbers.
 */
class PublicDocumentController extends Controller
{
    /** Kept in step with the length Notifier builds short links from. */
    private const SHORT_TOKEN_LENGTH = Notifier::SHORT_TOKEN_LENGTH;

    /**
     * Payment prompts allowed per invoice per hour.
     *
     * A prompt costs the recipient nothing but interrupts them, and this
     * endpoint needs no login — so without a ceiling, anyone holding a
     * share link could use it to bombard whatever number they typed. Six
     * is generous for a client fumbling their PIN and useless for a
     * nuisance.
     */
    private const MAX_ATTEMPTS_PER_HOUR = 6;

    public function show(Request $request): void
    {
        $doc = $this->findByToken((string) $request->param('token'));

        $items = Database::all(
            'SELECT description, quantity, unit, unit_price, line_total
               FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $doc['id']]
        );

        $payments = Database::all(
            "SELECT amount, method, reference, paid_at, created_at
               FROM payments
              WHERE document_id = :id AND status = 'completed'
           ORDER BY created_at",
            ['id' => $doc['id']]
        );

        $sections = Database::all(
            'SELECT * FROM document_sections WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $doc['id']]
        );

        // Record the first open so the team can see the client received it.
        if (empty($doc['viewed_at'])) {
            Database::update('documents', ['viewed_at' => date('Y-m-d H:i:s')], ['id' => $doc['id']]);
        }

        $this->view('public/document', [
            'title'     => $doc['doc_number'],
            'doc'       => $doc,
            'items'     => $items,
            'sections'  => $sections,
            'payments'  => $payments,
            'company'   => Settings::company(),
            'token'     => $doc['public_token'],
            'autoPrint' => $request->query('print') === '1',
        ], 'public');
    }

    /**
     * Token lookup. Only documents that have actually been issued are
     * reachable — a draft is still internal.
     */
    /**
     * Resolve either the full 48-character token from an email link, or the
     * short prefix used in SMS.
     *
     * The short form exists purely to save money: the full link eats 79 of
     * the 160 characters in a text, which pushes routine messages to two
     * billable parts. Ten hex characters is still 40 bits — far too many to
     * guess — and an ambiguous prefix is refused rather than resolved to the
     * wrong client's document.
     */
    private function findByToken(string $token): array
    {
        $token = strtolower(trim($token));

        // Cheap shape check before touching the database.
        if (!preg_match('/^[a-f0-9]{48}$/', $token) && !preg_match('/^[a-f0-9]{10}$/', $token)) {
            throw new HttpException(404, 'This link is not valid.');
        }

        $isShort = strlen($token) === self::SHORT_TOKEN_LENGTH;

        $rows = Database::all(
            'SELECT d.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
                    c.address AS client_address, c.city AS client_city,
                    c.kra_pin AS client_kra_pin, c.contact_person AS client_contact
               FROM documents d
               JOIN clients c ON c.id = d.client_id
              WHERE ' . ($isShort ? 'LEFT(d.public_token, :len) = :token' : 'd.public_token = :token') . '
              LIMIT 2',
            $isShort
                ? ['len' => self::SHORT_TOKEN_LENGTH, 'token' => $token]
                : ['token' => $token]
        );

        // Two matches means the prefix is ambiguous — never guess which.
        if (count($rows) > 1) {
            throw new HttpException(404, 'This link is not valid. Please ask us to resend it.');
        }

        $doc = $rows[0] ?? null;

        if (!$doc) {
            throw new HttpException(404, 'This link is no longer valid. Please ask us to resend it.');
        }

        if ($doc['status'] === 'draft') {
            throw new HttpException(404, 'This document is not available yet.');
        }

        if ($doc['status'] === 'cancelled') {
            throw new HttpException(410, 'This document has been cancelled. Please contact us for an up-to-date copy.');
        }

        return $doc;
    }

    /**
     * Whether this document can be paid from the share link right now.
     *
     * Checked in the view to decide whether to offer payment, and again in
     * pay() before acting — the view only decides what to draw, and a POST
     * can arrive without it.
     */
    public static function payable(array $doc): bool
    {
        return $doc['doc_type'] === 'invoice'
            && (float) $doc['balance'] > 0.009
            && !in_array($doc['status'], ['cancelled', 'draft', 'paid'], true)
            && Settings::bool('kopokopo_enabled');
    }

    /**
     * Send an M-Pesa prompt for an invoice opened from a share link.
     *
     * No session, so the token is the only credential — which shapes
     * everything here:
     *
     *   - The amount is taken from the invoice, never from the form. A
     *     posted amount would let anyone settle a 50,000 invoice for 5.
     *   - Attempts are capped per invoice per hour, so the endpoint cannot
     *     be used to pester a phone number.
     *   - Nothing is revealed on failure beyond what the page already
     *     shows, so this cannot be used to probe for valid tokens.
     */
    public function pay(Request $request): void
    {
        $doc = $this->findByToken((string) $request->param('token'));

        if (!self::payable($doc)) {
            Session::error('This invoice cannot be paid online. Please contact us.');
            Response::to('/view/' . $doc['public_token']);
        }

        $phone = normalize_phone((string) $request->input('phone', ''));

        if ($phone === null) {
            Session::error('Please enter a valid M-Pesa number, for example 0712345678.');
            Response::to('/view/' . $doc['public_token']);
        }

        // Always the full balance, read from the invoice. The form asks only
        // for a phone number, so any amount arriving in this POST was put
        // there by hand — there is no legitimate request it could come from.
        // Part payment from the client side would need a visible field and a
        // deliberate decision to allow it; until then, accepting a number
        // here would only be a way to fire prompts for arbitrary sums.
        $amount = (float) $doc['balance'];

        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM stk_requests
              WHERE document_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            ['id' => $doc['id']],
            0
        );

        if ($recent >= self::MAX_ATTEMPTS_PER_HOUR) {
            Session::error('Too many payment attempts on this invoice. Please try again later, or contact us to pay another way.');
            Response::to('/view/' . $doc['public_token']);
        }

        // One prompt at a time — a second while the first is still on the
        // client's handset only confuses them.
        $pending = Database::first(
            "SELECT id FROM stk_requests
              WHERE document_id = :id AND status = 'pending'
                AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
              LIMIT 1",
            ['id' => $doc['id']]
        );

        if ($pending) {
            Session::put('public_stk_id', (int) $pending['id']);
            Session::warning('A payment request is already on your phone. Enter your M-Pesa PIN to complete it.');
            Response::to('/view/' . $doc['public_token']);
        }

        $client = Database::first('SELECT * FROM clients WHERE id = :id', ['id' => $doc['client_id']]);

        $names     = preg_split('/\s+/', trim((string) ($client['name'] ?? 'Client')));
        $firstName = $names[0] ?? 'Client';
        $lastName  = count($names) > 1 ? end($names) : '-';

        // Recorded before the call goes out, so a callback that arrives
        // before the HTTP response still finds a row to attach itself to.
        $stkId = Database::insert('stk_requests', [
            'document_id'  => (int) $doc['id'],
            'client_id'    => (int) $doc['client_id'],
            'phone'        => $phone,
            'amount'       => $amount,
            'status'       => 'pending',
            'initiated_by' => null,   // the client did this, not a member of staff
        ]);

        $result = (new KopoKopo())->stkPush(
            phone:       $phone,
            amount:      $amount,
            callbackUrl: Notifier::absoluteUrl('/webhooks/kopokopo'),
            reference:   (string) $doc['doc_number'],
            firstName:   $firstName,
            lastName:    $lastName,
            email:       $client['email'] ?: null,
            metadata:    [
                'client_id'   => (string) $doc['client_id'],
                'document_id' => (string) $doc['id'],
                'stk_id'      => (string) $stkId,
                'source'      => 'share_link',
            ]
        );

        Database::update('stk_requests', [
            'kopokopo_id'      => $result['id'] ?? null,
            'location_url'     => $result['location'] ?? null,
            'request_payload'  => json_encode($result['request'] ?? [], JSON_UNESCAPED_SLASHES),
            'response_payload' => mb_substr((string) ($result['response'] ?? ''), 0, 4000),
            'status'           => $result['ok'] ? 'pending' : 'failed',
            'result_desc'      => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? ''), 0, 255),
        ], ['id' => $stkId]);

        if (!$result['ok']) {
            ActivityLog::record('stk_failed', 'stk_request', $stkId,
                'Client-initiated STK Push failed for ' . $doc['doc_number']);
            Session::error('We could not reach M-Pesa just now. Please try again in a moment.');
            Response::to('/view/' . $doc['public_token']);
        }

        ActivityLog::record('stk_sent', 'stk_request', $stkId,
            'Client paid ' . $doc['doc_number'] . ' from the share link — prompt sent to ' . $phone);

        // Held in the session so the page that follows knows which request
        // to watch, without putting the id in a URL anyone could change.
        Session::put('public_stk_id', $stkId);
        Session::success('Check your phone and enter your M-Pesa PIN to complete the payment.');
        Response::to('/view/' . $doc['public_token']);
    }

    /**
     * Polled by the client's browser while a prompt is outstanding.
     *
     * Answers only about the request this visitor started, matched against
     * both the session and the document behind the token — a guessed id
     * belonging to another invoice tells them nothing.
     */
    public function payStatus(Request $request): void
    {
        $doc = $this->findByToken((string) $request->param('token'));
        $id  = (int) Session::get('public_stk_id', 0);

        if ($id <= 0) {
            Response::json(['ok' => false, 'error' => 'Nothing to check.'], 404);
        }

        $stk = Database::first(
            'SELECT * FROM stk_requests WHERE id = :id AND document_id = :doc',
            ['id' => $id, 'doc' => $doc['id']]
        );

        if (!$stk) {
            Response::json(['ok' => false, 'error' => 'Nothing to check.'], 404);
        }

        // Same treatment the staff page gets: chase the provider if their
        // webhook is late, and give up on a prompt nobody answered.
        $stk = (new PaymentController())->reconcilePending($stk);

        $fresh = Database::first('SELECT balance, status FROM documents WHERE id = :id', ['id' => $doc['id']]);

        Response::json([
            'ok'      => true,
            'status'  => $stk['status'],
            'message' => match ($stk['status']) {
                'success'   => 'Payment received. Thank you.',
                'failed'    => $stk['result_desc'] ?: 'The payment did not go through.',
                'cancelled' => 'The request was cancelled on your phone.',
                'timeout'   => 'The request timed out. You can try again.',
                default     => 'Waiting for you to enter your M-Pesa PIN…',
            },
            'balance' => money($fresh['balance'] ?? 0),
        ]);
    }

    /**
     * The client accepting an agreement on their share link.
     *
     * What makes this stand as evidence is not the click but the record of
     * it: who typed their name, when, and from where. Kept on the document
     * so it prints alongside the clauses that were agreed.
     */
    public function accept(Request $request): void
    {
        $token = (string) $request->param('token');
        $doc   = $this->findByToken($token);

        if ($doc['doc_type'] !== 'agreement') {
            throw new HttpException(404, 'This document is not an agreement.');
        }

        if ($doc['status'] === 'cancelled') {
            Session::error('This agreement has been withdrawn. Please contact us.');
            Response::to('/view/' . $token);
        }

        // Already accepted: show it rather than letting a stale tab or a
        // forwarded link record a second, contradictory acceptance.
        if (!empty($doc['accepted_at'])) {
            Session::info('This agreement was already accepted on ' . fdate($doc['accepted_at']) . '.');
            Response::to('/view/' . $token);
        }

        $name = trim((string) $request->input('accepted_name', ''));

        if ($name === '') {
            Session::error('Please type your full name to accept.');
            Response::to('/view/' . $token);
        }

        if (!$request->bool('confirm')) {
            Session::error('Please tick the box to confirm you have read and agree to the terms.');
            Response::to('/view/' . $token);
        }

        Database::update('documents', [
            'status'        => 'accepted',
            'accepted_at'   => date('Y-m-d H:i:s'),
            'accepted_name' => mb_substr($name, 0, 160),
            'accepted_ip'   => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ], ['id' => $doc['id']]);

        ActivityLog::record(
            'agreement_accepted',
            'document',
            (int) $doc['id'],
            $doc['doc_number'] . ' accepted online by ' . $name
        );

        Session::success('Thank you — your acceptance is recorded and we will be in touch to begin.');
        Response::to('/view/' . $token);
    }
}
