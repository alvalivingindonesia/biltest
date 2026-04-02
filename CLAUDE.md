# CLAUDE.md — Build in Lombok (biltest)

## Project Overview
**Site:** https://biltest.roving-i.com.au  
**Repo:** git@github.com:alvalivingindonesia/biltest.git  
**Owner:** Jon (alvalivingindonesia@gmail.com)  
**OS:** Windows (Git Bash / Claude Code terminal)  
**Server:** Shared hosting (HostPapa) — PHP 7.4+, MySQL/MariaDB  
**Stack:** Vanilla PHP (no framework), single-page JS frontend, MySQL  

## Business Philosophy — GROW FIRST, MONETISE SMART
Revenue must never come at the cost of user acquisition. The free tier must be genuinely useful — users who get real value from the site become paying customers, advocates, and repeat visitors.

**Priority order:**
1. **Acquire** — Free tools must be good enough that users want to come back and tell others
2. **Engage** — Build habit and trust through useful free features (search, directory, basic RAB, guides)
3. **Convert** — Make upgrade paths clear, compelling and natural — never frustrating
4. **Retain** — Keep paying subscribers renewing through ongoing premium value

**The rule:** If a free user can't accomplish anything meaningful, they leave forever. If they can accomplish most things but hit a clear, fair ceiling — they upgrade.

## Subscription Tiers (Revenue Engine)
| Tier | Period options | Access |
|------|---------------|--------|
| Free | — | Genuinely useful core features — enough to build habit and trust |
| Basic | Monthly / Annual / Lifetime | Mid-tier power features |
| Premium | Monthly / Annual / Lifetime | Full access to all tools |

Feature gating is controlled via the `feature_access` DB table — toggled in Admin Console.  
**Free must be genuinely useful. Gate power features, not basic value.**

## File Structure
```
/                        ← Web root
├── index.html           ← SPA shell
├── app.js               ← All frontend JS (SPA routing, rendering, API calls)
├── style.css            ← Main styles
├── base.css             ← Reset / base styles
├── api/
│   ├── index.php        ← Public API (providers, developers, projects, guides, search)
│   ├── user.php         ← Auth API (login, register, profile, quotes, favourites)
│   └── rab_api.php      ← RAB (Bill of Quantities) API
├── admin/
│   ├── console.php      ← Full admin panel (users, subscriptions, listings, lookups)
│   └── rab_tool.php     ← RAB admin tool
├── .gitignore           ← /config/, /database/, /input data/ are excluded
```

Config lives OUTSIDE web root at `/home/rovin629/config/biltest_config.php` — never commit credentials.

## Key Entities
- **Providers** — Builders, architects, suppliers etc. (core directory)
- **Developers** — Property developers
- **Agents** — Real estate agents
- **Projects** — Property development projects
- **Listings** — Property listings (sale/rent)
- **Guides** — Blog/editorial content (SEO & trust building)
- **RAB** — Bill of Quantities / cost estimation tool (key upgrade driver)

## Database Conventions
- All IDs: `int UNSIGNED`
- Slugs: `varchar(150/200)` — used in URLs
- Timestamps: `timestamp` with `DEFAULT CURRENT_TIMESTAMP`
- Soft deletes via `is_active` flag — never hard delete records
- Use PDO prepared statements — NO raw string interpolation in SQL
- Currency stored as IDR (bigint), USD/EUR/AUD as int

## Coding Standards
- **PHP:** PDO only, prepared statements always, `json_out()` / `json_error()` helpers
- **JS:** Vanilla JS, no frameworks, `UserAuth.apiCall()` for authenticated requests
- **CSS:** CSS custom properties (variables) for all colours/spacing — see `base.css`
- **No build step** — files are served directly, no webpack/npm in production
- Keep PHP files compatible with PHP 7.4 (no named args, no enums, no fibers)
- Always use `htmlspecialchars()` / `escHtml()` when rendering user content

## API Patterns
```php
// GET endpoint
GET /api/index.php?action=providers&page=1&per_page=20

// POST endpoint (JSON body)
POST /api/user.php?action=save_quote
Content-Type: application/json

// Auth check
$uid = require_auth(); // throws 401 if not logged in

// Feature gate
$access = check_feature_access('rab_tool', $uid);
if (!$access['allowed']) json_error(403, 'upgrade_required');
```

## Freemium Development Rules
1. **Free must be genuinely useful** — directory search, provider browsing, basic guides, and the RAB calculator are free. Users must accomplish real tasks without paying.
2. **Gate thoughtfully, not aggressively** — use the `feature_access` table, never hardcode gates. Ask: "Would a frustrated free user leave the site entirely?" If yes, don't gate it.
3. **Every premium feature needs a free version** — e.g. RAB quick calculator (free) → full RAB project management with saved versions and exports (premium). Show the value before the wall.
4. **Upgrade prompts must sell the benefit** — never show a raw error. Show what they'd unlock, what it costs, and a clear CTA. Make it feel like an opportunity, not a punishment.
5. **WhatsApp CTAs are free value** — always surface WA/contact buttons for providers and agents. This builds trust and drives listing signups.
6. **SEO & guides drive the top of funnel** — clean markup, quality content, structured data. Free organic traffic = free user acquisition.
7. **Account creation is the first conversion** — nudge registration naturally (save searches, favourites, quote history). Never force it for basic browsing.
8. **RAB Tool is the primary upgrade hook** — basic estimation free, full project management premium. It's the clearest demonstration of paid value.
9. **If a gate causes drop-off instead of upgrades, loosen it** — acquisition always beats aggressive monetisation at this stage.

## Git Workflow
```bash
# Always pull before editing
git pull origin main

# After changes
git add -A
git commit -m "Brief description of change and business reason"
git push origin main
```

## Deployment
- Push to `main` branch on GitHub
- Manual deploy: copy changed files to server via FTP/SSH (HostPapa shared hosting)
- No CI/CD pipeline yet — changes go live on manual upload
- Test at: https://biltest.roving-i.com.au

## Admin Access
- Admin panel: https://biltest.roving-i.com.au/admin/console.php
- Subscription management: `?s=subscriptions`
- Feature access controls: `?s=feature_access`

## Do Not Touch
- `/config/` — credentials, never edit or commit
- `/database/` — local DB dumps, gitignored
- `.gitignore` entries — keep them as-is

## Current Focus Areas (as of setup)
- Quote tracking feature (recently added in last commit)
- RAB tool improvements
- Subscription conversion optimisation
- Property listings module
