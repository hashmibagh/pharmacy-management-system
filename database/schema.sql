-- ============================================================================
-- Pharmacy Management System - Database Schema (MySQL 8+)
-- ----------------------------------------------------------------------------
-- Charset : utf8mb4 | Engine: InnoDB
-- Conventions:
--   * Every table has `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `created_at` / `updated_at` timestamps, and `deleted_at` (soft delete)
--     where the domain calls for it.
--   * Money is DECIMAL(12,2) everywhere.
--   * FKs: ON DELETE RESTRICT, or ON DELETE SET NULL where the column is
--     nullable. All FKs use ON UPDATE CASCADE.
--   * Idempotent: every CREATE TABLE uses IF NOT EXISTS.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `pharmacy_management`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `pharmacy_management`;

SET NAMES utf8mb4;

-- ============================================================================
-- AUTH & ACCESS CONTROL
-- ============================================================================

-- Roles: Super Admin, Owner, Manager, Pharmacist, Cashier, Store Keeper
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions, grouped by module (dashboard, medicines, inventory, ...)
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `module`      VARCHAR(50) NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_name` (`name`),
  KEY `idx_permissions_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System users (staff logins)
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100) NOT NULL,
  `email`          VARCHAR(150) NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `phone`          VARCHAR(30) NULL,
  `avatar`         VARCHAR(255) NULL,
  `role_id`        INT UNSIGNED NOT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `last_login_at`  TIMESTAMP NULL DEFAULT NULL,
  `remember_token` VARCHAR(100) NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role <-> permission pivot
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permissions` (`role_id`, `permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `permissions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user permission overrides (granted=0 acts as an explicit deny)
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `granted`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_permissions` (`user_id`, `permission_id`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_up_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `permissions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extended staff profile (one row per user)
CREATE TABLE IF NOT EXISTS `profiles` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  `full_name`         VARCHAR(150) NULL,
  `cnic`              VARCHAR(20) NULL,
  `address`           TEXT NULL,
  `emergency_contact` VARCHAR(30) NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_profiles_user_id` (`user_id`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CATALOG: categories, manufacturers, suppliers, customers, employees
-- ============================================================================

-- Medicine categories (Tablets, Syrups, ...)
CREATE TABLE IF NOT EXISTS `medicine_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_medicine_categories_name` (`name`),
  KEY `idx_medicine_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manufacturers / pharma companies
CREATE TABLE IF NOT EXISTS `manufacturers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(150) NOT NULL,
  `country`      VARCHAR(100) NULL,
  `contact_info` VARCHAR(255) NULL,
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manufacturers_name` (`name`),
  KEY `idx_manufacturers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suppliers (purchase sources)
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(150) NOT NULL,
  `contact_person`  VARCHAR(100) NULL,
  `phone`           VARCHAR(30) NULL,
  `whatsapp`        VARCHAR(30) NULL,
  `email`           VARCHAR(150) NULL,
  `address`         TEXT NULL,
  `tax_number`      VARCHAR(50) NULL,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`      TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_suppliers_phone` (`phone`),
  KEY `idx_suppliers_name` (`name`),
  KEY `idx_suppliers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers (walk-in + registered)
CREATE TABLE IF NOT EXISTS `customers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(150) NOT NULL,
  `phone`           VARCHAR(30) NULL,
  `whatsapp`        VARCHAR(30) NULL,
  `address`         TEXT NULL,
  `cnic`            VARCHAR(20) NULL,
  `medical_notes`   TEXT NULL,
  `credit_limit`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reward_points`   INT NOT NULL DEFAULT 0,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`      TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employees (HR records; separate from login `users`)
CREATE TABLE IF NOT EXISTS `employees` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `phone`       VARCHAR(30) NULL,
  `email`       VARCHAR(150) NULL,
  `address`     TEXT NULL,
  `cnic`        VARCHAR(20) NULL,
  `designation` VARCHAR(100) NULL,
  `joining_date` DATE NULL,
  `salary`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `commission`  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `photo`       VARCHAR(255) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_employees_name` (`name`),
  KEY `idx_employees_phone` (`phone`),
  KEY `idx_employees_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense categories (Rent, Utilities, Salaries, ...)
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expense_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MEDICINES & BATCHES (batch-level inventory)
-- ============================================================================

-- Medicine master data
CREATE TABLE IF NOT EXISTS `medicines` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_name`   VARCHAR(150) NOT NULL,
  `generic_name`    VARCHAR(150) NULL,
  `brand_name`      VARCHAR(150) NULL,
  `manufacturer_id` INT UNSIGNED NULL,
  `category_id`     INT UNSIGNED NULL,
  `strength`        VARCHAR(50) NULL,
  `dosage_form`     VARCHAR(50) NULL,
  `packing`         VARCHAR(50) NULL,
  `barcode`         VARCHAR(50) NULL,
  `qr_code`         VARCHAR(255) NULL,
  `description`     TEXT NULL,
  `image`           VARCHAR(255) NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`      TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_medicines_barcode` (`barcode`),
  KEY `idx_medicines_barcode` (`barcode`),
  KEY `idx_medicines_name` (`medicine_name`),
  KEY `idx_medicines_generic` (`generic_name`),
  KEY `idx_medicines_category` (`category_id`),
  KEY `idx_medicines_manufacturer` (`manufacturer_id`),
  KEY `idx_medicines_status` (`status`),
  CONSTRAINT `fk_medicines_manufacturer` FOREIGN KEY (`manufacturer_id`)
    REFERENCES `manufacturers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_medicines_category` FOREIGN KEY (`category_id`)
    REFERENCES `medicine_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock is tracked per batch (expiry, pricing, rack)
CREATE TABLE IF NOT EXISTS `medicine_batches` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_id`        INT UNSIGNED NOT NULL,
  `supplier_id`        INT UNSIGNED NULL,
  `batch_number`       VARCHAR(50) NOT NULL,
  `manufacturing_date` DATE NULL,
  `expiry_date`        DATE NULL,
  `purchase_price`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sale_price`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `wholesale_price`    DECIMAL(12,2) NULL,
  `tax`                DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `discount`           DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `quantity`           INT NOT NULL DEFAULT 0,
  `minimum_stock`      INT NOT NULL DEFAULT 0,
  `maximum_stock`      INT NOT NULL DEFAULT 0,
  `rack_number`        VARCHAR(20) NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batches_medicine_batch` (`medicine_id`, `batch_number`),
  KEY `idx_batches_batch_number` (`batch_number`),
  KEY `idx_batches_expiry_date` (`expiry_date`),
  KEY `idx_batches_quantity` (`quantity`),
  KEY `idx_batches_medicine` (`medicine_id`),
  CONSTRAINT `fk_batches_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_batches_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PURCHASES (stock in from suppliers)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `purchases` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(50) NOT NULL,
  `supplier_id`    INT UNSIGNED NOT NULL,
  `purchase_date`  DATE NOT NULL,
  `subtotal`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `grand_total`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `due_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
  `notes`          TEXT NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchases_invoice` (`invoice_number`),
  KEY `idx_purchases_date` (`purchase_date`),
  KEY `idx_purchases_supplier` (`supplier_id`),
  KEY `idx_purchases_date_status` (`purchase_date`, `payment_status`),
  CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_purchases_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id`    INT UNSIGNED NOT NULL,
  `medicine_id`    INT UNSIGNED NOT NULL,
  `batch_id`       INT UNSIGNED NULL,
  `batch_number`   VARCHAR(50) NULL,
  `expiry_date`    DATE NULL,
  `quantity`       INT NOT NULL DEFAULT 0,
  `purchase_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_items_purchase` (`purchase_id`),
  KEY `idx_purchase_items_medicine` (`medicine_id`),
  CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`)
    REFERENCES `purchases` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_payments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id`    INT UNSIGNED NOT NULL,
  `payment_date`   DATE NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','card','bank','mobile_wallet') NOT NULL DEFAULT 'cash',
  `reference`      VARCHAR(100) NULL,
  `notes`          TEXT NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_payments_purchase` (`purchase_id`),
  KEY `idx_purchase_payments_date` (`payment_date`),
  CONSTRAINT `fk_pp_purchase` FOREIGN KEY (`purchase_id`)
    REFERENCES `purchases` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_returns` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_number` VARCHAR(50) NOT NULL,
  `purchase_id`  INT UNSIGNED NOT NULL,
  `return_date`  DATE NOT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reason`       TEXT NULL,
  `created_by`   INT UNSIGNED NOT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_returns_number` (`return_number`),
  KEY `idx_purchase_returns_purchase` (`purchase_id`),
  KEY `idx_purchase_returns_date` (`return_date`),
  CONSTRAINT `fk_pr_purchase` FOREIGN KEY (`purchase_id`)
    REFERENCES `purchases` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_return_items` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id`        INT UNSIGNED NOT NULL,
  `purchase_item_id` INT UNSIGNED NOT NULL,
  `quantity`         INT NOT NULL DEFAULT 0,
  `amount`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pri_return` (`return_id`),
  CONSTRAINT `fk_pri_return` FOREIGN KEY (`return_id`)
    REFERENCES `purchase_returns` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pri_item` FOREIGN KEY (`purchase_item_id`)
    REFERENCES `purchase_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SALES (stock out to customers)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `sales` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(50) NOT NULL,
  `customer_id`    INT UNSIGNED NULL,
  `sale_date`      DATETIME NOT NULL,
  `subtotal`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `grand_total`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `due_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'paid',
  `payment_method` ENUM('cash','card','bank','mobile_wallet','credit','split') NOT NULL DEFAULT 'cash',
  `notes`          TEXT NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `is_offline`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_invoice` (`invoice_number`),
  KEY `idx_sales_date` (`sale_date`),
  KEY `idx_sales_customer` (`customer_id`),
  KEY `idx_sales_date_status` (`sale_date`, `payment_status`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`    INT UNSIGNED NOT NULL,
  `medicine_id` INT UNSIGNED NOT NULL,
  `batch_id`   INT UNSIGNED NOT NULL,
  `quantity`   INT NOT NULL DEFAULT 0,
  `sale_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale_items_sale` (`sale_id`),
  KEY `idx_sale_items_medicine` (`medicine_id`),
  KEY `idx_sale_items_batch` (`batch_id`),
  CONSTRAINT `fk_si_sale` FOREIGN KEY (`sale_id`)
    REFERENCES `sales` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_si_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_si_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_payments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`        INT UNSIGNED NOT NULL,
  `payment_date`   DATE NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','card','bank','mobile_wallet','credit','split') NOT NULL DEFAULT 'cash',
  `reference`      VARCHAR(100) NULL,
  `notes`          TEXT NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale_payments_sale` (`sale_id`),
  KEY `idx_sale_payments_date` (`payment_date`),
  CONSTRAINT `fk_sp_sale` FOREIGN KEY (`sale_id`)
    REFERENCES `sales` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sp_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_returns` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_number` VARCHAR(50) NOT NULL,
  `sale_id`       INT UNSIGNED NOT NULL,
  `return_date`   DATE NOT NULL,
  `total_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reason`        TEXT NULL,
  `created_by`    INT UNSIGNED NOT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_returns_number` (`return_number`),
  KEY `idx_sales_returns_sale` (`sale_id`),
  KEY `idx_sales_returns_date` (`return_date`),
  CONSTRAINT `fk_sr_sale` FOREIGN KEY (`sale_id`)
    REFERENCES `sales` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sr_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_return_items` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id`    INT UNSIGNED NOT NULL,
  `sale_item_id` INT UNSIGNED NOT NULL,
  `quantity`     INT NOT NULL DEFAULT 0,
  `amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sri_return` (`return_id`),
  CONSTRAINT `fk_sri_return` FOREIGN KEY (`return_id`)
    REFERENCES `sales_returns` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sri_item` FOREIGN KEY (`sale_item_id`)
    REFERENCES `sale_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- EXPENSES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `expenses` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_category_id` INT UNSIGNED NOT NULL,
  `expense_date`        DATE NOT NULL,
  `amount`              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `description`         TEXT NULL,
  `payment_method`      ENUM('cash','card','bank','mobile_wallet') NOT NULL DEFAULT 'cash',
  `receipt_image`       VARCHAR(255) NULL,
  `created_by`          INT UNSIGNED NOT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_category` (`expense_category_id`),
  KEY `idx_expenses_date` (`expense_date`),
  CONSTRAINT `fk_expenses_category` FOREIGN KEY (`expense_category_id`)
    REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_expenses_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PAYMENTS LEDGER (polymorphic-ish money movement log)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `payments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `related_type`   ENUM('sale','purchase','customer','supplier','expense') NOT NULL,
  `related_id`     INT UNSIGNED NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','card','bank','mobile_wallet','credit','split') NOT NULL DEFAULT 'cash',
  `payment_date`   DATE NOT NULL,
  `direction`      ENUM('in','out') NOT NULL,
  `reference`      VARCHAR(100) NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_related` (`related_type`, `related_id`),
  KEY `idx_payments_date` (`payment_date`),
  KEY `idx_payments_direction` (`direction`),
  CONSTRAINT `fk_payments_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INVENTORY MOVEMENT
-- ============================================================================

-- Immutable per-movement stock ledger (quantity_change is signed)
CREATE TABLE IF NOT EXISTS `stock_transactions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_id`      INT UNSIGNED NOT NULL,
  `batch_id`         INT UNSIGNED NULL,
  `transaction_type` ENUM('purchase','sale','adjustment','transfer_in','transfer_out','return_in','return_out','opening','damaged','verification') NOT NULL,
  `quantity_change`  INT NOT NULL DEFAULT 0,
  `quantity_after`   INT NOT NULL DEFAULT 0,
  `reference_type`   VARCHAR(50) NULL,
  `reference_id`     INT UNSIGNED NULL,
  `notes`            TEXT NULL,
  `created_by`       INT UNSIGNED NOT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stock_txn_medicine` (`medicine_id`),
  KEY `idx_stock_txn_created` (`created_at`),
  KEY `idx_stock_txn_med_created` (`medicine_id`, `created_at`),
  KEY `idx_stock_txn_type` (`transaction_type`),
  CONSTRAINT `fk_stx_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_stx_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stx_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual stock corrections
CREATE TABLE IF NOT EXISTS `stock_adjustments` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `adjustment_number` VARCHAR(50) NOT NULL,
  `medicine_id`       INT UNSIGNED NOT NULL,
  `batch_id`          INT UNSIGNED NULL,
  `adjustment_type`   ENUM('increase','decrease') NOT NULL,
  `quantity`          INT NOT NULL DEFAULT 0,
  `reason`            TEXT NULL,
  `adjustment_date`   DATE NOT NULL,
  `created_by`        INT UNSIGNED NOT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_adjustments_number` (`adjustment_number`),
  KEY `idx_stock_adj_medicine` (`medicine_id`),
  KEY `idx_stock_adj_date` (`adjustment_date`),
  CONSTRAINT `fk_sa_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sa_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sa_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock moves between locations (store <-> counter, branches)
CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_number` VARCHAR(50) NOT NULL,
  `from_location`   VARCHAR(100) NOT NULL,
  `to_location`     VARCHAR(100) NOT NULL,
  `medicine_id`     INT UNSIGNED NOT NULL,
  `batch_id`        INT UNSIGNED NULL,
  `quantity`        INT NOT NULL DEFAULT 0,
  `transfer_date`   DATE NOT NULL,
  `notes`           TEXT NULL,
  `created_by`      INT UNSIGNED NOT NULL,
  `status`          ENUM('pending','completed') NOT NULL DEFAULT 'pending',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_transfers_number` (`transfer_number`),
  KEY `idx_stock_transfers_medicine` (`medicine_id`),
  KEY `idx_stock_transfers_status` (`status`),
  CONSTRAINT `fk_str_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_str_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_str_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic inventory event log
CREATE TABLE IF NOT EXISTS `inventory_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_id` INT UNSIGNED NULL,
  `batch_id`    INT UNSIGNED NULL,
  `action`      VARCHAR(100) NOT NULL,
  `details`     JSON NULL,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_logs_medicine` (`medicine_id`),
  KEY `idx_inventory_logs_action` (`action`),
  CONSTRAINT `fk_il_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_il_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_il_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PRESCRIPTIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `prescriptions` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prescription_number` VARCHAR(50) NOT NULL,
  `patient_name`       VARCHAR(150) NULL,
  `patient_phone`      VARCHAR(30) NULL,
  `patient_age`        INT UNSIGNED NULL,
  `patient_gender`     ENUM('male','female','other') NULL,
  `doctor_name`        VARCHAR(150) NULL,
  `doctor_phone`       VARCHAR(30) NULL,
  `clinic`             VARCHAR(150) NULL,
  `diagnosis`          TEXT NULL,
  `notes`              TEXT NULL,
  `image_path`         VARCHAR(255) NULL,
  `pdf_path`           VARCHAR(255) NULL,
  `sale_id`            INT UNSIGNED NULL,
  `created_by`         INT UNSIGNED NOT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prescriptions_number` (`prescription_number`),
  KEY `idx_prescriptions_patient` (`patient_name`),
  KEY `idx_prescriptions_doctor` (`doctor_name`),
  CONSTRAINT `fk_presc_sale` FOREIGN KEY (`sale_id`)
    REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_presc_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prescription_items` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prescription_id` INT UNSIGNED NOT NULL,
  `medicine_name`   VARCHAR(150) NOT NULL,
  `dosage`          VARCHAR(100) NULL,
  `frequency`       VARCHAR(100) NULL,
  `duration`        VARCHAR(100) NULL,
  `notes`           TEXT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_presc_items_prescription` (`prescription_id`),
  CONSTRAINT `fk_pitems_prescription` FOREIGN KEY (`prescription_id`)
    REFERENCES `prescriptions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- NOTIFICATIONS, AUDIT, SETTINGS, ATTACHMENTS, LABELS
-- ============================================================================

-- In-app notifications (user_id NULL = broadcast to all)
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NULL,
  `type`       ENUM('low_stock','expiry','out_of_stock','customer_due','supplier_due','daily_summary','system') NOT NULL,
  `title`      VARCHAR(255) NOT NULL,
  `message`    TEXT NULL,
  `link`       VARCHAR(255) NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  KEY `idx_notifications_type` (`type`),
  KEY `idx_notifications_read` (`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail of who did what
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `action`      VARCHAR(100) NOT NULL,
  `module`      VARCHAR(50) NULL,
  `record_id`   INT UNSIGNED NULL,
  `old_data`    JSON NULL,
  `new_data`    JSON NULL,
  `ip_address`  VARCHAR(45) NULL,
  `user_agent`  TEXT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_action_created` (`action`, `created_at`),
  KEY `idx_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value application settings
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(50) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`),
  KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic file attachments for any record
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `related_type` VARCHAR(50) NOT NULL,
  `related_id`   INT UNSIGNED NOT NULL,
  `file_path`    VARCHAR(255) NOT NULL,
  `file_name`    VARCHAR(255) NULL,
  `mime_type`    VARCHAR(100) NULL,
  `file_size`    INT UNSIGNED NULL,
  `uploaded_by`  INT UNSIGNED NOT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_related` (`related_type`, `related_id`),
  CONSTRAINT `fk_attachments_uploader` FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Printable barcode labels
CREATE TABLE IF NOT EXISTS `barcode_labels` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_id`   INT UNSIGNED NULL,
  `batch_id`      INT UNSIGNED NULL,
  `barcode_value` VARCHAR(100) NOT NULL,
  `format`        ENUM('EAN-13','EAN-8','UPC-A','CODE128','CODE39') NOT NULL DEFAULT 'EAN-13',
  `quantity`      INT NOT NULL DEFAULT 1,
  `created_by`    INT UNSIGNED NOT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_barcode_labels_medicine` (`medicine_id`),
  CONSTRAINT `fk_bl_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bl_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bl_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Printable QR labels
CREATE TABLE IF NOT EXISTS `qr_labels` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_id` INT UNSIGNED NULL,
  `batch_id`    INT UNSIGNED NULL,
  `qr_value`    TEXT NOT NULL,
  `quantity`    INT NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qr_labels_medicine` (`medicine_id`),
  CONSTRAINT `fk_ql_medicine` FOREIGN KEY (`medicine_id`)
    REFERENCES `medicines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ql_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `medicine_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ql_creator` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- HR: ATTENDANCE, SALARIES, LEAVES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `attendance` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`     INT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `status`          ENUM('present','absent','leave','half_day') NOT NULL DEFAULT 'present',
  `check_in`        TIME NULL,
  `check_out`       TIME NULL,
  `notes`           TEXT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_emp_date` (`employee_id`, `attendance_date`),
  KEY `idx_attendance_date` (`attendance_date`),
  CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_salaries` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`    INT UNSIGNED NOT NULL,
  `month`          CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `basic_salary`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `allowances`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `deductions`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_salary`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
  `payment_date`   DATE NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_salaries_emp_month` (`employee_id`, `month`),
  KEY `idx_emp_salaries_month` (`month`),
  CONSTRAINT `fk_es_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_leaves` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `leave_type`  VARCHAR(50) NOT NULL,
  `start_date`  DATE NOT NULL,
  `end_date`    DATE NOT NULL,
  `reason`      TEXT NULL,
  `status`      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emp_leaves_employee` (`employee_id`),
  KEY `idx_emp_leaves_status` (`status`),
  CONSTRAINT `fk_el_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_el_approver` FOREIGN KEY (`approved_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================
