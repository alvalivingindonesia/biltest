// Build in Lombok — Listing Worker (docs/adr/0007)
//
// Runs on the always-on home PC (residential IP defeats the portals' anti-bot).
// Outbound-only to HostPapa. Two phases per run:
//   1. Re-check — fetch each due listing's detail page; report present/gone/failed.
//   2. Discovery — scan active search pages; ingest NEW detail pages.
// Politeness: sequential, never parallel, randomised delays between page loads.
//
// Usage:
//   node listing-worker.js                 (re-check + discovery)
//   node listing-worker.js --recheck-only
//   node listing-worker.js --discover-only
//   node listing-worker.js --ping          (auth/connectivity check)

import 'dotenv/config';
import { chromium } from 'playwright';
import { api } from './lib/api.js';
import { SITES, readPage, detectGone, extractSearchLinks } from './lib/extractors.js';

const ARGS = new Set(process.argv.slice(2));
const RECHECK_LIMIT   = parseInt(process.env.RECHECK_LIMIT || '80', 10);
const DISCOVERY_LIMIT = parseInt(process.env.DISCOVERY_LIMIT || '40', 10);
const DELAY_MIN = parseInt(process.env.DELAY_MIN_MS || '30000', 10);
const DELAY_MAX = parseInt(process.env.DELAY_MAX_MS || '60000', 10);
const HEADFUL   = process.env.HEADFUL === '1' || new Set(process.argv.slice(2)).has('--headful');

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
// --disable-http2: OLX's CDN drops Playwright's HTTP/2 connection
// (net::ERR_HTTP2_PROTOCOL_ERROR); HTTP/1.1 is accepted and works for the
// others too. AutomationControlled off lowers the bot-detection footprint.
const LAUNCH_OPTS = { headless: !HEADFUL, args: ['--disable-http2', '--disable-blink-features=AutomationControlled'] };
const log = (...a) => console.log(new Date().toISOString(), ...a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const jitter = () => DELAY_MIN + Math.floor(Math.random() * Math.max(1, DELAY_MAX - DELAY_MIN));

function siteOf(url) {
  if (/lamudi\.co\.id/i.test(url)) return 'lamudi';
  if (/rumah123\.com/i.test(url)) return 'rumah123';
  if (/dotproperty\.id/i.test(url)) return 'dotproperty';
  if (/olx\.co\.id/i.test(url)) return 'olx';
  return null;
}

async function newContext(browser) {
  const ctx = await browser.newContext({
    userAgent: UA,
    locale: 'id-ID',
    viewport: { width: 1366, height: 900 },
    extraHTTPHeaders: { 'Accept-Language': 'id-ID,id;q=0.9,en;q=0.8' },
  });
  ctx.setDefaultNavigationTimeout(45000);
  return ctx;
}

// Load a URL, returning { status, finalUrl, ok } or { failed:true } on infra error.
async function goto(page, url) {
  try {
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500); // let RSC/JS settle
    return { status: resp ? resp.status() : 0, finalUrl: page.url(), ok: true };
  } catch (e) {
    return { failed: true, error: e.message };
  }
}

// ── Phase 1: re-check ───────────────────────────────────────────────
async function recheck(ctx, rechecks) {
  let present = 0, gone = 0, failed = 0;
  for (const r of rechecks) {
    const site = r.source_site && SITES[r.source_site] ? r.source_site : siteOf(r.source_url);
    if (!site) { log('skip (unknown site)', r.source_url); continue; }
    const page = await ctx.newPage();
    try {
      const nav = await goto(page, r.source_url);
      if (nav.failed) {
        await api.postLiveness({ listing_id: r.id, state: 'failed' }); failed++;
        log('failed', r.id, nav.error);
      } else {
        const pg = await readPage(page);
        if (detectGone(nav.finalUrl, nav.status, pg.text, SITES[site].detailUrlPattern)) {
          await api.postLiveness({ listing_id: r.id, state: 'gone' }); gone++;
          log('gone → expired', r.id, r.source_url);
        } else {
          const facts = await SITES[site].extractDetail(page, r.source_url);
          if (!facts.title) {
            // Loaded but no listing on the page (a not-found variant the gone
            // markers didn't catch, or a transient parse miss) — skip + retry,
            // never post an empty title.
            await api.postLiveness({ listing_id: r.id, state: 'failed' }); failed++;
            log('no title (skip)', r.id, r.source_url);
          } else {
            // Authoritative identity from the re-check record so the server
            // UPDATES this exact row instead of inserting a duplicate.
            facts.listing_id = r.id;
            facts.source_site = site;
            if (r.source_listing_id) facts.source_listing_id = r.source_listing_id;
            const res = await api.postListing(facts);
            present++;
            log('present', r.id, res.mode, facts.title?.slice(0, 50), res.price_flagged ? '(price flagged)' : '');
          }
        }
      }
    } catch (e) {
      await api.postLiveness({ listing_id: r.id, state: 'failed' }).catch(() => {});
      failed++; log('error', r.id, e.message);
    } finally {
      await page.close();
    }
    await sleep(jitter());
  }
  return { present, gone, failed };
}

