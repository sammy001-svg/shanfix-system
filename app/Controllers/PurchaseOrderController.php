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
use App\Services\StockLedger;

/**
 * Purchase orders, and receiving goods against them.
 *
 * A draft is a working document. Marking it ordered is the commitment to
 * the supplier. Stock only moves on receipt, and only for lines that are
 * really inventory — an order can carry a delivery charge, and nobody
 * puts a delivery charge on a shelf.
 */
class PurchaseOrderController extends Controller
{
    private const STATUSES = ['draft', 'ordered', 'partial', 'received', 'cancelled'];

    public function index(Request $request): void
    {
        $this->authorize('purchases.view');

        $status   = (string) $request->query('status', '');
        $supplier = $request->query('supplier_id');

        $where  = ['1=1'];
        $params = [];

        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }

        if ($supplier !== null && $supplier !== '') {
            $where[] = 'p.supplier_id = :sid';
            $params['sid'] = (int) $supplier;
        }

        $clause = implode(' AND ', $where);
        $total  = (int) Database::scalar("SELECT COUNT(*) FROM purchase_orders p WHERE {$clause}", $params, 0);
        $pager  = $this->paginate($total, 30);

        $orders = Database::all(
            "SELECT p.*, s.name AS supplier_name
               FROM purchase_orders p
               JOIN suppliers s ON s.id = p.supplier_id
              WHERE {$clause}
           ORDER BY p.id DESC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            "SELECT COUNT(CASE WHEN status IN ('ordered','partial') THEN 1 END) AS open_orders,
                    COALESCE(SUM(CASE WHEN status IN ('ordered','partial') THEN total END), 0) AS on_order,
                    COALESCE(SUM(CASE WHEN status = 'received'
                                       AND received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                      THEN total END), 0) AS received_30d
               FROM purchase_orders"
        );

        $this->view('purchases/index', [
            'title'   => 'Purchase orders',
            'orders'  => $orders,
            'pager'   => $pager,
            'summary' => $summary,
            'filters' => ['status' => $status, 'supplier_id' => $supplier],
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('purchases.manage');

        $this->view('purchases/form', array_merge($this->formData(), [
            'title'         => 'New purchase order',
            'order'         => null,
            'existingItems' => [],
            'nextNumber'    => 'Allocated on save',
        ]));
    }

    public function store(Request $request): void
    {
        $this->authorize('purchases.manage');

        $payload = $this->validatePayload($request);

        $id = Database::transaction(function () use ($payload) {
            $order = $payload['order'];
            $order['po_number']  = Numbering::next('purchase_order');
            $order['created_by'] = Auth::id();

            $orderId = Database::insert('purchase_orders', $order);
            $this->saveItems($orderId, $payload['items']);

            return $orderId;
        });

        ActivityLog::record('purchase_order_created', 'purchase_order', $id, 'Raised a purchase order');
        Session::success('Purchase order created.');
        Response::to('/purchase-orders/' . $id);
    }

    public function show(Request $request): void
    {
        $this->authorize('purchases.view');

        $order = $this->findOrFail($request->paramInt('id'));

        $this->view('purchases/show', [
            'title'     => $order['po_number'],
            'order'     => $order,
            'items'     => $this->items((int) $order['id']),
            'movements' => Database::all(
                "SELECT m.*, i.name AS item_name
                   FROM inventory_movements m
              LEFT JOIN inventory_items i ON i.id = m.item_id
                  WHERE m.reference_type = 'purchase' AND m.reference_id = :id
               ORDER BY m.id DESC",
                ['id' => $order['id']]
            ),
        ]);
    }

    public function edit(Request $request): void
    {
        $this->authorize('purchases.manage');

        $order = $this->findOrFail($request->paramInt('id'));

        // Once goods have arrived the order is a record of what happened,
        // not a plan that can be rewritten.
        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            Session::error('This order has goods received against it and can no longer be edited.');
            Response::to('/purchase-orders/' . $order['id']);
        }

        $this->view('purchases/form', array_merge($this->formData(), [
            'title'         => 'Edit ' . $order['po_number'],
            'order'         => $order,
            'existingItems' => $this->items((int) $order['id']),
            'nextNumber'    => $order['po_number'],
        ]));
    }

    public function update(Request $request): void
    {
        $this->authorize('purchases.manage');

        $order = $this->findOrFail($request->paramInt('id'));

        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            throw new HttpException(403, 'This order can no longer be edited.');
        }

