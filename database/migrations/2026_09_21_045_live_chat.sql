-- =====================================================================
-- Migration 045 — Live chat with website visitors
--
-- Replaces tawk.to. Somebody reading our website can ask a question and
-- get an answer from a person here, without an account and without
-- leaving the page.
--
-- Named live_* rather than chat_*, which is already taken by the staff
-- room (chat_conversations, chat_channels). Two different things: that
-- one is colleagues talking to each other, this one is a stranger on the
-- website asking what a banner costs. Keeping the names apart keeps the
-- queries honest.
--
-- It is also not portal_messages (migration 044). That is a signed-in
-- client's support thread — slow, one per client, tied to an account.
-- This is a live conversation with somebody we may never have met, who
-- is deciding right now whether to buy from us.
--
-- Departments are the point of the exercise. A question about an invoice
-- and a question about artwork should not land in the same pile, and
-- whoever answers should be somebody who actually knows. So a
-- conversation belongs to a department, and a department has staff.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Departments — the queues a visitor can arrive in
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS live_departments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL,
  slug          VARCHAR(80)  NOT NULL,
  -- Shown to the visitor under the name, so they pick the right queue:
  -- "Prices, quotations and new orders".
  blurb         VARCHAR(160) DEFAULT NULL,
  -- Where a conversation goes when nobody answers in time. Falls back to
  -- the company address when empty.
  fallback_email VARCHAR(160) DEFAULT NULL,
  -- Exactly one department should be the default: it catches anybody who
  -- does not choose, and anything whose department is deleted.
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  position      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_live_dept_slug (slug),
  KEY idx_live_dept_live (status, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Who answers for a department
--
-- Membership is what decides whose inbox a conversation appears in, so
-- it is a table and not a role: the same person may cover sales and
-- accounts, and two people covering sales is the normal case.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS live_department_staff (
  department_id INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  -- A lead gets the escalations and the unclaimed-queue warnings.
  is_lead       TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (department_id, user_id),
  KEY idx_lds_user (user_id),
  CONSTRAINT fk_lds_dept FOREIGN KEY (department_id) REFERENCES live_departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_lds_user FOREIGN KEY (user_id)       REFERENCES users(id)            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Conversations
--
-- The visitor's credential is a random token they keep in their own
-- browser. Only its hash is stored, as for API keys: somebody who reads
-- this table still cannot resume anybody's conversation.
--
-- client_id / client_user_id / partner_id are filled in when the person
-- turns out to be somebody we already know — signed into the portal, or
-- matched later by e-mail. A stranger leaves them null, which is the
-- ordinary case and not a defect.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS live_conversations (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Short human reference, so somebody can quote it on the phone.
  ref            VARCHAR(16)  NOT NULL,
  department_id  INT UNSIGNED DEFAULT NULL,
  token_hash     CHAR(64)     NOT NULL,

  visitor_name   VARCHAR(100) DEFAULT NULL,
  visitor_email  VARCHAR(160) DEFAULT NULL,
  visitor_phone  VARCHAR(30)  DEFAULT NULL,

  client_id      INT UNSIGNED DEFAULT NULL,
  client_user_id INT UNSIGNED DEFAULT NULL,
  partner_id     INT UNSIGNED DEFAULT NULL,

  -- Where they were and what they were using. The page is the single
  -- most useful thing an agent can know before answering.
  page_url       VARCHAR(255) DEFAULT NULL,
  page_title     VARCHAR(160) DEFAULT NULL,
  referrer       VARCHAR(255) DEFAULT NULL,
  user_agent     VARCHAR(255) DEFAULT NULL,
  ip             VARBINARY(16) DEFAULT NULL,

  -- waiting: nobody has answered yet. That is the queue that matters,
  -- and the one the inbox sorts to the top.
  status         ENUM('waiting','open','closed') NOT NULL DEFAULT 'waiting',
  assigned_user_id INT UNSIGNED DEFAULT NULL,
  assigned_at    DATETIME DEFAULT NULL,

  -- How long the visitor waited for a human. Kept because it is the one
  -- number that says whether this is working.
  first_reply_at DATETIME DEFAULT NULL,
  last_visitor_at DATETIME DEFAULT NULL,
  last_staff_at  DATETIME DEFAULT NULL,

  closed_at      DATETIME DEFAULT NULL,
  closed_by      INT UNSIGNED DEFAULT NULL,
  close_reason   VARCHAR(160) DEFAULT NULL,

  rating         TINYINT UNSIGNED DEFAULT NULL,   -- 1..5, set by the visitor
  rating_comment VARCHAR(255) DEFAULT NULL,

  -- Set once the transcript has been e-mailed, so it is not sent twice.
  transcript_sent_at DATETIME DEFAULT NULL,

  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_live_conv_token (token_hash),
  UNIQUE KEY uq_live_conv_ref   (ref),
  KEY idx_live_conv_queue  (status, department_id, created_at),
  KEY idx_live_conv_agent  (assigned_user_id, status),
  KEY idx_live_conv_client (client_id),
  KEY idx_live_conv_recent (updated_at),
  CONSTRAINT fk_live_conv_dept   FOREIGN KEY (department_id)    REFERENCES live_departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_live_conv_agent  FOREIGN KEY (assigned_user_id) REFERENCES users(id)            ON DELETE SET NULL,
  CONSTRAINT fk_live_conv_client FOREIGN KEY (client_id)        REFERENCES clients(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Messages
--
-- 'system' is a real sender: "Moved to Accounts", "Nobody is at the desk
-- just now". The visitor sees those, so they are messages and not a
-- separate log nobody reads.
--
-- A 'note' is the opposite — staff only, never sent to the visitor. It
-- is a column rather than a table because it belongs in the thread, in
-- order, where the next person reads it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS live_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender          ENUM('visitor','staff','system') NOT NULL,
  staff_user_id   INT UNSIGNED DEFAULT NULL,
  -- Kept alongside staff_user_id so a transcript still reads correctly
  -- after somebody leaves and their account is removed.
  sender_name     VARCHAR(100) DEFAULT NULL,
  body            TEXT NOT NULL,
  is_note         TINYINT(1) NOT NULL DEFAULT 0,
  read_by_staff_at   DATETIME DEFAULT NULL,
  read_by_visitor_at DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_live_msg_conv (conversation_id, id),
  KEY idx_live_msg_unread (conversation_id, sender, read_by_staff_at),
  CONSTRAINT fk_live_msg_conv FOREIGN KEY (conversation_id) REFERENCES live_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_msg_user FOREIGN KEY (staff_user_id)   REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Saved replies
--
-- The same six questions arrive every week. Typing the answer again each
-- time is how replies get shorter and worse.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS live_canned (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED DEFAULT NULL,   -- null = available everywhere
  title         VARCHAR(80)  NOT NULL,
  body          TEXT         NOT NULL,
  uses          INT UNSIGNED NOT NULL DEFAULT 0,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_live_canned_dept (department_id),
  CONSTRAINT fk_live_canned_dept FOREIGN KEY (department_id) REFERENCES live_departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_canned_user FOREIGN KEY (created_by)    REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Somewhere to start
--
-- Two departments, because one is not a choice and five is a quiz. The
-- office can rename them, add more, or turn the picker off entirely.
-- ---------------------------------------------------------------------
INSERT INTO live_departments (name, slug, blurb, is_default, position, status)
SELECT * FROM (SELECT
  'Sales' AS name, 'sales' AS slug,
  'Prices, quotations and new orders' AS blurb,
  1 AS is_default, 1 AS position, 'active' AS status) AS d
WHERE NOT EXISTS (SELECT 1 FROM live_departments WHERE slug = 'sales');

INSERT INTO live_departments (name, slug, blurb, is_default, position, status)
SELECT * FROM (SELECT
  'Support' AS name, 'support' AS slug,
  'An order in progress, or something that is not working' AS blurb,
  0 AS is_default, 2 AS position, 'active' AS status) AS d
WHERE NOT EXISTS (SELECT 1 FROM live_departments WHERE slug = 'support');

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('livechat_enabled',         '1'),
  ('livechat_greeting',        'Hello. How can we help you today?'),
  -- Shown when nobody is at the desk. Says what will happen next,
  -- because "we are offline" tells somebody nothing they can act on.
  ('livechat_offline_message', 'Nobody is at the desk just now. Leave your message and your e-mail address and we will reply as soon as we are back.'),
  ('livechat_hours_from',      '08:00'),
  ('livechat_hours_to',        '17:30'),
  -- Mon-Sat is the ordinary Kenyan working week; 0 would be Sunday.
  ('livechat_hours_days',      '1,2,3,4,5,6'),
  -- Whether to ask which department. Off means everything lands in the
  -- default one.
  ('livechat_ask_department',  '1'),
  -- Minutes a conversation may sit unanswered before the department's
  -- leads are told about it.
  ('livechat_alert_after',     '5')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
