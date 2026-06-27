-- ============================================================================
-- Zoning & Land Check (ADR 0013) — isolated zoning_* schema
-- Build in Lombok · MySQL 8.4 (full spatial support)
--
-- Geometry is stored as planar SRID 0 (X = lng, Y = lat) to avoid MySQL's
-- SRID-4326 lat/long axis-order trap; point-in-polygon containment at Lombok
-- scale is identical and unambiguous.
--
-- Applied to the live DB via tools/dbq.mjs --write (per the apply-DB-changes
-- directly policy). This file is the canonical record for clean re-import.
-- ============================================================================

-- Normalised Land-Use Class taxonomy (our EN/ID layer over official zona codes)
CREATE TABLE IF NOT EXISTS zoning_landuse_classes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  class_key     VARCHAR(64)  NOT NULL,
  name_en       VARCHAR(150) NOT NULL,
  name_id       VARCHAR(150) NOT NULL,
  buildability  ENUM('permitted','restricted','prohibited','unknown') NOT NULL DEFAULT 'unknown',
  villa_allowed TINYINT(1)   NOT NULL DEFAULT 0,
  summary_en    TEXT NULL,
  summary_id    TEXT NULL,
  detail_en     TEXT NULL,
  detail_id     TEXT NULL,
  color         VARCHAR(16) NULL,   -- presentation hint only (Zona Hijau => red)
  sort_order    INT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_key (class_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ingested zoning polygons (RTRW pola ruang + RDTR where available)
CREATE TABLE IF NOT EXISTS zoning_landuse_polys (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  class_key   VARCHAR(64) NOT NULL,
  plan_level  ENUM('rtrw','rdtr','other') NOT NULL DEFAULT 'rtrw',
  kabupaten   VARCHAR(64) NULL,
  raw_zona    VARCHAR(255) NULL,
  geom        GEOMETRY NOT NULL SRID 0,
  kdb         DECIMAL(5,2) NULL,           -- max footprint % (Koef. Dasar Bangunan)
  klb         DECIMAL(5,2) NULL,           -- floor-area ratio (Koef. Lantai Bangunan)
  kkb         DECIMAL(6,2) NULL,           -- max height m (Koef. Ketinggian Bangunan)
  max_floors  TINYINT UNSIGNED NULL,
  attrs       JSON NULL,
  source      VARCHAR(120) NULL,
  source_date DATE NULL,
  confidence  ENUM('indicative','confirmed') NOT NULL DEFAULT 'indicative',
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  SPATIAL INDEX spx_geom (geom),
  KEY idx_class (class_key),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A user's checked Plot (the query subject)
CREATE TABLE IF NOT EXISTS zoning_plots (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NULL,
  lat                DECIMAL(10,7) NOT NULL,
  lng                DECIMAL(10,7) NOT NULL,
  label              VARCHAR(255) NULL,
  nib                VARCHAR(64) NULL,
  resolved_class_key VARCHAR(64) NULL,
  buildability       ENUM('permitted','restricted','prohibited','unknown') NULL,
  snapshot           JSON NULL,
  is_saved           TINYINT(1) NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Paid Site Suitability Report request + concierge lifecycle
CREATE TABLE IF NOT EXISTS zoning_reports (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plot_id          INT UNSIGNED NOT NULL,
  user_id          INT UNSIGNED NULL,
  status           ENUM('requested','invoiced','paid','in_review','delivered','cancelled') NOT NULL DEFAULT 'requested',
  contact_name     VARCHAR(150) NULL,
  contact_email    VARCHAR(190) NULL,
  contact_whatsapp VARCHAR(40) NULL,
  message          TEXT NULL,
  price_idr        BIGINT UNSIGNED NULL,
  invoice_ref      VARCHAR(80) NULL,
  draft_json       JSON NULL,      -- auto-generated Indicative content
  verified_json    JSON NULL,      -- human-verified Confirmed content
  verified_by      INT UNSIGNED NULL,
  verified_at      TIMESTAMP NULL,
  delivered_at     TIMESTAMP NULL,
  admin_notes      TEXT NULL,
  access_token     CHAR(40) NULL,  -- unguessable owner view token
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_plot (plot_id),
  KEY idx_user (user_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Uploaded certificate metadata (file stored OUTSIDE the web root; never served raw)
CREATE TABLE IF NOT EXISTS zoning_cert_uploads (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id     INT UNSIGNED NULL,
  plot_id       INT UNSIGNED NULL,
  user_id       INT UNSIGNED NULL,
  original_name VARCHAR(255) NULL,
  stored_path   VARCHAR(255) NOT NULL,
  mime          VARCHAR(100) NULL,
  size_bytes    INT UNSIGNED NULL,
  sha256        CHAR(64) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- On-demand BHUMI parcel facts cache
CREATE TABLE IF NOT EXISTS zoning_parcel_cache (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cache_key         VARCHAR(80) NOT NULL,   -- nib or rounded "lat,lng"
  lat               DECIMAL(10,7) NULL,
  lng               DECIMAL(10,7) NULL,
  nib               VARCHAR(64) NULL,
  area_m2           DECIMAL(12,2) NULL,
  right_type        VARCHAR(40) NULL,
  registered_status VARCHAR(40) NULL,
  znt_idr           BIGINT UNSIGNED NULL,
  geojson           JSON NULL,
  raw               JSON NULL,
  source            VARCHAR(60) NULL,
  fetched_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tool config (key/value), mirrors drab_config
CREATE TABLE IF NOT EXISTS zoning_config (
  cfg_key   VARCHAR(64) NOT NULL,
  cfg_value TEXT NULL,
  PRIMARY KEY (cfg_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
