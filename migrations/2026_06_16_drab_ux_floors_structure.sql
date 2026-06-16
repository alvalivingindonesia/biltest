-- ============================================================================
-- Detailed RAB Generator — UX pass: flooring axis, per-storey structure,
-- clearer section names.  (Follows ADR 0012; namespace drab_*.)
--
-- Run AFTER 2026_06_16_drab_generator.sql + 2026_06_16_drab_seed.sql.
-- Idempotent: safe to re-run. Targets MariaDB 10.3+ (uses ADD COLUMN IF NOT
-- EXISTS); on MySQL 5.7 add the columns by hand if IF NOT EXISTS is rejected.
--
-- What it adds:
--   1. drab_floors  — a user-pickable Floor type axis (like styles/structures/
--      roofs). Each maps to a floor_finish work item. Reuses the real Confirmed
--      BOQ rates for ceramic/granito (AR06) and solid-wood/ulin (AR08); the rest
--      ship as honestly-badged Indicative until real Lombok quotes confirm them.
--   2. drab_buildings.floor_code — the chosen floor (NULL = follow finish tier).
--   3. drab_template_lines.per_level — superstructure lines split per storey so
--      the Structure page reads Substructure → Level 1 → Level 2 → Roof, not
--      three identical "STRUCTURE" blocks.  Totals are unchanged (the engine
--      apportions the same quantity across storeys by floor-area share).
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. New columns
-- ---------------------------------------------------------------------------
ALTER TABLE drab_buildings
  ADD COLUMN IF NOT EXISTS floor_code VARCHAR(30) NULL AFTER finish_tier;

ALTER TABLE drab_template_lines
  ADD COLUMN IF NOT EXISTS per_level TINYINT(1) NOT NULL DEFAULT 0 AFTER applies_when;

-- ---------------------------------------------------------------------------
-- 2. Floor-type lookup (the wizard's optional Floor axis)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drab_floors (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(30)  NOT NULL,
  name_en        VARCHAR(80)  NOT NULL,
  name_id        VARCHAR(80)  NOT NULL,
  description_en VARCHAR(255) NULL,
  description_id VARCHAR(255) NULL,
  work_item_code VARCHAR(16)  NOT NULL,   -- -> drab_work_items.code (spec_slot=floor_finish)
  status         ENUM('calibrated','indicative') NOT NULL DEFAULT 'indicative',
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_floor_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 3. Floor work items + prices (new ones use AF* codes; ceramic/granito + ulin
--    reuse the existing Confirmed BOQ items AR06 / AR08).
--    Indicative rates are Lombok ball-park S&I (material+labour) per m², zoned
--    Mataram baseline + a South row; basis records where the number came from.
-- ---------------------------------------------------------------------------
INSERT INTO drab_work_items (code,discipline,section_group,name_en,name_id,unit_id,spec_slot,is_pc_sum) VALUES
 ('AF01','ARCH','Floor','Bare cement screed (unfinished)','Lantai semen aci (tanpa penutup)',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0),
 ('AF02','ARCH','Floor','Polished / exposed concrete floor','Lantai beton poles / ekspos',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0),
 ('AF04','ARCH','Floor','Natural stone (andesite / marble)','Lantai batu alam (andesit / marmer)',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0),
 ('AF05','ARCH','Floor','Engineered wood flooring','Lantai kayu engineered',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0),
 ('AF06','ARCH','Floor','WPC / SPC vinyl plank','Lantai vinyl WPC / SPC',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0),
 ('AF07','ARCH','Floor','Vinyl sheet / roll','Lantai vinyl lembaran / roll',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0),
 ('AF08','ARCH','Floor','Epoxy / PU resin floor','Lantai epoxy / PU resin',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0),
 ('AF09','ARCH','Floor','Terrazzo (teraso, cast in situ)','Teraso cor di tempat',(SELECT id FROM drab_units WHERE code='m2'),'floor_finish',0)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), name_id=VALUES(name_id), spec_slot=VALUES(spec_slot);

-- Mataram baseline + South row for each new floor item (Indicative).
INSERT INTO drab_work_item_prices (work_item_id,zone,material_rate,labour_rate,confidence,basis,source,priced_on) VALUES
 ((SELECT id FROM drab_work_items WHERE code='AF01'),'mataram', 45000, 55000,'indicative','estimate','Lombok screed ball-park 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF01'),'south',   50000, 58000,'indicative','estimate','Lombok screed ball-park 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF02'),'mataram',120000,160000,'indicative','derived','Polished concrete derived 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF02'),'south',  135000,170000,'indicative','derived','Polished concrete derived 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF04'),'mataram',350000,220000,'indicative','other_province_adjusted','Bali/Java stone ref + Lombok freight','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF04'),'south',  400000,235000,'indicative','other_province_adjusted','Bali/Java stone ref + Lombok freight','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF05'),'mataram',380000,150000,'indicative','national_ref','National engineered-wood ref 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF05'),'south',  430000,160000,'indicative','national_ref','National engineered-wood ref 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF06'),'mataram',220000,120000,'indicative','national_ref','National WPC/SPC ref 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF06'),'south',  250000,128000,'indicative','national_ref','National WPC/SPC ref 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF07'),'mataram',160000, 90000,'indicative','national_ref','National vinyl-sheet ref 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF07'),'south',  180000, 95000,'indicative','national_ref','National vinyl-sheet ref 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF08'),'mataram',180000,170000,'indicative','other_province_adjusted','Epoxy contractor ref + Lombok freight','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF08'),'south',  200000,180000,'indicative','other_province_adjusted','Epoxy contractor ref + Lombok freight','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF09'),'mataram',250000,280000,'indicative','derived','Terrazzo in-situ derived 2026','2026-06-01'),
 ((SELECT id FROM drab_work_items WHERE code='AF09'),'south',  285000,295000,'indicative','derived','Terrazzo in-situ derived 2026','2026-06-01')
