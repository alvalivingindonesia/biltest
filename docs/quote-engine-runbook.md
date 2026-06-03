# Quote Engine — Deploy & Operations Runbook

Everything needed to get the "Find Me a Quote" engine live and keep it running.
Background and rationale live in [CONTEXT.md](../CONTEXT.md) and
[ADR 0002–0004](adr/). Architecture in one line: the home box runs WhatsApp
(Evolution), the AI (Ollama), and a worker agent that makes **only outbound** HTTPS
calls to HostPapa — nothing reaches into the home box (ADR 0002).

---

## 1. Database (HostPapa, phpMyAdmin)

Run both, in order, against the `biltest` database. Both are idempotent.

1. `migrations/2026_06_03_create_quote_engine_tables.sql`
2. `migrations/2026_06_03_quote_messages_add_updated_at.sql`

Check the `feature_access` table now has `quote_engine` (Basic+Premium) and
`quote_price_history` (Premium), and that `plan_limits` has rows for basic/premium.

> Confirm the DB is MySQL 5.7+ / MariaDB 10.2+ (`SELECT VERSION();`). If older, change
> `ai_payload JSON` to `LONGTEXT` in the first migration before running it.

## 2. Server config (one secret you add by hand)

`/config/` is outside the repo and never committed. Add to
`/home/rovin629/config/biltest_config.php`:

```php
define('WORKER_API_KEY', '<64-char hex>');
```

Generate one locally and reuse the **same** value in the agent's `config.json`:

```powershell
-join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Max 256) })
```

## 3. Deploy web files

`git push origin main` → cPanel's `.cpanel.yml` copies the repo to the web root
automatically. Files that matter on the server: `api/quotes.php`,
`api/quote_worker.php`, `admin/quotes.php`, `app.js`, `style.css`.

## 4. Rebuild the AI model (home box)

The model must emit the structured `pricing_extracted.line_items[]` array the engine
relies on (Q6). From the `agent/` folder:

```bash
ollama create whatsapp-agent -f whatsapp-agent.Modelfile
```

Quick sanity test (no `<think>`, valid JSON with `line_items`):

```bash
curl http://localhost:11434/api/chat -d "{\"model\":\"whatsapp-agent\",\"think\":false,\"stream\":false,\"messages\":[{\"role\":\"user\",\"content\":\"VENDOR MESSAGE:\nsemen tiga roda 75rb/sak, pasir 200rb/m3, ongkir 150rb\n\nBUYER ITEMS:\n  - request_item_id=1: cement\n  - request_item_id=2: sand\n\ndelivery_requested=yes\"}]}"
```

You should see `line_items` with `request_item_id` 1 (75000/sak) and 2 (200000/m3),
`follow_up_required:false`, and a `delivery_charge` of 150000.

## 5. Run the worker agent (home box)

1. `copy config.sample.json config.json` and fill it in (same `worker_key` as step 2).
2. Point Evolution's webhook at the agent: instance webhook **URL**
   `http://localhost:8099/evolution`, **events** include `MESSAGES_UPSERT`
   (Evolution Manager UI, or `POST /webhook/set/{instance}`).
3. Start it, ideally under a process manager so it survives reboots:
   ```bash
   npm i -g pm2
   pm2 start index.js --name quote-agent
   pm2 save && pm2 startup
   ```
   Do the same for Evolution and Ollama if they aren't already auto-starting.

---

## Verify checklist

### A. Worker header passthrough (the important one)

Confirm HostPapa passes the custom `X-Worker-Key` header through to PHP. From any
machine (Windows `curl.exe`):

```bash
curl -s -X POST "https://biltest.roving-i.com.au/api/quote_worker.php?action=ping" -H "X-Worker-Key: YOUR_KEY" -H "Content-Type: application/json" -d "{}"
```

- `{"ok":true,"pong":true}` → **pass**. Header + key work.
- `{"error":"Unauthorized"}` → the key is wrong **or** the header was stripped.
- `{"error":"Worker API key not configured on server."}` → step 2 not done.

**If you get Unauthorized and the key is definitely right**, the header is being
stripped. Disambiguate with a temporary probe — create `api/_probe.php`:

```php
<?php header('Content-Type: application/json');
echo json_encode(['headers'=>array_keys(getallheaders()), 'x'=>$_SERVER['HTTP_X_WORKER_KEY'] ?? null]);
```

Hit it with the header; if `x` is `null` and `X-Worker-Key` isn't in `headers`, it's
stripped. Fix by adding to `api/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP:X-Worker-Key} ^(.+)$
RewriteRule .* - [E=HTTP_X_WORKER_KEY:%1]
```

**Delete `api/_probe.php` when done.**

### B. Model output is clean
Run the step-4 test with three real messy Bahasa messages (a clean price list; a
"stock ada, harga besok"; a garbled one). Confirm no `<think>`, schema-valid JSON, and
that the garbled one returns `admin_intervention_required: true` rather than inventing
prices.

### C. Reply-quote routing data exists
Confirm Evolution returns `key.id` on send and includes `contextInfo.stanzaId` on
replies (visible in the agent log lines), so reply-quote routing works (Q9).

---

## Daily operation

- **Admin → reconciliation:** `admin/quotes.php` — map new vendor labels to catalog
  materials (creates reusable aliases, backfills the index). Only mapped prices feed the
  global RAB index (ADR 0003).
- **Admin → needs attention:** the same page's second tab — resolve ambiguous routing,
  disputes, and parse failures (Q9/Q10/Q12).
- **Tuning without code:** move the feature between tiers via `feature_access`; change
  quotas via `plan_limits`; change send pacing/cap in the agent's `config.json`.

## Failure behaviour (by design)

- Home box offline → outbound waits `queued`, inbound waits `unparsed`; both drain on
  recovery. Nothing lost.
- Agent crashes mid-send → the stranded `sending` row is reclaimed after 5 min.
- Bad AI parse → flagged for admin; the raw vendor message still shows in the thread.
