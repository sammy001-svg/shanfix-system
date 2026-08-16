-- =====================================================================
-- Migration 010 — Stock moves when you sell
--
-- inventory_movements has always carried a reference_type commented
-- "'invoice','purchase','manual'", but only 'manual' was ever written:
-- nothing outside the inventory screen touched stock. Selling 500 pens
-- on an invoice left the pen count untouched, so stock drifted from
-- reality on day one and reorder alerts fired on fiction.
--
-- Two columns close it:
--
--   documents.stock_posted_at   whether this invoice has already moved
--                               stock, so it can never be counted twice
--   document_items.unit_cost    what the item cost us at the moment of
--                               sale, not what it costs today — margin
--                               worked out later has to use the price
--                               that applied then
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'documents' AND column_name = 'stock_posted_at');

SET @sql := IF(@col = 0,
  'ALTER TABLE documents ADD COLUMN stock_posted_at DATETIME DEFAULT NULL AFTER sent_at',
  'SELECT "documents.stock_posted_at already present"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col2 := (SELECT COUNT(*) FROM information_schema.columns
               WHERE table_schema = DATABASE()
                 AND table_name = 'document_items' AND column_name = 'unit_cost');

SET @sql2 := IF(@col2 = 0,
  'ALTER TABLE document_items ADD COLUMN unit_cost DECIMAL(14,2) DEFAULT NULL AFTER unit_price',
  'SELECT "document_items.unit_cost already present"');

PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Invoices raised before this migration were never posted to stock, and
-- back-dating them now would double-count against whatever manual
-- adjustments have been keeping the counts honest. They stay unposted;
-- only invoices issued from here on move stock.
-- ---------------------------------------------------------------------
