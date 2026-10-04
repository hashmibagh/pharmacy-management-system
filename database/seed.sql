-- ============================================================================
-- Pharmacy Management System - Demo Seed Data (MySQL 8+)
-- ----------------------------------------------------------------------------
-- ALL DATA IS FICTIONAL and for demonstration/testing only.
--
-- NOTE ON PASSWORDS: the password_hash below is a REAL bcrypt hash of
-- "password123" ($2b$ prefix is compatible with PHP password_verify).
-- To regenerate: php -r "echo password_hash('password123', PASSWORD_BCRYPT), PHP_EOL;"
--
-- Run order : schema.sql first, then this file, on a FRESH database.
-- Idempotency: INSERT IGNORE is used where sensible; FK checks are disabled
-- during the load and re-enabled at the end. The whole load runs inside one
-- transaction.
-- Dates use CURDATE()/NOW() offsets so the demo stays "fresh" (last 60 days
-- of purchases, last 30 days of sales, expiring-soon batches for alerts).
-- ============================================================================

START TRANSACTION;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- ROLES
-- ============================================================================
INSERT IGNORE INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Super Admin', 'Full system access, including user management'),
(2, 'Owner', 'Business owner - everything except user management'),
(3, 'Manager', 'Day-to-day operations except settings and users'),
(4, 'Pharmacist', 'Dispensing, prescriptions and counter sales'),
(5, 'Cashier', 'Counter sales only'),
(6, 'Store Keeper', 'Inventory and stock management');

-- ============================================================================
-- PERMISSIONS (module + name)
-- ============================================================================
INSERT IGNORE INTO `permissions` (`id`, `name`, `module`, `description`) VALUES
(1,  'dashboard.view',     'dashboard',     'View dashboard'),
(2,  'medicines.view',     'medicines',     'View medicines'),
(3,  'medicines.create',   'medicines',     'Create medicines'),
(4,  'medicines.edit',     'medicines',     'Edit medicines'),
(5,  'medicines.delete',   'medicines',     'Delete medicines'),
(6,  'inventory.view',     'inventory',     'View inventory'),
(7,  'inventory.adjust',   'inventory',     'Adjust stock levels'),
(8,  'inventory.transfer', 'inventory',     'Transfer stock between locations'),
(9,  'purchases.view',     'purchases',     'View purchases'),
(10, 'purchases.create',   'purchases',     'Create purchases'),
(11, 'purchases.edit',     'purchases',     'Edit purchases'),
(12, 'purchases.delete',   'purchases',     'Delete purchases'),
(13, 'purchases.return',   'purchases',     'Return purchases to supplier'),
(14, 'sales.view',         'sales',         'View sales'),
(15, 'sales.create',       'sales',         'Create sales'),
(16, 'sales.edit',         'sales',         'Edit sales'),
(17, 'sales.return',       'sales',         'Process sales returns'),
(18, 'customers.view',     'customers',     'View customers'),
(19, 'customers.create',   'customers',     'Create customers'),
(20, 'customers.edit',     'customers',     'Edit customers'),
(21, 'customers.delete',   'customers',     'Delete customers'),
(22, 'suppliers.view',     'suppliers',     'View suppliers'),
(23, 'suppliers.create',   'suppliers',     'Create suppliers'),
(24, 'suppliers.edit',     'suppliers',     'Edit suppliers'),
(25, 'suppliers.delete',   'suppliers',     'Delete suppliers'),
(26, 'expenses.view',      'expenses',      'View expenses'),
(27, 'expenses.create',    'expenses',      'Create expenses'),
(28, 'expenses.edit',      'expenses',      'Edit expenses'),
(29, 'expenses.delete',    'expenses',      'Delete expenses'),
(30, 'employees.view',     'employees',     'View employees'),
(31, 'employees.manage',   'employees',     'Manage employees, attendance and payroll'),
(32, 'prescriptions.view', 'prescriptions', 'View prescriptions'),
(33, 'prescriptions.create','prescriptions','Create prescriptions'),
(34, 'reports.view',       'reports',       'View reports'),
(35, 'reports.export',     'reports',       'Export reports'),
(36, 'settings.view',      'settings',      'View settings'),
(37, 'settings.manage',    'settings',      'Manage settings'),
(38, 'users.view',         'users',         'View system users'),
(39, 'users.manage',       'users',         'Manage system users and roles'),
(40, 'audit_logs.view',    'audit_logs',    'View audit logs');

-- ============================================================================
-- ROLE <-> PERMISSION ASSIGNMENTS
-- ============================================================================

-- Super Admin: everything
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.name = 'Super Admin';

-- Owner: everything except users.manage
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.name = 'Owner' AND p.name <> 'users.manage';

-- Manager: full operational modules (no settings, users, audit)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.name = 'Manager'
  AND p.module IN ('dashboard','medicines','inventory','purchases','sales',
                   'customers','suppliers','expenses','prescriptions','reports');

-- Pharmacist: dispensing + counter sales + prescriptions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.name = 'Pharmacist'
  AND p.name IN ('dashboard.view','medicines.view','inventory.view',
                 'sales.view','sales.create',
                 'customers.view','customers.create',
                 'prescriptions.view','prescriptions.create');

-- Cashier: counter sales only
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.name = 'Cashier'
  AND p.name IN ('dashboard.view','sales.view','sales.create','customers.view');

-- Store Keeper: inventory only
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.name = 'Store Keeper'
  AND p.name IN ('dashboard.view','medicines.view',
                 'inventory.view','inventory.adjust','inventory.transfer');

