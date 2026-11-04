-- =====================================================================
-- Migration 064 — partners beyond Kenya
--
-- The partner module works, and works only here. Five things in it are
-- Kenya-shaped, and each one of them stops an overseas partner dead:
--
--   1. There is no country. `office_location` is free text, so nothing
--      can be grouped, filtered, taxed or paid differently by where the
--      partner actually is.
--
--   2. `pay_method` is ENUM('mpesa','bank') and `Payouts::destinationFor`
--      understands nothing else. A partner in Kampala or Dubai reaches
--      "No payment method chosen" and sits in a held line for ever.
--
--   3. Money is KES and only KES. A partner abroad wants to know what
--      they will be paid in, and finance needs the rate that was used
--      written down at the time — the same reason `commissions.rate` is
--      copied in rather than looked up later.
--
--   4. Tax identity is a KRA PIN and a Kenyan national ID. Neither
--      exists for a non-resident, and the payout run refuses to pay a
--      partner without one.
--
--   5. Nothing is withheld. For a resident agent that is a choice; for
--      a NON-RESIDENT it is an exposure, because the withholding on a
--      commission paid out of Kenya is owed whether or not we deducted
--      it. The deduction belongs here, at the point of payment.
--
-- Two deliberate decisions:
--
--   * Withholding is taken on the PAYOUT LINE, never in the commission
--     ledger. A commission earned is still the gross amount the partner
--     earned; tax deducted and remitted on their behalf is a fact about
--     the payment, not about the sale. This keeps the ledger's one
--     invariant — that it is derivable from the payments received — and
--     it keeps a partner's statement honest about what they earned.
--
--   * It starts switched OFF (`partner_withholding_enabled` = 0), so
--     applying this migration changes nobody's pay. Turning it on is a
--     decision with a date on it, not a side effect of an upgrade.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Where the partner is, and what that means
-- ---------------------------------------------------------------------
ALTER TABLE partners
  ADD COLUMN country CHAR(2) NOT NULL DEFAULT 'KE'
      COMMENT 'ISO 3166-1 alpha-2. Every partner on file today is Kenyan, hence the default'
      AFTER office_location,
  ADD COLUMN city VARCHAR(90) NULL AFTER country,
  ADD COLUMN timezone VARCHAR(64) NULL
      COMMENT 'IANA name. What time it is where they are, so we do not text them at 3am'
      AFTER city,
  ADD COLUMN territory VARCHAR(140) NULL
      COMMENT 'Where they are allowed to sell. Blank means no restriction'
      AFTER timezone;

CREATE INDEX idx_partners_country ON partners (country);

-- Residency is not the same question as country, which is why it is its
-- own column: a Kenyan-registered company owned from abroad is resident,
-- and a Kenyan who has moved away is not. Only a human knows, so a human
-- is asked, and the country only suggests the answer.
ALTER TABLE partners
  ADD COLUMN tax_residency ENUM('resident','non_resident') NOT NULL DEFAULT 'resident'
      COMMENT 'Resident for Kenyan tax purposes. Decides the withholding rate'
      AFTER kra_pin,
  ADD COLUMN tax_id VARCHAR(40) NULL
      COMMENT 'Their tax number where it is not a KRA PIN — VAT no., TIN, EIN'
      AFTER tax_residency,
  ADD COLUMN tax_id_label VARCHAR(40) NULL
      COMMENT 'What that number is called where they are, so the form can say it'
      AFTER tax_id,
  ADD COLUMN withholding_rate DECIMAL(5,2) NULL
      COMMENT 'Percent. NULL means use the rate for their residency; 0 is a real answer meaning do not withhold'
      AFTER tax_id_label;

-- ---------------------------------------------------------------------
-- What they are, and how far in
-- ---------------------------------------------------------------------
-- The programme page promises tiers and types. They have to exist.
ALTER TABLE partners
  ADD COLUMN partner_type ENUM('referral','reseller','implementation','affiliate')
      NOT NULL DEFAULT 'referral'
      COMMENT 'referral: introduces. reseller: sells and supports. implementation: deploys. affiliate: online only'
      AFTER status,
  ADD COLUMN tier VARCHAR(20) NOT NULL DEFAULT 'registered'
      COMMENT 'partner_tiers.code. Set by hand; the earned tier is worked out and compared against it'
      AFTER partner_type;

