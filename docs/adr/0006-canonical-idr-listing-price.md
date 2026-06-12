# Canonical IDR price for filtering; display currency is presentation-only

## Status

accepted

## Context

Listings arrive with mixed currencies: of 532 live listings, 409 have both USD and IDR prices, 19 are USD-only, 3 IDR-only, 101 have no price at all. The listings table carries four price columns (`price_idr`, `price_usd`, `price_eur`, `price_aud`), mostly NULL outside USD/IDR. The old price filter compared only `price_idr`, silently excluding every USD-only listing. Visitors need to browse in IDR, USD, EUR or AUD regardless of what currency a listing was posted in.

## Decision

`price_idr` is the canonical price. A one-time backfill computes it (via the `currency_rates` table) for every priced listing that lacks it, and the API fills it automatically on create/update from whatever currency is supplied. All price filtering and price sorting run against `price_idr` only. The visitor's Display Currency is purely presentational: if the listing stores an exact value in that currency, show it; otherwise convert from IDR client-side using `currency_rates` and prefix with ≈. Price-filter presets re-denominate into round numbers per currency. Listings with no price ("Price on Request") are excluded only while a price filter is active. `currency_rates` is refreshed daily by a cPanel cron hitting a free FX API, keeping last-known rates on failure. Display Currency is scoped to property listings and developments — materials, quotes and RAB prices are always IDR.

## Why this and not the alternatives

Backfilling all four currency columns was rejected: stored conversions go stale as rates move and four columns must be kept in sync on every edit, for no user benefit over on-the-fly conversion. Query-time SQL conversion (COALESCE across columns × rates) was rejected as index-hostile and fragile on shared-hosting MySQL. A single always-fresh canonical column plus client-side display conversion keeps queries simple and makes "what the filter matches" independent of "what the visitor sees".

## Consequences

A converted display price drifts from the stored canonical IDR as rates move between cron runs — the ≈ prefix is the honesty marker and must not be dropped. Sorting by price uses `price_idr`, so the previous `price_usd` sort key is retired. Any new listing-ingest path (scrapes, admin console, agent submissions) must go through the price-normalisation step or USD-only listings will start slipping through filters again.
