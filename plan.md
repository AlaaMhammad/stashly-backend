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
2. **Project-specific implementation decisions:** `Stashly_Backend_Docs/decisions/`.
3. **This plan:** delivery sequence and acceptance gates.

Do not silently resolve contradictions or fill gaps with guessed product behavior. Log ambiguities and decisions in `Stashly_Backend_Docs/decisions/open-questions.md`.

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
- [ ] Create/update `Stashly_Backend_Docs/` from this documentation set.
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
- [x] `GET /me`, `PATCH /me`, `GET /me/stats`. *(Evidence: `ProfileTest`; `/me/stats` added in Phase 5: `OverviewEndpointsTest::test_me_stats`.)*
- [x] Delete account only after password verification and remove all associated data/tokens. *(Evidence: `ProfileTest`. Every later user-owned table must use `cascadeOnDelete()` and extend the deletion test.)*
- [x] Implement the password reset web page and Terms/Privacy pages/URLs. *(Reset page: `PasswordResetTest`. Terms/Privacy: **draft** pages `GET /terms` and `GET /privacy` (version `draft-1`, visible draft banner), `/config` defaults to them and honors the `STASHLY_*` overrides: `LegalPagesTest`, `ConfigTest`. Final legal copy still has to replace the drafts before launch: DEC-021.)*
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
- [x] Cursor-paginated `GET /links`, default 20/max 50, newest first. *(Evidence: `ListLinksTest`; keyset on (created_at, id), signed cursor bound to user + filters.)*
- [x] Implement filters: q, favorite, content_type, source/domain, collection_id, tags, saved_within_days, sort. *(Evidence: `ListLinksTest`; `collection_id` and `tags` added in Phase 5.)*
- [x] Several tags use AND (link must have all); values within other same filter use OR; different filter categories combine with AND. *(Evidence: `ListLinksTest::test_several_tags_must_all_match`, `test_values_within_one_filter_are_ored`, `test_different_filters_are_anded`.)*
- [x] Search title, fetched title, description, note, and domain; case-insensitive and Arabic-aware (diacritic handling and alef normalization). *(Evidence: `ListLinksTest` search tests, `Tests\Unit\SearchTextTest`.)*
- [x] Detail endpoint includes metadata, collections, tags. *(Evidence: `LinkItemTest`, `LinkRelationsTest`.)*
- [x] PATCH only permits title, note, is_favorite; null/empty clears title/note; reject read-only fields. *(Evidence: `LinkItemTest`; every non-editable body field is rejected.)*
- [x] Delete link. *(Evidence: `LinkItemTest::test_delete_removes_the_link_permanently`.)*
- [x] Replace link collections atomically; empty array means Unsorted. *(Done in Phase 5. Evidence: `LinkRelationsTest`.)*
- [x] Replace link tags; normalize tags per user; delete tags that have no linked links. *(Done in Phase 5. Evidence: `LinkRelationsTest`; deleting a link also prunes its unused tags.)*
- [x] Refresh metadata endpoint, max once/minute/link. *(Evidence: `LinkItemTest` refresh tests; also 5/min per user (DEC-003). The job it queues is a Phase 6 placeholder.)*
- **Gate:** filtering combinations, ownership isolation, read-only field rejection, and cursor validity tests pass.

### Phase 5 — Collections, tags, home, filter options
- [x] Collections list with counts and up to four cover image URLs. *(Evidence: `CollectionTest`; covers per DEC-012; fixed query count verified.)*
- [x] Create collection: name 1–60, description max 300, case-insensitive unique per user, max 100 per user. *(Evidence: `CollectionTest`; unique index on lowercased `name_key` (binary collation on MySQL); user-row lock serializes the 100 check; 101st → `LIMIT_EXCEEDED`.)*
- [x] Detail, update, delete; deleting collection preserves links and links without another collection become Unsorted. *(Evidence: `CollectionTest::test_delete_keeps_links_and_leaves_them_unsorted_or_in_other_collections`.)*
- [x] Atomic attach/detach endpoint; max 100 IDs in each list and at least one non-empty. *(Evidence: `CollectionLinksTest`; a foreign id fails the whole request.)*
- [x] Tags list with prefix search, usage counts, sort, max 100. *(Evidence: `OverviewEndpointsTest`.)*
- [x] Filter options endpoint with counts. *(Evidence: `OverviewEndpointsTest`; option shapes provisional, DEC-028.)*
- [x] Home endpoint with recent limit default 10/max 20. *(Evidence: `OverviewEndpointsTest`; quick_access rule provisional, DEC-029.)*
- **Gate:** relationship integrity, count correctness, limits, and deletion semantics pass.

