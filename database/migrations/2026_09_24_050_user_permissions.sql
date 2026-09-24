-- =====================================================================
-- Migration 050 — Permissions for one person, on top of their role
--
-- Access has been decided entirely by role: pick Sales and you get
-- exactly what Sales gets. That is the right default and it stays the
-- default. What it could not express is the ordinary exception — this
-- salesperson also handles purchase orders; that designer must not see
-- what a job costs — and the only way to say either was to invent a
-- role for one person, which is how a permission system stops being
-- readable.
--
-- So a role remains the starting point and this table holds the
-- differences from it. A row says "for this person, this one permission
-- is settled here rather than by their roles": allowed = 1 grants it
-- even though no role of theirs does, allowed = 0 takes it away even
-- though one does. No row at all means the roles decide, which is the
-- case for almost everybody.
--
-- Deliberately differences rather than a copy of the whole list per
-- user. Whatever an administrator ticks is exactly what that person
-- ends up with either way — the difference only shows later, when the
-- meaning of a role changes in code. Then every salesperson moves with
-- it, instead of a hundred frozen copies of what Sales meant the day
-- each account was made.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id     INT UNSIGNED NOT NULL,
  -- Matches a key of Auth::PERMISSIONS. Not a foreign key to anything:
  -- that list lives in code, where it can be read beside the reasons
  -- for it. A row naming a permission that no longer exists is ignored
  -- rather than being an error, so removing one from the map cannot
  -- break an account.
  permission  VARCHAR(40)  NOT NULL,
  -- 1 = granted although no role of theirs allows it.
  -- 0 = taken away although one does.
  allowed     TINYINT(1)   NOT NULL DEFAULT 1,
  -- Who decided. An override is somebody overruling the ordinary rules
  -- for one person, which is exactly the sort of decision that gets
  -- asked about a year later.
  granted_by  INT UNSIGNED DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, permission),
  -- "Who can do X?" has to account for these, so it is asked by
  -- permission as well as by user.
  KEY idx_user_perm_who (permission, allowed),
  CONSTRAINT fk_user_perm_user  FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_perm_giver FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
