# Stashly Backend API — Implementation Plan

- **Version:** 1.0
- **Contract date:** 19 October 2026
- **Target stack:** Laravel 12, PHP 8.2, MySQL, Laravel Sanctum, queued jobs
- **API prefix:** `/api/v1`
- **Status:** Planning baseline; implementation status must be updated from repository evidence.

## 1. Objective

Build a secure, maintainable REST API for the Stashly Android application that implements the 32 endpoint operations in the Stashly API Reference v1.0, matching its request/response contract, ownership rules, limits, error codes, URL handling, asynchronous metadata extraction, and account lifecycle behavior.

## 2. Sources of truth

1. **Primary contract:** `Stashly_API_Reference.docx` (Stashly API v1.0).
2. **Project-specific implementation decisions:** `docs/decisions/`.
3. **This plan:** delivery sequence and acceptance gates.

Do not silently resolve contradictions or fill gaps with guessed product behavior. Log ambiguities and decisions in `docs/decisions/open-questions.md`.

## 3. Non-negotiable contract

- JSON UTF-8; use `snake_case`; clients send `Accept: application/json`.
- Success envelopes use `{ "data": ... }` where specified by the contract. Error envelope: `{ "error": { "code", "message", "details", "request_id" } }`.
- Client logic branches on stable `error.code`, not message text.
- Sanctum bearer tokens; 60-day token validity; refresh rotates and revokes the old token.
- ULIDs for all IDs; timestamps are ISO 8601 UTC.
- Cursor pagination: default limit 20, max 50; newest first unless specified; total only on first page.
- Cross-user resources must appear as `404 NOT_FOUND`, not `403`.
- `POST /links` supports `Idempotency-Key` UUID; repeated request within 24 hours returns the first response.
- Normalize URLs in one shared place; one saved normalized URL per user.
- Saving a link must not wait for metadata and must not fail because metadata extraction fails.
- Metadata extraction runs asynchronously and must implement SSRF controls.
- Do not download or store remote images/videos; store external preview image URL only.
- Never overwrite user-provided link title or note with fetched metadata.
- Rate limits and field/resource limits in the source contract are mandatory.
- Password reset response must not reveal whether an email exists.
- Account deletion removes all user data and tokens.

## 4. Scope

### In scope
- System health and app configuration.
- Authentication, password lifecycle, profile, stats, export, account deletion.
- Link preview/save/list/detail/edit/delete, favorites, collections assignment, tags assignment, metadata refresh.
- Collections CRUD and atomic attach/detach operation.
- Tags listing and filter options.
- Home aggregation.
- Cursor pagination, search/filter semantics, standardized errors.
- Queue worker, retries, stuck-pending cleanup, monitoring, reset-password web page, Terms and Privacy URLs/pages.
- Automated tests, deployment readiness, API documentation.

### Out of scope unless a documented decision adds them
- Social login, subscriptions, sharing links publicly, teams, folders beyond Collections, cloud file/image storage, video downloading, user-to-user sharing, admin dashboard, and unlisted API endpoints.
- Do not add these as assumptions.

## 5. Delivery phases and gates

### Phase 0 — Repository reconnaissance and baseline
- [x] Inspect repository structure, PHP/Laravel versions, installed packages, current routes, migrations, tests, CI, environment configuration. *(Evidence: `operations/project-audit.md` §1–3, §7; no CI exists.)*
- [x] Record current state and existing conventions before editing. *(Evidence: `operations/project-audit.md` §1.)*
- [x] Run baseline tests and capture failures separately from new failures. *(Evidence: `php artisan test` → 5 passed, 0 failures; `operations/project-audit.md` §6.)*
- [ ] Confirm database engine/version and queue/cache drivers in each environment.
- [ ] Create/update `docs/` from this documentation set.
- **Gate:** a written repository audit exists; no duplicate implementation is started blindly.

