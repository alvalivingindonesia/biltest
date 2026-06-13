// Build in Lombok — Listing Worker ↔ HostPapa ingest API client (docs/adr/0007).
// Outbound-only: the Worker calls these; HostPapa never calls the Worker.

const API_BASE = process.env.API_BASE;
const WORKER_KEY = process.env.WORKER_KEY;

async function call(action, body) {
  if (!API_BASE || !WORKER_KEY) throw new Error('API_BASE / WORKER_KEY not set (.env)');
  const res = await fetch(`${API_BASE}?action=${action}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Worker-Key': WORKER_KEY, // Authorization: Bearer is stripped by shared hosting (ADR 0002)
    },
    // Also carry the key in the body: some shared hosts strip custom headers.
    body: JSON.stringify({ ...(body || {}), worker_key: WORKER_KEY }),
  });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); }
  catch { throw new Error(`Non-JSON from ${action} (${res.status}): ${text.slice(0, 200)}`); }
  if (!res.ok || json.error) throw new Error(`${action} failed: ${json.error || res.status} ${json.detail || ''}`);
  return json;
}

export const api = {
  ping:                ()       => call('ping', {}),
  pullWork:            (limit)  => call('pull_work', { limit }),
  postListing:         (facts)  => call('post_listing', facts),
  postLiveness:        (p)      => call('post_liveness', p),
  recomputeReputation: ()       => call('recompute_reputation', {}),
  geography:           ()       => call('geography', {}),
  serveText:           (after, limit) => call('serve_text', { after_id: after, limit }),
  postLocation:        (p)      => call('post_location', p),
  pullRecrawl:         (after, limit) => call('pull_recrawl', { after_id: after, limit }),
  postCardImages:      (site, cards)  => call('post_card_images', { source_site: site, cards }),
};
