# Directory Harvest — populating Providers from Google Maps

How the Vendor/Provider directory is populated and kept fresh from Google Maps,
and how to run the weekly refresh. Companion to `admin/import.php` (the interactive
saved-HTML importer); this is the **headless, scriptable** path used for bulk
population and the future weekly Cowork task.

## Principles (match the owner's manual method)
- **Quality bar:** keep only businesses with **≥ 3 reviews AND ≥ 4.0 stars**.
- **Lombok only, never Bali:** every business is placed by its Google Maps
  coordinates (`!3d{lat}!4d{lng}`). A bounding box (`lng 115.72–116.85`,
  `lat −9.10 to −8.10`) **rejects Bali** (which sits west of ~115.7) and Sumbawa/
  Java. Coordinates beat names — a Lombok plant called "Sinarbali" is kept; a
  "…Lombok" business whose pin is in Denpasar is rejected.
- **Right locations:** coordinates → `area_key` via the same bounding boxes as
  `admin/import.php` (`detect_area_from_coords`), with `other_lombok` as the
  catch-all for in-Lombok spots outside a named box.
- **Multi-category:** a business can hold several categories (e.g. an interior
  designer that also does furniture). Stored in `provider_categories`; the public
  filter (`api/index.php`) reads that junction table, so every category surfaces it.

## Pipeline (3 stages)
```
Google Maps  --harvest-->  directory_seed/<group>/<key>.json   (raw rows)
                              + directory_seed/manifest.json    (key -> group+cats)
   |
   |  node tools/dir_pack.mjs <raw_dir> <manifest.json> <out_batch.json>
   v
directory_seed/<group>_batch.json   (ingest-shaped, same business merged across searches)
   |
   |  node tools/dir_ingest.mjs <batch.json> --write
   v
live DB  (providers + provider_categories + provider_tags), via api/db_console.php
```
- **`tools/dir_pack.mjs`** — turns raw harvest files + the manifest into one ingest
  batch; merges the *same* business found in several searches (same name + ~location)
  into one row with the **union** of its categories.
- **`tools/dir_ingest.mjs`** — applies the quality gate, Lombok gate, GPS→area,
  slug + collision suffix (parity with `import.php`), entity dedupe (by phone, else
  name+area), and writes. Runs in **plan mode by default**; `--write` applies.
  - Existing match → **enriches** that provider with the harvested categories
    (so an already-listed business gains an empty category's tag); `--refresh`
    also updates its rating/review count.
- **`tools/dir_fix_areas.mjs`** — corrects any provider whose `area_key` contradicts
  its coordinates; reports (does not touch) off-island providers for review.
- **`tools/dir_fix_urls.mjs`** — repairs `google_maps_url` to the canonical listing.

### Maps links — must open the listing, never a bare pin
`google_maps_url` MUST open the business's Google Maps **place page** (reviews, photos,
address) — never a `/maps/search/?query=lat,lng` coordinate (that drops a useless pin).
The canonical link is `https://maps.google.com/?cid=<cid>`, where the cid is the place's
feature id: each result `a.hfpxzc` href contains `!1s0x..:0x<hex>`; `cid = BigInt('0x'+hex)`.
`dir_pack` derives this from the harvested href automatically (so the harvester must keep
the href). To repair existing rows, re-run the searches capturing `[lat,lng,ftid]` (return
the **ftid hex** not the decimal cid — the browser tool redacts long digit strings as
"base64"), drop them in `directory_seed/urls/`, then `dir_fix_urls <urls_dir> --write
--fallback`. It matches by exact coordinates (immune to name edits) and writes cid links;
`--fallback` gives any unmatched row a `…/search/<name>/@lat,lng,17z` link (resolves to
the listing for uniquely-named shops) instead of a bare pin.

Raw harvest = the in-page extractor output: `{n,r,v,lat,lng,t,ph}` (name, rating,
reviews, lat, lng, gmaps type, phone). The extractor scrolls the `[role=feed]` list,
then reads each `a.hfpxzc` card.

**Pagination — scroll to the real end.** Google Maps lazy-loads ~8 results per page
as you scroll. The extractor must keep scrolling the last card into view until the
**"You've reached the end of the list"** marker appears (or growth stalls for ~8s on
slow loads) — NOT stop after the first page. Stopping early silently drops most of a
category (a search that shows 7 often has 20+).

**Zoom — one island search is NOT enough.** At island zoom (~10z) Maps only returns
the most prominent ~120 businesses for that viewport; local shops in Selong, Praya,
Kuta, Tanjung etc. never appear until you zoom into that town. So every category must
be harvested at **per-town centres**, not just island-wide. Coordinates then place
each result and the pipeline dedupes the overlap. Town centres used:

