-- =============================================================
-- Build in Lombok — Land Listings, Agents & Social Login Migration
-- Run this SQL on rovin629_biltest database
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- SOCIAL LOGIN: Add columns to users table
-- -------------------------------------------------------------

ALTER TABLE `users`
  ADD COLUMN `google_id` VARCHAR(100) DEFAULT NULL AFTER `whatsapp_number`,
  ADD COLUMN `facebook_id` VARCHAR(100) DEFAULT NULL AFTER `google_id`,
  ADD COLUMN `instagram_id` VARCHAR(100) DEFAULT NULL AFTER `facebook_id`,
  ADD COLUMN `avatar_url` VARCHAR(500) DEFAULT NULL AFTER `instagram_id`,
  ADD COLUMN `auth_provider` ENUM('email','google','facebook','instagram') NOT NULL DEFAULT 'email' AFTER `avatar_url`,
  ADD UNIQUE INDEX idx_google_id (`google_id`),
  ADD UNIQUE INDEX idx_facebook_id (`facebook_id`),
  ADD UNIQUE INDEX idx_instagram_id (`instagram_id`);

-- Make password_hash nullable for social login users (they don't set a password)
ALTER TABLE `users` MODIFY `password_hash` VARCHAR(255) DEFAULT NULL;


-- -------------------------------------------------------------
-- AGENTS (land/property agents who can post listings)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `agents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL UNIQUE,
  `slug` VARCHAR(150) NOT NULL UNIQUE,

  -- Profile info
  `agency_name` VARCHAR(200) DEFAULT NULL,
  `display_name` VARCHAR(150) NOT NULL,
  `bio` TEXT DEFAULT NULL,
  `profile_photo_url` VARCHAR(500) DEFAULT NULL,

  -- Contact (may differ from user account)
  `phone` VARCHAR(30) DEFAULT NULL,
  `whatsapp_number` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `website_url` VARCHAR(500) DEFAULT NULL,

  -- Location focus
  `areas_served` VARCHAR(500) DEFAULT NULL COMMENT 'Comma-separated area_keys',
  `languages` VARCHAR(200) DEFAULT 'Bahasa, English',

  -- Google reviews (for agents with a Google Maps presence)
  `google_place_id` VARCHAR(100) DEFAULT NULL,
  `google_maps_url` VARCHAR(500) DEFAULT NULL,
  `google_rating` DECIMAL(2,1) DEFAULT NULL,
  `google_review_count` INT UNSIGNED DEFAULT 0,

  -- Verification & status
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verification_method` VARCHAR(50) DEFAULT NULL COMMENT 'google, facebook, instagram, admin',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_active (`is_active`),
  INDEX idx_verified (`is_verified`),
  FULLTEXT idx_search (`display_name`, `agency_name`, `bio`),
  CONSTRAINT fk_agent_user FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- LAND & PROPERTY LISTINGS
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `listing_types` (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `listing_types` (`key`, `label`, `sort_order`) VALUES
  ('land', 'Land / Tanah', 1),
  ('villa', 'Villa', 2),
  ('house', 'House / Rumah', 3),
  ('apartment', 'Apartment', 4),
  ('commercial', 'Commercial Property', 5),
  ('warehouse', 'Warehouse / Gudang', 6);

CREATE TABLE IF NOT EXISTS `land_certificate_types` (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `land_certificate_types` (`key`, `label`, `sort_order`) VALUES
  ('shm', 'SHM (Sertifikat Hak Milik)', 1),
  ('hgb', 'HGB (Hak Guna Bangunan)', 2),
  ('hak_pakai', 'Hak Pakai', 3),
  ('girik', 'Girik / Letter C', 4),
  ('adat', 'Adat / Customary', 5),
  ('other', 'Other', 6);

CREATE TABLE IF NOT EXISTS `listings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(200) NOT NULL UNIQUE,
  `agent_id` INT UNSIGNED NOT NULL,

  -- Type & status
  `listing_type_key` VARCHAR(50) NOT NULL,
  `status` ENUM('draft','active','under_offer','sold','expired') NOT NULL DEFAULT 'draft',

  -- Title & description
  `title` VARCHAR(300) NOT NULL,
  `short_description` TEXT NOT NULL,
  `description` TEXT DEFAULT NULL,

  -- Location
  `area_key` VARCHAR(50) NOT NULL,
  `address` VARCHAR(300) DEFAULT NULL,
  `latitude` DECIMAL(10,6) DEFAULT NULL,
  `longitude` DECIMAL(10,6) DEFAULT NULL,
  `google_maps_url` VARCHAR(500) DEFAULT NULL,

  -- Pricing
  `price_idr` BIGINT UNSIGNED DEFAULT NULL,
  `price_usd` INT UNSIGNED DEFAULT NULL,
  `price_label` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. "Negotiable", "Per Are"',

  -- Land-specific fields
  `land_size_sqm` INT UNSIGNED DEFAULT NULL,
  `land_size_are` DECIMAL(8,2) DEFAULT NULL,
  `certificate_type_key` VARCHAR(50) DEFAULT NULL,
  `zoning` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. Tourism, Residential, Agricultural',

  -- Property-specific fields (for villas, houses, apartments)
  `building_size_sqm` INT UNSIGNED DEFAULT NULL,
  `bedrooms` TINYINT UNSIGNED DEFAULT NULL,
  `bathrooms` TINYINT UNSIGNED DEFAULT NULL,
  `year_built` SMALLINT UNSIGNED DEFAULT NULL,
  `furnishing` ENUM('unfurnished','semi_furnished','fully_furnished') DEFAULT NULL,

  -- Features
  `features` TEXT DEFAULT NULL COMMENT 'JSON array of feature strings',
  `nearby` TEXT DEFAULT NULL COMMENT 'JSON array: beach, airport, etc.',

  -- Contact override (if different from agent profile)
  `contact_whatsapp` VARCHAR(30) DEFAULT NULL,
  `contact_phone` VARCHAR(30) DEFAULT NULL,

  -- Admin
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
  `admin_notes` TEXT DEFAULT NULL,
  `views_count` INT UNSIGNED NOT NULL DEFAULT 0,

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,

  INDEX idx_agent (`agent_id`),
  INDEX idx_type (`listing_type_key`),
  INDEX idx_area (`area_key`),
  INDEX idx_status (`status`, `is_approved`),
  INDEX idx_featured (`is_featured`, `status`),
  INDEX idx_price_idr (`price_idr`),
  INDEX idx_land_size (`land_size_are`),
  FULLTEXT idx_search (`title`, `short_description`, `description`),
  CONSTRAINT fk_listing_agent FOREIGN KEY (`agent_id`)
    REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Listing tags
CREATE TABLE IF NOT EXISTS `listing_tags` (
  `listing_id` INT UNSIGNED NOT NULL,
  `tag` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`listing_id`, `tag`),
  INDEX idx_tag (`tag`),
  CONSTRAINT fk_ltag_listing FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- LISTING IMAGES (separate from the generic images table)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `listing_images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `listing_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `alt_text` VARCHAR(300) DEFAULT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_listing (`listing_id`),
  INDEX idx_primary (`listing_id`, `is_primary`),
  CONSTRAINT fk_limg_listing FOREIGN KEY (`listing_id`)
    REFERENCES `listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Also update the images table ENUM to support agents and listings
ALTER TABLE `images`
  MODIFY `entity_type` ENUM('provider', 'developer', 'project', 'guide', 'agent', 'listing') NOT NULL;


-- -------------------------------------------------------------
-- GOOGLE REVIEW UPDATE TRACKING
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `review_update_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `entity_type` ENUM('provider','developer','agent') NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `old_rating` DECIMAL(2,1) DEFAULT NULL,
  `new_rating` DECIMAL(2,1) DEFAULT NULL,
  `old_review_count` INT UNSIGNED DEFAULT NULL,
  `new_review_count` INT UNSIGNED DEFAULT NULL,
  `source` VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'manual, cron, api',
  `checked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_entity (`entity_type`, `entity_id`),
  INDEX idx_checked (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add last_review_check column to providers and developers
ALTER TABLE `providers`
  ADD COLUMN `last_review_check` TIMESTAMP NULL DEFAULT NULL AFTER `google_review_count`;

ALTER TABLE `developers`
  ADD COLUMN `last_review_check` TIMESTAMP NULL DEFAULT NULL AFTER `google_review_count`;


-- -------------------------------------------------------------
-- SAVED SEARCHES (users can save land search criteria)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `saved_searches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `criteria_json` TEXT NOT NULL COMMENT 'JSON of search filters',
  `notify_new` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_user (`user_id`),
  CONSTRAINT fk_ss_user FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Update user_favorites to also support listings and agents
ALTER TABLE `user_favorites`
  MODIFY `entity_type` ENUM('provider','developer','project','listing','agent') NOT NULL;


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- NOTES:
-- 1. agents table links to users via user_id (1:1)
-- 2. listings belong to agents, support land + property types
-- 3. listing_images stores uploaded photos per listing
-- 4. review_update_log tracks Google rating changes over time
-- 5. Social login adds google_id/facebook_id/instagram_id to users
-- 6. password_hash is now nullable for social-login-only accounts
-- 7. land_certificate_types covers Indonesian land title types
-- =============================================================
