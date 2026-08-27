<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Company letters.
 *
 * An introduction, a quotation cover note, a reference for a supplier, a
 * notice to a landlord, a letter to a bank. Written here rather than in
 * Word so that every letter leaving the company looks the same and there
 * is a record of what was sent to whom — a letter typed in Word lives on
 * one person's laptop and nobody else can find it again.
 *
 * The letterhead is not stored on the letter. It is drawn at print time
 * from the company settings, so changing the phone number corrects every
 * letter rather than only the ones written afterwards.
 */
class LetterController extends Controller
{
    /** Closings offered, in the order they are usually wanted. */
    public const CLOSINGS = [
        'Yours faithfully',
        'Yours sincerely',
        'Kind regards',
        'Best regards',
        'Regards',
    ];

    public function index(Request $request): void
    {
        $this->authorize('letters.view');

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(l.subject LIKE :q OR l.recipient_name LIKE :q2 OR l.recipient_org LIKE :q3 OR l.reference LIKE :q4)';
            $params['q']  = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }

        if (in_array($status, ['draft', 'final'], true)) {
            $where[]          = 'l.status = :status';
            $params['status'] = $status;
        }

        $letters = Database::all(
            'SELECT l.*, c.name AS client_name, u.name AS author
               FROM letters l
          LEFT JOIN clients c ON c.id = l.client_id
          LEFT JOIN users u ON u.id = l.created_by
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY l.letter_date DESC, l.id DESC',
            $params
        );

