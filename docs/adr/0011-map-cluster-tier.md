# 0011 — Map Cluster tier: a presentation-only zoom level between Region and Area

- **Status:** accepted
- **Date:** 2026-06-13
- **Relates to:** ADR 0005 (custom SVG region map), 0010 (three-tier location)

## Context

ADR 0010 keeps Areas deliberately fine-grained — South Lombok alone has eight
(`selong_belanak, mawi, mawun, kuta, are_guling, gerupuk, awang, ekas`), all strung
along a thin south-coast band. On the map's Region → Area zoom, tapping "South
Lombok" drops the user into that band where eight markers overlap inside ~45px of
vertical space: effectively un-tappable on mobile, which is most of the traffic. The
density is intrinsic to the south coast and will worsen as more coastal Areas earn
markers. Region → Area is too coarse a jump for the busiest region.

## Decision

Insert a **Cluster** level into the map only: **Region → Cluster → Area**.

- **Presentation-only, no data tier.** A Cluster is a named grouping of neighbouring
  Areas defined entirely in the map's `LOMBOK_MAP` config (label + zoom box + member
  `area_key` list). There is **no `clusters` table, no listing column, no ingestion
  change** — a listing belongs to a Cluster solely because its Area is a member. This
  deliberately deviates from the strict three-tier data model: the deviation lives in
  the view, never in the schema.
- **Distinct names.** Cluster names never reuse an Area name — the "Kuta–Mandalika"
  Cluster is not the "Kuta" Area — so the hierarchy reads unambiguously.
- **South Lombok only; mechanism generic.** Any region *may* declare `clusters`;
  only South Lombok does for now. A region with no clusters keeps today's exact
  Region → Area behaviour. South's three: **Selong Belanak Bays** (`selong_belanak,
  mawi`), **Kuta–Mandalika** (`mawun, kuta, are_guling, gerupuk` [+ legacy
  `tanjung_aan`]), **South East · Awang–Ekas** (`awang, ekas`) — Awang–Ekas stands
  apart because Ekas is ~1 hour from Kuta.
- **Tapping a Cluster zooms *and* filters** to the union of its member Areas, via a
  comma list on the existing `?area=` param (`area=kuta,mawun,are_guling,gerupuk`)
  resolved server-side to a SQL `IN` list. The backend stays ignorant of Clusters;
  membership never leaves the map config. `listing_counts` inherits the same builder,
  so Cluster counts derive client-side by summing member-Area counts (no new count
  endpoint shape for clusters).
- **Back = up one level, broadening the filter.** A context-aware back control walks
  Area → Cluster → Region → All Lombok, widening the location filter one step each tap.
- **Forward-compatible with the 0010 migration.** Built against the target model
  before the migration runs live: the `awang` marker is added; the `tanjung_aan`
  marker is dropped (it becomes a Place) but `tanjung_aan` stays in the Kuta–Mandalika
  `IN` list so today's `area_key='tanjung_aan'` listings still match; Place chips
  (with counts) render only once the `places` table exists, and silently don't before.

## Why this and not the alternatives

A real `sub_regions` data tier was rejected: it would re-open ADR 0010's settled
schema, add a migration + ingestion + Extractor burden, and bind buyers' fuzzy
"which beach cluster" mental model into stored data — all to serve a map-rendering
concern. Doing nothing but improving the single Region → Area zoom (bigger markers,
progressive reveal) was rejected because it doesn't give the "tap a stretch of coast,
zoom into it" interaction that actually fixes mobile tappability. Keeping Clusters as
view-only config is reversible per-region by deleting config, costs no schema, and
keeps the backend a dumb `area IN (…)` matcher.

## Consequences

- `?area=` becomes multi-valued (comma → `IN`). One change in `build_listing_filters`;
  `handle_listing_counts` inherits it. Shareable cluster URLs encode the member list,
  so renaming/re-membering a Cluster can stale an old link (degrades to a valid
  multi-area filter, never an error).
- The map's South Lombok marker set must track the curated Area set (add `awang`,
  drop `tanjung_aan` as a marker) — markers stay hand-placed (ADR 0005), so a new
  Area still needs a coordinate added before it appears.
- `listing_counts` gains a `places` block (`GROUP BY place_key`) so Place chips can
  show counts; absent/guarded until the migration runs.
- A future reader of ADR 0010 ("flat three tiers") will see a fourth layer on the
  map — this ADR is why: it is a view affordance, not a fourth taxonomy level.
