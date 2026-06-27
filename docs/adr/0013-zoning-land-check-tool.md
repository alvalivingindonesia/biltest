# 0013 — Zoning & Land Check: ingest-once spatial data, own Leaflet map, concierge paid tier, no owner data

- **Status:** accepted
- **Date:** 2026-06-28
- **Relates to:** ADR 0005 (the listings SVG region map — this tool deliberately does
  NOT use it); ADR 0007 (home-worker "pull data home, serve it ourselves" pattern,
  which this mirrors); ADR 0012 (DRAB — reuses the Indicative/Confirmed + basis +
  source + date trust vocabulary, the isolated-namespace pattern, and the
  printable-HTML export). Glossary section in CONTEXT.md.

## Context

Foreign HNW investors face real legal/financial anxiety about Indonesian land
zoning (*Tata Ruang*): is a given plot legally buildable for a villa, what are the
footprint/height limits, what is the certificate, where are its borders. The
official systems that hold this (GISTARU/ATR-BPN, BHUMI, KKPR/OSS) are slow,
bureaucratic, Indonesian-only, and not clean public APIs. We want a premium tool
that gives instant plain-English clarity for free and sells a verified, detailed
report.

Four hard realities shaped the design, established during a grilling session:

1. **ATR/BPN is the only origin of this data** — nobody else surveys it. The choice
   is not *which source* but *when/how we touch it*. GISTARU's own ArcGIS REST is
   gated ("Request Rejected"), so a live per-request proxy is fragile and exposed.
   The data is reachable through open mirrors: **BIG "Satu Peta"**
   (`kspservices.big.go.id/satupeta/rest/services`, folder `PERENCANAANRUANG`,
   public ArcGIS REST), **provincial/kabupaten SIMTARU** (ArcGIS REST / GeoServer
   WMS + shapefiles), and the **perda annexes** (RTRW for all 5 regencies; RDTR KEK
   Mandalika = Perbup Lombok Tengah 105/2021).
2. **Owner names are legally radioactive.** BHUMI itself withholds them; owner
   identity is confidential personal data (PDP Law UU 27/2022). Scraping/selling it
   is criminal + civil exposure and contradicts CONTEXT.md's NON-NEGOTIABLE trust
   posture. The legitimate channel is a notaris/PPAT *Pengecekan Sertipikat*.
3. **No payment gateway exists** — subscriptions are admin-granted, payments manual.
4. **The existing listings map (ADR 0005) is a hand-drawn SVG of market regions with
   no lat/lng projection** — it cannot do pin-drop, satellite, or polygon overlay.

## Decision

Build **Zoning & Land Check** (ID: *Cek Zonasi & Lahan*, route `#zoning`, isolated
`zoning_*` schema) as a new isolated subsystem. Key decisions:

1. **Ingest-once, serve ourselves.** Pull the official *pola ruang* (RTRW) and where
   available RDTR polygons **once** from Satu Peta / SIMTARU / perda annexes into our
   own DB; the live tool does **point-in-polygon against our copy**. Same shape as
   the Listing Worker (ADR 0007): pull home, store, serve fast — resilient,
   correctable, and honest about coverage. NOT a live proxy of GISTARU.

2. **Two data layers + a concierge tier.**
   - **Zoning** — ingested *Land-Use Class* polygons → a **Buildability Status**
     traffic light + plain-English can/can't-build. Island-wide where data allows.
   - **Plot Profile** — parcel/certificate facts (boundary, NIB, area, right-type,
     registered status, Land-Value-Zone) read **on demand per plot** from BHUMI's
     **documented WMS** (GetFeatureInfo for the queried plot; the WMS raster overlay
     for surrounding parcels), cached as we go. NOT a bulk parcel ingest.
   - **Verified Certificate Check** — the owner/encumbrance-grade layer, delivered as
     a **notaris/PPAT-brokered concierge service**, never as scraped data.

3. **Owner identity is out of scope as data — permanently.** No scraping, storing,
   displaying, or selling of owner names. The only owner-grade answer is the brokered
   notaris check. This is a boundary decision, not a v1 deferral.

4. **Indicative → Confirmed trust model (reused from DRAB).** Free = machine
   point-in-polygon, badged **Indicative** with source + date; copy never says
   "buildable/legal" as a guarantee — it says "indicatively zoned as X, verify before
   transacting." Paid = **human-verified Confirmed**. Decision-support, not legal
   advice; heavy disclaimer; the Zona-Hijau colour trap is handled explicitly (see
   Consequences).

