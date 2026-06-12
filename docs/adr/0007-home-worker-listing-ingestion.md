# Home-worker browser ingestion with server-side canonicalisation

## Status

accepted

## Context

Property listings are ingested from external portals (Lamudi, Rumah123, dotproperty,
OLX). The only existing ingest path is `admin/scrape_listings.php`: an admin manually
opens a portal's search-results page, does "View Page Source", and pastes the HTML for
PHP regex parsers to extract. This is paste-based because the portals are JS-heavy
(Rumah123 is Next.js RSC) and sit behind anti-bot protection, so a plain server-side
`curl` from HostPapa's datacenter IP is blocked or returns HTML without the data.

That path produced three classes of bad data, all visible in the parsers: per-are
prices stored as totals (the `Per Are` label is detected but never multiplied by land
size — `parse_lamudi_listing`), listings dumped into the wrong area (`detect_area_key`
keyword-scans a truncated card snippet and silently defaults to `praya`), and missing
or mis-scaled prices. A prior remediation attempt — an admin manually opening each
Lamudi page and re-typing the price into the console, ~87 listings, 4–6 hours — was
abandoned as unsustainable, and would have to be repeated every time data drifts.

The site has no SSH/root and caps PHP execution time. A residential Windows box (the
same class of machine ADR 0002 uses for the quote engine) is always on, has a
residential IP, and can run a real browser.

## Decision

Build an automated ingestion pipeline modelled on ADR 0002's home-pull worker, with
business rules on the server.

- A **Listing Worker** runs on the always-on home PC. It drives a headless browser
  (residential IP defeats the anti-bot) and makes only outbound HTTPS calls to a single
  authenticated, worker-facing ingest API on HostPapa. HostPapa never calls the home
  box; MySQL stays the single source of truth. Windows Task Scheduler triggers it
  nightly; it pulls its work list from the server ("what should I check tonight?").
- **Two-phase crawl.** *Discovery* reads search-results pages (Lamudi + Rumah123 +
  dotproperty; **not** OLX, which owns Lamudi and would re-discover the same stock as
  duplicates) to find new listings. *Re-check* fetches one listing's *detail* page for
  authoritative price/size/location and a liveness signal.
- **Nightly rolling window.** Each night the Worker re-checks the oldest-checked N
  listings (~80) with randomised 30–60s gaps, sequential never parallel. Every listing
  cycles roughly weekly; the source only ever sees a trickle. Re-check covers all sites
  already in the DB regardless of Discovery scope.
- **Worker extracts, server canonicalises.** The Worker posts clean JSON *facts* (price
  number + unit label, land size + unit, structured kecamatan/desa, certificate text,
  photos, agent). The server applies the business rules at ingest: per-are/per-m² ×
  size → total; structured address → `area_key` via an **Area Alias** table (unmapped
  locations queue for one-time admin mapping, never defaulting to an area); any currency
  → canonical `price_idr` (ADR 0006); dedupe.
- **Trust model.** Routine changes auto-apply. **Locked Fields** (admin hand-fixes,
  tracked per-field) are never overwritten — the Worker may still auto-update a
  listing's untouched fields. Suspicious changes (>~5× price swing, `area_key` flip) go
  to a review queue instead of auto-applying.
- **Liveness.** A genuine removal (404, redirect to search, "tidak tersedia") expires
  the listing immediately (`active → expired`). A fetch *failure* (timeout, network
  blip, anti-bot block) is skipped and retried, never counted as a removal. Liveness
  only moves `active → expired`; it never touches `sold`/`under_offer`/`draft` and never
  hard-deletes.
- **Per-are safety.** Compute a total only when the size is trustworthy; otherwise store
  no total (Price on Request) and flag for review. Never store a guessed total.

Fixing the existing data is not a separate build: it is the Re-check pipeline run once
over all current listings after the parser fixes land.

## Why this and not the alternatives

A cPanel cron + `curl` on HostPapa was rejected: a datacenter IP is the thing the
portals block, and PHP execution caps and the ~1-minute cron floor make a synchronous
fetch+parse loop fragile. It also can't render the JS the portals need. A paid external
scraper API (ScraperAPI/Bright Data/Apify) was rejected as an ongoing cost for a problem
the home box already solves for free, given low weekly volume. Continuing the
manual-paste importer was rejected as the status quo that already failed at ~87
listings. Putting the business rules on the Worker (so the server just writes) was
rejected because per-are math, `area_key` resolution and IDR normalisation must be
shared by every ingest path (Worker, admin paste, manual edit) and live next to the
canonical data — ADR 0006 already requires it. Shipping raw HTML to PHP regex was
rejected as the fragile core of the old approach; DOM extraction in the browser is more
robust and detail-page parsers are new work either way.

## Consequences

- A new always-on component (the Listing Worker) must be built and kept running on the
  home box, alongside a Windows Task Scheduler trigger.
- A new authenticated worker-facing ingest API and the canonicalisation step must be
  built on HostPapa; any future ingest path must route through canonicalisation or the
  per-are/area/IDR bugs return.
- New schema is needed: per-listing re-check bookkeeping (`last_rechecked_at`, a
  fetch-failure-vs-removal distinction), per-field locks, an Area Alias table, and a
  review queue for surprises + unmapped locations.
- Detail-page parsers are a new per-site maintenance surface and will break when portals
  change layout; starting Discovery on three sites (not four) keeps that surface
  smaller.
- "Freshness" is poll-bounded to the nightly cycle, and each listing's liveness is up to
  ~a week stale — acceptable for property listings.
- This supersedes the manual price-fill remediation and reframes data correction as a
  one-time pipeline run rather than recurring hand-editing.
