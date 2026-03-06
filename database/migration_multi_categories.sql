-- =============================================================
-- Migration: Multi-category support for providers and developers
-- Run this SQL on your database before deploying the updated code.
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Create provider_categories junction table
CREATE TABLE IF NOT EXISTS `provider_categories` (
  `provider_id` INT UNSIGNED NOT NULL,
  `category_key` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`provider_id`, `category_key`),
  INDEX idx_cat (`category_key`),
  CONSTRAINT fk_pcat_provider FOREIGN KEY (`provider_id`)
    REFERENCES `providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create developer_categories junction table
CREATE TABLE IF NOT EXISTS `developer_categories` (
  `developer_id` INT UNSIGNED NOT NULL,
  `category_key` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`developer_id`, `category_key`),
  INDEX idx_cat (`category_key`),
  CONSTRAINT fk_dcat_developer FOREIGN KEY (`developer_id`)
    REFERENCES `developers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Migrate existing provider category_key data into junction table
INSERT IGNORE INTO `provider_categories` (`provider_id`, `category_key`)
SELECT `id`, `category_key` FROM `providers`
WHERE `category_key` IS NOT NULL AND `category_key` != '';

-- 4. (Optional) After verifying data migrated correctly, you can drop the column:
-- ALTER TABLE `providers` DROP COLUMN `category_key`;
-- For now we keep it for backward compatibility. The code no longer writes to it.

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- VERIFICATION: Run these after migration to confirm data moved
-- =============================================================
-- SELECT COUNT(*) AS provider_category_rows FROM provider_categories;
-- SELECT COUNT(*) AS developer_category_rows FROM developer_categories;
-- SELECT p.name, GROUP_CONCAT(pc.category_key) AS categories
--   FROM providers p
--   LEFT JOIN provider_categories pc ON pc.provider_id = p.id
--   GROUP BY p.id LIMIT 10;
