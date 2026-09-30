-- =====================================================================
-- Migration 058 — A social media manager, distinct from the officer
--
-- There was one social media role and it could do everything: plan a
-- post, write it, approve it and add the company's Instagram account.
-- Approving is not the same authority as writing, and the permission
-- map said so in a comment while granting both to the same role. A team
-- where everybody can approve is a team where the first two people to
-- agree can put anything on the company's page.
--
-- So the role splits in two:
--
--   social          plans, writes, schedules, records how it did
--   social_manager  the above, plus approving what goes out, owning the
--                   profiles and their access tokens, and campaign spend
--
-- Nobody loses anything today. Everyone who currently holds 'social'
-- holds those powers right now, so they are given the manager role as
-- well and carry on exactly as before. Narrowing somebody's access is a
-- decision for whoever runs the company, taken by removing the manager
-- role from the people who should not have it — not something a
-- migration does to them overnight while they are mid-campaign.
--
-- Roles are varchar, not an enum, so there is no column to widen.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Everyone holding the old role keeps what they can do
--
-- Both places a role can live are read: user_roles, and the primary
-- role on the user record, which counts even when the join table has
-- not got it.
-- ---------------------------------------------------------------------
INSERT INTO user_roles (user_id, role)
SELECT u.id, 'social_manager'
  FROM users u
 WHERE (u.role = 'social'
        OR EXISTS (SELECT 1 FROM user_roles r
                    WHERE r.user_id = u.id AND r.role = 'social'))
   AND NOT EXISTS (SELECT 1 FROM user_roles r2
                    WHERE r2.user_id = u.id AND r2.role = 'social_manager');

-- ---------------------------------------------------------------------
-- And they keep the officer role too
--
-- A manager still writes posts. Somebody whose primary role was
-- 'social' but who has no user_roles row for it would otherwise be a
-- manager who cannot open the calendar.
-- ---------------------------------------------------------------------
INSERT INTO user_roles (user_id, role)
SELECT u.id, 'social'
  FROM users u
 WHERE u.role = 'social'
   AND NOT EXISTS (SELECT 1 FROM user_roles r
                    WHERE r.user_id = u.id AND r.role = 'social');
