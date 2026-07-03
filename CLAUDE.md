# CLAUDE.md — Build in Lombok (biltest)

## Project Overview
**Site:** https://biltest.roving-i.com.au  
**Repo:** git@github.com:alvalivingindonesia/biltest.git  
**Owner:** Jon (alvalivingindonesia@gmail.com)  
**OS:** Windows (Git Bash / Claude Code terminal)  
**Server:** Shared hosting (HostPapa) — PHP 7.4+, MySQL/MariaDB  
**Stack:** Vanilla PHP (no framework), single-page JS frontend, MySQL  

## Business Philosophy — GROW FIRST, MONETISE SMART
Revenue must never come at the cost of user acquisition. The free tier must be genuinely useful — users who get real value from the site become paying customers, advocates, and repeat visitors.

**Priority order:**
1. **Acquire** — Free tools must be good enough that users want to come back and tell others
2. **Engage** — Build habit and trust through useful free features (search, directory, basic RAB, guides)
3. **Convert** — Make upgrade paths clear, compelling and natural — never frustrating
4. **Retain** — Keep paying subscribers renewing through ongoing premium value

**The rule:** If a free user can't accomplish anything meaningful, they leave forever. If they can accomplish most things but hit a clear, fair ceiling — they upgrade.

## Subscription Tiers (Revenue Engine)
| Tier | Period options | Access |
|------|---------------|--------|
| Free | — | Genuinely useful core features — enough to build habit and trust |
| Basic | Monthly / Annual / Lifetime | Mid-tier power features |
| Premium | Monthly / Annual / Lifetime | Full access to all tools |

Feature gating is controlled via the `feature_access` DB table — toggled in Admin Console.  
**Free must be genuinely useful. Gate power features, not basic value.**

## File Structure
```
/                        ← Web root
├── index.html           ← SPA shell
├── app.js               ← All frontend JS (SPA routing, rendering, API calls)
├── style.css            ← Main styles
├── base.css             ← Reset / base styles
├── api/
│   ├── index.php        ← Public API (providers, developers, projects, guides, search)
│   ├── user.php         ← Auth API (login, register, profile, quotes, favourites)
│   └── rab_api.php      ← RAB (Bill of Quantities) API
├── admin/
│   ├── console.php      ← Full admin panel (users, subscriptions, listings, lookups)
│   └── rab_tool.php     ← RAB admin tool
├── .gitignore           ← /config/, /database/, /input data/ are excluded
```

Config lives OUTSIDE web root at `/home/rovin629/config/biltest_config.php` — never commit credentials.

## Key Entities
- **Providers** — Builders, architects, suppliers etc. (core directory)
- **Developers** — Property developers
- **Agents** — Real estate agents
- **Projects** — Property development projects
- **Listings** — Property listings (sale/rent)
- **Guides** — Blog/editorial content (SEO & trust building)
- **RAB** — Bill of Quantities / cost estimation tool (key upgrade driver)

## Database Conventions
- All IDs: `int UNSIGNED`
- Slugs: `varchar(150/200)` — used in URLs
- Timestamps: `timestamp` with `DEFAULT CURRENT_TIMESTAMP`
- Soft deletes via `is_active` flag — never hard delete records
- Use PDO prepared statements — NO raw string interpolation in SQL
- Currency stored as IDR (bigint), USD/EUR/AUD as int

## Coding Standards
- **PHP:** PDO only, prepared statements always, `json_out()` / `json_error()` helpers
- **JS:** Vanilla JS, no frameworks, `UserAuth.apiCall()` for authenticated requests
- **CSS:** CSS custom properties (variables) for all colours/spacing — see `base.css`
- **No build step** — files are served directly, no webpack/npm in production
- Keep PHP files compatible with PHP 7.4 (no named args, no enums, no fibers)
- Always use `htmlspecialchars()` / `escHtml()` when rendering user content

## API Patterns
```php
// GET endpoint
GET /api/index.php?action=providers&page=1&per_page=20

// POST endpoint (JSON body)
POST /api/user.php?action=save_quote
Content-Type: application/json

// Auth check
$uid = require_auth(); // throws 401 if not logged in

// Feature gate
$access = check_feature_access('rab_tool', $uid);
if (!$access['allowed']) json_error(403, 'upgrade_required');
```

