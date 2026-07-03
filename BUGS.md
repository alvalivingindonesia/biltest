# Bug Tracker — Build in Lombok

A lightweight, in-repo bug tracker. It lives in the repo so **any** Claude Code session
(or human) can read it, update it, and work a bug end-to-end with no external service or
auth. It is referenced from `CLAUDE.md`, so every session sees it automatically.

## How to use this file (Claude Code sessions: follow this)

- **Find work:** scan for entries with `Status: open`, highest severity first.
- **Claim a bug:** change its `Status:` to `in-progress` before you start.
- **Fix it:** implement the fix in the codebase as normal.
- **Close it:** set `Status: fixed` and fill `Resolution:` with the date, a one-line
  summary, and the commit hash (e.g. `2026-06-04 — preserved brand names in prompt; abc1234`).
  Leave the entry in place as history — do **not** delete fixed bugs.
- **Report a new bug:** copy the template below, give it the next `BUG-NNN` id (one higher
  than the current maximum — ids are never reused), fill it in, set `Status: open`.
- Keep entries ordered by id. Keep each entry **self-contained** (file paths, repro steps)
  so a cold session can act on it without extra context.

**Status:** `open` · `in-progress` · `fixed` · `wontfix`
**Severity:** `low` · `medium` · `high` · `critical` (= user impact; a data-integrity bug
outranks a display-only one).

### Template

```text
### BUG-NNN — <short title>
- **Status:** open
- **Severity:** <low|medium|high|critical>
- **Area:** <file(s) / module>
- **Reported:** <YYYY-MM-DD>
- **Description:** <what is wrong>
- **Repro / example:** <steps, or example input → wrong output>
- **Suggested fix:** <approach, if known>
- **Resolution:** <filled on close: date — summary — commit hash>
```

---

## Bugs

### BUG-001 — WhatsApp agent translates Indonesian brand / proper names
- **Status:** open
- **Severity:** low (display quality only — does not affect the price index or matching)
- **Area:** `agent/whatsapp-agent.Modelfile`; surfaces in `quote_messages.body_translated_en` / `body_expanded_id`, rendered by `renderQuoteDetail` in `app.js`
- **Reported:** 2026-06-03
- **Description:** The `whatsapp-agent` model literally translates Indonesian brand and product names. "Semen Tiga Roda" (a cement brand, lit. "three wheels") becomes "three-wheel cement" in `message_translated_english`. Brand names, product names, and proper nouns must be kept verbatim across all output fields.
- **Repro / example:** Send `semen tiga roda 75rb/sak` through the model → English output reads "three-wheel cement" instead of "Tiga Roda cement".
- **Suggested fix:** (1) Add a Modelfile SYSTEM rule: never translate brand/product/proper nouns; keep them verbatim in every field. Rebuild with `ollama create whatsapp-agent -f agent/whatsapp-agent.Modelfile`. (2) Optionally maintain a protected-brand glossary (Tiga Roda, Gresik, Holcim/Dynamix, SCG, Conch, Mapei, Aquaproof…) injected into the prompt. (3) Add a brand example to the verify test set. Note: `pricing_extracted.line_items[].item_label` already keeps the verbatim label and index matching uses `material_aliases` (ADR 0003), so the index is unaffected — this is display-only.
- **Resolution:** _(open)_

### BUG-002 — Admin server-side currency conversion never ran (wrong POST keys)
- **Status:** fixed
- **Severity:** medium (single-currency listings were saved without converted prices)
- **Area:** `admin/console.php` — `convert_listing_prices()`
- **Reported:** 2026-06-12
- **Description:** `convert_listing_prices()` read `$_POST['priceusd']` etc., but the listing edit forms post `price_usd` / `price_idr` / `price_eur` / `price_aud`. The lookup never matched, so the server-side conversion silently did nothing for every admin listing save since the feature was added.
- **Repro / example:** In the admin console, save a listing with only a USD price and JS disabled → `price_idr` stays NULL.
- **Suggested fix:** Use the real `price_<cur>` keys.
- **Resolution:** 2026-06-12 — fixed key names and aligned the function with the canonical-IDR model (docs/adr/0006): it now guarantees `price_idr` from any source currency instead of fanning out to all four columns — commit pending this session.