CREATE INDEX idx_partners_tier ON partners (tier, partner_type);

-- An international arrangement is a signed contract, not a handshake.
ALTER TABLE partners
  ADD COLUMN agreement_ref VARCHAR(60) NULL AFTER decision_note,
  ADD COLUMN agreement_signed_at DATE NULL AFTER agreement_ref,
  ADD COLUMN agreement_expires_at DATE NULL
      COMMENT 'NULL means it does not expire'
      AFTER agreement_signed_at;

CREATE INDEX idx_partners_agreement ON partners (agreement_expires_at);

-- ---------------------------------------------------------------------
-- Paying somebody who is not down the road
-- ---------------------------------------------------------------------
-- The enum is widened rather than loosened to VARCHAR: the set of ways
-- we can actually send money is small and known, and the database is the
-- right place to keep saying so.
ALTER TABLE partners
  MODIFY COLUMN pay_method ENUM('mpesa','bank','wire','paypal','wise') NULL
      COMMENT 'How we send it. NULL means we cannot pay them yet';

ALTER TABLE payout_lines
  MODIFY COLUMN method ENUM('mpesa','bank','wire','paypal','wise') NULL;

ALTER TABLE partners
  ADD COLUMN bank_swift VARCHAR(20) NULL
      COMMENT 'SWIFT/BIC. Needed for anything leaving the country'
      AFTER bank_account_no,
  ADD COLUMN bank_iban VARCHAR(40) NULL AFTER bank_swift,
  ADD COLUMN bank_routing VARCHAR(40) NULL
      COMMENT 'ABA, sort code, IFSC — whatever the destination country uses instead of an IBAN'
      AFTER bank_iban,
  ADD COLUMN bank_country CHAR(2) NULL
      COMMENT 'Where the account is, which is not always where the partner is'
      AFTER bank_routing,
  ADD COLUMN bank_address VARCHAR(200) NULL
      COMMENT 'The branch address correspondent banks ask for'
      AFTER bank_country,
  ADD COLUMN pay_email VARCHAR(160) NULL
      COMMENT 'The PayPal or Wise account, which is often not their contact address'
      AFTER bank_address,
  ADD COLUMN payout_currency CHAR(3) NOT NULL DEFAULT 'KES'
      COMMENT 'ISO 4217. What they are paid in; commission is always earned in KES'
      AFTER pay_email;

-- ---------------------------------------------------------------------
-- The rate used, written down
-- ---------------------------------------------------------------------
-- Commission is earned in KES because that is what the customer paid.
-- The payout may leave in something else, and the rate it left at has to
-- survive the next rate change, so it is copied onto the line.
ALTER TABLE payout_lines
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'KES' AFTER amount,
  ADD COLUMN fx_rate DECIMAL(16,6) NOT NULL DEFAULT 1.000000
      COMMENT 'KES per one unit of currency, as it stood when the run was built'
      AFTER currency,
  ADD COLUMN withholding_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER fx_rate,
  ADD COLUMN withheld DECIMAL(14,2) NOT NULL DEFAULT 0.00
      COMMENT 'Deducted and remitted on their behalf, in KES'
      AFTER withholding_rate,
  ADD COLUMN net_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00
      COMMENT 'amount - withheld, in KES. What we actually send'
      AFTER withheld,
  ADD COLUMN net_fx DECIMAL(14,2) NOT NULL DEFAULT 0.00
      COMMENT 'net_amount converted at fx_rate. What lands in their account'
      AFTER net_amount;

-- Lines written before this migration were paid gross in KES, and saying
-- so is more use than leaving zeros that read as "nothing was sent".
UPDATE payout_lines
   SET net_amount = amount,
       net_fx     = amount
 WHERE net_amount = 0.00 AND amount <> 0.00;

