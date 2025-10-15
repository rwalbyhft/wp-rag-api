# RAG API Access (Rebuilt) — v2.0.0

Expose Bricks-rendered WordPress pages via secure REST endpoints designed for Retrieval-Augmented Generation (RAG): pagination, incremental sync, and brand/pillar/section metadata derived from ACF breadcrumbs (with URL fallback).

## Setup in WordPress
1. Install and activate plugin
4. Create a dedicated **crawler** user (Subscriber) and generate a **WordPress Application Password** for REST access.

## Endpoints

### GET `/wp-json/rag/v1/status`
Params: `excluded_ids[]`, `include_paths[]`, `exclude_paths[]`  
Returns: `{ healthy, total, per_page_cap, last_modified, filters }`

### GET `/wp-json/rag/v1/pages`
Params: 
- `per_page` (1–100, default 50), `page` (default 1)  
- `modified_since` (ISO 8601)  
- `excluded_ids[]`, `include_paths[]`, `exclude_paths[]`  
- `fields` = `text` (default; omits HTML) or `full` (includes `content_html`)

Item fields:
- `id`, `title`, `permalink`, `modified_date_gmt`
- `brand`, `pillar`, `section` (from ACF; URL fallback)
- `breadcrumbs` (`level1..level4`, `brand_url`)
- `content_text`, `headings[]` (and `content_html` if `fields=full`)

## Typical usage

Initial backfill:
```
GET /wp-json/rag/v1/pages?per_page=100&page=1&fields=text
... loop pages ...
```

Weekly delta:
```
GET /wp-json/rag/v1/pages?modified_since=2025-10-01T00:00:00Z&per_page=100&page=1&fields=text
```

## Notes
- Bricks-aware: tries to isolate `<main id="brx-content">…</main>` before extracting text/headings.
- Breadcrumb levels 3/4 may be null; JSON still includes keys.
- Pagination + deltas avoid WordPress.com timeouts.