| Centre | URL fragment |
|---|---|
| Mataram / Cakra / Ampenan | `/@-8.59,116.13,13z` |
| Gunung Sari / Senggigi (W coast) | `/@-8.50,116.09,12.5z` |
| Gerung / Lembar (SW) | `/@-8.70,116.11,13z` |
| Praya / airport (Central) | `/@-8.70,116.27,12.5z` |
| Kuta / Mandalika (South) | `/@-8.87,116.28,12.5z` |
| Selong / Masbagik / Aikmel (East) | `/@-8.62,116.50,12.5z` |
| Tanjung / Pemenang / Bayan (North) | `/@-8.37,116.13,12.5z` |

Raw files are named `<manifestKey>_<centre>.json` (e.g. `bangunan_selong.json`,
`paint_praya.json`); `dir_pack` resolves the category by the longest matching key
prefix. NB: dedicated trade/service categories (sumur bor, AC, waterproofing, solar)
stay Bali-dominated even when you zoom into a Lombok town — the coordinate gate filters
them; the per-town zoom mainly multiplies the *supplier/shop* counts (toko bangunan,
mebel, cat, besi, listrik, keramik).

`directory_seed/manifest.json` maps each raw file to its group + category_keys, with
`_overrides` for name-substring multi-category (e.g. `mitra10`). It is the record of
which search populated which category.

## Per-category search terms (Indonesian works best; omit "lombok" from the query)
| Category | Query | Notes |
|---|---|---|
| tiles_stone_supplier | `toko keramik granit`, `jual granit marmer batu alam` | |
| sanitary_supplier | `toko sanitary` | thin; mostly inside building stores |
| lighting_supplier | `toko lampu listrik` | |
| paint_supplier | `toko cat tembok` | strong |
| steel_rebar_supplier | `toko besi baja` | |
| readymix_supplier | `ready mix beton cor` | many Bali — coords filter them |
| brick_block_supplier | `jual batako paving block` | thin (informal) |
| building_materials_store | `toko bangunan`, `depo bangunan` | already deep |
| sand/gravel/aggregate/earth_fill/topsoil | `jual material pasir batu koral`, `jual pasir sirtu` | **sparse on Maps** — populated by tagging large "depo material" stores (see below) |
| timber_workshop | `toko kayu` | thin |
| well_drilling | `jasa sumur bor` | good |
| waterproofing | `jasa waterproofing anti bocor` | mostly Bali |
| solar_installer | `pasang panel surya solar` | **no qualifying Lombok business on Maps** — Bali/Java only |
| pool_contractor | `kontraktor kolam renang` | villa market |
| hvac_contractor | `toko AC service pasang AC` | rich |
| equipment_rental | `sewa excavator alat berat` | |
| structural/civil/mep/qs/project_manager | `konsultan teknik sipil`, `jasa manajemen konstruksi` | full-service *konsultan teknik* span several disciplines |
| land_surveyor | `jasa pengukuran tanah surveyor` | |
| furniture_joinery | `toko mebel furniture`, `jasa kitchen set mebel custom` | strong |
| tiler/mason/plumber/painter | `jasa renovasi rumah tukang bangunan`, `jasa plumbing`, `jasa pengecatan` | individual trades barely exist standalone — multi-trade renovation/`jasa tukang` services populate them |
| roofer | `jasa pasang baja ringan atap` | thin |

### Sparse categories — honest handling
Some categories genuinely have **no dedicated Lombok business** meeting the bar on Maps:
- **Bulk aggregates** (sand, gravel/riverstone, crushed stone, earth fill, topsoil):
  sold by the large **"depo material"** stores. Populated by multi-tagging the
  genuine high-review Lombok depots (Depo Jaya, PT Kokoh, Surya Jaya, Plaza Bangunan
  Lombok Timur, Pos Bangunan) — see `directory_seed/bulk_material_enrich.json`.
- **solar_installer:** the market is served by **Bali-based** firms (Nusa Solar,
  BTI Energy) that are correctly excluded by the Lombok gate. Left empty pending a
  decision: relax the gate for Lombok-serving Bali firms, tag a local electrical
  contractor, or leave empty.

## Weekly Cowork task (refresh)
Once a week (data changes slowly):
1. For each search term above, harvest fresh raw files into `directory_seed/<group>/`.
2. `node tools/dir_pack.mjs directory_seed/<group> directory_seed/manifest.json directory_seed/<group>_batch.json`
3. `node tools/dir_ingest.mjs directory_seed/<group>_batch.json --write --refresh`
   - new businesses are added; existing ones are **deduped** and their
     **ratings/review counts refreshed** (`--refresh`). Idempotent — safe to re-run.
4. `node tools/dir_fix_areas.mjs --write` to keep `area_key` coordinate-consistent.
5. Review the off-island list `dir_fix_areas` prints and deactivate any non-Lombok
   rows (`UPDATE providers SET is_active=0 WHERE id IN (...)`).

The console key lives in `config/sql_console.key` (gitignored). `directory_seed/` and
`tools/` are excluded from the public deploy (`.cpanel.yml` + `.htaccess`).
