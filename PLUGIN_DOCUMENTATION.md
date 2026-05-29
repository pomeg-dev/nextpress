# Nextpress Plugin - Technical Documentation

**Version:** 2.02
**Namespace:** `nextpress`
**Author:** Pomegranate
**Last audited:** 2026-05-29

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [File Tree & Module Map](#3-file-tree--module-map)
4. [Boot Sequence & Autoloading](#4-boot-sequence--autoloading)
5. [Core Services](#5-core-services)
6. [REST API Layer](#6-rest-api-layer)
7. [Admin Layer](#7-admin-layer)
8. [Gutenberg Integration](#8-gutenberg-integration)
9. [Extension System](#9-extension-system)
10. [User Flow Module (Disabled)](#10-user-flow-module-disabled)
11. [Caching Architecture](#11-caching-architecture)
12. [Data Flow Diagrams](#12-data-flow-diagrams)
13. [Filter & Action Reference](#13-filter--action-reference)
14. [Security Audit](#14-security-audit)
15. [Performance Audit](#15-performance-audit)
16. [OOP & Architecture Audit](#16-oop--architecture-audit)
17. [Recommendations](#17-recommendations)

---

## 1. Executive Summary

Nextpress is a WordPress plugin that transforms WordPress into a **headless CMS** for Next.js frontends. It:

- Exposes custom REST API endpoints (`/nextpress/*`) that serve WordPress content as structured JSON optimised for Next.js consumption
- Dynamically registers ACF Gutenberg blocks by fetching block definitions from a Next.js `/api/blocks` endpoint
- Provides iframe-based block previews inside the WordPress editor that render via the Next.js frontend
- Handles URL redirects from WordPress frontend to Next.js, including preview links and draft mode
- Integrates with ACF, Yoast SEO, Gravity Forms, and Polylang
- Implements a Redis-aware caching layer with circuit breakers and rate limiting
- Includes a disabled JWT-based user authentication flow

---

## 2. Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                    nextpress.php                         │
│              (Entry point + Autoloader)                  │
│                         │                                │
│                    new Init()                            │
└─────────────┬───────────────────────────────────────────┘
              │
    ┌─────────┴─────────┐
    │     Helpers        │ ← Injected as dependency into most classes
    │  (Cache, URLs,     │
    │   Revalidation)    │
    └────────┬───────────┘
             │
   ┌─────────┼───────────────────────────────────┐
   │         │                                   │
   ▼         ▼                                   ▼
┌──────┐  ┌──────────┐  ┌───────────┐  ┌──────────────┐
│ API  │  │  Admin   │  │ Gutenberg │  │  Extensions  │
│ Layer│  │  Layer   │  │   Layer   │  │    Layer     │
└──────┘  └──────────┘  └───────────┘  └──────────────┘
```

**Dependency injection pattern:** `Helpers` is instantiated once in `Init` and passed by constructor to most classes. Some classes (`Ext_ACF`, `Ext_Yoast`, `Ext_GravityForms`, `Register_Pages`) operate independently without `Helpers`.

---

## 3. File Tree & Module Map

```
nextpress/
├── nextpress.php              # Entry point, autoloader, Init bootstrap
├── index.php                  # Silence-is-golden guard
├── LICENSE
├── class/
│   ├── init.php               # Init class - wires all modules
│   ├── helpers.php            # Helpers - URLs, cache delegation, revalidation, Polylang
│   ├── cache.php              # Cache - Redis/transient abstraction
│   ├── api/
│   │   ├── api-router.php     # /router endpoint (slug → post resolution)
│   │   ├── api-posts.php      # /posts, /tax_list, /tax_term endpoints
│   │   ├── api-settings.php   # /settings endpoint
│   │   ├── api-menus.php      # /menus endpoint
│   │   ├── api-theme.php      # /theme, /block_theme endpoints
│   │   └── post-formatter.php # Post_Formatter - transforms WP_Post → JSON
│   ├── admin/
│   │   ├── register-pages.php      # ACF options pages (Settings, Templates)
│   │   ├── register-settings.php   # ACF field groups for settings
│   │   ├── register-templates.php  # ACF flexible content templates
│   │   ├── fix-autoload-transients.php  # Admin tool for DB cleanup
│   │   └── url-handlers.php        # Frontend redirects + preview links
│   ├── gutenberg/
│   │   ├── register-blocks.php     # Dynamic ACF block registration + preview render
│   │   └── field-builder.php       # Maps API field definitions → ACF fields
│   ├── extensions/
│   │   ├── ext-acf.php             # ACF data enrichment + media reduction
│   │   ├── ext-yoast.php           # Yoast SEO meta + redirect handling
│   │   └── ext-gravityforms.php    # Gravity Forms data injection
│   └── user-flow/
│       └── user-flow.php           # JWT auth (DISABLED in Init)
├── assets/
│   ├── js/block-preview.js    # Editor iframe preview manager
│   └── css/block-preview.css  # Preview loading styles
└── includes/
    ├── php-jwt/               # Firebase JWT library (vendored)
    ├── plugin-update-checker/ # GitHub-based auto-update (vendored)
    └── acf-builder/           # StoutLogic ACF Builder (vendored)
```

---

## 4. Boot Sequence & Autoloading

### Autoloader (`nextpress.php:26-57`)

Uses a **static classmap** rather than filesystem scanning. All 18 plugin classes are mapped explicitly:

```php
static $class_map = [
    'nextpress\\init'          => '/class/init.php',
    'nextpress\\helpers'       => '/class/helpers.php',
    // ... 16 more entries
];
```

Key: `strtolower($class)` ensures case-insensitive resolution.

### Init Sequence (`init.php`)

```
1. plugin_update_checker()  → loads vendored PUC, configures GitHub repo
2. require acf-builder       → StoutLogic autoload
3. new Helpers()             → Cache, Polylang init, query monitoring
4. new Register_Pages()      → ACF options pages
5. new Register_Settings()   → ACF field groups (fetches blocks API here!)
6. new Register_Templates()  → Flexible content templates
7. new Fix_Autoload_Transients()
8. new API_Router()          → /router endpoint
9. new API_Settings()        → /settings endpoint
10. new API_Posts()           → /posts endpoint
11. new API_Menus()           → /menus endpoint
12. new API_Theme()           → /theme endpoint
13. new Ext_ACF()
14. new Ext_Yoast()
15. new Ext_GravityForms()
16. new Register_Blocks()     → Dynamic block registration
17. new URL_Handlers()        → Frontend redirects
```

**Critical note:** `Register_Settings` calls `fetch_blocks_from_api()` in its constructor (during `__construct` → `build_settings()`), which makes an HTTP request to the Next.js frontend on every admin page load where ACF initialises. This is mitigated by caching (12-hour TTL) and the circuit breaker.

---

## 5. Core Services

### Helpers (`class/helpers.php`)

The central service object. Responsibilities:

| Responsibility | Methods |
|---|---|
| **Frontend URL resolution** | `get_frontend_url()`, `get_docker_url()`, `get_frontend_url_public()` |
| **API URL construction** | `get_api_url()`, `get_blocks_url()` |
| **Block fetching** | `fetch_blocks_from_api($theme, $source)` |
| **Cache delegation** | `cache_set()`, `cache_get()`, `cache_delete()`, `cache_flush_group()` |
| **Next.js revalidation** | `revalidate_fetch_route($tag)`, `revalidate_specific_path($path)` |
| **Polylang** | `init_polylang()`, `$languages`, `$default_language` |
| **Save guards** | `should_skip_save($post_id)` |
| **Homepage** | `get_homepage()` |
| **Cache clear** | `clear_wp_cache()` |
| **Query monitoring** | `maybe_enable_query_monitoring()`, `log_slow_queries_for_rest_request()` |

**Frontend URL resolution order:**
1. ACF option `frontend_url` (via `get_field`)
2. WP option `options_frontend_url` (fallback)
3. Docker URL probe (`host.docker.internal:3000`, cached 60s)
4. Localhost fallback (`http://localhost:3000`)

**Block API circuit breaker:**
- Rate limited to 3 requests per 30 seconds
- After 3 consecutive failures, circuit breaker activates for 300 seconds
- Blocks response cached for 12 hours (filterable via `nextpress_blocks_cache_ttl`)

### Cache (`class/cache.php`)

Two-tier caching strategy:

```
wp_using_ext_object_cache() === true?
  ├── YES → wp_cache_set/get/delete/flush_group (Redis/Memcached)
  └── NO  → set_transient() + UPDATE autoload='no' (prevents options table bloat)
```

`flush_group()` for transients uses raw SQL `DELETE FROM wp_options WHERE option_name LIKE '{group}_%'`.

### Post_Formatter (`class/api/post-formatter.php`)

Transforms `WP_Post` objects into the JSON structure consumed by Next.js:

```json
{
  "id": 123,
  "slug": { "slug": "my-post", "full_path": "/blog/my-post" },
  "type": { "id": "post", "name": "Post", "slug": "blog" },
  "status": "publish",
  "date": "2024-01-01 00:00:00",
  "title": "My Post",
  "excerpt": "...",
  "image": { "full": "...", "thumbnail": "..." },
  "categories": [{ "id": 1, "name": "News", "slug": "news" }],
  "tags": [...],
  "password": "",
  "template": { "before_content": [...], "after_content": [...], "sidebar_content": [...] },
  "content": [/* parsed Gutenberg blocks */],
  "featured_image": { "url": "...", "sizes": {...} },
  "author": "John Doe",
  "is_homepage": false,
  "category_names": ["News"],
  "terms": { "custom_tax": ["Term 1"] },
  "path": "/blog/my-post",
  "wordpress_path": "https://...",
  "breadcrumbs": "<nav>...</nav>",
  "acf_data": {...},           // Added by Ext_ACF filter
  "yoastHeadJSON": {...},      // Added by Ext_Yoast filter
  "language": "en",            // Added if Polylang active
  "languages": {...},
  "translations": {...}
}
```

**Block parsing pipeline:**
1. `parse_block_data()` → calls `parse_blocks()` (WP core) → `format_blocks()`
2. `format_blocks()` recursively processes nested blocks, resolves `core/block` (reusable patterns)
3. Each block passes through `nextpress_block_data` filter (ACF reformatting, GF injection, nav replacement)

---

## 6. REST API Layer

All routes registered under the `nextpress` namespace. **All endpoints use `permission_callback => '__return_true'`** (public, unauthenticated access).

### Route Map

| Endpoint | Method | Class | Description |
|---|---|---|---|
| `/nextpress/router/{path?}` | GET | `API_Router` | Resolve path/ID to formatted post |
| `/nextpress/posts` | GET | `API_Posts` | Query posts with WP_Query params |
| `/nextpress/tax_list/{taxonomy}` | GET | `API_Posts` | List taxonomy terms |
| `/nextpress/tax_term/{taxonomy}/{term}` | GET | `API_Posts` | Get single term |
| `/nextpress/settings` | GET | `API_Settings` | Site settings + ACF options |
| `/nextpress/menus` | GET | `API_Menus` | All nav menus |
| `/nextpress/menus/{location}` | GET | `API_Menus` | Menu by location |
| `/nextpress/theme` | GET | `API_Theme` | theme.json contents |
| `/nextpress/block_theme` | GET | `API_Theme` | Selected block themes |
| `/nextpress/form/{form_id}` | GET | `Ext_GravityForms` | Gravity Forms form data |

### API_Router (`/router`)

The primary endpoint used by the Next.js `[[...slug]]/page.tsx` catch-all route.

**Resolution order:**
1. If `p` or `page_id` param → direct post lookup
2. If path contains `404` → return 404 filter
3. No path → homepage
4. Path matches `page_for_posts` → blog page
5. Path matches Polylang language slug → translated homepage
6. Otherwise → `url_to_postid()` → `get_post()`

**Cache:** 1 hour TTL in `nextpress_router` group. Invalidated on `save_post`, `delete_post`, `wp_trash_post`, `untrash_post`. Also invalidates related taxonomy/archive paths.

### API_Posts (`/posts`)

Accepts most `WP_Query` parameters directly via query string. Key features:

- **Parameter remapping:** `search` → `s`, `per_page` → `posts_per_page`, `status` → `post_status`, `page` → `paged`
- **Taxonomy filtering:** `filter_{taxonomy}=term_slug` auto-builds `tax_query`
- **Unbounded query cap:** `posts_per_page=-1` capped to 150 (filterable via `nextpress_max_posts_per_page`)
- **N+1 prevention:** Bulk-loads post meta and term caches before formatting
- **`slug_only` mode:** Lightweight query returning only `{ slug, full_path }`
- **`post_type__not_in`:** Custom query modifier via `pre_get_posts` and `posts_where` filters
- **Cache tags:** Optional `cache_tag` param stored in `np_cache_tags` transient for targeted revalidation

### API_Settings (`/settings`)

**Safe option allowlist pattern:** Only whitelisted WP options are exposed (see `get_safe_option_keys()`). This prevents leaking secrets from `wp_options`.

**Enrichment pipeline via `nextpress_settings` filter:**
1. `load_options_without_transients()` → safe WP options
2. `add_acf_to_nextpress_settings()` → merges ACF options page fields
3. `add_yoast_base_to_nextpress_settings()` → merges ALL Yoast settings
4. `format_default_template()` → formats flexible content templates

**Cache:** 1 day TTL. Invalidated on ACF options save, menu item save, and safe WP option updates. Debounced via static `$already_revalidated` flag.

### API_Menus (`/menus`)

Returns menus formatted as:
```json
{
  "id": 2,
  "name": "Main Menu",
  "slug": "main-menu",
  "items": [
    { "id": 45, "title": "Home", "url": "...", "menu_order": 1, "parent": "0" }
  ]
}
```

### API_Theme (`/theme`)

- `/theme` → reads and returns `theme.json` from the active WordPress theme directory via `file_get_contents()`
- `/block_theme` → returns the ACF `blocks_theme` option field

---

## 7. Admin Layer

### Register_Pages

Creates ACF options pages:
- **Nextpress** (top-level menu with SVG icon)
  - **Settings** (sub-page)
  - **Templates** (sub-page, slug: `templates`)

Requires `edit_posts` capability.

### Register_Settings

Builds ACF field groups for the Settings page using StoutLogic ACF Builder:

| Tab | Fields |
|---|---|
| Blocks | `blocks_theme` (select, multi), `frontend_url` (URL) |
| Google Tag Manager | `google_tag_manager_enabled` (true/false), `google_tag_manager_id` (text) |
| Favicon | `favicon` (image) |
| 404 | `page_404` (post object) |
| Coming Soon | `enable_coming_soon` (true/false), `coming_soon_page` (post object) |

**Note:** `build_settings()` is called in the constructor and calls `fetch_blocks_from_api()`, triggering an HTTP request during instantiation.

### Register_Templates

Builds ACF flexible content templates for before/after/sidebar content areas:
- **Default tab** with `default_before_content` and `default_after_content` flexible content fields
- **Per-post-type tabs** with repeater containing `category` (select) + `before_content` / `after_content` / `sidebar_content` flexible content fields
- **Polylang support:** Duplicate fields for each non-default language

Block layouts are populated from `fetch_blocks_from_api()` and built using `Field_Builder`.

### Fix_Autoload_Transients

Admin tool page (under Tools menu) that:
1. Displays count and size of incorrectly autoloaded transients
2. Shows top 20 largest offenders with type identification
3. One-click fix: `UPDATE wp_options SET autoload='no' WHERE option_name LIKE '_transient_%'`

Properly nonce-protected.

### URL_Handlers

**Frontend redirect (`template_redirect`):**
1. Check Yoast premium redirects → 301 to frontend URL
2. Handle `page_id` / `p` query params → redirect to `/api/draft?secret=<token>&id=...`
3. Skip `wp-admin`, `wp-login`, `index.php`
4. Everything else → 301 to frontend URL

**Preview links:** Rewrites `preview_post_link` to `{frontend_url}/api/draft?secret=<token>&id={post_id}`

---

## 8. Gutenberg Integration

### Register_Blocks

**Block registration flow:**
1. Fetch block definitions from Next.js `/api/blocks?theme={themes}`
2. For each block definition, create ACF field group using `Field_Builder`
3. Register ACF block type with `acf_register_block_type()`
4. Add theme as block category

**Smart loading:** Only fetches blocks on:
- `post.php`, `post-new.php` (post editor)
- `admin-ajax.php`
- Templates or Settings admin pages
- REST API requests
- Manual override via `?nextpress_register_blocks`

**Block preview rendering (`render_nextpress_block`):**
1. Convert ACF block to block comment string
2. Parse through `Post_Formatter::parse_block_data()`
3. Resolve inner blocks (from `$block`, `$content`, or saved post content)
4. Handle reusable patterns (`core/block`)
5. Compress data (gzip + base64url)
6. Render iframe pointing to `{frontend_url}/block-preview?content={compressed}`
7. Register with `NextPressBlockManager` JS for lifecycle management

**Block preview JS (`block-preview.js`):**
- Singleton `NextPressBlockManager` manages all iframe instances
- Content-hash-based change detection prevents unnecessary reloads
- Debounced reload (300ms) with visibility awareness
- ACF V3 event listeners: `append`, `remove`, `sortstop`
- `postMessage` API for dynamic height adjustment from Next.js iframe

### Field_Builder

Maps Next.js block field definitions to ACF field types. Supports 25+ field types including:
- Standard: text, textarea, number, email, url, wysiwyg, image, file, gallery
- Choice: select, checkbox, radio, true_false
- Relational: post_object, page_link, relationship, taxonomy, user
- Layout: repeater, group, flexible_content, tab, accordion
- Custom: `nav` (menus select), `post_type` (CPT select), `tax_list` (taxonomy select), `theme` (nextpress themes), `gravity_form` (GF select), `inner_blocks` (checkbox)

Recursive for nested repeaters, groups, and flexible content layouts.

---

## 9. Extension System

Extensions hook into the `nextpress_post_object`, `nextpress_block_data`, and other filters to enrich data.

### Ext_ACF

- **`acf/pre_save_block`:** Assigns `nextpress_id` (uniqid) and `anchor` to every ACF block
- **`nextpress_post_object`:** Appends `acf_data` (all ACF fields for the post) to output
- **`nextpress_block_data`:** Reformats raw ACF block data using `acf_setup_meta()` + `get_fields()` for proper field value resolution
- **Media reduction:** Strips unnecessary image size data (medium_large, 1536x1536, 2048x2048), reduces to essential fields only
- **Nav replacement:** Detects `{{nav_id-{id}}}` placeholders in block data and replaces with full menu item arrays
- **SVG dimensions:** Adds `dimensions` REST field to attachments for SVG viewBox parsing (with XXE protection)

### Ext_Yoast

- **`nextpress_post_object_w_meta`:** Appends `yoastHeadJSON` using Yoast Meta_Surface API
- **`nextpress_term_object`:** Same for taxonomy terms
- **`nextpress_post_not_found`:** Checks Yoast premium redirects on 404s (plain + regex patterns)
- Regex redirect support with capture group replacement (`$1`, `$2`, etc.)

### Ext_GravityForms

- **`nextpress_block_data`:** Auto-detects `gravity_form` or `*gravity*form*` keys and injects full form data via `GFAPI::get_form()`
- **`nextpress_post_object`:** Same for post-level ACF data
- **`/nextpress/form/{id}`:** Direct form data endpoint

---

## 10. User Flow Module (Disabled)

Currently commented out in `Init`: `// new User_Flow( $this->helpers );`

When enabled, provides JWT-based authentication:

| Endpoint | Method | Description |
|---|---|---|
| `/nextpress/login` | POST | `wp_signon()` + JWT generation (HS256, 7-day expiry) |
| `/nextpress/logout` | GET | Session destruction |
| `/nextpress/register` | POST | User creation with email domain whitelist |
| `/nextpress/request-reset` | POST | Password reset email |
| `/nextpress/reset-password` | POST | Password reset execution |

Uses `JWT_AUTH_SECRET_KEY` constant. Sets CORS headers for cross-origin auth.

---

## 11. Caching Architecture

### Cache Groups & TTLs

| Group | TTL | What's cached |
|---|---|---|
| `nextpress_router` | 1 hour | Router endpoint responses |
| `nextpress_posts` | 1 hour | Posts query responses |
| `nextpress_settings` | 1 day | Settings endpoint responses |
| `nextpress_blocks` | 12 hours | Block definitions from Next.js API |
| `nextpress` | 60s | Docker URL probe |

### Invalidation Triggers

| Event | Cache cleared | Next.js revalidation |
|---|---|---|
| `save_post` | Router group, Posts group | Specific path + archives + taxonomy terms |
| `delete_post` / `wp_trash_post` | Router group, Posts group | Post-type tag or specific post IDs |
| `pre_post_update` | - | Old URL path |
| `acf/options_page/save` | Settings cache + ACF options cache | `settings`, `before_content`, `after_content` |
| `update_option` (safe list) | Settings cache | `settings` |
| Nav menu save | - | `before_content`, `after_content` |

### Revalidation Mechanism

Fires `wp_remote_get()` to `{frontend_url}/api/revalidate?tag={tag}` or `?path={path}` with 1-second timeout (fire-and-forget).

---

## 12. Data Flow Diagrams

### Frontend Page Request
```
Next.js [[...slug]]
       │
       ▼
GET /nextpress/router/{path}
       │
       ├─ Cache HIT? → Return cached JSON
       │
       ├─ Resolve post (url_to_postid / homepage / Polylang)
       │
       ├─ Post_Formatter::format_post()
       │   ├─ Basic fields (title, slug, date, excerpt)
       │   ├─ Featured image (4 sizes)
       │   ├─ Categories, tags, custom taxonomies
       │   ├─ Breadcrumbs (HTML)
       │   ├─ Path computation (draft-safe)
       │   ├─ parse_block_data() → Gutenberg blocks → JSON tree
       │   │   └─ Each block → nextpress_block_data filter
       │   │       ├─ Ext_ACF::reformat_block_data() (ACF field resolution)
       │   │       ├─ Ext_ACF::replace_nav_id_in_data() (menu injection)
       │   │       └─ Ext_GravityForms::include_gf_data() (form injection)
       │   ├─ Template content (before/after/sidebar from ACF options)
       │   ├─ Polylang translations
       │   └─ nextpress_post_object filter
       │       ├─ Ext_ACF::include_acf_data() (post-level ACF)
       │       └─ nextpress_post_object_w_meta filter
       │           └─ Ext_Yoast::include_yoast_post_data() (SEO meta)
       │
       └─ Cache SET → Return JSON
```

### Block Registration Flow
```
Admin: post.php / post-new.php
       │
       ▼
Register_Blocks::register_nextpress_blocks()
       │
       ├─ get_field('blocks_theme') → selected themes
       │
       ├─ Helpers::fetch_blocks_from_api(themes)
       │   ├─ Cache HIT? → return cached
       │   ├─ Rate limit check (3/30s)
       │   ├─ Circuit breaker check
       │   ├─ GET {frontend_url}/api/blocks?theme={themes}
       │   └─ Cache SET (12h TTL)
       │
       ├─ For each block:
       │   ├─ Field_Builder::build(fields) → ACF field group
       │   ├─ acf_add_local_field_group()
       │   └─ acf_register_block_type()
       │
       └─ Editor renders blocks → render_nextpress_block()
           ├─ Convert block → comment string → parse_block_data()
           ├─ Resolve inner blocks (3 strategies)
           ├─ Compress data (gzip+base64url)
           └─ Output iframe → {frontend_url}/block-preview?content=...
```

---

## 13. Filter & Action Reference

### Filters

| Filter | Location | Purpose |
|---|---|---|
| `nextpress_path` | API_Router | Modify path before resolution |
| `nextpress_post_not_found` | API_Router | Customise 404 response |
| `nextpress_router_cache_ttl` | API_Router | Router cache duration |
| `nextpress_posts_cache_ttl` | API_Posts | Posts cache duration |
| `nextpress_max_posts_per_page` | API_Posts | Cap for unbounded queries (default: 150) |
| `nextpress_settings` | API_Settings | Enrich settings output |
| `nextpress_safe_option_keys` | API_Settings | Extend safe option allowlist |
| `nextpress_general_settings` | Register_Settings | Add ACF settings tabs |
| `nextpress_post_object` | Post_Formatter | Modify formatted post (all posts) |
| `nextpress_post_object_w_meta` | Post_Formatter | Modify formatted post (when metadata included) |
| `nextpress_block_data` | Post_Formatter | Modify individual block data |
| `nextpress_block_layouts` | Register_Templates | Modify template block layouts |
| `nextpress_breadcrumbs` | Post_Formatter | Modify breadcrumb output |
| `nextpress_term_object` | API_Posts | Modify term output |
| `nextpress_theme_json` | API_Theme | Modify theme.json output |
| `np_block_theme` | API_Theme | Modify block theme response |
| `nextpress_blocks_cache_ttl` | Helpers | Block cache duration |
| `nextpress_enable_query_monitoring` | Helpers | Enable slow query logging |
| `nextpress_slow_query_threshold` | Helpers | Slow query threshold (default: 1.0s) |

### Actions

| Action | Location | Purpose |
|---|---|---|
| `acf/pre_save_block` | Ext_ACF | Auto-assign nextpress_id and anchor |

---

## 14. Security Audit

### CRITICAL Issues

| ID | Severity | File | Line(s) | Issue | Description |
|---|---|---|---|---|---|
| S1 | **CRITICAL** | `api-settings.php` | 180-185 | **Yoast settings data leak** | `add_yoast_base_to_nextpress_settings()` calls `WPSEO_Options::get_all()` and merges ALL Yoast settings into the public API response. This can expose internal configuration, API keys, or sensitive metadata. Should filter to only safe Yoast keys. |
| S2 | **HIGH** | `url-handlers.php` | 60-64 | **Hardcoded draft secret** | Preview/draft URLs use literal `<token>` as the secret: `/api/draft?secret=<token>&id=...`. This is either a placeholder never replaced (broken draft mode) or the actual secret is leaked. Should use a real secret stored as a WP option or constant. |
| S3 | **HIGH** | `user-flow.php` | 248 | **JWT secret dependency** | `JWT_AUTH_SECRET_KEY` constant must exist but is never defined by the plugin. If undefined, JWT operations will fatal error. No validation or helpful error message. |
| S4 | **MEDIUM** | `user-flow.php` | 197 | **Password sanitisation** | `sanitize_text_field()` on passwords strips characters, potentially preventing users from using special characters in passwords. Should use raw input for `wp_signon()`. |
| S5 | **MEDIUM** | `user-flow.php` | 383-406 | **JWT in query string** | Login redirect passes JWT tokens via URL query parameters (`?token=...`), which are logged in server access logs, browser history, and referrer headers. |
| S6 | **MEDIUM** | `api-posts.php` | 221-265 | **Unrestricted WP_Query params** | `prepare_query_args()` passes nearly all request parameters directly to `WP_Query`. While `post_status` defaults to `publish`, a caller can pass `post_status=private` or `post_status=draft` to access non-public content. |
| S7 | **LOW** | `helpers.php` | 311 | **Cache clear via GET** | `?clear` query param triggers full cache flush. Protected by `manage_options` capability check, but should ideally use POST + nonce for destructive actions. |
| S8 | **LOW** | `ext-acf.php` | 214-215 | **Deprecated function** | `libxml_disable_entity_loader()` is deprecated in PHP 8.0+ and will emit warnings. The XXE protection intent is correct but needs a PHP version check. |
| S9 | **LOW** | `helpers.php` | 335 | **Debug queries via GET** | `?nextpress_debug_queries=1` enables query monitoring and adds timing headers. Protected by `manage_options` but could leak performance data. |

### Positive Security Patterns

- Safe option allowlist in `API_Settings::get_safe_option_keys()` - good defence against option enumeration
- Nonce verification in `Fix_Autoload_Transients`
- Taxonomy/term values sanitised with `sanitize_text_field()`
- XXE protection attempt in SVG parsing
- Circuit breaker prevents unlimited outbound requests
- Direct DB queries use `$wpdb->prepare()` consistently
- `ABSPATH` check on every file

---

## 15. Performance Audit

### Issues

| ID | Severity | File | Line(s) | Issue | Description |
|---|---|---|---|---|---|
| P1 | **HIGH** | `register-settings.php` | 46 | **HTTP request in constructor** | `build_settings()` calls `fetch_blocks_from_api()` during class construction. On cache miss, this blocks the admin page load with a 20-second timeout HTTP request. Should be deferred to the `acf/init` hook. |
| P2 | **HIGH** | `api-router.php` | 148 | **Full group flush on every save** | `invalidate_router_cache()` calls `cache_flush_group('nextpress_router')` which deletes ALL router cache entries on every post save. With many cached routes, this causes a thundering herd on the next requests. |
| P3 | **HIGH** | `api-posts.php` | 378 | **Full group flush on every save** | Same issue - `invalidate_posts_cache()` flushes the entire `nextpress_posts` cache group on every post save. |
| P4 | **MEDIUM** | `post-formatter.php` | 309-327 | **N+1 on custom taxonomies** | `include_tax_terms()` calls `get_the_terms()` for every custom taxonomy on every post. When formatting lists of posts, this can generate dozens of DB queries. The bulk-load in `API_Posts` only covers object taxonomies for the specific post types in the result set. |
| P5 | **MEDIUM** | `post-formatter.php` | 351-379 | **Breadcrumb DB queries** | `include_breadcrumbs()` traverses the full parent chain with individual `get_post()` calls per ancestor. For deeply nested pages, this generates N additional queries. |
| P6 | **MEDIUM** | `register-blocks.php` | 175-186 | **Duplicate category registration** | `block_categories_all` filter is added inside a `foreach` loop, meaning it runs once per block. Each invocation appends the same theme category, creating duplicates (though WP may deduplicate). |
| P7 | **MEDIUM** | `register-blocks.php` | 607 | **Deprecated `mt_srand`** | `mt_srand()` + `mt_rand()` are being used to seed icon selection. This modifies global PRNG state, which could affect other code using `mt_rand()`. |
| P8 | **LOW** | `post-formatter.php` | 98-107 | **Duplicate image URL calls** | `get_post_image()` fetches `full` and `thumbnail` sizes, then `include_featured_image()` fetches `full`, `thumbnail`, `medium`, and `large` again for the same thumbnail ID. These are cached by WP's metadata cache but the data structure is redundant. |
| P9 | **LOW** | `api-theme.php` | 50 | **file_get_contents on every request** | `get_theme_json()` reads theme.json from disk on every API call. Should be cached. |

### Positive Performance Patterns

- N+1 prevention with `update_meta_cache()` and `update_object_term_cache()` in API_Posts
- `no_found_rows` and disabled meta/term cache for `slug_only` mode
- Redis-aware caching with transient fallback
- Circuit breaker prevents cascading failures
- `autoload='no'` enforcement for transients
- Query monitoring infrastructure with configurable slow query threshold
- Unbounded query cap (150 default)

---

## 16. OOP & Architecture Audit

### Issues

| ID | Severity | Issue | Description |
|---|---|---|---|
| A1 | **HIGH** | **No interfaces or contracts** | Zero interfaces in the entire codebase. `Helpers` is injected everywhere but has no interface, making it impossible to mock for testing or swap implementations. |
| A2 | **HIGH** | **God object: Helpers** | `Helpers` handles URLs, caching, revalidation, Polylang, homepage lookup, save guards, cache clearing, and query monitoring. Should be split into focused services. |
| A3 | **HIGH** | **No dependency injection container** | All wiring is manual in `Init::__construct()`. Adding/removing modules requires editing `Init`. No way to conditionally load modules. |
| A4 | **MEDIUM** | **Public properties everywhere** | Nearly every class property is `public` (e.g., `$helpers`, `$formatter`, `$settings`, `$templates`). Should be `private` or `protected` with accessors only where needed. |
| A5 | **MEDIUM** | **Multiple Post_Formatter instances** | `API_Router`, `API_Posts`, `API_Settings`, and `Register_Blocks` each create their own `new Post_Formatter()`. Should be a shared singleton or injected. |
| A6 | **MEDIUM** | **Constructor side effects** | Most constructors immediately hook into WordPress actions/filters. This makes classes impossible to instantiate without triggering side effects, complicating testing. |
| A7 | **MEDIUM** | **Mixed responsibilities in Post_Formatter** | Handles post formatting, block parsing, flexible content formatting, breadcrumb generation, slug resolution, and revision handling. Should be decomposed. |
| A8 | **MEDIUM** | **Inconsistent error handling** | Some methods return `false`, some return empty arrays, some return `null`, some return `WP_Error`. No consistent error handling strategy. |
| A9 | **LOW** | **No type hints** | No parameter types, return types, or PHPDoc `@param`/`@return` annotations on most methods. PHP 7.4+ type hints should be used. |
| A10 | **LOW** | **`WP_Error` without namespace** | `api-menus.php:69,84` uses `WP_Error` without leading backslash in a namespaced context. This will throw a fatal error when a menu location is not found. Should be `\WP_Error`. |
| A11 | **LOW** | **Vendored dependencies** | `php-jwt`, `plugin-update-checker`, and `acf-builder` are vendored directly. No Composer autoload. Version management and updates require manual replacement. |
| A12 | **LOW** | **Global function** | `np_dumper()` is a free function in the `nextpress` namespace. Should be a static method or removed for production. |

### Positive OOP Patterns

- Clean namespace usage (`nextpress\*`)
- Static classmap autoloader (fast, no filesystem scanning)
- Dependency injection via constructors (even if inconsistent)
- Single responsibility at the class level (mostly)
- Good use of WordPress filter/action system for extensibility
- Cache service extracted into its own class

---

## 17. Recommendations

### Priority 1 - Security Fixes (Do Now)

1. **Fix Yoast data leak (S1):** Filter `WPSEO_Options::get_all()` to only include safe, public-facing keys (title templates, social URLs, etc.), similar to the `get_safe_option_keys()` pattern.

2. **Fix draft secret (S2):** Replace `<token>` in `url-handlers.php` with a real secret. Add a setting field or use `wp_generate_password()` stored as an option.

3. **Fix WP_Query parameter injection (S6):** Add an allowlist of acceptable `post_status` values in `prepare_query_args()`:
   ```php
   $allowed_statuses = ['publish'];
   if (current_user_can('edit_posts')) {
       $allowed_statuses = array_merge($allowed_statuses, ['draft', 'pending', 'private']);
   }
   ```

4. **Fix `\WP_Error` namespace (A10):** Add leading backslash to `WP_Error` in `api-menus.php:69,84`.

### Priority 2 - Performance Fixes (This Sprint)

5. **Defer settings block fetch (P1):** Move `build_settings()` call from constructor to `acf/init` callback, or lazy-load when the settings page is actually rendered.

6. **Targeted cache invalidation (P2, P3):** Instead of flushing entire cache groups, invalidate only the specific cache keys affected by the changed post. The post's URL, post type, and taxonomy terms are enough to compute the affected keys.

7. **Cache theme.json (P9):** Add transient caching to `get_theme_json()`.

### Priority 3 - Architecture Improvements (Next Quarter)

8. **Introduce interfaces:** Create `CacheInterface`, `FormatterInterface`, `RevalidatorInterface`. This enables testing and alternative implementations.

9. **Split Helpers:** Extract into:
   - `URLResolver` (frontend URL logic)
   - `Cache` (already partially done)
   - `Revalidator` (Next.js revalidation)
   - `LanguageService` (Polylang integration)

10. **Visibility modifiers:** Change all `public` properties to `private`/`protected`. Ensure `$helpers`, `$formatter`, etc. are not accessed externally.

11. **Shared Post_Formatter:** Inject a single instance through the DI chain rather than creating new instances in every API class.

12. **Type hints:** Add PHP 7.4+ parameter and return types to all methods progressively.

### Priority 4 - Housekeeping

13. **Remove `np_dumper()`:** Replace with proper logging or WP_CLI output.

14. **PHP 8.0 compatibility:** Guard `libxml_disable_entity_loader()` with `PHP_VERSION_ID < 80000`.

15. **Add phpstan/psalm:** Static analysis would catch the `WP_Error` namespace bug and other type issues automatically.

16. **Composer migration:** Replace vendored libraries with Composer dependencies for easier updates and autoloading.

---

## Appendix: Quick Reference

### Constants

| Constant | Value | Source |
|---|---|---|
| `NEXTPRESS_PATH` | Plugin directory path (no trailing slash) | `nextpress.php` |
| `NEXTPRESS_URI` | Plugin URL | `nextpress.php` |

### WP Options Used

| Option | Purpose |
|---|---|
| `options_frontend_url` | Frontend URL fallback |
| `page_on_front` | Homepage ID |
| `page_for_posts` | Blog page ID |
| `wpseo-premium-redirects-base` | Yoast premium redirects |
| `blocks_theme` (ACF) | Selected block themes |
| `frontend_url` (ACF) | Frontend URL |

### Cache Keys Pattern

- `nextpress_router_{md5(path_includeContent)}`
- `nextpress_posts_{md5(query_params)}`
- `nextpress_settings_{blog_id}`
- `nextpress_acf_options_{blog_id}`
- `next_blocks_{theme}_{source}` or `next_blocks_{md5(long_key)}`
- `docker_url`
- `blocks_api_requests`
- `blocks_api_circuit_breaker_{url_hash}`
- `blocks_api_failures_{url_hash}`
- `np_cache_tags`
