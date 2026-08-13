-- =====================================================================
-- Migration 009 — several roles per person, and a Reception role
--
-- Until now each user held exactly one role, in users.role. Real jobs do
-- not divide that cleanly: the person on the front desk also raises
-- quotations, and the office manager also keeps the books. Forcing one
-- role meant granting the widest one, which handed people far more access
-- than their job needed.
--
-- users.role is kept, and keeps its meaning: it is the PRIMARY role, used
-- for the badge next to someone's name. The full set lives in user_roles,
-- and always includes the primary one, so permission checks read from a
-- single place and there is no "which one wins" question.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- users.role was an ENUM listing the six original roles, so storing
-- 'reception' truncated it to an empty string — silently on a server that
-- is not in strict mode, which is worse than failing outright.
--
-- Widened to VARCHAR to match user_roles.role. The set of roles now lives
-- in one place, Auth::ROLES, which validates every value on the way in;
-- adding the next role becomes a code change rather than another schema
-- migration on a table with live data in it.
-- ---------------------------------------------------------------------
ALTER TABLE users MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'staff';

CREATE TABLE IF NOT EXISTS user_roles (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  role       VARCHAR(20)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- One row per role per person; re-assigning the same role is a no-op
  -- rather than a duplicate that would need de-duplicating on read.
  UNIQUE KEY uq_user_role (user_id, role),
  KEY idx_user_roles_user (user_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Carry every existing user across, so nobody loses access the moment
-- this runs. INSERT IGNORE plus the unique key make it safe to re-run.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO user_roles (user_id, role)
SELECT id, role FROM users WHERE role IS NOT NULL AND role <> '';