### Phase 1 — Foundation and shared API behavior
- [x] Configure `/api/v1` routing and JSON behavior. *(Evidence: `bootstrap/app.php` apiPrefix + `ForceJsonResponse`; `ErrorEnvelopeTest`.)*
- [x] Create consistent success/error response helpers or resources without overriding Laravel behavior unpredictably. *(Evidence: `app/Support/Api/*`; renderer applies to `api/*` only — `ErrorEnvelopeTest::test_web_routes_are_not_rendered_as_api_errors`. Provisional mappings: DEC-018.)*
- [x] Include `request_id` in errors and correlate it with safe logs. *(Evidence: `AssignRequestId` stores it in Laravel Context, which is appended to log entries; `ErrorEnvelopeTest::test_each_request_gets_a_distinct_request_id_shared_with_log_context`.)*
- [x] Implement ULID model keys and UTC timestamp serialization. *(Evidence: `UlidAndDateTest`, `Tests\Unit\ApiFormattingTest`. SQLite only; MySQL unverified.)*
- [x] Configure health checks for database, cache, queue; return 503 when a required check fails. *(Evidence: `HealthTest`. 503 body shape provisional: DEC-017.)*
- [x] Implement public `/config`, cache for 5 minutes, and configured terms/privacy/app versions/limits. *(Evidence: `ConfigTest`. `limits` key names provisional: DEC-008.)*
- [x] Add rate-limit definitions and `Retry-After`. *(Evidence: `RateLimitTest`. Refresh-preview limiter pending DEC-003; endpoint wiring happens in later phases.)*
- **Gate:** contract tests for envelopes, health/config, IDs/dates, and rate limiting pass.

### Phase 2 — Accounts and authentication
- [x] Register with display name, email, password/confirmation, terms accepted, terms version, optional device name. *(Evidence: `RegisterTest`.)*
- [x] Login with generic invalid-credentials response for unknown email or wrong password. *(Evidence: `LoginTest::test_wrong_password_and_unknown_email_are_indistinguishable`; the unknown-email path also spends one hash so timing matches.)*
- [x] Sanctum bearer token issuance, 60-day expiry policy, token metadata and revocation. *(Evidence: `TokenLifecycleTest`; per-token `expires_at`, ULID token ids, daily `sanctum:prune-expired`.)*
- [x] Logout current token or all tokens. *(Evidence: `TokenLifecycleTest`.)*
- [x] Refresh rotates current token and revokes old token. *(Evidence: `TokenLifecycleTest::test_refresh_rotates_the_token_and_revokes_the_old_one`.)*
- [x] Forgot password always returns the same 200 response regardless of account existence; queue email delivery. *(Evidence: `PasswordResetTest`.)*
- [x] Reset token expires after 60 minutes, single-use; successful reset revokes all tokens. *(Evidence: `PasswordResetTest`.)*
- [x] Change password verifies current password, requires new password to differ, revokes other tokens. *(Evidence: `ProfileTest`.)*
- [ ] `GET /me`, `PATCH /me`, `GET /me/stats`. *(`GET /me` and `PATCH /me` done: `ProfileTest`. `GET /me/stats` deferred: it counts links and collections, whose tables arrive in Phases 3-5.)*
- [x] Delete account only after password verification and remove all associated data/tokens. *(Evidence: `ProfileTest`. Every later user-owned table must use `cascadeOnDelete()` and extend the deletion test.)*
- [ ] Implement the password reset web page and Terms/Privacy pages/URLs. *(Reset page done: `PasswordResetTest`. Terms/Privacy pages BLOCKED on legal content: DEC-021. Their URLs are already configurable via `STASHLY_TERMS_URL` / `STASHLY_PRIVACY_URL`.)*
- **Gate:** auth feature tests cover success, validation, rate limits, token expiry/rotation/revocation, enumeration resistance, and deletion.

