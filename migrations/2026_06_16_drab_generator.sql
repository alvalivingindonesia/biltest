-- ============================================================================
-- Detailed RAB Generator (ADR 0012) — schema + structural lookups
-- Namespace: drab_*  (isolated; old rab_* tables stay frozen as a backup)
-- Stack: MySQL 5.7+/MariaDB 10.3+, PHP 7.4. InnoDB, utf8mb4.
-- Run this FIRST, then run 2026_06_16_drab_seed.sql for catalog + prices.
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Lookups & engine
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drab_units (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code      VARCHAR(12)  NOT NULL,
  name_en   VARCHAR(60)  NOT NULL,
  name_id   VARCHAR(60)  NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_unit_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_config (
  cfg_key   VARCHAR(60)  NOT NULL,
  cfg_value VARCHAR(255) NOT NULL,
  note      VARCHAR(255) NULL,
  PRIMARY KEY (cfg_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Base material/labour/equipment prices (the AHSP build-up inputs).
CREATE TABLE IF NOT EXISTS drab_base_prices (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind       ENUM('bahan','upah','alat') NOT NULL,
  code       VARCHAR(16)  NOT NULL,
  name_en    VARCHAR(120) NOT NULL,
  name_id    VARCHAR(120) NOT NULL,
  spec       VARCHAR(120) NULL,
  unit_id    INT UNSIGNED NULL,
  zone       ENUM('mataram','south') NOT NULL DEFAULT 'mataram',
  price      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  confidence ENUM('indicative','confirmed') NOT NULL DEFAULT 'indicative',
  basis      VARCHAR(40)  NOT NULL DEFAULT 'estimate',
  source     VARCHAR(200) NULL,
  priced_on  DATE NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_base (code, zone),
  KEY idx_base_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Work items = the priced "Supply & Install" catalog (pekerjaan).
CREATE TABLE IF NOT EXISTS drab_work_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(16)  NOT NULL,
  discipline    ENUM('PREP','STR','ARCH','MEP') NOT NULL,
  section_group VARCHAR(40)  NOT NULL,
  name_en       VARCHAR(180) NOT NULL,
  name_id       VARCHAR(180) NOT NULL,
  spec_en       VARCHAR(180) NULL,
  spec_id       VARCHAR(180) NULL,
  unit_id       INT UNSIGNED NOT NULL,
  spec_slot     VARCHAR(40)  NULL,
  is_pc_sum     TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_wi_code (code),
  KEY idx_wi_disc (discipline),
  KEY idx_wi_slot (spec_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One price row per (work item, zone). Material + labour stored separately always.
CREATE TABLE IF NOT EXISTS drab_work_item_prices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_item_id  INT UNSIGNED NOT NULL,
  zone          ENUM('mataram','south') NOT NULL DEFAULT 'mataram',
  material_rate BIGINT UNSIGNED NOT NULL DEFAULT 0,
  labour_rate   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  confidence    ENUM('indicative','confirmed') NOT NULL DEFAULT 'indicative',
  basis         VARCHAR(40)  NOT NULL DEFAULT 'estimate',
  source        VARCHAR(200) NULL,
  source_factor VARCHAR(120) NULL,
  priced_on     DATE NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_wi_zone (work_item_id, zone),
  KEY idx_wip_conf (confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional AHSP coefficient build-up (links a work item to base prices by code).
CREATE TABLE IF NOT EXISTS drab_ahsp_components (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_item_id INT UNSIGNED NOT NULL,
  comp_type    ENUM('bahan','upah','alat') NOT NULL,
  base_code    VARCHAR(16)  NOT NULL,
  coefficient  DECIMAL(12,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ahsp_wi (work_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Generation axes & templates
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drab_styles (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(30)  NOT NULL,
  name_en        VARCHAR(80)  NOT NULL,
  name_id        VARCHAR(80)  NOT NULL,
  description_en VARCHAR(255) NULL,
  description_id VARCHAR(255) NULL,
  wall_factor    DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  status         ENUM('calibrated','indicative') NOT NULL DEFAULT 'indicative',
  default_structure VARCHAR(30) NOT NULL DEFAULT 'rcc_full',
  default_roof   VARCHAR(30)  NOT NULL DEFAULT 'tile',
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_style_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_structures (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(30)  NOT NULL,
  name_en        VARCHAR(80)  NOT NULL,
  name_id        VARCHAR(80)  NOT NULL,
  description_en VARCHAR(255) NULL,
  description_id VARCHAR(255) NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_struct_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_roofs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(30)  NOT NULL,
  name_en        VARCHAR(80)  NOT NULL,
  name_id        VARCHAR(80)  NOT NULL,
  description_en VARCHAR(255) NULL,
  description_id VARCHAR(255) NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_roof_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_finish_tiers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(20)  NOT NULL,
  name_en         VARCHAR(60)  NOT NULL,
  name_id         VARCHAR(60)  NOT NULL,
  description_en  VARCHAR(255) NULL,
  description_id  VARCHAR(255) NULL,
  rate_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tier_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_spec_slots (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40)  NOT NULL,
  name_en    VARCHAR(80)  NOT NULL,
  name_id    VARCHAR(80)  NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slot_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which work item fills a slot at each finish tier (drives the per-line swap).
CREATE TABLE IF NOT EXISTS drab_slot_options (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slot_code    VARCHAR(40)  NOT NULL,
  tier_code    VARCHAR(20)  NOT NULL,
  work_item_id INT UNSIGNED NOT NULL,
  is_default   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_slotopt (slot_code, tier_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Template lines: the parametric recipe. scope = which axis owns the line.
CREATE TABLE IF NOT EXISTS drab_template_lines (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope          ENUM('shared','style','structure','roof','extra') NOT NULL,
  scope_code     VARCHAR(30)  NOT NULL DEFAULT '',
  discipline     ENUM('PREP','STR','ARCH','MEP') NOT NULL,
  section_group  VARCHAR(40)  NOT NULL,
  section_name_en VARCHAR(120) NOT NULL,
  section_name_id VARCHAR(120) NOT NULL,
  work_item_id   INT UNSIGNED NULL,
  slot_code      VARCHAR(40)  NULL,
  driver         VARCHAR(30)  NOT NULL,
  coefficient    DECIMAL(14,5) NOT NULL DEFAULT 0,
  applies_when   VARCHAR(40)  NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_tpl_scope (scope, scope_code),
  KEY idx_tpl_disc (discipline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Location
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drab_zone_presets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(30)  NOT NULL,
  name_en       VARCHAR(80)  NOT NULL,
  name_id       VARCHAR(80)  NOT NULL,
  base_zone     ENUM('mataram','south') NOT NULL DEFAULT 'mataram',
  distance_band VARCHAR(20)  NOT NULL DEFAULT 'near',
  access_level  VARCHAR(20)  NOT NULL DEFAULT 'easy',
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_zone_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_site_factors (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  distance_band VARCHAR(20)  NOT NULL,
  access_level  VARCHAR(20)  NOT NULL,
  material_pct  DECIMAL(6,3) NOT NULL DEFAULT 0,
  labour_pct    DECIMAL(6,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_factor (distance_band, access_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Documents: Development -> Buildings -> RAB -> Sections -> Items -> Takeoffs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drab_developments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  name            VARCHAR(160) NOT NULL,
  location_text   VARCHAR(160) NULL,
  base_zone       ENUM('mataram','south') NOT NULL DEFAULT 'south',
  distance_band   VARCHAR(20)  NOT NULL DEFAULT 'near',
  access_level    VARCHAR(20)  NOT NULL DEFAULT 'easy',
  zone_preset     VARCHAR(30)  NULL,
  display_combined TINYINT(1)  NOT NULL DEFAULT 1,
  lang            ENUM('en','id','both') NOT NULL DEFAULT 'en',
  markups_on      TINYINT(1)   NOT NULL DEFAULT 0,
  overhead_pct    DECIMAL(6,3) NOT NULL DEFAULT 0,
  contingency_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
  ppn_pct         DECIMAL(6,3) NOT NULL DEFAULT 0,
  status          ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dev_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_buildings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  development_id  INT UNSIGNED NOT NULL,
  name            VARCHAR(160) NOT NULL,
  style_code      VARCHAR(30)  NOT NULL,
  structure_code  VARCHAR(30)  NOT NULL,
  roof_code       VARCHAR(30)  NOT NULL,
  finish_tier     VARCHAR(20)  NOT NULL DEFAULT 'standard',
  floors          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  area_l1         DECIMAL(10,2) NOT NULL DEFAULT 0,
  area_l2         DECIMAL(10,2) NOT NULL DEFAULT 0,
  area_l3         DECIMAL(10,2) NOT NULL DEFAULT 0,
  area_other      DECIMAL(10,2) NOT NULL DEFAULT 0,
  footprint_m2    DECIMAL(10,2) NOT NULL DEFAULT 0,
  bedrooms        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bathrooms       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  has_pool        TINYINT(1)   NOT NULL DEFAULT 0,
  pool_area       DECIMAL(10,2) NOT NULL DEFAULT 0,
  has_rooftop     TINYINT(1)   NOT NULL DEFAULT 0,
  rooftop_area    DECIMAL(10,2) NOT NULL DEFAULT 0,
  has_deck        TINYINT(1)   NOT NULL DEFAULT 0,
  deck_area       DECIMAL(10,2) NOT NULL DEFAULT 0,
  has_pergola     TINYINT(1)   NOT NULL DEFAULT 0,
  pergola_area    DECIMAL(10,2) NOT NULL DEFAULT 0,
  has_carport     TINYINT(1)   NOT NULL DEFAULT 0,
  carport_area    DECIMAL(10,2) NOT NULL DEFAULT 0,
  boundary_len    DECIMAL(10,2) NOT NULL DEFAULT 0,
  current_rab_id  INT UNSIGNED NULL,
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bld_dev (development_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_rabs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  building_id     INT UNSIGNED NOT NULL,
  version         INT UNSIGNED NOT NULL DEFAULT 1,
  name            VARCHAR(160) NULL,
  status          ENUM('draft','issued_baseline') NOT NULL DEFAULT 'draft',
  notes           TEXT NULL,
  markups_on      TINYINT(1)   NOT NULL DEFAULT 0,
  overhead_pct    DECIMAL(6,3) NOT NULL DEFAULT 0,
  contingency_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
  ppn_pct         DECIMAL(6,3) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rab_bld (building_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_sections (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rab_id      INT UNSIGNED NOT NULL,
  discipline  ENUM('PREP','STR','ARCH','MEP') NOT NULL,
  code        VARCHAR(12)  NOT NULL,
  name_en     VARCHAR(160) NOT NULL,
  name_id     VARCHAR(160) NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_sec_rab (rab_id, discipline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id    INT UNSIGNED NOT NULL,
  ref_code      VARCHAR(16)  NOT NULL DEFAULT '',
  line_id       VARCHAR(40)  NOT NULL,
  work_item_id  INT UNSIGNED NULL,
  slot_code     VARCHAR(40)  NULL,
  name_en       VARCHAR(220) NOT NULL,
  name_id       VARCHAR(220) NOT NULL,
  unit_id       INT UNSIGNED NOT NULL,
  quantity      DECIMAL(14,4) NULL,
  material_rate BIGINT UNSIGNED NOT NULL DEFAULT 0,
  labour_rate   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_pc_sum     TINYINT(1)   NOT NULL DEFAULT 0,
  has_takeoff   TINYINT(1)   NOT NULL DEFAULT 0,
  confidence    ENUM('indicative','confirmed') NOT NULL DEFAULT 'indicative',
  source        VARCHAR(200) NULL,
  remark        VARCHAR(220) NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_item_sec (section_id),
  KEY idx_item_line (line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_item_takeoffs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id    INT UNSIGNED NOT NULL,
  label      VARCHAR(220) NOT NULL,
  quantity   DECIMAL(14,4) NOT NULL DEFAULT 0,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_takeoff_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drab_user_templates (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(160) NOT NULL,
  payload    LONGTEXT     NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_utpl_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Structural lookup seed (the data-heavy catalog is in 2026_06_16_drab_seed.sql)
-- ---------------------------------------------------------------------------
INSERT INTO drab_units (code,name_en,name_id,sort_order) VALUES
 ('m','metre','meter',10),('m1','linear m','meter lari',20),('m2','m²','m²',30),
 ('m3','m³','m³',40),('kg','kg','kg',50),('ton','ton','ton',60),
 ('unit','unit','unit',70),('nos','no.','buah',80),('pcs','pcs','buah',85),
 ('point','point','titik',90),('set','set','set',100),('ls','lump sum','ls',110),
 ('lot','lot','lot',120),('zak','bag','zak',130),('btg','length','batang',140),
 ('lbr','sheet','lembar',150),('ltr','litre','liter',160),('day','day','hari',170),
 ('oh','man-day','OH',180)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en);

INSERT INTO drab_config (cfg_key,cfg_value,note) VALUES
 ('south_default_premium_material','0.120','applied to Mataram base when base_zone=south and no south price exists'),
 ('south_default_premium_labour','0.050','labour mobilisation premium for south'),
 ('default_ratio_STR_material','0.70','category material:labour split fallback'),
 ('default_ratio_ARCH_material','0.62',''),
 ('default_ratio_MEP_material','0.75',''),
 ('default_ratio_PREP_material','0.30',''),
 ('default_overhead_pct','10.0','BUK default when markups enabled'),
 ('default_contingency_pct','5.0',''),
 ('default_ppn_pct','11.0','Indonesia VAT')
ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value);

INSERT INTO drab_finish_tiers (code,name_en,name_id,description_en,description_id,rate_multiplier,sort_order) VALUES
 ('budget','Budget','Ekonomis','Simple local materials, basic finishes.','Material lokal sederhana, finishing dasar.',0.85,10),
 ('standard','Standard','Standar','Good regional materials, sound finishes.','Material regional yang baik, finishing rapi.',1.00,20),
 ('premium','Premium','Premium','Bespoke surfaces, imported fittings.','Permukaan custom, perlengkapan impor.',1.30,30),
 ('signature','Signature','Signature','Elite international standard throughout.','Standar internasional kelas atas.',1.60,40)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en);

INSERT INTO drab_structures (code,name_en,name_id,description_en,description_id,sort_order) VALUES
 ('rcc_full','Full RCC frame','Rangka beton penuh (RCC)','Reinforced-concrete columns, beams and slabs throughout.','Kolom, balok, pelat beton bertulang.',10),
 ('batukali_masonry','Batu-kali + masonry + light steel','Pondasi batu kali + bata + baja ringan','Stone strip footing, masonry walls, light-steel roof frame.','Pondasi batu kali, dinding bata, rangka atap baja ringan.',20),
 ('steel_frame','Steel frame','Rangka baja','Structural steel frame with metal decking.','Rangka baja struktural dengan dek metal.',30),
 ('timber_frame','Timber frame','Rangka kayu','Traditional/engineered timber post-and-beam frame.','Rangka kayu tradisional/rekayasa.',40)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en);

INSERT INTO drab_roofs (code,name_en,name_id,description_en,description_id,sort_order) VALUES
 ('tile','Clay / concrete tile','Genteng beton/keramik','Tiled roof on light-steel or timber rafters.','Atap genteng di atas rangka baja ringan/kayu.',10),
 ('rcc_flat','RCC flat roof (dak)','Dak beton','Reinforced-concrete flat slab roof; walkable option.','Pelat dak beton; opsi bisa diakses.',20),
 ('timber_shingle','Timber / shingle','Atap sirap/kayu','Timber-framed pitched roof with shingle/wood cover.','Atap kayu dengan penutup sirap/kayu.',30),
 ('thatch','Thatch / alang-alang','Atap alang-alang','Traditional thatch over timber/bamboo structure.','Atap alang-alang di atas struktur kayu/bambu.',40),
 ('metal','Metal / spandek','Spandek/galvalum','Metal sheet roof on light-steel purlins.','Atap metal di atas gording baja ringan.',50)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en);

INSERT INTO drab_styles (code,name_en,name_id,description_en,description_id,wall_factor,status,default_structure,default_roof,sort_order) VALUES
 ('trop_med','Tropical Mediterranean','Mediterania Tropis','Open-plan rendered villa, arches, flat & tiled roofs. Calibrated to real Lombok BOQs.','Vila plester open-plan, lengkung, atap dak & genteng. Dikalibrasi dari RAB nyata Lombok.',2.150,'calibrated','rcc_full','rcc_flat',10),
 ('bali_villa','Bali villa','Vila Bali','Modern Balinese villa, alang-alang/timber accents, open living.','Vila Bali modern, aksen alang-alang/kayu, ruang terbuka.',2.300,'indicative','rcc_full','tile',20),
 ('joglo','Joglo (timber)','Joglo (kayu)','Traditional Javanese joglo, soko-guru timber frame, tiled roof.','Joglo Jawa tradisional, rangka soko guru, atap genteng.',2.600,'indicative','timber_frame','tile',30),
 ('bamboo','Premium bamboo villa','Vila bambu premium','Engineered-bamboo villa, thatch roof, artisanal joinery.','Vila bambu rekayasa, atap alang-alang, sambungan artisan.',2.400,'indicative','timber_frame','thatch',40),
 ('java_city','Jakarta / Java city house','Rumah kota Jawa','Compartmented two-storey urban RCC house, tiled roof.','Rumah kota dua lantai RCC bersekat, atap genteng.',3.000,'indicative','rcc_full','tile',50),
 ('local_simple','Local simple house','Rumah sederhana lokal','Single-storey batu-kali + masonry house, light-steel tiled roof.','Rumah 1 lantai batu kali + bata, atap genteng baja ringan.',2.800,'indicative','batukali_masonry','tile',60)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en);

INSERT INTO drab_spec_slots (code,name_en,name_id,sort_order) VALUES
 ('structural_concrete','Structural concrete','Beton struktur',10),
 ('wall_finish','Wall finish','Finishing dinding',20),
 ('floor_finish','Floor finish','Penutup lantai',30),
 ('ceiling','Ceiling','Plafon',40),
 ('interior_paint','Interior paint','Cat interior',50),
 ('exterior_paint','Exterior paint','Cat eksterior',60),
 ('waterproofing','Waterproofing','Waterproofing',70),
 ('roof_cover','Roof covering','Penutup atap',80),
 ('doors_windows','Doors & windows','Pintu & jendela',90),
 ('sanitary','Sanitary fittings','Sanitair',100)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en);

INSERT INTO drab_zone_presets (code,name_en,name_id,base_zone,distance_band,access_level,sort_order) VALUES
 ('mataram_city','Mataram / West Lombok','Mataram / Lombok Barat','mataram','near','easy',10),
 ('south_kuta','South Lombok (Kuta/Praya/Mandalika)','Lombok Selatan (Kuta/Praya)','south','near','easy',20),
 ('south_remote','South Lombok — remote / steep','Lombok Selatan — terpencil/curam','south','mid','steep',30),
 ('are_guling','Are Guling / Selong Belanak','Are Guling / Selong Belanak','south','mid','moderate',40),
 ('gili','Gili Islands (boat freight)','Gili (angkut perahu)','south','far','boat',50),
 ('sembalun','Sembalun / East remote','Sembalun / Timur terpencil','mataram','far','steep',60)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en);

INSERT INTO drab_site_factors (distance_band,access_level,material_pct,labour_pct) VALUES
 ('near','easy',0.000,0.000),('near','moderate',0.030,0.020),('near','steep',0.070,0.060),
 ('mid','easy',0.040,0.020),('mid','moderate',0.080,0.050),('mid','steep',0.140,0.100),
 ('far','easy',0.090,0.050),('far','moderate',0.140,0.090),('far','steep',0.220,0.160),
 ('far','boat',0.260,0.140),('mid','boat',0.200,0.120),('near','boat',0.160,0.100)
ON DUPLICATE KEY UPDATE material_pct=VALUES(material_pct);

-- ---------------------------------------------------------------------------
-- Feature gating (read by check_feature_access in drab_api.php)
-- ---------------------------------------------------------------------------
INSERT INTO feature_access (feature_key,feature_label,description,tier_free,tier_basic,tier_premium,require_login,is_active,sort_order) VALUES
 ('drab_generate','Detailed RAB — generate','Run the wizard and view a full generated RAB.',1,1,1,1,1,200),
 ('drab_save_multi','Detailed RAB — multiple projects','Save more than one development.',0,1,1,1,1,201),
 ('drab_templates','Detailed RAB — saved templates','Save and load your own RAB templates.',0,0,1,1,1,202),
 ('drab_confirmed_pricing','Detailed RAB — confirmed pricing','See confirmed, contract-grade rates.',0,0,1,1,1,203),
 ('drab_split_view','Detailed RAB — material/labour split','Toggle the material vs labour columns.',0,1,1,1,1,204),
 ('drab_export_clean','Detailed RAB — clean export','Download the clean Excel/PDF (no watermark).',0,0,1,1,1,205),
 ('drab_catalog_browse','Detailed RAB — catalog browser','Browse and search the full price catalog.',0,0,1,1,1,206)
ON DUPLICATE KEY UPDATE feature_label=VALUES(feature_label), description=VALUES(description),
 tier_free=VALUES(tier_free), tier_basic=VALUES(tier_basic), tier_premium=VALUES(tier_premium),
 require_login=VALUES(require_login), is_active=VALUES(is_active);

SET FOREIGN_KEY_CHECKS = 1;
-- End of schema migration. Next: 2026_06_16_drab_seed.sql