-- ============================================================================
-- USERS (fictional demo accounts; password for all is "password123")
-- NOTE: hash below is a real bcrypt hash of "password123" (see file header).
-- ============================================================================
INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password_hash`, `phone`, `role_id`, `status`) VALUES
(1, 'Admin User',  'admin@pharmacy.local',       '$2b$10$T8tVhtz6/ERCiQDMKQH98.t6DdKeKeeFvVlTTsHhVGyQdDUBaOcIe', '0300-9000001', 1, 'active'),
(2, 'Bilal Owner', 'owner@pharmacy.local',       '$2b$10$T8tVhtz6/ERCiQDMKQH98.t6DdKeKeeFvVlTTsHhVGyQdDUBaOcIe', '0300-9000002', 2, 'active'),
(3, 'Sara Manager','manager@pharmacy.local',     '$2b$10$T8tVhtz6/ERCiQDMKQH98.t6DdKeKeeFvVlTTsHhVGyQdDUBaOcIe', '0300-9000003', 3, 'active'),
(4, 'Ali Pharmacist','pharmacist@pharmacy.local','$2b$10$T8tVhtz6/ERCiQDMKQH98.t6DdKeKeeFvVlTTsHhVGyQdDUBaOcIe', '0300-9000004', 4, 'active'),
(5, 'Usman Cashier','cashier@pharmacy.local',    '$2b$10$T8tVhtz6/ERCiQDMKQH98.t6DdKeKeeFvVlTTsHhVGyQdDUBaOcIe', '0300-9000005', 5, 'active'),
(6, 'Hassan Keeper','storekeeper@pharmacy.local','$2b$10$T8tVhtz6/ERCiQDMKQH98.t6DdKeKeeFvVlTTsHhVGyQdDUBaOcIe', '0300-9000006', 6, 'active');

INSERT IGNORE INTO `profiles` (`user_id`, `full_name`, `cnic`, `address`, `emergency_contact`) VALUES
(1, 'Admin User',    '35202-1111111-1', 'Main Boulevard, Gulberg III, Lahore', '0300-9000011'),
(2, 'Bilal Raza',    '35202-2222222-2', 'DHA Phase 5, Lahore',                 '0300-9000012'),
(3, 'Sara Mehmood',  '35202-3333333-3', 'Model Town, Lahore',                  '0300-9000013'),
(4, 'Ali Nawaz',     '35202-4444444-4', 'Johar Town, Lahore',                  '0300-9000014'),
(5, 'Usman Tariq',   '35202-5555555-5', 'Wapda Town, Lahore',                  '0300-9000015'),
(6, 'Hassan Javed',  '35202-6666666-6', 'Iqbal Town, Lahore',                  '0300-9000016');

-- ============================================================================
-- MEDICINE CATEGORIES
-- ============================================================================
INSERT IGNORE INTO `medicine_categories` (`id`, `name`, `description`, `status`) VALUES
(1, 'Tablets',     'Solid oral dosage - tablets',          'active'),
(2, 'Capsules',    'Solid oral dosage - capsules',         'active'),
(3, 'Syrups',      'Liquid oral suspensions and syrups',   'active'),
(4, 'Injections',  'Injectable vials and ampoules',        'active'),
(5, 'Drops',       'Eye, ear and nasal drops',             'active'),
(6, 'Creams',      'Topical creams and ointments',         'active'),
(7, 'Inhalers',    'Respiratory inhalers and nebulisers',  'active'),
(8, 'Supplements', 'Vitamins, minerals and ORS',           'active');

-- ============================================================================
-- MANUFACTURERS (fictional)
-- ============================================================================
INSERT IGNORE INTO `manufacturers` (`id`, `name`, `country`, `contact_info`, `status`) VALUES
(1, 'NovaPharm Labs',       'Pakistan', '042-111-6688 | info@novapharm.example.pk',  'active'),
(2, 'MediCore Pakistan',    'Pakistan', '021-34556677 | care@medicore.example.pk',   'active'),
(3, 'Santex Pharma',        'Pakistan', '051-2223344 | sales@santex.example.pk',      'active'),
(4, 'VitaPlus Remedies',    'Pakistan', '042-35778899 | info@vitaplus.example.pk',   'active'),
(5, 'HerbHeal Naturals',    'Pakistan', '041-8556677 | hello@herbheal.example.pk',   'active');

-- ============================================================================
-- SUPPLIERS (fictional)
-- ============================================================================
INSERT IGNORE INTO `suppliers` (`id`, `name`, `contact_person`, `phone`, `whatsapp`, `email`, `address`, `tax_number`, `opening_balance`, `status`) VALUES
(1, 'City Pharma Distributors', 'Imran Sheikh',  '0321-4567890', '0321-4567890', 'orders@citypharma.example.pk',  'Shah Alam Market, Lahore',      '1234567-8', 25000.00, 'active'),
(2, 'Al-Shifa Traders',         'Bilal Ahmed',   '0333-9876543', '0333-9876543', 'bilal@alshifa.example.pk',      'Medicine Market, Karachi',      '2345678-9',     0.00, 'active'),
(3, 'MediServe Pharmaceuticals','Dr. Kamran Ali','0300-1122334', '0300-1122334', 'kamran@mediserve.example.pk',   'Blue Area, Islamabad',          '3456789-0', 15000.00, 'active'),
(4, 'PakHealth Supplies',       'Rashid Mehmood','0345-6677889', '0345-6677889', 'rashid@pakhealth.example.pk',   'Railway Road, Faisalabad',      '4567890-1',     0.00, 'active'),
(5, 'CareLine Distributors',    'Sanaullah Khan','0312-4455667', '0312-4455667', 'sana@careline.example.pk',      'Boson Road, Multan',            '5678901-2',  8000.00, 'active');

-- ============================================================================
-- CUSTOMERS (fictional)
-- ============================================================================
INSERT IGNORE INTO `customers` (`id`, `name`, `phone`, `whatsapp`, `address`, `cnic`, `medical_notes`, `credit_limit`, `reward_points`, `opening_balance`, `status`) VALUES
(1,  'Ahmed Khan',    '0300-0000001', '0300-0000001', 'House 12, Gulberg II, Lahore',      '35202-1010101-1', 'Allergic to penicillin', 5000.00,  120, 0.00, 'active'),
(2,  'Fatima Raza',   '0300-0000002', '0300-0000002', 'Street 4, Model Town, Lahore',      '35202-2020202-2', NULL,                     0.00,    45, 0.00, 'active'),
(3,  'Muhammad Ali',  '0300-0000003', '0300-0000003', 'Plot 88, DHA Phase 2, Karachi',     '42201-3030303-3', 'Diabetic - monitor sugar',10000.00, 210, 0.00, 'active'),
(4,  'Ayesha Malik',  '0300-0000004', '0300-0000004', 'Flat 7, Satellite Town, Rawalpindi','37405-4040404-4', NULL,                     0.00,    30, 0.00, 'active'),
(5,  'Bilal Hussain', '0300-0000005', '0300-0000005', 'House 3, Johar Town, Lahore',       '35202-5050505-5', 'Hypertension',             3000.00,  75, 0.00, 'active'),
(6,  'Sana Tariq',    '0300-0000006', '0300-0000006', 'Apt 21, Clifton Block 5, Karachi',   '42201-6060606-6', NULL,                     0.00,    15, 0.00, 'active'),
(7,  'Usman Ghani',   '0300-0000007', '0300-0000007', 'House 45, F-8/2, Islamabad',        '61101-7070707-7', NULL,                     0.00,    60, 0.00, 'active'),
(8,  'Hira Shahid',   '0300-0000008', '0300-0000008', 'House 9, Gulshan-e-Iqbal, Karachi', '42201-8080808-8', NULL,                     0.00,    25, 0.00, 'active'),
(9,  'Kamran Iqbal',  '0300-0000009', '0300-0000009', 'House 77, Cantt, Multan',           '36302-9090909-9', NULL,                     5000.00,  90, 0.00, 'active'),
(10, 'Nadia Farooq',  '0300-0000010', '0300-0000010', 'House 31, Peoples Colony, Faisalabad','33100-0101010-0', NULL,                  0.00,    40, 0.00, 'active');

-- ============================================================================
-- EMPLOYEES (fictional)
-- ============================================================================
INSERT IGNORE INTO `employees` (`id`, `name`, `phone`, `email`, `address`, `cnic`, `designation`, `joining_date`, `salary`, `commission`, `status`) VALUES
(1, 'Ali Raza',    '0300-1111111', 'aliraza@pharmacy.local',    'Samanabad, Lahore',   '35202-7777777-7', 'Pharmacist',     '2023-03-15', 65000.00, 2.00, 'active'),
(2, 'Bilal Ahmed', '0300-2222222', 'bilalahmed@pharmacy.local', 'Township, Lahore',    '35202-8888888-8', 'Cashier',        '2024-01-10', 45000.00, 0.00, 'active'),
(3, 'Sana Malik',  '0300-3333333', 'sanamalik@pharmacy.local',  'Green Town, Lahore',  '35202-9999999-9', 'Store Keeper',   '2024-06-01', 50000.00, 0.00, 'active'),
(4, 'Usman Tariq', '0300-4444444', 'usman@pharmacy.local',      'Chungi Amar Sidhu',   '35202-1212121-2', 'Delivery Rider', '2025-02-20', 35000.00, 0.00, 'active');

-- ============================================================================
-- MEDICINES (fictional brands, realistic generics)
-- ============================================================================
INSERT IGNORE INTO `medicines`
(`id`, `medicine_name`, `generic_name`, `brand_name`, `manufacturer_id`, `category_id`, `strength`, `dosage_form`, `packing`, `barcode`, `description`, `status`) VALUES
(1,  'Novagesic 500mg Tablets',      'Paracetamol',            'Novagesic',   1, 1, '500mg',     'Tablet',    '100 Tablets',  '8961001000017', 'Fever and pain relief tablets', 'active'),
(2,  'Ibufort 400mg Tablets',        'Ibuprofen',              'Ibufort',     2, 1, '400mg',     'Tablet',    '50 Tablets',   '8961001000024', 'NSAID for pain and inflammation', 'active'),
(3,  'Amoxicare 250mg Capsules',     'Amoxicillin',            'Amoxicare',   1, 2, '250mg',     'Capsule',   '100 Capsules', '8961001000031', 'Broad-spectrum antibiotic', 'active'),
(4,  'Omezol 20mg Capsules',         'Omeprazole',             'Omezol',      3, 2, '20mg',      'Capsule',   '30 Capsules',  '8961001000048', 'Proton pump inhibitor for acidity', 'active'),
(5,  'Cetrimed 10mg Tablets',        'Cetirizine',             'Cetrimed',    2, 1, '10mg',      'Tablet',    '100 Tablets',  '8961001000055', 'Antihistamine for allergy', 'active'),
(6,  'Metforin 500mg Tablets',       'Metformin',              'Metforin',    3, 1, '500mg',     'Tablet',    '100 Tablets',  '8961001000062', 'Antidiabetic (biguanide)', 'active'),
(7,  'Amlopress 5mg Tablets',        'Amlodipine',             'Amlopress',   1, 1, '5mg',       'Tablet',    '30 Tablets',   '8961001000079', 'Calcium channel blocker', 'active'),
(8,  'Losarkind 50mg Tablets',       'Losartan Potassium',     'Losarkind',   4, 1, '50mg',      'Tablet',    '30 Tablets',   '8961001000086', 'ARB for hypertension', 'active'),
(9,  'Cipromed 500mg Tablets',       'Ciprofloxacin',          'Cipromed',    2, 1, '500mg',     'Tablet',    '20 Tablets',   '8961001000093', 'Fluoroquinolone antibiotic', 'active'),
(10, 'Azimax 250mg Tablets',         'Azithromycin',           'Azimax',      3, 1, '250mg',     'Tablet',    '10 Tablets',   '8961001000109', 'Macrolide antibiotic', 'active'),
(11, 'Tusq-DX Syrup 120ml',          'Dextromethorphan',       'Tusq-DX',     4, 3, '15mg/5ml',  'Syrup',     '120ml Bottle', '8961001000116', 'Dry cough suppressant', 'active'),
(12, 'Novagesic Syrup 90ml',         'Paracetamol',            'Novagesic',   1, 3, '120mg/5ml', 'Syrup',     '90ml Bottle',  '8961001000123', 'Paediatric fever syrup', 'active'),
(13, 'Ceftrix 1g Injection',         'Ceftriaxone',            'Ceftrix',     2, 4, '1g',        'Injection', '1 Vial',       '8961001000130', 'Cephalosporin injection', 'active'),
(14, 'Diclogel 75mg Injection',      'Diclofenac Sodium',      'Diclogel',    3, 4, '75mg/3ml',  'Injection', '5 Ampoules',   '8961001000147', 'NSAID injection', 'active'),
(15, 'Tobracin Eye Drops 5ml',       'Tobramycin',             'Tobracin',    4, 5, '0.3%',      'Eye Drops', '5ml Bottle',   '8961001000154', 'Antibiotic eye drops', 'active'),
(16, 'Hydrocort 1% Cream 20g',       'Hydrocortisone',         'Hydrocort',   5, 6, '1%',        'Cream',     '20g Tube',     '8961001000161', 'Topical steroid cream', 'active'),
(17, 'Clotrimed 1% Cream 20g',       'Clotrimazole',           'Clotrimed',   5, 6, '1%',        'Cream',     '20g Tube',     '8961001000178', 'Antifungal cream', 'active'),
(18, 'Asthavent Inhaler 200D',       'Salbutamol',             'Asthavent',   4, 7, '100mcg/dose','Inhaler',   '200 Doses',    '8961001000185', 'Bronchodilator inhaler', 'active'),
(19, 'Vitaday Multivitamin',         'Multivitamin',           'Vitaday',     4, 8, '—',         'Tablet',    '60 Tablets',   '8961001000192', 'Daily multivitamin', 'active'),
(20, 'Oralyte ORS Sachets',          'Oral Rehydration Salts', 'Oralyte',     5, 8, '—',         'Sachet',    '50 Sachets',   '8961001000208', 'ORS for dehydration', 'active');

-- ============================================================================
-- MEDICINE BATCHES (ids 1-26 in insertion order)
-- Some batches expire within 90 days (alert demo), batch 13 is EXPIRED.
-- ============================================================================
INSERT IGNORE INTO `medicine_batches`
(`medicine_id`, `supplier_id`, `batch_number`, `manufacturing_date`, `expiry_date`,
 `purchase_price`, `sale_price`, `wholesale_price`, `tax`, `discount`,
 `quantity`, `minimum_stock`, `maximum_stock`, `rack_number`) VALUES
-- id 1-2 : Novagesic tablets (batch 2 expires in 45 days -> alert)
(1, 1, 'NV25001', CURDATE() - INTERVAL 190 DAY, CURDATE() + INTERVAL 540 DAY,  85.00, 120.00,  93.50, 0.00, 0.00, 480,  50, 1000, 'A-01'),
(1, 1, 'NV25002', CURDATE() - INTERVAL 320 DAY, CURDATE() + INTERVAL  45 DAY,  85.00, 120.00,  93.50, 0.00, 0.00, 200,  50, 1000, 'A-01'),
-- id 3 : Ibufort
(2, 2, 'MC25001', CURDATE() - INTERVAL 330 DAY, CURDATE() + INTERVAL 400 DAY, 140.00, 200.00, 154.00, 0.00, 0.00, 320,  40,  800, 'A-02'),
-- id 4-5 : Amoxicare (batch 5 expires in 75 days -> alert)
(3, 1, 'NV25003', CURDATE() - INTERVAL 130 DAY, CURDATE() + INTERVAL 600 DAY, 220.00, 310.00, 242.00, 0.00, 0.00, 250,  40,  600, 'A-03'),
(3, 1, 'NV25004', CURDATE() - INTERVAL 290 DAY, CURDATE() + INTERVAL  75 DAY, 220.00, 310.00, 242.00, 0.00, 0.00,  90,  40,  600, 'A-03'),
-- id 6 : Omezol
(4, 3, 'SX25001', CURDATE() - INTERVAL 230 DAY, CURDATE() + INTERVAL 500 DAY,  95.00, 140.00, 104.50, 0.00, 0.00, 410,  60,  900, 'A-04'),
-- id 7 : Cetrimed (LOW STOCK demo: 8 < min 50)
(5, 2, 'MC25002', CURDATE() - INTERVAL 350 DAY, CURDATE() + INTERVAL 380 DAY,  60.00,  95.00,  66.00, 0.00, 0.00,   8,  50,  500, 'A-05'),
-- id 8-9 : Metforin (batch 9 expires in 20 days -> alert)
(6, 3, 'SX25002', CURDATE() - INTERVAL  80 DAY, CURDATE() + INTERVAL 650 DAY, 110.00, 165.00, 121.00, 0.00, 0.00, 500,  60, 1200, 'A-06'),
(6, 3, 'SX25003', CURDATE() - INTERVAL 345 DAY, CURDATE() + INTERVAL  20 DAY, 110.00, 165.00, 121.00, 0.00, 0.00, 150,  60, 1200, 'A-06'),
-- id 10 : Amlopress
(7, 1, 'NV25005', CURDATE() - INTERVAL 250 DAY, CURDATE() + INTERVAL 480 DAY,  75.00, 115.00,  82.50, 0.00, 0.00, 280,  40,  700, 'A-07'),
-- id 11 : Losarkind
(8, 4, 'VP25001', CURDATE() - INTERVAL 210 DAY, CURDATE() + INTERVAL 520 DAY, 130.00, 195.00, 143.00, 0.00, 0.00, 190,  30,  500, 'A-08'),
-- id 12-13 : Cipromed (batch 13 EXPIRED 30 days ago -> alert)
(9, 2, 'MC25003', CURDATE() - INTERVAL 300 DAY, CURDATE() + INTERVAL 430 DAY, 180.00, 270.00, 198.00, 0.00, 0.00, 120,  30,  400, 'A-09'),
(9, 2, 'MC25004', CURDATE() - INTERVAL 700 DAY, CURDATE() - INTERVAL  30 DAY, 180.00, 270.00, 198.00, 0.00, 0.00,  60,  30,  400, 'A-09'),
-- id 14 : Azimax
(10, 2, 'SX25004', CURDATE() - INTERVAL 340 DAY, CURDATE() + INTERVAL 390 DAY, 260.00, 390.00, 286.00, 0.00, 0.00,  95,  20,  300, 'A-10'),
-- id 15 : Tusq-DX syrup
(11, 4, 'VP25002', CURDATE() - INTERVAL 370 DAY, CURDATE() + INTERVAL 360 DAY, 150.00, 230.00, 165.00, 0.00, 0.00, 140,  30,  400, 'B-01'),
-- id 16-17 : Novagesic syrup (batch 17 low stock + expires in 85 days)
(12, 1, 'NV25006', CURDATE() - INTERVAL 320 DAY, CURDATE() + INTERVAL 410 DAY,  95.00, 145.00, 104.50, 0.00, 0.00, 210,  40,  500, 'B-02'),
(12, 1, 'NV25007', CURDATE() - INTERVAL 280 DAY, CURDATE() + INTERVAL  85 DAY,  95.00, 145.00, 104.50, 0.00, 0.00,  12,  40,  500, 'B-02'),
-- id 18 : Ceftrix injection
(13, 2, 'MC25005', CURDATE() - INTERVAL 400 DAY, CURDATE() + INTERVAL 330 DAY, 320.00, 480.00, 352.00, 0.00, 0.00,  80,  20,  250, 'C-01'),
-- id 19 : Diclogel injection
(14, 3, 'SX25005', CURDATE() - INTERVAL 260 DAY, CURDATE() + INTERVAL 470 DAY, 210.00, 315.00, 231.00, 0.00, 0.00, 160,  30,  400, 'C-02'),
-- id 20 : Tobracin drops
(15, 4, 'VP25003', CURDATE() - INTERVAL 430 DAY, CURDATE() + INTERVAL 300 DAY, 240.00, 360.00, 264.00, 0.00, 0.00,  70,  20,  200, 'C-03'),
-- id 21 : Hydrocort cream
(16, 5, 'HH25001', CURDATE() - INTERVAL 170 DAY, CURDATE() + INTERVAL 560 DAY, 120.00, 185.00, 132.00, 0.00, 0.00, 130,  30,  350, 'D-01'),
-- id 22 : Clotrimed cream (LOW STOCK demo: 5 < min 30)
(17, 5, 'HH25002', CURDATE() - INTERVAL 120 DAY, CURDATE() + INTERVAL 610 DAY, 135.00, 205.00, 148.50, 0.00, 0.00,   5,  30,  300, 'D-02'),
-- id 23 : Asthavent inhaler
(18, 4, 'VP25004', CURDATE() - INTERVAL 290 DAY, CURDATE() + INTERVAL 440 DAY, 380.00, 570.00, 418.00, 0.00, 0.00,  90,  20,  250, 'D-03'),
-- id 24-25 : Vitaday (batch 25 expires in 90 days -> alert)
(19, 4, 'VP25005', CURDATE() - INTERVAL  30 DAY, CURDATE() + INTERVAL 700 DAY, 280.00, 420.00, 308.00, 0.00, 0.00, 350,  50,  800, 'E-01'),
(19, 4, 'VP25006', CURDATE() - INTERVAL 275 DAY, CURDATE() + INTERVAL  90 DAY, 280.00, 420.00, 308.00, 0.00, 0.00, 100,  50,  800, 'E-01'),
-- id 26 : Oralyte ORS
(20, 5, 'HH25003', CURDATE() - INTERVAL  10 DAY, CURDATE() + INTERVAL 720 DAY,  45.00,  70.00,  49.50, 0.00, 0.00, 600, 100, 1500, 'E-02');

-- ============================================================================
-- EXPENSE CATEGORIES + EXPENSES (fictional)
-- ============================================================================
INSERT IGNORE INTO `expense_categories` (`id`, `name`, `description`, `status`) VALUES
(1, 'Rent',      'Shop and warehouse rent',      'active'),
(2, 'Utilities', 'Electricity, gas, water, fuel', 'active'),
(3, 'Salaries',  'Staff salaries and advances',  'active'),
(4, 'Transport', 'Delivery and pickup transport','active'),
(5, 'Misc',      'Miscellaneous shop expenses',  'active');

INSERT IGNORE INTO `expenses`
(`id`, `expense_category_id`, `expense_date`, `amount`, `description`, `payment_method`, `created_by`) VALUES
(1,  1, CURDATE() - INTERVAL 28 DAY,  45000.00, 'Shop monthly rent - September',        'cash', 2),
(2,  2, CURDATE() - INTERVAL 25 DAY,   8500.00, 'Electricity bill - September',         'bank', 2),
(3,  3, CURDATE() - INTERVAL 30 DAY, 120000.00, 'Staff salaries - September',           'bank', 2),
(4,  4, CURDATE() - INTERVAL 20 DAY,   3500.00, 'Medicine delivery transport',          'cash', 3),
(5,  5, CURDATE() - INTERVAL 15 DAY,   2200.00, 'Cleaning and hygiene supplies',        'cash', 3),
(6,  2, CURDATE() - INTERVAL 12 DAY,  12000.00, 'Generator diesel',                     'cash', 3),
(7,  4, CURDATE() - INTERVAL  9 DAY,   4800.00, 'Supplier pickup - Shah Alam Market',   'cash', 6),
(8,  5, CURDATE() - INTERVAL  6 DAY,   1500.00, 'Stationery and bill printing',         'cash', 5),
(9,  2, CURDATE() - INTERVAL  3 DAY,   6800.00, 'Internet and phone bills',             'bank', 2),
(10, 3, CURDATE() - INTERVAL  2 DAY,  25000.00, 'Advance salary - cashier',             'cash', 2);

-- ============================================================================
-- PURCHASES (10, across the last 60 days)
-- ============================================================================
INSERT IGNORE INTO `purchases`
(`id`, `invoice_number`, `supplier_id`, `purchase_date`, `subtotal`, `tax_amount`,
 `discount_amount`, `grand_total`, `paid_amount`, `due_amount`, `payment_status`,
 `notes`, `created_by`) VALUES
(1,  'PO-2026-0001', 1, CURDATE() - INTERVAL 58 DAY, 39000.00, 0.00, 1000.00, 38000.00, 38000.00,     0.00, 'paid',    'Monthly antibiotic restock', 6),
(2,  'PO-2026-0002', 2, CURDATE() - INTERVAL 52 DAY, 32800.00, 0.00,  800.00, 32000.00, 20000.00, 12000.00, 'partial', 'Injectables order',          6),
(3,  'PO-2026-0003', 3, CURDATE() - INTERVAL 47 DAY, 52000.00, 0.00, 2000.00, 50000.00,     0.00, 50000.00, 'unpaid',  'Chronic-care medicines',     3),
(4,  'PO-2026-0004', 1, CURDATE() - INTERVAL 41 DAY, 27000.00, 0.00,    0.00, 27000.00, 27000.00,     0.00, 'paid',    'Pain & allergy restock',     6),
(5,  'PO-2026-0005', 4, CURDATE() - INTERVAL 35 DAY, 74000.00, 0.00, 4000.00, 70000.00, 50000.00, 20000.00, 'partial', 'Supplements bulk order',     3),
(6,  'PO-2026-0006', 5, CURDATE() - INTERVAL 29 DAY, 28600.00, 0.00,    0.00, 28600.00, 28600.00,     0.00, 'paid',    'Respiratory & eye care',     6),
(7,  'PO-2026-0007', 2, CURDATE() - INTERVAL 24 DAY, 30000.00, 0.00,    0.00, 30000.00, 30000.00,     0.00, 'paid',    'Antibiotics top-up',         6),
(8,  'PO-2026-0008', 3, CURDATE() - INTERVAL 18 DAY, 24250.00, 0.00,    0.00, 24250.00, 15000.00,  9250.00, 'partial', 'Cardiac range order',        3),
(9,  'PO-2026-0009', 1, CURDATE() - INTERVAL 11 DAY, 23400.00, 0.00,  400.00, 23000.00, 23000.00,     0.00, 'paid',    'Syrups for flu season',      6),
(10, 'PO-2026-0010', 4, CURDATE() - INTERVAL  4 DAY, 17700.00, 0.00,    0.00, 17700.00,     0.00, 17700.00, 'unpaid',  'Dermatology range',          3);

INSERT IGNORE INTO `purchase_items`
(`purchase_id`, `medicine_id`, `batch_id`, `batch_number`, `expiry_date`, `quantity`, `purchase_price`, `tax`, `discount`, `total`) VALUES
-- PO-1
(1, 3, NULL, 'NV25003', CURDATE() + INTERVAL 600 DAY, 100, 220.00, 0.00, 0.00, 22000.00),
(1, 1, NULL, 'NV25001', CURDATE() + INTERVAL 540 DAY, 200,  85.00, 0.00, 0.00, 17000.00),
-- PO-2
(2, 13, NULL, 'MC25005', CURDATE() + INTERVAL 330 DAY, 50, 320.00, 0.00, 0.00, 16000.00),
(2, 14, NULL, 'SX25005', CURDATE() + INTERVAL 470 DAY, 80, 210.00, 0.00, 0.00, 16800.00),
-- PO-3
(3, 6, NULL, 'SX25002', CURDATE() + INTERVAL 650 DAY, 300, 110.00, 0.00, 0.00, 33000.00),
(3, 4, NULL, 'SX25001', CURDATE() + INTERVAL 500 DAY, 200,  95.00, 0.00, 0.00, 19000.00),
-- PO-4
(4, 2, NULL, 'MC25001', CURDATE() + INTERVAL 400 DAY, 150, 140.00, 0.00, 0.00, 21000.00),
(4, 5, NULL, 'MC25002', CURDATE() + INTERVAL 380 DAY, 100,  60.00, 0.00, 0.00,  6000.00),
-- PO-5
(5, 19, NULL, 'VP25005', CURDATE() + INTERVAL 700 DAY, 200, 280.00, 0.00, 0.00, 56000.00),
(5, 20, NULL, 'HH25003', CURDATE() + INTERVAL 720 DAY, 400,  45.00, 0.00, 0.00, 18000.00),
-- PO-6
(6, 18, NULL, 'VP25004', CURDATE() + INTERVAL 440 DAY, 50, 380.00, 0.00, 0.00, 19000.00),
(6, 15, NULL, 'VP25003', CURDATE() + INTERVAL 300 DAY, 40, 240.00, 0.00, 0.00,  9600.00),
-- PO-7
(7, 9,  NULL, 'MC25003', CURDATE() + INTERVAL 430 DAY, 80, 180.00, 0.00, 0.00, 14400.00),
(7, 10, NULL, 'SX25004', CURDATE() + INTERVAL 390 DAY, 60, 260.00, 0.00, 0.00, 15600.00),
-- PO-8
(8, 8, NULL, 'VP25001', CURDATE() + INTERVAL 520 DAY, 100, 130.00, 0.00, 0.00, 13000.00),
(8, 7, NULL, 'NV25005', CURDATE() + INTERVAL 480 DAY, 150,  75.00, 0.00, 0.00, 11250.00),
-- PO-9
(9, 12, NULL, 'NV25006', CURDATE() + INTERVAL 410 DAY, 120,  95.00, 0.00, 0.00, 11400.00),
(9, 11, NULL, 'VP25002', CURDATE() + INTERVAL 360 DAY,  80, 150.00, 0.00, 0.00, 12000.00),
-- PO-10
(10, 16, NULL, 'HH25001', CURDATE() + INTERVAL 560 DAY, 80, 120.00, 0.00, 0.00, 9600.00),
(10, 17, NULL, 'HH25002', CURDATE() + INTERVAL 610 DAY, 60, 135.00, 0.00, 0.00, 8100.00);

INSERT IGNORE INTO `purchase_payments`
(`purchase_id`, `payment_date`, `amount`, `payment_method`, `reference`, `created_by`) VALUES
(1, CURDATE() - INTERVAL 58 DAY, 38000.00, 'cash',         'CASH-RCPT-101', 6),
(2, CURDATE() - INTERVAL 52 DAY, 20000.00, 'bank',         'TRF-88231',    6),
(4, CURDATE() - INTERVAL 41 DAY, 27000.00, 'cash',         'CASH-RCPT-102', 6),
(5, CURDATE() - INTERVAL 35 DAY, 50000.00, 'cash',         'CASH-RCPT-103', 3),
(6, CURDATE() - INTERVAL 29 DAY, 28600.00, 'card',         'POS-55671',    6),
(7, CURDATE() - INTERVAL 24 DAY, 30000.00, 'mobile_wallet','EASYP-99120',  6),
(8, CURDATE() - INTERVAL 18 DAY, 15000.00, 'cash',         'CASH-RCPT-104', 3),
(9, CURDATE() - INTERVAL 11 DAY, 23000.00, 'cash',         'CASH-RCPT-105', 6);

-- ============================================================================
-- SALES (20, across the last 30 days; walk-ins have customer_id NULL)
-- ============================================================================
INSERT IGNORE INTO `sales`
(`id`, `invoice_number`, `customer_id`, `sale_date`, `subtotal`, `tax_amount`,
 `discount_amount`, `grand_total`, `paid_amount`, `due_amount`, `payment_status`,
 `payment_method`, `notes`, `created_by`, `is_offline`) VALUES
(1,  'INV-2026-0001',  1,   NOW() - INTERVAL 29 DAY,  3350.00, 0.00,  50.00,  3300.00,  3300.00,    0.00, 'paid',    'cash',          'Regular customer', 5, 0),
(2,  'INV-2026-0002',  2,   NOW() - INTERVAL 28 DAY,  4200.00, 0.00,   0.00,  4200.00,  4200.00,    0.00, 'paid',    'cash',          NULL,               5, 0),
(3,  'INV-2026-0003',  NULL,NOW() - INTERVAL 27 DAY,  2000.00, 0.00,   0.00,  2000.00,  2000.00,    0.00, 'paid',    'cash',          'Walk-in',          5, 1),
(4,  'INV-2026-0004',  3,   NOW() - INTERVAL 26 DAY, 13350.00, 0.00, 350.00, 13000.00,  8000.00, 5000.00, 'partial', 'split',         'Chronic medicines',4, 0),
(5,  'INV-2026-0005',  4,   NOW() - INTERVAL 25 DAY,  6200.00, 0.00,   0.00,  6200.00,  6200.00,    0.00, 'paid',    'card',          NULL,               5, 0),
(6,  'INV-2026-0006',  5,   NOW() - INTERVAL 24 DAY,   750.00, 0.00,   0.00,   750.00,   750.00,    0.00, 'paid',    'cash',          NULL,               5, 0),
(7,  'INV-2026-0007',  NULL,NOW() - INTERVAL 22 DAY,  1900.00, 0.00,   0.00,  1900.00,  1900.00,    0.00, 'paid',    'cash',          'Walk-in',          5, 1),
(8,  'INV-2026-0008',  6,   NOW() - INTERVAL 21 DAY,  5850.00, 0.00,   0.00,  5850.00,  5850.00,    0.00, 'paid',    'bank',          NULL,               4, 0),
(9,  'INV-2026-0009',  7,   NOW() - INTERVAL 20 DAY,  2340.00, 0.00,   0.00,  2340.00,  2340.00,    0.00, 'paid',    'cash',          NULL,               5, 0),
(10, 'INV-2026-0010',  8,   NOW() - INTERVAL 18 DAY,  1540.00, 0.00,   0.00,  1540.00,  1540.00,    0.00, 'paid',    'mobile_wallet', NULL,               5, 0),
(11, 'INV-2026-0011',  NULL,NOW() - INTERVAL 17 DAY,   570.00, 0.00,   0.00,   570.00,   570.00,    0.00, 'paid',    'cash',          'Walk-in',          4, 0),
(12, 'INV-2026-0012',  9,   NOW() - INTERVAL 15 DAY,  2700.00, 0.00,   0.00,  2700.00,     0.00, 2700.00, 'unpaid',  'credit',        'Credit sale - 15 days', 4, 0),
(13, 'INV-2026-0013', 10,   NOW() - INTERVAL 14 DAY,   575.00, 0.00,   0.00,   575.00,   575.00,    0.00, 'paid',    'cash',          NULL,               5, 0),
(14, 'INV-2026-0014',  1,   NOW() - INTERVAL 12 DAY,  3600.00, 0.00,   0.00,  3600.00,  3600.00,    0.00, 'paid',    'card',          NULL,               5, 0),
(15, 'INV-2026-0015',  2,   NOW() - INTERVAL 11 DAY,   360.00, 0.00,   0.00,   360.00,   360.00,    0.00, 'paid',    'cash',          NULL,               4, 0),
(16, 'INV-2026-0016',  NULL,NOW() - INTERVAL  9 DAY,   960.00, 0.00,   0.00,   960.00,   960.00,    0.00, 'paid',    'cash',          'Walk-in',          5, 0),
(17, 'INV-2026-0017',  3,   NOW() - INTERVAL  8 DAY,  9900.00, 0.00,   0.00,  9900.00,  9900.00,    0.00, 'paid',    'bank',          NULL,               4, 0),
(18, 'INV-2026-0018',  4,   NOW() - INTERVAL  6 DAY,  4950.00, 0.00,  50.00,  4900.00,  4900.00,    0.00, 'paid',    'mobile_wallet', NULL,               5, 0),
(19, 'INV-2026-0019',  5,   NOW() - INTERVAL  3 DAY,  9300.00, 0.00,   0.00,  9300.00,  5000.00, 4300.00, 'partial', 'split',         'Balance on next visit', 4, 0),
(20, 'INV-2026-0020',  NULL,NOW() - INTERVAL  1 DAY,   495.00, 0.00,   0.00,   495.00,   495.00,    0.00, 'paid',    'cash',          'Walk-in',          5, 0);

-- sale_items: (sale_id, medicine_id, batch_id, quantity, sale_price, discount, tax, total)
-- batch ids follow the insertion order of medicine_batches (1-26)
INSERT IGNORE INTO `sale_items`
(`sale_id`, `medicine_id`, `batch_id`, `quantity`, `sale_price`, `discount`, `tax`, `total`) VALUES
(1,  1,  1, 20, 120.00, 0.00, 0.00, 2400.00),
(1,  5,  7, 10,  95.00, 0.00, 0.00,  950.00),
(2,  4,  6, 30, 140.00, 0.00, 0.00, 4200.00),
(3,  2,  3, 10, 200.00, 0.00, 0.00, 2000.00),
(4,  6,  8, 60, 165.00, 0.00, 0.00, 9900.00),
(4,  7, 10, 30, 115.00, 0.00, 0.00, 3450.00),
(5,  3,  4, 20, 310.00, 0.00, 0.00, 6200.00),
(6, 11, 15,  2, 230.00, 0.00, 0.00,  460.00),
(6, 12, 16,  2, 145.00, 0.00, 0.00,  290.00),
(7,  5,  7, 20,  95.00, 0.00, 0.00, 1900.00),
(8,  8, 11, 30, 195.00, 0.00, 0.00, 5850.00),
(9, 10, 14,  6, 390.00, 0.00, 0.00, 2340.00),
(10, 19, 24,  2, 420.00, 0.00, 0.00,  840.00),
(10, 20, 26, 10,  70.00, 0.00, 0.00,  700.00),
(11, 18, 23,  1, 570.00, 0.00, 0.00,  570.00),
(12,  9, 12, 10, 270.00, 0.00, 0.00, 2700.00),
(13, 16, 21,  2, 185.00, 0.00, 0.00,  370.00),
(13, 17, 22,  1, 205.00, 0.00, 0.00,  205.00),
(14,  1,  1, 30, 120.00, 0.00, 0.00, 3600.00),
(15, 15, 20,  1, 360.00, 0.00, 0.00,  360.00),
(16, 13, 18,  2, 480.00, 0.00, 0.00,  960.00),
(17,  6,  8, 60, 165.00, 0.00, 0.00, 9900.00),
(18,  2,  3, 20, 200.00, 0.00, 0.00, 4000.00),
(18,  5,  7, 10,  95.00, 0.00, 0.00,  950.00),
(19,  3,  4, 30, 310.00, 0.00, 0.00, 9300.00),
(20, 20, 26,  5,  70.00, 0.00, 0.00,  350.00),
(20, 12, 16,  1, 145.00, 0.00, 0.00,  145.00);

INSERT IGNORE INTO `sale_payments`
(`sale_id`, `payment_date`, `amount`, `payment_method`, `reference`, `created_by`) VALUES
(1,  CURDATE() - INTERVAL 29 DAY,  3300.00, 'cash',          'CASH-S-0001', 5),
(2,  CURDATE() - INTERVAL 28 DAY,  4200.00, 'cash',          'CASH-S-0002', 5),
(3,  CURDATE() - INTERVAL 27 DAY,  2000.00, 'cash',          'CASH-S-0003', 5),
(4,  CURDATE() - INTERVAL 26 DAY,  5000.00, 'cash',          'CASH-S-0004', 4),
(4,  CURDATE() - INTERVAL 26 DAY,  3000.00, 'card',          'POS-77120',  4),
(5,  CURDATE() - INTERVAL 25 DAY,  6200.00, 'card',          'POS-77121',  5),
(6,  CURDATE() - INTERVAL 24 DAY,   750.00, 'cash',          'CASH-S-0006', 5),
(7,  CURDATE() - INTERVAL 22 DAY,  1900.00, 'cash',          'CASH-S-0007', 5),
(8,  CURDATE() - INTERVAL 21 DAY,  5850.00, 'bank',          'TRF-90112',  4),
(9,  CURDATE() - INTERVAL 20 DAY,  2340.00, 'cash',          'CASH-S-0009', 5),
(10, CURDATE() - INTERVAL 18 DAY,  1540.00, 'mobile_wallet', 'EASYP-33110',5),
(11, CURDATE() - INTERVAL 17 DAY,   570.00, 'cash',          'CASH-S-0011', 4),
(13, CURDATE() - INTERVAL 14 DAY,   575.00, 'cash',          'CASH-S-0013', 5),
(14, CURDATE() - INTERVAL 12 DAY,  3600.00, 'card',          'POS-77130',  5),
(15, CURDATE() - INTERVAL 11 DAY,   360.00, 'cash',          'CASH-S-0015', 4),
(16, CURDATE() - INTERVAL  9 DAY,   960.00, 'cash',          'CASH-S-0016', 5),
(17, CURDATE() - INTERVAL  8 DAY,  9900.00, 'bank',          'TRF-90145',  4),
(18, CURDATE() - INTERVAL  6 DAY,  4900.00, 'mobile_wallet', 'EASYP-33201',5),
(19, CURDATE() - INTERVAL  3 DAY,  5000.00, 'cash',          'CASH-S-0019', 4),
(20, CURDATE() - INTERVAL  1 DAY,   495.00, 'cash',          'CASH-S-0020', 5);

-- ============================================================================
-- RETURNS (demo: one purchase return, one sales return)
-- ============================================================================
INSERT IGNORE INTO `purchase_returns`
(`id`, `return_number`, `purchase_id`, `return_date`, `total_amount`, `reason`, `created_by`) VALUES
(1, 'PRET-2026-0001', 2, CURDATE() - INTERVAL 40 DAY, 3200.00, 'Damaged vials on arrival', 6);

-- purchase_items ids follow insertion order: item 3 = PO-2 Ceftrix 50 x 320
INSERT IGNORE INTO `purchase_return_items`
(`return_id`, `purchase_item_id`, `quantity`, `amount`) VALUES
(1, 3, 10, 3200.00);

INSERT IGNORE INTO `sales_returns`
(`id`, `return_number`, `sale_id`, `return_date`, `total_amount`, `reason`, `created_by`) VALUES
(1, 'RET-2026-0001', 6, CURDATE() - INTERVAL 20 DAY, 230.00, 'Customer requested different flavour', 4);

-- sale_items ids follow insertion order: item 8 = sale 6 Tusq-DX 2 x 230
INSERT IGNORE INTO `sales_return_items`
(`return_id`, `sale_item_id`, `quantity`, `amount`) VALUES
(1, 8, 1, 230.00);

-- ============================================================================
-- STOCK TRANSACTIONS (immutable movement ledger)
-- ============================================================================
INSERT IGNORE INTO `stock_transactions`
(`medicine_id`, `batch_id`, `transaction_type`, `quantity_change`, `quantity_after`,
 `reference_type`, `reference_id`, `notes`, `created_by`) VALUES
(1,  1,  'opening',      480, 480, 'opening',      NULL, 'Opening stock take', 1),
(3,  4,  'purchase',     100, 350, 'purchase',     1,    'PO-2026-0001 received', 6),
(1,  1,  'sale',         -20, 460, 'sale',         1,    'INV-2026-0001', 5),
(5,  7,  'sale',         -10,   8, 'sale',         1,    'INV-2026-0001', 5),
(9,  13, 'damaged',      -60,   0, 'batch',        13,   'Expired batch quarantined', 6),
(1,  1,  'transfer_out', -50, 430, 'transfer',     1,    'Main Store -> Counter', 6),
(1,  1,  'transfer_in',   50, 480, 'transfer',     1,    'Counter refill received', 6),
(5,  7,  'adjustment',    50,  58, 'adjustment',   1,    'Cycle count correction', 6),
(11, 15, 'return_in',      1, 141, 'sales_return', 1,    'RET-2026-0001 restocked', 4);

INSERT IGNORE INTO `stock_adjustments`
(`id`, `adjustment_number`, `medicine_id`, `batch_id`, `adjustment_type`, `quantity`, `reason`, `adjustment_date`, `created_by`) VALUES
(1, 'ADJ-2026-0001', 5, 7, 'increase', 50, 'Cycle count correction - found unrecorded stock', CURDATE() - INTERVAL 5 DAY, 6);

INSERT IGNORE INTO `stock_transfers`
(`id`, `transfer_number`, `from_location`, `to_location`, `medicine_id`, `batch_id`, `quantity`, `transfer_date`, `notes`, `created_by`, `status`) VALUES
(1, 'TRF-2026-0001', 'Main Store', 'Counter', 1, 1, 50, CURDATE() - INTERVAL 7 DAY, 'Counter refill', 6, 'completed');

INSERT IGNORE INTO `inventory_logs`
(`medicine_id`, `batch_id`, `action`, `details`, `created_by`) VALUES
(1, 1, 'batch.created',  '{"batch_number": "NV25001", "quantity": 480}', 6),
(5, 7, 'low_stock.alert', '{"quantity": 8, "minimum_stock": 50}', NULL);

-- ============================================================================
-- PRESCRIPTIONS
-- ============================================================================
INSERT IGNORE INTO `prescriptions`
(`id`, `prescription_number`, `patient_name`, `patient_phone`, `patient_age`, `patient_gender`,
 `doctor_name`, `doctor_phone`, `clinic`, `diagnosis`, `notes`, `sale_id`, `created_by`) VALUES
(1, 'RX-2026-0001', 'Ahmed Khan',  '0300-0000001', 45, 'male',
 'Dr. Sarah Ahmed', '0301-2223344', 'CityCare Clinic, Gulberg', 'Acute bacterial sinusitis', 'Review after 7 days', 5, 4),
(2, 'RX-2026-0002', 'Fatima Raza', '0300-0000002', 32, 'female',
 'Dr. Kamran Ali',  '0301-5556677', 'MediServe Clinic, Model Town', 'GERD', NULL, 2, 4);

INSERT IGNORE INTO `prescription_items`
(`prescription_id`, `medicine_name`, `dosage`, `frequency`, `duration`, `notes`) VALUES
(1, 'Amoxicare 250mg',  '1 capsule', 'Three times daily',   '7 days',  'Take after meals'),
(1, 'Novagesic 500mg',  '1 tablet',  'As needed for pain',  '5 days',  NULL),
(2, 'Omezol 20mg',      '1 capsule', 'Once daily',          '14 days', 'Take before breakfast');

-- ============================================================================
-- PAYMENTS LEDGER (money in/out summary)
-- ============================================================================
INSERT IGNORE INTO `payments`
(`related_type`, `related_id`, `amount`, `payment_method`, `payment_date`, `direction`, `reference`, `created_by`) VALUES
('sale',     1,  3300.00, 'cash', CURDATE() - INTERVAL 29 DAY, 'in',  'INV-2026-0001', 5),
('sale',     4,  8000.00, 'cash', CURDATE() - INTERVAL 26 DAY, 'in',  'INV-2026-0004', 4),
('sale',    17,  9900.00, 'bank', CURDATE() - INTERVAL  8 DAY, 'in',  'INV-2026-0017', 4),
('customer', 3,  9900.00, 'bank', CURDATE() - INTERVAL  8 DAY, 'in',  'CUST-003-ADV',  4),
('purchase', 1, 38000.00, 'cash', CURDATE() - INTERVAL 58 DAY, 'out', 'PO-2026-0001',  6),
('purchase', 2, 20000.00, 'bank', CURDATE() - INTERVAL 52 DAY, 'out', 'PO-2026-0002',  6),
('expense',  1, 45000.00, 'cash', CURDATE() - INTERVAL 28 DAY, 'out', 'EXP-Rent-Sep',  2);

-- ============================================================================
-- NOTIFICATIONS
-- ============================================================================
INSERT IGNORE INTO `notifications`
(`user_id`, `type`, `title`, `message`, `link`, `is_read`) VALUES
(NULL, 'low_stock', 'Low stock: Cetrimed 10mg Tablets',
 'Only 8 units left in stock (minimum 50). Please reorder soon.',
 '/inventory?filter=low_stock', 0),
(NULL, 'expiry', '5 batches expiring within 90 days',
 'Metforin batch SX25003 expires in ~20 days; Cipromed batch MC25004 (60 units) has already expired and needs quarantine.',
 '/inventory?filter=expiring', 0),
(2, 'supplier_due', 'Supplier dues pending: Rs 108,950',
 'MediServe Rs 59,250 | PakHealth Rs 37,700 | Al-Shifa Rs 12,000 outstanding.',
 '/purchases?filter=due', 0),
(3, 'customer_due', 'Customer dues pending: Rs 12,000',
 'Muhammad Ali Rs 5,000 | Kamran Iqbal Rs 2,700 | Bilal Hussain Rs 4,300 outstanding.',
 '/sales?filter=due', 0),
(NULL, 'daily_summary', 'Daily summary',
 'Yesterday: 1 sale, Rs 495 revenue, 2 units dispensed. 3 low-stock alerts active.',
 '/reports/daily', 0);

-- ============================================================================
-- AUDIT LOGS
-- ============================================================================
INSERT IGNORE INTO `audit_logs`
(`user_id`, `action`, `module`, `record_id`, `old_data`, `new_data`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 'user.login',      'auth',      1,   NULL, '{"email": "admin@pharmacy.local", "role": "Super Admin"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 1 DAY),
(3, 'medicine.create', 'medicines', 20,  NULL, '{"medicine_name": "Oralyte ORS Sachets"}',                  '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 10 DAY),
(5, 'sale.create',     'sales',     20,  NULL, '{"invoice_number": "INV-2026-0020", "grand_total": "495.00"}','127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 1 DAY),
(6, 'purchase.create', 'purchases', 10,  NULL, '{"invoice_number": "PO-2026-0010", "grand_total": "17700.00"}','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 4 DAY),
(2, 'settings.update', 'settings',  NULL, '{"currency": "PKR"}', '{"currency": "PKR"}',                     '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 15 DAY);

-- ============================================================================
-- SETTINGS
-- ============================================================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('pharmacy_name',           'CityCare Pharmacy',                          'general'),
('tagline',                 'Your Health, Our Care',                      'general'),
('address',                 'Main Boulevard, Gulberg III, Lahore, Pakistan','general'),
('phone',                   '042-35771234',                               'general'),
('email',                   'info@citycare-pharmacy.local',                'general'),
('currency',                'PKR',                                        'localization'),
('currency_symbol',         'Rs',                                         'localization'),
('timezone',                'Asia/Karachi',                               'localization'),
('date_format',             'd-m-Y',                                      'localization'),
('invoice_prefix',          'INV-',                                       'invoicing'),
('purchase_invoice_prefix', 'PO-',                                        'invoicing'),
('return_prefix',           'RET-',                                       'invoicing'),
('low_stock_threshold',     '50',                                         'inventory'),
('near_expiry_days',        '90',                                         'inventory'),
('tax_rate',                '0',                                          'invoicing'),
('items_per_page',          '25',                                         'general'),
('enable_reward_points',    '1',                                          'loyalty');

-- ============================================================================
-- HR DEMO: attendance, salaries, leaves
-- ============================================================================
INSERT IGNORE INTO `attendance`
(`employee_id`, `attendance_date`, `status`, `check_in`, `check_out`) VALUES
(1, '2026-10-01', 'present', '08:55:00', '18:05:00'),
(2, '2026-10-01', 'present', '09:00:00', '18:00:00'),
(3, '2026-10-01', 'present', '08:50:00', '18:10:00'),
(4, '2026-10-01', 'present', '09:05:00', '18:00:00'),
(1, '2026-10-02', 'present', '08:57:00', '18:02:00'),
(2, '2026-10-02', 'present', '09:02:00', '18:00:00'),
(3, '2026-10-02', 'present', '08:52:00', '18:08:00'),
(4, '2026-10-02', 'leave',   NULL,       NULL),
(1, '2026-10-03', 'present', '08:54:00', NULL),
(2, '2026-10-03', 'present', '09:01:00', NULL),
(3, '2026-10-03', 'absent',  NULL,       NULL),
(4, '2026-10-03', 'leave',   NULL,       NULL);

INSERT IGNORE INTO `employee_salaries`
(`employee_id`, `month`, `basic_salary`, `allowances`, `deductions`, `net_salary`, `paid_amount`, `payment_status`, `payment_date`) VALUES
(1, '2026-10', 65000.00, 5000.00,    0.00, 70000.00, 70000.00, 'paid',   '2026-10-01'),
(2, '2026-10', 45000.00, 2000.00,    0.00, 47000.00, 47000.00, 'paid',   '2026-10-01'),
(3, '2026-10', 50000.00, 3000.00, 1000.00, 52000.00, 52000.00, 'paid',   '2026-10-01'),
(4, '2026-10', 35000.00, 2000.00,    0.00, 37000.00,     0.00, 'unpaid', NULL);

INSERT IGNORE INTO `employee_leaves`
(`employee_id`, `leave_type`, `start_date`, `end_date`, `reason`, `status`, `approved_by`) VALUES
(4, 'Sick', '2026-10-05', '2026-10-06', 'Down with flu, advised rest', 'pending', NULL);

-- ============================================================================
-- LABELS + ATTACHMENTS (small demo rows)
-- ============================================================================
INSERT IGNORE INTO `barcode_labels`
(`medicine_id`, `batch_id`, `barcode_value`, `format`, `quantity`, `created_by`) VALUES
(1, 1, '8961001000017', 'EAN-13', 50, 6),
(3, 4, '8961001000031', 'EAN-13', 50, 6);

INSERT IGNORE INTO `qr_labels`
(`medicine_id`, `batch_id`, `qr_value`, `quantity`, `created_by`) VALUES
(1, 1, 'https://pharmacy.local/m/1/b/NV25001', 20, 6);

INSERT IGNORE INTO `attachments`
(`related_type`, `related_id`, `file_path`, `file_name`, `mime_type`, `file_size`, `uploaded_by`) VALUES
('prescription', 1, 'uploads/prescriptions/rx-2026-0001.jpg', 'rx-2026-0001.jpg', 'image/jpeg', 184320, 4);

-- ============================================================================
SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
-- END OF SEED
-- ============================================================================
