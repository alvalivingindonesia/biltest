# Home-pull worker for the quote engine (no tunnels)

## Status

accepted

## Context

The "Find Me a Quote" engine spans two machines: a residential Windows box running
the WhatsApp gateway (Evolution API, `localhost:8090`, PostgreSQL) and a local LLM
(Ollama `whatsapp-agent` on `qwen3:8b`, `localhost:11434`), and the public website on
HostPapa shared hosting (MySQL). The website has no SSH/root and caps PHP execution
time; the home box sits behind a residential NAT with no stable inbound address. The
initial design exposed both home services to the internet via ephemeral Cloudflare
quick tunnels (`*.trycloudflare.com`) and had HostPapa synchronously call them (push),
with a "beacon" to re-register the rotating tunnel URLs after every restart.

## Decision

Invert all control flow to be home-originated and asynchronous. The home box runs a
small always-on **worker agent** that makes only outbound HTTPS calls to a single
authenticated worker API on HostPapa. It (a) pulls `queued` outbound messages and
sends them via local Evolution, (b) ingests inbound vendor replies (Evolution →
loopback webhook → agent → HostPapa), and (c) pulls `unparsed` messages, runs them
through local Ollama, and posts the structured result back. HostPapa's MySQL is the
single durable source of truth and **never initiates contact** with the home box.
There are no Cloudflare tunnels and no public exposure of Evolution or Ollama.

## Why this and not the alternatives

Synchronous push (HostPapa → tunnels → GPU) was rejected: PHP execution limits and
Evolution webhook retries turn slow GPU inference into dropped or duplicated work, and
ephemeral tunnel URLs cause silent outages on every home-box reboot. A cron-driven
pull on HostPapa was rejected because the cPanel cron floor (~1 minute) defeats the
near-real-time dashboard and still requires exposing Ollama. A public Evolution
webhook was rejected in favour of agent-mediated ingestion so the website exposes
exactly one hardened, authenticated surface instead of two.

## Consequences

- A new component must be built and kept running on the home box: the worker agent.
- "Real-time" is poll-bounded — outbound sends and parse results land on the next poll
  cycle (a few seconds), not instantly.
- Durability is automatic: if the home box is offline, outbound rows stay `queued` and
  inbound stays `unparsed`; both drain on recovery. Nothing is lost.
- The schema must model explicit job states (e.g. queued/sent, unparsed/parsed/failed)
  to support the pull protocol.
- Migrating later to stable named tunnels (for lower latency or a push model) would be
  a reversal of this ADR. The decision to start with ephemeral tunnels + a beacon was
  superseded before implementation by going fully tunnel-free.
