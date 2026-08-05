-- =====================================================================
-- Shanfix Technology - starter data
-- Run AFTER schema.sql
-- Default login: admin@shanfix.co.ke / Shanfix@2026   (CHANGE ON FIRST LOGIN)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Administrator
-- password_hash below is password_hash('Shanfix@2026', PASSWORD_DEFAULT)
-- ---------------------------------------------------------------------
INSERT INTO users (name, email, phone, password_hash, role, job_title, avatar_color)
VALUES ('System Administrator', 'admin@shanfix.co.ke', '0700000000',
        '$2y$10$3mEb4joH6QIae8jOZ/fce.ij0jT9jkq8ht/NavZT3gzsDK6yirPIK',
        'admin', 'Administrator', '#0D2B4B')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------
-- Company & document settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('company_name',        'Shanfix Technology'),
  ('company_tagline',     'Printing, Branding & Software Solutions'),
  ('company_email',       'info@shanfix.co.ke'),
  ('company_phone',       '+254 700 000 000'),
  ('company_address',     'Nairobi, Kenya'),
  ('company_kra_pin',     ''),
  ('company_website',     'www.shanfix.co.ke'),
  ('company_logo',        ''),
  ('currency',            'KES'),
  ('vat_rate',            '16'),
  ('vat_default_mode',    'exclusive'),
  ('quotation_prefix',    'QTN'),
  ('invoice_prefix',      'INV'),
  ('receipt_prefix',      'RCP'),
  ('payment_prefix',      'PMT'),
  ('expense_prefix',      'EXP'),
  ('lead_prefix',         'LD'),
  ('client_prefix',       'CL'),
  ('quotation_validity_days', '30'),
  ('invoice_due_days',    '14'),
  ('quotation_terms',     'Quotation valid for 30 days from date of issue. 60% deposit required to commence work. Prices are subject to change without prior notice.'),
  ('invoice_terms',       'Payment due within 14 days. Pay via M-Pesa Till or bank transfer using the invoice number as reference. Goods remain the property of Shanfix Technology until paid in full.'),
  -- Left blank on purpose: a half-filled "Account No: —" must never reach a
  -- client. Fill this in under Settings > Documents before invoicing.
  ('bank_details',        ''),
  ('mpesa_till',          ''),
  -- KopoKopo (fill these in from your KopoKopo dashboard)
  ('kopokopo_enabled',     '0'),
  ('kopokopo_env',         'sandbox'),
  ('kopokopo_client_id',   ''),
  ('kopokopo_client_secret', ''),
  ('kopokopo_till_number', ''),
  ('kopokopo_api_key',     ''),
  ('kopokopo_callback_url','')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- Categories
-- ---------------------------------------------------------------------
INSERT IGNORE INTO categories (name, type) VALUES
  ('Large Format Printing', 'inventory'),
  ('Branding Materials',    'inventory'),
  ('Corporate Gifts',       'inventory'),
  ('Signage',               'inventory'),
  ('Stationery',            'inventory'),
  ('Apparel & Textiles',    'inventory'),
  ('Consumables',           'inventory'),

  ('Software Development',  'service'),
  ('Web Development',       'service'),
  ('Graphic Design',        'service'),
  ('Printing Services',     'service'),
  ('Branding Services',     'service'),
  ('Digital Marketing',     'service'),
  ('Support & Maintenance', 'service'),

  ('Rent & Utilities',      'expense'),
  ('Salaries & Wages',      'expense'),
  ('Raw Materials',         'expense'),
  ('Machine Maintenance',   'expense'),
  ('Transport & Delivery',  'expense'),
  ('Marketing',             'expense'),
  ('Internet & Airtime',    'expense'),
  ('Licences & Subscriptions', 'expense'),
  ('Miscellaneous',         'expense');

