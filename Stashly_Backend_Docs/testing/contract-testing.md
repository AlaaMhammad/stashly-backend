# API Contract Testing

## Purpose
Prevent backend responses from drifting away from the Stashly Android API reference.

## Rules
- Maintain request/response fixtures for each endpoint operation.
- Verify status code, JSON field names, types, nullability, envelope, and error code.
- Verify all timestamps are UTC ISO 8601.
- Verify identifiers are ULIDs.
- Verify no undocumented model/database fields leak.
- Verify list metadata: `per_page`, `has_more`, `next_cursor`, `total` only on first page.
- Verify client should use error `code`; message text is not the branching contract.
- Verify cross-user resource behavior is 404.
- Verify `Idempotency-Key` replay returns first response within 24 hours.

## Suggested implementation
Use Laravel feature tests with explicit assertions or JSON schema fixtures if the project already uses a schema validator. Do not add a new dependency without reviewing its maintenance and compatibility.

## Fixture policy
Use synthetic test data only. Never copy real user data, live tokens, or production URLs into fixtures.
