-- =====================================================================
-- Build in Lombok — listing change history (for the admin review/revert panel)
--
-- Every automated field change (worker re-check, recanonicaliser) records an
-- old→new row here so an admin can SEE what changed and REVERT it. Admin edits
-- and reverts are recorded too (source = 'admin' / 'revert').
--
-- Run in the phpMyAdmin SQL tab. Idempotent (IF NOT EXISTS). Safe to re-run.
-- =====================================================================

CREATE TABLE IF NOT EXISTS listing_revisions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,
    field      VARCHAR(40)  NOT NULL,          -- price_idr, area_key, title, description, land_size_sqm, …
    old_value  TEXT NULL DEFAULT NULL,
    new_value  TEXT NULL DEFAULT NULL,
    source     VARCHAR(20)  NOT NULL DEFAULT 'worker',  -- worker | recanonicalize | admin | revert
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reverted   TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_lr_listing (listing_id, changed_at),
    KEY idx_lr_recent (changed_at),
    KEY idx_lr_field (listing_id, field, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Done. Populated by api/listing_ingest.php (worker) + admin tools; surfaced in
-- admin/modified_listings.php.
