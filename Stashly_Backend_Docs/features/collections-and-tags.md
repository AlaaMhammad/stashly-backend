# Feature: Collections and Tags

## Collections contract
- List collections with `links_count` and up to four cover image URLs.
- Create: name required, 1–60 chars; description up to 300.
- Name uniqueness is per user and case-insensitive.
- Maximum 100 collections per user.
- Update name/description.
- Delete collection without deleting links. Links in no remaining collection are Unsorted.
- `PATCH /collections/{collection}/links` supports `attach[]` and `detach[]`, maximum 100 IDs each, at least one array non-empty; operation is atomic.

## Tags contract
- Tags belong to a user and are unique by normalized name.
- Normalization: trim, lowercase, remove leading `#`, collapse repeated spaces.
- Tag max 40 chars; max 20 tags per link.
- Replacing link tags should reconcile pivots transactionally and delete unused tag records.
- `GET /tags` supports prefix query, usage/name sorting, limit max 100.

## Integrity requirements
- A user must not attach another user's link to their collection or tag.
- Validate every submitted ID in the authenticated user's scope.
- Use transactions for attach/detach and replacement operations.
- Database uniqueness/collation must enforce the intended case-insensitive uniqueness; test on the actual MySQL configuration.
