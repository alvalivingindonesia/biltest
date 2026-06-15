# 0012 — Detailed RAB Generator: a parametric, catalog-driven BOQ builder

- **Status:** accepted
- **Date:** 2026-06-16
- **Relates to:** the existing quick RAB calculator + detailed RAB editor (the
  `rab_*` tables, kept frozen as a comparison backup); CONTEXT.md (reuses the
  Price Point / Indicative-vs-Confirmed trust vocabulary); future Variations portal
  (this ADR bakes its plumbing).

## Context

The site already has a "Detailed RAB" editor (`rab_projects → rab_rabs →
rab_sections → rab_items`, ARCH/MEP/STR disciplines, a thin `rab_materials` table,
empty `rab_build_templates`, and an HTML-table-as-`.xls` export). It is a manual
line-item editor with a quick calculator bolted on: **no generation-from-style, no
coefficient engine, no material/labour split, no location premium, no price
provenance, no templates with data.** That thinness is why it "doesn't work well."

Two assets define the target. (1) The owner's **real BOQs** (Angin Tinggi Villa 1,
Villa 2, Security Post) are complete bills in a fixed house format: `Final Summary`
(Material / Labour / Total + an area schedule + bidder/tender header + notes) →
`Structure` → `Architecture` → `MEP`, hierarchical refs (`A.1.1`, `B.2.4`,
`C.4.21`), room-by-room takeoff with the measurement formula left *in* the
description, "PC Sum" provisional items, and the QS "carried to collection" bill
form — riddled with `#REF!` errors, which is itself a core pain point. (2)
`Lombok_RAB_Database_v2.xlsx` + `build_rab.py` are a working **AHSP engine**: base
prices (Bahan/Upah/Alat) in two zones × SNI coefficients → unit work-item rates →
per-m² template → costed RAB, with an honesty model and an explicit data-gap list.

The new tool must serve lay users *and* professionals, do the heavy lifting from a
style/structure/finish choice, stay granular, and produce a contractor-grade
document — without disturbing the existing tool.

## Decision

Build a new **Detailed RAB Generator** as an isolated subsystem. Sixteen decisions:

1. **Hybrid pricing engine.** A flat "Supply & Install" **work-item rate catalog**
   is the spine users see and edit (matches the real BOQs). Each item *optionally*
   carries an **AHSP coefficient build-up** underneath, which enables auto
   material/labour split, recompute when base prices move, and a "how this rate is
   derived" view. Lay users never see coefficients.

2. **Isolated `drab_*` namespace.** A fresh schema owns documents, catalog, engine,
   and templates. The old `rab_*` tables stay frozen as a backup; only the generic
   units lookup is shared. Zero migration risk on a repo that auto-deploys from
   `main`.

