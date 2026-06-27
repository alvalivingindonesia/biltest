# Zoning & Land Check — data ingest runbook (ADR 0013)

This is how zoning polygons get into `zoning_landuse_polys`. The principle (ADR 0013):
**ingest-once, serve ourselves** — we pull official spatial-plan polygons into our DB
and do point-in-polygon locally, rather than proxying a flaky government service per
request. ATR/BPN is the only origin, but it reaches us through *open mirrors*.

## What we ingest

- **RTRW *pola ruang*** (kabupaten structure plan, ~1:50,000) — enacted for all 5 Lombok
  regencies. Coarse classes (Kawasan Pariwisata / Pertanian / Hutan Lindung / Permukiman
  …) → the free **Buildability Status** traffic light, island-wide.
- **RDTR** (detailed, 1:5,000, with **KDB/KLB/KKB** = footprint / floor-area / height) —
  only where enacted (Mandalika/Kuta + a few others) → the paid report's hard metrics.

We do **not** bulk-ingest BHUMI parcels (millions, gated, fast-changing) — those are read
on demand per plot via BHUMI WMS (see `api/zoning_api.php` `plot_profile`).

## Candidate sources (in order of preference)

1. **BIG "Satu Peta"** — `https://kspservices.big.go.id/satupeta/rest/services`
   (public ArcGIS REST, no login). Folders include `PERENCANAANRUANG`, `BATASWILAYAH`,
   `2021`, `2022`. Browse to a MapServer/FeatureServer layer carrying pola-ruang for NTB,
   note its layer id + the zona attribute field name.
2. **Kabupaten / provincial SIMTARU** — e.g. Lombok Tengah `simtaru.lomboktengahkab.go.id`,
   NTB provincial PUPR. These often expose ArcGIS REST or GeoServer WMS **+ shapefile
   downloads**. A shapefile can be loaded into QGIS and exported as GeoJSON, then ingested
   with a small adapter (or converted to the same `--service` query shape via a local
   ArcGIS/GeoServer, or hand-converted — see "Shapefile path" below).
3. **GISTARU RTR Online / RDTR Interaktif** — `gistaru.atrbpn.go.id`. The web maps are
   public but the `/arcgis/rest/services` root is gated ("Request Rejected"), so prefer 1–2.
4. **Perda / Perbup annexes (legal source of truth)** — e.g. **Perbup Lombok Tengah
   No. 105/2021** (RDTR KEK Mandalika). Use to georeference/digitise where no live service
   exists, and to set `confidence='confirmed'` after verification.

> Coverage is honest by design: any point with no ingested polygon resolves to the
> **`unknown` (Not Yet Mapped)** class in the UI — never a false "buildable".

## Ingesting from an ArcGIS REST layer

```bash
# Preview first (writes nothing; shows normalisation + any unmapped zona values):
node tools/zoning_ingest.mjs \
  --service "https://<host>/arcgis/rest/services/<svc>/MapServer/<layerId>" \
  --zona-field "<ZONA_ATTR>" --plan-level rtrw --kabupaten lombok_tengah \
  --source "RTRW Lombok Tengah (Satu Peta)" --source-date 2024-01-01 \
  --dry-run

# If the unmapped list is empty (or you have extended ZONA_MAP in the script), run live:
node tools/zoning_ingest.mjs --service "…" --zona-field "<ZONA_ATTR>" \
  --plan-level rtrw --kabupaten lombok_tengah --source "…" --source-date 2024-01-01
```

RDTR layers usually carry KDB/KLB/KKB columns — pass them so the report metrics populate:

```bash
node tools/zoning_ingest.mjs --service "…/RDTR_Mandalika/MapServer/0" \
  --zona-field SUBZONA --kdb-field KDB --klb-field KLB --kkb-field KKB \
  --plan-level rdtr --kabupaten lombok_tengah --source "RDTR KEK Mandalika (Perbup 105/2021)" \
  --source-date 2021-12-01
```

- The tool requests `f=geojson`; it falls back to Esri JSON rings if the server is old.
- Geometry is stored as **SRID 0** (X=lng, Y=lat) to match the lookup in `zoning_api.php`.
- Writes go through the same `db_console` channel as `tools/dbq.mjs` (key from
  `config/sql_console.key`). Bbox defaults to the Lombok/NTB window.

## Normalisation

`ZONA_MAP` in `tools/zoning_ingest.mjs` maps raw Indonesian zona text → our `class_key`
(see the taxonomy in `migrations/2026_06_28_zoning_seed.sql`). The `--dry-run` output lists
any **unmapped** zona values with counts — add them to `ZONA_MAP` (most-specific-first) and
re-run. Unmapped features are skipped, never mislabelled.

## Shapefile path (when only a download exists)

1. Open the shapefile in **QGIS**; reproject to **EPSG:4326**.
2. Export → GeoJSON.
3. Either serve it via a local GeoServer/ArcGIS and point `--service` at it, or write a
   small one-off adapter that reuses `geojsonToWkt()` + the same INSERT shape.

## Replacing the seed placeholders

`migrations/2026_06_28_zoning_seed.sql` inserts 3 broad **`seed_demo_v1`** polygons
(Mandalika tourism, Gunung Tunak conservation, Praya-plain agriculture) so the tool works
end-to-end. Once authoritative data is ingested for an area, delete the demo rows it
overlaps:

```bash
node tools/dbq.mjs --write "DELETE FROM zoning_landuse_polys WHERE source='seed_demo_v1'"
```

## Refresh & provenance

- Spatial plans change rarely. Re-ingest a kabupaten when its RTRW/RDTR is revised; bump
  `source_date`. Each row keeps `source` + `source_date` + `confidence`, surfaced in the
  UI as the Indicative provenance line.
- Mark a polygon `confidence='confirmed'` only after human verification against the perda
  (this is the paid report's value).

## Coverage check

`/admin/zoning.php?s=coverage` shows polygon counts per class and per source.
