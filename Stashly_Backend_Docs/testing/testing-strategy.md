# Testing Strategy

## Layers

### Unit tests
Test deterministic business rules without database/network where practical:
- URL normalization and rejected schemes.
- Tag normalization.
- Arabic-aware search normalization helpers.
- Cursor validation/encoding helpers.
- Error-code mapping.
- Metadata parser behavior with fixture HTML.

### Feature tests
Use Laravel HTTP tests and a dedicated test database:
- Every endpoint's auth requirements, validation, status code, response envelope and fields.
- Database persistence and relationships.
- Ownership isolation and 404 behavior.
- Pagination/filter combinations.
- Rate-limit behavior.
- Idempotency and duplicate behavior.
- Account lifecycle and token revocation.
- Collection attach/detach atomicity.
- Export/deletion.

### Security tests
- Cross-user IDOR tests across all resources and relationship endpoints.
- Mass assignment/read-only fields.
- SSRF scheme, IP, redirect, DNS and response size tests.
- Auth enumeration behavior.
- Rate limit keys and `Retry-After`.
- Secrets/credentials not present in response/log output where testable.

### Queue/job tests
- Save dispatches metadata job after commit.
- Metadata success, blocked, permanent failure, temporary retry.
- Does not overwrite title/note.
- Handles deleted link.
- Pending cleanup.
- Fake mail/queue/HTTP in normal test suite; no external network dependency.

### Contract tests
Compare JSON response shape/status codes to API contract fixtures. Include `request_id` and exact stable error codes. Do not assert brittle human-readable messages except where the contract requires equivalent messages.

## Test environment
- Use isolated testing database, not development/production.
- Ensure `APP_ENV=testing`, safe queue/cache/mail drivers, and no real email/network calls.
- Use factories with ULID identifiers and distinct users.
- Test MySQL-specific uniqueness/collation/index behavior against MySQL in CI, not only SQLite.
- Never place real tokens/passwords or production data in fixtures.

## Completion policy
A feature is not complete until relevant tests pass. Record commands and evidence in `api/endpoint-status.md` and `features/feature-status.md`.
