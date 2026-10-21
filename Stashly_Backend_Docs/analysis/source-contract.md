# Source Contract Analysis — Stashly API v1.0

## Source facts

- Document title: Stashly API v1
- Version/date: 1.0, 19 October 2026
- Base URL: `https://{host}/api/v1`
- Stack assumed by source: Laravel REST API, Sanctum bearer tokens, MySQL, queued jobs
- Document states: 32 endpoints. (DEC-035 later added `POST /auth/google`; the implemented API has 33 operations.)
- JSON UTF-8, snake_case, `Accept: application/json`.
- IDs are ULIDs; timestamps are ISO 8601 UTC.
- Tags are identified by name.

## Shared response objects

### User
`id`, `display_name`, `email`, `created_at`.

### Token
`access_token`, `token_type` (`Bearer`), `expires_at`.

### Link
`id`, `original_url`, `normalized_url`, `source_domain`, user `title`, `display_title`, `note`, `is_favorite`, `content_type` (`video|article|product|social|other`), `metadata`, `collections[{id,name}]`, `collections_count`, `tags[]`, `created_at`, `updated_at`.

### Link metadata
`status` (`pending|ready|failed|blocked`), `title`, `description`, `site_name`, `favicon_url`, `preview_image_url` (external URL only), `fetched_at`, `error_code`.

### Collection
`id`, `name`, `description`, `links_count`, `cover_images[]` (up to four URLs), `created_at`, `updated_at`.

## Response and error conventions

Success uses `{ "data": ... }` where specified. Lists include `meta` with `per_page`, `has_more`, `next_cursor`, and `total`; `total` appears only on the first page. Errors use `{ "error": { "code", "message", "details", "request_id" } }`. Validation 422 details map fields to messages. The Android app uses `code`, not `message`.

## Ownership

Everything is scoped to the signed-in user. Access to another user's resource returns 404, not 403.

## Pagination

Cursor-based; default limit 20 and maximum 50. Newest first unless endpoint specifies otherwise. Invalid/mismatched cursor returns `INVALID_CURSOR`.

## Rate limits

| Operation | Limit |
|---|---|
| Login | 5/minute by email + IP |
| Register | 10/hour/IP |
| Forgot password | 3/hour/email |
| Link preview | 20/minute |
| Save link | 60/minute |
| Refresh-preview (interpretation needs confirmation) | 5/minute |
| Export | 1 per 10 minutes |
| Everything else | 120/minute/user |

Exceeded limit: HTTP 429, `RATE_LIMITED`, `Retry-After`.

## Limits

- URL: 2048 characters.
- Link title: 300.
- Note: 2000.
- Collection name: 60; description: 300.
- Tag: 40.
- Max 20 tags per link.
- Max 100 collections per user.
- Page size max 50.

## URL normalization contract

Trim spaces; add `https://` when scheme absent. Accept HTTP/HTTPS only. Reject `javascript:`, `file:`, `data:`, `intent:`, URL credentials, and overlength URLs. Lowercase scheme and host; remove `www.` / `m.`; remove default ports and fragments; remove tracking parameters including `utm_*`, `fbclid`, `gclid` and similar; sort remaining query parameters; remove trailing slash. Preserve `original_url`. Unique saved URL per user per normalized URL.

## Metadata fetch contract

- Queue after save; save response returns while metadata is pending.
- Store extracted text and external image URL only; never download/store images or videos.
- Only HTTP/HTTPS ports 80/443.
- Resolve DNS; permit public IP only; block private, loopback, link-local, cloud metadata ranges, IPv6 equivalents.
- Connect to checked IP; re-check every redirect; maximum three redirects.
- Timeout 8 seconds, preview timeout 4 seconds.
- Read max 512 KB; HTML only; no cookies; worker isolated from internal services.
- Retry only temporary 429, 5xx, timeout errors after 2 min, 15 min, 2 h; max four attempts.
- Never overwrite user title or note.
- Cleanup pending metadata older than 15 minutes.

## Account contract

- Hash passwords; never return or log passwords.
- Never log tokens or full URLs.
- Forgot-password always returns 200 regardless of whether account exists.
- Reset token expires in 60 minutes and is single-use; reset revokes all user tokens.
- Password change revokes other tokens.
- Account deletion removes all user data and tokens.
- Wrong email and wrong password return the same login error.

## Source ambiguities to resolve

See `../decisions/open-questions.md`. Do not silently turn interpretations into source facts.
