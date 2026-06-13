-- =====================================================================
-- Build in Lombok — unmapped place tally (Ingest Console "Unmapped" tab)
--
-- When the Extractor reads a place name that resolves to no Area/Place, the
-- server records it here (deduped, with a count) instead of flooding the review
-- queue with one row per listing. The admin maps the recurring ones once.
--
-- Run in the phpMyAdmin SQL tab. Idempotent. Safe to re-run.
-- =====================================================================

CREATE TABLE IF NOT EXISTS unmapped_places (
    place_text        VARCHAR(120) NOT NULL,           -- normalised place name (lowercase)
    sample_listing_id INT UNSIGNED NULL DEFAULT NULL,  -- one example, to click through
    cnt               INT UNSIGNED NOT NULL DEFAULT 1, -- how many listings hit it
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (place_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Done. Populated by api/listing_ingest.php (post_location / post_listing);
-- cleared per-place when an admin maps it in Ingest Console → Unmapped.