        $this->view('letters/index', [
            'title'   => 'Letters',
            'letters' => $letters,
            'filters' => compact('search', 'status'),
            'counts'  => $this->counts(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('letters.manage');

        $me = Auth::user();

        $this->view('letters/form', [
            'title'    => 'New Letter',
            'letter'   => null,
            'clients'  => $this->clients(),
            'closings' => self::CLOSINGS,
            // Prefilled from whoever is writing it; both are editable.
            'defaults' => [
                'signatory_name'  => $me['name'] ?? '',
                'signatory_title' => Settings::get('letter_default_signatory_title', ''),
                'letter_date'     => date('Y-m-d'),
            ],
            // A GET carries the client in the query string, not the body,
            // and Request::int() reads the body.
            'prefill'  => $this->prefillFromClient((int) $request->query('client_id', 0)),
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('letters.manage');

        $data = $this->validated($request);
        $data['reference']  = $this->reference();
        $data['created_by'] = Auth::id();

        $id = Database::insert('letters', $data);

        ActivityLog::record('letter_created', 'letter', $id, 'Wrote to ' . $data['recipient_name'] . ' — ' . $data['subject']);
        Session::success('Letter saved. Read it through, then print it when you are happy.');
        Response::to('/letters/' . $id);
    }

    public function show(Request $request): void
    {
        $this->authorize('letters.view');

        $letter = $this->findOrFail($request->paramInt('id'));

        $this->view('letters/show', [
            'title'     => $letter['reference'],
            'letter'    => $letter,
            'company'   => Settings::company(),
            'vision'    => trim((string) Settings::get('company_vision', '')),
            'canManage' => Auth::can('letters.manage'),
        ]);
    }

    public function edit(Request $request): void
    {
        $this->authorize('letters.manage');

        $letter = $this->findOrFail($request->paramInt('id'));

        $this->view('letters/form', [
            'title'    => 'Edit ' . $letter['reference'],
            'letter'   => $letter,
            'clients'  => $this->clients(),
            'closings' => self::CLOSINGS,
            'defaults' => [],
            'prefill'  => [],
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('letters.manage');

        $letter = $this->findOrFail($request->paramInt('id'));
        $data   = $this->validated($request);

        Database::update('letters', $data, ['id' => $letter['id']]);

        ActivityLog::record('letter_updated', 'letter', (int) $letter['id'], 'Edited ' . $letter['reference']);
        Session::success('Letter updated.');
        Response::to('/letters/' . $letter['id']);
    }

    /**
     * The letter on the company letterhead, ready to print.
     *
     * Uses the print layout, so the browser's own "Save as PDF" produces
     * the PDF. No PDF library on a shared host that may not have one.
     */
    public function print(Request $request): void
    {
        $this->authorize('letters.view');

        $letter = $this->findOrFail($request->paramInt('id'));

        $this->view('letters/print', [
            'title'   => $letter['reference'],
            'letter'  => $letter,
            'company' => Settings::company(),
            'vision'  => trim((string) Settings::get('company_vision', '')),
            'autoPrint' => $request->query('auto') === '1',
        ], 'print');
    }

    /** Mark it as sent, or put it back to a draft. */
    public function status(Request $request): void
    {
        $this->authorize('letters.manage');

        $letter = $this->findOrFail($request->paramInt('id'));
        $status = (string) $request->input('status', '');

        if (!in_array($status, ['draft', 'final'], true)) {
            Session::error('That is not a status a letter can be in.');
            Response::to('/letters/' . $letter['id']);
        }

        Database::update('letters', ['status' => $status], ['id' => $letter['id']]);

        ActivityLog::record(
            'letter_status',
            'letter',
            (int) $letter['id'],
            $letter['reference'] . ' marked ' . ($status === 'final' ? 'final' : 'a draft')
        );

        Session::success($status === 'final' ? 'Marked as final.' : 'Back to a draft.');
        Response::to('/letters/' . $letter['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('letters.manage');

        $letter = $this->findOrFail($request->paramInt('id'));

        // A letter that has gone out is the record of what was sent. It
        // can be superseded by another letter, but not quietly removed.
        if ($letter['status'] === 'final') {
            Session::error('This letter has been sent. Put it back to a draft first if it really needs deleting.');
            Response::to('/letters/' . $letter['id']);
        }

        Database::delete('letters', ['id' => $letter['id']]);

        ActivityLog::record('letter_deleted', 'letter', null, 'Deleted draft ' . $letter['reference']);
        Session::success('Draft deleted.');
        Response::to('/letters');
    }

    /** Start a new letter from an existing one. */
    public function duplicate(Request $request): void
    {
        $this->authorize('letters.manage');

        $letter = $this->findOrFail($request->paramInt('id'));

        $copy = $letter;
        unset($copy['id'], $copy['created_at'], $copy['updated_at'], $copy['client_name'], $copy['author']);

        $copy['reference']   = $this->reference();
        $copy['status']      = 'draft';
        $copy['letter_date'] = date('Y-m-d');
        $copy['created_by']  = Auth::id();

        $id = Database::insert('letters', $copy);

        ActivityLog::record('letter_created', 'letter', $id, 'Copied ' . $letter['reference']);
        Session::success('Copied. Edit the copy and send it where it needs to go.');
        Response::to('/letters/' . $id . '/edit');
    }

    // -- Internals ---------------------------------------------------------

    private function validated(Request $request): array
    {
        $v = new Validator($request->all());

        $v->require('recipient_name', 'Who it is addressed to')
          ->maxLen('recipient_name', 160, 'Who it is addressed to')
          ->require('subject', 'Subject')
          ->maxLen('subject', 200, 'Subject')
          ->require('body', 'The letter itself')
          ->require('signatory_name', 'Signed by')
          ->maxLen('signatory_name', 120, 'Signed by')
          ->date('letter_date', 'Date', true);

        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Session::flashInput($request->all());
            Response::back('/letters');
        }

        $clientId = $request->int('client_id');

        $closing = (string) $request->input('closing', 'Yours faithfully');

        return [
            'client_id'         => $clientId > 0 ? $clientId : null,
            'recipient_name'    => trim((string) $request->input('recipient_name')),
            'recipient_title'   => trim((string) $request->input('recipient_title')) ?: null,
            'recipient_org'     => trim((string) $request->input('recipient_org')) ?: null,
            'recipient_address' => trim((string) $request->input('recipient_address')) ?: null,
            'letter_date'       => date('Y-m-d', strtotime((string) $request->input('letter_date'))),
            'subject'           => trim((string) $request->input('subject')),
            'salutation'        => trim((string) $request->input('salutation')) ?: 'Dear Sir/Madam',
            'body'              => trim((string) $request->input('body')),
            'closing'           => in_array($closing, self::CLOSINGS, true) ? $closing : 'Yours faithfully',
            'signatory_name'    => trim((string) $request->input('signatory_name')),
            'signatory_title'   => trim((string) $request->input('signatory_title')) ?: null,
            'status'            => $request->input('status') === 'final' ? 'final' : 'draft',
        ];
    }

    private function findOrFail(int $id): array
    {
        $letter = Database::first(
            'SELECT l.*, c.name AS client_name, u.name AS author
               FROM letters l
          LEFT JOIN clients c ON c.id = l.client_id
          LEFT JOIN users u ON u.id = l.created_by
              WHERE l.id = :id',
            ['id' => $id]
        );

        if (!$letter) {
            throw new HttpException(404, 'That letter does not exist.');
        }

        return $letter;
    }

    /** Address fields filled in from a client, when one is chosen. */
    private function prefillFromClient(int $clientId): array
    {
        if ($clientId < 1) {
            return [];
        }

        $client = Database::first(
            'SELECT id, name, contact_person, address FROM clients WHERE id = :id',
            ['id' => $clientId]
        );

        if (!$client) {
            return [];
        }

        return [
            'client_id'         => (int) $client['id'],
            'recipient_name'    => $client['contact_person'] ?: $client['name'],
            'recipient_org'     => $client['contact_person'] ? $client['name'] : null,
            'recipient_address' => $client['address'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function clients(): array
    {
        return Database::all(
            "SELECT id, name, contact_person, address FROM clients
              WHERE status = 'active' ORDER BY name"
        );
    }

    /** LTR-2026-0001, stepping past anything already taken. */
    private function reference(): string
    {
        $prefix = Settings::get('letter_prefix', 'LTR');
        $year   = date('Y');

        $seq = (int) Database::scalar(
            'SELECT COUNT(*) + 1 FROM letters WHERE YEAR(created_at) = :y',
            ['y' => $year],
            1
        );

        do {
            $ref   = sprintf('%s-%s-%04d', $prefix, $year, $seq);
            $taken = Database::scalar('SELECT id FROM letters WHERE reference = :r', ['r' => $ref]);
            $seq++;
        } while ($taken);

        return $ref;
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM letters GROUP BY status');

        return array_map('intval', array_column($rows, 'n', 'status'));
    }
}
