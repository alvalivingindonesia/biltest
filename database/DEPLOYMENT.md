# Build in Lombok — Database & API Deployment Guide

## Architecture Overview

```
┌─────────────────────────────────────────────┐
│  Browser (app.js)                           │
│  ├── DataLayer checks for API               │
│  ├── If API exists → fetch paginated data   │
│  └── If no API → use embedded static arrays │
└──────────────┬──────────────────────────────┘
               │ GET /api/providers?area=kuta&page=2
               ▼
┌─────────────────────────────────────────────┐
│  PHP API (/api/index.php)                   │
│  ├── Parses request                         │
│  ├── Builds parameterized SQL query         │
│  ├── Returns paginated JSON                 │
│  └── CORS headers for frontend              │
└──────────────┬──────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────┐
│  MySQL / MariaDB                            │
│  ├── providers (FULLTEXT indexed)           │
│  ├── developers                             │
│  ├── projects                               │
│  ├── guides                                 │
│  ├── Lookup tables (areas, categories, etc) │
│  └── Tag junction tables                    │
└─────────────────────────────────────────────┘
```

## Hosting Requirements

Works on any shared host that provides:
- **PHP 7.4+** (8.x preferred)
- **MySQL 5.7+ or MariaDB 10.3+**
- **mod_rewrite** (Apache — standard on cPanel hosts)

Confirmed compatible: HostPapa, Namecheap, Bluehost, SiteGround, A2 Hosting, GoDaddy.


## Step-by-Step Deployment

### 1. Create the database

In your host's **cPanel → MySQL Databases**:

1. Create a new database (e.g., `build_in_lombok`)
2. Create a new database user with a strong password
3. Add the user to the database with **All Privileges**
4. Note down: database name, username, password

### 2. Import the schema

In **cPanel → phpMyAdmin**:

1. Select your database
2. Click the **Import** tab
3. Upload `database/schema.sql`
4. Click **Go**

This creates all tables and lookup data. No listing data yet — that comes next session.

### 3. Upload files

Upload via **cPanel → File Manager** or FTP:

```
public_html/
├── index.html
├── base.css
├── style.css
├── app.js
└── api/
    ├── index.php
    └── .htaccess
```

### 4. Configure the API

Edit `api/index.php` and set your database credentials (lines 18-20):

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_cpanel_prefix_build_in_lombok');
define('DB_USER', 'your_cpanel_prefix_dbuser');
define('DB_PASS', 'your_actual_password');
```

### 5. Connect the frontend

Edit `app.js` and set the API_BASE URL (line 635):

```javascript
const API_BASE = 'https://yourdomain.com/api';
```

### 6. Test

Visit these URLs in your browser:
- `https://yourdomain.com/api/` — should show JSON with API name and version
- `https://yourdomain.com/api/filters` — should show all filter options
- `https://yourdomain.com/api/providers` — should show empty list (no data yet)
- `https://yourdomain.com/` — full site should load


## API Reference

### Providers
```
GET /api/providers
  ?group=builder_tukang
  &category=general_contractor
  &area=kuta
  &featured=1
  &q=search+text
  &tag=Villa+Construction
  &sort=name|google_rating|created_at
  &dir=ASC|DESC
  &page=1
  &per_page=20

GET /api/providers/{slug}
```

### Developers
```
GET /api/developers
  ?area=kuta
  &project_type=villa_complex
  &featured=1
  &q=search+text
  &sort=name|google_rating|min_ticket_usd|created_at
  &dir=ASC|DESC
  &page=1
  &per_page=20

GET /api/developers/{slug}
```

### Projects
```
GET /api/projects
  ?area=kuta
  &type=villa_complex
  &status=under_construction
  &developer_id=1
  &min_invest=100000
  &max_invest=500000
  &featured=1
  &q=search+text
  &sort=name|min_investment_usd|created_at
  &dir=ASC|DESC
  &page=1
  &per_page=20

GET /api/projects/{slug}
```

### Guides
```
GET /api/guides
GET /api/guides/{slug}
```

### Filters (for dropdowns)
```
GET /api/filters
```

### Cross-entity search
```
GET /api/search?q=villa&limit=10
```


## Performance at Scale

The database is designed to handle **1 million+ listings** efficiently:

| Feature | How it scales |
|---------|--------------|
| **Pagination** | Only 20 rows loaded per request (configurable) |
| **FULLTEXT indexes** | MySQL's built-in full-text search — no external search engine needed |
| **Composite indexes** | `(is_featured, is_active)` for the most common query pattern |
| **Indexed filters** | All filter columns (group, category, area, status) have indexes |
| **Tag filtering** | Junction tables with indexed tag columns for fast `EXISTS` subqueries |
| **Investment range** | `min_investment_usd` indexed for range queries |
| **Response caching** | Frontend DataLayer caches API responses in memory |

For reference, MySQL can serve a simple indexed query against 1M rows in **under 10ms** on shared hosting.


## Database Schema (summary)

### Main tables
- `providers` — builders, architects, specialists (15 columns + timestamps)
- `developers` — property development companies
- `projects` — investment projects linked to developers
- `guides` — educational articles/guides

### Lookup tables (populate filter dropdowns)
- `groups` — builder_tukang, architect_engineer, specialist_trade
- `categories` — general_contractor, architect, electrician, etc.
- `areas` — kuta, selong_belanak, senggigi, etc.
- `project_types` — villa_complex, apartment, mixed_use, etc.
- `project_statuses` — planning, under_construction, completed, sold_out

### Junction tables (many-to-many)
- `provider_tags` — tags for providers
- `developer_tags` — tags for developers
- `developer_areas` — which areas a developer operates in
- `developer_project_types` — what project types a developer builds
- `project_tags` — tags for projects

### Shared tables
- `images` — photos for any entity type (polymorphic: provider/developer/project/guide)


## Static Fallback

When `API_BASE` is empty or the API is unreachable, the site automatically falls back to the data embedded in `app.js`. This means:

- The S3-hosted demo continues to work without changes
- Development/testing can happen without a database
- If the API goes down, the site degrades gracefully (shows embedded sample data)

Once you deploy to shared hosting with the database, you can optionally remove the embedded data arrays from `app.js` to reduce file size.