        $payload = $this->validatePayload($request);

        Database::transaction(function () use ($order, $payload) {
            Database::update('purchase_orders', $payload['order'], ['id' => $order['id']]);
            Database::delete('purchase_order_items', ['purchase_order_id' => $order['id']]);
            $this->saveItems((int) $order['id'], $payload['items']);
        });

        ActivityLog::record('purchase_order_updated', 'purchase_order', (int) $order['id'], 'Updated ' . $order['po_number']);
        Session::success($order['po_number'] . ' updated.');
        Response::to('/purchase-orders/' . $order['id']);
    }

    /** Draft to ordered, or cancel. Receiving has its own action. */
    public function updateStatus(Request $request): void
    {
        $this->authorize('purchases.manage');

        $order  = $this->findOrFail($request->paramInt('id'));
        $status = (string) $request->input('status', '');

        if (!in_array($status, ['ordered', 'cancelled'], true)) {
            throw new HttpException(422, 'That status cannot be set by hand.');
        }

        if ($status === 'cancelled' && in_array($order['status'], ['partial', 'received'], true)) {
            Session::error(
                'Goods have already been received against this order. Return them through a '
                . 'stock adjustment rather than cancelling the order.'
            );
            Response::to('/purchase-orders/' . $order['id']);
        }

        Database::update('purchase_orders', ['status' => $status], ['id' => $order['id']]);

        ActivityLog::record(
            'purchase_order_' . $status,
            'purchase_order',
            (int) $order['id'],
            $order['po_number'] . ': ' . $order['status'] . ' → ' . $status
        );

        Session::success($order['po_number'] . ' marked as ' . $status . '.');
        Response::to('/purchase-orders/' . $order['id']);
    }

    /**
     * Book in a delivery.
     *
     * Quantities arrive per line, so a supplier sending half an order is
     * recorded as exactly that rather than being rounded to all or nothing.
     */
    public function receive(Request $request): void
    {
        $this->authorize('purchases.receive');

        $order = $this->findOrFail($request->paramInt('id'));

        if (!in_array($order['status'], ['ordered', 'partial'], true)) {
            Session::error('Only an order that has been placed can have goods received against it.');
            Response::to('/purchase-orders/' . $order['id']);
        }

        $quantities = [];
        foreach ((array) $request->input('receive', []) as $lineId => $qty) {
            $quantities[(int) $lineId] = (float) $qty;
        }

        if ($quantities === []) {
            Session::error('Enter the quantities that arrived.');
            Response::to('/purchase-orders/' . $order['id']);
        }

        $result = StockLedger::receivePurchase((int) $order['id'], $quantities);

        if ($result['received'] === 0) {
            Session::error('Nothing was received — check the quantities entered.');
        } else {
            ActivityLog::record(
                'purchase_order_received',
                'purchase_order',
                (int) $order['id'],
                'Received goods against ' . $order['po_number']
            );

            Session::success(
                $result['received'] . ' line(s) received. Stock and cost prices have been updated.'
            );
        }

        foreach (array_slice($result['warnings'], 0, 5) as $warning) {
            Session::warning($warning);
        }

        Response::to('/purchase-orders/' . $order['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('purchases.delete');

        $order = $this->findOrFail($request->paramInt('id'));

        if (in_array($order['status'], ['partial', 'received'], true)) {
            Session::error('An order with goods received against it cannot be deleted.');
            Response::to('/purchase-orders/' . $order['id']);
        }

        Database::delete('purchase_orders', ['id' => $order['id']]);

        ActivityLog::record('purchase_order_deleted', 'purchase_order', (int) $order['id'], 'Deleted ' . $order['po_number']);
        Session::success($order['po_number'] . ' deleted.');
        Response::to('/purchase-orders');
    }

    // -- internals -------------------------------------------------------

    private function formData(): array
    {
        return [
            'suppliers' => Database::all(
                "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"
            ),
            'stockItems' => Database::all(
                'SELECT id, name, sku, unit, cost_price FROM inventory_items ORDER BY name'
            ),
            'vatRate' => Settings::vatRate(),
        ];
    }

    /**
     * @return array{order:array, items:array<int,array>}
     */
    private function validatePayload(Request $request): array
    {
        $v = new Validator($request->all());
        $v->require('supplier_id', 'Supplier')
          ->date('order_date', 'Order date', true)
          ->in('vat_mode', ['exclusive', 'inclusive', 'exempt'], 'VAT treatment')
          ->maxLen('reference', 120, 'Supplier reference');

        $supplierId = $request->int('supplier_id', 0);

        if ($supplierId > 0) {
            $exists = (int) Database::scalar(
                'SELECT COUNT(*) FROM suppliers WHERE id = :id', ['id' => $supplierId], 0
            );
            if ($exists === 0) {
                $v->custom('supplier_id', false, 'That supplier does not exist.');
            }
        }

        $items    = [];
        $subtotal = 0.0;

        foreach ((array) $request->input('items', []) as $i => $row) {
            $description = trim((string) ($row['description'] ?? ''));
            $quantity    = (float) ($row['quantity'] ?? 0);
            $unitCost    = (float) ($row['unit_cost'] ?? 0);

            if ($description === '' && $quantity <= 0) {
                continue;   // an empty row the operator left behind
            }

            if ($description === '') {
                $v->custom('items', false, 'Every line needs a description.');
                continue;
            }

            if ($quantity <= 0) {
                $v->custom('items', false, $description . ': the quantity must be more than zero.');
                continue;
            }

            $refId = isset($row['ref_id']) && $row['ref_id'] !== '' ? (int) $row['ref_id'] : null;
            $type  = $refId !== null ? 'inventory' : 'custom';
            $total = round($quantity * $unitCost, 2);
            $subtotal += $total;

            $items[] = [
                'item_type'   => $type,
                'ref_id'      => $refId,
                'description' => mb_substr($description, 0, 500),
                'quantity'    => $quantity,
                'unit'        => trim((string) ($row['unit'] ?? '')) ?: null,
                'unit_cost'   => $unitCost,
                'line_total'  => $total,
                'sort_order'  => (int) $i,
            ];
        }

        if ($items === []) {
            $v->custom('items', false, 'Add at least one line to the order.');
        }

        if ($v->fails()) {
            $v->redirectBack('/purchase-orders');
        }

        $vatMode = (string) $request->input('vat_mode', 'exclusive');
        $vatRate = $vatMode === 'exempt' ? 0.0 : Settings::vatRate();

        // Inclusive means the prices already carry VAT, so it is extracted
        // rather than added — otherwise the order would be taxed twice.
        if ($vatMode === 'inclusive') {
            $net   = round($subtotal / (1 + ($vatRate / 100)), 2);
            $vat   = round($subtotal - $net, 2);
            $total = $subtotal;
            $subtotal = $net;
        } else {
            $vat   = round($subtotal * ($vatRate / 100), 2);
            $total = round($subtotal + $vat, 2);
        }

        $status = (string) $request->input('status', 'draft');

        return [
            'order' => [
                'supplier_id'   => $supplierId,
                'status'        => in_array($status, ['draft', 'ordered'], true) ? $status : 'draft',
                'order_date'    => (string) $request->input('order_date'),
                'expected_date' => $request->input('expected_date') ?: null,
                'vat_mode'      => $vatMode,
                'vat_rate'      => $vatRate,
                'subtotal'      => $subtotal,
                'vat_amount'    => $vat,
                'total'         => $total,
                'reference'     => trim((string) $request->input('reference')) ?: null,
                'notes'         => trim((string) $request->input('notes')) ?: null,
            ],
            'items' => $items,
        ];
    }

    private function saveItems(int $orderId, array $items): void
    {
        foreach ($items as $item) {
            Database::insert('purchase_order_items', $item + ['purchase_order_id' => $orderId]);
        }
    }

    private function items(int $orderId): array
    {
        return Database::all(
            'SELECT * FROM purchase_order_items WHERE purchase_order_id = :id ORDER BY sort_order, id',
            ['id' => $orderId]
        );
    }

    private function findOrFail(int $id): array
    {
        $order = Database::first(
            'SELECT p.*, s.name AS supplier_name, s.email AS supplier_email,
                    s.phone AS supplier_phone, s.payment_terms, u.name AS created_by_name
               FROM purchase_orders p
               JOIN suppliers s ON s.id = p.supplier_id
          LEFT JOIN users u ON u.id = p.created_by
              WHERE p.id = :id',
            ['id' => $id]
        );

        if (!$order) {
            throw new HttpException(404, 'That purchase order does not exist.');
        }

        return $order;
    }
}
