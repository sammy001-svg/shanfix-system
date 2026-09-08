-- =====================================================================
-- Migration 032 — a rate per partner, per thing
--
-- Until now a rate could say "design pays 20%" but not "design pays 20%
-- to Jane and 12% to Kamau", and partners are not all on the same terms:
-- one brings volume, another brings the customers nobody else reaches,
-- and a business negotiates with each of them separately.
--
-- So there are now three places a rate can come from, and they are
-- checked in this order, most specific first:
--
--   1. THIS PARTNER, THIS THING     partner_rates          (here)
--   2. THIS THING, ANY PARTNER      services.commission_rate
--   3. THIS PARTNER, ANYTHING       partners.default_rate
--
-- Nothing already agreed moves: commission rows keep the rate they were
-- earned at, because the row carries its own copy.
--
-- item_type deliberately uses the same words as document_items —
-- 'service' and 'inventory' — so a line on an invoice and a rate for it
-- are matched directly, with no translation in between to get wrong.
-- (ImageLibrary calls the same thing 'product'; that is a display word,
-- and it stops at the view.)
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS partner_rates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  partner_id  INT UNSIGNED NOT NULL,

  -- 'service'   -> services.id
  -- 'inventory' -> inventory_items.id
  item_type   ENUM('service','inventory') NOT NULL,
  ref_id      INT UNSIGNED NOT NULL,

  -- Percent. Zero is a real answer and means this partner earns nothing
  -- on this one; a row that is absent means "fall through to the next
  -- rule". The two must stay tellable apart, which is why an override is
  -- deleted rather than set to null when somebody clears the box.
  rate        DECIMAL(5,2) NOT NULL,

  note        VARCHAR(255) NULL COMMENT 'Why this one differs, for whoever asks later',

  set_by      INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- One rate per partner per thing. Two rows for the same pair would make
  -- the commission depend on which one the query happened to read first.
  UNIQUE KEY uq_partner_rate (partner_id, item_type, ref_id),
  KEY idx_partner_rates_item (item_type, ref_id),

  CONSTRAINT fk_partner_rates_partner FOREIGN KEY (partner_id)
    REFERENCES partners (id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_rates_setby FOREIGN KEY (set_by)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
