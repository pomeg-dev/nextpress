# Nextpress Plugin - Technical Documentation

**Version:** 3.0
**Namespace:** `nextpress`
**Author:** Pomegranate
**Last audited:** 2026-09-30

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

---

## 1. Executive Summary

Nextpress is a WordPress plugin that transforms WordPress into a **headless CMS** for Next.js frontends. It:

- Exposes custom REST API endpoints (`/nextpress/*`) that serve WordPress content as structured JSON optimised for Next.js consumption
- Dynamically registers ACF Gutenberg blocks by fetching block definitions from a Next.js `/api/blocks` endpoint
- Provides iframe-based block previews inside the WordPress editor that render via the Next.js frontend
- Handles URL redirects from WordPress frontend to Next.js, including preview links and draft mode
- Integrates with ACF, Yoast SEO, Gravity Forms, and Polylang
- Implements a Redis-aware caching layer with circuit breakers and rate limiting
- Provides an experimental live full-page preview ("page-preview") that renders the whole page in a Next.js canvas from inside the editor, with a `/format` endpoint and an admin compatibility check
- Includes an opt-in (filter-gated) JWT-based user authentication flow

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
│   │   ├── api-editor.php     # /format endpoint (live page-preview, editors only)
│   │   └── post-formatter.php # Post_Formatter - transforms WP_Post → JSON
│   ├── admin/
│   │   ├── register-pages.php      # ACF options pages (Settings, Templates)
│   │   ├── register-settings.php   # ACF field groups for settings
│   │   ├── register-templates.php  # ACF flexible content templates
│   │   ├── register-editor.php     # "Editor" options page (mode + frontend compat check)
│   │   ├── fix-autoload-transients.php  # Admin tool for DB cleanup
│   │   └── url-handlers.php        # Frontend redirects + preview links
│   ├── gutenberg/
│   │   ├── register-blocks.php     # Dynamic ACF block registration + preview render
│   │   ├── page-preview-spike.php  # SPIKE: live full-page canvas bridge (?np_spike=1)
│   │   └── field-builder.php       # Maps API field definitions → ACF fields
│   ├── extensions/
│   │   ├── ext-acf.php             # ACF data enrichment + media reduction
│   │   ├── ext-yoast.php           # Yoast SEO meta + redirect handling
│   │   └── ext-gravityforms.php    # Gravity Forms data injection
│   └── user-flow/
│       └── user-flow.php           # JWT auth (opt-in via nextpress_load_user_flow filter)
├── assets/
│   ├── js/
│   │   ├── block-preview.js        # Editor iframe preview manager (legacy per-block)
│   │   ├── page-editor-entry.js    # "Edit visually" entry button (page-preview mode)
│   │   └── page-preview-spike.js   # SPIKE: live canvas bridge editor
│   └── css/
│       ├── block-preview.css       # Preview loading styles
│       ├── page-editor.css         # Live editor shell styles
│       └── page-editor-blocks.css  # In-canvas block label styles
└── includes/
    ├── php-jwt/               # Firebase JWT library (vendored)
    ├── plugin-update-checker/ # GitHub-based auto-update (vendored)
    └── acf-builder/           # StoutLogic ACF Builder (vendored)
```

---

## 4. Boot Sequence & Autoloading

### Autoloader (`nextpress.php:26-57`)

Uses a **static classmap** rather than filesystem scanning. All 22 plugin classes are mapped explicitly:

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
7. new Register_Editor()     → "Editor" options page (mode + compat check)
8. new Fix_Autoload_Transients()
9. new API_Router()          → /router endpoint
10. new API_Settings()        → /settings endpoint
11. new API_Posts()           → /posts endpoint
12. new API_Menus()           → /menus endpoint
13. new API_Theme()           → /theme endpoint
14. new API_Editor()          → /format endpoint (live page-preview)
15. after_setup_theme hook    → new User_Flow() IF nextpress_load_user_flow filter is true (default false)
16. new Ext_ACF()
17. new Ext_Yoast()
18. new Ext_GravityForms()
19. new Register_Blocks()     → Dynamic block registration
20. new Page_Preview_Spike()  → SPIKE live canvas bridge (only active with ?np_spike=1)
21. new URL_Handlers()        → Frontend redirects
```

