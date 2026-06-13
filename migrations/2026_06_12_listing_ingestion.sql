-- =====================================================================
-- Build in Lombok — Automated listing ingestion + agent identity/reputation
-- Pairs with: docs/adr/0007-home-worker-listing-ingestion.md
--             docs/adr/0008-cross-portal-agent-identity-and-reputation.md
--
-- Run in the phpMyAdmin SQL tab (it understands DELIMITER). Idempotent:
-- every column/index add is guarded by information_schema, every table is
-- CREATE TABLE IF NOT EXISTS, and every seed uses INSERT IGNORE / ON DUPLICATE.
-- Safe to re-run.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 0. Idempotency helpers (guarded column / index add via dynamic SQL)
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS bil_add_col;
DROP PROCEDURE IF EXISTS bil_add_index;

DELIMITER $$

CREATE PROCEDURE bil_add_col(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE bil_add_index(IN p_tbl VARCHAR(64), IN p_idx VARCHAR(64), IN p_cols TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND INDEX_NAME = p_idx
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_tbl, '` ADD INDEX `', p_idx, '` (', p_cols, ')');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$

DELIMITER ;


-- ---------------------------------------------------------------------
-- 1. listings — re-check bookkeeping, per-field locks, price review flag
--    (docs/adr/0007)
-- ---------------------------------------------------------------------

CALL bil_add_col('listings', 'first_seen_at',      'first_seen_at TIMESTAMP NULL DEFAULT NULL');
CALL bil_add_col('listings', 'last_seen_at',       'last_seen_at TIMESTAMP NULL DEFAULT NULL');
CALL bil_add_col('listings', 'last_rechecked_at',  'last_rechecked_at TIMESTAMP NULL DEFAULT NULL');
CALL bil_add_col('listings', 'recheck_status',     "recheck_status VARCHAR(20) NULL DEFAULT NULL");
CALL bil_add_col('listings', 'recheck_fail_count', 'recheck_fail_count INT UNSIGNED NOT NULL DEFAULT 0');
-- comma-separated list of column names an admin hand-edited; the Worker never
-- overwrites a locked field (ADR 0007 "Locked Field").
CALL bil_add_col('listings', 'locked_fields',      'locked_fields VARCHAR(500) NULL DEFAULT NULL');
-- 1 = price could not be trusted at ingest (e.g. per-are with no size); the
-- listing shows as Price on Request and sits in the review queue.
CALL bil_add_col('listings', 'price_review_flag',  'price_review_flag TINYINT(1) NOT NULL DEFAULT 0');

-- Backfill first_seen / last_seen from the existing scrape timestamp so tenure
-- and the rolling re-check window have a starting point.
UPDATE listings
   SET first_seen_at = COALESCE(first_seen_at, source_scraped_at, created_at)
 WHERE first_seen_at IS NULL;
UPDATE listings
   SET last_seen_at = COALESCE(last_seen_at, source_scraped_at, created_at)
 WHERE last_seen_at IS NULL;

-- Drives the nightly rolling window: oldest-checked first.
CALL bil_add_index('listings', 'idx_listings_recheck', 'status, last_rechecked_at');
CALL bil_add_index('listings', 'idx_listings_source',  'source_site, source_listing_id');


-- ---------------------------------------------------------------------
-- 2. agents — classification + canonical-merge pointer + reputation
--    (docs/adr/0008)
-- ---------------------------------------------------------------------

-- 'agent' = browsable named agency/agent; 'private_seller' = hidden shared
-- bucket (kept attributable, excluded from directory + reputation).
CALL bil_add_col('agents', 'agent_kind',            "agent_kind VARCHAR(20) NOT NULL DEFAULT 'agent'");
-- when set, this row was merged into another canonical agent and must not be
-- shown on its own.
CALL bil_add_col('agents', 'merged_into_agent_id',  'merged_into_agent_id INT UNSIGNED NULL DEFAULT NULL');
CALL bil_add_col('agents', 'reputation_score',      'reputation_score INT UNSIGNED NOT NULL DEFAULT 0');
CALL bil_add_col('agents', 'reputation_tier',       "reputation_tier VARCHAR(20) NOT NULL DEFAULT 'new'");
CALL bil_add_col('agents', 'listings_total',        'listings_total INT UNSIGNED NOT NULL DEFAULT 0');
CALL bil_add_col('agents', 'listings_active',       'listings_active INT UNSIGNED NOT NULL DEFAULT 0');
CALL bil_add_col('agents', 'first_seen_at',         'first_seen_at TIMESTAMP NULL DEFAULT NULL');
CALL bil_add_col('agents', 'reputation_updated_at', 'reputation_updated_at TIMESTAMP NULL DEFAULT NULL');

CALL bil_add_index('agents', 'idx_agents_kind',    'agent_kind, is_active');
CALL bil_add_index('agents', 'idx_agents_merged',  'merged_into_agent_id');
CALL bil_add_index('agents', 'idx_agents_rep',     'reputation_score');

-- Classify existing placeholder rows -------------------------------------------------
-- Private sellers: the shared per-site buckets created by the importer.
UPDATE agents
   SET agent_kind = 'private_seller'
 WHERE source_agent_id LIKE '%private_seller%'
    OR LOWER(display_name) REGEXP 'private seller';

-- Platform placeholders: the portal's own name as a "seller". Detach their
-- listings (agent_id = NULL) and deactivate the row — never browsable.
UPDATE listings l
   JOIN agents a ON a.id = l.agent_id
    SET l.agent_id = NULL
 WHERE LOWER(TRIM(a.display_name)) IN ('lamudi','rumah123','rumah 123','dotproperty','dot property','olx','olx indonesia');

UPDATE agents
   SET is_active = 0, agent_kind = 'platform'
 WHERE LOWER(TRIM(display_name)) IN ('lamudi','rumah123','rumah 123','dotproperty','dot property','olx','olx indonesia');


-- ---------------------------------------------------------------------
-- 3. agent_sources — one canonical Agent, many portal profiles (docs/adr/0008)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS agent_sources (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id          INT UNSIGNED NOT NULL,          -- canonical agents.id
    source_site       VARCHAR(30)  NOT NULL,
    source_agent_id   VARCHAR(190) NOT NULL,
    source_profile_url VARCHAR(500) NULL DEFAULT NULL,
    source_display_name VARCHAR(190) NULL DEFAULT NULL,
    phone_digits      VARCHAR(30)  NULL DEFAULT NULL, -- normalised, for cross-portal merge
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_source (source_site, source_agent_id),
    KEY idx_agent_sources_agent (agent_id),
    KEY idx_agent_sources_phone (phone_digits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill 1:1 from existing agents (each existing agent becomes its own source).
-- Phone-based cross-portal merging is done afterwards by the canonicaliser /
-- the ingest admin console, never blindly in SQL.
INSERT IGNORE INTO agent_sources
    (agent_id, source_site, source_agent_id, source_profile_url, source_display_name, phone_digits)
SELECT a.id,
       COALESCE(NULLIF(a.source_site,''), 'unknown'),
       COALESCE(NULLIF(a.source_agent_id,''), CONCAT('agent_', a.id)),
       a.source_profile_url,
       a.display_name,
       -- 5.7-safe partial strip (+, space, -, (, ), .); PHP does full
       -- normalisation on the next ingest. Good enough to seed merge hints.
       NULLIF(
         REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
           COALESCE(a.whatsapp_number, a.phone, ''),
           '+',''),' ',''),'-',''),'(',''),')',''),'.',''),
         '')
