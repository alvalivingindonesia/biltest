-- =====================================================================
-- Build in Lombok — search-sweep liveness (cheap expired-listing detection)
--
-- A "sweep" pages a portal's SEARCH-RESULTS grid (no detail-page fetch, reusing
-- the image-backfill machinery) and reports which of OUR active listings still
-- appear. A listing absent from N consecutive *complete* sweeps is a cheap
-- "probably expired" candidate — the worker then confirms only those with a
-- real --recheck (one detail fetch each) before anything is expired.
--
-- These columns are bookkeeping only; nothing auto-expires off them. The live
-- worker/site tolerate their absence (every reference is lc_col_exists-guarded),
-- so this can be applied any time.
--
-- Run in the phpMyAdmin SQL tab. Idempotent. Safe to re-run.
-- =====================================================================

DROP PROCEDURE IF EXISTS bil_add_col2;
DELIMITER $$
CREATE PROCEDURE bil_add_col2(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col) THEN
        SET @s = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END$$
DELIMITER ;

-- consecutive complete sweeps this active listing's URL was NOT seen (0 = seen last sweep)
CALL bil_add_col2('listings', 'sweep_absent_count', "sweep_absent_count INT UNSIGNED NOT NULL DEFAULT 0");
-- last time the listing appeared in a sweep of its portal
CALL bil_add_col2('listings', 'sweep_seen_at',      "sweep_seen_at TIMESTAMP NULL DEFAULT NULL");
-- last sweep that evaluated this listing's portal (seen or not)
CALL bil_add_col2('listings', 'sweep_checked_at',   "sweep_checked_at TIMESTAMP NULL DEFAULT NULL");

DROP PROCEDURE IF EXISTS bil_add_col2;

-- Done. Populated by api/listing_ingest.php (post_sweep); surfaced in
-- Ingest Console → "Stale / Gone". Confirm candidates with:
--   npm run recheck-gone      (worker: re-checks only sweep_absent_count >= 2)