ALTER TABLE payout_runs
  ADD COLUMN withheld_total DECIMAL(14,2) NOT NULL DEFAULT 0.00
      COMMENT 'What the run holds back, for the tax return that follows it'
      AFTER total;

-- ---------------------------------------------------------------------
-- Exchange rates
-- ---------------------------------------------------------------------
-- Entered by finance, not fetched. There is no outbound API call to rely
-- on from a cPanel box, and in any case the rate that matters is the one
-- the bank actually gave us, which no public feed knows.
CREATE TABLE IF NOT EXISTS fx_rates (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  currency   CHAR(3) NOT NULL,
  rate       DECIMAL(16,6) NOT NULL COMMENT 'KES per one unit of this currency',
  as_of      DATE NOT NULL,
  source     VARCHAR(90) NULL COMMENT 'Where it came from: the bank, CBK, whoever',
  set_by     INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fx_day (currency, as_of),
  KEY idx_fx_currency (currency, as_of),
  CONSTRAINT fk_fx_set_by FOREIGN KEY (set_by)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KES against itself, so the conversion has one code path rather than a
-- special case that only the home currency takes.
INSERT INTO fx_rates (currency, rate, as_of, source)
VALUES ('KES', 1.000000, '2020-01-01', 'Base currency')
ON DUPLICATE KEY UPDATE rate = 1.000000;

-- ---------------------------------------------------------------------
-- Tiers
-- ---------------------------------------------------------------------
-- A table rather than four settings keys, because the benefits are shown
-- on the public programme page and the thresholds are compared against
-- what partners have actually billed. Both want rows.
CREATE TABLE IF NOT EXISTS partner_tiers (
  code            VARCHAR(20) NOT NULL,
  name            VARCHAR(60) NOT NULL,
  sort            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  min_billed_12m  DECIMAL(14,2) NOT NULL DEFAULT 0.00
                  COMMENT 'KES a partner must have introduced in the last 12 months to earn this tier',
  default_rate    DECIMAL(5,2) NOT NULL DEFAULT 10.00
                  COMMENT 'Commission a partner at this tier gets where nothing more specific applies',
  benefits        TEXT NULL COMMENT 'One per line. Shown on the public programme page',
  is_public       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code),
  KEY idx_tiers_sort (sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO partner_tiers (code, name, sort, min_billed_12m, default_rate, benefits) VALUES
  ('registered', 'Registered', 1, 0.00, 10.00,
   'Partner portal with live earnings\nMarketing material and product sheets\nCommission on every invoice your customer pays, for as long as they stay'),
  ('bronze', 'Bronze', 2, 500000.00, 12.50,
   'Everything in Registered\nA named account manager\nQuoting support on bids you are working\nYour logo on our partners page'),
  ('silver', 'Silver', 3, 2000000.00, 15.00,
   'Everything in Bronze\nDemo environments for your own pitches\nPriority on delivery slots\nCo-branded proposals'),
  ('gold', 'Gold', 4, 6000000.00, 18.00,
   'Everything in Silver\nTerritory protection in your market\nJoint go-to-market planning each quarter\nDirect line to the delivery team')
ON DUPLICATE KEY UPDATE code = code;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  -- Off on purpose. See the header: turning this on changes what
  -- partners are paid, and that is a dated decision.
  ('partner_withholding_enabled', '0'),
  ('partner_wht_resident',        '5'),
  ('partner_wht_non_resident',    '20'),

  ('partner_international_enabled', '1'),
  ('partner_home_country',          'KE'),
  ('partner_payout_currencies',     'KES,USD,EUR,GBP,UGX,TZS,RWF,ZAR,AED'),

  ('partner_programme_headline',
   'Sell what we build. Keep a share of every invoice your customer pays.'),
  ('partner_programme_intro',
   'We build business software and we print and brand. Partners bring us the customers who need it, in Kenya and beyond, and earn on that customer for as long as they stay with us.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
