-- =====================================================================
-- Build in Lombok — Facebook tukang/specialist candidate staging table
--
-- A vetting + outreach staging area in FRONT of `providers`. Tradespeople
-- ("tukang") and specialists discovered on Facebook (Groups, Marketplace,
-- Pages) land here as low-trust CANDIDATES — never directly in the public
-- directory. They are gated on the FB quality bar (HIGH activity + an
-- OBVIOUS body of completed work, not a mere claim of skill), vetted, and
-- only then PROMOTED into `providers` (gaining `facebook_url`).
--
-- Security (CONTEXT.md — external input is hostile): FB names/phones are
-- third-party PII scraped from an untrusted source. This table is NOT served
-- by api/index.php (admin-only), all writes are parameterized, and nothing
-- here is public until an admin promotes a vetted row.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Single statement — runs via the SQL
-- console (api/db_console.php) and phpMyAdmin alike. Safe to re-run.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `dir_fb_candidates` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- ── provenance ──────────────────────────────────────────────────────
  `source_site`          VARCHAR(20)  NOT NULL DEFAULT 'facebook', -- future: instagram, tiktok
  `source_group`         VARCHAR(255)     NULL,                    -- FB group/page name we found them in
  `source_url`           VARCHAR(500)     NULL,                    -- link to the post/profile/listing
  `fb_profile_url`       VARCHAR(500)     NULL,                    -- their profile/page (dedupe + promote target)

  -- ── identity ────────────────────────────────────────────────────────
  `name`                 VARCHAR(200) NOT NULL,
  `trade_text`           VARCHAR(255)     NULL,                    -- raw stated trade ("tukang las", "jasa bangun rumah")
  `description`          TEXT             NULL,                    -- captured verbatim service blurb

  -- ── contact ─────────────────────────────────────────────────────────
  `phone`                VARCHAR(30)      NULL,
  `whatsapp_number`      VARCHAR(30)      NULL,
  `phone_norm`           VARCHAR(20)      NULL,                    -- normalized last-11 digits, dedupe key

  -- ── location (text-based gate; no GPS on FB) ────────────────────────
  `location_text`        VARCHAR(255)     NULL,                    -- free-text stated location
  `area_key`             VARCHAR(50)      NULL,                    -- mapped Area (NULL until vetted)
  `lombok_confidence`    ENUM('lombok','maybe','off_island','unknown') NOT NULL DEFAULT 'unknown',

  -- ── suggested taxonomy (filled at vet/promote time) ─────────────────
  `suggested_group_key`     VARCHAR(50)   NULL,
  `suggested_category_key`  VARCHAR(50)   NULL,

  -- ── FB QUALITY BAR: high activity + obvious body of work ────────────
  `activity_level`       ENUM('low','medium','high','unknown') NOT NULL DEFAULT 'unknown',
  `work_photo_count`     INT UNSIGNED     NULL,                    -- # of obvious completed-job photos seen
  `recent_post_date`     DATE             NULL,                    -- most recent post we observed
  `engagement_note`      VARCHAR(500)     NULL,                    -- recommendations / comments / reactions evidence
  `quality_signals`      TEXT             NULL,                    -- raw signals blob (transparency / audit)

  -- ── workflow + outreach ─────────────────────────────────────────────
  `status` ENUM('new','vetted','rejected','contacted','replied','joined','declined')
                         NOT NULL DEFAULT 'new',
  `vetting_note`         TEXT             NULL,                    -- why accepted/rejected
  `promoted_provider_id` INT UNSIGNED     NULL,                    -- set when graduated into `providers`
  `dedupe_provider_id`   INT UNSIGNED     NULL,                    -- existing provider matched by phone

  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE  KEY `uq_fb_profile`  (`fb_profile_url`(190)),
  KEY      `ix_phone_norm`     (`phone_norm`),
  KEY      `ix_status`         (`status`),
  KEY      `ix_lombok_conf`    (`lombok_confidence`),
  KEY      `ix_source_group`   (`source_group`(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
