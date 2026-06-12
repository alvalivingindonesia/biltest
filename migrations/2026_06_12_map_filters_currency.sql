-- =====================================================================
-- Build in Lombok — Interactive map, dynamic filters, canonical currency
-- Run in phpMyAdmin. Safe to re-run (guards on every statement).
-- Pairs with: docs/adr/0005-custom-svg-region-map.md
--             docs/adr/0006-canonical-idr-listing-price.md
-- The site works WITHOUT this migration (PHP fallbacks); running it turns
-- on exact tag filtering, Indonesian labels, and fixes price filtering
-- for single-currency listings.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Indonesian labels (label_id) for lookup tables
-- ---------------------------------------------------------------------

UPDATE area_regions SET label_id = 'Lombok Selatan' WHERE region_key = 'south_lombok';
UPDATE area_regions SET label_id = 'Lombok Barat'   WHERE region_key = 'west_lombok';
UPDATE area_regions SET label_id = 'Lombok Tengah'  WHERE region_key = 'central_lombok';
UPDATE area_regions SET label_id = 'Lombok Timur'   WHERE region_key = 'east_lombok';
UPDATE area_regions SET label_id = 'Lombok Utara'   WHERE region_key = 'north_lombok';
UPDATE area_regions SET label_id = 'Kepulauan Gili' WHERE region_key = 'gili_islands';

UPDATE listing_types SET label_id = 'Tanah'               WHERE `key` = 'land';
UPDATE listing_types SET label_id = 'Vila'                WHERE `key` = 'villa';
UPDATE listing_types SET label_id = 'Rumah'               WHERE `key` = 'house';
UPDATE listing_types SET label_id = 'Apartemen'           WHERE `key` = 'apartment';
UPDATE listing_types SET label_id = 'Properti Komersial'  WHERE `key` = 'commercial';
UPDATE listing_types SET label_id = 'Gudang'              WHERE `key` = 'warehouse';
UPDATE listing_types SET label_id = 'Sewa Jangka Panjang' WHERE `key` = 'long_term_rental';

UPDATE land_certificate_types SET label_id = 'SHM (Sertifikat Hak Milik)' WHERE `key` = 'shm';
UPDATE land_certificate_types SET label_id = 'HGB (Hak Guna Bangunan)'    WHERE `key` = 'hgb';
UPDATE land_certificate_types SET label_id = 'Hak Pakai'                  WHERE `key` = 'hak_pakai';
UPDATE land_certificate_types SET label_id = 'Girik / Letter C'           WHERE `key` = 'girik';
UPDATE land_certificate_types SET label_id = 'Tanah Adat'                 WHERE `key` = 'adat';
UPDATE land_certificate_types SET label_id = 'Lainnya'                    WHERE `key` = 'other';

-- Areas are proper nouns; only the descriptive ones get Indonesian labels
UPDATE areas SET label_id = 'Kepulauan Gili'             WHERE `key` = 'gili_islands';
UPDATE areas SET label_id = 'Lombok Utara'               WHERE `key` = 'north_lombok';
UPDATE areas SET label_id = 'Teluk Ekas / Lombok Timur'  WHERE `key` = 'ekas';
UPDATE areas SET label_id = 'Lombok Lainnya'             WHERE `key` = 'other_lombok';


-- ---------------------------------------------------------------------
-- 2. Feature Tags lookup table (canonical amenity keys, EN + ID labels)
--    applies_to: 'all' or a comma list of listing_type keys
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS feature_tags (
    `key`      varchar(50)  NOT NULL,
    label      varchar(100) NOT NULL,
    label_id   varchar(100) DEFAULT NULL,
    applies_to varchar(255) NOT NULL DEFAULT 'all',
    sort_order int UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO feature_tags (`key`, label, label_id, applies_to, sort_order) VALUES
    ('beachfront',      'Beachfront',      'Tepi Pantai',        'all', 1),
    ('ocean_view',      'Ocean View',      'Pemandangan Laut',   'all', 2),
    ('mountain_view',   'Mountain View',   'Pemandangan Gunung', 'all', 3),
    ('rice_field_view', 'Rice Field View', 'Pemandangan Sawah',  'all', 4),
    ('cliff_top',       'Cliff Top',       'Atas Tebing',        'all', 5),
    ('near_airport',    'Near Airport',    'Dekat Bandara',      'all', 6),
    ('pool',            'Swimming Pool',   'Kolam Renang',       'villa,house,apartment,commercial,long_term_rental', 7),
    ('furnished',       'Furnished',       'Berperabot',         'villa,house,apartment,commercial,long_term_rental', 8)
ON DUPLICATE KEY UPDATE label = VALUES(label), label_id = VALUES(label_id), applies_to = VALUES(applies_to), sort_order = VALUES(sort_order);


-- ---------------------------------------------------------------------
-- 3. Backfill listing_tags from bilingual keyword scan of title/descriptions
--    (NOT EXISTS guard makes every statement idempotent)
-- ---------------------------------------------------------------------

INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'beachfront' FROM listings l
WHERE LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'beachfront|beach front|tepi pantai|pinggir pantai|depan pantai'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'beachfront');

INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'ocean_view' FROM listings l
WHERE LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'ocean view|sea view|seaview|oceanview|pemandangan laut|view laut'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'ocean_view');

INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'mountain_view' FROM listings l
WHERE LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'mountain view|rinjani view|view of (mount )?rinjani|pemandangan gunung|view gunung'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'mountain_view');

INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'rice_field_view' FROM listings l
WHERE LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'rice ?field|rice ?paddy|paddy view|sawah'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'rice_field_view');

INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'cliff_top' FROM listings l
WHERE LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'cliff ?top|clifftop|cliff front|on the cliff|cliff edge|tebing'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'cliff_top');

INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'near_airport' FROM listings l
WHERE LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'airport|bandara'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'near_airport');

-- pool / furnished: built property types only
INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'pool' FROM listings l
WHERE l.listing_type_key IN ('villa','house','apartment','commercial','long_term_rental')
  AND LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'swimming ?pool|private pool|plunge pool|kolam renang'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'pool');

INSERT INTO listing_tags (listing_id, tag)
SELECT l.id, 'furnished' FROM listings l
WHERE l.listing_type_key IN ('villa','house','apartment','commercial','long_term_rental')
  AND LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) REGEXP 'fully furnished|full furnished|semi furnished|full furnish|berperabot'
  AND LOWER(CONCAT_WS(' ', l.title, IFNULL(l.short_description,''), IFNULL(l.description,''))) NOT REGEXP 'unfurnished'
  AND NOT EXISTS (SELECT 1 FROM listing_tags t WHERE t.listing_id = l.id AND t.tag = 'furnished');


-- ---------------------------------------------------------------------
-- 4. Canonical IDR price backfill (docs/adr/0006)
--    Every priced listing gets price_idr; filtering/sorting use it only.
-- ---------------------------------------------------------------------

-- Allow very small rates (e.g. IDR→USD 0.000061) before the cron writes them
ALTER TABLE currency_rates MODIFY rate DECIMAL(18,8) NOT NULL;

UPDATE listings l
JOIN currency_rates r ON r.from_currency = 'USD' AND r.to_currency = 'IDR'
SET l.price_idr = ROUND(l.price_usd * r.rate)
WHERE (l.price_idr IS NULL OR l.price_idr = 0)
  AND l.price_usd IS NOT NULL AND l.price_usd > 0;

UPDATE listings l
JOIN currency_rates r ON r.from_currency = 'EUR' AND r.to_currency = 'IDR'
SET l.price_idr = ROUND(l.price_eur * r.rate)
WHERE (l.price_idr IS NULL OR l.price_idr = 0)
  AND l.price_eur IS NOT NULL AND l.price_eur > 0;

UPDATE listings l
JOIN currency_rates r ON r.from_currency = 'AUD' AND r.to_currency = 'IDR'
SET l.price_idr = ROUND(l.price_aud * r.rate)
WHERE (l.price_idr IS NULL OR l.price_idr = 0)
  AND l.price_aud IS NOT NULL AND l.price_aud > 0;


-- ---------------------------------------------------------------------
-- 5. Indexes for the new query shapes (MariaDB: IF NOT EXISTS supported)
-- ---------------------------------------------------------------------

ALTER TABLE listings     ADD INDEX IF NOT EXISTS idx_listings_price_idr (price_idr);
ALTER TABLE listings     ADD INDEX IF NOT EXISTS idx_listings_area_status (area_key, status, is_approved);
ALTER TABLE listing_tags ADD INDEX IF NOT EXISTS idx_listing_tags_tag (tag, listing_id);
