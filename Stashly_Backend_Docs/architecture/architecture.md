# Proposed Laravel Architecture

## Design principles

- Keep controllers thin: validate, authorize, call application/service layer, return resource/envelope.
- Keep URL normalization and remote metadata retrieval in separate services.
- Apply ownership constraints in query scopes/policies and service boundaries, not only in the UI.
- Use database transactions for multi-step relationship updates, refresh token rotation, and deletion workflows where appropriate.
- Keep API response schemas stable and covered by tests.
- Prefer Laravel framework conventions and existing project conventions after repository inspection.
- Do not introduce repositories/interfaces/packages solely for ceremony. Add abstractions where they isolate external behavior, complex rules, or test seams.

## Suggested layout

```text
app/
  Http/
    Controllers/Api/V1/
      SystemController.php
      AuthController.php
      ProfileController.php
      LinkController.php
      LinkPreviewController.php
      LinkCollectionController.php
      LinkTagController.php
      CollectionController.php
      CollectionLinksController.php
      TagController.php
      SearchFilterController.php
      HomeController.php
    Requests/Api/V1/
    Resources/Api/V1/
    Middleware/
  Models/
    User.php
    Link.php
    Collection.php
    Tag.php
    IdempotencyRecord.php
  Services/
    Api/
    Auth/
    Links/UrlNormalizer.php
    Links/LinkService.php
    Links/MetadataExtractor.php
    Links/MetadataUrlGuard.php
    Search/
    Accounts/AccountDeletionService.php
    Accounts/DataExportService.php
  Jobs/
    FetchLinkMetadata.php
  Mail/
  Policies/
  Support/Api/
    ApiResponse.php
    ApiError.php
    RequestId.php
database/
  migrations/
  factories/
  seeders/
routes/
  api.php
resources/views/
  auth/reset-password.blade.php
  legal/terms.blade.php
  legal/privacy.blade.php
tests/
  Feature/Api/V1/
  Unit/Links/
  Security/
```

This is a proposal, not a command to duplicate existing classes. Inspect the repository first and adapt names to existing conventions.

## Data model — conceptual only

Expected entities:
- `users`
- `links` owned by user
- `collections` owned by user
- link-collection pivot
- tags owned by user and normalized by name
- link-tag pivot
- idempotency records scoped to user/key and expiry

Candidate link fields include ULID primary key, user foreign key, original and normalized URL, source domain, user title/note, favorite, content type, metadata status/payload fields, and timestamps. Exact columns, indexes, foreign keys, cascade behavior, and metadata storage type must be decided after checking MySQL version and migration strategy.

Important invariants:
- unique `(user_id, normalized_url)` for saved links.
- unique collection name per user case-insensitively (implementation must account for MySQL collation behavior).
- unique normalized tag name per user.
- every pivot relationship must prevent cross-user attachment.
- `Idempotency-Key` replay must be scoped to authenticated user and retained for 24 hours.
- deletion of a collection must preserve links.
- tag records with no links are removed.

## API layer

- Use Form Requests for input validation.
- Use Policies or explicit ownership-scoped queries.
- Use API Resources/DTOs to avoid exposing internal columns.
- Standardize error mapping and request ID generation.
- Ensure validation exceptions return the contract's error envelope.
- Do not expose exception traces in production.
- Use explicit status codes per endpoint.

## Background processing

`FetchLinkMetadata` should:
1. Reload the link and verify it still exists.
2. Use normalized/guarded target URL.
3. Validate DNS/IP and every redirect before connecting.
4. Fetch bounded HTML under strict timeout/size limits.
5. Parse only metadata needed by the contract.
6. Update metadata state without touching user-owned title/note.
7. Retry only permitted temporary failures.
8. Log safe diagnostic data without full URLs or credentials.
9. Finish in `ready`, `failed`, or `blocked`.

## External network boundary

Remote URL fetching is an SSRF-sensitive capability. Prefer a dedicated fetcher/egress boundary or isolated worker. Application-level URL checks alone are not sufficient if DNS rebinding or redirect re-targeting is possible. Verify that the HTTP client can connect to the validated IP while preserving the correct Host/TLS SNI behavior; otherwise use a safe egress proxy or equivalent control.

## Configuration

Keep environment-specific values in configuration/environment, not hard-coded:
- app versions and legal URLs
- token expiry policy
- queue/cache/database connections
- mail transport
- rate limit settings
- remote fetch timeouts and size limits

Never commit secrets. Provide `.env.example` with placeholders only.
