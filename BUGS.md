# Bug Tracker — Build in Lombok

A lightweight, in-repo bug tracker. It lives in the repo so **any** Claude Code session
(or human) can read it, update it, and work a bug end-to-end with no external service or
auth. It is referenced from `CLAUDE.md`, so every session sees it automatically.

## How to use this file (Claude Code sessions: follow this)

- **Find work:** scan for entries with `Status: open`, highest severity first.
- **Claim a bug:** change its `Status:` to `in-progress` before you start.
- **Fix it:** implement the fix in the codebase as normal.
- **Close it:** set `Status: fixed` and fill `Resolution:` with the date, a one-line
  summary, and the commit hash (e.g. `2026-06-04 — preserved brand names in prompt; abc1234`).
  Leave the entry in place as history — do **not** delete fixed bugs.
- **Report a new bug:** copy the template below, give it the next `BUG-NNN` id (one higher
  than the current maximum — ids are never reused), fill it in, set `Status: open`.
- Keep entries ordered by id. Keep each entry **self-contained** (file paths, repro steps)
  so a cold session can act on it without extra context.

**Status:** `open` · `in-progress` · `fixed` · `wontfix`
**Severity:** `low` · `medium` · `high` · `critical` (= user impact; a data-integrity bug
outranks a display-only one).

### Template

```text
### BUG-NNN — <short title>
- **Status:** open
- **Severity:** <low|medium|high|critical>
- **Area:** <file(s) / module>
- **Reported:** <YYYY-MM-DD>
- **Description:** <what is wrong>
- **Repro / example:** <steps, or example input → wrong output>
- **Suggested fix:** <approach, if known>
- **Resolution:** <filled on close: date — summary — commit hash>
```

---

## Bugs

### BUG-001 — WhatsApp agent translates Indonesian brand / proper names
- **Status:** open
- **Severity:** low (display quality only — does not affect the price index or matching)
- **Area:** `agent/whatsapp-agent.Modelfile`; surfaces in `quote_messages.body_translated_en` / `body_expanded_id`, rendered by `renderQuoteDetail` in `app.js`
- **Reported:** 2026-06-03
- **Description:** The `whatsapp-agent` model literally translates Indonesian brand and product names. "Semen Tiga Roda" (a cement brand, lit. "three wheels") becomes "three-wheel cement" in `message_translated_english`. Brand names, product names, and proper nouns must be kept verbatim across all output fields.
- **Repro / example:** Send `semen tiga roda 75rb/sak` through the model → English output reads "three-wheel cement" instead of "Tiga Roda cement".
- **Suggested fix:** (1) Add a Modelfile SYSTEM rule: never translate brand/product/proper nouns; keep them verbatim in every field. Rebuild with `ollama create whatsapp-agent -f agent/whatsapp-agent.Modelfile`. (2) Optionally maintain a protected-brand glossary (Tiga Roda, Gresik, Holcim/Dynamix, SCG, Conch, Mapei, Aquaproof…) injected into the prompt. (3) Add a brand example to the verify test set. Note: `pricing_extracted.line_items[].item_label` already keeps the verbatim label and index matching uses `material_aliases` (ADR 0003), so the index is unaffected — this is display-only.
- **Resolution:** _(open)_
