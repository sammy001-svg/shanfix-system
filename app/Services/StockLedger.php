<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;

/**
 * The one place stock moves because of a sale.
 *
 * Mirrors PaymentPoster: every consumption and every reversal goes
 * through here, so an item's quantity can never disagree with its
 * movement ledger.
 *
 * Only invoices move stock. A quotation is an offer, not a sale, and a
 * receipt is a copy of an invoice already posted — counting either would
 * take the same goods out of the store twice.
 */
class StockLedger
{
    /**
     * Post an invoice's stock consumption.
     *
     * Idempotent: an invoice already posted is left alone, so a status
     * change that fires twice cannot take the goods out twice.
     *
     * @return array{posted:bool, moved:int, warnings:array<int,string>}
     */
    public static function postForDocument(int $documentId): array
    {
        $doc = self::invoice($documentId);

        if ($doc === null || $doc['stock_posted_at'] !== null) {
            return ['posted' => false, 'moved' => 0, 'warnings' => []];
        }

        $lines = self::stockLines($documentId);

        // Nothing stockable on this invoice: mark it posted anyway, so it is
        // not re-examined every time the status changes.
        if ($lines === []) {
            Database::update('documents', ['stock_posted_at' => date('Y-m-d H:i:s')], ['id' => $documentId]);
            return ['posted' => true, 'moved' => 0, 'warnings' => []];
        }

        $warnings = [];
        $moved    = 0;

        Database::transaction(static function () use ($doc, $lines, &$warnings, &$moved) {
            foreach ($lines as $line) {
                $item = Database::first(
                    'SELECT id, name, sku, quantity, cost_price, reorder_level
                       FROM inventory_items WHERE id = :id FOR UPDATE',
                    ['id' => $line['ref_id']]
                );

                if (!$item) {
                    // The item was deleted after the line was written. Say so
                    // rather than silently selling something untracked.
                    $warnings[] = $line['description'] . ' is no longer in the inventory, so no stock was moved for it.';
                    continue;
                }

                $qty   = (float) $line['quantity'];
                $after = round((float) $item['quantity'] - $qty, 4);

                self::move((int) $item['id'], 'out', $qty, $after, 'invoice', (int) $doc['id'],
                    $doc['doc_number'] . ' — ' . $line['description']);

                // The cost that applied when it was sold, kept on the line so
                // margin worked out next year uses this year's cost.
                Database::update(
                    'document_items',
                    ['unit_cost' => $item['cost_price']],
                    ['id' => $line['id']]
                );

                $moved++;

                if ($after < 0) {
                    $warnings[] = $item['name'] . ' is now at ' . rtrim(rtrim(number_format($after, 2), '0'), '.')
                        . ' — the count was lower than what was sold.';
                } elseif ((float) $item['reorder_level'] > 0 && $after <= (float) $item['reorder_level']) {
                    $warnings[] = $item['name'] . ' is down to ' . rtrim(rtrim(number_format($after, 2), '0'), '.')
                        . ' and has reached its reorder level.';
                }
            }

            Database::update('documents', ['stock_posted_at' => date('Y-m-d H:i:s')], ['id' => $doc['id']]);
        });

        return ['posted' => true, 'moved' => $moved, 'warnings' => $warnings];
    }

    /**
     * Put back everything an invoice took out.
     *
     * Used when an invoice is cancelled or deleted, and as the first half
     * of a repost when its lines change.
     */
    public static function reverseForDocument(int $documentId): int
    {
        $doc = self::invoice($documentId);

        if ($doc === null || $doc['stock_posted_at'] === null) {
            return 0;
        }

        // Reverse what was actually taken, read back from the ledger rather
        // than recomputed from the lines — the lines may since have changed,
        // and the ledger is the record of what really left the store.
        $taken = Database::all(
            "SELECT item_id, SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE -quantity END) AS net
               FROM inventory_movements
              WHERE reference_type = 'invoice' AND reference_id = :id
           GROUP BY item_id
             HAVING net <> 0",
            ['id' => $documentId]
        );

        $restored = 0;

        Database::transaction(static function () use ($taken, $doc, $documentId, &$restored) {
            foreach ($taken as $row) {
                $item = Database::first(
                    'SELECT id, quantity FROM inventory_items WHERE id = :id FOR UPDATE',
                    ['id' => $row['item_id']]
                );

                if (!$item) {
                    continue;
                }

                $qty   = (float) $row['net'];
                $after = round((float) $item['quantity'] + $qty, 4);

                self::move((int) $item['id'], 'in', $qty, $after, 'invoice', $documentId,
                    'Reversed ' . $doc['doc_number']);

                $restored++;
            }

            Database::update('documents', ['stock_posted_at' => null], ['id' => $documentId]);
        });

        return $restored;
    }

