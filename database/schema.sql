-- =============================================================
-- Build in Lombok — MySQL Schema
-- Compatible with MySQL 5.7+ / MariaDB 10.3+ (shared hosting)
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- LOOKUP TABLES (for filter dropdowns and data integrity)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `groups` (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `groups` (`key`, `label`, `sort_order`) VALUES
  ('builders_trades', 'Builders & Trades', 1),
  ('professional_services', 'Professional Services', 2),
  ('specialist_contractors', 'Specialist Contractors', 3),
  ('suppliers_materials', 'Suppliers & Materials', 4);

CREATE TABLE IF NOT EXISTS `categories` (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `group_key` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_group (`group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `categories` (`key`, `group_key`, `label`, `sort_order`) VALUES
  -- Builders & Trades
  ('general_contractor', 'builders_trades', 'General Contractor', 1),
  ('carpenter', 'builders_trades', 'Carpenter / Joiner', 2),
  ('mason', 'builders_trades', 'Mason / Concrete Worker', 3),
  ('roofer', 'builders_trades', 'Roofer', 4),
  ('plumber', 'builders_trades', 'Plumber', 5),
  ('electrician', 'builders_trades', 'Electrician', 6),
  ('painter', 'builders_trades', 'Painter / Finisher', 7),
  ('tiler', 'builders_trades', 'Tiler', 8),
  -- Professional Services
  ('architect', 'professional_services', 'Architect', 1),
  ('interior_designer', 'professional_services', 'Interior Designer', 2),
  ('structural_engineer', 'professional_services', 'Structural Engineer', 3),
  ('mep_engineer', 'professional_services', 'MEP Engineer', 4),
  ('civil_engineer', 'professional_services', 'Civil Engineer', 5),
  ('quantity_surveyor', 'professional_services', 'Quantity Surveyor / Cost Consultant', 6),
  ('project_manager', 'professional_services', 'Project Manager / Construction Manager', 7),
  -- Specialist Contractors
  ('pool_contractor', 'specialist_contractors', 'Pool Builder / Pool Contractor', 1),
  ('solar_installer', 'specialist_contractors', 'Solar / PV Installer', 2),
  ('waterproofing', 'specialist_contractors', 'Waterproofing Specialist', 3),
  ('glazing_contractor', 'specialist_contractors', 'Windows & Doors / Glazing Contractor', 4),
  ('metalwork_contractor', 'specialist_contractors', 'Steel / Welding / Metalwork Contractor', 5),
  ('hvac_contractor', 'specialist_contractors', 'Air-conditioning / HVAC Contractor', 6),
  ('landscaping_contractor', 'specialist_contractors', 'Landscaping Contractor', 7),
  -- Suppliers & Materials
  ('building_materials_store', 'suppliers_materials', 'General Building Materials Store', 1),
  ('timber_workshop', 'suppliers_materials', 'Timber & Carpentry Workshop', 2),
  ('tiles_stone_supplier', 'suppliers_materials', 'Tiles & Stone Finishes Supplier', 3),
  ('sanitary_supplier', 'suppliers_materials', 'Sanitary Ware & Plumbing Fixtures Supplier', 4),
  ('lighting_supplier', 'suppliers_materials', 'Lighting & Electrical Fixtures Supplier', 5),
  -- Suppliers & Materials > Raw Materials / Aggregates
  ('sand_supplier', 'suppliers_materials', 'Sand Supplier', 10),
  ('gravel_supplier', 'suppliers_materials', 'Gravel & Riverstone Supplier', 11),
  ('aggregate_supplier', 'suppliers_materials', 'Crushed Stone / Aggregate Supplier', 12),
  ('earth_fill_supplier', 'suppliers_materials', 'Earth Fill / Compacted Fill Supplier', 13),
  ('topsoil_supplier', 'suppliers_materials', 'Topsoil & Landscaping Materials Supplier', 14);

CREATE TABLE IF NOT EXISTS `areas` (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `areas` (`key`, `label`, `sort_order`) VALUES
  ('kuta', 'Kuta / Mandalika', 1),
  ('selong_belanak', 'Selong Belanak', 2),
  ('ekas', 'Ekas Bay / East Lombok', 3),
  ('senggigi', 'Senggigi', 4),
  ('mataram', 'Mataram / Cakranegara', 5),
  ('north_lombok', 'North Lombok', 6),
  ('gili_islands', 'Gili Islands', 7),
  ('other_lombok', 'Other Lombok', 8);

CREATE TABLE IF NOT EXISTS `project_types` (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `project_types` (`key`, `label`, `sort_order`) VALUES
  ('villa_complex', 'Villa Complex', 1),
  ('apartment', 'Apartment / Condo', 2),
  ('mixed_use', 'Mixed Use', 3),
  ('land_subdivision', 'Land / Subdivision', 4),
  ('hotel_resort', 'Hotel / Resort', 5),
  ('commercial', 'Commercial', 6);

CREATE TABLE IF NOT EXISTS `project_statuses` (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `project_statuses` (`key`, `label`, `sort_order`) VALUES
  ('planning', 'Planning', 1),
  ('under_construction', 'Under Construction', 2),
  ('completed', 'Completed', 3),
  ('sold_out', 'Sold Out', 4);


-- -------------------------------------------------------------
-- PROVIDERS (builders, architects, specialists)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,
  `group_key` VARCHAR(50) NOT NULL,
  `category_key` VARCHAR(50) NOT NULL,
  `area_key` VARCHAR(50) NOT NULL,

  -- Descriptions
  `short_description` TEXT NOT NULL,
  `description` TEXT NOT NULL,

  -- Location
  `address` VARCHAR(300) DEFAULT NULL,
  `latitude` DECIMAL(10,6) DEFAULT NULL,
  `longitude` DECIMAL(10,6) DEFAULT NULL,
  `google_maps_url` VARCHAR(500) DEFAULT NULL,

  -- Google reviews (cached — refreshed periodically)
  `google_place_id` VARCHAR(100) DEFAULT NULL,
  `google_rating` DECIMAL(2,1) DEFAULT NULL,
  `google_review_count` INT UNSIGNED DEFAULT 0,

  -- Contact
  `phone` VARCHAR(30) DEFAULT NULL,
  `whatsapp_number` VARCHAR(30) DEFAULT NULL,
  `website_url` VARCHAR(500) DEFAULT NULL,
  `languages` VARCHAR(200) DEFAULT 'Bahasa only',

  -- Internal reviews (future)
  `rating_internal` DECIMAL(2,1) DEFAULT NULL,
  `review_count_internal` INT UNSIGNED DEFAULT 0,

  -- Monetization & display
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `badge` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Indexes for filtering and search
  INDEX idx_group (`group_key`),
  INDEX idx_category (`category_key`),
  INDEX idx_area (`area_key`),
  INDEX idx_featured (`is_featured`, `is_active`),
  INDEX idx_active (`is_active`),
  INDEX idx_rating (`google_rating` DESC),
  FULLTEXT idx_search (`name`, `short_description`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Tags for providers (many-to-many)
CREATE TABLE IF NOT EXISTS `provider_tags` (
  `provider_id` INT UNSIGNED NOT NULL,
  `tag` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`provider_id`, `tag`),
  INDEX idx_tag (`tag`),
  CONSTRAINT fk_ptag_provider FOREIGN KEY (`provider_id`)
    REFERENCES `providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories for providers (many-to-many)
CREATE TABLE IF NOT EXISTS `provider_categories` (
  `provider_id` INT UNSIGNED NOT NULL,
  `category_key` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`provider_id`, `category_key`),
  INDEX idx_cat (`category_key`),
  CONSTRAINT fk_pcat_provider FOREIGN KEY (`provider_id`)
    REFERENCES `providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- DEVELOPERS
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `developers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,

  -- Descriptions
  `short_description` TEXT NOT NULL,
  `description` TEXT NOT NULL,

  -- Financial
  `min_ticket_usd` INT UNSIGNED DEFAULT NULL,

  -- Google reviews
  `google_place_id` VARCHAR(100) DEFAULT NULL,
  `google_maps_url` VARCHAR(500) DEFAULT NULL,
  `google_rating` DECIMAL(2,1) DEFAULT NULL,
  `google_review_count` INT UNSIGNED DEFAULT 0,

  -- Contact
  `phone` VARCHAR(30) DEFAULT NULL,
  `whatsapp_number` VARCHAR(30) DEFAULT NULL,
  `website_url` VARCHAR(500) DEFAULT NULL,
  `languages` VARCHAR(200) DEFAULT 'Bahasa only',

  -- Display
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `badge` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_featured (`is_featured`, `is_active`),
  INDEX idx_active (`is_active`),
  FULLTEXT idx_search (`name`, `short_description`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Developer focus areas (many-to-many)
CREATE TABLE IF NOT EXISTS `developer_areas` (
  `developer_id` INT UNSIGNED NOT NULL,
  `area_key` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`developer_id`, `area_key`),
  CONSTRAINT fk_darea_dev FOREIGN KEY (`developer_id`)
    REFERENCES `developers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Developer project types (many-to-many)
CREATE TABLE IF NOT EXISTS `developer_project_types` (
  `developer_id` INT UNSIGNED NOT NULL,
  `project_type_key` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`developer_id`, `project_type_key`),
  CONSTRAINT fk_dptype_dev FOREIGN KEY (`developer_id`)
    REFERENCES `developers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Developer tags
CREATE TABLE IF NOT EXISTS `developer_tags` (
  `developer_id` INT UNSIGNED NOT NULL,
  `tag` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`developer_id`, `tag`),
  INDEX idx_tag (`tag`),
  CONSTRAINT fk_dtag_dev FOREIGN KEY (`developer_id`)
    REFERENCES `developers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories for developers (many-to-many)
CREATE TABLE IF NOT EXISTS `developer_categories` (
  `developer_id` INT UNSIGNED NOT NULL,
  `category_key` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`developer_id`, `category_key`),
  INDEX idx_cat (`category_key`),
  CONSTRAINT fk_dcat_developer FOREIGN KEY (`developer_id`)
    REFERENCES `developers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- PROJECTS
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,
  `developer_id` INT UNSIGNED DEFAULT NULL,
  `area_key` VARCHAR(50) NOT NULL,
  `project_type_key` VARCHAR(50) NOT NULL,
  `status_key` VARCHAR(50) NOT NULL DEFAULT 'planning',

  -- Financial
  `min_investment_usd` INT UNSIGNED DEFAULT NULL,
  `expected_yield_range` VARCHAR(100) DEFAULT NULL,
  `timeline_summary` VARCHAR(200) DEFAULT NULL,

  -- Descriptions
  `short_description` TEXT NOT NULL,
  `description` TEXT NOT NULL,

  -- Contact
  `website_url` VARCHAR(500) DEFAULT NULL,
  `info_contact_whatsapp` VARCHAR(30) DEFAULT NULL,

  -- Display
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `badge` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,

  -- Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_developer (`developer_id`),
  INDEX idx_area (`area_key`),
  INDEX idx_type (`project_type_key`),
  INDEX idx_status (`status_key`),
  INDEX idx_featured (`is_featured`, `is_active`),
  INDEX idx_active (`is_active`),
  INDEX idx_investment (`min_investment_usd`),
  FULLTEXT idx_search (`name`, `short_description`, `description`),
  CONSTRAINT fk_proj_dev FOREIGN KEY (`developer_id`)
    REFERENCES `developers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Project tags
CREATE TABLE IF NOT EXISTS `project_tags` (
  `project_id` INT UNSIGNED NOT NULL,
  `tag` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`project_id`, `tag`),
  INDEX idx_tag (`tag`),
  CONSTRAINT fk_ptag_proj FOREIGN KEY (`project_id`)
    REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- GUIDES (content / articles)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `guides` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `title` VARCHAR(300) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `read_time` VARCHAR(30) DEFAULT NULL,
  `excerpt` TEXT NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_category (`category`),
  INDEX idx_published (`is_published`),
  FULLTEXT idx_search (`title`, `excerpt`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------------
-- IMAGES (shared by all listing types)
-- Optional: attach photos to any listing
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `entity_type` ENUM('provider', 'developer', 'project', 'guide') NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `alt_text` VARCHAR(300) DEFAULT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_entity (`entity_type`, `entity_id`),
  INDEX idx_primary (`entity_type`, `entity_id`, `is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- NOTES:
-- 1. FULLTEXT indexes enable MATCH...AGAINST search across name
--    and descriptions — fast even at 1M+ rows.
-- 2. Composite indexes on (is_featured, is_active) support the
--    common query "show me active featured listings first".
-- 3. Tags are normalized into junction tables so you can filter
--    by tag efficiently with JOIN + WHERE tag IN (...).
-- 4. The images table uses entity_type + entity_id pattern
--    (polymorphic) to avoid 4 separate image tables.
-- 5. All VARCHAR lengths are generous but bounded. TEXT columns
--    handle long descriptions without wasting row space.
-- =============================================================
