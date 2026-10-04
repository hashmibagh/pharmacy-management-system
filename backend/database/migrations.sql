-- =====================================================================
-- Pharmacy Management System — Backend support migrations
-- =====================================================================
-- IMPORTANT: run this file AFTER database/schema.sql.
--
-- The FULL domain schema (users, roles, permissions, role_permissions,
-- user_permissions, medicines, medicine_batches, suppliers, customers,
-- purchases, sales, expenses, employees, etc.) lives in
-- database/schema.sql, which is authoritative. The role_permissions and
-- user_permissions tables already exist there in their normalized form
-- (permissions table + permission_id foreign keys) — they are NOT
-- recreated here.
--
-- This file contains ONLY the auth support tables that the PHP backend
-- requires on top of that schema (refresh_tokens, password_resets),
-- plus an optional seed granting the Super Admin role every permission.
--
--   mysql -u root -p pharmacy_db < database/schema.sql
--   mysql -u root -p pharmacy_db < backend/database/migrations.sql
--
-- All statements are idempotent (IF NOT EXISTS / INSERT IGNORE).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Refresh tokens: stored SHA-256 hashed, rotated on every use.
-- Used by: helpers/Auth.php (issue/rotate/revoke)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 of the raw token',
  `expires_at`  DATETIME NOT NULL,
  `revoked_at`  DATETIME NULL DEFAULT NULL,
  `ip_address`  VARCHAR(45) NULL,
  `user_agent`  VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `ix_user` (`user_id`),
  KEY `ix_expires` (`expires_at`),
  CONSTRAINT `fk_refresh_tokens_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Password reset tokens (single-use, 1h expiry, hashed).
-- Used by: controllers/AuthController.php (forgot/reset password)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `email`       VARCHAR(190) NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT 'SHA-256 of the raw token',
  `expires_at`  DATETIME NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `ix_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Seed: grant every permission to the Super Admin role.
-- Uses the NORMALIZED role_permissions(role_id, permission_id) shape from
-- database/schema.sql (permission names are resolved to ids server-side).
-- The permissions themselves are seeded by database/seed.sql.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.name = 'Super Admin';