// ── Phase 2: discovery ──────────────────────────────────────────────
async function discover(ctx, sources) {
  let imported = 0;
  const seen = new Set();
  for (const src of sources) {
    if (imported >= DISCOVERY_LIMIT) break;
    const site = src.source_site;
    if (!SITES[site]) continue;
    for (let p = 1; p <= (src.max_pages || 3) && imported < DISCOVERY_LIMIT; p++) {
      const url = src.search_url + (src.search_url.includes('?') ? '&' : '?') + 'page=' + p;
      const page = await ctx.newPage();
      let links = [];
      try {
        const nav = await goto(page, url);
        if (!nav.failed) links = await extractSearchLinks(page, site);
        log('discovery', site, 'page', p, '→', links.length, 'links');
      } catch (e) { log('discovery error', url, e.message); }
      finally { await page.close(); }
      await sleep(jitter());

      for (const link of links) {
        if (imported >= DISCOVERY_LIMIT) break;
        if (seen.has(link)) continue;
        seen.add(link);
        const dp = await ctx.newPage();
        try {
          const nav = await goto(dp, link);
          if (nav.failed) { log('detail failed', link); }
          else {
            const pg = await readPage(dp);
            if (detectGone(nav.finalUrl, nav.status, pg.text, SITES[site].detailUrlPattern)) { /* skip dead */ }
            else {
              const facts = await SITES[site].extractDetail(dp, link);
              if (facts.title) {
                const res = await api.postListing(facts);
                if (res.mode === 'inserted') { imported++; log('discovered', site, facts.title.slice(0, 50)); }
              }
            }
          }
        } catch (e) { log('detail error', link, e.message); }
        finally { await dp.close(); }
        await sleep(jitter());
      }
    }
  }
  return { imported };
}

// ── One-off: re-check EVERY active listing (fixes the whole backlog) ──
// Pulls max-size batches repeatedly until no new listing comes back. Used to
// back-fill real titles/descriptions/prices over existing rows in one
// supervised run, rather than waiting for the nightly window.
async function recheckAll(ctx) {
  const processed = new Set();
  const totals = { present: 0, gone: 0, failed: 0 };
  let round = 0;
  while (true) {
    round++;
    const work = await api.pullWork(500); // server caps at 500
    const batch = work.rechecks.filter((r) => !processed.has(r.id));
    log(`round ${round}: server returned ${work.rechecks.length}, ${batch.length} not-yet-done`);
    if (batch.length === 0) break; // everything reachable has been processed
    batch.forEach((r) => processed.add(r.id));
    const rc = await recheck(ctx, batch);
    totals.present += rc.present; totals.gone += rc.gone; totals.failed += rc.failed;
    log(`round ${round} done`, rc, '· cumulative', totals, '· listings seen', processed.size);
  }
  return { listings: processed.size, ...totals };
}

// ── Main ────────────────────────────────────────────────────────────
(async () => {
  if (ARGS.has('--ping')) {
    const r = await api.ping(); log('ping', JSON.stringify(r)); return;
  }

  if (ARGS.has('--reputation')) {
    const r = await api.recomputeReputation(); log('reputation recomputed', JSON.stringify(r)); return;
  }

  if (ARGS.has('--recheck-all')) {
    log('RECHECK-ALL one-off starting (all active listings)', { DELAY_MIN, DELAY_MAX, HEADFUL });
    const browser = await chromium.launch(LAUNCH_OPTS);
    const ctx = await newContext(browser);
    try {
      const summary = await recheckAll(ctx);
      log('RECHECK-ALL COMPLETE', summary);
    } finally {
      await ctx.close();
      await browser.close();
    }
    return;
  }

  log('Listing Worker starting', { RECHECK_LIMIT, DISCOVERY_LIMIT });
  const work = await api.pullWork(RECHECK_LIMIT);
  log('pulled', work.rechecks.length, 'rechecks,', work.discovery_sources.length, 'discovery sources');

  const browser = await chromium.launch(LAUNCH_OPTS);
  const ctx = await newContext(browser);
  try {
    let rc = { present: 0, gone: 0, failed: 0 }, dc = { imported: 0 };
    if (!ARGS.has('--discover-only')) rc = await recheck(ctx, work.rechecks);
    if (!ARGS.has('--recheck-only'))  dc = await discover(ctx, work.discovery_sources);
    // Recompute agent reputation off the fresh listing counts (ADR 0008).
    let rep = null;
    try { rep = await api.recomputeReputation(); } catch (e) { log('reputation recompute failed', e.message); }
    log('DONE', { recheck: rc, discovery: dc, reputation: rep });
  } finally {
    await ctx.close();
    await browser.close();
  }
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
