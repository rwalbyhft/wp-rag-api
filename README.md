# RAG Content API – Endpoint Reference

**Version:** 2.9.0
**Base URL:** `https://vbl.harborfreight.com/wp-json/rag/v1`
**Authentication:** HTTP Basic Auth using a WordPress Application Password
**Protocol:** HTTPS required

---

## Authentication

All requests require Basic Auth with a service account and Application Password. Authorized users: `rag-system`, `MLOpsrunner`, and any WordPress administrator.

```bash
curl -u "USERNAME:APPLICATION_PASSWORD" "https://vbl.harborfreight.com/wp-json/rag/v1/status"
```

> **Note:** Application Passwords contain spaces (e.g., `AbCd EfGh IjKl MnOp`). Always wrap credentials in quotes.

---

## Endpoints

### GET `/status`

Health check and site summary. Use this to verify connectivity, check total page count, and confirm the API version.

**Example:**

```bash
curl -s -u "USERNAME:APP_PASSWORD" \
  "https://vbl.harborfreight.com/wp-json/rag/v1/status" \
  | python3 -m json.tool
```

**Response:**

```json
{
  "healthy": true,
  "version": "2.9.0",
  "total_items": 306,
  "excluded_pages": 23,
  "last_modified": "2026-03-31T22:10:41+00:00",
  "server_time": "2026-03-31T22:30:00+00:00",
  "endpoints": {
    "pages": "https://vbl.harborfreight.com/wp-json/rag/v1/pages",
    "status": "https://vbl.harborfreight.com/wp-json/rag/v1/status",
    "reindex": "https://vbl.harborfreight.com/wp-json/rag/v1/reindex"
  }
}
```

| Field | Description |
|-------|-------------|
| `total_items` | Number of published pages available (excludes internal/BCI pages) |
| `last_modified` | Timestamp of the most recently modified page (UTC) |
| `server_time` | Current server time (UTC) |

---

### GET `/pages`

Retrieve page content with plaintext extraction, chunking, and metadata. Supports pagination, incremental sync, and direct ID lookup.

#### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | integer | 50 | Items per request (1–100) |
| `page` | integer | 1 | Page number (1-indexed) |
| `fields` | string | `text` | `text` = plaintext only; `full` = includes `content_html` |
| `modified_after` | string | — | ISO 8601 timestamp; returns only pages modified after this date |
| `ids` | string | — | Comma-separated post IDs for direct lookup |
| `nocache` | boolean | false | Skip transient cache and force fresh content extraction |

#### Fetch specific pages by ID

```bash
curl -s -u "USERNAME:APP_PASSWORD" \
  "https://vbl.harborfreight.com/wp-json/rag/v1/pages?ids=129708,125610&fields=text" \
  | python3 -m json.tool
```

#### Paginated fetch (all pages)

```bash
# First page
curl -s -u "USERNAME:APP_PASSWORD" \
  "https://vbl.harborfreight.com/wp-json/rag/v1/pages?per_page=50&page=1" \
  | python3 -m json.tool

# Check metadata.has_more and metadata.next_page to continue
```

#### Incremental sync (only pages changed since last run)

```bash
curl -s -u "USERNAME:APP_PASSWORD" \
  "https://vbl.harborfreight.com/wp-json/rag/v1/pages?modified_after=2026-03-01T00:00:00Z&per_page=100" \
  | python3 -m json.tool
```

#### Force fresh extraction (skip cache)

```bash
curl -s -u "USERNAME:APP_PASSWORD" \
  "https://vbl.harborfreight.com/wp-json/rag/v1/pages?ids=125610&nocache=true&fields=full" \
  | python3 -m json.tool
```

#### Response structure

```json
{
  "metadata": {
    "mode": "ids",
    "requested_ids": [125610],
    "returned": 1,
    "fields": "text",
    "cache_skipped": false,
    "sync_timestamp": "2026-03-31T22:30:09+00:00",
    "processing_time_seconds": 0.74
  },
  "pages": [
    {
      "id": 125610,
      "title": "Harbor Freight Tools – Signage Kit Dates",
      "permalink": "https://vbl.harborfreight.com/hft/marketing-channels/sign-kit-dates/",
      "modified_date_gmt": "2026-03-31T22:10:41+00:00",
      "brand": "hft",
      "pillar": "marketing-channels",
      "section": "signage/kit-dates",
      "content_text": "Full plaintext content...",
      "content_excerpt": "First 50 words...",
      "headings": [
        { "level": 2, "text": "Signage Kit Dates", "id": "brxe-lopclt" }
      ],
      "word_count": 155,
      "last_author": "Ross Walby",
      "canonical": "https://vbl.harborfreight.com/hft/marketing-channels/sign-kit-dates/",
      "template": "default",
      "breadcrumbs": {
        "level1": "HF VBL",
        "level2": "Marketing Channels",
        "level3": "Signage",
        "level4": "Kit Dates",
        "brand_url": "https://vbl.harborfreight.com/hft/"
      },
      "chunks": [
        {
          "index": 0,
          "text": "Chunk text (~1200 chars with 150 char overlap)...",
          "source": "https://vbl.harborfreight.com/hft/marketing-channels/sign-kit-dates/",
          "page_id": 125610,
          "modified": "2026-03-31T22:10:41+00:00",
          "title": "Harbor Freight Tools – Signage Kit Dates",
          "brand": "hft",
          "pillar": "marketing-channels",
          "section": "signage/kit-dates"
        }
      ]
    }
  ]
}
```

