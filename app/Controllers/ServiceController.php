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
use App\Core\Validator;

class ServiceController extends Controller
{
    public const PRICING_TYPES = [
        'fixed'   => 'Fixed price',
        'from'    => 'Starting from',
        'hourly'  => 'Per hour',
        'daily'   => 'Per day',
        'monthly' => 'Per month (retainer)',
        'project' => 'Quoted per project',
    ];

    public function index(Request $request): void
    {
        $search   = (string) $request->query('q', '');
        $category = (int) $request->query('category', 0);
        $status   = (string) $request->query('status', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(s.name LIKE :q OR s.code LIKE :q2 OR s.description LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }

        if ($category > 0) {
            $where[] = 's.category_id = :cat';
            $params['cat'] = $category;
        }

        if ($status === 'active')   { $where[] = 's.is_active = 1'; }
        if ($status === 'inactive') { $where[] = 's.is_active = 0'; }

        $clause = implode(' AND ', $where);

        // The examples come along with the list so a card can show how many
        // there are and lead with one of them. Sub-selects rather than joins:
        // a join would multiply the service row by its examples and need
        // grouping back down again.
        $services = Database::all(
            "SELECT s.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM service_jobs sj
                      WHERE sj.service_id = s.id) AS example_count,
                    -- Images only. Print artwork is very often a PDF, and a
                    -- PDF in an <img> tag renders as a broken picture.
                    (SELECT jf.file_path
                       FROM service_jobs sj
                       JOIN job_files jf ON jf.job_id = sj.job_id
                      WHERE sj.service_id = s.id
                        AND jf.status = 'approved'
                        AND LOWER(SUBSTRING_INDEX(jf.file_path, '.', -1))
                            IN ('jpg','jpeg','png','webp','gif')
                   ORDER BY sj.sort_order, jf.version DESC
                      LIMIT 1) AS cover_path
               FROM services s
          LEFT JOIN categories c ON c.id = s.category_id
              WHERE {$clause}
           ORDER BY c.name ASC, s.name ASC",
            $params
        );

        // Group by category for the card layout.
        $grouped = [];
        foreach ($services as $s) {
            $grouped[$s['category_name'] ?? 'Uncategorised'][] = $s;
        }

        $stats = Database::first(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active
               FROM services'
        );

        $this->view('services/index', [
            'title'      => 'Services',
            'grouped'    => $grouped,
            'stats'      => $stats,
            'categories' => $this->categories(),
            'filters'    => compact('search', 'category', 'status'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('services.manage');

        $this->view('services/form', [
            'title'        => 'New Service',
            'service'      => null,
            'categories'   => $this->categories(),
            'pricingTypes' => self::PRICING_TYPES,
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('services.manage');

        $data = $this->validated($request, null);
        $id   = Database::insert('services', $data);

        ActivityLog::record('service_created', 'service', $id, 'Added service ' . $data['name']);
        Session::success('"' . $data['name'] . '" has been added to your service catalogue.');
        Response::to('/services/' . $id);
    }

    public function show(Request $request): void
    {
        $service = $this->findOrFail($request->paramInt('id'));

        $sold = Database::all(
            "SELECT d.id, d.doc_number, d.doc_type, d.issue_date, d.status,
                    c.name AS client_name, di.quantity, di.line_total
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
               JOIN clients c ON c.id = d.client_id
              WHERE di.item_type = 'service' AND di.ref_id = :id
           ORDER BY d.issue_date DESC
              LIMIT 20",
            ['id' => $service['id']]
        );

        $revenue = (float) Database::scalar(
            "SELECT COALESCE(SUM(di.line_total), 0)
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
              WHERE di.item_type = 'service' AND di.ref_id = :id
                AND d.doc_type = 'invoice' AND d.status <> 'cancelled'",
            ['id' => $service['id']],
            0
        );

        $openLeads = Database::all(
            "SELECT l.id, l.lead_number, l.name, l.company, l.stage, l.estimated_value
               FROM leads l
              WHERE l.service_id = :id AND l.stage NOT IN ('won','lost')
           ORDER BY l.created_at DESC
              LIMIT 10",
            ['id' => $service['id']]
        );

        $this->view('services/show', [
            'title'        => $service['name'],
            'service'      => $service,
            'sold'         => $sold,
            'revenue'      => $revenue,
            'openLeads'    => $openLeads,
            'pricingTypes' => self::PRICING_TYPES,
            'examples'     => $this->examplesFor((int) $service['id']),
            'suggestions'  => $this->suggestedJobs($service),
        ]);
    }

    /**
     * Past jobs linked to this service, with a picture where there is one.
     *
     * The artwork is what makes this useful in front of a client, so the
     * approved proof comes along with each job rather than being fetched a
     * row at a time by the view.
     */
    private function examplesFor(int $serviceId): array
    {
        return Database::all(
            "SELECT sj.id AS link_id, sj.note, sj.sort_order,
                    j.id, j.job_number, j.title, j.stage, j.completed_at, j.delivered_at, j.created_at,
                    cl.name AS client_name, cl.id AS client_id,
                    (SELECT jf.file_path FROM job_files jf
                      WHERE jf.job_id = j.id AND jf.status = 'approved'
                        AND LOWER(SUBSTRING_INDEX(jf.file_path, '.', -1))
                            IN ('jpg','jpeg','png','webp','gif')
                   ORDER BY jf.version DESC LIMIT 1) AS proof_path,
                    -- Approved artwork that is not a picture — a PDF, usually.
                    -- Worth saying so rather than showing a blank tile.
                    (SELECT UPPER(SUBSTRING_INDEX(jf.file_path, '.', -1))
                       FROM job_files jf
                      WHERE jf.job_id = j.id AND jf.status = 'approved'
                   ORDER BY jf.version DESC LIMIT 1) AS proof_kind,
                    (SELECT COALESCE(SUM(di.line_total), 0)
                       FROM document_items di WHERE di.document_id = j.document_id) AS job_value
               FROM service_jobs sj
               JOIN jobs j    ON j.id = sj.job_id
               JOIN clients cl ON cl.id = j.client_id
              WHERE sj.service_id = :id
           ORDER BY sj.sort_order, j.completed_at DESC, j.id DESC",
            ['id' => $serviceId]
        );
    }

    /**
     * Jobs worth linking, offered so nobody has to hunt through the board.
     *
     * Finished work only — an example is something we can show, and a job
     * still on the floor is not that yet. Anything invoiced alongside this
     * service is the strongest signal we have that it is the same kind of
     * work, so those come first; recent finished jobs fill the rest.
     */
    private function suggestedJobs(array $service): array
    {
        return Database::all(
            "SELECT j.id, j.job_number, j.title, j.completed_at, j.delivered_at,
                    cl.name AS client_name,
                    CASE WHEN di.id IS NULL THEN 0 ELSE 1 END AS same_service
               FROM jobs j
               JOIN clients cl ON cl.id = j.client_id
          LEFT JOIN document_items di
                 ON di.document_id = j.document_id
                AND di.item_type = 'service'
                AND di.ref_id = :sid1
              WHERE j.stage IN ('ready','delivered')
                AND j.id NOT IN (
                    SELECT job_id FROM service_jobs WHERE service_id = :sid2
                )
           ORDER BY same_service DESC, COALESCE(j.delivered_at, j.completed_at, j.created_at) DESC
              LIMIT 12",
            ['sid1' => $service['id'], 'sid2' => $service['id']]
        );
    }

    /** Add a finished job to this service's examples. */
    public function linkJob(Request $request): void
    {
        $this->authorize('services.manage');

        $service = $this->findOrFail($request->paramInt('id'));
        $jobId   = $request->int('job_id');

        $job = Database::first('SELECT id, job_number FROM jobs WHERE id = :id', ['id' => $jobId]);

        if (!$job) {
            Session::error('That job could not be found.');
            Response::to('/services/' . $service['id']);
        }

        try {
            Database::insert('service_jobs', [
                'service_id' => (int) $service['id'],
                'job_id'     => (int) $job['id'],
                'note'       => trim((string) $request->input('note', '')) ?: null,
                'linked_by'  => Auth::id(),
            ]);
        } catch (\Throwable) {
            // The unique key caught a job that is already an example —
            // a double click, not something to complain about loudly.
            Session::warning($job['job_number'] . ' is already linked to this service.');
            Response::to('/services/' . $service['id']);
        }

        ActivityLog::record('service_example_added', 'service', (int) $service['id'],
            'Linked ' . $job['job_number'] . ' to ' . $service['name']);

        Session::success($job['job_number'] . ' added as an example of this service.');
        Response::to('/services/' . $service['id']);
    }

    /** Remove an example. The job itself is untouched. */
    public function unlinkJob(Request $request): void
    {
        $this->authorize('services.manage');

        $service = $this->findOrFail($request->paramInt('id'));

        Database::run(
            'DELETE FROM service_jobs WHERE service_id = :s AND job_id = :j',
            ['s' => $service['id'], 'j' => $request->paramInt('job')]
        );

        Session::success('Example removed. The job card itself is unchanged.');
        Response::to('/services/' . $service['id']);
    }

    public function edit(Request $request): void
    {
        $this->authorize('services.manage');

        $service = $this->findOrFail($request->paramInt('id'));

        $this->view('services/form', [
            'title'        => 'Edit ' . $service['name'],
            'service'      => $service,
            'categories'   => $this->categories(),
            'pricingTypes' => self::PRICING_TYPES,
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('services.manage');

        $service = $this->findOrFail($request->paramInt('id'));
        $data    = $this->validated($request, (int) $service['id']);

        Database::update('services', $data, ['id' => $service['id']]);

        ActivityLog::record('service_updated', 'service', (int) $service['id'], 'Updated service ' . $data['name']);
        Session::success('Service updated.');
        Response::to('/services/' . $service['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('services.manage');

        $service = $this->findOrFail($request->paramInt('id'));

        $used = (int) Database::scalar(
            "SELECT COUNT(*) FROM document_items WHERE item_type = 'service' AND ref_id = :id",
            ['id' => $service['id']],
            0
        );

        if ($used > 0) {
            Database::update('services', ['is_active' => 0], ['id' => $service['id']]);
            ActivityLog::record('service_deactivated', 'service', (int) $service['id'], 'Deactivated ' . $service['name']);
            Session::warning('"' . $service['name'] . '" is used on ' . $used . ' document(s), so it was deactivated instead of deleted.');
            Response::to('/services');
        }

        Database::delete('services', ['id' => $service['id']]);
        ActivityLog::record('service_deleted', 'service', (int) $service['id'], 'Deleted ' . $service['name']);
        Session::success('"' . $service['name'] . '" has been deleted.');
        Response::to('/services');
    }

    // -- Internals -----------------------------------------------------

    private function validated(Request $request, ?int $ignoreId): array
    {
        $v = new Validator($request->all());
        $v->require('code', 'Service code')
          ->maxLen('code', 60, 'Service code')
          ->unique('code', 'services', 'code', 'Service code', $ignoreId)
          ->require('name', 'Service name')
          ->maxLen('name', 180, 'Service name')
          ->in('pricing_type', array_keys(self::PRICING_TYPES), 'Pricing type')
          ->numeric('price', 'Price')
          ->min('price', 0, 'Price')
          ->maxLen('unit_label', 40, 'Unit label')
          ->maxLen('lead_time', 80, 'Turnaround');

        if ($request->input('category_id')) {
            $v->exists('category_id', 'categories', 'category');
        }

        // "Quoted per project" is the only type where a zero price makes sense.
        if ($request->input('pricing_type') !== 'project' && $request->decimal('price') <= 0) {
            $v->custom('price', false, 'Enter a price, or set the pricing type to "Quoted per project".');
        }

        if ($v->fails()) {
            $v->redirectBack($ignoreId ? "/services/{$ignoreId}/edit" : '/services/create');
        }

        return [
            'code'         => strtoupper((string) $request->input('code')),
            'name'         => (string) $request->input('name'),
            'category_id'  => $request->int('category_id') ?: null,
            'description'  => $request->input('description') ?: null,
            'pricing_type' => (string) $request->input('pricing_type', 'fixed'),
            'price'        => $request->decimal('price'),
            'unit_label'   => $request->input('unit_label') ?: null,
            'lead_time'    => $request->input('lead_time') ?: null,
            'is_active'    => $request->bool('is_active') ? 1 : 0,
        ];
    }

    private function findOrFail(int $id): array
    {
        $service = Database::first(
            'SELECT s.*, c.name AS category_name
               FROM services s
          LEFT JOIN categories c ON c.id = s.category_id
              WHERE s.id = :id',
            ['id' => $id]
        );

        if (!$service) {
            throw new HttpException(404, 'That service does not exist.');
        }

        return $service;
    }

    private function categories(): array
    {
        return Database::all("SELECT id, name FROM categories WHERE type = 'service' ORDER BY name");
    }
}