### BUG-004 — Lamudi per-are price stored as the total; area silently defaulted to Praya
- **Status:** fixed
- **Severity:** high (data-integrity — poisoned price filters/sorts and mislocated listings)
- **Area:** `admin/scrape_listings.php` (`parse_lamudi_listing`); area fallback in `detect_area_key`
- **Reported:** 2026-06-12
- **Description:** Lamudi listings priced "/are" had the per-are unit price stored directly in `price_idr` — the `Per Are` label was detected but never multiplied by land size, so a per-are figure masqueraded as the full price (and broke price filtering/sorting under ADR 0006). Separately, `detect_area_key()` dumped any location it couldn't keyword-match into `'praya'`, mislocating listings.
- **Repro / example:** Import a Lamudi land card showing "Rp 275Jt/are" on 100 are → stored as Rp 275,000,000 instead of Rp 27,500,000,000.
- **Suggested fix:** Canonicalise price/area server-side (docs/adr/0007). `lc_canonical_price()` multiplies per-are/per-m² by size (no trustworthy size → Price on Request + review flag); `lc_resolve_area_key()` maps structured location via `area_aliases` and never defaults.
- **Resolution:** 2026-06-12 — added `api/listing_canonical.php`; routed the Lamudi parser through it; one-time corrector `admin/recanonicalize_listings.php` fixes existing rows; ongoing ingest via `api/listing_ingest.php` + home Worker (ADR 0007/0008) — commit 37bf479. **Follow-up (same day):** the first corrector trusted the stored "Per Are" label and multiplied values that were already totals, producing trillion-rupiah prices. Replaced label-trust with magnitude-based inference (`lc_infer_price()`): every priced row is read against a plausible Lombok per-m² band (Rp 10rb–50jt/m²; soft ceiling Rp 15jt/m²); a value that is already a sane total is kept (never multiplied), only an implausibly cheap value is read as a unit price, and anything with no plausible reading is flagged for review rather than guessed. `lc_canonical_price()` gained a sanity guard (absurd total/per-m² → Price on Request + flag) — commit pending this session.

### BUG-003 — IDR price filter silently excluded USD-only listings
- **Status:** fixed
- **Severity:** high (19 of 532 live listings invisible to any price-filtered search)
- **Area:** `api/index.php` listings price filter; `listings.price_idr` data
- **Reported:** 2026-06-12
- **Description:** Price filtering compares only `l.price_idr`. Listings priced solely in USD/EUR/AUD have `price_idr` NULL, so any active price filter dropped them from results entirely.
- **Repro / example:** Live audit 2026-06-12: 19 USD-only listings disappear when any IDR price band is applied.
- **Suggested fix:** Canonical-IDR model — backfill `price_idr` for all priced listings and auto-fill it on every save.
- **Resolution:** 2026-06-12 — `normalize_listing_price_idr()` added to all listing write paths (`api/user.php`, `admin/console.php`) plus one-time backfill in `migrations/2026_06_12_map_filters_currency.sql` (fully fixed once Jon runs the migration) — commit pending this session.

