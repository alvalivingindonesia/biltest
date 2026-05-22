-- =====================================================================
-- 2026-05-22 — Unified search (global command palette)
--
-- Adds FULLTEXT indexes on the two entity tables that were missing them
-- (agents and guides) so the extended handle_search() can use
-- MATCH(...) AGAINST(?) consistently across all six entity types.
--
-- Adds a search_queries telemetry table so we accumulate query history
-- from day one — future "Popular Searches" feature reads from this.
--
-- Run order: safe to run in any order; both ADD/CREATE are idempotent if
-- you guard manually (MySQL does not support IF NOT EXISTS on FULLTEXT
-- index definitions across all versions).
-- =====================================================================

-- ---------------------------------------------------------------------
-- FULLTEXT indexes for unified search
-- ---------------------------------------------------------------------

-- agents: search across display_name + agency_name + bio
ALTER TABLE agents
  ADD FULLTEXT KEY ft_agents_search (display_name, agency_name, bio);

-- guides: search across title + excerpt + content
ALTER TABLE guides
  ADD FULLTEXT KEY ft_guides_search (title, excerpt, content);

-- ---------------------------------------------------------------------
-- Search query telemetry
--
-- One row per backend search hit (after the in-handler noise filter:
-- skip < 3 chars and skip duplicates within 2s from the same session).
-- Anonymous queries log user_id = NULL. No IP, no fingerprinting.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS search_queries (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  query           VARCHAR(200) NOT NULL,
  result_count    INT UNSIGNED NOT NULL DEFAULT 0,
  user_id         INT UNSIGNED NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_search_queries_created_at (created_at),
  KEY idx_search_queries_query (query(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
