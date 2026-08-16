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

/**
 * Who we buy from.
 *
 * Deliberately close to ClientController in shape — the same fields mean
 * the same things, so anyone who can maintain a client can maintain a
 * supplier without learning a second screen.
 */
class SupplierController extends Controller
{
    public function index(Request $request): void
    {
        $this->authorize('purchases.view');

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(s.name LIKE :q OR s.supplier_code LIKE :q2 OR s.phone LIKE :q3 OR s.email LIKE :q4)';
            $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }

        $clause = implode(' AND ', $where);
        $total  = (int) Database::scalar("SELECT COUNT(*) FROM suppliers s WHERE {$clause}", $params, 0);
        $pager  = $this->paginate($total, 30);

        $suppliers = Database::all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM purchase_orders p WHERE p.supplier_id = s.id) AS order_count,
                    (SELECT COALESCE(SUM(p.total), 0) FROM purchase_orders p
                      WHERE p.supplier_id = s.id AND p.status <> 'cancelled') AS total_spend
               FROM suppliers s
              WHERE {$clause}
           ORDER BY s.name
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $this->view('suppliers/index', [
            'title'     => 'Suppliers',
            'suppliers' => $suppliers,
            'pager'     => $pager,
            'filters'   => compact('search', 'status'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('purchases.manage');

        $this->view('suppliers/form', [
            'title'    => 'New supplier',
            'supplier' => null,
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('purchases.manage');

        $data = $this->validated($request);

        $id = Database::insert('suppliers', $data + [
            'supplier_code' => Numbering::next('supplier'),
            'created_by'    => Auth::id(),
        ]);

        ActivityLog::record('supplier_created', 'supplier', $id, 'Added supplier ' . $data['name']);
        Session::success($data['name'] . ' added.');
        Response::to('/suppliers/' . $id);
    }

    public function show(Request $request): void
    {
        $this->authorize('purchases.view');

        $supplier = $this->findOrFail($request->paramInt('id'));

        $this->view('suppliers/show', [
            'title'    => $supplier['name'],
            'supplier' => $supplier,
            'orders'   => Database::all(
                'SELECT * FROM purchase_orders WHERE supplier_id = :id ORDER BY id DESC LIMIT 40',
                ['id' => $supplier['id']]
            ),
        ]);
    }

    public function edit(Request $request): void
    {
        $this->authorize('purchases.manage');

        $this->view('suppliers/form', [
            'title'    => 'Edit supplier',
            'supplier' => $this->findOrFail($request->paramInt('id')),
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('purchases.manage');

        $supplier = $this->findOrFail($request->paramInt('id'));
        $data     = $this->validated($request);

        Database::update('suppliers', $data, ['id' => $supplier['id']]);

        ActivityLog::record('supplier_updated', 'supplier', (int) $supplier['id'], 'Updated ' . $data['name']);
        Session::success($data['name'] . ' updated.');
        Response::to('/suppliers/' . $supplier['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('purchases.delete');

        $supplier = $this->findOrFail($request->paramInt('id'));

        // Purchase history is a financial record. A supplier we have bought
        // from is retired, never erased, so the orders keep their name.
        $orders = (int) Database::scalar(
            'SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = :id',
            ['id' => $supplier['id']],
            0
        );

        if ($orders > 0) {
            Database::update('suppliers', ['status' => 'inactive'], ['id' => $supplier['id']]);
            Session::warning(
                $supplier['name'] . ' has ' . $orders . ' order(s) on record, so it was '
                . 'marked inactive rather than deleted.'
            );
            Response::to('/suppliers/' . $supplier['id']);
        }

        Database::delete('suppliers', ['id' => $supplier['id']]);
        ActivityLog::record('supplier_deleted', 'supplier', (int) $supplier['id'], 'Deleted ' . $supplier['name']);
        Session::success($supplier['name'] . ' deleted.');
        Response::to('/suppliers');
    }

    // -- internals -------------------------------------------------------

    private function validated(Request $request): array
    {
        $v = new Validator($request->all());
        $v->require('name', 'Supplier name')
          ->maxLen('name', 180, 'Supplier name')
          ->email('email', 'Email')
          ->phone('phone', 'Phone')
          ->maxLen('contact_person', 140, 'Contact person')
          ->maxLen('kra_pin', 30, 'KRA PIN')
          ->maxLen('address', 255, 'Address')
          ->maxLen('city', 80, 'City');

        if ($v->fails()) {
            $v->redirectBack('/suppliers');
        }

        return [
            'name'           => trim((string) $request->input('name')),
            'contact_person' => trim((string) $request->input('contact_person')) ?: null,
            'email'          => trim((string) $request->input('email')) ?: null,
            'phone'          => trim((string) $request->input('phone')) ?: null,
            'kra_pin'        => strtoupper(trim((string) $request->input('kra_pin'))) ?: null,
            'address'        => trim((string) $request->input('address')) ?: null,
            'city'           => trim((string) $request->input('city')) ?: null,
            'payment_terms'  => max(0, min(365, $request->int('payment_terms', 30))),
            'notes'          => trim((string) $request->input('notes')) ?: null,
            'status'         => $request->input('status') === 'inactive' ? 'inactive' : 'active',
        ];
    }

    private function findOrFail(int $id): array
    {
        $supplier = Database::first('SELECT * FROM suppliers WHERE id = :id', ['id' => $id]);

        if (!$supplier) {
            throw new HttpException(404, 'That supplier does not exist.');
        }

        return $supplier;
    }
}