### BUG-005 — Worker didn't detect Rumah123 "iklan sudah tidak aktif" inactive ads
- **Status:** fixed
- **Severity:** high (dead listings stay live with stale price/location)
- **Area:** `worker/lib/extractors.js` `GONE_MARKERS`
- **Reported:** 2026-06-12
- **Description:** Rumah123 keeps the detail page reachable (HTTP 200, URL unchanged) for expired ads, showing only a banner "Iklan ini sudah tidak aktif … belum diperbarui oleh pemilik iklan". `detectGone()` didn't list that phrase, so the re-check treated the listing as present and kept its stale price/location. Example: https://www.rumah123.com/properti/lombok-tengah/las8731478/ (listing #42) stays active.
- **Repro / example:** Re-check an expired Rumah123 ad → `recheck_status='present'`, listing stays `active`.
- **Suggested fix:** Add `tidak aktif`, `sudah tidak aktif`, `belum diperbarui`, `iklan ini sudah` to `GONE_MARKERS` (page text is already lowercased). On 'gone' the worker posts liveness only (never the price), and the server expires it.
- **Resolution:** 2026-06-12 — markers added; existing #42 expires on the next Worker re-check (or set `status='expired'` manually in the console meanwhile) — commit pending this session.

### BUG-006 — Real total price buried in the description was ignored
- **Status:** fixed
- **Severity:** high (per-are-only cards stored Price on Request despite a clear total in the text)
- **Area:** `api/listing_canonical.php` (price parsing); `api/listing_ingest.php`; `admin/recanonicalize_listings.php`; worker extractors
- **Reported:** 2026-06-12
- **Description:** Many Lamudi cards show only a per-are unit ("Jual 200 juta/are") while the description states the actual total ("Hanya 1,9 M"). The pipeline only read the structured card price, so these became Price on Request / per-are flags even though the total was right there in the text.
- **Repro / example:** https://www.lamudi.co.id/properti/41032-73-52f21a7e305b-bc52-ce4058cb-a6b8-422f — desc "LT 9.67 are … Jual 200 juta/are … Hanya 1,9 M"; the Rp 1.9 B total was discarded.
- **Suggested fix:** Indonesian description price parser (`lc_prices_from_text` / `lc_best_total_from_text`) that understands `1,9 M`, `200 juta/are`, `Rp 1.900.000.000`, ignores sizes (`9.67 are`, `967 m2`), and sanity-checks the recovered total against land size + the per-m² band. Use it as a fallback in ingest, and prefill the corrector's price field with it.
- **Resolution:** 2026-06-12 — parser added; `listing_ingest.php` falls back to the description total when the card price is missing/flagged; the worker now sends the full `description`; the recanonicalize desk shows "📝 from description: Rp …" and prefills the Save field — commit pending this session.

### BUG-007 — Area resolver relabelled correct listings to "Kuta" from a description mention
- **Status:** fixed
- **Severity:** high (data-integrity — would move dozens of correctly-located listings)
- **Area:** `api/listing_canonical.php` `lc_resolve_area_key`; area logic in `admin/recanonicalize_listings.php`; `area_aliases` seed
- **Reported:** 2026-06-13
- **Description:** `lc_resolve_area_key` sorted candidates by string length and scanned the whole description, so a listing whose description merely said "…near Kuta…" resolved to `kuta` and the corrector flagged it to move there — even though its stored area (are_guling, gerupuk, selong_belanak…) was correct. Separately the seed mapped Pujut-district villages (Mertak, Sengkol, Pengembur) to `praya` though they are the Kuta/Mandalika area, so a "Mertak" listing was flagged Kuta→Praya.
- **Repro / example:** Listings #700/#721/#724 etc. (correct areas) all suggested → Kuta; #659 "Mertak" (correctly Kuta) suggested → Praya.
- **Suggested fix:** Resolve candidates in priority order (structured/title first), word-boundary match, and only let the **title** override an already-set area; fill default/blank areas from the best signal (description → review, not auto). Remap Pujut villages to `kuta`.
- **Resolution:** 2026-06-13 — resolver rewritten (order-preserving, `\b` match) + `lc_resolve_area_with_source`; recanonicalize only flags a set-area conflict on explicit title evidence and leaves correct areas alone; alias fix in `migrations/2026_06_13_fix_area_aliases.sql` (+ corrected seed) — commit pending this session.

