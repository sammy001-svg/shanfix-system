-- =====================================================================
-- Migration 034 — who the partner actually is
--
-- The application asked for a name, a business, an email, a phone number
-- and a pitch. That is enough to decide whether somebody is interesting
-- and nowhere near enough to sign an agreement with them or to pay them:
-- there was no ID number, no occupation, no address, and the name was one
-- free-text box, so "J. Otieno" and "John Peter Otieno" were the same
-- shape of data.
--
-- Names are collected in three parts here because that is how they are
-- written in Kenya and how they appear on an ID and a KRA certificate —
-- which are the documents the name has to match when money moves.
--
-- The composed `name` column stays, and stays authoritative for display.
-- Everything downstream reads it — letters, the M-Pesa file, the text
-- that says we have paid — and none of that should have to know how a
-- name is spelled out. It is written from the three parts whenever they
-- are set, so the two cannot drift.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- The person
-- ---------------------------------------------------------------------
-- All nullable: there are partners on the system already who were taken
-- on with less, and a migration that made these compulsory would either
-- fail or invent data for them. The application form is what requires
-- them, which is the right place — going forward, not backwards.
ALTER TABLE partners
  ADD COLUMN first_name VARCHAR(60) NULL AFTER partner_code,
  ADD COLUMN middle_name VARCHAR(60) NULL
      COMMENT 'Not everyone uses one, so never required'
      AFTER first_name,
  ADD COLUMN last_name VARCHAR(60) NULL AFTER middle_name,
  ADD COLUMN occupation VARCHAR(120) NULL
      COMMENT 'What they do, which is usually how they reach customers'
      AFTER company,
  ADD COLUMN id_number VARCHAR(30) NULL
      COMMENT 'National ID. Checked against the name when we pay them'
      AFTER kra_pin,
  ADD COLUMN office_location VARCHAR(200) NULL
      COMMENT 'Where they actually work from — town, building, floor'
      AFTER id_number;

-- Looked up when an application arrives, to recognise somebody who has
-- already applied. Not UNIQUE on purpose: a duplicate would raise a
-- database error on the public form, and an error that only appears for
-- real ID numbers is a way to test whether one is on file.
CREATE INDEX idx_partners_id_number ON partners (id_number);

-- ---------------------------------------------------------------------
-- Give the partners already on the system a first and last name
-- ---------------------------------------------------------------------
-- So their profile does not read as blank next to the new ones. Only the
-- ends are taken: whatever sits in the middle is guesswork, and `name`
-- still holds the whole of it either way.
UPDATE partners
   SET first_name = SUBSTRING_INDEX(TRIM(name), ' ', 1)
 WHERE first_name IS NULL AND TRIM(name) <> '';

UPDATE partners
   SET last_name = SUBSTRING_INDEX(TRIM(name), ' ', -1)
 WHERE last_name IS NULL
   AND TRIM(name) LIKE '% %';