## Freemium Development Rules
1. **Free must be genuinely useful** — directory search, provider browsing, basic guides, and the RAB calculator are free. Users must accomplish real tasks without paying.
2. **Gate thoughtfully, not aggressively** — use the `feature_access` table, never hardcode gates. Ask: "Would a frustrated free user leave the site entirely?" If yes, don't gate it.
3. **Every premium feature needs a free version** — e.g. RAB quick calculator (free) → full RAB project management with saved versions and exports (premium). Show the value before the wall.
4. **Upgrade prompts must sell the benefit** — never show a raw error. Show what they'd unlock, what it costs, and a clear CTA. Make it feel like an opportunity, not a punishment.
5. **WhatsApp CTAs are free value** — always surface WA/contact buttons for providers and agents. This builds trust and drives listing signups.
6. **SEO & guides drive the top of funnel** — clean markup, quality content, structured data. Free organic traffic = free user acquisition.
7. **Account creation is the first conversion** — nudge registration naturally (save searches, favourites, quote history). Never force it for basic browsing.
8. **RAB Tool is the primary upgrade hook** — basic estimation free, full project management premium. It's the clearest demonstration of paid value.
9. **If a gate causes drop-off instead of upgrades, loosen it** — acquisition always beats aggressive monetisation at this stage.

## Bug Tracker
Known bugs to fix later live in [BUGS.md](BUGS.md) — an in-repo tracker any Claude Code session can use.
- **Before bug-fix work:** read `BUGS.md` and check for relevant `Status: open` entries.
- **When you spot a bug** you won't fix right now: add it to `BUGS.md` (next `BUG-NNN` id, `Status: open`).
- **When you fix one:** flip its `Status:` to `fixed` and fill `Resolution:` (date — summary — commit hash).
- The file header has the full protocol (claiming, severity, the entry template). Don't delete fixed entries.

## Git Workflow
```bash
# Always pull before editing
git pull origin main

# After changes
git add -A
git commit -m "Brief description of change and business reason"
git push origin main
```

## Deployment
- **Auto-deploy from `main` only:** the test webserver uses cPanel Git Version
  Control (`.cpanel.yml`) which runs **only when you push to `main`** — it copies
  the repo to the live subdomain. Pushing to a feature branch does NOT deploy.
  So to make a change go live: merge/fast-forward it onto `main` and push `main`.
- Test (live) at: https://biltest.roving-i.com.au — hard-refresh to bypass cached
  CSS/JS, and verify there after pushing.

## Admin Access
- Admin panel: https://biltest.roving-i.com.au/admin/console.php
- Subscription management: `?s=subscriptions`
- Feature access controls: `?s=feature_access`

## Do Not Touch
- `/config/` — credentials, never edit or commit
- `/database/` — local DB dumps, gitignored
- `.gitignore` entries — keep them as-is

## Current Focus Areas (as of setup)
- Quote tracking feature (recently added in last commit)
- RAB tool improvements
- Subscription conversion optimisation
- Property listings module

## Automated Listing Ingestion (ADR 0007 / 0008)
A home-PC **Listing Worker** (Node + Playwright, `worker/`) crawls Lamudi/Rumah123/
dotproperty on a residential IP and posts raw facts to `api/listing_ingest.php`
(auth: `X-Worker-Key` = `WORKER_API_KEY`). The server canonicalises via
`api/listing_canonical.php` (per-are→total, `area_key` via `area_aliases`, IDR,
dedupe, trust model). Nightly re-check expires gone listings; `api/cron_reputation.php`
recomputes agent Reputation. Operate it from `admin/ingest_console.php`; fix existing
data once with `admin/recanonicalize_listings.php`. Schema:
`migrations/2026_06_12_listing_ingestion.sql` (needs `WORKER_API_KEY` +
`CRON_REPUTATION_TOKEN` in private config). Glossary in CONTEXT.md.

