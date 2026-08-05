-- =====================================================================
-- Shanfix Technology - Business Management System
-- MySQL 5.7+ / MariaDB 10.3+ schema
-- Charset utf8mb4 throughout (emoji-safe chat, correct KES symbols)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Users & access control
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(160)  NOT NULL,
  phone         VARCHAR(30)   DEFAULT NULL,
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('admin','manager','sales','finance','staff') NOT NULL DEFAULT 'staff',
  job_title     VARCHAR(120)  DEFAULT NULL,
  avatar_color  VARCHAR(7)    NOT NULL DEFAULT '#0D2B4B',
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  last_login_at DATETIME      DEFAULT NULL,
  last_seen_at  DATETIME      DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Application settings (key/value, includes KopoKopo credentials)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
  setting_value TEXT         DEFAULT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Categories: shared lookup for inventory / services / expenses
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  type       ENUM('inventory','service','expense') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_category (name, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Inventory: physical stock for printing & branding
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(60)   NOT NULL,
  name           VARCHAR(180)  NOT NULL,
  category_id    INT UNSIGNED  DEFAULT NULL,
  description    TEXT          DEFAULT NULL,
  unit           VARCHAR(30)   NOT NULL DEFAULT 'pcs',
  cost_price     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  selling_price  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  quantity       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reorder_level  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_items_sku (sku),
  KEY idx_items_category (category_id),
  KEY idx_items_active (is_active),
  CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id        INT UNSIGNED  NOT NULL,
  movement_type  ENUM('in','out','adjustment') NOT NULL,
  quantity       DECIMAL(14,2) NOT NULL,
  balance_after  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reference_type VARCHAR(40)   DEFAULT NULL,   -- 'invoice','purchase','manual'
  reference_id   INT UNSIGNED  DEFAULT NULL,
  note           VARCHAR(255)  DEFAULT NULL,
  user_id        INT UNSIGNED  DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mov_item (item_id),
  KEY idx_mov_created (created_at),
  CONSTRAINT fk_mov_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_mov_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Services: software dev, web dev, design retainers, etc.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(60)  NOT NULL,
  name         VARCHAR(180) NOT NULL,
  category_id  INT UNSIGNED DEFAULT NULL,
  description  TEXT         DEFAULT NULL,
  pricing_type ENUM('fixed','hourly','daily','monthly','project','from') NOT NULL DEFAULT 'fixed',
  price        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  unit_label   VARCHAR(40)  DEFAULT NULL,      -- 'per page', 'per hour'
  lead_time    VARCHAR(80)  DEFAULT NULL,      -- '2-3 weeks'
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_services_code (code),
  KEY idx_services_category (category_id),
  CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Clients
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_code    VARCHAR(30)  NOT NULL,
  client_type    ENUM('individual','company') NOT NULL DEFAULT 'company',
  name           VARCHAR(180) NOT NULL,
  contact_person VARCHAR(140) DEFAULT NULL,
  email          VARCHAR(160) DEFAULT NULL,
  phone          VARCHAR(30)  DEFAULT NULL,
  alt_phone      VARCHAR(30)  DEFAULT NULL,
  kra_pin        VARCHAR(30)  DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  city           VARCHAR(80)  DEFAULT NULL,
  industry       VARCHAR(120) DEFAULT NULL,
  notes          TEXT         DEFAULT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  credit_limit   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  source_lead_id INT UNSIGNED DEFAULT NULL,
  created_by     INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clients_code (client_code),
  KEY idx_clients_name (name),
  KEY idx_clients_status (status),
  CONSTRAINT fk_clients_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Documents: one table drives quotations, invoices and receipts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_type           ENUM('quotation','invoice','receipt') NOT NULL,
  doc_number         VARCHAR(40)  NOT NULL,
  client_id          INT UNSIGNED NOT NULL,
  lead_id            INT UNSIGNED DEFAULT NULL,
  parent_document_id INT UNSIGNED DEFAULT NULL,  -- quote -> invoice -> receipt chain
  title              VARCHAR(200) DEFAULT NULL,
  issue_date         DATE NOT NULL,
  due_date           DATE DEFAULT NULL,
  valid_until        DATE DEFAULT NULL,
  status             ENUM('draft','sent','accepted','rejected','expired',
                          'unpaid','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  currency           VARCHAR(5)   NOT NULL DEFAULT 'KES',
  subtotal           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_type      ENUM('none','percent','amount') NOT NULL DEFAULT 'none',
  discount_value     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  vat_mode           ENUM('exclusive','inclusive','exempt') NOT NULL DEFAULT 'exclusive',
  vat_rate           DECIMAL(6,3)  NOT NULL DEFAULT 16.000,
  vat_amount         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total              DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  amount_paid        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  balance            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  notes              TEXT DEFAULT NULL,
  terms              TEXT DEFAULT NULL,
  sent_at            DATETIME DEFAULT NULL,
  created_by         INT UNSIGNED DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doc_number (doc_number),
  KEY idx_doc_client (client_id),
  KEY idx_doc_type_status (doc_type, status),
  KEY idx_doc_issue (issue_date),
  CONSTRAINT fk_doc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_parent FOREIGN KEY (parent_document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id   INT UNSIGNED NOT NULL,
  item_type     ENUM('inventory','service','custom') NOT NULL DEFAULT 'custom',
  ref_id        INT UNSIGNED DEFAULT NULL,   -- inventory_items.id or services.id
  description   VARCHAR(500) NOT NULL,
  quantity      DECIMAL(14,2) NOT NULL DEFAULT 1.00,
  unit          VARCHAR(30)   DEFAULT NULL,
  unit_price    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  line_total    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sort_order    INT NOT NULL DEFAULT 0,
  KEY idx_ditems_doc (document_id),
  CONSTRAINT fk_ditems_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Payments & KopoKopo STK Push
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_number VARCHAR(40)  NOT NULL,
  document_id    INT UNSIGNED DEFAULT NULL,
  client_id      INT UNSIGNED NOT NULL,
  amount         DECIMAL(14,2) NOT NULL,
  method         ENUM('mpesa_stk','mpesa_manual','bank','cash','cheque','other') NOT NULL DEFAULT 'cash',
  reference      VARCHAR(120) DEFAULT NULL,   -- M-Pesa code / cheque no / slip no
  status         ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'completed',
  paid_at        DATETIME DEFAULT NULL,
  notes          VARCHAR(255) DEFAULT NULL,
  recorded_by    INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_number (payment_number),
  KEY idx_pay_doc (document_id),
  KEY idx_pay_client (client_id),
  KEY idx_pay_status (status),
  CONSTRAINT fk_pay_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stk_requests (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id         INT UNSIGNED DEFAULT NULL,
  document_id        INT UNSIGNED DEFAULT NULL,
  client_id          INT UNSIGNED NOT NULL,
  phone              VARCHAR(20)  NOT NULL,   -- normalised 2547XXXXXXXX
  amount             DECIMAL(14,2) NOT NULL,
  kopokopo_id        VARCHAR(120) DEFAULT NULL,  -- id parsed from Location header
  location_url       VARCHAR(255) DEFAULT NULL,
  status             ENUM('pending','success','failed','cancelled','timeout') NOT NULL DEFAULT 'pending',
  result_code        VARCHAR(20)  DEFAULT NULL,
  result_desc        VARCHAR(255) DEFAULT NULL,
  mpesa_receipt      VARCHAR(60)  DEFAULT NULL,
  request_payload    TEXT DEFAULT NULL,
  response_payload   TEXT DEFAULT NULL,
  callback_payload   TEXT DEFAULT NULL,
  callback_at        DATETIME DEFAULT NULL,
  initiated_by       INT UNSIGNED DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_stk_kopokopo (kopokopo_id),
  KEY idx_stk_status (status),
  KEY idx_stk_doc (document_id),
  CONSTRAINT fk_stk_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_stk_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_stk_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Leads pipeline
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_number         VARCHAR(30)  NOT NULL,
  name                VARCHAR(180) NOT NULL,
  company             VARCHAR(180) DEFAULT NULL,
  email               VARCHAR(160) DEFAULT NULL,
  phone               VARCHAR(30)  DEFAULT NULL,
  source              ENUM('walk_in','referral','website','social_media','phone_call',
                           'email','exhibition','cold_outreach','other') NOT NULL DEFAULT 'other',
  service_id          INT UNSIGNED DEFAULT NULL,
  inventory_item_id   INT UNSIGNED DEFAULT NULL,
  requirement         TEXT DEFAULT NULL,
  estimated_value     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  stage               ENUM('new','contacted','qualified','proposal','negotiation','won','lost')
                        NOT NULL DEFAULT 'new',
  probability         TINYINT UNSIGNED NOT NULL DEFAULT 10,
  assigned_to         INT UNSIGNED DEFAULT NULL,
  expected_close_date DATE DEFAULT NULL,
  lost_reason         VARCHAR(255) DEFAULT NULL,
  converted_client_id INT UNSIGNED DEFAULT NULL,
  converted_at        DATETIME DEFAULT NULL,
  created_by          INT UNSIGNED DEFAULT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lead_number (lead_number),
  KEY idx_leads_stage (stage),
  KEY idx_leads_assigned (assigned_to),
  CONSTRAINT fk_leads_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_leads_client FOREIGN KEY (converted_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_activities (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id       INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED DEFAULT NULL,
  activity_type ENUM('call','email','whatsapp','meeting','site_visit','quotation_sent',
                     'note','stage_change') NOT NULL DEFAULT 'note',
  subject       VARCHAR(200) DEFAULT NULL,
  notes         TEXT DEFAULT NULL,
  outcome       VARCHAR(160) DEFAULT NULL,
  activity_date DATETIME NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_act_lead (lead_id),
  CONSTRAINT fk_act_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_act_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminders (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id      INT UNSIGNED DEFAULT NULL,
  client_id    INT UNSIGNED DEFAULT NULL,
  user_id      INT UNSIGNED NOT NULL,           -- who must action it
  title        VARCHAR(200) NOT NULL,
  notes        TEXT DEFAULT NULL,
  remind_at    DATETIME NOT NULL,
  is_done      TINYINT(1) NOT NULL DEFAULT 0,
  completed_at DATETIME DEFAULT NULL,
  created_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rem_user_due (user_id, is_done, remind_at),
  KEY idx_rem_lead (lead_id),
  CONSTRAINT fk_rem_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_rem_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_rem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Expenses
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_number VARCHAR(40) NOT NULL,
  category_id    INT UNSIGNED DEFAULT NULL,
  vendor         VARCHAR(180) DEFAULT NULL,
  description    VARCHAR(500) NOT NULL,
  amount         DECIMAL(14,2) NOT NULL,
  vat_amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('cash','mpesa','bank','cheque','card','other') NOT NULL DEFAULT 'cash',
  reference      VARCHAR(120) DEFAULT NULL,
  expense_date   DATE NOT NULL,
  receipt_file   VARCHAR(255) DEFAULT NULL,
  is_billable    TINYINT(1) NOT NULL DEFAULT 0,
  client_id      INT UNSIGNED DEFAULT NULL,
  recorded_by    INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_expense_number (expense_number),
  KEY idx_exp_date (expense_date),
  KEY idx_exp_category (category_id),
  CONSTRAINT fk_exp_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_exp_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Internal chat
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_conversations (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        ENUM('dm','channel') NOT NULL DEFAULT 'dm',
  name        VARCHAR(120) DEFAULT NULL,       -- channels only
  description VARCHAR(255) DEFAULT NULL,
  dm_key      VARCHAR(40) DEFAULT NULL,        -- "minUserId:maxUserId", keeps DMs unique
  created_by  INT UNSIGNED DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dm_key (dm_key),
  CONSTRAINT fk_conv_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_participants (
  conversation_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_read_at    DATETIME DEFAULT NULL,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_part_user (user_id),
  CONSTRAINT fk_part_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_part_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  body            TEXT DEFAULT NULL,
  attachment_path VARCHAR(255) DEFAULT NULL,
  attachment_name VARCHAR(180) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at      DATETIME DEFAULT NULL,
  KEY idx_msg_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit trail & counters
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED DEFAULT NULL,
  action      VARCHAR(60)  NOT NULL,
  entity_type VARCHAR(40)  DEFAULT NULL,
  entity_id   INT UNSIGNED DEFAULT NULL,
  description VARCHAR(400) DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_user (user_id),
  KEY idx_log_entity (entity_type, entity_id),
  KEY idx_log_created (created_at),
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atomic sequence source for document numbers (avoids race on MAX()+1)
CREATE TABLE IF NOT EXISTS counters (
  counter_key  VARCHAR(60) NOT NULL PRIMARY KEY,  -- e.g. 'invoice:2026'
  last_value   INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