    /**
     * Re-apply after an issued invoice's lines were edited.
     *
     * Reverse-then-post rather than working out deltas: it is fewer moving
     * parts, and the ledger reads honestly as "this came back, then this
     * went out again".
     *
     * @return array{posted:bool, moved:int, warnings:array<int,string>}
     */
    public static function repostForDocument(int $documentId): array
    {
        self::reverseForDocument($documentId);

        return self::postForDocument($documentId);
    }

    // -- Goods coming in ---------------------------------------------------

    /**
     * Receive quantities against a purchase order.
     *
     * @param array<int,float> $quantities purchase_order_items.id => quantity
     *                                     arriving in this delivery
     *
     * @return array{received:int, warnings:array<int,string>}
     */
    public static function receivePurchase(int $purchaseOrderId, array $quantities): array
    {
        $po = Database::first(
            'SELECT id, po_number, status FROM purchase_orders WHERE id = :id',
            ['id' => $purchaseOrderId]
        );

        if (!$po || in_array($po['status'], ['draft', 'cancelled', 'received'], true)) {
            return ['received' => 0, 'warnings' => []];
        }

        $warnings = [];
        $received = 0;

        Database::transaction(static function () use ($po, $quantities, &$warnings, &$received) {
            foreach ($quantities as $lineId => $qty) {
                $qty = round((float) $qty, 4);

                if ($qty <= 0) {
                    continue;
                }

                $line = Database::first(
                    'SELECT * FROM purchase_order_items
                      WHERE id = :id AND purchase_order_id = :po',
                    ['id' => (int) $lineId, 'po' => $po['id']]
                );

                if (!$line) {
                    continue;
                }

                // Never accept more than was ordered on the line — a supplier
                // over-delivering is a conversation, not a silent stock gain.
                $outstanding = round((float) $line['quantity'] - (float) $line['quantity_received'], 4);

                if ($outstanding <= 0) {
                    $warnings[] = $line['description'] . ' was already fully received.';
                    continue;
                }

                if ($qty > $outstanding) {
                    $warnings[] = $line['description'] . ': only ' . self::trim($outstanding)
                        . ' was still outstanding, so that is what was received.';
                    $qty = $outstanding;
                }

                Database::update(
                    'purchase_order_items',
                    ['quantity_received' => round((float) $line['quantity_received'] + $qty, 4)],
                    ['id' => $line['id']]
                );

                $received++;

                // Only stock lines move stock; a delivery charge on the order
                // is a cost, not something that sits on a shelf.
                if ($line['item_type'] !== 'inventory' || !$line['ref_id']) {
                    continue;
                }

                $item = Database::first(
                    'SELECT id, name, quantity, cost_price FROM inventory_items
                      WHERE id = :id FOR UPDATE',
                    ['id' => $line['ref_id']]
                );

                if (!$item) {
                    $warnings[] = $line['description'] . ' is no longer in the inventory, so no stock was added.';
                    continue;
                }

                $after = round((float) $item['quantity'] + $qty, 4);

                self::move((int) $item['id'], 'in', $qty, $after, 'purchase', (int) $po['id'],
                    $po['po_number'] . ' — ' . $line['description']);

                Database::update(
                    'inventory_items',
                    ['cost_price' => self::weightedCost(
                        (float) $item['quantity'],
                        (float) $item['cost_price'],
                        $qty,
                        (float) $line['unit_cost']
                    )],
                    ['id' => $item['id']]
                );
            }

            self::refreshPurchaseStatus((int) $po['id']);
        });

        return ['received' => $received, 'warnings' => $warnings];
    }