## Detailed RAB Generator (ADR 0012)
A new cost-estimation tool that generates contractor-grade RABs (BOQs) from a wizard
(Style → Structure → Roof → Size → Extras → Finish tier → Site), in its own isolated
**`drab_*`** schema. The old `rab_*` tool stays **frozen as a backup** ("Detailed RAB
(classic)") — do not change it; compare against it. Engine is **hybrid**: a flat
"Supply & Install" work-item rate catalog (the spine) with an optional AHSP coefficient
build-up underneath (material/labour split + recompute). Generation is **multi-driver
parametric**, coefficients calibrated from the owner's real Villa BOQs; **Spec Slots**
let the Finish Tier auto-pick materials (incl. waterproofing) with per-line swap; a
**Site Factor** (distance × access/terrain) lifts material + labour off a Mataram
baseline. Prices are **Indicative/Confirmed** with provenance (`basis` incl.
`other_province_adjusted` for styles sourced outside Lombok + premium); Confirmed pricing
and clean export are **premium** (gate via `feature_access`, never hardcode). Hierarchy:
**Development → Buildings → RAB**, with **stable `line_id`s + issued-baseline snapshots**
baked in for the future Variations portal (UI deferred). Output is fully **bilingual**
(EN+ID), IDR only, in the four-page house format (Final Summary → Structure →
Architecture → MEP) with optional room-by-room **Takeoff Rows** under items.

Code lives in dedicated files — **`drab.js`** (loaded via its own `<script>`, like
`i18n/*.js`) and **`api/drab_api.php`** (parallel to `rab_api.php`) — a deliberate,
ADR-recorded deviation from "all frontend JS in app.js," justified by app.js size and the
existing i18n/per-domain-PHP precedent. Reuse the existing `.wizard` / `.rdtl-*` /
`.rab-*` CSS (new bits in `drab.css`). Excel export is a **self-contained
`api/lib/xlsx_writer.php` (`DrabXlsx`)** — dependency-free OOXML via `ext-zip`, no
Composer/`vendor/`. Catalog/prices/templates seed from `migrations/2026_06_16_drab_seed.sql`
(derived from the 3 Villa BOQs + `Lombok_RAB_Database_v2` + other-province refs); schema in
`migrations/2026_06_16_drab_generator.sql`. Glossary in CONTEXT.md.

## Zoning & Land Check (ADR 0013)
A zoning/land-regulation tool for foreign HNW investors — instant plain-English
"is this Lombok plot buildable?" clarity (free) plus a paid, human-verified **Site
Suitability Report**. Isolated **`zoning_*`** schema; dedicated **`zoning.js`** /
**`zoning.css`** (own `<script>`/`<link>`, like `drab.js`) + **`api/zoning_api.php`**;
route `#zoning`. **Data is ingest-once, served by us** (point-in-polygon against our
DB) — NOT a live proxy: ATR/BPN is the only origin but reaches us via open mirrors
(**BIG Satu Peta** `kspservices.big.go.id/satupeta/rest/services` folder
`PERENCANAANRUANG`, kabupaten **SIMTARU**, perda annexes incl. **Perbup Lombok Tengah
105/2021** for RDTR Mandalika); GISTARU's own REST is gated. Three layers: **Zoning**
(ingested *Land-Use Class* → **Buildability Status** traffic light; *Zona Hijau* =
"green zone" is **Prohibited → RED**, never colour-only), **Plot Profile** (parcel/cert
facts via BHUMI's documented **WMS**, on-demand per plot + cache), and a notaris-brokered
**Verified Certificate Check**. **HARD BOUNDARY: owner names are out, permanently** —
confidential personal data (PDP Law UU 27/2022); the notaris check is the only
owner-grade route. Trust model reuses DRAB's **Indicative/Confirmed** + `source`/`date`;
free never says "buildable/legal" as a guarantee (decision-support, not legal advice).
Its own **Leaflet** map (Esri satellite, OSM/Photon geocoder, keyless-first, pin-drop +
paste-coords) — **separate from the listings map** (also Leaflet since ADR 0014, which
superseded the ADR 0005 SVG), which stays listings-only. **No payment gateway** yet (subs are admin-granted): free = instant basic
per-plot info + watermarked preview; paid = detailed verified report via WhatsApp +
generated invoice, with a gateway-ready status lifecycle. Cert upload is
**attachment-only, no OCR**, hardened + PDP-aware. Report = one engine, free/premium
views, **printable-HTML** (no PDF lib). KKPR/OSS = a permitting workflow, not a dataset
(checklist step + concierge upsell). Gate via `feature_access`, never hardcoded.
Glossary in CONTEXT.md.
