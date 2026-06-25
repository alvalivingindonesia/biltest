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
import { createHash } from 'crypto';
import { writeFileSync, mkdirSync } from 'fs';
import { chromium } from 'playwright';
import { api } from './lib/api.js';
import { SITES, readPage, detectGone, extractSearchLinks, extractSearchCards, isGenericTitle } from './lib/extractors.js';
import { ollamaEnabled, ollamaUp, extractLocationTags, extractListing } from './lib/ollama.js';

const ARGS = new Set(process.argv.slice(2));
let GEO = null; // geography (areas/places/aliases) for the Extractor prompt
const sourceHash = (title, desc) => createHash('sha256').update(String(title || '') + '\n' + String(desc || '')).digest('hex');
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
            await api.postLiveness({ listing_id: r.id, state: 'failed' }); failed++;
            log('no title (skip)', r.id, r.source_url);
          } else {
            const siteHash = sourceHash(facts.title, facts.description);
            // Run the LLM only when there's something to fix or recover: a generic
            // scraped title, a thin description, or content that changed since last
            // time. Good, unchanged listings skip straight to liveness-only.
            const needsLLM = isGenericTitle(facts.title) || (facts.description || '').length < 120
              || !r.source_hash || r.source_hash !== siteHash;
            if (!needsLLM) {
              await api.postLiveness({ listing_id: r.id, state: 'present' });
              present++;
              log('unchanged (liveness only)', r.id);
            } else {
              // LLM full extraction from the PAGE text (ADR 0009): recover the real
              // title + full description the site selectors missed, plus location/
              // tags. Price/size stay deterministic (kept from the site extractor).
              if (ollamaEnabled() && GEO) {
                const ex = await extractListing({ title: facts.title, description: facts.description, visible_text: pg.text }, GEO);
                if (ex) {
                  if (ex.title && isGenericTitle(facts.title) && !isGenericTitle(ex.title)) facts.title = ex.title;
                  if (ex.description && ex.description.length > (facts.description || '').length) facts.description = ex.description;
                  facts.llm_area_key = ex.llm_area_key;
                  facts.llm_place = ex.llm_place;
                  if (ex.tags && ex.tags.length) facts.tags = ex.tags;
                  // Explicit category from the page wins; fall back to the LLM's
                  // (prompt-disciplined) type only when no category was found.
                  if (!facts.listing_type && ex.listing_type) facts.listing_type = ex.listing_type;
                  if (ex.certificate_text) facts.certificate_text = ex.certificate_text;
                  facts.extraction_method = 'llm';
                  facts.extraction_confidence = ex.extraction_confidence;
                }
              }
              facts.source_hash = sourceHash(facts.title, facts.description);
              facts.listing_id = r.id; // authoritative — server UPDATES this row
              facts.source_site = site;
              if (r.source_listing_id) facts.source_listing_id = r.source_listing_id;
              const res = await api.postListing(facts);
              present++;
              log('present', r.id, res.mode, facts.title?.slice(0, 40), facts.llm_place ? '@' + facts.llm_place : '');
            }
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

// ── Mode A: re-extract location/place/tags from STORED text (no crawl) ──
// (ADR 0009) Fast, headless, no browser — fixes locations corpus-wide via the
// local LLM over descriptions we already stored. Cursor by id.
async function reextractLocations() {
  if (!ollamaEnabled()) { log('OLLAMA_LOCATION not enabled in .env — nothing to do'); return; }
  if (!(await ollamaUp())) { log('ABORT: Ollama is not reachable — start it (Ollama app / `ollama serve`) and retry. Nothing was changed.'); return; }
  GEO = await api.geography();
  log('geography:', (GEO.areas || []).length, 'areas,', (GEO.places || []).length, 'places');
  let after = 0, done = 0, changed = 0, unmapped = 0;
  const unmappedTally = new Map();   // place name → count (alias / new-Area to-do list)
  const unmappedIds = new Map();     // place name → [listing ids]
  const noLocation = [];             // {id, title} — thin/generic text, left unchanged
  while (true) {
    const batch = await api.serveText(after, 100);
    if (!batch.rows.length) break;
    for (const row of batch.rows) {
      try {
        const ex = await extractLocationTags(row.title, row.description, GEO);
        if (!ex || (ex.area_key === 'unknown' && !ex.place)) {
          // No confident location (Ollama miss, or thin/generic text) — leave the
          // listing alone, but MARK it so --recrawl-thin targets exactly these.
          noLocation.push({ id: row.id, title: row.title || '' });
          try { await api.postLocation({ listing_id: row.id, no_location: 1 }); } catch (e) {}
          log('no confident location', row.id);
          continue;
        }
        const res = await api.postLocation({
          listing_id: row.id,
          llm_area_key: ex.area_key,
          llm_place: ex.place,
          tags: ex.tags,
          certificate_text: ex.certificate,
          extraction_confidence: ex.confidence,
        });
        done++;
        if (res.changed && res.changed.length) changed++;
        if (!res.area_key) {
          unmapped++;
          const p = (ex.place || '?').trim().toLowerCase();
          unmappedTally.set(p, (unmappedTally.get(p) || 0) + 1);
          const arr = unmappedIds.get(p) || []; arr.push(row.id); unmappedIds.set(p, arr);
        }
        log('reextract', row.id, '→', res.area_key || 'UNMAPPED', res.place_key ? '/' + res.place_key : '', '(' + (ex.place || '?') + ')');
      } catch (e) { log('reextract error', row.id, e.message); }
    }
    after = batch.next_after_id;
  }
  log('REEXTRACT COMPLETE', { processed: done, changed, unmapped, noLocation: noLocation.length });

  try { mkdirSync('logs', { recursive: true }); } catch (_) {}

  // ── Section 1: NO CONFIDENT LOCATION (check these) ──────────────────
  log('───── NO CONFIDENT LOCATION (' + noLocation.length + ') — thin/generic text, left unchanged; check these ─────');
  noLocation.slice(0, 40).forEach((r) => log('   #' + r.id + '  ' + r.title.slice(0, 60)));
  if (noLocation.length > 40) log('   …+' + (noLocation.length - 40) + ' more (full list in logs/reextract-no-location.txt)');
  writeFileSync('logs/reextract-no-location.txt',
    'No confident location — ' + noLocation.length + ' listings\n\n' +
    noLocation.map((r) => '#' + r.id + '\t' + r.title).join('\n') + '\n');

  // ── Section 2: UNMAPPED PLACES (alias / new Area candidates) ────────
  const top = [...unmappedTally.entries()].filter(([p]) => p && p !== '?').sort((a, b) => b[1] - a[1]);
  log('───── UNMAPPED PLACES (' + top.length + ') — recurring names to alias or promote to an Area ─────');
  top.slice(0, 40).forEach(([p, c]) => log('   ' + String(c).padStart(3) + '×  ' + p));
  if (top.length > 40) log('   …+' + (top.length - 40) + ' more (full list in logs/reextract-unmapped.txt)');
  writeFileSync('logs/reextract-unmapped.txt',
    'Unmapped places (count, name, listing ids)\n\n' +
    top.map(([p, c]) => String(c).padStart(3) + '×  ' + p + '   [' + (unmappedIds.get(p) || []).join(',') + ']').join('\n') + '\n');
}

// ── Main ────────────────────────────────────────────────────────────
(async () => {
  if (ARGS.has('--ping')) {
    const r = await api.ping(); log('ping', JSON.stringify(r)); return;
  }

  if (ARGS.has('--reputation')) {
    const r = await api.recomputeReputation(); log('reputation recomputed', JSON.stringify(r)); return;
  }

  if (ARGS.has('--reextract')) {
    log('REEXTRACT (Mode A) starting — location/tags from stored text, no crawl');
    await reextractLocations();
    return;
  }

  if (ARGS.has('--backfill-images')) {
    // Fill thumbnails for imageless listings from the SEARCH-RESULTS cards — no
    // detail-page fetch. Pages each discovery source; the server fills only
    // listings that currently have no image.
    const IMG_PAGES = parseInt(process.env.IMAGE_PAGES || '20', 10);
    const imgDelay = () => 4000 + Math.floor(Math.random() * 5000); // search pages: gentler than detail crawl
    const onlySite = (process.argv.find((a) => a.startsWith('--site=')) || '').split('=')[1] || process.env.SITE || '';
    const REFRESH = process.argv.includes('--refresh'); // overwrite stale/broken images, not just empty ones
    log('BACKFILL-IMAGES starting', { IMG_PAGES, HEADFUL, onlySite: onlySite || '(all)', refresh: REFRESH });
    const work = await api.pullWork(1); // just for the discovery_sources list
    let sources = (work.discovery_sources || []);
    log('active discovery sources:', sources.map((s) => s.source_site).join(', ') || '(none)');
    if (onlySite) sources = sources.filter((s) => s.source_site === onlySite);
    const browser = await chromium.launch(LAUNCH_OPTS);
    const ctx = await newContext(browser);
    try {
      let matched = 0, updated = 0;
      for (const src of sources) {
        const site = src.source_site;
        if (!SITES[site]) continue;
        const seen = new Set(); // detect end-of-results / page looping back
        for (let p = 1; p <= IMG_PAGES; p++) {
          const url = src.search_url + (src.search_url.includes('?') ? '&' : '?') + 'page=' + p;
          const page = await ctx.newPage();
          let cards = [], diag = '';
          try {
            const nav = await goto(page, url);
            if (nav.failed) { diag = 'nav failed: ' + nav.error; }
            else {
              // scroll to trigger lazy-loaded cards + images before reading
              try {
                await page.evaluate(async () => {
                  for (let y = 0; y < document.body.scrollHeight; y += 700) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 150)); }
                  window.scrollTo(0, 0);
                });
                await page.waitForTimeout(1500);
              } catch (_) {}
              cards = await extractSearchCards(page, site);
              if (!cards.length) {
                try {
                  const t = await page.title();
                  const html = await page.content();
                  const m = (html.match(new RegExp(SITES[site].detailUrlPattern.source, 'gi')) || []).length;
                  diag = `finalUrl=${nav.finalUrl} title="${(t || '').slice(0, 50)}" rawDetailLinks=${m} htmlLen=${html.length}`;
                } catch (_) {}
              }
            }
          } catch (e) { diag = 'err: ' + e.message; }
          finally { await page.close(); }
          if (!cards.length) { log('images', site, 'p' + p, 'no cards —', diag); break; }
          // Only the cards not seen on earlier pages. No new cards ⇒ end of
          // results (or Lamudi looped back to page 1) ⇒ stop this source.
          const fresh = cards.filter((c) => !seen.has(c.url));
          if (!fresh.length) { log('images', site, 'p' + p, '— no new cards (end of results)'); break; }
          fresh.forEach((c) => seen.add(c.url));
          const payload = fresh.map((c) => ({ source_listing_id: SITES[site].idFromUrl(c.url), url: c.url, image: c.img }));
          let res = { matched: 0, updated: 0 };
          try { res = await api.postCardImages(site, payload, REFRESH); } catch (e) { log('post images failed', e.message); }
          matched += res.matched || 0; updated += res.updated || 0;
          log('images', site, src.label || '', 'p' + p, '—', fresh.length, 'new of', cards.length, '→', (res.updated || 0), 'filled (', (res.matched || 0), 'matched, total', updated + ')');
          await sleep(imgDelay());
        }
      }
      log('BACKFILL-IMAGES COMPLETE', { matched, updated });
    } finally {
      await ctx.close();
      await browser.close();
    }
    return;
  }

  if (ARGS.has('--sweep-liveness')) {
    // Cheap expired-listing detection: page each portal's SEARCH grid (no detail
    // fetch) and report which of our active listings still appear. The server
    // increments an absent counter for the rest — but only when we reached the
    // end of results (complete), so partial coverage never wrongly flags. Then
    // `npm run recheck-gone` confirms candidates with a real detail check.
    const SWEEP_PAGES = parseInt(process.env.SWEEP_PAGES || '40', 10);
    const sweepDelay = () => 4000 + Math.floor(Math.random() * 5000);
    const onlySite = (process.argv.find((a) => a.startsWith('--site=')) || '').split('=')[1] || process.env.SITE || '';
    log('SWEEP-LIVENESS starting', { SWEEP_PAGES, HEADFUL, onlySite: onlySite || '(all)' });
    const work = await api.pullWork(1); // just for the discovery_sources list
    let sources = (work.discovery_sources || []);
    log('active discovery sources:', sources.map((s) => s.source_site).join(', ') || '(none)');
    if (onlySite) sources = sources.filter((s) => s.source_site === onlySite);
    const bySite = {};
    for (const s of sources) { if (!SITES[s.source_site]) continue; (bySite[s.source_site] = bySite[s.source_site] || []).push(s); }
    const browser = await chromium.launch(LAUNCH_OPTS);
    const ctx = await newContext(browser);
    try {
      for (const site of Object.keys(bySite)) {
        const ids = new Set(), urls = new Set();
        let complete = true; // false if any source can't be fully paged → don't flag absences this run
        for (const src of bySite[site]) {
          const seen = new Set();
          let reachedEnd = false;
          for (let p = 1; p <= SWEEP_PAGES; p++) {
            const url = src.search_url + (src.search_url.includes('?') ? '&' : '?') + 'page=' + p;
            const page = await ctx.newPage();
            let cards = [], navFailed = false;
            try {
              const nav = await goto(page, url);
              if (nav.failed) { navFailed = true; }
              else {
                try {
                  await page.evaluate(async () => {
                    for (let y = 0; y < document.body.scrollHeight; y += 700) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); }
                    window.scrollTo(0, 0);
                  });
                  await page.waitForTimeout(1200);
                } catch (_) {}
                cards = await extractSearchCards(page, site);
                if (!cards.length) {
                  // some layouts yield links but no card images — links are enough for liveness
                  try { const links = await extractSearchLinks(page, site); cards = links.map((u) => ({ url: u, img: '' })); } catch (_) {}
                }
              }
            } catch (e) { navFailed = true; }
            finally { await page.close(); }
            if (navFailed) { complete = false; log('sweep', site, 'p' + p, 'nav failed — marking site coverage partial'); break; }
            if (!cards.length) { reachedEnd = true; break; }
            const fresh = cards.filter((c) => !seen.has(c.url));
            if (!fresh.length) { reachedEnd = true; break; } // looped back / no new ⇒ end
            fresh.forEach((c) => { seen.add(c.url); urls.add(c.url); const id = SITES[site].idFromUrl(c.url); if (id) ids.add(String(id)); });
            await sleep(sweepDelay());
          }
          if (!reachedEnd) complete = false; // hit page cap without end ⇒ incomplete
          log('sweep', site, src.label || '', '— collected', seen.size, reachedEnd ? '(end reached)' : '(page cap — partial)');
        }
        let res = {};
        try { res = await api.postSweep(site, { ids: Array.from(ids), urls: Array.from(urls), complete }); }
        catch (e) { log('post sweep failed', e.message); }
        log('SWEEP', site, '— sent', ids.size, 'ids /', urls.size, 'urls; complete=' + complete,
            '→ active', res.active, 'seen', res.seen, 'absent+', res.absent_incremented, 'gone-candidates', res.gone_candidates);
      }
    } finally {
      await ctx.close();
      await browser.close();
    }
    return;
  }

  if (ARGS.has('--recheck-gone')) {
    // Confirm sweep-flagged candidates (sweep_absent_count >= 2) with a real
    // detail check. A live one resets its counter; a genuinely gone one expires.
    log('RECHECK-GONE starting (sweep-flagged candidates only)', { HEADFUL });
    if (ollamaEnabled()) { try { GEO = await api.geography(); } catch (e) { log('geography fetch failed', e.message); } }
    const browser = await chromium.launch(LAUNCH_OPTS);
    const ctx = await newContext(browser);
    try {
      let after = 0, totals = { present: 0, gone: 0, failed: 0 }, n = 0;
      while (true) {
        const work = await api.pullGoneCandidates(after, 200);
        if (!work.rows.length) break;
        log('recheck-gone batch:', work.rows.length, '(after id', after + ')');
        const rc = await recheck(ctx, work.rows);
        totals.present += rc.present; totals.gone += rc.gone; totals.failed += rc.failed; n += work.rows.length;
        after = work.next_after_id;
      }
      log('RECHECK-GONE COMPLETE', { pulled: n, ...totals });
    } finally {
      await ctx.close();
      await browser.close();
    }
    return;
  }

  if (ARGS.has('--recrawl-thin')) {
    // Targeted Mode B: re-crawl ONLY listings with thin/no-location stored data,
    // so the LLM recovers the real title + full description from the live page.
    log('RECRAWL-THIN starting (thin/no-location listings only)', { HEADFUL });
    if (ollamaEnabled()) { try { GEO = await api.geography(); log('geography:', (GEO.areas || []).length, 'areas,', (GEO.places || []).length, 'places'); } catch (e) { log('geography fetch failed', e.message); } }
    const browser = await chromium.launch(LAUNCH_OPTS);
    const ctx = await newContext(browser);
    try {
      let after = 0, totals = { present: 0, gone: 0, failed: 0 }, n = 0;
      while (true) {
        const work = await api.pullRecrawl(after, 200);
        if (!work.rows.length) break;
        log('recrawl batch:', work.rows.length, 'listings (after id', after + ')');
        const rc = await recheck(ctx, work.rows);
        totals.present += rc.present; totals.gone += rc.gone; totals.failed += rc.failed; n += work.rows.length;
        after = work.next_after_id;
      }
      log('RECRAWL-THIN COMPLETE', { pulled: n, ...totals });
    } finally {
      await ctx.close();
      await browser.close();
    }
    return;
  }

  // Fetch geography once for LLM location enrichment (Mode B).
  if (ollamaEnabled()) {
    if (!(await ollamaUp())) log('WARN: Ollama unreachable — crawl will run (liveness + site extraction) but skip LLM location/title enrichment.');
    try { GEO = await api.geography(); log('geography:', (GEO.areas || []).length, 'areas,', (GEO.places || []).length, 'places'); } catch (e) { log('geography fetch failed', e.message); }
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
