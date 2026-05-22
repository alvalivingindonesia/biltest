# Global command palette as the primary search UX

## Status

accepted

## Context

The site previously had three separate search affordances: a hero search bar with three intent-pill segments (Property / Builders / Materials) that routed to different entity-specific pages, a per-page search input inside the mobile menu, and a `/search` page that was reachable only by typing `#search` directly into the URL. Search was fragmented, the intent pills disambiguated a backend routing problem by exposing it as UI noise, and inner pages had no global way to find anything across entity types.

## Decision

Replace the fragmented search affordances with a single global command palette, always rendered as a centered overlay modal (full-screen on mobile), summoned from one of three trigger surfaces: a `<button>` styled as the hero search bar on the home page, a 200px nav pill present on every page at viewport ≥1024px, and the mobile menu's existing search input on smaller viewports. The palette is also globally reachable via ⌘K / Ctrl+K and `/` (the latter guarded to fire only when no editable element has focus). The palette runs against an extended `handle_search` backend covering six entity types (Providers, Developers, Projects, Listings, Agents, Guides) and renders results sectioned by type (top-3 per section on desktop, top-2 on mobile) with per-section "View all N →" links that deep-link into the surviving `/search?q=…&type=…` page.

## Why this and not the alternatives

We considered a minimal "patch the existing /search page" refresh and a middle path that would have kept the home hero as a real input feeding a refreshed full-page results view. Both were rejected because they preserved the inconsistency between home-page search and inner-page search, and because they left the intent pills (or some equivalent disambiguation UI) in place. The command palette pattern was chosen specifically to make search one component used three ways, give power users a discoverable global shortcut, and provide mobile and desktop with consistent UX. We also considered an anchored-dropdown variant (Algolia DocSearch style) where the palette renders attached to whatever input was triggered; rejected in favour of always-overlay because the always-overlay path is one code path, one visual experience, and one mental model regardless of trigger.

## Consequences

The hero search input is no longer a real `<input>` — it is a `<button>` styled to look like one. Users cannot type "into" the visible hero bar; clicking it transfers focus to the palette's input. Page-specific filtering (e.g. on `/directory`) must henceforth be done via that page's own filter bar, not by typing into a search box. The `handle_search` endpoint now serves two callers (palette and `/search` page) and uses a `palette=1` query param to cap each section's SELECT to 6 rows for the high-volume palette path. Adding new searchable entity types in the future requires touching three call sites: the backend UNION query, the frontend `renderSearchCard` typeMap, and the section order in the palette renderer.
