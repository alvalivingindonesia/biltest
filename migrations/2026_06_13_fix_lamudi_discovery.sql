-- =====================================================================
-- Build in Lombok — fix Lamudi discovery/search URLs
--
-- Lamudi changed its URL structure: the old /lombok/<type>/dijual/ search URLs
-- now 404, which silently broke BOTH new-listing discovery and image backfill.
-- Replace with the current /jual/<province>/<kabupaten>/<type>/ format
-- (all verified returning listings on 2026-06-13; ?page=N pagination confirmed).
--
-- Run in the phpMyAdmin SQL tab. Idempotent. Safe to re-run.
-- =====================================================================

-- Retire the dead URLs (keep the rows for history; just deactivate).
UPDATE discovery_sources SET is_active = 0
 WHERE source_site = 'lamudi' AND search_url LIKE 'https://www.lamudi.co.id/lombok/%';

INSERT INTO discovery_sources (source_site, label, search_url, max_pages, is_active) VALUES
    ('lamudi', 'Lamudi — Lombok Tengah land',   'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-tengah/tanah/', 5, 1),
    ('lamudi', 'Lamudi — Lombok Tengah houses', 'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-tengah/rumah/', 3, 1),
    ('lamudi', 'Lamudi — Lombok Barat land',    'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-barat/tanah/',  4, 1),
    ('lamudi', 'Lamudi — Lombok Utara land',    'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-utara/tanah/',  4, 1),
    ('lamudi', 'Lamudi — Lombok Timur land',    'https://www.lamudi.co.id/jual/nusa-tenggara-barat/lombok-timur/tanah/',  3, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = 1, max_pages = VALUES(max_pages);

-- Done. Fixes Lamudi image backfill (npm run backfill-images) AND new-listing
-- discovery. (Mataram has a different slug and is omitted; add via the Ingest
-- Console → Discovery tab if needed.)