### BUG-008 — Lamudi listings stored the generic breadcrumb title, not the real name
- **Status:** fixed
- **Severity:** medium (every Lamudi listing showed "Tanah Dijual di Lombok Tengah"; descriptions/location/features lost)
- **Area:** `worker/lib/extractors.js`; `admin/scrape_listings.php` (`parse_lamudi`); `api/listing_ingest.php`
- **Reported:** 2026-06-13
- **Description:** The Lamudi title came from the breadcrumb/snippet ("Tanah Dijual di Lombok Tengah") instead of the real heading ("GILI NUSA ESTATE - Lokasi Kapling berada di bukit tertinggi"), and the full description wasn't captured — losing location ("Teluk Are Guling"), pricing guide and feature text we want for the site + area/tag detection.
- **Repro / example:** https://www.lamudi.co.id/properti/41032-73-… shows the real title + a rich description; the DB had the generic title and no description.
- **Suggested fix:** Prefer JSON-LD name / `<h1>` over the breadcrumb, rejecting generic titles (`lc_is_generic_title`); capture the full description (worker `readDescription`, paste importer JSON-LD/h1); guard the server so re-check never overwrites a real title with a generic one. Existing rows self-heal as the Worker re-checks them.
- **Resolution:** 2026-06-13 — `pickTitle`/`readDescription` in the worker (all 3 sites send the full `description`); paste importer prefers the non-generic title; `lc_is_generic_title` guard in `listing_ingest.php` — commit pending this session. Existing listings get corrected on the next Worker re-check cycle.

### BUG-009 — Listing images didn't render (photo_urls stored JSON-LD ImageObjects)
- **Status:** fixed
- **Severity:** high (most crawled listings showed no image despite having a valid, live image URL)
- **Area:** `api/index.php` (`attach_listing_primary_image`, `handle_listing_detail`); `worker/lib/extractors.js` (`imageUrls`)
- **Reported:** 2026-06-13
- **Description:** The crawler stored schema.org JSON-LD `ImageObject`s in `photo_urls` (`[{"@type":"ImageObject","contentUrl":"https://…jpg","name":…}]`) instead of plain URL strings. The API set `image.url` to the *whole object*, so the frontend rendered `<img src="[object Object]">` → blank. The actual URL was inside `contentUrl` all along; images were never missing or stale (verified live: `200 image/jpeg`, hotlinkable). The image-backfill tool reported `0 filled` because no `photo_urls` were actually empty.
- **Repro / example:** `GET /api/listings` → `data[0].image.url` was `{"@type":"ImageObject","contentUrl":"https://picture.rumah123.com/…"}` rather than the URL string.
- **Suggested fix:** Add `_photo_url_str()` that coerces a string OR an ImageObject (`contentUrl`/`url`/`@id`) to a URL; use it in the list image-derivation and as a `photo_urls` fallback for the detail gallery (non-destructive, no DB rewrite). Normalize `product.image` to plain URLs in the extractor (`imageUrls`) so future crawls store clean data.
- **Resolution:** 2026-06-13 — `_photo_url_str` + tolerant list/detail derivation in `api/index.php`; `imageUrls()` normalizer applied to all four site extractors; verified live API now returns `image.url` as a string and the URLs load — commit c6a2888.

