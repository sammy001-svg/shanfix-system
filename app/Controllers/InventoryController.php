<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\ImageLibrary;

class InventoryController extends Controller
{
    private const UNITS = ['pcs', 'box', 'pack', 'set', 'sqft', 'sqm', 'metre', 'roll', 'ream', 'litre', 'kg', 'hour'];

    public function index(Request $request): void
    {
        $search   = (string) $request->query('q', '');
        $category = (int) $request->query('category', 0);
        $stock    = (string) $request->query('stock', '');
        $status   = (string) $request->query('status', '');

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(i.name LIKE :s OR i.sku LIKE :s2 OR i.description LIKE :s3)';
            $params['s'] = $params['s2'] = $params['s3'] = '%' . $search . '%';
        }

        if ($category > 0) {
            $where[] = 'i.category_id = :cat';
            $params['cat'] = $category;
        }

        if ($status === 'active')   { $where[] = 'i.is_active = 1'; }
        if ($status === 'inactive') { $where[] = 'i.is_active = 0'; }

        if ($stock === 'low')  { $where[] = 'i.quantity <= i.reorder_level AND i.quantity > 0'; }
        if ($stock === 'out')  { $where[] = 'i.quantity <= 0'; }
        if ($stock === 'in')   { $where[] = 'i.quantity > i.reorder_level'; }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM inventory_items i WHERE {$clause}", $params, 0);
        $pager = $this->paginate($total, 25);

