-- =====================================================================
-- Migration 014 — Suppliers and purchase orders
--
-- The other half of inventory. Until now stock only ever arrived through
-- a manual adjustment and cost_price was whatever somebody typed, which
-- meant every margin figure rested on a guess.
--
-- Receiving goods against a purchase order now adds stock at a real
-- cost, and maintains a weighted average cost price as prices move —
-- so the cost captured on an invoice line is one we can stand behind.
--
-- Numbered 014 to sit after the 013 already in the tree. Note that
-- migrations run in filename order, not by this number.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_code  VARCHAR(30)  NOT NULL,
  name           VARCHAR(180) NOT NULL,
  contact_person VARCHAR(140) DEFAULT NULL,
  email          VARCHAR(160) DEFAULT NULL,
  phone          VARCHAR(30)  DEFAULT NULL,
  kra_pin        VARCHAR(30)  DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  city           VARCHAR(80)  DEFAULT NULL,
  -- Days from invoice to payment, for ageing what we owe.
  payment_terms  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  notes          TEXT         DEFAULT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by     INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_code (supplier_code),
  KEY idx_supplier_name (name),
  KEY idx_supplier_status (status),
  CONSTRAINT fk_supplier_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- A purchase order. Goods can arrive in more than one delivery, so the
-- status distinguishes a part delivery from a complete one.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_number     VARCHAR(40)  NOT NULL,
  supplier_id   INT UNSIGNED NOT NULL,
  status        ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  order_date    DATE NOT NULL,
  expected_date DATE DEFAULT NULL,
  currency      VARCHAR(5)   NOT NULL DEFAULT 'KES',
  vat_mode      ENUM('exclusive','inclusive','exempt') NOT NULL DEFAULT 'exclusive',
  vat_rate      DECIMAL(6,3)  NOT NULL DEFAULT 16.000,
  subtotal      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  vat_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reference     VARCHAR(120) DEFAULT NULL,   -- supplier invoice or delivery note
  notes         TEXT         DEFAULT NULL,
  received_at   DATETIME     DEFAULT NULL,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_number (po_number),
  KEY idx_po_supplier (supplier_id),
  KEY idx_po_status (status),
  KEY idx_po_date (order_date),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_creator  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- quantity_received is what makes part deliveries work: each receipt
-- moves it towards quantity, and stock only ever moves by the difference.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT UNSIGNED NOT NULL,
  item_type         ENUM('inventory','custom') NOT NULL DEFAULT 'inventory',
  ref_id            INT UNSIGNED DEFAULT NULL,   -- inventory_items.id
  description       VARCHAR(500) NOT NULL,
  quantity          DECIMAL(14,2) NOT NULL DEFAULT 1.00,
  quantity_received DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  unit              VARCHAR(30)  DEFAULT NULL,
  unit_cost         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  line_total        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sort_order        INT NOT NULL DEFAULT 0,
  KEY idx_poitems_po (purchase_order_id),
  KEY idx_poitems_ref (ref_id),
  CONSTRAINT fk_poitems_po FOREIGN KEY (purchase_order_id)
    REFERENCES purchase_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Number prefixes, alongside the existing document ones.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('supplier_prefix',       'SUP'),
  ('purchase_order_prefix', 'PO')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
