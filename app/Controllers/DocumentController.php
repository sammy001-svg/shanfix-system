<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Numbering;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\Logger;
use App\Services\DocumentCalculator;
use App\Services\Notifier;
use App\Services\StockLedger;

/**
 * Drives quotations, invoices and receipts from one table.
 * The doc_type is fixed by the route, never by user input.
 */
class DocumentController extends Controller
{
    private const TYPES = [
        'quotation' => ['label' => 'Quotation', 'path' => '/quotations', 'plural' => 'Quotations'],
        'invoice'   => ['label' => 'Invoice',   'path' => '/invoices',   'plural' => 'Invoices'],
        'receipt'   => ['label' => 'Receipt',   'path' => '/receipts',   'plural' => 'Receipts'],
        'proposal'  => ['label' => 'Proposal',  'path' => '/proposals',  'plural' => 'Proposals'],
        'agreement' => ['label' => 'Agreement', 'path' => '/agreements', 'plural' => 'Agreements'],
    ];

    private const STATUSES = [
        'quotation' => ['draft', 'sent', 'accepted', 'rejected', 'expired', 'cancelled'],
        'invoice'   => ['draft', 'sent', 'unpaid', 'partial', 'paid', 'overdue', 'cancelled'],
        'receipt'   => ['paid'],
        'proposal'  => ['draft', 'sent', 'accepted', 'rejected', 'expired', 'cancelled'],
        'agreement' => ['draft', 'sent', 'accepted', 'cancelled'],
    ];

    // -- Listing -------------------------------------------------------

    public function index(Request $request, string $type): void
    {
        $this->assertType($type);

        $search = (string) $request->query('q', '');
        $status = (string) $request->query('status', '');
        $from   = (string) $request->query('from', '');
        $to     = (string) $request->query('to', '');

        $where  = ['d.doc_type = :type'];
        $params = ['type' => $type];

        if ($search !== '') {
            $where[] = '(d.doc_number LIKE :q OR c.name LIKE :q2 OR d.title LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }

        if ($status !== '' && in_array($status, self::STATUSES[$type], true)) {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }

        if ($from !== '' && strtotime($from)) {
            $where[] = 'd.issue_date >= :from';
            $params['from'] = date('Y-m-d', strtotime($from));
        }

        if ($to !== '' && strtotime($to)) {
            $where[] = 'd.issue_date <= :to';
            $params['to'] = date('Y-m-d', strtotime($to));
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM documents d JOIN clients c ON c.id = d.client_id WHERE {$clause}",
            $params,
            0
        );
        $pager = $this->paginate($total, 25);

        $documents = Database::all(
            "SELECT d.*, c.name AS client_name, c.client_code, u.name AS created_by_name
               FROM documents d
               JOIN clients c ON c.id = d.client_id
          LEFT JOIN users u ON u.id = d.created_by
              WHERE {$clause}
           ORDER BY d.issue_date DESC, d.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT COUNT(*) AS count,
                    COALESCE(SUM(total), 0)   AS total_value,
                    COALESCE(SUM(balance), 0) AS total_balance,
                    COALESCE(SUM(amount_paid), 0) AS total_paid
               FROM documents
              WHERE doc_type = :type AND status <> 'cancelled'",
            ['type' => $type]
        );

        $this->view('documents/index', [
            'title'     => self::TYPES[$type]['plural'],
            'type'      => $type,
            'meta'      => self::TYPES[$type],
            'statuses'  => self::STATUSES[$type],
            'documents' => $documents,
            'pager'     => $pager,
            'summary'   => $summary,
            'filters'   => compact('search', 'status', 'from', 'to'),
        ]);
    }

    // -- Create / edit -------------------------------------------------

    public function create(Request $request, string $type): void
    {
        $this->assertType($type);
        $this->authorize('documents.manage');

        if ($type === 'receipt') {
            Session::info('Receipts are generated from a paid invoice rather than created directly.');
            Response::to('/invoices');
        }

        $clientId = (int) $request->query('client_id', 0);

        $this->view('documents/form', array_merge(
            $this->formData($type, null),
            [
                'title'           => 'New ' . self::TYPES[$type]['label'],
                'selectedClient'  => $clientId,
                'nextNumber'      => Numbering::peek($type),
            ]
        ));
    }

    public function store(Request $request, string $type): void
    {
        $this->assertType($type);
        $this->authorize('documents.manage');

        if ($type === 'receipt') {
            throw new HttpException(403, 'Receipts are generated from invoices.');
        }

        $payload = $this->validatePayload($request, $type, null);

        $id = Database::transaction(function () use ($payload, $type) {
            $doc = $payload['document'];
            $doc['doc_type']   = $type;
            $doc['doc_number'] = Numbering::next($type);
            $doc['created_by'] = Auth::id();

            $docId = Database::insert('documents', $doc);
            $this->saveItems($docId, $payload['items']);
            $this->saveSections($docId, $payload['sections']);

            return $docId;
        });

        ActivityLog::record(
            $type . '_created',
            'document',
            $id,
            self::TYPES[$type]['label'] . ' raised for client #' . $payload['document']['client_id']
        );

        // An invoice created straight as issued sells the goods there and
        // then. A draft does not: nothing has left the store yet.
        if ($type === 'invoice' && ($payload['document']['status'] ?? 'draft') !== 'draft') {
            $this->flashStockWarnings(StockLedger::postQuietly($id));
        }

        Session::success(self::TYPES[$type]['label'] . ' created successfully.');
        Response::to(self::TYPES[$type]['path'] . '/' . $id);
    }

    public function edit(Request $request, string $type): void
    {
        $this->assertType($type);
        $this->authorize('documents.manage');

        $doc = $this->findOrFail($request->paramInt('id'), $type);

        if ($type === 'receipt') {
            throw new HttpException(403, 'Receipts cannot be edited. Cancel it and issue a new one instead.');
        }

        if ($doc['status'] === 'paid') {
            Session::error('Paid invoices cannot be edited. Record a credit note or cancel the invoice instead.');
            Response::to(self::TYPES[$type]['path'] . '/' . $doc['id']);
        }

        if ((float) $doc['amount_paid'] > 0) {
            Session::warning('This invoice has payments against it. Editing may leave the balance inconsistent.');
        }

        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $doc['id']]
        );

