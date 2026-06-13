# 0010 — Three-tier location: Region → Area → Place

- **Status:** accepted
- **Date:** 2026-06-13
- **Relates to:** ADR 0005 (custom SVG region map), 0009 (Extractor)

## Context

The taxonomy was two levels — Region → Area — with everything finer dumped into
free-text `location_detail` (unsearchable) or, worse, mis-mapped to the nearest
Area (Mertak and Awang were both being tagged "Kuta"). For a Lombok-only product,
location granularity is the competitive edge: buyers treat Kuta, Mawun, Are
Guling, Selong Belanak, Mawi, Awang and Ekas as **separate destinations**, and a
general portal never makes that distinction. But the interactive map can't carry
a marker for every hamlet without becoming unreadable.

## Decision

Introduce a third, searchable tier: **Place**.

- **Region → Area → Place.** An **Area** is a distinct, market-meaningful
  destination that earns a **map marker** and the primary filter — kept
  deliberately fine-grained (Awang and Mawun are **promoted to Areas**). A
  **Place** is a finer locality that **rolls up** to a parent Area: searchable
  and displayed on its own merit, but **not** its own map marker.
- **Roll-up via a stored parent.** A Place listing carries `place_key` *and* its
  parent `area_key`. So **Area/Region filters and the map are unchanged** (they
  match `area_key`), yet the listing **displays** as the Place ("Mertak", never
  "Kuta") and a `place` filter narrows to it exactly. Area filter = Area + all
  its Places; Place filter = that Place only.
- **One alias table.** `area_aliases` gains a nullable `place_key`; a single
  alias row carries both keys, and **many aliases may point at one locality** —
  that is how synonyms resolve ("Awang"/"Teluk Awang", "Pink Beach"/"Tangsi",
  "Klancing"/"Lancing"). A Place alias implies its Area. The Ingest Console's
  existing alias screen edits it; admin-added aliases also feed the Extractor.
- **Curated, not exhaustive.** A Place exists only if it's a recognised locality;
  the `place` frequency the Extractor (ADR 0009) reports across the corpus —
  not anyone's memory — drives which to add or promote next.
- **Map drill-down later.** v1 ships the data + filter + labels; Area→Place dots
  on the SVG map are a follow-up once Place data has populated.

## Consequences

- New `places` table (`place_key`, `label`, `area_key`); `area_aliases.place_key`;
  `listings.place_key` (joins `area_key` in the Locked Field set).
- New Areas `awang`, `mawun` (South Lombok). Curated Place seed of ~45 localities.
- `api/index.php` listings gain `place_key`/`place_label` and a `?place=` filter
  (guarded by `_col_exists` so the public site survives until the migration runs).
- A general portal can't replicate this without local knowledge — which is the
  point.
