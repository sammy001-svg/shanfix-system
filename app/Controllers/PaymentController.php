<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\KopoKopo;
use App\Services\PaymentPoster;

class PaymentController extends Controller
{
    private const METHODS = ['mpesa_stk', 'mpesa_manual', 'bank', 'cash', 'cheque', 'other'];

    public function index(Request $request): void
    {
        $search = (string) $request->query('q', '');
        $method = (string) $request->query('method', '');
        $status = (string) $request->query('status', '');
        $from   = (string) $request->query('from', '');
        $to     = (string) $request->query('to', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(p.payment_number LIKE :q OR p.reference LIKE :q2 OR c.name LIKE :q3 OR d.doc_number LIKE :q4)';
            $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }

        if (in_array($method, self::METHODS, true)) {
            $where[] = 'p.method = :method';
            $params['method'] = $method;
        }

        if (in_array($status, ['pending', 'completed', 'failed', 'cancelled'], true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }

        if ($from !== '' && strtotime($from)) {
            $where[] = 'DATE(COALESCE(p.paid_at, p.created_at)) >= :from';
            $params['from'] = date('Y-m-d', strtotime($from));
        }

        if ($to !== '' && strtotime($to)) {
            $where[] = 'DATE(COALESCE(p.paid_at, p.created_at)) <= :to';
            $params['to'] = date('Y-m-d', strtotime($to));
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*)
               FROM payments p
               JOIN clients c ON c.id = p.client_id
          LEFT JOIN documents d ON d.id = p.document_id
              WHERE {$clause}",
            $params,
            0
        );
        $pager = $this->paginate($total, 30);

        $payments = Database::all(
            "SELECT p.*, c.name AS client_name, d.doc_number, u.name AS recorded_by_name
               FROM payments p
               JOIN clients c ON c.id = p.client_id
          LEFT JOIN documents d ON d.id = p.document_id
          LEFT JOIN users u ON u.id = p.recorded_by
              WHERE {$clause}
           ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT
                COALESCE(SUM(CASE WHEN status='completed' THEN amount END), 0) AS collected_all,
                COALESCE(SUM(CASE WHEN status='completed'
                                   AND MONTH(COALESCE(paid_at, created_at)) = MONTH(CURDATE())
                                   AND YEAR(COALESCE(paid_at, created_at)) = YEAR(CURDATE())
                             THEN amount END), 0) AS collected_month,
                COALESCE(SUM(CASE WHEN status='completed' AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
                             THEN amount END), 0) AS collected_today,
                COUNT(CASE WHEN status='pending' THEN 1 END) AS pending_count
               FROM payments"
        );

        $this->view('payments/index', [
            'title'    => 'Payments',
            'payments' => $payments,
            'pager'    => $pager,
            'summary'  => $summary,
            'methods'  => self::METHODS,
            'filters'  => compact('search', 'method', 'status', 'from', 'to'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('payments.manage');

        $documentId = (int) $request->query('document_id', 0);
        $clientId   = (int) $request->query('client_id', 0);

        $document = null;
        if ($documentId > 0) {
            $document = Database::first(
                "SELECT d.*, c.name AS client_name
                   FROM documents d JOIN clients c ON c.id = d.client_id
                  WHERE d.id = :id AND d.doc_type = 'invoice'",
                ['id' => $documentId]
            );

            if ($document) {
                $clientId = (int) $document['client_id'];
            }
        }

        $clients = Database::all("SELECT id, name, client_code FROM clients ORDER BY name");

        $openInvoices = $clientId > 0
            ? Database::all(
                "SELECT id, doc_number, total, balance, due_date
                   FROM documents
                  WHERE client_id = :cid AND doc_type = 'invoice'
                    AND status NOT IN ('cancelled','paid','draft') AND balance > 0
               ORDER BY due_date ASC",
                ['cid' => $clientId]
            )
            : [];

        $this->view('payments/form', [
            'title'        => 'Record Payment',
            'document'     => $document,
            'clients'      => $clients,
            'openInvoices' => $openInvoices,
            'clientId'     => $clientId,
            'methods'      => self::METHODS,
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('payments.manage');

        $v = new Validator($request->all());
        $v->require('client_id', 'Client')
          ->exists('client_id', 'clients', 'client')
          ->numeric('amount', 'Amount', true)
          ->min('amount', 0.01, 'Amount')
          ->in('method', self::METHODS, 'Payment method')
          ->date('paid_at', 'Payment date')
          ->maxLen('reference', 120, 'Reference')
          ->maxLen('notes', 255, 'Notes');

        $documentId = $request->int('document_id') ?: null;
        $amount     = $request->decimal('amount');

        if ($documentId) {
            $invoice = Database::first(
                "SELECT id, balance, doc_number, client_id, status
                   FROM documents WHERE id = :id AND doc_type = 'invoice'",
                ['id' => $documentId]
            );

            if (!$invoice) {
                $v->custom('document_id', false, 'That invoice does not exist.');
            } elseif ((int) $invoice['client_id'] !== $request->int('client_id')) {
                $v->custom('document_id', false, 'That invoice belongs to a different client.');
            } elseif ($invoice['status'] === 'cancelled') {
                $v->custom('document_id', false, 'This invoice has been cancelled.');
            } elseif ($amount > (float) $invoice['balance'] + 0.01) {
                $v->custom(
                    'amount',
                    false,
                    'That is more than the outstanding balance of ' . money($invoice['balance']) . '.'
                );
            }
        }

        if ($v->fails()) {
            $v->redirectBack('/payments/create');
        }

        $paidAt = $request->input('paid_at')
            ? date('Y-m-d H:i:s', strtotime((string) $request->input('paid_at')))
            : date('Y-m-d H:i:s');

        $result = PaymentPoster::post(
            clientId:   $request->int('client_id'),
            amount:     $amount,
            method:     (string) $request->input('method', 'cash'),
            documentId: $documentId,
            reference:  $request->input('reference') ?: null,
            notes:      $request->input('notes') ?: null,
            recordedBy: Auth::id(),
            paidAt:     $paidAt
        );

        ActivityLog::record(
            'payment_recorded',
            'payment',
            $result['payment_id'],
            'Recorded ' . money($amount) . ' via ' . label_of((string) $request->input('method'))
        );

        Session::success('Payment ' . $result['payment_number'] . ' recorded (' . money($amount) . ').');

        Response::to($documentId ? '/invoices/' . $documentId : '/payments');
    }

    public function reverse(Request $request): void
    {
        $this->authorize('payments.manage');

        $id      = $request->paramInt('id');
        $payment = Database::first('SELECT * FROM payments WHERE id = :id', ['id' => $id]);

        if (!$payment) {
            throw new HttpException(404, 'That payment does not exist.');
        }

        if ($payment['status'] !== 'completed') {
            Session::error('Only completed payments can be reversed.');
            Response::back('/payments');
        }

        PaymentPoster::reverse($id, Auth::id());

        Session::success('Payment ' . $payment['payment_number'] . ' has been reversed.');
        Response::back('/payments');
    }

    // -- KopoKopo STK Push --------------------------------------------

    public function sendStk(Request $request): void
    {
        $this->authorize('payments.stk');

        if (!Settings::bool('kopokopo_enabled')) {
            Session::error('KopoKopo is not enabled. An administrator must configure it in Settings first.');
            Response::back('/clients');
        }

        $v = new Validator($request->all());
        $v->require('client_id', 'Client')
          ->exists('client_id', 'clients', 'client')
          ->require('phone', 'Phone number')
          ->numeric('amount', 'Amount', true)
          ->min('amount', 1, 'Amount');

        if ($v->fails()) {
            $v->redirectBack('/clients');
        }

        $clientId = $request->int('client_id');
        $amount   = $request->decimal('amount');
        $phone    = normalize_phone((string) $request->input('phone'));

        if ($phone === null) {
            Session::error('That phone number is not valid. Use the format 0712345678 or 254712345678.');
            Response::back('/clients/' . $clientId);
        }

        $client = Database::first('SELECT * FROM clients WHERE id = :id', ['id' => $clientId]);

        $documentId = $request->int('document_id') ?: null;
        $invoice    = null;

        if ($documentId) {
            $invoice = Database::first(
                "SELECT * FROM documents WHERE id = :id AND doc_type = 'invoice' AND client_id = :cid",
                ['id' => $documentId, 'cid' => $clientId]
            );

            if (!$invoice) {
                Session::error('That invoice could not be found for this client.');
                Response::back('/clients/' . $clientId);
            }

            if ($amount > (float) $invoice['balance'] + 0.01) {
                Session::error('That is more than the outstanding balance of ' . money($invoice['balance']) . '.');
                Response::back('/invoices/' . $documentId);
            }
        }

        $redirect = $documentId ? '/invoices/' . $documentId : '/clients/' . $clientId;

        // Don't stack prompts on the same invoice.
        if ($documentId) {
            $existing = Database::first(
                "SELECT id, created_at FROM stk_requests
                  WHERE document_id = :id AND status = 'pending'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 3 MINUTE)
                  LIMIT 1",
                ['id' => $documentId]
            );

            if ($existing) {
                Session::warning('An STK Push for this invoice is already awaiting the client. Give it a moment.');
                Response::to($redirect);
            }
        }

        $reference   = $invoice['doc_number'] ?? ($client['client_code'] . '-' . date('YmdHis'));
        $callbackUrl = $this->callbackUrl();

        $nameParts = preg_split('/\s+/', trim((string) $client['name']));
        $firstName = $nameParts[0] ?? 'Client';
        $lastName  = count($nameParts) > 1 ? end($nameParts) : '-';

        // Log the attempt before calling out, so a callback that beats the
        // HTTP response still finds a row to attach itself to.
        $stkId = Database::insert('stk_requests', [
            'document_id'  => $documentId,
            'client_id'    => $clientId,
            'phone'        => $phone,
            'amount'       => $amount,
            'status'       => 'pending',
            'initiated_by' => Auth::id(),
        ]);

        $kopokopo = new KopoKopo();

        $result = $kopokopo->stkPush(
            phone:       $phone,
            amount:      $amount,
            callbackUrl: $callbackUrl,
            reference:   $reference,
            firstName:   $firstName,
            lastName:    $lastName,
            email:       $client['email'] ?: null,
            metadata:    [
                'client_id'   => (string) $clientId,
                'document_id' => (string) ($documentId ?? ''),
                'stk_id'      => (string) $stkId,
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
            ActivityLog::record('stk_failed', 'stk_request', $stkId, 'STK Push failed: ' . ($result['error'] ?? ''));
            Session::error('STK Push could not be sent: ' . ($result['error'] ?? 'unknown error'));
            Response::to($redirect);
        }

        ActivityLog::record(
            'stk_sent',
            'stk_request',
            $stkId,
            'STK Push of ' . money($amount) . ' sent to ' . $phone
        );

        Session::success('Payment prompt sent to ' . $phone . '. Ask the client to enter their M-Pesa PIN.');
        Response::to($redirect);
    }

    /**
     * Polled by the browser while an STK Push is outstanding.
     */
    public function stkStatus(Request $request): void
    {
        $this->authorize('payments.stk');

        $id  = (int) $request->query('id', 0);
        $stk = Database::first('SELECT * FROM stk_requests WHERE id = :id', ['id' => $id]);

        if (!$stk) {
            Response::json(['ok' => false, 'error' => 'Request not found.'], 404);
        }

        // If the callback has not landed, ask KopoKopo directly. Their webhook
        // can be delayed or lost, and the client should not be left waiting.
        if ($stk['status'] === 'pending' && $stk['location_url']) {
            $ageSeconds = time() - strtotime($stk['created_at']);

            if ($ageSeconds > 12) {
                $poll = (new KopoKopo())->pollStatus($stk['location_url']);

                if ($poll['ok'] && isset($poll['status'])) {
                    $mapped = match (strtolower($poll['status'])) {
                        'success', 'received' => 'success',
                        'failed', 'rejected'  => 'failed',
                        default               => 'pending',
                    };

                    if ($mapped !== 'pending') {
                        $this->settleStk($stk, [
                            'status'      => $mapped,
                            'receipt'     => $poll['receipt'] ?? null,
                            'amount'      => null,
                            'phone'       => null,
                            'kopokopo_id' => $stk['kopokopo_id'],
                            'description' => 'Resolved by status poll',
                        ], json_encode($poll['body'] ?? [], JSON_UNESCAPED_SLASHES));

                        $stk = Database::first('SELECT * FROM stk_requests WHERE id = :id', ['id' => $id]);
                    }
                }
            }
        }

        // Time out prompts the customer clearly abandoned.
        if ($stk['status'] === 'pending' && (time() - strtotime($stk['created_at'])) > 180) {
            Database::update('stk_requests', [
                'status'      => 'timeout',
                'result_desc' => 'No response from the customer within 3 minutes.',
            ], ['id' => $stk['id']]);

            $stk['status'] = 'timeout';
        }

        $message = match ($stk['status']) {
            'success'  => 'Payment of ' . money($stk['amount']) . ' received'
                          . ($stk['mpesa_receipt'] ? ' (M-Pesa ref ' . $stk['mpesa_receipt'] . ').' : '.'),
            'failed'   => $stk['result_desc'] ?: 'The customer declined or the payment failed.',
            'timeout'  => 'The customer did not respond to the prompt in time.',
            'cancelled'=> 'The customer cancelled the payment.',
            default    => 'Waiting for the customer to enter their M-Pesa PIN…',
        };

        Response::json([
            'ok'      => true,
            'status'  => $stk['status'],
            'message' => $message,
            'receipt' => $stk['mpesa_receipt'],
        ]);
    }

    /**
     * KopoKopo webhook. Public route — authenticated by HMAC signature only.
     */
    public function kopokopoCallback(Request $request): void
    {
        $rawBody   = $request->rawBody();
        $signature = $_SERVER['HTTP_X_KOPOKOPO_SIGNATURE'] ?? null;

        Logger::info('KopoKopo callback received', ['bytes' => strlen($rawBody)]);

        if (!KopoKopo::verifySignature($rawBody, $signature)) {
            Logger::error('KopoKopo callback rejected: bad signature');
            Response::json(['ok' => false, 'error' => 'Invalid signature.'], 401);
        }

        $body = json_decode($rawBody, true);

        if (!is_array($body)) {
            Response::json(['ok' => false, 'error' => 'Malformed payload.'], 400);
        }

        $parsed = KopoKopo::parseCallback($body);

        // Match on KopoKopo's id, falling back to the metadata we sent.
        $stk = null;

        if (!empty($parsed['kopokopo_id'])) {
            $stk = Database::first(
                'SELECT * FROM stk_requests WHERE kopokopo_id = :kid ORDER BY id DESC LIMIT 1',
                ['kid' => $parsed['kopokopo_id']]
            );
        }

        if (!$stk) {
            $metaStkId = (int) ($body['data']['attributes']['metadata']['stk_id'] ?? 0);
            if ($metaStkId > 0) {
                $stk = Database::first('SELECT * FROM stk_requests WHERE id = :id', ['id' => $metaStkId]);
            }
        }

        if (!$stk) {
            Logger::warning('KopoKopo callback did not match a known STK request', [
                'kopokopo_id' => $parsed['kopokopo_id'],
            ]);
            // 200 so KopoKopo stops retrying something we cannot resolve.
            Response::json(['ok' => true, 'note' => 'No matching request.']);
        }

        // Callbacks can be delivered more than once; settle only once.
        if ($stk['status'] !== 'pending') {
            Response::json(['ok' => true, 'note' => 'Already processed.']);
        }

        $this->settleStk($stk, $parsed, $rawBody);

        Response::json(['ok' => true]);
    }

    /**
     * Apply a resolved STK result: post the payment on success, or mark it failed.
     */
    private function settleStk(array $stk, array $parsed, string $rawPayload): void
    {
        if ($parsed['status'] !== 'success') {
            Database::update('stk_requests', [
                'status'           => $parsed['status'] === 'pending' ? 'failed' : $parsed['status'],
                'result_desc'      => mb_substr((string) ($parsed['description'] ?? 'Payment was not completed.'), 0, 255),
                'callback_payload' => mb_substr($rawPayload, 0, 6000),
                'callback_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $stk['id']]);

            ActivityLog::record(
                'stk_failed',
                'stk_request',
                (int) $stk['id'],
                'STK Push not completed: ' . ($parsed['description'] ?? 'declined')
            );
            return;
        }

        // Trust the amount M-Pesa actually moved over the amount we requested.
        $amount = $parsed['amount'] ?? (float) $stk['amount'];

        $result = PaymentPoster::post(
            clientId:   (int) $stk['client_id'],
            amount:     $amount,
            method:     'mpesa_stk',
            documentId: $stk['document_id'] ? (int) $stk['document_id'] : null,
            reference:  $parsed['receipt'],
            notes:      'Received via KopoKopo STK Push',
            recordedBy: $stk['initiated_by'] ? (int) $stk['initiated_by'] : null
        );

        Database::update('stk_requests', [
            'status'           => 'success',
            'payment_id'       => $result['payment_id'],
            'mpesa_receipt'    => $parsed['receipt'],
            'result_desc'      => 'Payment received',
            'callback_payload' => mb_substr($rawPayload, 0, 6000),
            'callback_at'      => date('Y-m-d H:i:s'),
        ], ['id' => $stk['id']]);

        ActivityLog::record(
            'stk_success',
            'stk_request',
            (int) $stk['id'],
            'M-Pesa payment of ' . money($amount) . ' received'
            . ($parsed['receipt'] ? ' (ref ' . $parsed['receipt'] . ')' : '')
        );
    }

    /** Callback URL KopoKopo posts back to. */
    private function callbackUrl(): string
    {
        $configured = (string) Settings::get('kopokopo_callback_url', '');

        if ($configured !== '') {
            return $configured;
        }

        $appUrl = rtrim((string) Config::get('app.url', ''), '/');

        if ($appUrl !== '') {
            return $appUrl . base_path() . '/webhooks/kopokopo';
        }

        // Last resort: derive from the current request.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/webhooks/kopokopo');
    }
}