#### Page fields reference

| Field | Description |
|-------|-------------|
| `id` | WordPress post ID |
| `title` | Page title (HTML entities decoded) |
| `permalink` | Canonical URL |
| `modified_date_gmt` | Last modified timestamp (UTC) |
| `brand` | Brand identifier derived from URL/breadcrumbs (e.g., `hft`) |
| `pillar` | Content pillar (e.g., `brand-foundations`, `marketing-channels`) |
| `section` | Content section path (e.g., `copywriting/measurements-units`) |
| `content_text` | Full plaintext extraction with tables as pipe-delimited rows, links preserved with URLs, images as `[Image: alt \| URL: src]` |
| `content_excerpt` | First 50 words of plaintext |
| `content_html` | Raw rendered HTML (only when `fields=full`) |
| `headings` | Array of headings with level, text, and anchor ID |
| `word_count` | Approximate word count of plaintext |
| `last_author` | Display name of the last editor |
| `breadcrumbs` | ACF breadcrumb hierarchy (if available) |
| `chunks` | Pre-chunked text segments for vector store ingestion |

#### Chunk structure

Each chunk is ~1200 characters with 150-character overlap between consecutive chunks. Every chunk includes full metadata for standalone vector store ingestion.

| Field | Description |
|-------|-------------|
| `index` | Zero-based chunk position |
| `text` | Chunk content |
| `source` | Page permalink |
| `page_id` | WordPress post ID |
| `modified` | Page last modified timestamp |
| `title` | Page title |
| `brand`, `pillar`, `section` | Content taxonomy |

#### Pagination headers

| Header | Description |
|--------|-------------|
| `X-WP-Total` | Total number of matching pages |
| `X-WP-TotalPages` | Total number of result pages |
| `Link` | Next page URL (when `has_more` is true) |
| `ETag` | Content hash for conditional requests |

---

### POST `/reindex`

Force fresh extraction for specific pages. Always skips transient cache. Use this after content updates when you need immediate re-ingestion without waiting for cache expiry (12 hours).

#### Parameters (JSON body)

| Parameter | Type | Description |
|-----------|------|-------------|
| `ids` | array of integers | **Required.** Post IDs to reindex |
| `fields` | string | `text` (default) or `full` |

**Example:**

```bash
curl -s -u "USERNAME:APP_PASSWORD" \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"ids": [125610, 129708], "fields": "text"}' \
  "https://vbl.harborfreight.com/wp-json/rag/v1/reindex" \
  | python3 -m json.tool
```

**Response:** Same structure as `/pages` with a `pages` array.

---

## Recommended sync workflow

### Initial full sync

```bash
# Paginate through all pages
PAGE=1
while true; do
  RESPONSE=$(curl -s -u "USERNAME:APP_PASSWORD" \
    "https://vbl.harborfreight.com/wp-json/rag/v1/pages?per_page=50&page=$PAGE&fields=text")

  # Process pages...
  # Store the sync_timestamp from metadata

  HAS_MORE=$(echo "$RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)['metadata']['has_more'])")
  if [ "$HAS_MORE" = "False" ]; then break; fi
  PAGE=$((PAGE + 1))
done
```

### Incremental sync (subsequent runs)

```bash
# Use the sync_timestamp from the last successful run
curl -s -u "USERNAME:APP_PASSWORD" \
  "https://vbl.harborfreight.com/wp-json/rag/v1/pages?modified_after=2026-03-31T22:30:09Z&per_page=100&fields=text"
```

### On-demand reindex (after known content update)

```bash
curl -s -u "USERNAME:APP_PASSWORD" \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"ids": [125610]}' \
  "https://vbl.harborfreight.com/wp-json/rag/v1/reindex"
```

---

## Caching behavior

- Pages are cached as WordPress transients for **12 hours**
- Cache is automatically invalidated when a page is saved or deleted in WordPress
- Use `nocache=true` on `/pages` or the `/reindex` endpoint to bypass cache
- The `processing_time_seconds` field indicates whether a response was cached (near 0) or freshly extracted (typically 0.5–2s per page)

---

## Error responses

| Status | Meaning |
|--------|---------|
| 400 | HTTPS required |
| 401 | Authentication missing or invalid |
| 403 | User not authorized for RAG endpoints |

All errors return a standard WordPress REST API error object:

```json
{
  "code": "rag_auth",
  "message": "Authentication required.",
  "data": { "status": 401 }
}
```

---

## Content extraction notes

- **Tables** are converted to pipe-delimited rows (e.g., `Header 1 \| Header 2`)
- **Links** are preserved as `Link Text (https://url)`
- **Images** are replaced with `[Image: alt text \| URL: src]`
- **Navigation, headers, footers, scripts, and hidden elements** are stripped
- **HTML entities** are decoded to Unicode in all plaintext fields
- Pages with fewer than 20 characters of extractable text are excluded from results