5. **Own Leaflet map, separate from the listings map.** This tool uses its own
   Leaflet map (CDN, no build step): **Esri World Imagery** satellite basemap
   (keyless, attribution), **OSM/Photon** geocoding for landmark search, with
   pin-drop and paste-coordinates as the always-reliable inputs. The ADR-0005 SVG
   region map is untouched and stays scoped to property listings (vendors maybe
   later). Keyless-first; Google Places only added later if villa-name search blocks
   conversion.

6. **Monetization without a gateway.** Free = instant per-plot *basic* info (triage +
   basic Plot Profile, Indicative) + watermarked preview. Paid = the detailed,
   human-verified **Site Suitability Report** + Verified Certificate Check, collected
   via the existing manual channel (WhatsApp + generated invoice / admin unlock).
   Report records carry a status lifecycle (`requested → invoiced → paid →
   in_review → delivered`) so a real gateway (Midtrans/Xendit + Stripe) plugs in
   later without rework. Gated via `feature_access`, never hardcoded.

7. **Certificate upload is attachment-only, no OCR.** The SHM/Hak Pakai scan is a
   supporting document for the paid check (a human reads it); an optional manual
   "enter your NIB" field helps locate the parcel. Hardened to the security baseline:
   auth-gated, type/size whitelist, randomized names, stored **outside the web root**,
   never served raw, short retention, access-logged (it contains personal data → PDP).

8. **One generation engine, free/premium views, printable-HTML report.** The engine
   builds report content from our data; free renders the Indicative subset + a
   watermarked printable preview; paid unlocks the full + Confirmed content + notaris
   findings as a clean branded printable-HTML report (browser print-to-PDF). No PDF
   library — same rationale as DRAB's `DrabXlsx` (no Composer/`vendor/` on the host).

9. **KKPR/OSS is not a data source.** It is a permitting *workflow*; we compute a
   feasibility read from the zoning class, surface KKPR/PKKPR as a checklist step, and
   keep "we help you file it" as a future concierge upsell.

10. **Dedicated code** (deviation recorded, as with DRAB ADR 0012): `zoning.js` +
    `zoning.css` (own `<script>`/`<link>`) and `api/zoning_api.php`, parallel to the
    DRAB files. Reuse `.wizard`/`.rdtl-*`/existing CSS where possible.

## Why this and not the alternatives

- **Live proxy of GISTARU/BHUMI** was rejected: GISTARU's REST is gated, BHUMI has no
  documented public API, and presenting their gaps/outages as our real-time legal
  answer to HNW buyers is the catastrophic failure mode. Ingest-once owns accuracy.
- **Scraping/selling owner names** was rejected as illegal (PDP) and brand-poison; the
  notaris concierge is more valuable *and* lawful.
- **Reusing the ADR-0005 SVG map** was rejected — it has no lat/lng projection, so
  pin-drop, satellite, and parcel overlay are impossible on it.
- **Google/Mapbox from day one** was rejected for the same billing/lock-in reasons as
  ADR 0005; the thing investors actually need (satellite) is free via Esri.
- **A payment gateway in v1** was rejected as a major new PCI-adjacent security
  surface our NON-NEGOTIABLE posture would force us to harden first; concierge billing
  ships now and the lifecycle is gateway-ready.
- **Auto-OCR of certificates** was rejected: unreliable on legal docs (false-confidence
  on data that must be right) and an extra attack surface.
- **Bulk-ingesting parcels** like zoning was rejected: millions of gated, frequently
  changing polygons; on-demand-and-cache fits BHUMI's WMS and the per-plot use.

## Consequences

- A one-off **ingest** acquires Lombok *pola ruang* (RTRW, 5 regencies) and RDTR
  Mandalika, normalising raw `zona` codes → our **Land-Use Class** taxonomy with EN+ID
  plain-English translations and a Buildability Status. Coverage is honest: areas
  without ingested data show "not yet covered," not a false green.
- **The Zona-Hijau colour trap:** *Zona Hijau* ("green zone") is the **prohibited**
  class. The traffic light shows it **red**, and the UI never relies on colour alone —
  always an explicit label. Baked into the design to prevent a catastrophic
  mistranslation.
- Storing certificate uploads makes us a **PDP data processor**: encryption-at-rest
  where feasible, access-controlled, retention/deletion policy, consent wording.
- A future reader finds **two maps** (SVG listings map; Leaflet zoning map) and **two
  "zone" meanings** (DRAB price-Zone; this tool's Land-Use Class) — both deliberate;
  see CONTEXT.md.
- The new arbitrary-input surfaces (coordinate/geocode query, file upload, report
  request) get the standard `api/_sec.php` bootstrap, rate limiting, and SEC tracking.
