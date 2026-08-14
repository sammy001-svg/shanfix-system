-- =====================================================================
-- Migration 011 — past work against a service
--
-- "Have we done this before?" is the question a salesperson gets asked
-- most, and until now the answer lived in whoever had been here longest.
-- This links a service in the catalogue to the job cards that are good
-- examples of it, so quoting a client for shopfront signage can show them
-- the shopfront signage we did last month.
--
-- Many-to-many on purpose: one job often demonstrates several services —
-- a branded shopfront is signage, printing and installation at once — and
-- a service naturally has many examples.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS service_jobs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id INT UNSIGNED NOT NULL,
  job_id     INT UNSIGNED NOT NULL,

  -- Why this one is worth showing. Optional; the job's own title usually
  -- says enough, but "client approved the second proof, great photos" is
  -- the kind of thing that stops someone re-reading the whole job card.
  note       VARCHAR(255) DEFAULT NULL,

  -- Ordering for the portfolio: the best example first, rather than
  -- whichever happened to be linked first.
  sort_order INT NOT NULL DEFAULT 0,

  linked_by  INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Linking the same job twice is a mis-click, not an intent.
  UNIQUE KEY uq_service_job (service_id, job_id),
  KEY idx_service_jobs_service (service_id),
  KEY idx_service_jobs_job (job_id),

  CONSTRAINT fk_service_jobs_service FOREIGN KEY (service_id)
    REFERENCES services(id) ON DELETE CASCADE,
  -- Deleting a job removes it from the portfolio rather than leaving a
  -- broken example behind.
  CONSTRAINT fk_service_jobs_job FOREIGN KEY (job_id)
    REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_jobs_user FOREIGN KEY (linked_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
