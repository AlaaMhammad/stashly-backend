# Feature: Search Filters and Home

## Endpoints
- `GET /search/filters`
- `GET /home`

## Search filters
Return the option lists and counts needed by the Android Filters sheet:
- content types
- sources/domains
- collections
- tags
- date presets

Counts and options must only reflect the authenticated user's own links/resources. Exact JSON property shape should follow the API reference and app contract examples if available; do not invent extra fields.

## Home
- Query `recent_limit`, default 10, maximum 20.
- Return user display name, links count, favorites count, quick-access collections, recent links.
- Use bounded eager loading/aggregate queries to avoid N+1 query growth.
- Counts must be scoped to the authenticated user.

## Tests
- Empty account.
- User with links in multiple collections and tags.
- Counts do not include another user's records.
- Recent limit defaults and bounds.
- Stable output schema and sorting.
