# Listings Location Model — handoff for the interactive map

**Purpose:** everything the map work needs to know about how listing location now
works after the Extractor + Place-tier changes (ADR 0009, 0010). Glossary terms
(Region / Area / Place / Area Alias) are defined in `CONTEXT.md`.

---

## 1. Three tiers: Region → Area → Place

| Tier | What it is | Map role | Table |
|------|-----------|----------|-------|
| **Region** | One of 6 *market* regions (South/West/Central/East/North Lombok, Gili Islands). NOT administrative kabupaten. | Top level of the map | `area_regions` (`region_key`, `label`, `label_id`) |
| **Area** | A distinct, named, market-meaningful destination. Kept deliberately **fine-grained** — the product's edge. Each gets a **map marker** + primary filter. | The markers/zoom targets | `areas` (`key`, `label`, `label_id`, `region_key`, `sort_order`) |
| **Place** *(NEW)* | A finer named locality that **rolls up** to a parent Area. Searchable + displayed on its own merit, but **no map marker of its own** (yet). | Filter chip + display label; future Area→Place drill-down | `places` (`place_key`, `label`, `label_id`, `area_key`, `sort_order`, `is_active`) |

- Every **Area** belongs to exactly one **Region** (`areas.region_key`).
- Every **Place** belongs to exactly one **Area** (`places.area_key`).
- Granularity is intentional: Kuta, Mawun, Are Guling, Selong Belanak, Mawi,
  Awang, Ekas are **separate Areas**, not one "Kuta". Two Areas were just added:
  **`awang`** and **`mawun`** (both `region_key = south_lombok`).

---

## 2. The roll-up principle (most important thing for the map)

