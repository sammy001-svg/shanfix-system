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
use App\Core\Validator;

class LeadController extends Controller
{
    public const STAGES = [
        'new'         => ['label' => 'New',          'probability' => 10],
        'contacted'   => ['label' => 'Contacted',    'probability' => 25],
        'qualified'   => ['label' => 'Qualified',    'probability' => 45],
        'proposal'    => ['label' => 'Proposal Sent','probability' => 65],
        'negotiation' => ['label' => 'Negotiation',  'probability' => 80],
        'won'         => ['label' => 'Won',          'probability' => 100],
        'lost'        => ['label' => 'Lost',         'probability' => 0],
    ];

    public const SOURCES = [
        'walk_in', 'referral', 'website', 'social_media',
        'phone_call', 'email', 'exhibition', 'cold_outreach', 'other',
    ];

    public const ACTIVITY_TYPES = [
        'call', 'email', 'whatsapp', 'meeting', 'site_visit', 'quotation_sent', 'note',
    ];

    // -- Pipeline board ------------------------------------------------

    public function index(Request $request): void
    {
        $view     = (string) $request->query('view', 'board');
        $search   = (string) $request->query('q', '');
        $assigned = (int) $request->query('assigned', 0);
        $source   = (string) $request->query('source', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(l.name LIKE :q OR l.company LIKE :q2 OR l.phone LIKE :q3 OR l.lead_number LIKE :q4 OR l.email LIKE :q5)';
            $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = '%' . $search . '%';
        }

        if ($assigned > 0) {
            $where[] = 'l.assigned_to = :assigned';
            $params['assigned'] = $assigned;
        }

        if (in_array($source, self::SOURCES, true)) {
            $where[] = 'l.source = :source';
            $params['source'] = $source;
        }

        $clause = implode(' AND ', $where);

        $leads = Database::all(
            "SELECT l.*, s.name AS service_name, u.name AS assignee_name, u.avatar_color,
                    (SELECT MIN(r.remind_at) FROM reminders r
                      WHERE r.lead_id = l.id AND r.is_done = 0) AS next_reminder
               FROM leads l
          LEFT JOIN services s ON s.id = l.service_id
          LEFT JOIN users u ON u.id = l.assigned_to
              WHERE {$clause}
           ORDER BY l.updated_at DESC",
            $params
        );

        // Bucket by stage for the kanban board.
        $board = array_fill_keys(array_keys(self::STAGES), []);
        $stageValues = array_fill_keys(array_keys(self::STAGES), 0.0);

        foreach ($leads as $lead) {
            $stage = $lead['stage'];
            if (!isset($board[$stage])) {
                $stage = 'new';
            }
            $board[$stage][] = $lead;
            $stageValues[$stage] += (float) $lead['estimated_value'];
        }

        $summary = Database::first(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN stage NOT IN ('won','lost') THEN 1 END) AS open_leads,
                    COUNT(CASE WHEN stage = 'won' THEN 1 END) AS won,
                    COUNT(CASE WHEN stage = 'lost' THEN 1 END) AS lost,
                    COALESCE(SUM(CASE WHEN stage NOT IN ('won','lost') THEN estimated_value END), 0) AS pipeline_value,
                    COALESCE(SUM(CASE WHEN stage = 'won' THEN estimated_value END), 0) AS won_value
               FROM leads"
        );

        $overdueFollowups = (int) Database::scalar(
            'SELECT COUNT(*) FROM reminders WHERE is_done = 0 AND remind_at < NOW() AND lead_id IS NOT NULL',
            [],
            0
        );