### Phase 3 — URL normalization and link saving
- [ ] Build one URL normalization service and reuse it in preview, save, duplicate detection, and metadata processing. *(`App\Services\Links\UrlNormalizer`, used by preview, save and duplicate detection. Reuse before metadata fetch lands in Phase 6.)*
- [x] Trim spaces; add `https://` if scheme absent. *(Evidence: `Tests\Unit\Links\UrlNormalizerTest`.)*
- [x] Accept only HTTP/HTTPS; reject javascript/file/data/intent and credentials in URL. *(Evidence: `UrlNormalizerTest`, `SaveLinkTest::test_invalid_urls_return_invalid_url_and_save_nothing`.)*
- [x] Enforce maximum URL length 2048. *(Evidence: `UrlNormalizerTest` length tests; applies to the input and to the normalized form.)*
- [x] Lowercase scheme/host; drop `www.` and `m.`; remove default ports, fragments, tracking parameters (`utm_*`, `fbclid`, `gclid`, similar), sort remaining query parameters, strip trailing slash. *(Evidence: `UrlNormalizerTest`. Tracking list provisional: DEC-004; other details: DEC-026.)*
- [x] Preserve the user's entered value as `original_url`. *(Evidence: `SaveLinkTest::test_saves_immediately_with_pending_metadata_and_contract_shape`; stored trimmed.)*
- [x] Enforce unique normalized URL per user in application logic and with an appropriate database uniqueness strategy. *(Evidence: unique index on `(user_id, normalized_url_hash)` (sha256; a 2048-char utf8mb4 column exceeds MySQL's index key limit); `SaveLinkTest::test_concurrent_duplicate_caught_by_the_database_returns_409`. SQLite only; MySQL unverified.)*
- [ ] Implement preview endpoint with 4-second limit and preview rate limit. *(Endpoint and 20/min limit done: `LinkPreviewTest`. `metadata` is `null` and the 4-second fetch is pending the SSRF-safe fetcher in Phase 6.)*
- [x] Implement save endpoint; queue metadata work; use 24-hour idempotency key behavior. *(Evidence: `SaveLinkTest`, `IdempotencyTest`. `FetchLinkMetadata` is dispatched after commit but is a placeholder until Phase 6. Idempotency rules provisional: DEC-005.)*
- [x] Duplicate save returns 409 with `details.existing_link`. *(Evidence: `SaveLinkTest::test_duplicate_returns_409_with_existing_link_and_queues_nothing`.)*
- **Gate:** normalization test matrix and concurrent duplicate/idempotency tests pass.

### Phase 4 — Link library operations
- [ ] Cursor-paginated `GET /links`, default 20/max 50, newest first.
- [ ] Implement filters: q, favorite, content_type, source/domain, collection_id, tags, saved_within_days, sort.
- [ ] Several tags use AND (link must have all); values within other same filter use OR; different filter categories combine with AND.
- [ ] Search title, fetched title, description, note, and domain; case-insensitive and Arabic-aware (diacritic handling and alef normalization).
- [ ] Detail endpoint includes metadata, collections, tags.
- [ ] PATCH only permits title, note, is_favorite; null/empty clears title/note; reject read-only fields.
- [ ] Delete link.
- [ ] Replace link collections atomically; empty array means Unsorted.
- [ ] Replace link tags; normalize tags per user; delete tags that have no linked links.
- [ ] Refresh metadata endpoint, max once/minute/link.
- **Gate:** filtering combinations, ownership isolation, read-only field rejection, and cursor validity tests pass.

### Phase 5 — Collections, tags, home, filter options
- [ ] Collections list with counts and up to four cover image URLs.
- [ ] Create collection: name 1–60, description max 300, case-insensitive unique per user, max 100 per user.
- [ ] Detail, update, delete; deleting collection preserves links and links without another collection become Unsorted.
- [ ] Atomic attach/detach endpoint; max 100 IDs in each list and at least one non-empty.
- [ ] Tags list with prefix search, usage counts, sort, max 100.
- [ ] Filter options endpoint with counts.
- [ ] Home endpoint with recent limit default 10/max 20.
- **Gate:** relationship integrity, count correctness, limits, and deletion semantics pass.

### Phase 6 — Metadata queue and SSRF hardening
- [ ] Metadata job fetches HTML and extracts title, description, site name, favicon URL, preview image URL, content type.
- [ ] State machine: pending → ready / failed / blocked.
- [ ] Retry only temporary errors (429, 5xx, timeout), at 2 minutes, 15 minutes, 2 hours; at most 4 attempts.
- [ ] Only ports 80/443; DNS resolve and reject private, loopback, link-local, cloud metadata IPs, and IPv6 equivalents.
- [ ] Connect to the checked IP to reduce DNS rebinding risk.
- [ ] Re-check every redirect; max 3 redirects.
- [ ] Timeout 8 seconds (4 seconds for preview); maximum 512 KB HTML; HTML only; no cookies.
- [ ] Run worker without access to internal services.
- [ ] Never download images/video or overwrite user title/note.
- [ ] Retry refresh max once/minute/link.
- [ ] Scheduled cleanup marks pending metadata older than 15 minutes as recovered/failed according to documented implementation policy; expired tokens cleanup as applicable.
- **Gate:** SSRF test suite, redirect tests, timeout/size limits, queue retry behavior, and stuck-job cleanup pass.

### Phase 7 — Export, observability, and hardening
- [ ] Export all user data as a JSON attachment; limit 1 per 10 minutes.
- [ ] Ensure export includes only the authenticated user's data and agreed data schema.
- [ ] Verify all rate limits: login 5/min email+IP; register 10/hour/IP; forgot password 3/hour/email; preview 20/min; save 60/min; refresh-preview 5/min; export 1/10 min; all other endpoints 120/min/user.
- [ ] Structured safe logs, request IDs, health monitoring, queue failure monitoring.
- [ ] Verify no passwords, tokens, or full URLs appear in logs.
- [ ] Review CORS, HTTPS, secrets, debug mode, database least privilege, queue worker isolation, backup/restore.
- **Gate:** security checklist complete and deployment smoke tests pass.

### Phase 8 — Release candidate
- [ ] Run full automated suite and static analysis/formatting configured by repository.
- [ ] Run migration from a clean database and verify rollback strategy.
- [ ] Validate all 32 endpoint operations against the API reference.
- [ ] Verify Android client compatibility with real JSON contract examples.
- [ ] Test production queue worker, scheduler, email delivery, and health monitoring.
- [ ] No unresolved critical/high security findings.
- [ ] Update endpoint matrix and status files with test evidence.
- **Gate:** release approved only when every required endpoint has acceptance evidence.

## 6. Global acceptance criteria

- All endpoints in `docs/api/endpoints.md` implemented or explicitly blocked by a recorded decision.
- API contract shapes and error codes match the source reference.
- Every user-owned query is scoped by authenticated user.
- Cross-user IDs return 404 without leaking existence.
- Database constraints protect critical invariants where practical.
- All state-changing operations have validation and relevant tests.
- Metadata failures do not fail link saving.
- No unsafe remote fetch path can reach private/internal networks.
- No secrets or sensitive URL/token data in logs.
- Tests run reproducibly in documented local/CI setup.
- Documentation reflects actual code, not intended code.

## 7. Definition of Done for each feature

A feature is `DONE` only when:
1. Request validation and authorization/ownership are implemented.
2. Persistence and transaction boundaries are correct.
3. Response fields and status codes match the contract.
4. Success, validation, failure, boundary, and security tests pass.
5. Relevant docs and endpoint status are updated.
6. No duplicate routes/classes/migrations were introduced.
7. Code has been reviewed against existing repository conventions.
8. Test command and evidence are recorded.

## 8. Progress tracking

Track endpoint-level status in `docs/api/endpoint-status.md`, feature status in `docs/features/feature-status.md`, and unresolved decisions in `docs/decisions/open-questions.md`. Do not mark a checkbox complete without evidence (test name, command, or reviewed implementation path).
