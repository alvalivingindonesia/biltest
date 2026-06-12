# Cross-portal agent identity and earned reputation

## Status

accepted

## Context

Listings ingested by the home-worker pipeline (ADR 0007) carry an agent. The portals
expose three very different "sellers": named agencies/agents, private individuals, and
the portal's own name as a seller. The existing importer collapses the messy cases into
shared placeholder `agents` rows — `"Private Seller (Lamudi)"`, `"Lamudi Agent"` — and
keys every agent on `(source_site, source_agent_id)`, so the same real agency posting on
Lamudi and Rumah123 becomes two unrelated rows.

Two goals expose the cost of this. First, "open by agent and check their listings"
should show an agent's whole catalogue, but per-portal rows fragment it. Second, the
business wants an *earned* trust signal — more listings and longer tenure means a more
trustworthy agent — but listing volume and tenure are split across the duplicate rows,
and the only trust fields today (`is_verified`, `is_trusted`) are manual curation
levers (ADR 0001), not earned. Separately, the placeholder rows pollute the public Agent
directory: "Lamudi" is not an agent.

## Decision

Model agents as a canonical entity with per-portal sources, classify non-agents
explicitly, and compute reputation separately from the manual flags.

- **Canonical Agent + Agent Sources.** A canonical Agent owns one or more Agent Sources,
  each a `(source_site, source_agent_id, source_profile_url)` tuple. Ingestion matches a
  scraped agent to an existing canonical Agent by **normalised phone/WhatsApp number**,
  with name as a tiebreak. High-confidence matches merge; ambiguous ones go to the
  review queue (ADR 0007) rather than auto-merging. "Browse by agent" and Reputation
  aggregate across all of an Agent's Sources.
- **Classification.** A *named* agency/agent is a browsable Agent. A *Private Seller*
  (individual, no agency) is bucketed into one shared, hidden per-site Agent: the listing
  keeps its `agent_id`, but the row is excluded from the directory, search and
  Reputation. A *Platform Placeholder* (the portal name as seller — "Lamudi") is never
  stored as an Agent; those listings get `agent_id = NULL`.
- **Reputation.** A computed score + tier (e.g. New / Established / Top), recomputed
  nightly by the pipeline from listing volume + tenure (time since first seen) + current
  active count. Tenure and track record count distinct listings *ever seen* (expired and
  sold included — preserved by the soft-delete rule), so reputation does not evaporate
  when listings expire; active count is reported separately. Reputation is independent of
  `is_verified` and `is_trusted`, which remain manual levers an editor can apply to
  anyone.

## Why this and not the alternatives

Keeping one agent row per `(source_site, source_agent_id)` was rejected: it fragments
both the agent's visible catalogue and the volume/tenure that feed reputation, making an
established multi-portal agent look like several minor ones. Merging only on an exact
name+phone match was rejected as too brittle — agents vary their display/agency name
across portals — so phone is the primary key with name as tiebreak and a review queue
for ambiguity. Auto-flipping the existing `is_trusted` flag when thresholds are met was
rejected because it overloads an editorial curation control and destroys the
manual-vs-earned distinction. Counting only active listings toward reputation was
rejected because it would erase an agent's track record every time stock expires or
sells. Dropping placeholder rows entirely (no private-seller bucket) was considered but
the shared hidden bucket keeps private-seller listings attributable and countable
without surfacing them as browsable agents.

## Consequences

- New schema: a canonical `agents` row plus an `agent_sources` table (the current
  `source_site`/`source_agent_id` columns move there), an agent classification/kind, and
  stored reputation score + tier + first-seen.
- A one-time migration must split existing agent rows into canonical Agents + Sources and
  merge phone-duplicates; existing placeholder rows must be reclassified (private-seller
  hidden, platform placeholders detached to `agent_id = NULL`).
- Phone-based merging will occasionally be wrong (shared agency lines, mistyped numbers);
  the review queue and an admin merge/split capability are the safety valve.
- Reputation is poll-fresh (nightly), not real-time, and depends on retaining expired/sold
  listings — reinforcing the soft-delete rule; a future hard-delete would silently
  corrupt tenure.
- Agent directory queries, search (`_search_agents`) and the agent detail endpoint must
  filter out hidden private-seller and excluded rows, and aggregate listings/reputation
  across Sources rather than per row.
