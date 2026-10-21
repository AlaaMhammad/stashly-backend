# Data Model and Invariants (Provisional)

This is a logical model, not a final migration specification. Inspect the repository and decide exact types/indexes before writing migrations.

## User
- ULID ID.
- display name, unique email, password hash, terms acceptance/version fields as required by implementation.
- Linking Google to an existing account sets `password` to NULL (DEC-037); `password_set` in the API user object is `password IS NOT NULL` (DEC-036).
- Google Sign-In (DEC-035): nullable unique `google_id` (Google's `sub`), nullable `avatar_url` (stored, not exposed) and a nullable password hash (Google-only accounts have none; password login rejects them like unknown e-mails).
- Sanctum tokens or equivalent token table.
- Do not expose password or internal fields.

## Link
- ULID ID.
- Belongs to one user.
- Original URL and normalized URL.
- Source domain.
- User title and note.
- Favorite flag and content type.
- Metadata status/data/fetched timestamp/error code.
- Unique per `(user_id, normalized_url)`.

## Collection
- ULID ID.
- Belongs to one user.
- Name and optional description.
- Unique per user ignoring case.
- Maximum 100 per user enforced safely under concurrent requests, not only by a count check.

## Tag
- ULID ID.
- Belongs to one user.
- Original/display name and normalized name if useful.
- Unique per user by normalized name.
- Delete when no links remain, per source contract.

## Pivots
- Link-collection many-to-many.
- Link-tag many-to-many.
- Enforce ownership consistency at service/query level and with database design where possible.

## Idempotency record
- User, key, request fingerprint, response/status, creation/expiry.
- Unique key scoped to user.
- Retention/replay window 24 hours.
- Exact behavior for same key/different body requires decision DEC-005.

## Invariants to test
- No cross-user link, collection, tag, pivot, export, or count leakage.
- One normalized URL per user.
- Collection deletion never deletes links.
- Empty collection assignment means Unsorted.
- Tags with no links are removed.
- Metadata never overwrites user title/note.
- Multi-step relationship changes are atomic.
- Token refresh revokes previous token.
- Account deletion removes associated data/tokens.