**User flow gating:** Unlike the rest of the wiring (which runs immediately in `Init::__construct`), `User_Flow` is instantiated inside an `after_setup_theme` callback and only when `apply_filters( 'nextpress_load_user_flow', false )` returns true. This defers instantiation until the active theme has had a chance to register the filter (the plugin loads before the theme), while staying ahead of `rest_api_init`. Opt in from the theme, e.g. `functions.php`:
```php
add_filter( 'nextpress_load_user_flow', '__return_true' );
```

**Critical note:** `Register_Settings` calls `fetch_blocks_from_api()` in its constructor (during `__construct` → `build_settings()`), which makes an HTTP request to the Next.js frontend on every admin page load where ACF initialises. This is mitigated by caching (12-hour TTL) and the circuit breaker.

---

## 5. Core Services

### Helpers (`class/helpers.php`)

The central service object. Responsibilities:

| Responsibility | Methods |
|---|---|
| **Frontend URL resolution** | `get_frontend_url()`, `get_docker_url()`, `get_frontend_url_public()`, `get_frontend_url_internal()` |
| **API URL construction** | `get_api_url()`, `get_blocks_url()` |
| **Page-preview mode** | `page_editor_mode()`, `is_page_preview_mode()` |
| **Preview tokens** | `preview_secret()`, `preview_secret_is_default()`, `mint_preview_token($post_id)` |
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

**Internal vs public frontend URL:** `get_frontend_url_internal()` returns the server-reachable URL (`host.docker.internal` in Docker, the real domain in prod) for `wp_remote_*` calls; `get_frontend_url_public()` returns the browser-facing URL (localhost in dev) for iframes and redirects.

**Page-preview mode & tokens:** `page_editor_mode()` reads the ACF `page_editor_mode` option (`legacy` default, or `page_preview`); a `nextpress_live_editor_available` filter returning false forces legacy (licensing hook). `mint_preview_token($post_id)` produces a stateless `base64url(payload).base64url(HMAC-SHA256(payload))` token (payload `{ uid, post, sid, iat, exp }`, 2-hour expiry) signed with `preview_secret()` — the `NEXTPRESS_PREVIEW_SECRET` constant, falling back to a shipped default (`preview_secret_is_default()` surfaces a prod nudge). The Next.js side recomputes the signature to authorise live renders.

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

All routes registered under the `nextpress` namespace. **All content endpoints use `permission_callback => '__return_true'`** (public, unauthenticated access). The exception is `/format`, which requires the `edit_posts` capability, plus the opt-in user-flow endpoints (see §10).

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
| `/nextpress/format` | POST | `API_Editor` | Format serialized editor block markup (requires `edit_posts`) |
| `/nextpress/form/{form_id}` | GET | `Ext_GravityForms` | Gravity Forms form data |

> The user-flow module adds `/login`, `/logout`, `/register`, `/request-reset`, and `/reset-password` when enabled — see §10.

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

### API_Editor (`/format`) — live page-preview

Part of the experimental page-preview feature. **`POST /nextpress/format`** accepts `{ content: "<serialized block markup>" }` — the editor bridge sends `wp.blocks.serialize( getBlocks() )`, i.e. the exact `post_content` a save would produce. It runs that through `Post_Formatter::parse_block_data()` — the **same pipeline** `/router` uses for live pages — so preview output (including nested innerBlocks and reusable-block refs) matches production with no hand-rolled serialization. Returns a `WP_REST_Response` of the formatted block tree (empty array for empty input). Restricted to users with `edit_posts`; the bridge sends the standard `wp_rest` nonce.

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

### Register_Editor

Registers the **Editor** ACF options page (`admin.php?page=editor`) on the `acf/init` hook. Two fields:

- **`page_editor_mode`** (select): `legacy` (default — classic per-block iframe previews) or `page_preview` (experimental live canvas + "Edit visually"). Read back via `Helpers::page_editor_mode()`.
- **`page_editor_compat`** (message): a live compatibility banner against the Next.js frontend.

The compat check (`run_compat_check()`) is only computed when actually rendering the Editor page (not on every admin load), and its result is cached in the `nextpress_live_editor_compat` transient for 5 minutes (`?np_recheck=1` busts it). It mints a preview token and `wp_remote_get`s `{internal_frontend_url}/page-preview/health?np_token=...` (5s timeout), distinguishing the real-world failure modes: **unreachable** (frontend down / wrong URL), **missing** (200 route absent — old build without the feature), **secret_mismatch** (token rejected — `NEXTPRESS_PREVIEW_SECRET` differs between WP and Next), **unknown**, and **ready**. When `preview_secret_is_default()` is true it also renders a "set a unique secret before production" nudge.

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

