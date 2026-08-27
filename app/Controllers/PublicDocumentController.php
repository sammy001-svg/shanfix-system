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
use App\Services\StkPayment;
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

    public function show(Request $request): void
    {
        $doc = $this->findByToken((string) $request->param('token'));

        // A document held for approval must not be readable on its link
        // either — otherwise blocking the send button achieves nothing the
        // moment somebody pastes the link into WhatsApp. The client is not
        // told there is an internal hold; only that it is not ready.
        if (\App\Services\DocumentApproval::isPending($doc)) {
            throw new HttpException(404, 'This document is not ready yet. Please contact us.');
        }

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
    /** Kept as a thin name for the view; the rule lives in StkPayment. */
    public static function payable(array $doc): bool
    {
        return StkPayment::payable($doc);
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

        // The amount, the rate limit and the one-prompt-at-a-time rule live
        // in StkPayment, shared with the client portal. Two copies of that
        // reasoning would be two places to get it wrong, and this is the
        // code that decides how much somebody is asked to pay.
        $result = StkPayment::request($doc, (string) $request->input('phone', ''), 'share link');

        if (!empty($result['stk_id'])) {
            // Held in the session so the page that follows knows which
            // request to watch, without an id in a URL anyone could change.
            Session::put('public_stk_id', (int) $result['stk_id']);
        }

        if (!$result['ok']) {
            Session::error($result['error'] ?? 'The payment could not be started.');
        } elseif (!empty($result['pending'])) {
            Session::warning($result['error']);
        } else {
            Session::success('Check your phone and enter your M-Pesa PIN to complete the payment.');
        }

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