-- ---------------------------------------------------------------------
-- Sample inventory (edit prices to match your real rate card)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO inventory_items (sku, name, category_id, unit, cost_price, selling_price, quantity, reorder_level, description)
SELECT * FROM (
  SELECT 'PRN-BC-001' AS sku, 'Business Cards (Matte, 350gsm) - Box of 100' AS name,
         (SELECT id FROM categories WHERE name='Stationery' AND type='inventory') AS category_id,
         'box' AS unit, 450.00 AS cost_price, 1200.00 AS selling_price, 0 AS quantity, 5 AS reorder_level,
         'Full colour double sided, matte lamination' AS description
  UNION ALL SELECT 'PRN-BAN-001', 'Roll-up Banner 800x2000mm',
         (SELECT id FROM categories WHERE name='Large Format Printing' AND type='inventory'),
         'pcs', 3200.00, 6500.00, 0, 3, 'Includes retractable stand and carry bag'
  UNION ALL SELECT 'PRN-TSH-001', 'Branded T-Shirt (Cotton, Screen Print)',
         (SELECT id FROM categories WHERE name='Apparel & Textiles' AND type='inventory'),
         'pcs', 420.00, 850.00, 0, 20, 'Round neck, up to 2 colour print'
  UNION ALL SELECT 'PRN-MUG-001', 'Branded Ceramic Mug (Sublimation)',
         (SELECT id FROM categories WHERE name='Corporate Gifts' AND type='inventory'),
         'pcs', 220.00, 550.00, 0, 20, '11oz white mug, full wrap print'
  UNION ALL SELECT 'PRN-SIG-001', '3D Signage Letters (Acrylic, per sq ft)',
         (SELECT id FROM categories WHERE name='Signage' AND type='inventory'),
         'sqft', 1800.00, 3500.00, 0, 0, 'Illuminated acrylic letters, installation quoted separately'
  UNION ALL SELECT 'PRN-STK-001', 'Vinyl Stickers (per sq ft)',
         (SELECT id FROM categories WHERE name='Branding Materials' AND type='inventory'),
         'sqft', 90.00, 250.00, 0, 0, 'Self-adhesive, contour cut'
  UNION ALL SELECT 'PRN-BRC-001', 'A5 Brochure (Full Colour, 130gsm) - per 100',
         (SELECT id FROM categories WHERE name='Stationery' AND type='inventory'),
         'pack', 1600.00, 3200.00, 0, 5, 'Double sided, gloss art paper'
) AS s;

-- ---------------------------------------------------------------------
-- Sample services
-- ---------------------------------------------------------------------
INSERT IGNORE INTO services (code, name, category_id, pricing_type, price, unit_label, lead_time, description)
SELECT * FROM (
  SELECT 'SVC-WEB-001' AS code, 'Business Website (up to 5 pages)' AS name,
         (SELECT id FROM categories WHERE name='Web Development' AND type='service') AS category_id,
         'from' AS pricing_type, 45000.00 AS price, 'per site' AS unit_label, '2-3 weeks' AS lead_time,
         'Responsive design, CMS, contact forms, SEO basics, 1 year support' AS description
  UNION ALL SELECT 'SVC-WEB-002', 'E-Commerce Website',
         (SELECT id FROM categories WHERE name='Web Development' AND type='service'),
         'from', 120000.00, 'per site', '4-6 weeks',
         'Product catalogue, cart, M-Pesa & card payments, admin dashboard'
  UNION ALL SELECT 'SVC-SFT-001', 'Custom Software Development',
         (SELECT id FROM categories WHERE name='Software Development' AND type='service'),
         'project', 0.00, 'per project', 'Scoped per project',
         'Bespoke business systems - quoted after requirements analysis'
  UNION ALL SELECT 'SVC-SFT-002', 'Software Development (Hourly)',
         (SELECT id FROM categories WHERE name='Software Development' AND type='service'),
         'hourly', 3500.00, 'per hour', 'On demand',
         'Ad-hoc development, integrations and fixes'
  UNION ALL SELECT 'SVC-SFT-003', 'System Maintenance & Support',
         (SELECT id FROM categories WHERE name='Support & Maintenance' AND type='service'),
         'monthly', 15000.00, 'per month', 'Ongoing',
         'Hosting monitoring, backups, updates, priority support'
  UNION ALL SELECT 'SVC-DSN-001', 'Logo Design & Brand Identity',
         (SELECT id FROM categories WHERE name='Graphic Design' AND type='service'),
         'fixed', 25000.00, 'per brand', '1-2 weeks',
         '3 concepts, 2 revision rounds, full brand guideline document'
  UNION ALL SELECT 'SVC-DSN-002', 'Graphic Design (Artwork Setup)',
         (SELECT id FROM categories WHERE name='Graphic Design' AND type='service'),
         'hourly', 1500.00, 'per hour', 'Same day',
         'Artwork preparation, print-ready file setup'
  UNION ALL SELECT 'SVC-BRD-001', 'Vehicle Branding (Full Wrap)',
         (SELECT id FROM categories WHERE name='Branding Services' AND type='service'),
         'from', 65000.00, 'per vehicle', '3-5 days',
         'Design, print, laminate and professional installation'
  UNION ALL SELECT 'SVC-BRD-002', 'Office & Shop Branding',
         (SELECT id FROM categories WHERE name='Branding Services' AND type='service'),
         'project', 0.00, 'per site', 'Site survey required',
         'Wall graphics, window frosting, reception signage'
  UNION ALL SELECT 'SVC-MKT-001', 'Social Media Management',
         (SELECT id FROM categories WHERE name='Digital Marketing' AND type='service'),
         'monthly', 30000.00, 'per month', 'Ongoing',
         'Content calendar, 12 posts/month, community management, monthly report'
) AS s;
