# Build in Lombok

A freemium directory and tooling platform for people building in Lombok: a public
directory of local businesses, a RAB (cost-estimation) tool, and a paid engine that
gathers price quotes from vendors over WhatsApp. This glossary defines the shared
language across the directory and the quote engine.

## Security — a first-class priority (NON-NEGOTIABLE)

**Security is a primary product requirement for Build in Lombok, not an afterthought.**
This site handles user accounts, authentication, payments-adjacent subscription state,
private quote/RAB data, and an admin console with full data access. A breach would be
existential for trust and the business.

**The rule:** Every future addition or change to the website — feature, refactor, copy
tweak, migration, dependency bump — MUST be designed and implemented through a
cyber-security lens. Functionality, growth, speed, and convenience are **never** to be
delivered at the expense of security. If a desired feature cannot be built securely, it
does not ship until it can. When the GROW-FIRST philosophy (CLAUDE.md) and security
appear to conflict, **security wins** — a hacked free tier acquires no one.

**Secure-by-default baseline every change must uphold:**
- **Server-side trust only.** Authentication, authorization (ownership + role), and all
  freemium/premium gating are enforced on the server. The client is untrusted; never gate
  a paid feature with only a JS flag.
- **Parameterized SQL always.** PDO prepared statements, no string-interpolated SQL,
  whitelist any dynamic table/column/sort identifiers.
- **Escape all output.** `htmlspecialchars()`/`escHtml()` server-side; safe DOM APIs
  (`textContent`, not raw `innerHTML`) for any user/API-derived data on the frontend.
- **No secrets in the repo.** Credentials live only in the private config outside the web
  root; nothing sensitive (config, `.git/`, raw SQL, internal docs) may be served.
- **CSRF + session hygiene.** State-changing requests carry anti-CSRF protection;
  sessions use HttpOnly/Secure/SameSite cookies and regenerate on privilege change.
- **Defense in depth.** Security headers (CSP, X-Frame-Options, etc.), rate-limiting on
  auth endpoints, validated/limited uploads, and least-privilege everywhere.
- **Validate, then store; encode, then render.** Treat every external input (users,
  scraped portals, the worker, the LLM extractor) as hostile.

**Process:** Outstanding hardening work and the running security backlog are tracked in
[SECURITY_AUDIT.md](SECURITY_AUDIT.md) (findings carry `SEC-NNN` ids, worked one by one
until all are `resolved`). Before launch, all Critical/High findings must be `resolved`.
When you introduce a new endpoint, form, file operation, third-party dependency, or admin
action, apply the same discipline — and add a `SEC-NNN` entry for anything you cannot fix
immediately.

## Directory

**Vendor**:
The user-facing name for any business listed in the directory — a builder, trade,
professional service, or material supplier. Stored and referenced in code as a Provider.
_Avoid_: Service (the old label), business, listing.

**Provider**:
The data-layer name for a Vendor — the canonical entity behind every directory listing,
referenced everywhere by `provider_id`. "Vendor" is the display label; "Provider" is the
model. Use Provider in schema and code, Vendor only in UI copy.

**Supplier**:
A Vendor in the `suppliers_materials` group — a business that sells building materials.
A subset of Vendors, not a separate entity.
_Avoid_: Store, merchant, shop.

## Listings & map

**Region**:
One of the six *market* regions of Lombok used to group listings: South Lombok, West
Lombok, Central Lombok, East Lombok, North Lombok, Gili Islands. Deliberately NOT the
administrative kabupaten — e.g. Kuta sits in Kabupaten Lombok Tengah but belongs to the
South Lombok Region, because that is how buyers think. Table: `area_regions`.
_Avoid_: kabupaten, regency, district.

**Area**:
A distinct, named, market-meaningful locality within a Region — the level the
interactive map pins and the primary location filter. Kept deliberately fine-grained
as the product's edge: a Lombok-only site treats Kuta, Mawun, Are Guling, Selong
Belanak, Mawi, Awang and Ekas as **separate** Areas rather than clubbing them under one
"Kuta", because buyers treat them as separate destinations and a general portal never
makes that distinction. An Area earns a row only if it is an anchor destination worth a
map marker; finer spots are Places that roll up to it. Listings carry `area_key`; every
Area belongs to exactly one Region. Table: `areas`.
_Avoid_: location, zone, suburb, kabupaten.