### Phase 6 — Metadata queue and SSRF hardening
- [x] Metadata job fetches HTML and extracts title, description, site name, favicon URL, preview image URL, content type. *(Evidence: `MetadataJobTest`, `Tests\Unit\Metadata\MetadataExtractorTest`; one live HTTPS lookup of example.com verified manually.)*
- [x] State machine: pending → ready / failed / blocked. *(Evidence: `MetadataJobTest`; request-id guard makes superseded jobs no-ops.)*
- [x] Retry only temporary errors (429, 5xx, timeout), at 2 minutes, 15 minutes, 2 hours; at most 4 attempts. *(Evidence: `MetadataJobTest::test_temporary_failures_are_retried_after_2_minutes_15_minutes_and_2_hours_then_fail`.)*
- [x] Only ports 80/443; DNS resolve and reject private, loopback, link-local, cloud metadata IPs, and IPv6 equivalents. *(Evidence: `IpPolicyTest`, `Tests\Feature\Security\SsrfTest`.)*
- [x] Connect to the checked IP to reduce DNS rebinding risk. *(Evidence: `CurlTransportTest::test_connection_is_pinned_to_the_given_ip_while_the_hostname_is_kept`, `SsrfTest::test_connection_is_pinned_to_the_checked_address_even_if_dns_changes`; real HTTPS with certificate check verified manually.)*
- [x] Re-check every redirect; max 3 redirects. *(Evidence: `SsrfTest` redirect tests.)*
- [x] Timeout 8 seconds (4 seconds for preview); maximum 512 KB HTML; HTML only; no cookies. *(Evidence: `SsrfTest`, `CurlTransportTest`. Caveat: DNS lookup time is not covered by the timeout, DEC-030.)*
- [ ] Run worker without access to internal services. *(Application-level controls done. Network isolation is a deployment task; see `operations/deployment-and-operations.md`. UNVERIFIED.)*
- [x] Never download images/video or overwrite user title/note. *(Evidence: only the HTML page is requested; image/favicon values are stored as URLs; `MetadataJobTest::test_success_stores_metadata_and_never_touches_the_users_title_or_note`.)*
- [x] Retry refresh max once/minute/link. *(Done in Phase 4: `LinkItemTest`.)*
- [x] Scheduled cleanup marks pending metadata older than 15 minutes as recovered/failed according to documented implementation policy; expired tokens cleanup as applicable. *(Evidence: `StaleMetadataTest` (DEC-006: failed / STALE_PENDING), token prune in `TokenLifecycleTest`.)*
- **Gate:** SSRF test suite, redirect tests, timeout/size limits, queue retry behavior, and stuck-job cleanup pass.

### Phase 7 — Export, observability, and hardening
- [x] Export all user data as a JSON attachment; limit 1 per 10 minutes. *(Evidence: `ExportTest`; streamed in keyset chunks, 1203-link library verified.)*
- [x] Ensure export includes only the authenticated user's data and agreed data schema. *(Evidence: `ExportTest::test_never_contains_another_users_data_or_any_secret`, schema version 1: DEC-013 accepted provisionally.)*
- [x] Verify all rate limits: login 5/min email+IP; register 10/hour/IP; forgot password 3/hour/email; preview 20/min; save 60/min; refresh-preview 5/min; export 1/10 min; all other endpoints 120/min/user. *(Evidence: `RateLimitTest`, per-endpoint tests, and `RouteMatrixTest` which checks every one of the 32 routes has exactly the contract limiters. Per-IP keys need `TRUSTED_PROXIES` behind a load balancer.)*
- [x] Structured safe logs, request IDs, health monitoring, queue failure monitoring. *(Evidence: `ObservabilityTest` (request id in log context, failed-job and backlog entries, `queue:monitor` every minute), `HealthTest`. Alert delivery itself is infrastructure: UNVERIFIED.)*
- [x] Verify no passwords, tokens, or full URLs appear in logs. *(Evidence: `ObservabilityTest::test_logs_never_contain_secrets_emails_or_urls_across_all_flows`, `SensitiveDataLoggingTest`; database errors log only SQLSTATE.)*
- [ ] Review CORS, HTTPS, secrets, debug mode, database least privilege, queue worker isolation, backup/restore. *(Code-level done: CORS closed by default, HSTS + HTTPS URL forcing in production, `stashly:preflight`, generic errors with debug on/off. UNVERIFIED, deployment: TLS termination, DB privileges, worker isolation, backup/restore.)*
- **Gate:** security checklist complete and deployment smoke tests pass.

