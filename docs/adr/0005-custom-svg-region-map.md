# Custom hand-drawn SVG region map instead of a map library

## Status

accepted

## Context

The Property & Land Explorer needs an interactive map that filters listings by location. Listings carry no coordinates — only an `area_key` (Kuta, Selong Belanak, …), each belonging to one of six market Regions (South, West, Central, East, North Lombok, Gili Islands). Critically, these Regions are *market* groupings, not administrative ones: Kuta sits in Kabupaten Lombok Tengah but belongs to the South Lombok Region, because that is how buyers think. There is no official "South Lombok" boundary anywhere.

## Decision

Build the map as a custom inline SVG: a traced Lombok coastline silhouette with the six market Regions drawn as hand-crafted polygons in the site's cream/sand topographic style. Region click animates the SVG viewBox to zoom in and reveals Area markers; clicks drive the same filter state as the dropdowns (two-way sync). Live listing counts per Region/Area come from a small aggregate API endpoint; Regions with zero listings render desaturated and non-clickable. No map library, no tiles, no API keys.

## Why this and not the alternatives

Google Maps and Leaflet+GeoJSON were rejected. Both would require hand-drawing the region polygons anyway (real administrative GeoJSON draws boundaries that contradict the market Regions — Kuta would land in "Central Lombok"), so the only thing a library adds is tile weight (~300–800KB/view), API billing (Google), and a generic look that fights the $20k-studio brand. With no listing coordinates there are no pins to place, so a real-geography canvas buys nothing. Inline SVG costs ~tens of KB, is fully brandable (serif labels, thin rules, premium cubic-bezier zoom), and works identically on mobile (prominent collapsible panel above the listings) and desktop (45/55 split view).

## Consequences

The region shapes are an owned design asset: adding or re-splitting a Region means editing the SVG paths, not a data file. Per-listing map pins are impossible until listings gain coordinates — if that data ever arrives, this decision should be revisited rather than bolted onto. The "ARRIVING SOON" static placeholder in `renderListings` is replaced by this component.