**A listing in a Place carries BOTH keys:** `listings.place_key` *and*
`listings.area_key` (= the Place's parent Area). So:

- **The map and Area/Region filters are UNCHANGED** — they match `area_key` (and
  `area_key IN (areas WHERE region_key=…)`). A Mertak listing has
  `area_key='kuta'`, so clicking the **Kuta** marker already includes it.
- The listing **displays** as the Place ("Mertak", "Teluk Awang") via
  `place_label`, never as the bare Area.
- A **Place filter narrows to exactly that Place** (`place_key = ?`).

So: **Area filter = the Area + all its Places. Place filter = that Place only.**
You do not need to special-case Places for the existing region/area map behaviour
— roll-up is automatic because `area_key` is always set to the parent.

`location_detail` (free text) holds the specific place name as written
("Teluk Awang", "Torok Aik Belek") and is set even when no `place_key` resolved.

---

## 3. Listing columns relevant to location

| Column | Meaning |
|--------|---------|
| `area_key` | Parent Area (always set when known). Drives map/region/area filters. |
| `place_key` | Specific Place, nullable. NULL = no sub-place, just the Area. |
| `location_detail` | The place name as written (display fallback). |
| `extraction_method` / `extraction_confidence` | provenance (`llm` / `llm-location` / `fallback`); confidence is triage-only, not a gate. |

`status` enum is the lifecycle (`draft/active/under_offer/sold/expired`); the map
should only count/show `status='active' AND is_approved=1` (the API already does).

---

## 4. API — what the map consumes

Base: `/api/index.php`

### 4a. Listing list — `GET /api/listings`
Filters (query params): `region`, `area`, **`place`** (NEW), `listing_type`,
`min_price_idr`/`max_price_idr`, feature `tags`, etc.

- `area=kuta` → returns the Area **and all its Places** (rolls up).
- `place=mertak` → returns **only** that Place.
- `region=south_lombok` → all Areas (and their Places) in the Region.

Each listing in the payload now includes: `area_key`, `area_label`,
**`place_key`**, **`place_label`** (NEW). (Place fields are `_col_exists`-guarded
server-side, so they're absent until the migration runs — code defensively.)

### 4b. Counts for the map — `GET /api/listing_counts`
Returns live counts honouring all active filters:
```json
{ "regions": { "south_lombok": 210, "west_lombok": 47, … },
  "areas":   { "kuta": 88, "selong_belanak": 31, "awang": 9, … },
  "total":   532 }
```
**This currently has NO place-level counts.** If you want Place dots/badges,
extend `handle_listing_counts()` in `api/index.php` to also
`GROUP BY l.place_key` and add a `"places": { "mertak": 12, … }` block (one line
of SQL + a loop — same shape as `areas`).

### 4c. Geography lookups
- Areas: `SELECT key,label,label_id,region_key,sort_order FROM areas`
- Places: `SELECT place_key,label,label_id,area_key,sort_order FROM places WHERE is_active=1`
- (The worker pulls these via `api/listing_ingest.php?action=geography`, but for
  the map read them straight from the DB / add a small public endpoint if needed.)

---

## 5. How a listing GETS its area/place (context, not map work)

A local-LLM **Extractor** (Ollama, in the home Worker) reads each listing and
returns the real location, ignoring landmark/travel references ("20 min to
Kuta" ≠ in Kuta). The server resolves the place name → `place_key`/`area_key` via
**`area_aliases`** (now carries a nullable `place_key`). Aliases handle synonyms:
many rows → one key (`awang`/`teluk awang` → `awang`; `tumpak` → `are_guling`).
Unrecognised places are left unmapped (never force-fit) and surfaced for an admin
to alias once. Net effect for the map: **`area_key`/`place_key` are now reliable
and curated**, not keyword-guessed.

---

## 6. What's done vs. what the map session should build

**Done (data + API):**
- `places` table seeded (~45 Places) + `awang`/`mawun` Areas.
- `place_key` on listings, populated by the Extractor.
- `?place=` filter + `place_key`/`place_label` in the listing payload.
- Cards display the Place name (`app.js renderListingCard` uses `place_label`).

**Not done — candidates for the map work:**
1. **Place-level counts** in `listing_counts` (see 4b) — needed for any
   Place-aware map UI.
2. **Area → Place drill-down** on the SVG map: the map already does
   Region → Area zoom; Place is the natural third level (dots/labels inside an
   Area when zoomed). Deferred by ADR 0010 — "ship data + filter + labels now,
   map dots later." Places have **no coordinates** (Areas don't either — the map
   is a hand-traced custom SVG, ADR 0005), so Place markers would be
   hand-placed/curated, not data-driven.
3. **Place filter chips**: when an Area is selected, show its Places as
   sub-filter chips (`?place=`). Pure UI on top of the existing API.
4. Optional: per-Place pages / SEO ("Land for sale in Torok") — `place_key` +
   `places.label` already support it.

**Design rule to preserve:** keep Areas as the only map markers unless a Place
earns one by listing volume. Frequency data (which Places actually recur in
listings) should drive any promotion — don't plot every Place.

---

## 7. Quick reference — current Areas & their Places

Areas (markers): `kuta, mawun, are_guling, selong_belanak, mawi, awang, ekas,
gerupuk, sekotong, senggigi, mataram, praya, north_lombok, gili_islands` (+ a few
admin/other keys already in `areas`).

Seeded Places (roll up to the Area shown):
- **selong_belanak**: torok, serangan, tampah, lancing, mekarsari
- **kuta**: tanjung_aan, seger, merese, bumbang, mertak
- **sekotong**: pengantap, buwun_mas, mekaki, gili_gede, gili_asahan, bangko_bangko
- **mawi**: semeti, rowok
- **ekas**: pantai_surga, tanjung_ringgit, pink_beach, kaliantan, jerowaru
- **senggigi**: batu_layar, batu_bolong, mangsit, malimbu, nipah, setangi
- **north_lombok**: sire, medana, tanjung, bangsal, pemenang, senaru
- **awang**: gunung_tunak

(Authoritative list: `SELECT * FROM places`. The set grows as admins alias new
recurring locations.)
