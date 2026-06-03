/**
 * Build in Lombok — Home Worker Agent
 *
 * Runs on the home box (the only always-on component). Makes ONLY outbound
 * HTTPS calls to HostPapa's worker API; nothing reaches into this box (ADR 0002).
 *
 *   1. Outbound loop  — claim queued sends, send via local Evolution, mark result.
 *   2. Inbound server — receive Evolution's loopback webhook, post raw to HostPapa.
 *   3. Parse loop     — claim unparsed, run local Ollama, post structured result.
 *
 * Requirements: Node 18+ (global fetch). No npm dependencies.
 * Setup: copy config.sample.json -> config.json, fill it in, then `node index.js`.
 * Point Evolution's webhook (MESSAGES_UPSERT) at http://localhost:<webhook_port>/evolution
 */

'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');

const CFG = JSON.parse(fs.readFileSync(path.join(__dirname, 'config.json'), 'utf8'));
const log = (...a) => { if (CFG.verbose) console.log(new Date().toISOString(), ...a); };
const err = (...a) => console.error(new Date().toISOString(), 'ERROR', ...a);
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const rand = (lo, hi) => lo + Math.floor(Math.random() * (hi - lo + 1));

// ── Structured-output schema for Ollama (Q6/Q12) ─────────────────
const PARSE_SCHEMA = {
  type: 'object',
  properties: {
    sender_type: { type: 'string' },
    original_message: { type: 'string' },
    original_language: { type: 'string', enum: ['id', 'en'] },
    message_expanded_indonesian: { type: 'string' },
    message_translated_english: { type: 'string' },
    intent: { type: 'string' },
    stock_status: { type: 'string', enum: ['available', 'out_of_stock', 'unknown'] },
    pricing_extracted: {
      type: 'object',
      properties: {
        pricing_available: { type: 'boolean' },
        currency: { type: ['string', 'null'] },
        line_items: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              item_label: { type: 'string' },
              request_item_id: { type: ['integer', 'null'] },
              unit_price: { type: ['number', 'null'] },
              unit: { type: ['string', 'null'] },
              quantity: { type: ['string', 'null'] },
              price_includes_delivery: { type: ['boolean', 'null'] }
            },
            required: ['item_label']
          }
        },
        overall_notes: { type: ['string', 'null'] }
      },
      required: ['pricing_available']
    },
    delivery_logistics: {
      type: 'object',
      properties: {
        delivery_possible: { type: ['boolean', 'null'] },
        delivery_charge: { type: ['string', 'number', 'null'] }
      }
    },
    follow_up_required: { type: 'boolean' },
    admin_intervention_required: { type: 'boolean' },
    admin_intervention_reason: { type: ['string', 'null'] },
    response: { type: 'string' }
  },
  required: ['intent', 'stock_status', 'pricing_extracted', 'follow_up_required', 'admin_intervention_required']
};