        // The main photo comes along in the same query — a per-row lookup
        // would be 25 extra queries on every page of the list.
        $items = Database::all(
            "SELECT i.*, c.name AS category_name,
                    img.thumb_path, img.file_path AS image_path,
                    (SELECT COUNT(*) FROM inventory_images WHERE item_id = i.id) AS image_count
               FROM inventory_items i
          LEFT JOIN categories c ON c.id = i.category_id
          LEFT JOIN inventory_images img ON img.id = (
                    SELECT id FROM inventory_images
                     WHERE item_id = i.id
                  ORDER BY is_primary DESC, sort_order, id
                     LIMIT 1)
              WHERE {$clause}
           ORDER BY i.name ASC
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $summary = Database::first(
            'SELECT COUNT(*) AS total_items,
                    COALESCE(SUM(quantity * cost_price), 0) AS stock_value,
                    COALESCE(SUM(quantity * selling_price), 0) AS retail_value,
                    SUM(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 ELSE 0 END) AS low_stock,
                    SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock
               FROM inventory_items
              WHERE is_active = 1'
        );

        $this->view('inventory/index', [
            'title'      => 'Inventory',
            'items'      => $items,
            'pager'      => $pager,
            'summary'    => $summary,
            'categories' => $this->categories(),
            'filters'    => compact('search', 'category', 'stock', 'status'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('inventory.manage');

        $this->view('inventory/form', [
            'title'      => 'New Inventory Item',
            'item'       => null,
            'categories' => $this->categories(),
            'units'      => self::UNITS,
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('inventory.manage');

        $data = $this->validated($request, null);

        $id = Database::transaction(function () use ($data, $request) {
            $id = Database::insert('inventory_items', $data);

            // Opening stock is recorded as a movement so the ledger balances.
            if ($data['quantity'] > 0) {
                Database::insert('inventory_movements', [
                    'item_id'        => $id,
                    'movement_type'  => 'in',
                    'quantity'       => $data['quantity'],
                    'balance_after'  => $data['quantity'],
                    'reference_type' => 'manual',
                    'note'           => 'Opening stock',
                    'user_id'        => Auth::id(),
                ]);
            }

            return $id;
        });

        // Photos are optional, and a failure to store one must not lose the
        // item the user just typed in — so it is reported, not thrown.
        $photos = $this->storeImages($id, $request);

        ActivityLog::record('inventory_created', 'inventory_item', $id, 'Added item ' . $data['name']);

        Session::success(
            '"' . $data['name'] . '" has been added to inventory.'
            . ($photos['saved'] > 0 ? ' ' . $photos['saved'] . ' photo(s) uploaded.' : '')
        );

        foreach ($photos['errors'] as $error) {
            Session::warning($error);
        }

        Response::to('/inventory/' . $id);
    }

    public function show(Request $request): void
    {
        $item = $this->findOrFail($request->paramInt('id'));

        $movements = Database::all(
            'SELECT m.*, u.name AS user_name
               FROM inventory_movements m
          LEFT JOIN users u ON u.id = m.user_id
              WHERE m.item_id = :id
           ORDER BY m.created_at DESC, m.id DESC
              LIMIT 60',
            ['id' => $item['id']]
        );

        // Where this item has been sold.
        $recentSales = Database::all(
            "SELECT d.id, d.doc_number, d.doc_type, d.issue_date, d.status,
                    c.name AS client_name, di.quantity, di.line_total
               FROM document_items di
               JOIN documents d ON d.id = di.document_id
               JOIN clients c ON c.id = d.client_id
              WHERE di.item_type = 'inventory' AND di.ref_id = :id
                AND d.doc_type = 'invoice'
           ORDER BY d.issue_date DESC
              LIMIT 15",
            ['id' => $item['id']]
        );

        $this->view('inventory/show', [
            'title'       => $item['name'],
            'item'        => $item,
            'movements'   => $movements,
            'recentSales' => $recentSales,
            'images'      => $this->imagesFor((int) $item['id']),
        ]);
    }

    public function edit(Request $request): void
    {
        $this->authorize('inventory.manage');

        $item = $this->findOrFail($request->paramInt('id'));

        $this->view('inventory/form', [
            'title'      => 'Edit ' . $item['name'],
            'item'       => $item,
            'categories' => $this->categories(),
            'units'      => self::UNITS,
            'images'     => $this->imagesFor((int) $item['id']),
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('inventory.manage');

        $item = $this->findOrFail($request->paramInt('id'));
        $data = $this->validated($request, (int) $item['id']);

        // Stock level is adjusted through the stock-movement form, never here,
        // so an edit cannot silently rewrite the ledger.
        unset($data['quantity']);

        Database::update('inventory_items', $data, ['id' => $item['id']]);

        $photos = $this->storeImages((int) $item['id'], $request);

        foreach ($photos['errors'] as $error) {
            Session::warning($error);
        }

        ActivityLog::record('inventory_updated', 'inventory_item', (int) $item['id'], 'Updated item ' . $data['name']);
        Session::success('Item updated.');
        Response::to('/inventory/' . $item['id']);
    }

    public function adjustStock(Request $request): void
    {
        $this->authorize('inventory.manage');

        $item = $this->findOrFail($request->paramInt('id'));

        $type     = (string) $request->input('movement_type', 'in');
        $quantity = $request->decimal('quantity');
        $note     = (string) $request->input('note', '');

        $v = new Validator($request->all());
        $v->in('movement_type', ['in', 'out', 'adjustment'], 'Movement type')
          ->numeric('quantity', 'Quantity', true)
          ->maxLen('note', 255, 'Note');

        if ($type !== 'adjustment') {
            $v->min('quantity', 0.01, 'Quantity');
        }

        if ($v->fails()) {
            $v->redirectBack('/inventory/' . $item['id']);
        }

        $current = (float) $item['quantity'];

        $newBalance = match ($type) {
            'in'         => $current + $quantity,
            'out'        => $current - $quantity,
            'adjustment' => $quantity,   // absolute stock-take figure
        };

        if ($newBalance < 0) {
            Session::error(
                'That would take stock below zero. Current balance is ' . qty($current) . ' ' . $item['unit'] . '.'
            );
            Response::to('/inventory/' . $item['id']);
        }

        Database::transaction(function () use ($item, $type, $quantity, $newBalance, $note, $current) {
            Database::update('inventory_items', ['quantity' => $newBalance], ['id' => $item['id']]);

            Database::insert('inventory_movements', [
                'item_id'        => $item['id'],
                'movement_type'  => $type,
                // For a stock-take, log the delta so the ledger stays additive.
                'quantity'       => $type === 'adjustment' ? ($newBalance - $current) : $quantity,
                'balance_after'  => $newBalance,
                'reference_type' => 'manual',
                'note'           => $note !== '' ? $note : null,
                'user_id'        => Auth::id(),
            ]);
        });

        ActivityLog::record(
            'stock_adjusted',
            'inventory_item',
            (int) $item['id'],
            sprintf('%s: %s %s → balance %s', $item['name'], $type, qty($quantity), qty($newBalance))
        );

        Session::success('Stock updated. New balance: ' . qty($newBalance) . ' ' . $item['unit'] . '.');
        Response::to('/inventory/' . $item['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('inventory.manage');

        $item = $this->findOrFail($request->paramInt('id'));

        // Items that appear on documents are deactivated, not deleted, so
        // historic quotations and invoices keep their references intact.
        $used = (int) Database::scalar(
            "SELECT COUNT(*) FROM document_items WHERE item_type = 'inventory' AND ref_id = :id",
            ['id' => $item['id']],
            0
        );

        if ($used > 0) {
            Database::update('inventory_items', ['is_active' => 0], ['id' => $item['id']]);
            ActivityLog::record('inventory_deactivated', 'inventory_item', (int) $item['id'], 'Deactivated ' . $item['name']);
            Session::warning('"' . $item['name'] . '" appears on ' . $used . ' document(s), so it was deactivated instead of deleted.');
            Response::to('/inventory');
        }

        // The rows go with the item via ON DELETE CASCADE, but the files on
        // disk would be orphaned, so they are removed first.
        foreach ($this->imagesFor((int) $item['id']) as $image) {
            $this->deleteUpload($image['file_path']);
            $this->deleteUpload($image['thumb_path']);
        }

        Database::delete('inventory_items', ['id' => $item['id']]);
        ActivityLog::record('inventory_deleted', 'inventory_item', (int) $item['id'], 'Deleted ' . $item['name']);
        Session::success('"' . $item['name'] . '" has been deleted.');
        Response::to('/inventory');
    }

    public function export(Request $request): void
    {
        $this->authorize('inventory.view');

        $rows = Database::all(
            'SELECT i.sku, i.name, c.name AS category, i.unit, i.cost_price, i.selling_price,
                    i.quantity, i.reorder_level, i.is_active
               FROM inventory_items i
          LEFT JOIN categories c ON c.id = i.category_id
           ORDER BY i.name'
        );

        $out = array_map(static fn($r) => [
            $r['sku'],
            $r['name'],
            $r['category'] ?? '',
            $r['unit'],
            $r['cost_price'],
            $r['selling_price'],
            $r['quantity'],
            $r['reorder_level'],
            $r['is_active'] ? 'Active' : 'Inactive',
        ], $rows);

        ActivityLog::record('inventory_exported', 'inventory_item', null, 'Exported inventory CSV');

        Response::csv(
            'shanfix-inventory-' . date('Y-m-d') . '.csv',
            ['SKU', 'Name', 'Category', 'Unit', 'Cost Price', 'Selling Price', 'Quantity', 'Reorder Level', 'Status'],
            $out
        );
    }

    // -- Product images ------------------------------------------------

    /** Add photos to an item that already exists. */
    public function uploadImages(Request $request): void
    {
        $this->authorize('inventory.manage');

        $item   = $this->findOrFail($request->paramInt('id'));
        $photos = $this->storeImages((int) $item['id'], $request);

        if ($photos['saved'] > 0) {
            ActivityLog::record(
                'inventory_images_added',
                'inventory_item',
                (int) $item['id'],
                $photos['saved'] . ' photo(s) added to ' . $item['name']
            );

            Session::success($photos['saved'] . ' photo(s) added.');
        } elseif ($photos['errors'] === []) {
            Session::warning('No photos were selected.');
        }

        foreach ($photos['errors'] as $error) {
            Session::error($error);
        }

        Response::to('/inventory/' . $item['id']);
    }

    public function deleteImage(Request $request): void
    {
        $this->authorize('inventory.manage');

        $image = Database::first(
            'SELECT i.*, it.name AS item_name FROM inventory_images i
               JOIN inventory_items it ON it.id = i.item_id
              WHERE i.id = :id',
            ['id' => $request->paramInt('imageId')]
        );

        if (!$image) {
            throw new HttpException(404, 'That image does not exist.');
        }

        Database::transaction(function () use ($image) {
            $this->deleteUpload($image['file_path']);
            $this->deleteUpload($image['thumb_path']);

            Database::delete('inventory_images', ['id' => $image['id']]);

            // The gallery must never be left without a primary.
            if ((int) $image['is_primary'] === 1) {
                $next = Database::first(
                    'SELECT id FROM inventory_images WHERE item_id = :id ORDER BY sort_order, id LIMIT 1',
                    ['id' => $image['item_id']]
                );

                if ($next) {
                    Database::update('inventory_images', ['is_primary' => 1], ['id' => $next['id']]);
                }
            }
        });

        ActivityLog::record(
            'inventory_image_deleted',
            'inventory_item',
            (int) $image['item_id'],
            'Removed a photo from ' . $image['item_name']
        );

        Session::success('Photo removed.');
        Response::back('/inventory/' . $image['item_id']);
    }

    /** Choose which photo represents the item in lists and on documents. */
    public function setPrimaryImage(Request $request): void
    {
        $this->authorize('inventory.manage');

        $image = Database::first(
            'SELECT * FROM inventory_images WHERE id = :id',
            ['id' => $request->paramInt('imageId')]
        );

        if (!$image) {
            throw new HttpException(404, 'That image does not exist.');
        }

        Database::transaction(function () use ($image) {
            Database::run(
                'UPDATE inventory_images SET is_primary = 0 WHERE item_id = :id',
                ['id' => $image['item_id']]
            );

            Database::update('inventory_images', ['is_primary' => 1], ['id' => $image['id']]);
        });

        Session::success('Main photo updated.');
        Response::back('/inventory/' . $image['item_id']);
    }

    /**
     * Validate, resize and store whatever came in on the "images" field.
     *
     * Returns counts rather than throwing, so one bad photo cannot discard
     * a whole form submission.
     *
     * @return array{saved:int, errors:array<int,string>}
     */
    /**
     * Photos for this item.
     *
     * The work itself lives in ImageLibrary, shared with services. It is
     * the code that decides whether an uploaded file reaches the disk, and
     * a second copy of it would mean any flaw had to be found twice.
     *
     * @return array{saved:int, errors:array<int,string>}
     */
    private function storeImages(int $itemId, Request $request): array
    {
        return ImageLibrary::store('product', $itemId);
    }

    /** @return array<int,array<string,mixed>> */
    private function imagesFor(int $itemId): array
    {
        return ImageLibrary::all('product', $itemId);
    }

    // -- Internals -----------------------------------------------------

    private function validated(Request $request, ?int $ignoreId): array
    {
        $v = new Validator($request->all());
        $v->require('sku', 'SKU')
          ->maxLen('sku', 60, 'SKU')
          ->unique('sku', 'inventory_items', 'sku', 'SKU', $ignoreId)
          ->require('name', 'Item name')
          ->maxLen('name', 180, 'Item name')
          ->in('unit', self::UNITS, 'Unit')
          ->numeric('cost_price', 'Cost price')
          ->min('cost_price', 0, 'Cost price')
          ->numeric('selling_price', 'Selling price', true)
          ->min('selling_price', 0, 'Selling price')
          ->numeric('quantity', 'Quantity')
          ->min('quantity', 0, 'Quantity')
          ->numeric('reorder_level', 'Reorder level')
          ->min('reorder_level', 0, 'Reorder level');

        if ($request->input('category_id')) {
            $v->exists('category_id', 'categories', 'category');
        }

        if ($v->fails()) {
            $v->redirectBack($ignoreId ? "/inventory/{$ignoreId}/edit" : '/inventory/create');
        }

        return [
            'sku'           => strtoupper((string) $request->input('sku')),
            'name'          => (string) $request->input('name'),
            'category_id'   => $request->int('category_id') ?: null,
            'description'   => $request->input('description') ?: null,
            'unit'          => (string) $request->input('unit', 'pcs'),
            'cost_price'    => $request->decimal('cost_price'),
            'selling_price' => $request->decimal('selling_price'),
            'quantity'      => $request->decimal('quantity'),
            'reorder_level' => $request->decimal('reorder_level'),
            'is_active'     => $request->bool('is_active') ? 1 : 0,
        ];
    }

    private function findOrFail(int $id): array
    {
        $item = Database::first(
            'SELECT i.*, c.name AS category_name
               FROM inventory_items i
          LEFT JOIN categories c ON c.id = i.category_id
              WHERE i.id = :id',
            ['id' => $id]
        );

        if (!$item) {
            throw new HttpException(404, 'That inventory item does not exist.');
        }

        return $item;
    }

    private function categories(): array
    {
        return Database::all("SELECT id, name FROM categories WHERE type = 'inventory' ORDER BY name");
    }
}
