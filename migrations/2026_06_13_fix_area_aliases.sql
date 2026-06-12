-- =====================================================================
-- Build in Lombok — correct south-coast area aliases
--
-- The first ingestion seed lumped Pujut-district villages (Mertak, Sengkol,
-- Pengembur, …) into 'praya'. They are the Kuta / Mandalika market area, not
-- Praya town — so a "Mertak" listing was being flagged to move from Kuta to
-- Praya. Remap them to 'kuta' and add the common ones that were missing.
--
-- Run in the phpMyAdmin SQL tab. Idempotent (UPDATE + INSERT IGNORE / ON DUP).
-- Safe to re-run.
-- =====================================================================

UPDATE area_aliases SET area_key = 'kuta'
 WHERE alias_text IN ('mertak','sengkol','pengembur','pujut');

-- Drop the misleading combined alias if it was seeded.
DELETE FROM area_aliases WHERE alias_text = 'pujut praya';

INSERT INTO area_aliases (alias_text, area_key) VALUES
    ('pujut', 'kuta'), ('sengkol', 'kuta'), ('mertak', 'kuta'), ('pengembur', 'kuta'),
    ('rembitan', 'kuta'), ('sukadana', 'kuta'), ('bumbang', 'kuta'), ('prabu', 'kuta')
ON DUPLICATE KEY UPDATE area_key = VALUES(area_key);

-- Done.
