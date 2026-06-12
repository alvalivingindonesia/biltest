# Build in Lombok

A freemium directory and tooling platform for people building in Lombok: a public
directory of local businesses, a RAB (cost-estimation) tool, and a paid engine that
gathers price quotes from vendors over WhatsApp. This glossary defines the shared
language across the directory and the quote engine.

## Directory

**Vendor**:
The user-facing name for any business listed in the directory — a builder, trade,
professional service, or material supplier. Stored and referenced in code as a Provider.
_Avoid_: Service (the old label), business, listing.

**Provider**:
The data-layer name for a Vendor — the canonical entity behind every directory listing,
referenced everywhere by `provider_id`. "Vendor" is the display label; "Provider" is the
model. Use Provider in schema and code, Vendor only in UI copy.

**Supplier**:
A Vendor in the `suppliers_materials` group — a business that sells building materials.
A subset of Vendors, not a separate entity.
_Avoid_: Store, merchant, shop.

## Listings & map

**Region**:
One of the six *market* regions of Lombok used to group listings: South Lombok, West
Lombok, Central Lombok, East Lombok, North Lombok, Gili Islands. Deliberately NOT the
administrative kabupaten — e.g. Kuta sits in Kabupaten Lombok Tengah but belongs to the
South Lombok Region, because that is how buyers think. Table: `area_regions`.
_Avoid_: kabupaten, regency, district.

**Area**:
A named locality within a Region — the finest location granularity a listing has
(e.g. Kuta, Selong Belanak, Are Guling). Listings carry an `area_key`; they have no
coordinates. Every Area belongs to exactly one Region. Table: `areas`.
_Avoid_: location, zone, suburb.

**Display Currency**:
The currency a visitor chooses to *view* listing and development prices in (IDR, USD,
EUR, AUD). A presentation setting only — it never changes what a listing costs or how
it is stored. Scoped to property listings and developments; materials and RAB prices
are always IDR.

**Price on Request**:
A listing with no price in any currency. Shown without a price and excluded from
results only while a price filter is active.

**Feature Tag**:
A canonical amenity/attribute key on a listing (beachfront, ocean_view, pool,
furnished, cliff_top, rice_field_view, near_airport, …) with EN and ID labels.
Assigned automatically at import by keyword scan and correctable by admins; the only
thing feature filters are allowed to match against. Table: `listing_tags`.
_Avoid_: feature, amenity, keyword (as schema/code names).

## Quote engine

**Get Quotes (manual)**:
The existing free flow: a user lists materials, the site generates a WhatsApp message,
and the user messages suppliers one-by-one by hand. Nothing is captured server-side.
This is the baseline the paid engine automates.

**Quote Request**:
The paid, automated job: one user's request for pricing on a set of materials,
dispatched automatically to multiple Vendors and tracked to completion. The canonical
meaning of "quote" in the automated feature; supersedes the overloaded legacy uses.
Table: `quote_requests`.

**Vendor Chat**:
One Vendor's conversation within a single Quote Request — the per-vendor thread that
carries state (stock status, follow-up flags). One Quote Request has many Vendor Chats;
each Vendor Chat belongs to exactly one Quote Request and one Vendor. Table:
`quote_vendor_chats`.

**Price Point**:
One extracted price observation — material X cost Y IDR, quoted by Vendor Z on date D,
delivery-inclusive or not. Price Points accumulate over time into the historical
material index that informs the RAB tool. Table: `historical_material_prices`.

**Reconciliation**:
The act of linking a Price Point's free-text label to a canonical `rab_materials`
entry, so it can join the global index. Done via a Material Alias (automatic) or by an
admin in the reconciliation queue (manual). Until reconciled, a Price Point is visible
to its own user but excluded from the global index.

**Material Alias**:
A saved mapping from a normalised label ("semen tiga roda") to a catalog material (+
unit). Created once during Reconciliation and reused forever after to auto-link future
Price Points. Table: `material_aliases`.

## Flagged ambiguities

**"Quote" is overloaded — three distinct meanings in this codebase:**
1. A legacy manual *contact log* — a user pressed "WhatsApp this provider" and the site
   recorded that contact (no message capture, no AI).
2. The free *Get Quotes* request builder (manual, client-side only).
3. The new *automated* quote-gathering engine (this project).

_Resolution:_ reserve **Quote Request** for the automated engine (meaning 3). Keep
**Get Quotes** as the name of the free flow (meaning 2). Refer to meaning 1 as the
**contact log**, not a "quote". Do not introduce a `vendor_id` column that points at
`providers` — the established FK is `provider_id`; "Vendor" is display language only.

## Example dialogue

> **Dev:** When a supplier replies with a price, where does it go?
> **Domain expert:** It becomes a Price Point on the historical index, and it also shows
> up in that Vendor Chat's thread on the user's dashboard.
> **Dev:** "That supplier" — is that a separate thing from a Vendor?
> **Domain expert:** No. A Supplier *is* a Vendor — just one in the materials group. In
> the database it's a Provider either way.
> **Dev:** And one Quote Request can hit several of them?
> **Domain expert:** Right — one Quote Request, many Vendor Chats, one per Vendor. The
> items live on the Request; every Vendor gets asked about the same list.
