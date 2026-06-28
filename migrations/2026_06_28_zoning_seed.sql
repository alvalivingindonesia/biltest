-- ============================================================================
-- Zoning & Land Check (ADR 0013) — seed data
-- Land-Use Class taxonomy, tool config, feature gates, and a STARTER polygon set.
--
-- The starter polygons are broad, directionally-correct INDICATIVE placeholders
-- (Mandalika = tourism, Gunung Tunak = conservation, Praya plain = agriculture)
-- sourced 'seed_demo_v1'. They make the tool work end-to-end and are REPLACED by
-- the authoritative ingest (tools/zoning_ingest.mjs, docs/zoning-ingest.md). Points
-- with no polygon resolve to the honest 'unknown' (Not Yet Mapped) class.
-- ============================================================================

-- Feature gates (free triage; premium detail/report/cert-check)
INSERT INTO feature_access (feature_key, feature_label, description, tier_free, tier_basic, tier_premium, is_active, require_login, sort_order) VALUES
 ('zoning_check', 'Zoning & Land Check — instant triage', 'Free buildability traffic light + basic plot info.', 1,1,1,1,0,210),
 ('zoning_plot_profile_detail', 'Zoning & Land Check — detailed plot profile', 'Detailed parcel/certificate facts for a plot.', 0,0,1,1,1,211),
 ('zoning_report', 'Zoning & Land Check — Site Suitability Report', 'Full verified report (clean, downloadable).', 0,0,1,1,1,212),
 ('zoning_cert_check', 'Zoning & Land Check — Verified Certificate Check', 'Notary-brokered certificate verification.', 0,0,1,1,1,213);

-- Land-Use Class taxonomy
INSERT INTO zoning_landuse_classes (class_key, name_en, name_id, buildability, villa_allowed, summary_en, summary_id, color, sort_order) VALUES
 ('pariwisata','Tourism Zone','Zona Pariwisata','permitted',1,'Villas, resorts and tourist accommodation are the intended use. Generally the most favourable zone for a luxury villa.','Vila, resor, dan akomodasi wisata adalah peruntukan utama. Umumnya zona paling sesuai untuk vila mewah.','green',10),
 ('permukiman','Residential / Settlement','Zona Permukiman','permitted',1,'Housing is the intended use. Private villas are normally permitted, subject to building permits.','Perumahan adalah peruntukan utama. Vila pribadi umumnya diizinkan dengan perizinan bangunan.','green',20),
 ('perdagangan_jasa','Commercial & Services','Zona Perdagangan dan Jasa','permitted',1,'Shops, offices and services. Building is allowed, and mixed commercial-residential use is common.','Perdagangan, perkantoran, dan jasa. Pembangunan diizinkan, dan penggunaan campuran umum terjadi.','green',30),
 ('pertanian','Agriculture','Zona Pertanian','restricted',0,'Designated for farming. Building is restricted and usually requires land-use conversion. Protected wet-rice land (LP2B) cannot be converted.','Diperuntukkan bagi pertanian. Pembangunan dibatasi dan biasanya memerlukan alih fungsi lahan. Sawah lestari (LP2B) tidak dapat dialihfungsikan.','amber',40),
 ('perkebunan','Plantation','Zona Perkebunan','restricted',0,'Designated for plantation crops. Building is restricted and typically needs conversion approval.','Diperuntukkan bagi perkebunan. Pembangunan dibatasi dan umumnya butuh persetujuan alih fungsi.','amber',50),
 ('industri','Industrial','Zona Industri','restricted',0,'Intended for industry and warehousing, not residential villas. Building is allowed only for permitted industrial uses.','Diperuntukkan bagi industri dan pergudangan, bukan vila hunian. Pembangunan hanya untuk peruntukan industri.','amber',60),
 ('fasilitas','Special / Public Facility','Fasilitas Khusus / Umum','restricted',0,'Reserved for a specific public or special use (port, airport, office, defence, road or public facility). Not available for private villa development.','Diperuntukkan bagi penggunaan khusus atau umum tertentu (pelabuhan, bandara, perkantoran, pertahanan, jalan, atau fasilitas umum). Tidak tersedia untuk pembangunan vila pribadi.','amber',65),
 ('sempadan','Protective Buffer (setback)','Sempadan (Zona Lindung Setempat)','prohibited',0,'A protected buffer strip (coast, river or cliff setback). Permanent buildings are not allowed inside the setback line.','Jalur sempadan lindung (pantai, sungai, atau jurang). Bangunan permanen tidak diizinkan di dalam garis sempadan.','red',70),
 ('hutan_lindung','Protected Forest','Hutan Lindung','prohibited',0,'Protected state forest. Private building is prohibited.','Hutan lindung milik negara. Pembangunan pribadi dilarang.','red',80),
 ('hutan_produksi','Production Forest','Hutan Produksi','prohibited',0,'State production forest. Not available for private villa development.','Hutan produksi negara. Tidak tersedia untuk pembangunan vila pribadi.','red',90),
 ('hijau','Green / Protected Open Zone','Zona Hijau / Kawasan Lindung','prohibited',0,'Protected green space. Despite the name, this is a NO-BUILD zone for villas.','Ruang hijau lindung. Meski bernama hijau, ini adalah zona TANPA-BANGUN untuk vila.','red',100),
 ('rth','Green Open Space (RTH)','Ruang Terbuka Hijau','prohibited',0,'Public green open space. Building is not permitted.','Ruang terbuka hijau publik. Pembangunan tidak diizinkan.','red',110),
 ('konservasi','Conservation / National Park','Kawasan Konservasi / Taman Nasional','prohibited',0,'Conservation area, sanctuary or national park. Strictly no private development.','Kawasan konservasi, suaka, atau taman nasional. Sangat dilarang untuk pembangunan pribadi.','red',120),
 ('badan_air','Water Body','Badan Air','prohibited',0,'Rivers, lakes or reservoirs. Not buildable land.','Sungai, danau, atau waduk. Bukan lahan yang dapat dibangun.','red',130),
 ('rawan_bencana','Disaster-Prone Area','Kawasan Rawan Bencana','restricted',0,'Identified hazard area (flood, landslide, tsunami). Building is restricted and needs special mitigation.','Kawasan rawan bahaya (banjir, longsor, tsunami). Pembangunan dibatasi dan butuh mitigasi khusus.','amber',140),
 ('unknown','Not Yet Mapped','Belum Terpetakan','unknown',0,'We do not yet hold official zoning data for this exact point. Request a verified report for a definitive answer.','Kami belum memiliki data zonasi resmi untuk titik ini. Minta laporan terverifikasi untuk jawaban pasti.','grey',999)
ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), name_id=VALUES(name_id), buildability=VALUES(buildability), villa_allowed=VALUES(villa_allowed), summary_en=VALUES(summary_en), summary_id=VALUES(summary_id), color=VALUES(color), sort_order=VALUES(sort_order);

