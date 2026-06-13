-- =====================================================================
-- Build in Lombok — Place tier (Region→Area→Place) + LLM Extractor support
-- Pairs with: docs/adr/0009-llm-listing-extractor.md
--             docs/adr/0010-three-tier-location.md
--
-- Adds the searchable Place level under Area, the LLM extraction bookkeeping
-- columns (source_hash for content-hash gating, confidence/method), promotes
-- Awang + Mawun to Areas, and seeds the curated Place list + aliases.
--
-- Run in the phpMyAdmin SQL tab (understands DELIMITER). Idempotent: guarded
-- column/index adds, CREATE TABLE IF NOT EXISTS, INSERT … ON DUPLICATE. Re-runnable.
-- =====================================================================

DROP PROCEDURE IF EXISTS bil_add_col2;
DELIMITER $$
CREATE PROCEDURE bil_add_col2(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col) THEN
        SET @s = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 1. places — the searchable sub-Area locality (docs/adr/0010)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS places (
    place_key  VARCHAR(50)  NOT NULL,
    label      VARCHAR(100) NOT NULL,
    label_id   VARCHAR(100) DEFAULT NULL,
    area_key   VARCHAR(50)  NOT NULL,           -- parent Area (roll-up)
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (place_key),
    KEY idx_places_area (area_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. area_aliases gains place_key (an alias may resolve to a Place, which
--    implies its Area). listings gain place_key + extraction bookkeeping.
-- ---------------------------------------------------------------------
CALL bil_add_col2('area_aliases', 'place_key', "place_key VARCHAR(50) NULL DEFAULT NULL AFTER area_key");

CALL bil_add_col2('listings', 'place_key',              "place_key VARCHAR(50) NULL DEFAULT NULL");
CALL bil_add_col2('listings', 'source_hash',            "source_hash VARCHAR(64) NULL DEFAULT NULL");      -- content-hash gating
CALL bil_add_col2('listings', 'extraction_method',      "extraction_method VARCHAR(20) NULL DEFAULT NULL"); -- llm | fallback | manual
CALL bil_add_col2('listings', 'extraction_confidence',  "extraction_confidence DECIMAL(3,2) NULL DEFAULT NULL");

DROP PROCEDURE IF EXISTS bil_add_col2;

-- index for place filtering (plain ADD; ignore "Duplicate key name" on re-run)
ALTER TABLE listings ADD INDEX idx_listings_place (place_key);

-- ---------------------------------------------------------------------
-- 3. Promote Awang + Mawun to Areas (South Lombok). region_key matches the
--    existing area_regions seed (south_lombok).
-- ---------------------------------------------------------------------
INSERT INTO areas (`key`, label, label_id, region_key, sort_order) VALUES
    ('awang', 'Awang',  'Awang',  'south_lombok', 60),
    ('mawun', 'Mawun',  'Mawun',  'south_lombok', 61)
ON DUPLICATE KEY UPDATE label = VALUES(label), region_key = VALUES(region_key);

-- ---------------------------------------------------------------------
-- 4. Seed Places (curated; frequency data from Mode A refines later)
-- ---------------------------------------------------------------------
INSERT INTO places (place_key, label, label_id, area_key, sort_order) VALUES
    -- Selong Belanak
    ('torok',        'Torok',          'Torok',          'selong_belanak', 1),
    ('serangan',     'Serangan',       'Serangan',       'selong_belanak', 2),
    ('tampah',       'Tampah',         'Tampah',         'selong_belanak', 3),
    ('lancing',      'Lancing',        'Lancing',        'selong_belanak', 4),
    ('mekarsari',    'Mekarsari',      'Mekarsari',      'selong_belanak', 5),
    -- Kuta
    ('tanjung_aan',  'Tanjung Aan',    'Tanjung Aan',    'kuta', 10),
    ('seger',        'Seger',          'Seger',          'kuta', 11),
    ('merese',       'Merese',         'Merese',         'kuta', 12),
    ('bumbang',      'Bumbang',        'Bumbang',        'kuta', 13),
    ('mertak',       'Mertak',         'Mertak',         'kuta', 14),
    -- Sekotong
    ('pengantap',    'Pengantap',      'Pengantap',      'sekotong', 20),
    ('buwun_mas',    'Buwun Mas',      'Buwun Mas',      'sekotong', 21),
    ('mekaki',       'Mekaki',         'Mekaki',         'sekotong', 22),
    ('gili_gede',    'Gili Gede',      'Gili Gede',      'sekotong', 23),
    ('gili_asahan',  'Gili Asahan',    'Gili Asahan',    'sekotong', 24),
    ('bangko_bangko','Bangko-Bangko',  'Bangko-Bangko',  'sekotong', 25),
    -- Mawi
    ('semeti',       'Semeti',         'Semeti',         'mawi', 30),
    ('rowok',        'Rowok',          'Rowok',          'mawi', 31),
    -- Ekas
    ('pantai_surga', 'Pantai Surga',   'Pantai Surga',   'ekas', 40),
    ('tanjung_ringgit','Tanjung Ringgit','Tanjung Ringgit','ekas', 41),
    ('pink_beach',   'Pink Beach',     'Pantai Pink',    'ekas', 42),
    ('kaliantan',    'Kaliantan',      'Kaliantan',      'ekas', 43),
    ('jerowaru',     'Jerowaru',       'Jerowaru',       'ekas', 44),
    -- Senggigi
    ('batu_layar',   'Batu Layar',     'Batu Layar',     'senggigi', 50),
    ('batu_bolong',  'Batu Bolong',    'Batu Bolong',    'senggigi', 51),
    ('mangsit',      'Mangsit',        'Mangsit',        'senggigi', 52),
    ('malimbu',      'Malimbu',        'Malimbu',        'senggigi', 53),
    ('nipah',        'Nipah',          'Nipah',          'senggigi', 54),
    ('setangi',      'Setangi',        'Setangi',        'senggigi', 55),
    -- North Lombok
    ('sire',         'Sire',           'Sire',           'north_lombok', 60),
    ('medana',       'Medana',         'Medana',         'north_lombok', 61),
    ('tanjung',      'Tanjung',        'Tanjung',        'north_lombok', 62),
    ('bangsal',      'Bangsal',        'Bangsal',        'north_lombok', 63),
    ('pemenang',     'Pemenang',       'Pemenang',       'north_lombok', 64),
    ('senaru',       'Senaru',         'Senaru',         'north_lombok', 65),
    -- Awang
    ('gunung_tunak', 'Gunung Tunak',   'Gunung Tunak',   'awang', 70)
ON DUPLICATE KEY UPDATE label = VALUES(label), label_id = VALUES(label_id), area_key = VALUES(area_key);

-- ---------------------------------------------------------------------
-- 5. Seed aliases (surface-form → place_key + area_key). Synonyms = many rows.
--    ON DUPLICATE updates existing area-only aliases (e.g. mertak, bumbang)
--    to also carry their place_key. uq_area_alias is on (alias_text).
-- ---------------------------------------------------------------------
INSERT INTO area_aliases (alias_text, area_key, place_key) VALUES
    -- Selong Belanak places
    ('torok', 'selong_belanak', 'torok'), ('torok aik belek', 'selong_belanak', 'torok'),
    ('serangan', 'selong_belanak', 'serangan'),
    ('tampah', 'selong_belanak', 'tampah'),
    ('lancing', 'selong_belanak', 'lancing'), ('klancing', 'selong_belanak', 'lancing'),
    ('mekarsari', 'selong_belanak', 'mekarsari'), ('mekar sari', 'selong_belanak', 'mekarsari'),
    -- Kuta places
    ('tanjung aan', 'kuta', 'tanjung_aan'), ('tanjung an', 'kuta', 'tanjung_aan'),
    ('seger', 'kuta', 'seger'), ('merese', 'kuta', 'merese'),
    ('bumbang', 'kuta', 'bumbang'), ('mertak', 'kuta', 'mertak'),
    -- Sekotong places
    ('pengantap', 'sekotong', 'pengantap'),
    ('buwun mas', 'sekotong', 'buwun_mas'), ('buwun mas', 'sekotong', 'buwun_mas'),
    ('mekaki', 'sekotong', 'mekaki'),
    ('gili gede', 'sekotong', 'gili_gede'), ('gili asahan', 'sekotong', 'gili_asahan'),
    ('bangko bangko', 'sekotong', 'bangko_bangko'), ('bangko-bangko', 'sekotong', 'bangko_bangko'),
    ('desert point', 'sekotong', 'bangko_bangko'),
    -- Mawi places
    ('semeti', 'mawi', 'semeti'), ('rowok', 'mawi', 'rowok'),
    -- Ekas places
    ('pantai surga', 'ekas', 'pantai_surga'), ('surga', 'ekas', 'pantai_surga'),
    ('tanjung ringgit', 'ekas', 'tanjung_ringgit'),
    ('pink beach', 'ekas', 'pink_beach'), ('pantai pink', 'ekas', 'pink_beach'), ('tangsi', 'ekas', 'pink_beach'),
    ('kaliantan', 'ekas', 'kaliantan'), ('jerowaru', 'ekas', 'jerowaru'),
    -- Senggigi places
    ('batu layar', 'senggigi', 'batu_layar'), ('batulayar', 'senggigi', 'batu_layar'),
    ('batu bolong', 'senggigi', 'batu_bolong'),
    ('mangsit', 'senggigi', 'mangsit'), ('malimbu', 'senggigi', 'malimbu'),
    ('nipah', 'senggigi', 'nipah'), ('setangi', 'senggigi', 'setangi'),
    ('meninting', 'senggigi', NULL),
    -- North Lombok places
    ('sire', 'north_lombok', 'sire'), ('sira', 'north_lombok', 'sire'), ('medana', 'north_lombok', 'medana'),
    ('bangsal', 'north_lombok', 'bangsal'), ('pemenang', 'north_lombok', 'pemenang'), ('senaru', 'north_lombok', 'senaru'),
    -- Awang (Area) — synonyms, plus its one Place
    ('awang', 'awang', NULL), ('teluk awang', 'awang', NULL), ('awang bay', 'awang', NULL),
    ('gunung tunak', 'awang', 'gunung_tunak'),
    -- Mawun (Area)
    ('mawun', 'mawun', NULL), ('pantai mawun', 'mawun', NULL)
ON DUPLICATE KEY UPDATE area_key = VALUES(area_key), place_key = VALUES(place_key);

-- Done. Next: run the LLM Extractor Mode A pass (worker --reextract) to fill
-- place_key/area_key across the existing corpus, then the nightly Mode B.