// ── HostPapa worker API ──────────────────────────────────────────
async function hp(action, body) {
  const res = await fetch(`${CFG.hostpapa_base}/api/quote_worker.php?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Worker-Key': CFG.worker_key },
    body: JSON.stringify(body || {})
  });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch (e) { throw new Error(`Bad JSON from ${action}: ${text.slice(0, 200)}`); }
  if (!res.ok) throw new Error(`${action} -> ${res.status}: ${json.error || text.slice(0, 200)}`);
  return json;
}

// ── Local Evolution send ─────────────────────────────────────────
async function evoSend(number, text) {
  const res = await fetch(`${CFG.evolution_url}/message/sendText/${encodeURIComponent(CFG.evolution_instance)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', apikey: CFG.evolution_apikey },
    body: JSON.stringify({ number, text })
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`Evolution send ${res.status}: ${JSON.stringify(json).slice(0, 200)}`);
  // v2 returns the sent message key.
  const waId = (json && json.key && json.key.id) ? json.key.id : null;
  return waId;
}

// ── Local Ollama parse (think:false + structured outputs) ────────
function extractJson(raw) {
  let s = String(raw);
  s = s.replace(/<think>[\s\S]*?<\/think>/gi, '');           // strip qwen3 reasoning
  s = s.replace(/```json\s*/gi, '').replace(/```/g, '');     // strip fences
  const a = s.indexOf('{'), b = s.lastIndexOf('}');
  if (a === -1 || b === -1 || b < a) throw new Error('no JSON object found');
  return JSON.parse(s.slice(a, b + 1));
}

async function ollamaParse(message) {
  // Give the model the vendor text + the request items so it can fill request_item_id (Q7).
  const itemsCtx = (message.request_items || [])
    .map(it => `  - request_item_id=${it.request_item_id}: ${it.material}${it.quantity ? ' (qty ' + it.quantity + ')' : ''}${it.info ? ' [' + it.info + ']' : ''}`)
    .join('\n');
  const content =
    `VENDOR MESSAGE:\n${message.body}\n\n` +
    `THE BUYER ASKED ABOUT THESE ITEMS (map each price you find to the matching request_item_id; use null if unsure):\n${itemsCtx || '  (none provided)'}\n\n` +
    `delivery_requested=${message.delivery_required ? 'yes' : 'no'}`;

  const res = await fetch(`${CFG.ollama_url}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: CFG.ollama_model,
      messages: [{ role: 'user', content }],
      stream: false,
      think: false,                 // qwen3: no <think> block (Q12)
      format: PARSE_SCHEMA,         // constrained decoding (Q12)
      options: { temperature: 0.1 }
    })
  });
  const json = await res.json();
  if (!res.ok) throw new Error(`Ollama ${res.status}: ${JSON.stringify(json).slice(0, 200)}`);
  const out = (json.message && json.message.content) ? json.message.content : '';
  return extractJson(out); // defensive even with structured outputs
}

// ── 1) Outbound loop (self-pacing, daily cap — Q11/ADR 0004) ─────
let sentToday = 0, sentDay = new Date().toDateString();
function capOk() {
  const today = new Date().toDateString();
  if (today !== sentDay) { sentDay = today; sentToday = 0; }
  return sentToday < CFG.daily_send_cap;
}

async function outboundLoop() {
  for (;;) {
    try {
      if (!capOk()) { await sleep(60000); continue; }
      const { messages } = await hp('claim_outbound', { limit: 1 });
      if (!messages || messages.length === 0) { await sleep(CFG.poll_interval_ms); continue; }

      const m = messages[0];
      try {
        const waId = await evoSend(m.vendor_phone, m.body);
        await hp('mark_sent', { message_id: m.message_id, ok: true, wa_message_id: waId });
        sentToday++;
        log(`sent message ${m.message_id} -> ${m.vendor_phone} (${sentToday}/${CFG.daily_send_cap} today)`);
      } catch (e) {
        err('send failed', m.message_id, e.message);
        await hp('mark_sent', { message_id: m.message_id, ok: false, error: e.message }).catch(() => {});
      }
      await sleep(rand(CFG.send_min_delay_ms, CFG.send_max_delay_ms)); // human-like spacing
    } catch (e) {
      err('outboundLoop', e.message);
      await sleep(10000);
    }
  }
}

// ── 3) Parse loop (Ollama bottleneck — runs serial) ──────────────
async function parseLoop() {
  for (;;) {
    try {
      const { messages } = await hp('claim_parse', { limit: CFG.parse_batch });
      if (!messages || messages.length === 0) { await sleep(CFG.poll_interval_ms); continue; }

      for (const msg of messages) {
        try {
          const payload = await ollamaParse(msg);
          await hp('post_parse_result', { message_id: msg.message_id, payload });
          log(`parsed message ${msg.message_id}`);
        } catch (e) {
          err('parse failed', msg.message_id, e.message);
          await hp('post_parse_result', { message_id: msg.message_id, error: e.message }).catch(() => {});
        }
      }
    } catch (e) {
      err('parseLoop', e.message);
      await sleep(10000);
    }
  }
}

// ── 2) Inbound webhook server (Evolution -> loopback -> HostPapa) ─
function readBody(req) {
  return new Promise((resolve) => {
    let b = '';
    req.on('data', c => { b += c; if (b.length > 2_000_000) req.destroy(); });
    req.on('end', () => resolve(b));
  });
}

function extractInbound(evt) {
  // Evolution v2 MESSAGES_UPSERT shape: { event, data: { key, message, pushName } }
  const d = (evt && evt.data) ? evt.data : evt;
  if (!d || !d.key) return null;
  if (d.key.fromMe) return null;                       // ignore our own sends
  const jid = d.key.remoteJid || '';
  if (jid.endsWith('@g.us')) return null;              // ignore group chats
  const phone = (jid.split('@')[0] || '').replace(/\D+/g, '');
  const mm = d.message || {};
  const text = mm.conversation
    || (mm.extendedTextMessage && mm.extendedTextMessage.text)
    || (mm.imageMessage && mm.imageMessage.caption)
    || '';
  const ctx = (mm.extendedTextMessage && mm.extendedTextMessage.contextInfo) || {};
  const replyTo = ctx.stanzaId || null;                // reply-quote id (Q9)
  if (!phone || !text) return null;
  return { vendor_phone: phone, body: text, wa_message_id: d.key.id || null, reply_to_wa_message_id: replyTo };
}

function startWebhookServer() {
  http.createServer(async (req, res) => {
    if (req.method !== 'POST') { res.writeHead(405).end(); return; }
    const raw = await readBody(req);
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end('{"ok":true}');                            // ack Evolution immediately
    try {
      const evt = JSON.parse(raw);
      const inbound = extractInbound(evt);
      if (!inbound) return;
      const r = await hp('post_inbound', inbound);
      log(`inbound from ${inbound.vendor_phone} -> ${r.routed ? 'chat ' + r.chat_id : 'unrouted'}`);
    } catch (e) {
      err('webhook', e.message);
    }
  }).listen(CFG.webhook_port, '127.0.0.1', () => {
    log(`inbound webhook listening on http://127.0.0.1:${CFG.webhook_port}/evolution`);
  });
}

// ── Boot ─────────────────────────────────────────────────────────
log('Build in Lombok worker agent starting…');
startWebhookServer();
outboundLoop();
parseLoop();
