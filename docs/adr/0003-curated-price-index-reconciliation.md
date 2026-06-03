# Curated reconciliation for the material price index

## Status

accepted

## Context

The quote engine extracts Price Points (a material, a price, a vendor, a date) from
messy WhatsApp replies. These feed a historical material price index that informs RAB
cost estimates — a paid output. The vendor's and user's free-text labels never match the
curated `rab_materials` catalog, so each Price Point must be linked to a canonical
material before it can safely inform an estimate. The stakes are asymmetric: a missing
link is invisible, but a wrong link silently corrupts a price users pay for.

## Decision

Do not auto-match Price Points into the index. Store every Price Point with a nullable
`rab_material_id`. Resolve the link two ways: (1) a `material_aliases` table maps
normalised label text → catalog material (+ unit), auto-linking on ingest when an alias
already exists; (2) unmatched Price Points surface in an admin reconciliation queue
where a human maps text → catalog, which creates a reusable alias and back-fills other
rows with the same text. The user-facing dashboard shows all of a user's Price Points
(including unlinked, raw values); the global RAB index draws only from linked (curated)
Price Points. The index is additive history and never auto-overwrites
`rab_materials.default_rate`.

## Why this and not the alternatives

AI-based auto-match was rejected: the catalog is too large to fit reliably in the
model's context window, and a confident-but-wrong pick is the exact failure mode we
cannot afford. Code-based fuzzy matching (LIKE / Levenshtein / FULLTEXT) was rejected
because Indonesian-vs-English labels tank precision. Curated reconciliation trades
coverage and automation for precision — the correct trade for data that drives a paid
estimate. The alias table keeps the human cost bounded: each distinct label is mapped
once and never again.

## Consequences

- An admin reconciliation surface must exist in the console before the index is trusted.
- New Price Points may sit unlinked (and outside the global index) until reconciled;
  this is acceptable and invisible to the requesting user, who still sees their raw data.
- The reconciliation queue doubles as the first human-in-the-loop surface for the future
  procurement vision.