ON DUPLICATE KEY UPDATE material_rate=VALUES(material_rate), labour_rate=VALUES(labour_rate),
  confidence=VALUES(confidence), basis=VALUES(basis), source=VALUES(source), priced_on=VALUES(priced_on);

-- ---------------------------------------------------------------------------
-- 4. The Floor axis itself.  ceramic/granito -> AR06 (Confirmed real BOQ),
--    solid wood -> AR08 (Confirmed real BOQ). Order: cheapest-first-ish.
-- ---------------------------------------------------------------------------
INSERT INTO drab_floors (code,name_en,name_id,description_en,description_id,work_item_code,status,sort_order) VALUES
 ('bare_screed','Bare cement screed','Lantai semen aci','Floated/polished cement only — no surface finish. Cheapest, utilitarian.','Hanya semen aci/poles — tanpa penutup. Termurah.','AF01','indicative',10),
 ('polished_concrete','Polished / exposed concrete','Beton poles / ekspos','Ground & sealed concrete — modern industrial look, low maintenance.','Beton dipoles & disealer — tampilan industrial modern.','AF02','indicative',20),
 ('ceramic','Ceramic / granito tile','Keramik / granito','Glazed ceramic or homogeneous granito tile — the Lombok default.','Keramik berglazur atau granito homogen — standar Lombok.','AR06','calibrated',30),
 ('natural_stone','Natural stone (andesite / marble)','Batu alam (andesit / marmer)','Andesite, sandstone or marble — premium, characterful, heavier.','Andesit, batu pasir atau marmer — premium dan berkarakter.','AF04','indicative',40),
 ('terrazzo','Terrazzo (teraso)','Teraso','Cast-in-situ terrazzo — seamless, durable, artisanal.','Teraso cor di tempat — mulus, awet, artisan.','AF09','indicative',50),
 ('engineered_wood','Engineered wood','Kayu engineered','Engineered timber planks — warm, stable, indoor only.','Papan kayu engineered — hangat, stabil, untuk interior.','AF05','indicative',60),
 ('solid_wood','Solid hardwood / ulin','Kayu solid / ulin','Solid ulin/durawood boards — the calibrated villa decking spec.','Papan ulin solid — spec decking vila terkalibrasi.','AR08','calibrated',70),
 ('wpc_spc','WPC / SPC vinyl plank','Vinyl WPC / SPC','Click-lock vinyl plank — waterproof, wood-look, fast to lay.','Vinyl plank klik — tahan air, motif kayu, cepat dipasang.','AF06','indicative',80),
 ('vinyl_sheet','Vinyl sheet / roll','Vinyl lembaran','Roll vinyl — budget, soft underfoot, quick.','Vinyl roll — ekonomis, empuk, cepat.','AF07','indicative',90),
 ('epoxy','Epoxy / PU resin','Epoxy / PU resin','Poured resin floor — seamless, hygienic, wet-area friendly.','Lantai resin tuang — mulus, higienis, cocok area basah.','AF08','indicative',100)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), name_id=VALUES(name_id),
  description_en=VALUES(description_en), description_id=VALUES(description_id),
  work_item_code=VALUES(work_item_code), status=VALUES(status), sort_order=VALUES(sort_order);

-- Make every floor type swappable per-line in the editor too (slot alternatives
-- already include any spec_slot=floor_finish item, so no slot_options change is
-- strictly required — but seed the per-tier defaults to keep them sensible).

-- ---------------------------------------------------------------------------
-- 5. Per-storey structure: rename foundation, mark superstructure per_level=1,
--    keep roof structure as its own block. Engine apportions the same quantity
--    across storeys so totals are identical — only the labels get clearer.
-- ---------------------------------------------------------------------------
-- Substructure / foundation blocks (footprint-driven) — clearer name, stays whole.
UPDATE drab_template_lines
   SET section_name_en='SUBSTRUCTURE (FOUNDATION)', section_name_id='STRUKTUR BAWAH (PONDASI)'
 WHERE scope='structure' AND section_name_en='BOTTOM STRUCTURE';

-- Superstructure blocks (frame, rebar, formwork, slabs) — split per storey.
UPDATE drab_template_lines
   SET per_level=1,
       section_name_en='SUPERSTRUCTURE', section_name_id='STRUKTUR ATAS'
 WHERE scope='structure' AND section_name_en='STRUCTURE';

-- The RCC flat-roof (dak) emits concrete + reinforcement as TWO lines that both
-- read "ROOF STRUCTURE"; share one section_group so they collapse into a single
-- "ROOF STRUCTURE" block (same fix in spirit as the structure relabelling above).
UPDATE drab_template_lines
   SET section_group='Roof structure'
 WHERE scope='roof' AND scope_code='rcc_flat' AND discipline='STR';

SET FOREIGN_KEY_CHECKS = 1;
-- End. The new code reads these via drab_api.php (meta.floors, engine per_level).