        $this->view('leads/index', [
            'title'            => 'Leads',
            'view'             => $view === 'list' ? 'list' : 'board',
            'leads'            => $leads,
            'board'            => $board,
            'stageValues'      => $stageValues,
            'stages'           => self::STAGES,
            'sources'          => self::SOURCES,
            'summary'          => $summary,
            'overdueFollowups' => $overdueFollowups,
            'users'            => $this->salesUsers(),
            'filters'          => compact('search', 'assigned', 'source'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('leads.manage');

        $this->view('leads/form', [
            'title'     => 'New Lead',
            'lead'      => null,
            'services'  => $this->services(),
            'inventory' => $this->inventory(),
            'users'     => $this->salesUsers(),
            'stages'    => self::STAGES,
            'sources'   => self::SOURCES,
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('leads.manage');

        $data = $this->validated($request, null);
        $data['lead_number'] = Numbering::next('lead');
        $data['created_by']  = Auth::id();

        $id = Database::transaction(function () use ($data, $request) {
            $id = Database::insert('leads', $data);

            Database::insert('lead_activities', [
                'lead_id'       => $id,
                'user_id'       => Auth::id(),
                'activity_type' => 'note',
                'subject'       => 'Lead created',
                'notes'         => 'Lead registered from ' . label_of($data['source']) . '.',
                'activity_date' => date('Y-m-d H:i:s'),
            ]);

            // Optional first follow-up, straight from the create form.
            $followUp = $request->input('follow_up_at');
            if ($followUp && strtotime((string) $followUp)) {
                Database::insert('reminders', [
                    'lead_id'    => $id,
                    'user_id'    => $data['assigned_to'] ?: Auth::id(),
                    'title'      => 'Follow up with ' . $data['name'],
                    'notes'      => $data['requirement'] ? str_excerpt($data['requirement'], 180) : null,
                    'remind_at'  => date('Y-m-d H:i:s', strtotime((string) $followUp)),
                    'created_by' => Auth::id(),
                ]);
            }

            return $id;
        });

        ActivityLog::record('lead_created', 'lead', $id, 'Registered lead ' . $data['name']);
        Session::success('Lead registered. Log your first activity to keep the trail complete.');
        Response::to('/leads/' . $id);
    }

    public function show(Request $request): void
    {
        $lead = $this->findOrFail($request->paramInt('id'));

        $activities = Database::all(
            'SELECT a.*, u.name AS user_name, u.avatar_color
               FROM lead_activities a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE a.lead_id = :id
           ORDER BY a.activity_date DESC, a.id DESC',
            ['id' => $lead['id']]
        );

        $reminders = Database::all(
            'SELECT r.*, u.name AS user_name
               FROM reminders r
          LEFT JOIN users u ON u.id = r.user_id
              WHERE r.lead_id = :id
           ORDER BY r.is_done ASC, r.remind_at ASC',
            ['id' => $lead['id']]
        );

        $documents = Database::all(
            'SELECT id, doc_type, doc_number, issue_date, status, total
               FROM documents WHERE lead_id = :id ORDER BY issue_date DESC',
            ['id' => $lead['id']]
        );

        $this->view('leads/show', [
            'title'         => $lead['name'],
            'lead'          => $lead,
            'activities'    => $activities,
            'reminders'     => $reminders,
            'documents'     => $documents,
            'stages'        => self::STAGES,
            'activityTypes' => self::ACTIVITY_TYPES,
            'users'         => $this->salesUsers(),
        ]);
    }

    public function edit(Request $request): void
    {
        $this->authorize('leads.manage');

        $lead = $this->findOrFail($request->paramInt('id'));

        $this->view('leads/form', [
            'title'     => 'Edit ' . $lead['name'],
            'lead'      => $lead,
            'services'  => $this->services(),
            'inventory' => $this->inventory(),
            'users'     => $this->salesUsers(),
            'stages'    => self::STAGES,
            'sources'   => self::SOURCES,
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('leads.manage');

        $lead = $this->findOrFail($request->paramInt('id'));
        $data = $this->validated($request, (int) $lead['id']);

        // Stage moves go through moveStage() so they always leave a trail.
        unset($data['stage'], $data['probability']);

        Database::update('leads', $data, ['id' => $lead['id']]);

        ActivityLog::record('lead_updated', 'lead', (int) $lead['id'], 'Updated lead ' . $data['name']);
        Session::success('Lead updated.');
        Response::to('/leads/' . $lead['id']);
    }

    /** Move a lead along the pipeline, logging the change. */
    public function moveStage(Request $request): void
    {
        $this->authorize('leads.manage');

        $lead  = $this->findOrFail($request->paramInt('id'));
        $stage = (string) $request->input('stage', '');

        if (!isset(self::STAGES[$stage])) {
            throw new HttpException(422, 'That pipeline stage is not valid.');
        }

        if ($stage === $lead['stage']) {
            Response::back('/leads/' . $lead['id']);
        }

        $lostReason = (string) $request->input('lost_reason', '');

        if ($stage === 'lost' && trim($lostReason) === '') {
            Session::error('Please give a reason so the team can learn from it.');
            Response::back('/leads/' . $lead['id']);
        }

        Database::transaction(function () use ($lead, $stage, $lostReason, $request) {
            Database::update('leads', [
                'stage'       => $stage,
                'probability' => self::STAGES[$stage]['probability'],
                'lost_reason' => $stage === 'lost' ? $lostReason : null,
            ], ['id' => $lead['id']]);

            Database::insert('lead_activities', [
                'lead_id'       => $lead['id'],
                'user_id'       => Auth::id(),
                'activity_type' => 'stage_change',
                'subject'       => 'Stage: ' . self::STAGES[$lead['stage']]['label'] . ' → ' . self::STAGES[$stage]['label'],
                'notes'         => $request->input('stage_note') ?: ($stage === 'lost' ? $lostReason : null),
                'activity_date' => date('Y-m-d H:i:s'),
            ]);
        });

        ActivityLog::record(
            'lead_stage_changed',
            'lead',
            (int) $lead['id'],
            $lead['name'] . ': ' . $lead['stage'] . ' → ' . $stage
        );

        if ($stage === 'won' && !$lead['converted_client_id']) {
            Session::success('Marked as won. Convert this lead into a client to start invoicing.');
        } else {
            Session::success('Lead moved to ' . self::STAGES[$stage]['label'] . '.');
        }

        Response::to('/leads/' . $lead['id']);
    }

    /** Log a call, meeting, email or note against the lead. */
    public function logActivity(Request $request): void
    {
        $this->authorize('leads.manage');

        $lead = $this->findOrFail($request->paramInt('id'));

        $v = new Validator($request->all());
        $v->in('activity_type', self::ACTIVITY_TYPES, 'Activity type')
          ->require('notes', 'Details')
          ->maxLen('subject', 200, 'Subject')
          ->maxLen('outcome', 160, 'Outcome');

        if ($v->fails()) {
            $v->redirectBack('/leads/' . $lead['id']);
        }

        $activityDate = $request->input('activity_date')
            ? date('Y-m-d H:i:s', strtotime((string) $request->input('activity_date')))
            : date('Y-m-d H:i:s');

        Database::transaction(function () use ($lead, $request, $activityDate) {
            Database::insert('lead_activities', [
                'lead_id'       => $lead['id'],
                'user_id'       => Auth::id(),
                'activity_type' => (string) $request->input('activity_type', 'note'),
                'subject'       => $request->input('subject') ?: null,
                'notes'         => (string) $request->input('notes'),
                'outcome'       => $request->input('outcome') ?: null,
                'activity_date' => $activityDate,
            ]);

            // Touch the lead so it sorts to the top of the board.
            Database::update('leads', ['updated_at' => date('Y-m-d H:i:s')], ['id' => $lead['id']]);

            // Chain the next follow-up straight from the activity form.
            $nextFollowUp = $request->input('next_follow_up');
            if ($nextFollowUp && strtotime((string) $nextFollowUp)) {
                Database::insert('reminders', [
                    'lead_id'    => $lead['id'],
                    'user_id'    => $lead['assigned_to'] ?: Auth::id(),
                    'title'      => 'Follow up with ' . $lead['name'],
                    'notes'      => $request->input('notes') ? str_excerpt((string) $request->input('notes'), 180) : null,
                    'remind_at'  => date('Y-m-d H:i:s', strtotime((string) $nextFollowUp)),
                    'created_by' => Auth::id(),
                ]);
            }
        });

        ActivityLog::record(
            'lead_activity_logged',
            'lead',
            (int) $lead['id'],
            label_of((string) $request->input('activity_type')) . ' logged for ' . $lead['name']
        );

        Session::success('Activity logged.');
        Response::to('/leads/' . $lead['id']);
    }

    public function addReminder(Request $request): void
    {
        $this->authorize('leads.manage');

        $lead = $this->findOrFail($request->paramInt('id'));

        $v = new Validator($request->all());
        $v->require('title', 'Reminder title')
          ->maxLen('title', 200, 'Reminder title')
          ->require('remind_at', 'Reminder date');

        if (!strtotime((string) $request->input('remind_at', ''))) {
            $v->custom('remind_at', false, 'Enter a valid date and time.');
        }

        if ($v->fails()) {
            $v->redirectBack('/leads/' . $lead['id']);
        }

        Database::insert('reminders', [
            'lead_id'    => $lead['id'],
            'user_id'    => $request->int('user_id') ?: ($lead['assigned_to'] ?: Auth::id()),
            'title'      => (string) $request->input('title'),
            'notes'      => $request->input('notes') ?: null,
            'remind_at'  => date('Y-m-d H:i:s', strtotime((string) $request->input('remind_at'))),
            'created_by' => Auth::id(),
        ]);

        Session::success('Reminder set.');
        Response::to('/leads/' . $lead['id']);
    }

    /** Won lead → client record, carrying the details across. */
    public function convert(Request $request): void
    {
        $this->authorize('clients.manage');

        $lead = $this->findOrFail($request->paramInt('id'));

        if ($lead['converted_client_id']) {
            Session::info('This lead has already been converted.');
            Response::to('/clients/' . $lead['converted_client_id']);
        }

        // Warn on an obvious duplicate rather than silently creating one.
        if ($lead['phone'] || $lead['email']) {
            $duplicate = Database::first(
                'SELECT id, name FROM clients
                  WHERE (phone IS NOT NULL AND phone = :phone)
                     OR (email IS NOT NULL AND email = :email)
                  LIMIT 1',
                ['phone' => $lead['phone'] ?: '~none~', 'email' => $lead['email'] ?: '~none~']
            );

            if ($duplicate && !$request->bool('confirm_duplicate')) {
                Session::warning(
                    'A client with these contact details already exists (' . $duplicate['name'] . '). '
                    . 'Link the lead manually, or confirm to create a second record.'
                );
                Response::to('/leads/' . $lead['id']);
            }
        }

        $clientId = Database::transaction(function () use ($lead) {
            $clientId = Database::insert('clients', [
                'client_code'    => Numbering::next('client'),
                'client_type'    => $lead['company'] ? 'company' : 'individual',
                'name'           => $lead['company'] ?: $lead['name'],
                'contact_person' => $lead['company'] ? $lead['name'] : null,
                'email'          => $lead['email'],
                'phone'          => $lead['phone'],
                'notes'          => $lead['requirement']
                    ? "Converted from lead {$lead['lead_number']}.\n\nOriginal requirement:\n" . $lead['requirement']
                    : 'Converted from lead ' . $lead['lead_number'] . '.',
                'source_lead_id' => $lead['id'],
                'status'         => 'active',
                'created_by'     => Auth::id(),
            ]);

            Database::update('leads', [
                'stage'               => 'won',
                'probability'         => 100,
                'converted_client_id' => $clientId,
                'converted_at'        => date('Y-m-d H:i:s'),
            ], ['id' => $lead['id']]);

            Database::insert('lead_activities', [
                'lead_id'       => $lead['id'],
                'user_id'       => Auth::id(),
                'activity_type' => 'stage_change',
                'subject'       => 'Converted to client',
                'notes'         => 'Deal closed. Client record created.',
                'activity_date' => date('Y-m-d H:i:s'),
            ]);

            // Close out any follow-ups that no longer apply.
            Database::run(
                'UPDATE reminders SET is_done = 1, completed_at = NOW()
                  WHERE lead_id = :id AND is_done = 0',
                ['id' => $lead['id']]
            );

            return $clientId;
        });

        ActivityLog::record(
            'lead_converted',
            'lead',
            (int) $lead['id'],
            $lead['name'] . ' converted to client #' . $clientId
        );

        Session::success('Deal closed. ' . $lead['name'] . ' is now a client — raise their first invoice below.');
        Response::to('/clients/' . $clientId);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('leads.delete');

        $lead = $this->findOrFail($request->paramInt('id'));

        if ($lead['converted_client_id']) {
            Session::error('This lead has been converted to a client and cannot be deleted.');
            Response::to('/leads/' . $lead['id']);
        }

        Database::delete('leads', ['id' => $lead['id']]);

        ActivityLog::record('lead_deleted', 'lead', (int) $lead['id'], 'Deleted lead ' . $lead['name']);
        Session::success('Lead deleted.');
        Response::to('/leads');
    }

    // -- Internals -----------------------------------------------------

    private function validated(Request $request, ?int $ignoreId): array
    {
        $v = new Validator($request->all());
        $v->require('name', 'Contact name')
          ->maxLen('name', 180, 'Contact name')
          ->maxLen('company', 180, 'Company')
          ->email('email', 'Email address')
          ->phone('phone', 'Phone number')
          ->in('source', self::SOURCES, 'Lead source')
          ->in('stage', array_keys(self::STAGES), 'Stage')
          ->numeric('estimated_value', 'Estimated value')
          ->min('estimated_value', 0, 'Estimated value')
          ->date('expected_close_date', 'Expected close date');

        if (!$request->input('phone') && !$request->input('email')) {
            $v->custom('phone', false, 'Provide at least a phone number or an email address.');
        }

        if ($request->input('service_id')) {
            $v->exists('service_id', 'services', 'service');
        }

        if ($request->input('assigned_to')) {
            $v->exists('assigned_to', 'users', 'team member');
        }

        if ($v->fails()) {
            $v->redirectBack($ignoreId ? "/leads/{$ignoreId}/edit" : '/leads/create');
        }

        $stage = (string) $request->input('stage', 'new');

        return [
            'name'                => (string) $request->input('name'),
            'company'             => $request->input('company') ?: null,
            'email'               => $request->input('email') ? strtolower((string) $request->input('email')) : null,
            'phone'               => $request->input('phone') ?: null,
            'source'              => (string) $request->input('source', 'other'),
            'service_id'          => $request->int('service_id') ?: null,
            'inventory_item_id'   => $request->int('inventory_item_id') ?: null,
            'requirement'         => $request->input('requirement') ?: null,
            'estimated_value'     => $request->decimal('estimated_value'),
            'stage'               => $stage,
            'probability'         => self::STAGES[$stage]['probability'],
            'assigned_to'         => $request->int('assigned_to') ?: null,
            'expected_close_date' => $request->input('expected_close_date') ?: null,
        ];
    }

    private function findOrFail(int $id): array
    {
        $lead = Database::first(
            'SELECT l.*, s.name AS service_name, i.name AS item_name,
                    u.name AS assignee_name, u.avatar_color,
                    cr.name AS created_by_name, c.name AS client_name
               FROM leads l
          LEFT JOIN services s ON s.id = l.service_id
          LEFT JOIN inventory_items i ON i.id = l.inventory_item_id
          LEFT JOIN users u ON u.id = l.assigned_to
          LEFT JOIN users cr ON cr.id = l.created_by
          LEFT JOIN clients c ON c.id = l.converted_client_id
              WHERE l.id = :id',
            ['id' => $id]
        );

        if (!$lead) {
            throw new HttpException(404, 'That lead does not exist.');
        }

        return $lead;
    }

    private function services(): array
    {
        return Database::all('SELECT id, name, price, pricing_type FROM services WHERE is_active = 1 ORDER BY name');
    }

    private function inventory(): array
    {
        return Database::all('SELECT id, name, selling_price FROM inventory_items WHERE is_active = 1 ORDER BY name');
    }

    private function salesUsers(): array
    {
        return Database::all(
            "SELECT id, name, role, avatar_color FROM users
              WHERE is_active = 1 AND role IN ('admin','manager','sales')
           ORDER BY name"
        );
    }
}