    /**
     * Blend the price we already hold stock at with the price just paid.
     *
     * Weighted average, so a price rise moves the cost gradually in
     * proportion to how much of each we hold — rather than the last
     * delivery rewriting the value of everything already on the shelf.
     *
     * Falls back to the new price when there is nothing meaningful to
     * average against: no stock, or a negative balance from overselling,
     * where an average would be arithmetic nonsense.
     */
    public static function weightedCost(
        float $heldQty,
        float $heldCost,
        float $incomingQty,
        float $incomingCost
    ): float {
        if ($incomingQty <= 0) {
            return round($heldCost, 2);
        }

        if ($heldQty <= 0 || $heldCost <= 0) {
            return round($incomingCost, 2);
        }

        $value = ($heldQty * $heldCost) + ($incomingQty * $incomingCost);

        return round($value / ($heldQty + $incomingQty), 2);
    }

    /** Move the order to partial or received, based on its lines. */
    private static function refreshPurchaseStatus(int $purchaseOrderId): void
    {
        $totals = Database::first(
            'SELECT COALESCE(SUM(quantity), 0) AS ordered,
                    COALESCE(SUM(quantity_received), 0) AS received
               FROM purchase_order_items WHERE purchase_order_id = :id',
            ['id' => $purchaseOrderId]
        );

        $ordered  = (float) ($totals['ordered'] ?? 0);
        $received = (float) ($totals['received'] ?? 0);

        if ($received <= 0) {
            return;
        }

        $complete = $received >= round($ordered, 4) - 0.0001;

        Database::update('purchase_orders', [
            'status'      => $complete ? 'received' : 'partial',
            'received_at' => $complete ? date('Y-m-d H:i:s') : null,
        ], ['id' => $purchaseOrderId]);
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    // -- internals -------------------------------------------------------

    /**
     * The document, but only if it is an invoice worth posting.
     *
     * Draft invoices have not been issued, cancelled ones have been
     * withdrawn, and quotations and receipts never move stock at all.
     */
    private static function invoice(int $documentId): ?array
    {
        $doc = Database::first(
            "SELECT id, doc_number, doc_type, status, stock_posted_at
               FROM documents WHERE id = :id",
            ['id' => $documentId]
        );

        if (!$doc || $doc['doc_type'] !== 'invoice') {
            return null;
        }

        return $doc;
    }

    /** Lines on this document that refer to a real inventory item. */
    private static function stockLines(int $documentId): array
    {
        return Database::all(
            "SELECT id, ref_id, description, quantity
               FROM document_items
              WHERE document_id = :id AND item_type = 'inventory'
                AND ref_id IS NOT NULL AND quantity > 0
           ORDER BY sort_order, id",
            ['id' => $documentId]
        );
    }

    /** Write one movement and bring the item's quantity with it. */
    private static function move(
        int $itemId,
        string $type,
        float $quantity,
        float $balanceAfter,
        string $referenceType,
        int $referenceId,
        string $note
    ): void {
        Database::insert('inventory_movements', [
            'item_id'        => $itemId,
            'movement_type'  => $type,
            'quantity'       => abs($quantity),
            'balance_after'  => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'note'           => mb_substr($note, 0, 255),
            'user_id'        => Auth::id(),
        ]);

        Database::update('inventory_items', ['quantity' => $balanceAfter], ['id' => $itemId]);
    }

    /**
     * Post without ever letting a stock problem break the sale.
     *
     * An invoice being issued is the important event; the stock movement
     * is bookkeeping that follows it. If this fails we log it and let the
     * invoice stand rather than blocking the operator.
     *
     * @return array<int,string> warnings to show the operator
     */
    public static function postQuietly(int $documentId): array
    {
        try {
            return self::postForDocument($documentId)['warnings'];
        } catch (\Throwable $e) {
            Logger::error('Stock posting failed: ' . $e->getMessage(), ['document' => $documentId]);
            return ['Stock could not be updated for this invoice — check the inventory ledger.'];
        }
    }

    /** The mirror of postQuietly, for cancellations and deletions. */
    public static function reverseQuietly(int $documentId): void
    {
        try {
            self::reverseForDocument($documentId);
        } catch (\Throwable $e) {
            Logger::error('Stock reversal failed: ' . $e->getMessage(), ['document' => $documentId]);
        }
    }
}