### BUG-010 — Coastal places dragged into "Praya" by the kecamatan
- **Status:** fixed
- **Severity:** high (data-integrity — south-coast listings showed the wrong area, undermining the granular-location selling point)
- **Area:** `api/listing_ingest.php` (location fallback order); `api/listing_canonical.php` `lc_resolve_location`
- **Reported:** 2026-06-25
- **Description:** Listings for Torok, Tampah, Lancing, Pengantap, Selong Belanak etc. resolved to area `praya`. Two compounding resolver bugs: (1) the ingest fallback matched candidates in the order kecamatan→…→title, so the broad admin district `location_detail` ("Praya Barat Daya, Lombok Tengah") was tried BEFORE the title that names the real place; (2) the only `praya` alias substring-matched `\bpraya\b` inside "praya barat"/"praya barat daya" — which are the south-COAST kecamatan, not Praya town. The place aliases (`torok aik belek → selong_belanak`, `pengantap → sekotong`, …) were already correct; this was purely order + greedy matching.
- **Repro / example:** Listing #1010 "Di Jual Tanah Di Torok Aik Belek Beach Front", `location_detail`="Praya Barat Daya, Lombok Tengah" → `area_key`='praya'.
- **Suggested fix:** Reorder the ingest fallback so `llm_place`+`title` are tried before admin districts; strip "praya (barat daya|barat|timur|tengah)" in `lc_resolve_location` before alias matching so a bare "praya" can't match the coastal kecamatan.
- **Resolution:** 2026-06-25 — both fixes in commit e8fa91d (verified: a Torok title now resolves to selong_belanak/torok); 10 already-misclassified active rows re-pointed to selong_belanak / sekotong (+place_key) via the SQL console. Future crawls + worker re-checks self-heal the rest.

### BUG-011 — Per-are "land plots" development priced as a 1-are total (understated ~20×)
- **Status:** fixed
- **Severity:** high (price understated ~20× — a Rp 700M min plot shown as a ~$1,960 / 1-are card)
- **Area:** `api/listing_canonical.php` (`lc_parse_id_number`, `lc_prices_from_text`, new `lc_min_are_from_text` + `lc_per_are_development`); `api/listing_ingest.php` (post_listing)
- **Reported:** 2026-06-25
- **Description:** Listing #1123 "SELONG BELANAK LAND PLOTS" is a PER-ARE development ("From 35,000,000 IDR per Are, Min 20 Are"). Lamudi's price selector reads only the headline "Rp 35.000.000" — the "/Are" unit lives in the description body — so it entered labelled 'Total', and the land size was a nominal 1-are (100 m²) stub. Read as a total, 35M/100m² = Rp 350k/m² sits in the plausible band → not flagged, and the sub-$10k price-floor's per-are rescale is a no-op (are = 100/100 = 1). Two latent parser bugs compounded it: `lc_parse_id_number` mis-read US comma-thousands ("35,000,000"→35) and `lc_prices_from_text` dropped a per-are figure when a currency word ("IDR") sat between the number and "per Are". Net: min total ≈ Rp 700M (35M×20) shown as Rp 35M.
- **Repro / example:** `GET /api/listings/selong-belanak-land-plots-beach-view-jungle-view` → price_idr=35,000,000, land_size_sqm=100.
- **Suggested fix:** parse US commas; let IDR/Rp sit between number and unit; a per-are/per-m² unit qualifies a figure as a price; add `lc_per_are_development` to recover total=per_are×min_are and land=min_are×100, tightly gated (0<size≤200 nominal stub AND explicit per-are price AND stated minimum) so genuine small plots (#903) and null-size developments (#746) are untouched.
- **Resolution:** 2026-06-25 — commit 4f5e13b (verified vs #1123/#903/#746/#1111 with a JS mirror of every regex); #1123 corrected to Rp 700M / 20 are / label 'From' via the SQL console (+audit revisions). Ingest handler kept deliberately narrow to the nominal 1-2 are stub (commit 38a2c72): larger per-are listings often say "mulai dari Rp X per are" (a FLOOR rate), so auto-recomputing per_are×size would understate premium plots. Full audit of the 23 per-are-development listings found exactly 3 wrong — all fixed via SQL (+audit revisions): #1123 (per-are as 1-are total → Rp 700M/20are), #1111 (price inflated 10× → Rp 900M/3are), #1068 (land inflated 10× → 7000 m², price already correct). The rest are consistent (ratio ≈1.0) or legitimate from-rate premium plots; two "Harga total Rp X juta/are" seller-typo listings (#1024/#1093) were verified correct as totals and left alone.
