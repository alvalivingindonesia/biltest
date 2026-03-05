# Build in Lombok

A directory web app connecting foreign investors and builders with trusted local contractors, architects, engineers, developers, and projects across Lombok, Indonesia.

## How to Run

Open `index.html` in a browser — no build step required. All assets are static and loaded via CDN or relative paths.

For local development with proper routing, serve from a simple static server:

```bash
# Python 3
python -m http.server 8000

# Node (npx)
npx serve .
```

Then open `http://localhost:8000`.

## File Structure

```
build-in-lombok/
├── index.html        ← Entry point, semantic HTML shell with header/footer
├── base.css          ← Reset, antialiasing, and typographic defaults
├── style.css         ← All design tokens (colors, spacing, type scale) + component styles
├── app.js            ← All application logic: data models, routing, filtering, rendering
└── README.md
```

## Architecture

Single-page application with hash-based routing. All JS and data are embedded in `app.js`.

### Routes

| Hash | Page |
|------|------|
| `#home` | Hero + featured listings |
| `#directory` | Service providers with filters |
| `#provider/:slug` | Individual provider detail |
| `#developers` | Developer listing |
| `#developer/:slug` | Developer detail with linked projects |
| `#projects` | Projects listing with filters |
| `#project/:slug` | Project detail |
| `#guides` | Guide index |
| `#guide/:slug` | Guide article |

Filter state is encoded in hash query params (e.g. `#directory?area=kuta&group=builder_tukang`) so URLs are shareable.

## Data Models

All data lives as plain JS arrays at the top of `app.js`:

- **`businesses`** — Service providers (builders, tukangs, architects, specialists)
- **`developers`** — Property developers
- **`projects`** — Investment projects linked to developers via `developer_id`
- **`guides`** — Static guide articles (title, content, category)

### Adding New Listings

To add a provider, push a new object into the `businesses` array in `app.js`:

```javascript
businesses.push({
  id: 99, name: "My Company", slug: "my-company",
  group: "builder_tukang",           // builder_tukang | professional_service | specialist_supplier
  category: "general_contractor",     // see category options in app.js
  area: "kuta",                       // kuta | senggigi | mataram | selong_belanak | ekas | other_lombok
  short_description_en: "Short desc",
  description_en: "Full description",
  address: "Jl. Example No. 1",
  google_rating: 4.5, google_review_count: 67,
  phone: "+62 370 123456", whatsapp_number: "6281234567890",
  website_url: null,
  languages: "Bahasa + English",     // "Bahasa only" | "Bahasa + English" | "Bahasa + English + Other"
  tags: ["Tag1", "Tag2"],
  is_featured: false, badge: null, is_active: true
});
```

Developer and project entries follow the same pattern — see the data declarations in `app.js`.

## Design System

### Fonts
- **Display:** Instrument Serif (loaded from Google Fonts)
- **Body:** Work Sans (loaded from Google Fonts)

### Colors
Custom warm sandy beige palette with ocean teal primary accent. CSS variables defined in `style.css` under `:root, [data-theme="light"]` and `[data-theme="dark"]`. Light and dark mode are both fully supported.

### Key Variables
- `--color-primary`: Ocean teal — `#0c7c84`
- `--color-star`: Google star gold — `#f59e0b`
- `--color-whatsapp`: WhatsApp green — `#25d366`
- `--font-display`: Instrument Serif
- `--font-body`: Work Sans

## Trust Signal: Google Ratings

Every card and detail page renders the Google rating as gold stars + numeric rating + review count. The default sort order uses a confidence-weighted score (`rating × log(review_count + 1)`) that surfaces well-reviewed providers over those with few reviews at a similar rating.

## Extending the App

- **Backend/form submissions:** Use the `cgi-bin/` pattern with a Python CGI script for any server-side logic (contact forms, lead capture)
- **More data:** Add entries directly to the JS arrays or replace them with a `fetch()` call to a JSON API
- **Search:** Global search in the header covers all entity types, grouped by category
