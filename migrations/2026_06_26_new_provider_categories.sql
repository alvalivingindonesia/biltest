-- 2026-06-26 — Add missing building categories to the provider directory taxonomy.
-- Applied live via the SQL console (tools/dbq.mjs --write) per the "apply DB changes
-- directly" workflow; committed here as the canonical record for clean re-import.
--
-- Context: 20 of 36 categories were empty and several important building trades/tokos
-- had no home. These 8 categories fill genuine gaps for "building in Lombok" and all
-- reliably have >=3-review / 4-star businesses on Google Maps in Lombok.
--   Suppliers & Materials : Paint, Steel & Rebar, Ready-Mix Concrete, Brick/Block/Paving
--   Specialist Contractors: Well Drilling (Sumur Bor), Heavy Equipment & Scaffolding Rental
--   Builders & Trades     : Furniture & Joinery / Kitchen Set
--   Professional Services : Land Surveyor

INSERT INTO categories (`key`, group_key, label, label_id, sort_order) VALUES
  ('furniture_joinery',     'builders_trades',        'Furniture & Joinery / Kitchen Set',     'Mebel & Kitchen Set',            9),
  ('land_surveyor',         'professional_services',  'Land Surveyor',                          'Surveyor / Jasa Ukur Tanah',    10),
  ('well_drilling',         'specialist_contractors', 'Well Drilling / Borehole',               'Sumur Bor',                      8),
  ('equipment_rental',      'specialist_contractors', 'Heavy Equipment & Scaffolding Rental',   'Sewa Alat Berat & Scaffolding',  9),
  ('paint_supplier',        'suppliers_materials',    'Paint Supplier',                         'Toko Cat',                      13),
  ('steel_rebar_supplier',  'suppliers_materials',    'Steel & Rebar Supplier',                 'Toko Besi & Baja',              14),
  ('readymix_supplier',     'suppliers_materials',    'Ready-Mix Concrete Supplier',            'Beton Ready Mix',               15),
  ('brick_block_supplier',  'suppliers_materials',    'Brick, Block & Paving Supplier',         'Batako, Bata & Paving',         16)
ON DUPLICATE KEY UPDATE
  group_key  = VALUES(group_key),
  label      = VALUES(label),
  label_id   = VALUES(label_id),
  sort_order = VALUES(sort_order);
