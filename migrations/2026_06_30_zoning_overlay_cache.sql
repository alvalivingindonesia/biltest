-- Zoning overlay performance: pre-simplified, pre-serialised colour-overlay geometry.
--
-- The #zoning colour overlay is a STATIC whole-island layer. Serving it by running
-- ST_Simplify + ST_AsGeoJSON per request was ~2.7s and, on a wide view, returned a
-- multi-MB payload. Instead we store the display GeoJSON once (Douglas–Peucker ~44 m,
-- coordinates capped to 5 decimals ≈ 1 m) and the API just concatenates the strings.
--
-- api/zoning_api.php ?action=overlay reads `gj_display` (COALESCE-falls back to an
-- on-the-fly ST_Simplify for any row where it is NULL) and sends it browser-cacheable.
--
-- Tolerance 0.0004° ≈ 44 m: fine for an indicative colour overlay (the precise layer is
-- "Land certificates"). Whole island ≈ 1.6 MB raw / ~135 KB Brotli.

ALTER TABLE zoning_landuse_polys
  ADD COLUMN gj_display MEDIUMTEXT NULL COMMENT 'Pre-simplified GeoJSON geometry for the colour overlay (ST_Simplify 0.0004, precision 5)';

-- Populate for all active polygons.
UPDATE zoning_landuse_polys
   SET gj_display = ST_AsGeoJSON(COALESCE(ST_Simplify(geom, 0.0004), geom), 5)
 WHERE is_active = 1;

-- IMPORTANT: after re-ingesting / editing zoning polygons (tools/zoning_ingest.mjs),
-- re-run the UPDATE above so new rows are fast. Until then the API still serves them
-- correctly via its on-the-fly COALESCE fallback (just slower for those rows).
