# 0009 — LLM listing Extractor (local Ollama, inline in the Worker)

- **Status:** accepted
- **Date:** 2026-06-13
- **Supersedes the brittle parts of:** the per-site CSS/JSON-LD extractors (ADR 0007)

## Context

The deterministic per-site extractors kept producing wrong data the rules
couldn't catch: breadcrumb titles ("Tanah Dijual di Lombok Tengah"), per-are vs
total price confusion, prices buried in prose ("Hanya 1,9 M"), and — worst —
wrong locations, because keyword matching can't tell "the property is in Awang"
from "20 minutes to Kuta". A local Ollama (llama3.1:8b) read a real Awang listing
correctly in ~8s, ignoring the Kuta landmark. The home Worker already runs on the
same PC as Ollama.

## Decision

Add an **Extractor**: an inline Ollama call in the Listing Worker that turns raw
page signals into structured JSON. Key boundaries:

1. **Extractor-only.** The LLM *finds* facts; the server's Canonicalisation
   (sanity bands, area/place resolution, locked fields, surprise queue, revision
   log) still *decides* what is stored. An invented number must still pass the
   same immune system. (Rejected: LLM as decision-maker.)
2. **Inline, not a separate service.** The Worker has the page in hand; an ~8s
   call disappears inside the existing 30–60s politeness delay. One process, one
   log, deterministic fallback when Ollama is down. (Rejected: a poll/queue
   agent like the quote engine — its queue exists because WhatsApp is async; this
   isn't.)
3. **Prose verbatim, numbers normalised.** Descriptions/titles are copied, never
   summarised or translated (downstream tag/price/area parsers need source
   text). Numbers come back as one clean integer ("9.67 are" → 967).
4. **LLM primary, structured data as hint + fallback.** The per-site extractors
   are demoted to *signal collectors* (JSON-LD, DOM price text, visible text)
   fed into the prompt; the LLM reconciles. Ollama down → deterministic mapping
   of the same signals, marked `extraction_method = 'fallback'`.
5. **Content-hash gating.** The Worker stores `source_hash` of the seller's text;
   on re-check, unchanged hash → **liveness only, no LLM, no revision** (keeps
   re-check cheap and the Modified-Listings panel honest). LLM runs on the few %
   that actually changed, plus first extraction.
6. **Deterministic gates, confidence as triage only.** 8B self-confidence is
   poorly calibrated, so it never decides store-vs-review: price/size gated by
   the per-m² + 5× bands; area/place gated by whether they *resolve* to the
   taxonomy (else review). `extraction_confidence` is stored for triage/sort.
7. **Two modes.** *Mode A* (`--reextract`): re-extract location/tags/certificate
   from already-stored descriptions, no crawl — headless, ~1h, fixes the corpus
   and is the prompt-iteration tool. *Mode B*: full crawl-time extraction in the
   nightly run.

## Consequences

- New dependency: a local LLM on the home PC; the server never calls it.
- The Worker pulls the geography (areas/places/aliases) from the server each run
  and injects it into the prompt, so admin-added aliases teach the model too.
- Schema: `listings.source_hash`, `extraction_method`, `extraction_confidence`.
- Per-site extractors stay as the signal-collector + offline fallback, not dead
  code. Full LLM cutover of price/size (currently still deterministic in Mode B)
  can follow once trust is established.
- Endpoints added to `api/listing_ingest.php`: `geography`, `serve_text`
  (Mode A source), `post_location` (Mode A sink); `pull_work` now returns
  `source_hash`.
