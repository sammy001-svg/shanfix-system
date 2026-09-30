-- =====================================================================
-- Migration 057 — Tax per line, which eTIMS needs and the books wanted
--
-- VAT has been stored once per document: one mode, one rate, applied to
-- the whole net. That is right for a business where everything is
-- standard-rated and wrong the moment one line is not — a zero-rated
-- item on the same invoice as a standard one has been charged sixteen
-- per cent along with everything else. So this is a correction to the
-- books before it is anything to do with KRA.
--
-- eTIMS then requires exactly the same thing, per line, and will not
-- take an invoice without it:
--
--   a tax class      A exempt, B standard, C zero-rated, D non-VAT,
--                    E the reduced rate — the letters KRA uses
--   a classification the KRA item classification code for what was sold
--   the tax amount   worked out for that line, not for the invoice
--
-- The class lives on the service or stock item, because that is a fact
-- about the thing and not about one sale of it. It is then copied onto
-- the invoice line when the line is written: a price list that changes
-- next year must not change what an invoice issued today says, and an
-- invoice sent to KRA least of all.
--
-- The rates are not written into the schema. VAT rates are changed by
-- the Finance Act, and a rate baked into an enum is a rate somebody has
-- to migrate.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- What a thing is, for tax
--
-- Defaulting to B: nearly everything a print shop sells is standard
-- rated, and the exceptions are few enough to be set by hand. A default
-- of "unknown" would mean every existing service silently failing
-- transmission later.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'services' AND column_name = 'tax_type');

SET @sql := IF(@col = 0,
  'ALTER TABLE services
     ADD COLUMN tax_type CHAR(1) NOT NULL DEFAULT ''B''
         COMMENT ''KRA tax class: A exempt, B standard, C zero, D non-VAT, E reduced'',
     ADD COLUMN etims_code VARCHAR(20) DEFAULT NULL
         COMMENT ''KRA item classification code''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'inventory_items' AND column_name = 'tax_type');

SET @sql := IF(@col = 0,
  'ALTER TABLE inventory_items
     ADD COLUMN tax_type CHAR(1) NOT NULL DEFAULT ''B''
         COMMENT ''KRA tax class: A exempt, B standard, C zero, D non-VAT, E reduced'',
     ADD COLUMN etims_code VARCHAR(20) DEFAULT NULL
         COMMENT ''KRA item classification code''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- What this line was charged
--
-- Copied from the item when the line is written, never read back
-- through the join. The price list is a living thing; an invoice is
-- not.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'document_items' AND column_name = 'tax_type');

-- Whether the column is new, remembered before we add it, because the
-- backfill below must only ever run the once.
SET @fresh := (@col = 0);

SET @sql := IF(@col = 0,
  'ALTER TABLE document_items
     ADD COLUMN tax_type CHAR(1) NOT NULL DEFAULT ''B'' AFTER line_total,
     ADD COLUMN tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_type,
     ADD COLUMN etims_code VARCHAR(20) DEFAULT NULL AFTER tax_amount',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Existing documents keep the answer they already have
--
-- Every line is set to the class its own document was computed under,
-- so nothing recomputes to a different total than the one the client
-- was sent. An exempt document becomes A; everything else B, which is
-- what the single-rate arithmetic was already doing to it.
--
-- Once only. A zero-rated line has no tax on it quite legitimately, so
-- "no tax yet" stops being a way to spot an un-backfilled row the
-- moment the first such line is written. Run this a second time and it
-- would quietly re-stamp every one of them as standard rated.
-- ---------------------------------------------------------------------
SET @sql := IF(@fresh,
  'UPDATE document_items i
     JOIN documents d ON d.id = i.document_id
      SET i.tax_type = IF(d.vat_mode = ''exempt'', ''A'', ''B'')',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Settings
--
-- The rates live here because the Finance Act moves them. B and E are
-- the only classes with a rate; A, C and D are nought by definition.
--
-- Only the reduced rate is added. The standard rate is vat_rate, which
-- has held it since the beginning and which the settings page edits;
-- this file used to add a tax_rate_b beside it, and migration 059
-- removes that again. A rate held in two settings goes stale in one of
-- them, and the stale one is whichever the administrator was not shown.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('tax_rate_e', '8')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
