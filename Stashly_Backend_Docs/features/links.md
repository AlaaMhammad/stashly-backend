# Feature: Saved Links

## Contract endpoints
- `POST /links/preview`
- `POST /links`
- `GET /links`
- `GET /links/{link}`
- `PATCH /links/{link}`
- `DELETE /links/{link}`
- `PUT /links/{link}/collections`
- `PUT /links/{link}/tags`
- `POST /links/{link}/metadata/refresh`

## Save flow
1. Validate URL input and length.
2. Normalize with the shared `UrlNormalizer`.
3. Verify duplicate within the current user scope.
4. Apply idempotency behavior for `POST /links`.
5. Persist link quickly with metadata status `pending`.
6. Dispatch metadata job after transaction commit.
7. Return 201 without waiting for remote site access.

Metadata failures must never cause link save failure.

## Patch contract
Only `title`, `note`, and `is_favorite` are editable through `PATCH /links/{link}`. Null/empty title and note clear the values. Reject read-only fields instead of silently accepting them.

## Collections/tags
- Link collections endpoint is full replacement. Empty `collection_ids` means Unsorted.
- Link tags endpoint is full replacement. Max 20 tags, each max 40 characters.
- Tags are identified by normalized name, unique per user; tags with no linked links disappear.

## List/search behavior
- Cursor pagination; default 20/max 50; newest first by default.
- Search across user title, fetched title, description, note, domain.
- Case-insensitive and Arabic-aware: ignore diacritics and normalize alef forms.
- Every search word must match.
- Several selected tags must all match (AND).
- Multiple values inside another filter category use OR; different filter categories combine with AND.
- Filters include favorite, content type, source/domain, collection, tags, saved within 7/30 days, sort newest/oldest.

## Tests
See `testing/test-matrix.md` and `security/security-test-cases.md`.
