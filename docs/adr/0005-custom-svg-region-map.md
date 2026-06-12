# Custom hand-drawn SVG region map instead of a map library

## Status

accepted

## Context

The Property & Land Explorer needs an interactive map that filters listings by location. Listings carry no coordinates — only an `area_key` (Kuta, Selong Belanak, …), each belonging to one of six market Regions (South, West, Central, East, North Lombok, Gili Islands). Critically, these Regions are *market* groupings, not administrative ones: Kuta sits in Kabupaten Lombok Tengah but belongs to the South Lombok Region, because that is how buyers think. There is no official "South Lombok" boundary anywhere.

## Decision

Build the map as a custom inline SVG over real geometry: the coastline is the actual OSM polygon for Lombok (simplified Douglas-Peucker, projected to an 880×640 viewBox by `docs/tools/build_lombok_map.py`), the six market Regions are that real coast split along chosen interior boundaries, and Area markers sit at their true coordinates. Underneath the interactive layer sits a single 22KB WebP terrain illustration (`images/lombok-terrain.webp`) — AI-painted shaded relief generated from the exact silhouette, so it aligns with the SVG geometry; in dark mode the image is hidden and regions fall back to solid fills. Region click animates the SVG viewBox to zoom in and reveals Area markers; clicks drive the same filter state as the dropdowns (two-way sync). Live listing counts per Region/Area come from a small aggregate API endpoint and honour the active non-location filters; Regions with zero listings render desaturated and non-clickable. No map library, no tiles, no API keys.

## Why this and not the alternatives

Google Maps and Leaflet+GeoJSON were rejected. Both would require hand-drawing the region polygons anyway (real administrative GeoJSON draws boundaries that contradict the market Regions — Kuta would land in "Central Lombok"), so the only thing a library adds is tile weight (~300–800KB/view), API billing (Google), and a generic look that fights the $20k-studio brand. With no listing coordinates there are no pins to place, so a real-geography canvas buys nothing. Inline SVG costs ~tens of KB, is fully brandable (serif labels, thin rules, premium cubic-bezier zoom), and works identically on mobile (prominent collapsible panel above the listings) and desktop (45/55 split view).

## Consequences

The region shapes are generated, not hand-edited: adding or re-splitting a Region means changing the junction/boundary coordinates in `docs/tools/build_lombok_map.py` and pasting the regenerated `LOMBOK_MAP` constant into `app.js`. The terrain WebP must be regenerated (silhouette → image-edit) if the projection or viewBox ever changes, or it will misalign with the overlay. Per-listing map pins are impossible until listings gain coordinates — if that data ever arrives, this decision should be revisited rather than bolted onto. The "ARRIVING SOON" static placeholder in `renderListings` is replaced by this component.
