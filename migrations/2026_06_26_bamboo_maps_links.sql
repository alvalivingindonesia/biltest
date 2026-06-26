-- 2026-06-26 — Give the link-less bamboo_supplier entries real Google Maps links.
-- Applied live via the SQL console; committed here as the canonical record.
--
-- bamboo_supplier was populated only by 5 manually-added, coordinate-less, link-less
-- entries. Two of them turned out to have real Maps listings (found via a broad
-- bamboo search, not their exact stored name) — attach coordinates + the canonical
-- cid link + accurate Google reviews. Two genuinely-reviewed bamboo suppliers are
-- added new. The remaining 3 (Bale Bambu Alang Alang, Adi Bambu Alang Alang, Bale
-- Bambu) are informal alang-alang/bamboo sellers with no Google Maps presence — they
-- keep their phone contact and (correctly) render no Maps button.
--
-- cid = the place feature id; CONV(hex,16,10) turns it into the decimal cid that
-- https://maps.google.com/?cid=<n> opens to the real listing (reviews/photos).

UPDATE providers SET latitude=-8.6488, longitude=116.1290767,
  google_maps_url=CONCAT('https://maps.google.com/?cid=',CONV('dc6354657888f2e0',16,10)),
  google_rating=4.8, google_review_count=23, updated_at=CURRENT_TIMESTAMP
  WHERE name='Penjual Bambu Komang Arya';

UPDATE providers SET latitude=-8.6606934, longitude=116.3628048,
  google_maps_url=CONCAT('https://maps.google.com/?cid=',CONV('cef4b6d9b31bde2d',16,10)),
  google_rating=5.0, google_review_count=1, updated_at=CURRENT_TIMESTAMP
  WHERE name='Ruji Bambu UlilAmri';

INSERT INTO providers (slug,name,group_key,category_key,area_key,short_description,description,
    latitude,longitude,google_maps_url,google_rating,google_review_count,phone,whatsapp_number,
    languages,is_featured,is_trusted,is_active)
  VALUES ('ud-lombok-bambu','UD. Lombok Bambu','suppliers_materials','bamboo_supplier','mataram',
    'Bamboo Supplier in Mataram, Lombok.','Bamboo Supplier in Mataram, Lombok.',
    -8.5477988,116.1103469,CONCAT('https://maps.google.com/?cid=',CONV('d7604ddd5338db3b',16,10)),
    4.5,13,'0878-0588-8404','6287805888404','Bahasa only',0,0,1)
  ON DUPLICATE KEY UPDATE google_rating=VALUES(google_rating);
INSERT IGNORE INTO provider_categories (provider_id,category_key)
  SELECT id,'bamboo_supplier' FROM providers WHERE slug='ud-lombok-bambu';

INSERT INTO providers (slug,name,group_key,category_key,area_key,short_description,description,
    latitude,longitude,google_maps_url,google_rating,google_review_count,languages,
    is_featured,is_trusted,is_active)
  VALUES ('atap-alang-alang-pt-dewi-anjani-wanakarya',
    'Atap Alang-alang & Bamboo Contractor - PT Dewi Anjani Wanakarya',
    'suppliers_materials','bamboo_supplier','praya',
    'Bamboo Supplier & alang-alang contractor in Praya, Lombok.',
    'Bamboo Supplier & alang-alang contractor in Praya, Lombok.',
    -8.7312595,116.2489752,CONCAT('https://maps.google.com/?cid=',CONV('c595dc1f9bfc4692',16,10)),
    5.0,8,'Bahasa only',0,0,1)
  ON DUPLICATE KEY UPDATE google_rating=VALUES(google_rating);
INSERT IGNORE INTO provider_categories (provider_id,category_key)
  SELECT p.id,c.ck FROM providers p
  JOIN (SELECT 'bamboo_supplier' ck UNION ALL SELECT 'general_contractor') c
  WHERE p.slug='atap-alang-alang-pt-dewi-anjani-wanakarya';