FROM agents a;


-- ---------------------------------------------------------------------
-- 4. area_aliases — kecamatan/desa string -> canonical area_key (docs/adr/0007)
--    Seeded from the old keyword map so we start with real coverage; unmapped
--    locations are queued for one-time admin mapping, never defaulted.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS area_aliases (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alias_text  VARCHAR(190) NOT NULL,            -- normalised (lowercase, trimmed)
    area_key    VARCHAR(50)  NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_area_alias (alias_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO area_aliases (alias_text, area_key) VALUES
    ('kuta', 'kuta'), ('kuta mandalika', 'kuta'), ('mandalika', 'kuta'),
    ('tanjung aan', 'kuta'), ('tampah', 'kuta'), ('pujut', 'kuta'),
    ('selong belanak', 'selong_belanak'), ('belanak', 'selong_belanak'),
    ('ekas', 'ekas'), ('jerowaru', 'ekas'),
    ('senggigi', 'senggigi'), ('batu layar', 'senggigi'), ('batulayar', 'senggigi'), ('batu bolong', 'senggigi'),
    ('mataram', 'mataram'), ('cakranegara', 'mataram'), ('ampenan', 'mataram'), ('sekarbela', 'mataram'), ('sandubaya', 'mataram'),
    ('tanjung', 'north_lombok'), ('gangga', 'north_lombok'), ('bayan', 'north_lombok'), ('kayangan', 'north_lombok'), ('senaru', 'north_lombok'),
    ('gili', 'gili_islands'), ('gili trawangan', 'gili_islands'), ('gili air', 'gili_islands'), ('gili meno', 'gili_islands'),
    ('mawi', 'mawi'), ('are guling', 'are_guling'), ('areguling', 'are_guling'),
    ('gerupuk', 'gerupuk'), ('sekotong', 'sekotong'),
    ('praya', 'praya'), ('batujai', 'praya'), ('penujak', 'praya'),
    ('batukliang', 'praya'), ('jonggat', 'praya'), ('kopang', 'praya'), ('janapria', 'praya'),
    -- Pujut district (south coast) villages = the Kuta/Mandalika market area, NOT Praya town.
    ('pujut', 'kuta'), ('sengkol', 'kuta'), ('mertak', 'kuta'), ('pengembur', 'kuta'),
    ('rembitan', 'kuta'), ('sukadana', 'kuta'), ('bumbang', 'kuta'), ('prabu', 'kuta');


-- ---------------------------------------------------------------------
-- 5. listing_review_queue — surprises + unmapped locations (docs/adr/0007)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS listing_review_queue (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id  INT UNSIGNED NULL DEFAULT NULL,   -- NULL for a brand-new candidate
    kind        VARCHAR(40)  NOT NULL,            -- unmapped_area | price_surprise | area_flip | per_are_no_size | ambiguous_agent
    detail      TEXT NULL DEFAULT NULL,           -- JSON: proposed vs current, raw fields, source
    status      VARCHAR(20)  NOT NULL DEFAULT 'open',  -- open | resolved | dismissed
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_review_status (status, kind),
    KEY idx_review_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 6. discovery_sources — the search pages the Worker scans for NEW listings
--    (docs/adr/0007). Lamudi + Rumah123 + dotproperty only; NOT OLX (owns
--    Lamudi -> duplicates). Admin can edit/add rows.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS discovery_sources (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_site VARCHAR(30)  NOT NULL,
    label       VARCHAR(120) NOT NULL,
    search_url  VARCHAR(500) NOT NULL,
    max_pages   INT UNSIGNED NOT NULL DEFAULT 3,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    last_run_at TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_discovery_url (search_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO discovery_sources (source_site, label, search_url, max_pages, is_active) VALUES
    ('lamudi',      'Lamudi — Lombok Tengah land',   'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-tengah/tanah/', 5, 1),
    ('lamudi',      'Lamudi — Lombok Tengah houses', 'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-tengah/rumah/', 3, 1),
    ('lamudi',      'Lamudi — Lombok Barat land',    'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-barat/tanah/',  4, 1),
    ('lamudi',      'Lamudi — Lombok Utara land',    'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-utara/tanah/',  4, 1),
    ('rumah123',    'Rumah123 — Lombok land',  'https://www.rumah123.com/jual/lombok-tengah/tanah/',   3, 1),
    ('rumah123',    'Rumah123 — Lombok houses','https://www.rumah123.com/jual/lombok-tengah/rumah/',   3, 1),
    ('dotproperty', 'DotProperty — Lombok',    'https://www.dotproperty.id/en/properties-for-sale/lombok', 3, 1);


-- ---------------------------------------------------------------------
-- 7. Clean up the helper procedures
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS bil_add_col;
DROP PROCEDURE IF EXISTS bil_add_index;

-- Done. Next: run the one-time corrector at admin/recanonicalize_listings.php
-- (dry-run first), then point the home Worker at api/listing_ingest.php.