### Phase 8 — Release candidate
- [x] Run full automated suite and static analysis/formatting configured by repository. *(610 passed; Pint passed; `composer audit` clean. No static analyzer is configured in the repository. Evidence: `operations/release-candidate.md`.)*
- [ ] Run migration from a clean database and verify rollback strategy. *(Done on SQLite: migrate, rollback, fresh, seed. MySQL UNVERIFIED.)*
- [x] Validate all 32 endpoint operations against the API reference. *(Evidence: `ApiReferenceContractTest`, `RouteMatrixTest`. Against the written reference only.)*
- [ ] Verify Android client compatibility with real JSON contract examples. *(UNVERIFIED: no Android models or real payloads exist; DEC-002, DEC-008.)*
- [ ] Test production queue worker, scheduler, email delivery, and health monitoring. *(UNVERIFIED: registration and logic tested only; needs staging.)*
- [ ] No unresolved critical/high security findings. *(None known in code. Worker isolation, TLS, backups and MySQL-specific behavior are unverified deployment items.)*
- [x] Update endpoint matrix and status files with test evidence. *(Done; no endpoint is DONE pending the gate below.)*
- **Gate:** release approved only when every required endpoint has acceptance evidence.

### Phase 9 - Google Sign-In (owner-requested extension, DEC-035)
- [x] `POST /api/v1/auth/google` (`id_token`, `device_name`, `terms_accepted`, `terms_version`): 200 existing/linked, 201 new, same `{user, token}` shape as login. *(Evidence: `GoogleAuthTest`.)*
- [x] Local verification of the Google ID token (RS256 signature against Google's key set, `iss`, `aud`, `exp`, `email_verified`) in a mockable `GoogleIdTokenVerifier`; tokens never logged. *(Evidence: `GoogleIdTokenVerifierTest`, `GoogleAuthTest::test_logs_never_contain_the_token_the_email_or_google_ids`.)*
- [x] Account rules: link only on a Google-verified e-mail, never take over an account linked to another Google id, terms required for a new account. *(Evidence: `GoogleAuthTest`.)*
- [x] Migration: `users.google_id` (unique, nullable), `avatar_url`, nullable `password`; password login on passwordless accounts is a normal 401. *(Evidence: forward/backward check on SQLite, `GoogleAuthTest`.)*
- [x] Rate limit `google-auth` (10/min per IP), `auth.google_enabled` in `GET /config`, `GOOGLE_CLIENT_ID` placeholder, preflight warning. *(Evidence: `GoogleAuthTest`, `ConfigTest`, `PreflightTest`, `RouteMatrixTest`.)*
- [x] Postman collection and environment updated. *(Evidence: static audit, newman full run 0 failures, mock run of the new request.)*
- [ ] Exercise with a real Google ID token and the real key endpoint. *(UNVERIFIED: needs the real client id and a device.)*
- [x] DEC-036 (Google-only account deletion via fresh `id_token`, `password_set` on the user object) and DEC-037 (linking clears the password) implemented and tested; both pending Android confirmation.
- [ ] Open: password change for Google-only users stays via forgot-password; e-mail verification at registration; sign-in provider in the export.
- **Gate:** Android owner confirms the contract; a real-token smoke test passes.

### Phase 10 - On-demand AI with Gemini (owner-requested extension, DEC-038)

- [x] Additive migration (`links.ai_summary`, `ai_tags`, `ai_generated_at`); config `GEMINI_API_KEY`, `GEMINI_MODEL=gemini-3.5-flash-lite`, `GEMINI_TIMEOUT=20`; `/config` `ai.enabled`; preflight warning.
- [x] `POST /links/{link}/summarize` and `POST /ai/ask`: synchronous, no cron/queue, owner-scoped, 503/504/502 error codes, `ai` limiter (10/min/user).
- [x] Tests with a faked Gemini (54), RouteMatrixTest at 35 operations, Postman folder 09, Arabic report in `Stashly_Backend_Docs/تقارير/ai_features_report.md`.
- [ ] Open: real Gemini smoke test with a real key (nothing has called Gemini yet), free-tier terms and quotas, Android confirmation, MySQL run.

## 6. Global acceptance criteria

- All endpoints in `Stashly_Backend_Docs/api/endpoints.md` implemented or explicitly blocked by a recorded decision.
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

Track endpoint-level status in `Stashly_Backend_Docs/api/endpoint-status.md`, feature status in `Stashly_Backend_Docs/features/feature-status.md`, and unresolved decisions in `Stashly_Backend_Docs/decisions/open-questions.md`. Do not mark a checkbox complete without evidence (test name, command, or reviewed implementation path).
