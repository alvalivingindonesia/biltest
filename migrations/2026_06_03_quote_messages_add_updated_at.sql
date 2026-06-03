-- =============================================================
-- Migration: add updated_at to quote_messages (crash-safe queue claims)
-- Date: 2026-06-03
-- Purpose: the worker claims an outbound row by flipping status
--          queued -> sending. If the agent dies mid-send, the row must be
--          reclaimable. updated_at (ON UPDATE CURRENT_TIMESTAMP) gives us the
--          claim age, so api/quote_worker.php can reset a stale 'sending' row
--          back to 'queued'. See docs/adr/0002.
-- =============================================================
-- Run on: MySQL 5.7+ / MariaDB 10.2+ (HostPapa). Run AFTER
--          2026_06_03_create_quote_engine_tables.sql. Safe to re-run.
-- =============================================================

DROP PROCEDURE IF EXISTS bil_add_qm_updated_at;
DELIMITER $$
CREATE PROCEDURE bil_add_qm_updated_at()
BEGIN
  DECLARE col_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO col_exists
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'quote_messages'
     AND COLUMN_NAME  = 'updated_at';
  IF col_exists = 0 THEN
    ALTER TABLE quote_messages
      ADD COLUMN updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
      ADD KEY idx_qm_status_updated (status, updated_at);
  END IF;
END$$
DELIMITER ;

CALL bil_add_qm_updated_at();
DROP PROCEDURE IF EXISTS bil_add_qm_updated_at;
