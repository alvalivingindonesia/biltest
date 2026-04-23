-- =============================================================
-- Migration: add Indonesian label column (label_id) to all lookup tables
-- Date: 2026-04-23
-- Purpose: support bilingual UI (EN + ID). Existing English `label`
--          stays as-is; `label_id` is a parallel Indonesian translation.
--          NULL label_id values fall back to English at the client.
-- =============================================================
-- Run on: MySQL 5.7+ / MariaDB 10.x (HostPapa shared hosting).
-- Safe to re-run: uses conditional column add via information_schema.
-- Run via phpMyAdmin or mysql CLI against the biltest database.
-- =============================================================

-- Helper: only adds the column if it doesn't already exist.
DROP PROCEDURE IF EXISTS bil_add_label_id;
DELIMITER $$
CREATE PROCEDURE bil_add_label_id(IN tbl VARCHAR(64))
BEGIN
  DECLARE col_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO col_exists
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = tbl
    AND COLUMN_NAME = 'label_id';
  IF col_exists = 0 THEN
    SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `label_id` VARCHAR(150) NULL AFTER `label`');
    PREPARE stmt FROM @ddl;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL bil_add_label_id('groups');
CALL bil_add_label_id('categories');
CALL bil_add_label_id('areas');
CALL bil_add_label_id('area_regions');
CALL bil_add_label_id('project_types');
CALL bil_add_label_id('project_statuses');
CALL bil_add_label_id('listing_types');
CALL bil_add_label_id('land_certificate_types');

DROP PROCEDURE IF EXISTS bil_add_label_id;

-- =============================================================
-- Seed: pre-populate Indonesian translations for the core group labels.
-- These are the top-level category names shown across navigation,
-- hero cards, and filters. Editors can refine them later in the admin
-- console. Uses WHERE `key` = ... so it's idempotent.
-- =============================================================

UPDATE `groups` SET label_id = 'Pengembang Properti'      WHERE `key` = 'property_developers' AND (label_id IS NULL OR label_id = '');
UPDATE `groups` SET label_id = 'Tukang & Pengrajin'        WHERE `key` = 'builders_trades'      AND (label_id IS NULL OR label_id = '');
UPDATE `groups` SET label_id = 'Jasa Profesional'          WHERE `key` = 'professional_services' AND (label_id IS NULL OR label_id = '');
UPDATE `groups` SET label_id = 'Pemasok & Bahan'           WHERE `key` = 'suppliers_materials'  AND (label_id IS NULL OR label_id = '');
UPDATE `groups` SET label_id = 'Agen Properti'             WHERE `key` = 'property_agents'      AND (label_id IS NULL OR label_id = '');

-- Project statuses (short list — safe to seed).
UPDATE project_statuses SET label_id = 'Direncanakan'   WHERE `key` = 'planned'     AND (label_id IS NULL OR label_id = '');
UPDATE project_statuses SET label_id = 'Dalam Pembangunan' WHERE `key` = 'under_construction' AND (label_id IS NULL OR label_id = '');
UPDATE project_statuses SET label_id = 'Selesai'        WHERE `key` = 'completed'   AND (label_id IS NULL OR label_id = '');
UPDATE project_statuses SET label_id = 'Terjual'        WHERE `key` = 'sold_out'    AND (label_id IS NULL OR label_id = '');