-- Tool config (key/value)
INSERT INTO zoning_config (cfg_key, cfg_value) VALUES
 ('map_center_lat','-8.78'),
 ('map_center_lng','116.28'),
 ('map_default_zoom','11'),
 ('map_min_zoom','9'),
 ('map_max_zoom','19'),
 ('satellite_tiles_url','https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
 ('satellite_attribution','Tiles (c) Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community'),
 ('labels_tiles_url','https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}'),
 ('geocoder_provider','photon'),
 ('geocoder_url','https://photon.komoot.io/api/'),
 ('bhumi_wms_url',''),
 ('bhumi_wms_layers',''),
 ('contact_whatsapp',''),
 ('report_price_idr','1500000'),
 ('disclaimer_en','Indicative information for decision-support only — not legal, planning or investment advice. Zoning is read from official spatial plans captured on the date shown and may be outdated or imprecise at boundaries. Always confirm via an official KKPR application and a licensed notary (PPAT) before transacting.'),
 ('disclaimer_id','Informasi indikatif untuk pendukung keputusan saja — bukan nasihat hukum, tata ruang, atau investasi. Zonasi dibaca dari rencana tata ruang resmi pada tanggal yang ditampilkan dan dapat tidak mutakhir atau kurang presisi di batas. Selalu konfirmasi melalui pengajuan KKPR resmi dan notaris (PPAT) berlisensi sebelum bertransaksi.'),
 ('coverage_note_en','Official zoning coverage is being expanded across Lombok. Where we do not yet hold data for a point, the status shows Not Yet Mapped and you can request a verified report.'),
 ('coverage_note_id','Cakupan zonasi resmi sedang diperluas di seluruh Lombok. Bila data suatu titik belum tersedia, status menampilkan Belum Terpetakan dan Anda dapat meminta laporan terverifikasi.')
ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value);

-- Starter INDICATIVE polygons (SRID 0, X=lng Y=lat) — replaced by the real ingest.
INSERT INTO zoning_landuse_polys (class_key, plan_level, kabupaten, raw_zona, geom, kdb, klb, kkb, max_floors, source, source_date, confidence) VALUES
 ('pariwisata','rdtr','lombok_tengah','Kawasan Pariwisata (Mandalika)', ST_GeomFromText('POLYGON((116.265 -8.865,116.335 -8.865,116.335 -8.915,116.265 -8.915,116.265 -8.865))',0), 60.00, 1.20, 12.00, 2, 'seed_demo_v1','2026-06-28','indicative'),
 ('konservasi','rtrw','lombok_tengah','Kawasan Konservasi (TWA Gunung Tunak)', ST_GeomFromText('POLYGON((116.345 -8.895,116.385 -8.895,116.385 -8.935,116.345 -8.935,116.345 -8.895))',0), NULL, NULL, NULL, NULL, 'seed_demo_v1','2026-06-28','indicative'),
 ('pertanian','rtrw','lombok_tengah','Kawasan Pertanian (dataran Praya)', ST_GeomFromText('POLYGON((116.220 -8.780,116.320 -8.780,116.320 -8.830,116.220 -8.830,116.220 -8.780))',0), NULL, NULL, NULL, NULL, 'seed_demo_v1','2026-06-28','indicative');
