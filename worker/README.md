# Listing Worker (home PC)

The automated half of listing ingestion — see `docs/adr/0007-home-worker-listing-ingestion.md`.
Runs on Jon's always-on Windows PC. Its **residential IP** is what defeats the
portals' anti-bot (a datacenter `curl` from HostPapa gets blocked). It only ever
makes **outbound** HTTPS calls to `api/listing_ingest.php`; HostPapa never reaches in.

## What it does each run

1. `pull_work` → gets the nightly re-check batch (oldest-checked listings) + the
   active discovery search URLs.
2. **Re-check**: opens each listing's detail page and reports `present` (with fresh
   facts), `gone` (→ server expires it), or `failed` (infra error → skipped, retried
   next run).
3. **Discovery**: scans the search pages for NEW listings and ingests their detail
   pages.

It only **extracts raw facts**. All business rules (per-are→total, area_key, IDR,
dedupe, trust model) run server-side in `api/listing_canonical.php`.

## One-time setup (Windows)

```powershell
# 1. Install Node 18+ (https://nodejs.org), then:
cd path\to\worker
npm install                 # also runs "playwright install chromium"

# 2. Configure
copy .env.example .env
notepad .env                # set WORKER_KEY (= WORKER_API_KEY in the server config)

# 3. Connectivity check
npm run ping                # expect: { ok: true, pong: true }

# 4. First real run (watch it once with HEADFUL=1 in .env to sanity-check)
npm start
```

### Server side (HostPapa), once

- Run `migrations/2026_06_12_listing_ingestion.sql` in phpMyAdmin.
- Add to `/home/rovin629/config/biltest_config.php`:
  ```php
  define('WORKER_API_KEY', 'a-long-random-secret');      // same value as worker/.env
  define('CRON_REPUTATION_TOKEN', 'another-random-token');
  ```
- cPanel cron, nightly (after the worker), to refresh reputation:
  ```
  /usr/local/bin/php /home/rovin629/public_html/api/cron_reputation.php
  ```

## Schedule it (Task Scheduler)

- Create a Basic Task → Trigger: Daily, e.g. 02:00.
- Action: Start a program → `path\to\worker\run.bat`.
- "Run whether user is logged on or not", "Wake the computer to run".
- Logs land in `worker\logs\worker-YYYY-MM-DD.log`.

## Tuning / maintenance

- Pacing: `DELAY_MIN_MS` / `DELAY_MAX_MS` (default 30–60s between pages), `RECHECK_LIMIT`,
  `DISCOVERY_LIMIT` in `.env`.
- Manage what gets discovered, map unmapped areas, merge duplicate agents, review
  surprises, and lock hand-edited fields in **`/admin/ingest_console.php`**.
- The per-site selectors in `lib/extractors.js` (marked `// TUNE`) are the expected
  maintenance surface — if a portal changes layout, that's where to fix it. JSON-LD is
  used first because it's the most stable.

## Notes

- `.env` holds the shared secret — it is gitignored; never commit it.
- Sequential by design (never parallel) so the source only ever sees a human-paced trickle.