**Place**:
A finer named locality that rolls up to a parent Area — searchable and displayed on its
own merit, but *not* its own map marker. Torok and Serangan are Places in the Selong
Belanak Area; Mertak and Bumbang in Kuta; Pengantap, Buwun Mas and Gili Gede in
Sekotong. A listing carries an optional `place_key` alongside its `area_key`: it
**displays** as the Place ("Mertak", never "Kuta"), a place filter narrows to it
exactly, yet the parent Area's map marker and filter still find it. The level that lets
the site name a spot precisely without cluttering the map. Table: `places` (`place_key`,
`label`, parent `area_key`).
_Avoid_: calling a Place an Area; storing a Place only as free-text `location_detail`.

**Cluster**:
A named geographic grouping of neighbouring Areas, used **only** by the interactive
map to add an intermediate zoom step in dense regions (e.g. the South Lombok coast).
The map flow is Region → Cluster → Area: tapping a Cluster zooms to its stretch of coast
and reveals the member Area markers. A Cluster is a *presentation* concept — it has no
table, no listing column, and never changes how an Area or Place is stored or filtered;
a listing belongs to a Cluster only by virtue of its Area being a member. Cluster names
are distinct from Area names (the "Kuta–Mandalika" Cluster is not the "Kuta" Area).
Membership and zoom boxes are curated, not data-driven (the map is a hand-traced SVG, no
coordinates exist in the DB).
_Avoid_: sub-region, kabupaten; do not give a Cluster the same name as a member Area.

**Display Currency**:
The currency a visitor chooses to *view* listing and development prices in (IDR, USD,
EUR, AUD). A presentation setting only — it never changes what a listing costs or how
it is stored. Scoped to property listings and developments; materials and RAB prices
are always IDR.

**Price on Request**:
A listing with no price in any currency. Shown without a price and excluded from
results only while a price filter is active.

**Feature Tag**:
A canonical amenity/attribute key on a listing (beachfront, ocean_view, pool,
furnished, cliff_top, rice_field_view, near_airport, …) with EN and ID labels.
Assigned automatically at import by keyword scan and correctable by admins; the only
thing feature filters are allowed to match against. Table: `listing_tags`.
_Avoid_: feature, amenity, keyword (as schema/code names).

## Listing ingestion

