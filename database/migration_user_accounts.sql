-- =============================================================
-- Build in Lombok — User Accounts Migration
-- Run this SQL on rovin629_biltest database
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- USERS
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `whatsapp_number` VARCHAR(30) DEFAULT NULL,

  -- Email verification
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verify_token` VARCHAR(64) DEFAULT NULL,
  `verify_expires` TIMESTAMP NULL DEFAULT NULL,

  -- Password reset
  `reset_token` VARCHAR(64) DEFAULT NULL,
  `reset_expires` TIMESTAMP NULL DEFAULT NULL,

  -- Status
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `role` ENUM('user','provider_owner','admin') NOT NULL DEFAULT 'user',

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,

  INDEX idx_verify_token (`verify_token`),
  INDEX idx_reset_token (`reset_token`),
  INDEX idx_active (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- FAVORITES (users can save providers, developers, projects)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_favorites` (
  `user_id` INT UNSIGNED NOT NULL,
  `entity_type` ENUM('provider','developer','project') NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`user_id`, `entity_type`, `entity_id`),
  INDEX idx_entity (`entity_type`, `entity_id`),
  CONSTRAINT fk_fav_user FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- CLAIM REQUESTS (user claims ownership of a provider listing)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `claim_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NOT NULL,

  -- Verification info
  `business_role` VARCHAR(100) NOT NULL COMMENT 'e.g. Owner, Manager, Director',
  `proof_description` TEXT NOT NULL COMMENT 'How they can prove ownership',
  `contact_phone` VARCHAR(30) DEFAULT NULL,

  -- Status
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` TEXT DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_status (`status`),
  INDEX idx_provider (`provider_id`),
  CONSTRAINT fk_claim_user FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT fk_claim_provider FOREIGN KEY (`provider_id`)
    REFERENCES `providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- NEW LISTING SUBMISSIONS (user submits a new provider listing)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `listing_submissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,

  -- Business details
  `business_name` VARCHAR(200) NOT NULL,
  `group_key` VARCHAR(50) NOT NULL,
  `category_keys` VARCHAR(500) NOT NULL COMMENT 'Comma-separated category keys',
  `area_key` VARCHAR(50) NOT NULL,
  `short_description` TEXT NOT NULL,
  `address` VARCHAR(300) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `whatsapp_number` VARCHAR(30) DEFAULT NULL,
  `website_url` VARCHAR(500) DEFAULT NULL,
  `google_maps_url` VARCHAR(500) DEFAULT NULL,
  `languages` VARCHAR(200) DEFAULT 'Bahasa only',

  -- Status
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` TEXT DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_provider_id` INT UNSIGNED DEFAULT NULL COMMENT 'Links to provider after approval',

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_status (`status`),
  INDEX idx_user (`user_id`),
  CONSTRAINT fk_sub_user FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- PROVIDER OWNERSHIP (links verified users to their listing)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `provider_owners` (
  `provider_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`provider_id`, `user_id`),
  INDEX idx_user (`user_id`),
  CONSTRAINT fk_po_provider FOREIGN KEY (`provider_id`)
    REFERENCES `providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT fk_po_user FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- NOTES:
-- 1. users table supports email verification via token
-- 2. user_favorites is polymorphic across providers/developers/projects
-- 3. claim_requests tracks listing ownership claims (admin-reviewed)
-- 4. listing_submissions allows new provider listings (admin-reviewed)
-- 5. provider_owners links approved owners to their listings
-- =============================================================
