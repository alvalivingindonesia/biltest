-- =============================================================
-- Build in Lombok — Social Media, Profile Photos & Region Hierarchy
-- Run this SQL on rovin629_biltest database
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- 1. SOCIAL MEDIA & PROFILE PHOTO COLUMNS — providers
-- -------------------------------------------------------------

ALTER TABLE `providers`
  ADD COLUMN `instagram_url` VARCHAR(500) DEFAULT NULL AFTER `website_url`,
  ADD COLUMN `facebook_url` VARCHAR(500) DEFAULT NULL AFTER `instagram_url`,
  ADD COLUMN `tiktok_url` VARCHAR(500) DEFAULT NULL AFTER `facebook_url`,
  ADD COLUMN `youtube_url` VARCHAR(500) DEFAULT NULL AFTER `tiktok_url`,
  ADD COLUMN `linkedin_url` VARCHAR(500) DEFAULT NULL AFTER `youtube_url`,
  ADD COLUMN `profile_photo_url` VARCHAR(500) DEFAULT NULL AFTER `linkedin_url`,
  ADD COLUMN `profile_description` TEXT DEFAULT NULL AFTER `profile_photo_url`;

-- -------------------------------------------------------------
-- 2. SOCIAL MEDIA & PROFILE PHOTO COLUMNS — developers
-- -------------------------------------------------------------

ALTER TABLE `developers`
  ADD COLUMN `instagram_url` VARCHAR(500) DEFAULT NULL AFTER `website_url`,
  ADD COLUMN `facebook_url` VARCHAR(500) DEFAULT NULL AFTER `instagram_url`,
  ADD COLUMN `tiktok_url` VARCHAR(500) DEFAULT NULL AFTER `facebook_url`,
  ADD COLUMN `youtube_url` VARCHAR(500) DEFAULT NULL AFTER `tiktok_url`,
  ADD COLUMN `linkedin_url` VARCHAR(500) DEFAULT NULL AFTER `youtube_url`,
  ADD COLUMN `profile_photo_url` VARCHAR(500) DEFAULT NULL AFTER `linkedin_url`,
  ADD COLUMN `profile_description` TEXT DEFAULT NULL AFTER `profile_photo_url`;

-- -------------------------------------------------------------
-- 3. AREA REGIONS — parent grouping for areas
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `area_regions` (
  `region_key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `area_regions` (`region_key`, `label`, `sort_order`) VALUES
  ('south_lombok', 'South Lombok', 1),
  ('west_lombok', 'West Lombok', 2),
  ('central_lombok', 'Central Lombok', 3),
  ('east_lombok', 'East Lombok', 4),
  ('north_lombok', 'North Lombok', 5),
  ('gili_islands', 'Gili Islands', 6);

-- -------------------------------------------------------------
-- 4. AREAS TABLE — add region_key + new sub-areas
-- -------------------------------------------------------------

-- Add region_key column to areas
ALTER TABLE `areas`
  ADD COLUMN `region_key` VARCHAR(50) DEFAULT NULL AFTER `label`,
  ADD INDEX idx_region (`region_key`);

-- Update existing areas with their region
UPDATE `areas` SET `region_key` = 'south_lombok' WHERE `key` IN ('kuta', 'selong_belanak', 'ekas');
UPDATE `areas` SET `region_key` = 'west_lombok' WHERE `key` IN ('mataram', 'senggigi');
UPDATE `areas` SET `region_key` = 'north_lombok' WHERE `key` = 'north_lombok';
UPDATE `areas` SET `region_key` = 'gili_islands' WHERE `key` = 'gili_islands';
UPDATE `areas` SET `region_key` = 'central_lombok' WHERE `key` = 'other_lombok';

-- Insert new sub-areas for South Lombok
INSERT IGNORE INTO `areas` (`key`, `label`, `region_key`, `sort_order`) VALUES
  ('mawi', 'Mawi', 'south_lombok', 11),
  ('mawun', 'Mawun', 'south_lombok', 12),
  ('are_guling', 'Are Guling', 'south_lombok', 13),
  ('gerupuk', 'Gerupuk', 'south_lombok', 14),
  ('tanjung_aan', 'Tanjung Aan', 'south_lombok', 15);

-- Insert new sub-areas for West Lombok
INSERT IGNORE INTO `areas` (`key`, `label`, `region_key`, `sort_order`) VALUES
  ('sekotong', 'Sekotong', 'west_lombok', 21),
  ('lembar', 'Lembar', 'west_lombok', 22),
  ('gerung', 'Gerung', 'west_lombok', 23);

-- Insert new sub-areas for Central Lombok
INSERT IGNORE INTO `areas` (`key`, `label`, `region_key`, `sort_order`) VALUES
  ('praya', 'Praya', 'central_lombok', 31),
  ('jonggat', 'Jonggat', 'central_lombok', 32),
  ('batukliang', 'Batukliang', 'central_lombok', 33);

-- Insert new sub-areas for East Lombok
INSERT IGNORE INTO `areas` (`key`, `label`, `region_key`, `sort_order`) VALUES
  ('labuhan_lombok', 'Labuhan Lombok', 'east_lombok', 41),
  ('selong', 'Selong', 'east_lombok', 42);

-- Insert new sub-areas for North Lombok
INSERT IGNORE INTO `areas` (`key`, `label`, `region_key`, `sort_order`) VALUES
  ('senaru', 'Senaru', 'north_lombok', 51),
  ('tanjung', 'Tanjung', 'north_lombok', 52),
  ('bangsal', 'Bangsal', 'north_lombok', 53);


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- NOTES:
-- 1. providers & developers gain: instagram_url, facebook_url,
--    tiktok_url, youtube_url, linkedin_url, profile_photo_url,
--    profile_description
-- 2. area_regions is a new parent table grouping areas into regions
-- 3. areas.region_key links each area to its parent region
-- 4. New sub-areas added for South/West/Central/East/North Lombok
-- 5. 'other_lombok' is remapped to 'central_lombok' region
-- =============================================================
