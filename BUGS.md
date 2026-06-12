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