        $this->view('documents/form', array_merge(
            $this->formData($type, $doc),
            [
                'title'          => 'Edit ' . $doc['doc_number'],
                'existingItems'  => $items,
                'selectedClient' => (int) $doc['client_id'],
                'nextNumber'     => $doc['doc_number'],
            ]
        ));
    }

    public function update(Request $request, string $type): void
    {
        $this->assertType($type);
        $this->authorize('documents.manage');

        $doc = $this->findOrFail($request->paramInt('id'), $type);

        if ($type === 'receipt' || $doc['status'] === 'paid') {
            throw new HttpException(403, 'This document can no longer be edited.');
        }

        $payload = $this->validatePayload($request, $type, $doc);

        Database::transaction(function () use ($doc, $payload, $type) {
            $update = $payload['document'];

            // Re-derive the invoice status against what has already been paid.
            if ($type === 'invoice') {
                $paid = (float) $doc['amount_paid'];
                $update['amount_paid'] = $paid;
                $update['balance']     = DocumentCalculator::round($update['total'] - $paid);
                $update['status']      = DocumentCalculator::invoiceStatus(
                    (float) $update['total'],
                    $paid,
                    $update['due_date'],
                    $update['status']
                );
            }

            Database::update('documents', $update, ['id' => $doc['id']]);

            Database::delete('document_items', ['document_id' => $doc['id']]);
            $this->saveItems((int) $doc['id'], $payload['items']);

            Database::delete('document_sections', ['document_id' => $doc['id']]);
            $this->saveSections((int) $doc['id'], $payload['sections']);
        });

        ActivityLog::record($type . '_updated', 'document', (int) $doc['id'], 'Updated ' . $doc['doc_number']);

        // The lines were rewritten, so whatever stock this invoice took out
        // no longer matches what it is selling. Put it all back and take out
        // the new quantities.
        if ($type === 'invoice' && $doc['stock_posted_at'] !== null) {
            try {
                $this->flashStockWarnings(StockLedger::repostForDocument((int) $doc['id'])['warnings']);
            } catch (\Throwable $e) {
                Logger::error('Stock repost failed: ' . $e->getMessage(), ['document' => $doc['id']]);
                Session::warning('Stock could not be re-checked for this invoice — check the inventory ledger.');
            }
        }

        Session::success($doc['doc_number'] . ' updated.');
        Response::to(self::TYPES[$type]['path'] . '/' . $doc['id']);
    }

    // -- Viewing -------------------------------------------------------

    public function show(Request $request, string $type): void
    {
        $this->assertType($type);

        $doc = $this->findOrFail($request->paramInt('id'), $type);

        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $doc['id']]
        );

        $payments = Database::all(
            'SELECT p.*, u.name AS recorded_by_name
               FROM payments p
          LEFT JOIN users u ON u.id = p.recorded_by
              WHERE p.document_id = :id
           ORDER BY p.created_at DESC',
            ['id' => $doc['id']]
        );

        // Related documents in the quote → invoice → receipt chain.
        $related = Database::all(
            'SELECT id, doc_type, doc_number, status, total, issue_date
               FROM documents
              WHERE (parent_document_id = :id OR id = :parent)
                AND id <> :self',
            [
                'id'     => $doc['id'],
                'parent' => $doc['parent_document_id'] ?? 0,
                'self'   => $doc['id'],
            ]
        );

        $pendingStk = Database::first(
            "SELECT * FROM stk_requests
              WHERE document_id = :id AND status = 'pending'
           ORDER BY id DESC LIMIT 1",
            ['id' => $doc['id']]
        );

        // A production job may already have been raised from this document.
        $job = Database::first(
            "SELECT id, job_number, stage FROM jobs
              WHERE document_id = :id AND stage <> 'cancelled'
           ORDER BY id DESC LIMIT 1",
            ['id' => $doc['id']]
        );

        $emailOn = Settings::bool('smtp_enabled');
        $smsOn   = Settings::bool('sms_enabled');

        // Only mint a share link for documents that have actually been issued.
        $publicLink = null;
        if ($doc['status'] !== 'draft') {
            $token = \App\Services\Notifier::ensureToken((int) $doc['id'], $doc['public_token'] ?? null);
            $publicLink = \App\Services\Notifier::publicUrl($token);
        }

        $this->view('documents/show', [
            'title'       => $doc['doc_number'],
            'type'        => $type,
            'meta'        => self::TYPES[$type],
            'statuses'    => self::STATUSES[$type],
            'doc'         => $doc,
            'items'       => $items,
            'payments'    => $payments,
            'related'     => $related,
            'pendingStk'  => $pendingStk,
            'job'         => $job,
            'stkEnabled'  => Settings::bool('kopokopo_enabled'),
            'emailOn'     => $emailOn,
            'smsOn'       => $smsOn,
            'messagingOn' => $emailOn || $smsOn,
            'publicLink'  => $publicLink,
        ]);
    }

    /** Printable A4 view, also used for "Save as PDF" via the browser. */
    public function print(Request $request, string $type): void
    {
        $this->assertType($type);

        $doc = $this->findOrFail($request->paramInt('id'), $type);

        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $doc['id']]
        );

        $payments = Database::all(
            "SELECT * FROM payments
              WHERE document_id = :id AND status = 'completed'
           ORDER BY created_at",
            ['id' => $doc['id']]
        );

        $sections = Database::all(
            'SELECT * FROM document_sections WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $doc['id']]
        );

        $this->view('documents/print', [
            'title'     => $doc['doc_number'],
            'type'      => $type,
            'meta'      => self::TYPES[$type],
            'doc'       => $doc,
            'items'     => $items,
            'sections'  => $sections,
            'payments'  => $payments,
            'company'   => Settings::company(),
            'autoPrint' => $request->query('auto') === '1',
        ], 'print');
    }

    // -- Workflow ------------------------------------------------------

    public function updateStatus(Request $request, string $type): void
    {
        $this->assertType($type);
        $this->authorize('documents.manage');

        $doc    = $this->findOrFail($request->paramInt('id'), $type);
        $status = (string) $request->input('status', '');

        if (!in_array($status, self::STATUSES[$type], true)) {
            throw new HttpException(422, 'That status is not valid for this document type.');
        }

        // Paid/partial are set by the payment engine, never by hand.
        if ($type === 'invoice' && in_array($status, ['paid', 'partial'], true)) {
            Session::error('Record a payment to mark an invoice as paid.');
            Response::to(self::TYPES[$type]['path'] . '/' . $doc['id']);
        }

        $update = ['status' => $status];
        if ($status === 'sent' && !$doc['sent_at']) {
            $update['sent_at'] = date('Y-m-d H:i:s');
        }

        Database::update('documents', $update, ['id' => $doc['id']]);

        // Issuing an invoice is the moment the goods are sold; cancelling it
        // puts them back. Both are no-ops if the stock state already matches,
        // so a repeated status change cannot double-count.
        if ($type === 'invoice') {
            if ($status === 'cancelled') {
                StockLedger::reverseQuietly((int) $doc['id']);
            } elseif ($doc['status'] === 'draft' || $doc['status'] === 'cancelled') {
                $this->flashStockWarnings(StockLedger::postQuietly((int) $doc['id']));
            }
        }

        ActivityLog::record(
            $type . '_status_changed',
            'document',
            (int) $doc['id'],
            $doc['doc_number'] . ': ' . $doc['status'] . ' → ' . $status
        );

        $message = $doc['doc_number'] . ' marked as ' . label_of($status) . '.';

        // Confirm to the client that we are proceeding on their quotation.
        if ($type === 'quotation' && $status === 'accepted' && $doc['status'] !== 'accepted') {
            $context = Notifier::documentContext(array_merge($doc, ['status' => $status]));

            if (Notifier::dispatch('quotation_accepted', $context)['queued'] > 0) {
                Notifier::processQueue(10);
                $message .= ' The client has been sent a confirmation.';
            }
        }

        Session::success($message);
        Response::to(self::TYPES[$type]['path'] . '/' . $doc['id']);
    }


    /**
     * Proposal → Quotation, carrying the pricing across.
     *
     * The two stay linked through parent_document_id, so the quotation
     * shows what it came from and the proposal shows what it became. The
     * narrative does not follow: a quotation is a price, and the case for
     * the work has already been made.
     */
    public function convertToQuotation(Request $request): void
    {
        $this->authorize('documents.manage');

        $proposal = $this->findOrFail($request->paramInt('id'), 'proposal');

        $existing = Database::first(
            "SELECT id, doc_number FROM documents
              WHERE parent_document_id = :id AND doc_type = 'quotation' AND status <> 'cancelled'
              LIMIT 1",
            ['id' => $proposal['id']]
        );

        if ($existing) {
            Session::warning('This proposal already became quotation ' . $existing['doc_number'] . '.');
            Response::to('/quotations/' . $existing['id']);
        }

        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $proposal['id']]
        );

        if (!$items) {
            Session::error('This proposal has no priced lines, so there is nothing to quote.');
            Response::to('/proposals/' . $proposal['id']);
        }

        $validity = Settings::int('quotation_validity_days', 30);

        $quoteId = Database::transaction(function () use ($proposal, $items, $validity) {
            $quoteId = Database::insert('documents', [
                'doc_type'           => 'quotation',
                'doc_number'         => Numbering::next('quotation'),
                'client_id'          => $proposal['client_id'],
                'lead_id'            => $proposal['lead_id'],
                'parent_document_id' => $proposal['id'],
                'title'              => $proposal['title'],
                'issue_date'         => date('Y-m-d'),
                'valid_until'        => date('Y-m-d', strtotime("+{$validity} days")),
                'status'             => 'draft',
                'currency'           => $proposal['currency'],
                'subtotal'           => $proposal['subtotal'],
                'discount_type'      => $proposal['discount_type'],
                'discount_value'     => $proposal['discount_value'],
                'discount_amount'    => $proposal['discount_amount'],
                'vat_mode'           => $proposal['vat_mode'],
                'vat_rate'           => $proposal['vat_rate'],
                'vat_amount'         => $proposal['vat_amount'],
                'total'              => $proposal['total'],
                'balance'            => $proposal['total'],
                'notes'              => $proposal['notes'],
                'terms'              => Settings::get('quotation_terms', ''),
                'created_by'         => Auth::id(),
            ]);

            foreach ($items as $i => $item) {
                Database::insert('document_items', [
                    'document_id' => $quoteId,
                    'item_type'   => $item['item_type'],
                    'ref_id'      => $item['ref_id'],
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'unit_price'  => $item['unit_price'],
                    'line_total'  => $item['line_total'],
                    'sort_order'  => $i,
                ]);
            }

            if ($proposal['status'] !== 'accepted') {
                Database::update('documents', ['status' => 'accepted'], ['id' => $proposal['id']]);
            }

            return $quoteId;
        });

        ActivityLog::record('proposal_converted', 'document', $quoteId,
            $proposal['doc_number'] . ' became a quotation');

        Session::success('Quotation raised from ' . $proposal['doc_number'] . '. Check it before sending.');
        Response::to('/quotations/' . $quoteId);
    }

    /**
     * Draw up the agreement a client signs before work starts.
     *
     * Raised from a proposal or a quotation so the scope and the price on
     * the agreement are the ones already discussed, rather than retyped.
     */
    public function generateAgreement(Request $request): void
    {
        $this->authorize('documents.manage');

        $source = Database::first(
            "SELECT d.*, c.name AS client_name FROM documents d
               JOIN clients c ON c.id = d.client_id
              WHERE d.id = :id AND d.doc_type IN ('proposal','quotation')",
            ['id' => $request->paramInt('id')]
        );

        if (!$source) {
            throw new HttpException(404, 'An agreement is raised from a proposal or a quotation.');
        }

        $existing = Database::first(
            "SELECT id, doc_number FROM documents
              WHERE parent_document_id = :id AND doc_type = 'agreement' AND status <> 'cancelled'
              LIMIT 1",
            ['id' => $source['id']]
        );

        if ($existing) {
            Session::warning('An agreement already exists for this: ' . $existing['doc_number'] . '.');
            Response::to('/agreements/' . $existing['id']);
        }

        $items    = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $source['id']]
        );
        $sections = self::defaultSections('agreement');

        $agreementId = Database::transaction(function () use ($source, $items, $sections) {
            $agreementId = Database::insert('documents', [
                'doc_type'           => 'agreement',
                'doc_number'         => Numbering::next('agreement'),
                'client_id'          => $source['client_id'],
                'lead_id'            => $source['lead_id'],
                'parent_document_id' => $source['id'],
                'title'              => $source['title'] ?: 'Service agreement',
                'issue_date'         => date('Y-m-d'),
                'status'             => 'draft',
                'currency'           => $source['currency'],
                'subtotal'           => $source['subtotal'],
                'discount_type'      => $source['discount_type'],
                'discount_value'     => $source['discount_value'],
                'discount_amount'    => $source['discount_amount'],
                'vat_mode'           => $source['vat_mode'],
                'vat_rate'           => $source['vat_rate'],
                'vat_amount'         => $source['vat_amount'],
                'total'              => $source['total'],
                'balance'            => $source['total'],
                'terms'              => Settings::get('invoice_terms', ''),
                'created_by'         => Auth::id(),
            ]);

            foreach ($items as $i => $item) {
                Database::insert('document_items', [
                    'document_id' => $agreementId,
                    'item_type'   => $item['item_type'],
                    'ref_id'      => $item['ref_id'],
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'unit_price'  => $item['unit_price'],
                    'line_total'  => $item['line_total'],
                    'sort_order'  => $i,
                ]);
            }

            // The standard clauses, with the parties filled in so nobody
            // sends an agreement still saying {client_name}.
            foreach ($sections as $i => $section) {
                Database::insert('document_sections', [
                    'document_id' => $agreementId,
                    'heading'     => $section['heading'],
                    'body'        => strtr($section['body'], [
                        '{client_name}'  => $source['client_name'],
                        '{company_name}' => Settings::get('company_name', 'Shanfix Technology'),
                    ]),
                    'sort_order'  => $i,
                ]);
            }

            return $agreementId;
        });

        ActivityLog::record('agreement_created', 'document', $agreementId,
            'Agreement drawn up from ' . $source['doc_number']);

        Session::success('Agreement drafted. Review the clauses before sending it.');
        Response::to('/agreements/' . $agreementId);
    }
    /** Quotation → Invoice, copying line items across. */
    public function convertToInvoice(Request $request): void
    {
        $this->authorize('documents.manage');

        $quote = $this->findOrFail($request->paramInt('id'), 'quotation');

        $existing = Database::first(
            "SELECT id, doc_number FROM documents
              WHERE parent_document_id = :id AND doc_type = 'invoice' AND status <> 'cancelled'
              LIMIT 1",
            ['id' => $quote['id']]
        );

        if ($existing) {
            Session::warning('This quotation was already invoiced as ' . $existing['doc_number'] . '.');
            Response::to('/invoices/' . $existing['id']);
        }

        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $quote['id']]
        );

        if (!$items) {
            Session::error('This quotation has no line items to invoice.');
            Response::to('/quotations/' . $quote['id']);
        }

        $dueDays = Settings::int('invoice_due_days', 14);

        $invoiceId = Database::transaction(function () use ($quote, $items, $dueDays) {
            $invoiceId = Database::insert('documents', [
                'doc_type'           => 'invoice',
                'doc_number'         => Numbering::next('invoice'),
                'client_id'          => $quote['client_id'],
                'lead_id'            => $quote['lead_id'],
                'parent_document_id' => $quote['id'],
                'title'              => $quote['title'],
                'issue_date'         => date('Y-m-d'),
                'due_date'           => date('Y-m-d', strtotime("+{$dueDays} days")),
                'status'             => 'unpaid',
                'currency'           => $quote['currency'],
                'subtotal'           => $quote['subtotal'],
                'discount_type'      => $quote['discount_type'],
                'discount_value'     => $quote['discount_value'],
                'discount_amount'    => $quote['discount_amount'],
                'vat_mode'           => $quote['vat_mode'],
                'vat_rate'           => $quote['vat_rate'],
                'vat_amount'         => $quote['vat_amount'],
                'total'              => $quote['total'],
                'amount_paid'        => 0,
                'balance'            => $quote['total'],
                'notes'              => $quote['notes'],
                'terms'              => Settings::get('invoice_terms', ''),
                'created_by'         => Auth::id(),
            ]);

            foreach ($items as $i => $item) {
                Database::insert('document_items', [
                    'document_id' => $invoiceId,
                    'item_type'   => $item['item_type'],
                    'ref_id'      => $item['ref_id'],
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'unit_price'  => $item['unit_price'],
                    'line_total'  => $item['line_total'],
                    'sort_order'  => $i,
                ]);
            }

            if ($quote['status'] !== 'accepted') {
                Database::update('documents', ['status' => 'accepted'], ['id' => $quote['id']]);
            }

            return $invoiceId;
        });

        ActivityLog::record(
            'quotation_converted',
            'document',
            $invoiceId,
            $quote['doc_number'] . ' converted to invoice'
        );

        Session::success('Quotation ' . $quote['doc_number'] . ' converted to an invoice.');
        Response::to('/invoices/' . $invoiceId);
    }

    /** Invoice → Receipt, once fully paid. */
    public function generateReceipt(Request $request): void
    {
        $this->authorize('documents.manage');

        $invoice = $this->findOrFail($request->paramInt('id'), 'invoice');

        if ((float) $invoice['amount_paid'] <= 0) {
            Session::error('No payment has been recorded against this invoice yet.');
            Response::to('/invoices/' . $invoice['id']);
        }

        $existing = Database::first(
            "SELECT id, doc_number FROM documents
              WHERE parent_document_id = :id AND doc_type = 'receipt' LIMIT 1",
            ['id' => $invoice['id']]
        );

        if ($existing) {
            Session::info('A receipt already exists for this invoice.');
            Response::to('/receipts/' . $existing['id']);
        }

        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $invoice['id']]
        );

        $receiptId = Database::transaction(function () use ($invoice, $items) {
            $receiptId = Database::insert('documents', [
                'doc_type'           => 'receipt',
                'doc_number'         => Numbering::next('receipt'),
                'client_id'          => $invoice['client_id'],
                'parent_document_id' => $invoice['id'],
                'title'              => 'Payment for ' . $invoice['doc_number'],
                'issue_date'         => date('Y-m-d'),
                'status'             => 'paid',
                'currency'           => $invoice['currency'],
                'subtotal'           => $invoice['subtotal'],
                'discount_type'      => $invoice['discount_type'],
                'discount_value'     => $invoice['discount_value'],
                'discount_amount'    => $invoice['discount_amount'],
                'vat_mode'           => $invoice['vat_mode'],
                'vat_rate'           => $invoice['vat_rate'],
                'vat_amount'         => $invoice['vat_amount'],
                'total'              => $invoice['total'],
                'amount_paid'        => $invoice['amount_paid'],
                'balance'            => $invoice['balance'],
                'notes'              => 'Received with thanks.',
                'created_by'         => Auth::id(),
            ]);

            foreach ($items as $i => $item) {
                Database::insert('document_items', [
                    'document_id' => $receiptId,
                    'item_type'   => $item['item_type'],
                    'ref_id'      => $item['ref_id'],
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'unit_price'  => $item['unit_price'],
                    'line_total'  => $item['line_total'],
                    'sort_order'  => $i,
                ]);
            }

            return $receiptId;
        });

        ActivityLog::record('receipt_generated', 'document', $receiptId, 'Receipt issued for ' . $invoice['doc_number']);

        $message = 'Receipt issued.';

        // Send the client their receipt, with a link they can open and print.
        $receipt = Database::first(
            'SELECT d.*, c.name AS client_name, c.email AS client_email,
                    c.phone AS client_phone, c.contact_person AS client_contact
               FROM documents d JOIN clients c ON c.id = d.client_id
              WHERE d.id = :id',
            ['id' => $receiptId]
        );

        if ($receipt) {
            $context = Notifier::documentContext($receipt);
            $context['paid_for'] = $invoice['doc_number'];

            if (Notifier::dispatch('receipt_issued', $context)['queued'] > 0) {
                Notifier::processQueue(10);
                $message .= ' The client has been sent a copy.';
            }
        }

        Session::success($message);
        Response::to('/receipts/' . $receiptId);
    }

    /** Copy an existing document into a fresh draft. */
    public function duplicate(Request $request, string $type): void
    {
        $this->assertType($type);
        $this->authorize('documents.manage');

        if ($type === 'receipt') {
            throw new HttpException(403, 'Receipts cannot be duplicated.');
        }

        $source = $this->findOrFail($request->paramInt('id'), $type);
        $items  = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $source['id']]
        );

        $newId = Database::transaction(function () use ($source, $items, $type) {
            $validity = Settings::int('quotation_validity_days', 30);
            $dueDays  = Settings::int('invoice_due_days', 14);

            $newId = Database::insert('documents', [
                'doc_type'        => $type,
                'doc_number'      => Numbering::next($type),
                'client_id'       => $source['client_id'],
                'title'           => $source['title'],
                'issue_date'      => date('Y-m-d'),
                'due_date'        => $type === 'invoice' ? date('Y-m-d', strtotime("+{$dueDays} days")) : null,
                'valid_until'     => $type === 'quotation' ? date('Y-m-d', strtotime("+{$validity} days")) : null,
                'status'          => 'draft',
                'currency'        => $source['currency'],
                'subtotal'        => $source['subtotal'],
                'discount_type'   => $source['discount_type'],
                'discount_value'  => $source['discount_value'],
                'discount_amount' => $source['discount_amount'],
                'vat_mode'        => $source['vat_mode'],
                'vat_rate'        => $source['vat_rate'],
                'vat_amount'      => $source['vat_amount'],
                'total'           => $source['total'],
                'balance'         => $type === 'invoice' ? $source['total'] : 0,
                'notes'           => $source['notes'],
                'terms'           => $source['terms'],
                'created_by'      => Auth::id(),
            ]);

            foreach ($items as $i => $item) {
                Database::insert('document_items', [
                    'document_id' => $newId,
                    'item_type'   => $item['item_type'],
                    'ref_id'      => $item['ref_id'],
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'],
                    'unit_price'  => $item['unit_price'],
                    'line_total'  => $item['line_total'],
                    'sort_order'  => $i,
                ]);
            }

            return $newId;
        });

        ActivityLog::record($type . '_duplicated', 'document', $newId, 'Duplicated from ' . $source['doc_number']);
        Session::success('Created a copy of ' . $source['doc_number'] . '. It is saved as a draft.');
        Response::to(self::TYPES[$type]['path'] . '/' . $newId . '/edit');
    }

    public function destroy(Request $request, string $type): void
    {
        $this->assertType($type);
        $this->authorize('documents.delete');

        $doc = $this->findOrFail($request->paramInt('id'), $type);

        // Anything with money against it is cancelled, not erased.
        if ((float) $doc['amount_paid'] > 0) {
            Database::update('documents', ['status' => 'cancelled'], ['id' => $doc['id']]);
            StockLedger::reverseQuietly((int) $doc['id']);
            ActivityLog::record($type . '_cancelled', 'document', (int) $doc['id'], 'Cancelled ' . $doc['doc_number']);
            Session::warning($doc['doc_number'] . ' has payments recorded, so it was cancelled instead of deleted.');
            Response::to(self::TYPES[$type]['path'] . '/' . $doc['id']);
        }

        // Before the row goes: the ledger looks the document up by id, and
        // stock put back after deletion would have nothing to reverse.
        StockLedger::reverseQuietly((int) $doc['id']);

        Database::delete('documents', ['id' => $doc['id']]);
        ActivityLog::record($type . '_deleted', 'document', (int) $doc['id'], 'Deleted ' . $doc['doc_number']);
        Session::success($doc['doc_number'] . ' deleted.');
        Response::to(self::TYPES[$type]['path']);
    }

    // -- Internals -----------------------------------------------------

    /**
     * Surface stock consequences without burying the main message.
     *
     * A count going negative or hitting its reorder level is something the
     * operator needs to see, but it must not look like the invoice failed.
     */
    private function flashStockWarnings(array $warnings): void
    {
        foreach (array_slice($warnings, 0, 5) as $warning) {
            Session::warning($warning);
        }

        if (count($warnings) > 5) {
            Session::warning((count($warnings) - 5) . ' more stock warning(s) — see the inventory list.');
        }
    }

    private function assertType(string $type): void
    {
        if (!isset(self::TYPES[$type])) {
            throw new HttpException(404, 'Unknown document type.');
        }
    }

    private function findOrFail(int $id, string $type): array
    {
        $doc = Database::first(
            'SELECT d.*, c.name AS client_name, c.client_code, c.email AS client_email,
                    c.phone AS client_phone, c.address AS client_address, c.city AS client_city,
                    c.kra_pin AS client_kra_pin, c.contact_person AS client_contact,
                    u.name AS created_by_name
               FROM documents d
               JOIN clients c ON c.id = d.client_id
          LEFT JOIN users u ON u.id = d.created_by
              WHERE d.id = :id AND d.doc_type = :type',
            ['id' => $id, 'type' => $type]
        );

        if (!$doc) {
            throw new HttpException(404, 'That document does not exist.');
        }

        return $doc;
    }

    /** Shared data for the create/edit form. */
    private function formData(string $type, ?array $doc): array
    {
        $clients = Database::all(
            "SELECT id, name, client_code, phone, email FROM clients
              WHERE status = 'active' OR id = :current
           ORDER BY name",
            ['current' => $doc['client_id'] ?? 0]
        );

        $inventory = Database::all(
            // Main photo comes along so the picker can show what the item is.
            'SELECT i.id, i.sku, i.name, i.unit, i.selling_price, i.quantity, i.description,
                    (SELECT COALESCE(thumb_path, file_path) FROM inventory_images
                      WHERE item_id = i.id ORDER BY is_primary DESC, sort_order, id LIMIT 1) AS thumb
               FROM inventory_items i WHERE i.is_active = 1 ORDER BY i.name'
        );

        $services = Database::all(
            'SELECT id, code, name, unit_label, price, pricing_type, description
               FROM services WHERE is_active = 1 ORDER BY name'
        );

        return [
            'type'          => $type,
            'meta'          => self::TYPES[$type],
            'doc'           => $doc,
            'clients'       => $clients,
            'inventory'     => $inventory,
            'services'      => $services,
            'existingItems' => [],
            'existingSections' => $doc
                ? Database::all(
                    'SELECT * FROM document_sections WHERE document_id = :id ORDER BY sort_order, id',
                    ['id' => $doc['id']]
                  )
                : self::defaultSections($type),
            'isNarrative'   => in_array($type, self::NARRATIVE_TYPES, true),
            'vatRate'       => Settings::vatRate(),
            'defaultTerms'  => $type === 'quotation'
                ? Settings::get('quotation_terms', '')
                : Settings::get('invoice_terms', ''),
            // A proposal usually stands longer than a quotation, so it has
            // its own window rather than borrowing the quotation's.
            'validityDays'  => $type === 'proposal'
                ? Settings::int('proposal_validity_days', 30)
                : Settings::int('quotation_validity_days', 30),
            'dueDays'       => Settings::int('invoice_due_days', 14),
        ];
    }

    /**
     * Validate the posted form and return the document row plus its items.
     *
     * @return array{document:array<string,mixed>, items:array<int,array<string,mixed>>}
     */
    private function validatePayload(Request $request, string $type, ?array $existing): array
    {
        $back = $existing
            ? self::TYPES[$type]['path'] . '/' . $existing['id'] . '/edit'
            : self::TYPES[$type]['path'] . '/create';

        $v = new Validator($request->all());
        $v->require('client_id', 'Client')
          ->exists('client_id', 'clients', 'client')
          ->date('issue_date', 'Issue date', true)
          ->in('discount_type', ['none', 'percent', 'amount'], 'Discount type')
          ->numeric('discount_value', 'Discount value')
          ->min('discount_value', 0, 'Discount value')
          ->in('vat_mode', ['exclusive', 'inclusive', 'exempt'], 'VAT treatment')
          ->numeric('vat_rate', 'VAT rate')
          ->min('vat_rate', 0, 'VAT rate')
          ->maxLen('title', 200, 'Title');

        if ($type === 'quotation') {
            $v->date('valid_until', 'Valid until');
        } else {
            $v->date('due_date', 'Due date');
        }

        if ($request->input('discount_type') === 'percent' && $request->decimal('discount_value') > 100) {
            $v->custom('discount_value', false, 'A percentage discount cannot exceed 100%.');
        }

        // -- Line items
        $rawItems = $request->array('items');
        $items    = [];

        foreach ($rawItems as $row) {
            if (!is_array($row)) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            $quantity    = (float) str_replace(',', '', (string) ($row['quantity'] ?? 0));
            $unitPrice   = (float) str_replace(',', '', (string) ($row['unit_price'] ?? 0));

            // Skip rows the user left completely blank.
            if ($description === '' && $quantity <= 0 && $unitPrice <= 0) {
                continue;
            }

            if ($description === '') {
                $v->custom('items', false, 'Every line item needs a description.');
                break;
            }

            if ($quantity <= 0) {
                $v->custom('items', false, 'Line "' . str_excerpt($description, 30) . '" needs a quantity greater than zero.');
                break;
            }

            if ($unitPrice < 0) {
                $v->custom('items', false, 'Line "' . str_excerpt($description, 30) . '" cannot have a negative price.');
                break;
            }

            $itemType = in_array($row['item_type'] ?? '', ['inventory', 'service', 'custom'], true)
                ? $row['item_type'] : 'custom';

            $items[] = [
                'item_type'   => $itemType,
                'ref_id'      => $itemType === 'custom' ? null : ((int) ($row['ref_id'] ?? 0) ?: null),
                'description' => mb_substr($description, 0, 500),
                'quantity'    => $quantity,
                'unit'        => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 30) ?: null,
                'unit_price'  => $unitPrice,
            ];
        }

        if ($items === []) {
            $v->custom('items', false, 'Add at least one line item.');
        }

        if ($v->fails()) {
            $v->redirectBack($back);
        }

        // -- Totals
        $discountType  = (string) $request->input('discount_type', 'none');
        $discountValue = $request->decimal('discount_value');
        $vatMode       = (string) $request->input('vat_mode', 'exclusive');
        $vatRate       = $request->decimal('vat_rate', Settings::vatRate());

        $totals = DocumentCalculator::compute($items, $discountType, $discountValue, $vatMode, $vatRate);

        foreach ($items as $i => &$item) {
            $item['line_total'] = $totals['lines'][$i];
            $item['sort_order'] = $i;
        }
        unset($item);

        $status = (string) $request->input('status', 'draft');
        if (!in_array($status, self::STATUSES[$type], true)) {
            $status = 'draft';
        }

        // Only the payment engine may set these.
        if (in_array($status, ['paid', 'partial', 'overdue'], true)) {
            $status = 'unpaid';
        }

        $document = [
            'client_id'       => $request->int('client_id'),
            'title'           => $request->input('title') ?: null,
            'issue_date'      => (string) $request->input('issue_date', date('Y-m-d')),
            'due_date'        => $type === 'invoice'   ? ($request->input('due_date')    ?: null) : null,
            'valid_until'     => $type === 'quotation' ? ($request->input('valid_until') ?: null) : null,
            'status'          => $status,
            'currency'        => Settings::currency(),
            'subtotal'        => $totals['subtotal'],
            'discount_type'   => $discountType,
            'discount_value'  => $discountValue,
            'discount_amount' => $totals['discount_amount'],
            'vat_mode'        => $vatMode,
            'vat_rate'        => $vatRate,
            'vat_amount'      => $totals['vat_amount'],
            'total'           => $totals['total'],
            'notes'           => $request->input('notes') ?: null,
            'terms'           => $request->input('terms') ?: null,
        ];

        if ($type === 'invoice' && $existing === null) {
            $document['amount_paid'] = 0;
            $document['balance']     = $totals['total'];
        }

        return [
            'document' => $document,
            'items'    => $items,
            'sections' => $this->sectionsFromRequest($request),
        ];
    }


    /**
     * Section blocks posted from the form.
     *
     * A block with neither a heading nor a body is a row the operator left
     * behind, not an instruction to print an empty box.
     */
    private function sectionsFromRequest(Request $request): array
    {
        $out = [];

        foreach ((array) $request->input('sections', []) as $i => $row) {
            $heading = trim((string) ($row['heading'] ?? ''));
            $body    = trim((string) ($row['body'] ?? ''));

            if ($heading === '' && $body === '') {
                continue;
            }

            $out[] = [
                'heading'    => mb_substr($heading !== '' ? $heading : 'Section', 0, 200),
                'body'       => $body !== '' ? $body : null,
                'sort_order' => (int) $i,
            ];
        }

        return $out;
    }

    private function saveSections(int $documentId, array $sections): void
    {
        foreach ($sections as $section) {
            Database::insert('document_sections', $section + ['document_id' => $documentId]);
        }
    }

    /**
     * The headings a new proposal or agreement starts from.
     *
     * Held in Settings as "Heading|Body" lines so an operator can shape
     * their own house style once instead of retyping it every time.
     *
     * @return array<int,array{heading:string, body:string}>
     */
    private static function defaultSections(string $type): array
    {
        if (!in_array($type, self::NARRATIVE_TYPES, true)) {
            return [];
        }

        $raw = (string) Settings::get('tpl_' . $type . '_sections', '');
        $out = [];

        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$heading, $body] = array_pad(explode('|', $line, 2), 2, '');

            $out[] = ['heading' => trim($heading), 'body' => trim($body)];
        }

        return $out;
    }
    private function saveItems(int $documentId, array $items): void
    {
        foreach ($items as $item) {
            Database::insert('document_items', [
                'document_id' => $documentId,
                'item_type'   => $item['item_type'],
                'ref_id'      => $item['ref_id'],
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit'        => $item['unit'],
                'unit_price'  => $item['unit_price'],
                'line_total'  => $item['line_total'],
                'sort_order'  => $item['sort_order'],
            ]);
        }
    }
}