3. **Multi-driver parametric generation.** The wizard collects *drivers* (floor
   area per level, footprint, bed/bath counts, and each extra's size). Each template
   line = `driver × coefficient`, with coefficients **calibrated from the real Villa
   BOQs**. The `wall_area` driver = `floor_area × style.wall_factor`, so Style moves
   the numbers, not just the labels. No CAD/floor-plan import in v1 — precise
   per-wall takeoff is editing, not geometry.

4. **Spec slots with tier defaults + per-line swap.** Each line fills a slot
   (structural concrete, wall finish, ceiling, floor finish, paint, waterproofing).
   The Finish Tier resolves every slot to a specific catalog item; any line then
   offers a dropdown of alternatives for that slot, or goes custom. Waterproofing's
   presence *and* spec follow the tier automatically. Brand/colour is printed
   metadata.

5. **Mataram baseline + computed Site Factor.** One base price set; a Site Factor
   from `distance_band × access/terrain` lifts the **material** component (freight)
   and adds a **labour mobilisation** uplift. Named zones are presets that pre-fill
   it. Shown transparently ("+18% materials, +10% labour").

6. **Store material_rate + labour_rate on every line.** Exact where a build-up
   exists; otherwise derived from an editable **category ratio** (concrete 70/30,
   masonry 60/40, painting 40/60, MEP fixtures 80/20). The combined-vs-split control
   is **display/export only** — one "Unit Price" column (Villa-1 style) or
   Material/Labour/Total (Final-Summary style).

7. **Indicative / Confirmed trust model, index-ready.** Every price (per item, per
   zone) carries a confidence flag, `source` + `date`, and a `basis` enum
   (`real_quote`, `real_boq`, `derived`, `national_ref`, `estimate`,
   **`other_province_adjusted`**). Confirmed = an agreed contractor price or paid
   invoice (the Villa rates qualify). Derived/adjusted rows record their base source
   + factor (e.g. "Mitre10 Mataram retail +30% freight"). Schema is shaped to later
   graduate into a running Price Point index (CONTEXT.md).

8. **Paywall gates outputs, persistence, and trust — not the magic.** Free: full
   wizard + on-screen RAB + tier-swap + line editing + **Indicative** pricing + 1
   saved project + watermarked/summary export. Premium: unlimited saves & templates,
   clean contractor-ready `.xlsx` + PDF, **Confirmed** pricing, material/labour split
   view, Variations. All gates via `feature_access`, never hardcoded.

9. **Anti-abuse by what we don't show.** Free shows precise Indicative rates only
   **in-document** — there is deliberately **no free browsable price-book** (the
   single biggest anti-scrape lever). Full breakdown sits behind a free login
   (throttleable). The moat is "current + Confirmed + Lombok-specific," not secrecy
   of ballpark numbers; ballpark leakage is acquisition.

10. **Dedicated code: `drab.js` + `api/drab_api.php`.** New frontend file loaded via
    its own `<script>` tag (precedent: `i18n/en.js`), new backend PHP parallel to
    `rab_api.php` (precedent: per-domain PHP). Reuse existing `.wizard` / `.rdtl-*` /
    `.rab-*` CSS, add `.drab-*` only as needed. This is a deliberate, recorded
    deviation from CLAUDE.md's "all frontend JS in app.js."

11. **True multi-sheet `.xlsx`**, plus a printable PDF/CSV. The workbook is the real
    deliverable (contractors negotiate on it). Implemented as a **self-contained,
    dependency-free `DrabXlsx` writer** (`api/lib/xlsx_writer.php`, ZipArchive +
    hand-written OOXML) — chosen over vendoring PhpSpreadsheet because the host has no
    Composer/build step and a committed `vendor/` is heavy; same outcome, zero
    dependency. PDF is a printable HTML page (browser "Save as PDF") and is the
    free-tier watermarked/preview output.

12. **Three overlay axes + a 7-step wizard.** **Style = template** (character +
    item composition + `wall_factor`); **Structure system** and **Roof system** are
    independent **overlays** that swap items/coefficients. Authoring is additive
    (styles × structures × roofs as overlays, not a combinatorial explosion of
    templates). Wizard: Style → Structure → Roof → Size & floors → Extras → Finish
    tier → Site & logistics → Review, with a live ballpark in the footer.

13. **Markups.** Preliminaries (*Pekerjaan Persiapan*) is a **generated section**
    with seeded lump-sum lines. Overhead & Profit (BUK), Contingency, and PPN are
    **optional summary-level % lines, OFF by default** — a fresh RAB equals pure
    direct cost like the Villa BOQs; one click adds margin/contingency/tax for
    self-estimates or client quotes.

14. **Development → Buildings → RAB.** A project is a development holding multiple
    buildings (Villa 1 / Villa 2 / Security Post); site/zone/Site-Factor settings
    live at development level (overridable per building); a roll-up totals across
    buildings.

15. **Variations plumbing now, UI later.** v1 ships: a RAB version can be frozen as
    an **issued baseline**; every line carries a **stable `line_id`** surviving
    version clones; a clean version model per building. No diff view or
    work-order/agreement generation yet — but no painful re-ID/snapshot migration
    later.

16. **Optional takeoff sub-rows.** An item's quantity is either a single number or
    the **sum of named takeoff rows** carrying measurement notes ("Back wall: 13 −
    1.2 = 11.8 m²"). Generated items start single-number; "break into takeoff" adds
    per-wall detail. The tool owns the arithmetic, so sums never rot into `#REF!`.

Two cross-cutting choices from review: **(a) ship many styles, sourced honestly.**
v1 ships Tropical Mediterranean (RCC, **Confirmed** from the Villa BOQs) plus Bali
villa, Joglo (timber), Premium bamboo villa, Jakarta/Java city house, and
Local-simple — the non-flagship styles **composed and priced from other-province /
national project data + the Lombok premium**, shipped as full usable styles badged
**Indicative** (`basis = other_province_adjusted`) until real Lombok BOQ data
confirms them. **(b) fully bilingual.** Every catalog item, section, and template
stores `name_en` + `name_id`; the document exports as EN, ID, or both columns; UI
follows the site's language toggle. Currency is always **IDR** (RAB never uses
display-currency conversion).

## Why this and not the alternatives

A **pure flat catalog** was rejected for losing auto-split and recompute; a **pure
AHSP engine** for being too heavy and intimidating for lay users — the hybrid keeps
both audiences. **Extending `rab_*`** was rejected because it entangles the new tool
with the backup it is meant to be compared against, on a live auto-deploying repo.
**Single floor-area multiplication** (`build_rab.py`'s template) was rejected as
unconvincing for walls/foundations/fixtures and unable to model extras. **Discrete
price zones** were rejected for being unable to express "same region, two hours up a
goat track." **Lombok-only data** would have forced joglo/bamboo to be hidden or
faked; **other-province-adjusted Indicative** ships them honestly instead. Building
the **Variations UI now** was rejected as scope creep; ignoring it entirely was
rejected as a guaranteed future migration.

## Consequences

- A one-off **seeding importer** parses the 3 Villa BOQs (→ Confirmed catalog +
  calibrated flagship coefficients), the `Bahan/Upah/Alat/AHSP_*` sheets (→ base
  prices + build-ups, Indicative Mataram), and other-province references (→ the
  remaining styles, `other_province_adjusted`). The Site Factor is derived as
  South-Confirmed ÷ Mataram-Indicative per category.
- Excel export is a self-contained `api/lib/xlsx_writer.php` (`DrabXlsx`) — no
  `vendor/`, no Composer; requires the standard `ext-zip` on the host.
- The old tool is relabelled "Detailed RAB (classic)" so it reads as the backup.
- Authoring N styles × structures × roofs as overlays is real content work; only
  Tropical-Med is Confirmed at launch, the rest improve as Lombok quotes arrive.
- A future reader will find two RAB toolchains (`rab_*` and `drab_*`): the former is
  a deliberately frozen backup, the latter is the live product.
