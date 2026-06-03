# Build in Lombok — Home Worker Agent

The always-on process on the home box. It is the **only** moving part that talks to
both the local services (Evolution, Ollama) and the public website. It makes **only
outbound** HTTPS calls to HostPapa — nothing on the internet reaches into this box
(see `docs/adr/0002` in the main repo).

```
Evolution (WhatsApp, :8090) ──loopback webhook──> agent ──┐
Ollama (whatsapp-agent, :11434) <──parse calls── agent    ├──HTTPS──> HostPapa /api/quote_worker.php
                                  ──sends──> Evolution ────┘            (single authenticated door)
```

## Prerequisites

- **Node 18+** (uses the built-in `fetch` and `http` — there are no npm dependencies).
- Evolution API running locally with a scanned, live WhatsApp device.
- Ollama running locally with the `whatsapp-agent` model (built per the revised Modelfile
  that emits a `pricing_extracted.line_items[]` array — see main repo Q6).
- `WORKER_API_KEY` already added to the server config (`/home/rovin629/config/biltest_config.php`).

## Setup

1. `copy config.sample.json config.json` (Windows) and fill it in:
   - `worker_key` — **the same** 64-char hex you put in `WORKER_API_KEY` on the server.
   - `evolution_apikey`, `evolution_instance` — from your Evolution install.
   - Adjust pacing/cap if needed (defaults: 8–30 s between sends, 200/day — Q11).
2. **Point Evolution's webhook at the agent.** Configure the instance webhook to:
   - URL: `http://localhost:8099/evolution` (match `webhook_port`)
   - Events: at least `MESSAGES_UPSERT`
   - (Evolution Manager UI, or the `/webhook/set/{instance}` API.)
3. Start it: `node index.js` (or `npm start`).

> **Do not commit `config.json`** — it holds secrets. Only `config.sample.json` is tracked.

## Keep it running (survives reboots)

Use a process manager so it auto-starts. Simplest on Windows is
[PM2](https://pm2.keymetrics.io/):

```
npm i -g pm2
pm2 start index.js --name quote-agent
pm2 save
pm2 startup        # follow the printed instructions to enable on boot
```

Do the same for `cloudflared`/Evolution/Ollama if not already services, so the whole
stack comes back after a power cut.

## Verify the setup (matches the main repo checklist)

1. **Auth/reachability:** `node -e "fetch(...)"` or just start the agent — `claim_outbound`
   returning `{"ok":true,"messages":[]}` means the worker key + custom header work.
   A 401 means the `X-Worker-Key` header isn't reaching PHP (fix `.htaccess`).
2. **Model output:** push 3 real messy Bahasa messages through `whatsapp-agent` and confirm
   no `<think>` leakage and schema-valid JSON (the agent strips `<think>` defensively, but
   `think:false` + structured outputs should mean it never appears).
3. **Send IDs:** confirm Evolution's send response includes `key.id`, and inbound replies
   include `contextInfo.stanzaId` — both power reply-quote routing (Q9).

## How it behaves when things go wrong

- **Box offline / asleep:** outbound rows stay `queued`, inbound webhooks queue at HostPapa
  as `unparsed`. Everything drains when the agent restarts. Nothing is lost (ADR 0002).
- **Agent crashes mid-send:** the stranded `sending` row is reclaimed by the server after
  5 minutes and re-sent.
- **Ollama returns junk:** the agent reports a parse failure; the message is flagged for
  admin and still shows as raw text in the user's thread (Q12).
