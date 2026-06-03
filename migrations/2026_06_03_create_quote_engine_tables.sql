-- =============================================================
-- Migration: Quote Engine ("Find Me a Quote") — core tables + seeds
-- Date: 2026-06-03
-- Purpose: automated WhatsApp quote-gathering + a curated historical
--          material price index that feeds the RAB tool.
--          See docs/adr/0002, 0003, 0004 and CONTEXT.md for the why.
-- =============================================================
-- Run on: MySQL 5.7+ / MariaDB 10.2+ (HostPapa shared hosting).
-- Run via phpMyAdmin or mysql CLI against the biltest database.
-- Safe to re-run: every statement is idempotent
--   (CREATE TABLE IF NOT EXISTS, INSERT IGNORE, INSERT ... WHERE NOT EXISTS).
--
-- NOTES
--  * IDs are INT UNSIGNED per project convention (CLAUDE.md). FK columns
--    point at users / providers / rab_materials / rab_units by id.
--  * No hard FOREIGN KEY constraints — matches the existing house style
--    (e.g. provider_quotes). Referential integrity is enforced in PHP;
--    logical references are documented in column comments.
--  * `ai_payload` uses the JSON type. On MariaDB this is an alias for
--    LONGTEXT; on MySQL 5.7+ it is native. If your DB predates either,
--    change `JSON` to `LONGTEXT` (the app does not rely on JSON functions).
--  * Money: `unit_price` is BIGINT in the row's `currency` (IDR is the
--    common case and is stored as whole rupiah, per project convention).
-- =============================================================