### Page_Preview_Spike (experimental)

> **SPIKE / proof-of-concept.** A bridge between the native Gutenberg editor and a Next.js full-page live canvas. Off by default; requires `page_editor_mode = page_preview` **and** the `?np_spike=1` query param on the editor URL. It piggybacks on the real `post.php` editor, so ACF forms, the block-editor store, and native save are all untouched — a JS failure degrades to the standard editor intact.

What it does:
1. **"Edit visually" row action** — adds a link to `post.php?post={id}&action=edit&np_spike=1` in the posts/pages list tables (`post_row_actions` / `page_row_actions`), gated on page-preview mode, `edit_post` capability, non-trashed status, and a block-editor post type.
2. **Boot loader** — server-side `np-editor-booting` admin body class paints a loading shell from first paint so the native editor never flashes; JS fades it once the canvas renders.
3. **Editor bridge (`page-preview-spike.js`)** — reads blocks reactively from `core/block-editor` (`wp.data`), POSTs them to `/wp-json/nextpress/format`, and `postMessage`s the formatted result to the Next.js canvas; a click on the canvas calls `selectBlock()` so the matching native ACF form shows.
4. **Assets** — enqueues `page-preview-spike.js` + `page-editor.css` (spike view) and, in page-preview mode generally, `page-editor-entry.js` (the entry button) and `page-editor-blocks.css` (in-canvas block labels, loaded via `enqueue_block_assets` so it reaches inside the editor-canvas iframe).
5. **Localised data (`NP_PREVIEW`)** — `postId`, public frontend URL + origin, the `/nextpress/format` REST URL, a `wp_rest` nonce, and a freshly minted `previewToken` (see `Helpers::mint_preview_token()`); `sid` in the token isolates concurrent editor sessions on the same post.

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

## 10. User Flow Module (Opt-in)

Disabled by default (next-auth is too heavy for most projects). `Init` instantiates `User_Flow` on `after_setup_theme` only when the `nextpress_load_user_flow` filter returns true — opt in from the active theme:

```php
add_filter( 'nextpress_load_user_flow', '__return_true' );
```

When enabled, provides JWT-based authentication (all routes under the `nextpress` namespace, `permission_callback => '__return_true'`):

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
| `nextpress_live_editor_available` | Helpers | Force legacy editor mode (licensing hook); return false to disable page-preview |
| `nextpress_load_user_flow` | Init | Enable the opt-in JWT user-flow module (default false) |
| `nextpress_enable_query_monitoring` | Helpers | Enable slow query logging |
| `nextpress_slow_query_threshold` | Helpers | Slow query threshold (default: 1.0s) |

### Actions

| Action | Location | Purpose |
|---|---|---|
| `acf/pre_save_block` | Ext_ACF | Auto-assign nextpress_id and anchor |

---

## Appendix: Quick Reference

### Constants

| Constant | Value | Source |
|---|---|---|
| `NEXTPRESS_PATH` | Plugin directory path (no trailing slash) | `nextpress.php` (defined) |
| `NEXTPRESS_URI` | Plugin URL | `nextpress.php` (defined) |
| `NEXTPRESS_PREVIEW_SECRET` | Shared HMAC secret for live-preview tokens; mirror in the Next.js `.env`. Falls back to a shipped default — override in production | user-defined (wp-config.php) |
| `JWT_AUTH_SECRET_KEY` | Signing key for the opt-in user-flow JWTs | user-defined (wp-config.php) |

### WP Options Used

| Option | Purpose |
|---|---|
| `options_frontend_url` | Frontend URL fallback |
| `page_on_front` | Homepage ID |
| `page_for_posts` | Blog page ID |
| `wpseo-premium-redirects-base` | Yoast premium redirects |
| `blocks_theme` (ACF) | Selected block themes |
| `frontend_url` (ACF) | Frontend URL |
| `page_editor_mode` (ACF) | Editor mode: `legacy` or `page_preview` |

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
- `nextpress_live_editor_compat` (transient, 5 min — Editor page frontend compat check)
