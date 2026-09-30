# Nextpress

**Nextpress turns WordPress into a headless CMS for Next.js.**

It exposes WordPress content as structured, Next.js-friendly JSON over a set of custom REST endpoints, dynamically registers ACF Gutenberg blocks from your Next.js app, renders live block previews inside the WordPress editor via the frontend, and handles redirects, draft/preview links, and cache revalidation between WordPress and Next.js.

- **Version:** 2.02
- **Namespace:** `nextpress`
- **Author:** [Pomegranate](https://pomegranate.co.uk)

> For the full technical breakdown — architecture, data flows, every filter/action, caching internals, and the security/performance audit — see [PLUGIN_DOCUMENTATION.md](PLUGIN_DOCUMENTATION.md).

## What it does

- **Headless REST API** — serves posts, pages, taxonomies, menus, settings, and theme data as JSON optimised for Next.js consumption under the `/wp-json/nextpress/*` namespace.
- **Dynamic ACF blocks** — fetches block definitions from your Next.js app's `/api/blocks` endpoint and registers matching ACF Gutenberg blocks automatically.
- **In-editor block previews** — renders blocks inside the WordPress editor via iframes pointing at the Next.js frontend, so what editors see matches production.
- **URL & preview handling** — redirects the WordPress frontend to Next.js, and rewrites preview/draft links to Next.js draft mode.
- **Cache & revalidation** — a Redis-aware caching layer (with transient fallback, circuit breakers, and rate limiting) that triggers Next.js on-demand revalidation when content changes.

## Requirements

- WordPress with Gutenberg
- **Advanced Custom Fields (ACF) PRO** — required for block registration, flexible-content templates, options pages, and repeaters.
- A Next.js frontend exposing the expected endpoints (`/api/blocks`, `/api/revalidate`, `/api/draft`, `/block-preview`).

### Optional integrations

These are detected at runtime and degrade gracefully if absent:

- **Yoast SEO** — injects `yoastHeadJSON` metadata and honours Yoast Premium redirects.
- **Gravity Forms** — injects full form data into blocks and exposes a form endpoint.
- **Polylang** — multilingual content, translations, and per-language templates.

## Installation

This plugin self-updates from GitHub via the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), tracking `https://github.com/pomeg-dev/nextpress`.

1. Copy the `nextpress` directory into `wp-content/plugins/`.
2. Activate **Nextpress** from the WordPress Plugins screen.
3. Ensure ACF PRO is installed and active.

Vendored libraries (no Composer step required) live in `includes/`: `acf-builder` (StoutLogic ACF Builder), `plugin-update-checker` (GitHub updates), and `php-jwt` (for the optional user-flow module).

## Configuration

Go to **Nextpress → Settings** in the WordPress admin and set:

- **Frontend URL** — your Next.js app's base URL. Used for block fetching, previews, redirects, and revalidation. (Falls back to a Docker/localhost probe in local development.)
- **Blocks theme(s)** — which block set(s) to request from the Next.js `/api/blocks` endpoint.
- Plus Google Tag Manager, favicon, 404 page, and coming-soon options.

Templates for before/after/sidebar content are configured under **Nextpress → Templates**. An **Nextpress → Editor** page lets you choose the page-render mode and shows a live health check against the Next.js frontend.

## REST API

All endpoints are public (`GET`, unauthenticated) unless noted, under the `nextpress` namespace:

| Endpoint | Method | Description |
|---|---|---|
| `/nextpress/router/{path?}` | GET | Resolve a path or ID to a fully formatted post/page (primary catch-all route) |
| `/nextpress/posts` | GET | Query posts with `WP_Query`-style params |
| `/nextpress/tax_list/{taxonomy}` | GET | List taxonomy terms |
| `/nextpress/tax_term/{taxonomy}/{term}` | GET | Get a single term |
| `/nextpress/settings` | GET | Site settings + ACF options (safe-allowlisted) |
| `/nextpress/menus` · `/menus/{location}` | GET | Nav menus (all, or by location) |
| `/nextpress/theme` · `/block_theme` | GET | `theme.json` contents / selected block themes |
| `/nextpress/form/{form_id}` | GET | Gravity Forms form data (requires Gravity Forms) |
| `/nextpress/format` | POST | Format editor block markup through the frontend pipeline (requires `edit_posts`) |

An optional, filter-gated **user-flow** module adds JWT auth endpoints (`/login`, `/logout`, `/register`, `/request-reset`, `/reset-password`). It is disabled by default; enable it per-project from your theme:

```php
add_filter( 'nextpress_load_user_flow', '__return_true' );
```

It requires a `JWT_AUTH_SECRET_KEY` constant to be defined.

## Extending

Nextpress is filter-driven throughout. Common hooks include `nextpress_post_object` (modify formatted posts), `nextpress_block_data` (modify individual blocks), `nextpress_settings` (enrich settings output), and cache-TTL filters like `nextpress_router_cache_ttl`. See the [full filter & action reference](PLUGIN_DOCUMENTATION.md#13-filter--action-reference).

## Experimental: live page preview

A visual full-page editor bridge ("page-preview spike") is present but gated behind `?np_spike=1` and the Editor-page mode setting. It is proof-of-concept and off by default; see [PLUGIN_DOCUMENTATION.md](PLUGIN_DOCUMENTATION.md) before relying on it.