**Source Site**:
The external property portal a listing was ingested from — `lamudi`, `rumah123`,
`dotproperty`, `olx`. Stored on the listing as `source_site` alongside
`source_listing_id` (the portal's own id, stable identity for re-check) and
`source_url` (the detail page). A listing has exactly one Source Site. Discovery
actively scans Lamudi, Rumah123 and dotproperty only: **OLX owns Lamudi**, so scanning
OLX would re-discover Lamudi stock as cross-site duplicates. OLX listings already in
the DB are still Re-checked.
_Avoid_: portal, origin, provider (Provider is the directory entity).

**Listing Worker**:
The always-on home PC that runs a headless browser to fetch source pages, extracts
raw facts, and posts them to HostPapa over an outbound-only authenticated channel. The
ingestion counterpart of the quote engine's worker (ADR 0002); never receives inbound
calls. HostPapa's MySQL stays the single source of truth.
_Avoid_: scraper, bot, crawler (as the named component).

**Discovery**:
Phase one of a crawl: reading a Source Site's *search-results* pages to learn which
listings exist (their `source_listing_id` + detail URL). Finds new listings; does not
produce authoritative per-listing data.
_Avoid_: scrape, import (too broad).

**Re-check**:
Phase two and the steady-state job: fetching one listing's *detail* page to (a) confirm
it is still active and (b) re-read its authoritative price/size/location. Runs as a
nightly rolling window over the oldest-checked listings. Once ingestion is correct,
re-check should rarely change anything except liveness.
_Avoid_: refresh, sync, update (too broad).

**Extractor**:
The local-LLM (Ollama) step **inside** the Worker that reads a listing's raw page
signals and returns structured JSON facts — title, price, sizes, certificate, the
verbatim description, and the location (ADR 0009). It *finds* facts; Canonicalisation
still *decides* what is stored. Prose is copied verbatim, numbers normalised, the LLM
is primary with JSON-LD as a hint and a deterministic fallback when Ollama is down.
Distinct from the quote engine's separate `agent/` process — the Extractor is an inline
function call, not a service. _Avoid_: agent (overloaded), AI, parser.

**Canonicalisation**:
The server-side step at ingest that turns a Worker's raw extracted facts into stored
columns: per-are/per-m² → total price, location string → `place_key`/`area_key`, any
currency → canonical `price_idr` (ADR 0006), plus dedupe. The business rules live here,
on HostPapa next to the DB — not on the Worker or in the Extractor.
_Avoid_: cleaning, processing.

**Area Alias**:
A saved mapping from a location surface-form (a kecamatan/desa or place name as
written, or a shorthand like "Awang" for "Teluk Awang") to a canonical `place_key`
and/or `area_key`. **Many aliases may point to one locality** — that is how synonyms and
spellings ("Klancing"/"Lancing", "Pink Beach"/"Tangsi", "Sira"/"Sire") all resolve to
the same place. A Place alias implies its parent Area. Created once by an admin — or
seeded — when an unmapped location surfaces, then reused forever to auto-resolve location
at Canonicalisation, and injected into the Extractor prompt so the LLM learns it too. An
unmapped location is queued for mapping, never silently defaulted.
_Avoid_: location map, region lookup.

**Liveness**:
Whether a listing still exists on its Source Site, read by a Re-check from the detail
page. A **genuine removal** — 404, redirect to search, or "tidak tersedia"/"not
available" text — expires the listing immediately (`status: active → expired`). A
**fetch failure** — timeout, network blip, anti-bot block — is *not* a removal: it is
skipped and retried next cycle, never counted against the listing. Liveness only ever
moves `active → expired`; it never touches the market/human statuses `sold`,
`under_offer`, or `draft`, and never hard-deletes (soft-delete rule). `status` (enum:
`draft`, `active`, `under_offer`, `sold`, `expired`) is the listing lifecycle field;
listings have no `is_active` column (that flag lives on providers/agents).
_Avoid_: deleted, dead, removed (as the stored state — the state is `expired`).

**Locked Field**:
A specific listing field (price, area_key, land size, …) an admin has manually
corrected, marked so the Worker's Re-check never overwrites *that* value. Locking is
per-field, not per-listing: the Worker may still auto-update the untouched fields of a
listing that has some locked ones. The guard that lets auto-apply ingestion coexist
with hand-fixes.
_Avoid_: locked listing (locking is field-level), pinned, frozen.

## Agents

**Agent**:
A real-estate agent or agency that lists properties — the entity a Listing is attributed
to via `agent_id`, and a browsable directory profile ("open by agent, see their
listings"). A separate entity from Provider/Vendor (the trades/services directory):
different table (`agents`), different purpose. One Agent has many Listings; a Listing
has at most one Agent.
_Avoid_: provider, vendor, seller (seller = the private-seller case below).

**Agent Source**:
One portal profile of an Agent — a `(source_site, source_agent_id, source_profile_url)`
tuple. A single canonical Agent has one or more Agent Sources, because the same real
agent posts on several portals. Matched and merged to the canonical Agent by normalised
phone/WhatsApp number (name as tiebreak); ambiguous matches go to the review queue, not
auto-merged. This is what lets "browse by agent" and Reputation see all of an agent's
listings instead of a fragment.
_Avoid_: agent row (the canonical Agent is the row that matters), duplicate.

**Private Seller**:
A listing posted by an individual with no agency. Bucketed into a single shared,
**hidden** per-site Agent (e.g. "Private Seller (Lamudi)"): the listing keeps an
`agent_id`, but the row is excluded from the Agent directory, search, and Reputation. A
real person, just not a browsable agent.
_Avoid_: treating as a normal Agent, or as a Platform Placeholder.

**Platform Placeholder**:
The portal's own name appearing as the seller ("Lamudi", "Rumah123") — not a person at
all. Never stored as an Agent; such listings get `agent_id = NULL` and show no agent.
Distinct from a Private Seller (a real human).
_Avoid_: agent, private seller.

**Reputation**:
An Agent's *earned* trust, computed nightly from listing volume + tenure (time since
first seen) + current active count. Deliberately separate from the manual `is_verified`
(portal/verified badge) and `is_trusted` (editorial curation, ADR 0001) flags — those
stay independent human levers. Tenure and track record count distinct listings *ever
seen* (expired/sold included, which the soft-delete rule preserves), so Reputation does
not evaporate when listings expire; active count is shown separately. Surfaced as a
score + tier badge and usable for sort.
_Avoid_: trust (ambiguous with `is_trusted`), rating (that is `google_rating`).

## Quote engine

**Get Quotes (manual)**:
The existing free flow: a user lists materials, the site generates a WhatsApp message,
and the user messages suppliers one-by-one by hand. Nothing is captured server-side.
This is the baseline the paid engine automates.

**Quote Request**:
The paid, automated job: one user's request for pricing on a set of materials,
dispatched automatically to multiple Vendors and tracked to completion. The canonical
meaning of "quote" in the automated feature; supersedes the overloaded legacy uses.
Table: `quote_requests`.

**Vendor Chat**:
One Vendor's conversation within a single Quote Request — the per-vendor thread that
carries state (stock status, follow-up flags). One Quote Request has many Vendor Chats;
each Vendor Chat belongs to exactly one Quote Request and one Vendor. Table:
`quote_vendor_chats`.

**Price Point**:
One extracted price observation — material X cost Y IDR, quoted by Vendor Z on date D,
delivery-inclusive or not. Price Points accumulate over time into the historical
material index that informs the RAB tool. Table: `historical_material_prices`.

**Reconciliation**:
The act of linking a Price Point's free-text label to a canonical `rab_materials`
entry, so it can join the global index. Done via a Material Alias (automatic) or by an
admin in the reconciliation queue (manual). Until reconciled, a Price Point is visible
to its own user but excluded from the global index.

**Material Alias**:
A saved mapping from a normalised label ("semen tiga roda") to a catalog material (+
unit). Created once during Reconciliation and reused forever after to auto-link future
Price Points. Table: `material_aliases`.

## Detailed RAB Generator

The new cost-estimation subsystem (ADR 0012), in the isolated `drab_*` namespace.
Distinct from the old `rab_*` "Detailed RAB (classic)" tool, which is kept frozen as
a comparison backup.

**Development**:
The top of the RAB document hierarchy — a project that holds one or more Buildings and
owns the shared site settings (Zone, distance band, access/terrain → Site Factor). Angin
Tinggi is one Development with three Buildings. Table: `drab_developments`.
_Avoid_: project (ambiguous with the old `rab_projects`), site.

**Building**:
One structure within a Development, carrying its own generation inputs (Style, Structure
System, Roof System, Finish Tier, and the drivers) and its own RAB. One Development has
many Buildings; one Building has many RAB versions. Table: `drab_buildings`.
_Avoid_: unit, block, villa (Building is the generic term; "Villa 1" is a Building's name).

**RAB**:
One costed document for a Building — a versioned set of Sections and Items rendered as
the four-page bill (Final Summary → Structure → Architecture → MEP). "RAB" = the document;
the tool that builds it is the Detailed RAB Generator. Table: `drab_rabs`.
_Avoid_: estimate (that is the quick calculator's output), quote.

**Work Item**:
A catalog entry for a unit of priced work (*pekerjaan*) — "Supply & install K300
concrete", per `m3` — with EN+ID names, a discipline, an optional Spec Slot, and one
Price per Zone. The reusable thing an Item references. Table: `drab_work_items`.
_Avoid_: material (a Work Item is supply **and** install, not a raw material), task.

**Item**:
One priced line *inside* a RAB — a Work Item (or a custom line) with a quantity, a
ref code (`A.1.1`), material/labour rates, and a stable `line_id`. Its quantity is a
single number or the sum of its Takeoff Rows. Table: `drab_items`.

**Takeoff Row**:
A named sub-measurement under an Item ("Back wall: 13 − 1.2 = 11.8 m²") whose values sum
to the Item's quantity. The tool owns the arithmetic — replacing the `#REF!`-prone manual
sums of the source BOQs. Table: `drab_item_takeoffs`.
_Avoid_: detail, breakdown.

**Spec Slot**:
A swappable specification position on a template line — `structural_concrete`,
`wall_finish`, `ceiling`, `floor_finish`, `paint`, `waterproofing`. The Finish Tier
resolves each Slot to a specific Work Item; the user can swap to alternatives per line.
Whether `waterproofing` even appears is Tier-driven.
_Avoid_: option, variant.

**Style / Structure System / Roof System**:
The three independent generation axes. **Style** is the template (architectural character,
item composition, and `wall_factor`): Tropical Mediterranean, Bali villa, Joglo, Premium
bamboo, Jakarta/Java city, Local-simple. **Structure System** (Full RCC / batu-kali +
masonry + light-steel / steel frame / timber) and **Roof System** (tile / RCC flat *dak* /
timber / thatch / metal) are overlays that swap items and coefficients. Authoring is
additive, not combinatorial.
_Avoid_: type, model (overloaded).

**Driver**:
A wizard-collected quantity that scales generated Items — floor area per level, footprint,
bedroom/bathroom count, or an extra's size (pool/deck/rooftop/pergola/boundary). A template
line is `driver × coefficient`. The `wall_area` driver = `floor_area × Style.wall_factor`.
_Avoid_: parameter, input.

**Zone / Site Factor**:
**Zone** is the base-price region a Work Item Price belongs to (Mataram baseline, South
Lombok, …). **Site Factor** is the computed uplift from `distance_band × access/terrain`,
lifting the material component (freight) and adding a labour mobilisation uplift, shown
transparently. Named zones are presets that pre-fill the Site Factor.
_Avoid_: location premium (use Site Factor), region (that is the listings Region).

**Indicative / Confirmed**:
A Work Item Price's confidence. **Confirmed** = a price agreed with a contractor or paid on
an invoice (the Villa BOQ rates). **Indicative** = everything else. Each price also carries
a `basis` (`real_quote`, `real_boq`, `derived`, `national_ref`, `estimate`,
`other_province_adjusted`) and a source + date. Confirmed pricing is premium-gated;
Indicative is free. Shares vocabulary with the quote engine's Price Point index, which
these rows are shaped to graduate into.
_Avoid_: verified, estimate (as the flag — `estimate` is one `basis` value).

**Issued Baseline**:
A RAB version frozen as the agreed document a future Variation diffs against. Variations
(ADR 0012, phase 2) are deferred, but the baseline + stable `line_id` plumbing ships in v1.
_Avoid_: locked, final.

## Zoning & Land Check

The zoning/land-regulation subsystem (ADR 0013), in the isolated `zoning_*`
namespace, route `#zoning`. Public name **Zoning & Land Check** (ID: *Cek Zonasi &
Lahan*). Reuses the DRAB Indicative/Confirmed trust vocabulary. Uses its **own
Leaflet map** — unrelated to the listings SVG region map (ADR 0005), which stays
scoped to property listings only.

**Plot**:
The land a user is investigating — the subject of a Zoning & Land Check query,
identified by a coordinate (pin-drop / GPS / geocoded landmark) and optionally a NIB.
A Plot is the *question*; it maps to zero, one, or many Parcels. Table:
`zoning_plots`.
_Avoid_: site (overloaded — "Site Suitability Report", DRAB "Site Factor"), lot.

**Parcel**:
One official BHUMI *bidang tanah* polygon — the registered land boundary, carrying a
NIB, area, right-type, and registration status. Read **on demand per Plot** from
BHUMI's documented WMS (GetFeatureInfo for the queried point; the WMS raster overlay
shows surrounding Parcels for border context), cached as fetched. Never bulk-ingested.
_Avoid_: certificate (the legal document), plot (the Parcel is official geometry; the
Plot is the user's question), persil (use Parcel in code/UI).

**Land-Use Class**:
The zoning classification a coordinate falls in — our normalised taxonomy over the
official *zona* codes (Zona Pariwisata, Zona Pertanian, Zona Permukiman, Zona
Hijau/Hutan Lindung, …), each with EN+ID plain-English meaning. Resolved by
point-in-polygon against our **ingested** zoning polygons. This is the canonical term
for the zoning class — **bare "Zone" stays reserved for the DRAB price-region** (see
Flagged ambiguities). Table: `zoning_landuse_classes`; polygons: `zoning_landuse_polys`.
_Avoid_: Zone (DRAB), Region/Area (listings geography), zona (use Land-Use Class).

**Buildability Status**:
The traffic-light verdict derived from the Land-Use Class: **Permitted** (e.g.
pariwisata/permukiman), **Restricted** (e.g. pertanian), or **Prohibited** (Zona
Hijau / Hutan Lindung). NOTE the colour trap: *Zona Hijau* means "green zone" but is
**Prohibited → shown RED**; the UI never relies on colour alone and always shows an
explicit label.
_Avoid_: green/amber/red as the stored value (those are presentation only).

**Plot Profile**:
The parcel/certificate facts panel for a Plot — boundary, area, right-type
(Hak Milik / HGB / Hak Pakai), registered-vs-unregistered status, Land-Value-Zone
(ZNT). Free shows the *basic* Indicative profile; the detailed/verified profile is
paid. Sourced from the Parcel(s) under the Plot.
_Avoid_: certificate details, parcel info.

**Site Suitability Report**:
The paid artifact — a detailed, human-verified (Confirmed) report for one Plot:
Buildability verdict + development metrics (KDB/KLB/KKB → footprint/floor-area/height
where RDTR exists) + Plot Profile + infrastructure/access warnings + a tailored
regulatory checklist + provenance/disclaimer. Generated by one engine with free
(Indicative, watermarked) and paid (full, Confirmed) views; delivered as a printable
HTML report. Table: `zoning_reports`.
_Avoid_: certificate, assessment.

**Verified Certificate Check**:
The owner/encumbrance-grade due-diligence layer — a formal *Pengecekan Sertipikat*
brokered through a partner notaris/PPAT, with proper legal standing/consent. The
**only** legitimate route to owner/charge information; owner names are never obtained
as scraped data (PDP Law). A premium concierge add-on, not an automated lookup.
_Avoid_: ownership lookup, owner search (those imply illegal data access).

**Indicative / Confirmed** (shared with DRAB):
Here, **Indicative** = a machine point-in-polygon / WMS read of official layers
captured at a date; **Confirmed** = human-verified against the perda / via the notaris
check. Free is Indicative; the paid report's value is the Confirmed layer. Every
result carries `source` + `date`.

## Flagged ambiguities

**"Zone" vs "Land-Use Class" — keep them apart.** **Zone** is the DRAB base-price
region (Mataram baseline, South Lombok, …). The Zoning & Land Check tool's land-use
classification (*Zona Pariwisata/Hijau*) is a **Land-Use Class**, never a "Zone".
Likewise the listings **Region/Area** geography is unrelated to either. _Resolution:_
reserve bare "Zone" for DRAB; use **Land-Use Class** in the zoning tool; use
Region/Area only for listings.

**Two maps — do not conflate.** The hand-drawn **SVG region map** (ADR 0005) is for
**property listings only** (possibly vendors later) and has no lat/lng projection. The
**Leaflet map** (ADR 0013) is the Zoning & Land Check tool's own real-coordinate map.
They share no code, data, or geometry.

**"Quote" is overloaded — three distinct meanings in this codebase:**
1. A legacy manual *contact log* — a user pressed "WhatsApp this provider" and the site
   recorded that contact (no message capture, no AI).
2. The free *Get Quotes* request builder (manual, client-side only).
3. The new *automated* quote-gathering engine (this project).

_Resolution:_ reserve **Quote Request** for the automated engine (meaning 3). Keep
**Get Quotes** as the name of the free flow (meaning 2). Refer to meaning 1 as the
**contact log**, not a "quote". Do not introduce a `vendor_id` column that points at
`providers` — the established FK is `provider_id`; "Vendor" is display language only.

## Example dialogue

> **Dev:** When a supplier replies with a price, where does it go?
> **Domain expert:** It becomes a Price Point on the historical index, and it also shows
> up in that Vendor Chat's thread on the user's dashboard.
> **Dev:** "That supplier" — is that a separate thing from a Vendor?
> **Domain expert:** No. A Supplier *is* a Vendor — just one in the materials group. In
> the database it's a Provider either way.
> **Dev:** And one Quote Request can hit several of them?
> **Domain expert:** Right — one Quote Request, many Vendor Chats, one per Vendor. The
> items live on the Request; every Vendor gets asked about the same list.
