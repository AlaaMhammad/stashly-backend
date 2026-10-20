# Feature Status Tracker

Initial state is `NOT STARTED`. Update status only with implementation and test evidence.

| Feature | Status | Acceptance evidence |
|---|---|---|
| API foundation and response envelope | IN PROGRESS | `/api/v1` prefix, JSON-only API, error envelope with `request_id`, ULID users, UTC dates. `ErrorEnvelopeTest`, `UlidAndDateTest`, `Tests\Unit\ApiFormattingTest` pass (Phase 1). Open: DEC-018; MySQL migration run unverified. |
| Health/config | IN PROGRESS | `HealthTest`, `ConfigTest` pass (Phase 1). Open: DEC-008, DEC-010, DEC-017, DEC-019. |
| Registration/login/token lifecycle | IN PROGRESS | `RegisterTest`, `LoginTest`, `TokenLifecycleTest` pass (Phase 2). Open: MySQL run (DEC-015). |
| Forgot/reset/change password | IN PROGRESS | `PasswordResetTest`, `ProfileTest` pass (Phase 2). Open: real mail transport untested. |
| Profile/stats | IN PROGRESS | `ProfileTest`, `OverviewEndpointsTest::test_me_stats` pass. Only open item: MySQL run (DEC-015). |
| Account export/deletion | IN PROGRESS | `ExportTest`, `ProfileTest` pass. Only open items: MySQL run (DEC-015) and staging validation. |
| URL normalization | IN PROGRESS | `UrlNormalizer` with matrix in `Tests\Unit\Links\UrlNormalizerTest` (Phase 3). Open: DEC-004, DEC-026; reuse before fetch in Phase 6. |
| Link preview/save/idempotency/duplicate handling | IN PROGRESS | `SaveLinkTest`, `IdempotencyTest`, `LinkPreviewTest` pass (Phase 3), including the simulated concurrent duplicate. Open: preview metadata (Phase 6), collections/tags on save (Phase 5, DEC-024), DEC-005. |
| Link list/search/filter/cursor pagination | IN PROGRESS | `ListLinksTest`, `SearchTextTest`, `LinkRelationsTest` (query count) pass. Open: DEC-027, MySQL run. |
| Link edit/delete/favorite | IN PROGRESS | `LinkItemTest` passes (Phase 4). Only open item: MySQL run (DEC-015). |
| Collections and relationship updates | IN PROGRESS | `CollectionTest`, `CollectionLinksTest`, `LinkRelationsTest` pass (Phase 5). Open: DEC-028, MySQL collation run (DEC-015). |
| Tags and normalization | IN PROGRESS | `LinkRelationsTest`, `OverviewEndpointsTest` pass (Phase 5). Open: DEC-028, MySQL run. |
| Home and search filters | IN PROGRESS | `OverviewEndpointsTest` passes (Phase 5). Open: DEC-028, DEC-029. |
| Metadata extraction job | IN PROGRESS | `MetadataJobTest`, `MetadataExtractorTest`, `StaleMetadataTest` pass (Phase 6); live HTTPS lookup verified manually. Open: DEC-030, worker deployment and MySQL run. |
| SSRF protections | IN PROGRESS | `IpPolicyTest`, `SsrfTest` (88), `CurlTransportTest` (real curl vs local server) pass; 19 mutation checks caught. Open: network-level worker isolation and egress limits (deployment, UNVERIFIED), blocked-attempt alerting (Phase 7), DNS time not bounded (DEC-030). |
| Rate limiting | IN PROGRESS | `RateLimitTest`, per-endpoint tests and `RouteMatrixTest` (every one of the 32 routes has the contract limiters) pass. Open: refresh-preview meaning (DEC-003, provisional), per-IP keys need `TRUSTED_PROXIES` in production. |
| Password reset page and legal pages | BLOCKED | Reset page done and tested (`PasswordResetTest`). Terms/Privacy pages blocked on legal content (DEC-021). |
| Export, monitoring, scheduled cleanup | IN PROGRESS | Export, scheduled cleanup, failed-job and backlog logging done and tested. Alert delivery and production cron are deployment tasks (UNVERIFIED). |
| Production hardening | IN PROGRESS | CORS closed, security headers, HSTS, https URL forcing, trusted proxies, `stashly:preflight`, safe DB-error logging done and tested (Phase 7). Open: TLS, DB privileges, worker isolation, backup/restore, alerting (deployment). |
