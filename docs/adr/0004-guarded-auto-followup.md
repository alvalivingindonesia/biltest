# Guarded auto-follow-up, not free-text auto-reply

## Status

accepted

## Context

The `whatsapp-agent` model emits a `response` field — a polite, LLM-drafted WhatsApp
reply that asks the vendor for any missing price/delivery/timeline detail — plus
`follow_up_required` and `admin_intervention_required` flags. The obvious reading is
that the system sends this `response` automatically. But these messages go out over a
single, unofficial (Baileys) WhatsApp number to real Lombok vendors. Outbound mistakes
are irreversible (the vendor already read it), and high-velocity or odd automated
messaging is the classic signature that gets a number reported and banned — which would
kill the feature for every paying user at once.

## Decision

The system never auto-sends the raw LLM `response`. Instead: when a parse shows a
specific structured field is missing (no price, no delivery answer, no timeline), the
agent auto-sends a **vetted, language-matched template** filled from known variables —
templates cannot hallucinate. Auto-follow-ups are **capped per Vendor Chat**
(`follow_up_count`, max ~2). Anything outside "politely ask for a missing field" —
`admin_intervention_required`, disputes, confusing replies, repeated non-answers — is
**held for a human**, never auto-sent. The LLM `response` is still stored on the message
row (`ai_suggested_response`) as a pre-filled draft for whoever takes over.

## Why this and not the alternatives

Full free-text auto-reply was rejected: an 8B model occasionally produces odd or
off-tone Bahasa, and an unsupervised nagging loop is a direct ban vector — the blast
radius (all users, one banned number) is unacceptable for the 10% of cases that go
wrong. Draft-and-hold (nothing auto-sends) was rejected as too conservative: chasing a
missing price is the safe, high-frequency 90% case and is exactly what "automated quote
gathering" promises. Guarded auto-follow-up keeps the automation where it is safe and
routes the dangerous cases to a human.

## Consequences

- A small library of follow-up templates (per missing-field, per language) must be
  maintained.
- The schema carries `sender_kind` (`user`/`agent_auto`/`admin`/`vendor`) and
  `follow_up_count` to enforce the cap and keep an audit trail.
- Fully autonomous free-text messaging remains a *future* step, gated behind moving to
  the official WhatsApp Business API (lower ban risk) — and would supersede this ADR.