-- ── 1. quote_requests — the master job (a Quote Request) ──────
CREATE TABLE IF NOT EXISTS quote_requests (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,                      -- -> users.id (the paying user)
  status            ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
  delivery_required TINYINT(1) NOT NULL DEFAULT 0,
  delivery_location VARCHAR(255) DEFAULT NULL,
  delivery_maps_url VARCHAR(500) DEFAULT NULL,
  message_lang      ENUM('en','id') NOT NULL DEFAULT 'en',
  message_body      TEXT DEFAULT NULL,                          -- snapshot of the generated WhatsApp message
  is_active         TINYINT(1) NOT NULL DEFAULT 1,              -- soft delete
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_qr_user   (user_id),
  KEY idx_qr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 2. quote_request_items — structured items (matrix rows) ───
CREATE TABLE IF NOT EXISTS quote_request_items (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id      INT UNSIGNED NOT NULL,                        -- -> quote_requests.id
  material        VARCHAR(255) NOT NULL,                        -- user's free text
  quantity        VARCHAR(100) DEFAULT NULL,
  info            VARCHAR(255) DEFAULT NULL,                    -- colour / size / spec
  rab_material_id INT UNSIGNED DEFAULT NULL,                    -- -> rab_materials.id (curated, nullable)
  sort_order      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qri_request  (request_id),
  KEY idx_qri_material (rab_material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 3. quote_vendor_chats — one channel per Vendor per job ────
CREATE TABLE IF NOT EXISTS quote_vendor_chats (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id                INT UNSIGNED NOT NULL,              -- -> quote_requests.id
  provider_id               INT UNSIGNED NOT NULL,              -- -> providers.id (Vendor = Provider)
  vendor_phone              VARCHAR(32) NOT NULL,               -- snapshot of the number messaged
  state                     ENUM('queued','awaiting_reply','info_received','needs_admin','closed')
                              NOT NULL DEFAULT 'queued',
  stock_status              ENUM('available','out_of_stock','unknown') NOT NULL DEFAULT 'unknown',
  follow_up_count           INT UNSIGNED NOT NULL DEFAULT 0,    -- enforces the auto-follow-up cap (ADR 0004)
  admin_intervention        TINYINT(1) NOT NULL DEFAULT 0,
  admin_intervention_reason VARCHAR(255) DEFAULT NULL,
  last_inbound_at           TIMESTAMP NULL DEFAULT NULL,
  last_outbound_at          TIMESTAMP NULL DEFAULT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qvc_request_provider (request_id, provider_id),  -- one chat per Vendor per Request
  KEY idx_qvc_provider (provider_id),                            -- layered routing lookup (Q9)
  KEY idx_qvc_state    (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 4. quote_messages — every message; also the work queues ───
-- Outbound queue = WHERE direction='outbound' AND status='queued'
-- Parse queue    = WHERE direction='inbound'  AND parse_status='pending'
CREATE TABLE IF NOT EXISTS quote_messages (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id                INT UNSIGNED NOT NULL,                 -- -> quote_vendor_chats.id
  direction              ENUM('outbound','inbound') NOT NULL,
  sender_kind            ENUM('user','agent_auto','admin','vendor') NOT NULL,
  status                 ENUM('queued','sending','sent','failed','received')
                           NOT NULL DEFAULT 'queued',          -- outbound lifecycle; idempotent claim (Q5)
  parse_status           ENUM('na','pending','parsed','failed') NOT NULL DEFAULT 'na',
  wa_message_id          VARCHAR(128) DEFAULT NULL,            -- Evolution key.id of the sent/received message
  reply_to_wa_message_id VARCHAR(128) DEFAULT NULL,            -- contextInfo.stanzaId for reply-quote routing
  body_raw               TEXT,                                 -- exact text (in or out)
  body_expanded_id       TEXT,                                 -- clean Indonesian (inbound)
  body_translated_en     TEXT,                                 -- English translation (inbound)
  intent                 VARCHAR(40) DEFAULT NULL,
  ai_payload             JSON DEFAULT NULL,                    -- full structured model output (see header note)
  ai_suggested_response  TEXT,                                 -- LLM draft reply (suggestion, NOT auto-sent — ADR 0004)
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at                TIMESTAMP NULL DEFAULT NULL,
  received_at            TIMESTAMP NULL DEFAULT NULL,
  parsed_at              TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_qm_chat          (chat_id),
  KEY idx_qm_outbound_queue (direction, status),
  KEY idx_qm_parse_queue    (direction, parse_status),
  KEY idx_qm_wa            (wa_message_id),
  KEY idx_qm_replyto       (reply_to_wa_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 5. historical_material_prices — the Price Point index ─────
CREATE TABLE IF NOT EXISTS historical_material_prices (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id              INT UNSIGNED NOT NULL,               -- -> quote_messages.id (source)
  chat_id                 INT UNSIGNED NOT NULL,               -- -> quote_vendor_chats.id
  request_item_id         INT UNSIGNED DEFAULT NULL,           -- -> quote_request_items.id (AI in-job match)
  provider_id             INT UNSIGNED NOT NULL,               -- -> providers.id (who quoted)
  rab_material_id         INT UNSIGNED DEFAULT NULL,           -- -> rab_materials.id; NULL = not yet in global index (ADR 0003)
  vendor_item_label       VARCHAR(255) NOT NULL,               -- AI item_label, the vendor's own words
  unit_price              BIGINT DEFAULT NULL,                 -- amount in `currency`
  unit                    VARCHAR(40) DEFAULT NULL,            -- vendor's unit (sak, m3, batang, ...)
  quantity                VARCHAR(100) DEFAULT NULL,
  currency                CHAR(3) NOT NULL DEFAULT 'IDR',
  price_includes_delivery TINYINT(1) DEFAULT NULL,             -- NULL = unknown / unmentioned
  quoted_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hmp_catalog      (rab_material_id, quoted_at),       -- global index / trend reads
  KEY idx_hmp_provider     (provider_id),
  KEY idx_hmp_request_item (request_item_id),
  KEY idx_hmp_message      (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 6. material_aliases — self-improving normalization ────────
CREATE TABLE IF NOT EXISTS material_aliases (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alias_text      VARCHAR(255) NOT NULL,                       -- normalised label, e.g. "semen tiga roda"
  rab_material_id INT UNSIGNED NOT NULL,                       -- -> rab_materials.id
  unit_id         INT UNSIGNED DEFAULT NULL,                   -- -> rab_units.id
  created_by      INT UNSIGNED DEFAULT NULL,                   -- -> users.id (admin who mapped it)
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias_text (alias_text),
  KEY idx_ma_material (rab_material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 7. plan_limits — tunable per-tier numeric quotas ──────────
CREATE TABLE IF NOT EXISTS plan_limits (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tier        VARCHAR(20) NOT NULL,                            -- 'free' | 'basic' | 'premium'
  limit_key   VARCHAR(50) NOT NULL,                            -- e.g. 'quote_requests_per_30d'
  limit_value INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pl_tier_key (tier, limit_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =============================================================
-- SEEDS
-- =============================================================

-- Feature gates (idempotent; feature_key guarded via WHERE NOT EXISTS).
-- Move between tiers later by toggling tier_basic / tier_premium in the
-- admin console — never in code.
INSERT INTO feature_access
  (feature_key, feature_label, description, tier_free, tier_basic, tier_premium, require_login, is_active, sort_order)
SELECT 'quote_engine',
       'Quote Engine (Find Me a Quote)',
       'Automated WhatsApp quote gathering from vendors with AI parsing and a live price-comparison dashboard.',
       0, 1, 1, 1, 1,
       (SELECT COALESCE(MAX(s.sort_order),0)+1 FROM (SELECT sort_order FROM feature_access) s)
WHERE NOT EXISTS (SELECT 1 FROM (SELECT feature_key FROM feature_access) f WHERE f.feature_key = 'quote_engine');

INSERT INTO feature_access
  (feature_key, feature_label, description, tier_free, tier_basic, tier_premium, require_login, is_active, sort_order)
SELECT 'quote_price_history',
       'Material Price History',
       'Cross-vendor historical price trends drawn from the curated material index.',
       0, 0, 1, 1, 1,
       (SELECT COALESCE(MAX(s.sort_order),0)+1 FROM (SELECT sort_order FROM feature_access) s)
WHERE NOT EXISTS (SELECT 1 FROM (SELECT feature_key FROM feature_access) f WHERE f.feature_key = 'quote_price_history');

-- Per-tier quotas (idempotent via the unique key). Tune in admin later.
INSERT IGNORE INTO plan_limits (tier, limit_key, limit_value) VALUES
  ('basic',   'quote_requests_per_30d',  5),
  ('premium', 'quote_requests_per_30d', 15),
  ('basic',   'vendors_per_request',    12),
  ('premium', 'vendors_per_request',    12);

-- =============================================================
-- End of migration.
-- =============================================================
