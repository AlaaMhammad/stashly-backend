# Feature Status Tracker

Initial state is `NOT STARTED`. Update status only with implementation and test evidence.

| Feature | Status | Acceptance evidence |
|---|---|---|
| API foundation and response envelope | IN PROGRESS | `/api/v1` prefix, JSON-only API, error envelope with `request_id`, ULID users, UTC dates. `ErrorEnvelopeTest`, `UlidAndDateTest`, `Tests\Unit\ApiFormattingTest` pass (Phase 1). DEC-018: Approved v1 baseline — pending Android confirmation. Open: MySQL migration run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| Health/config | IN PROGRESS | `HealthTest`, `ConfigTest` pass (Phase 1). Decisions: DEC-008 and DEC-010 Closed; DEC-019 Final; DEC-017: Approved v1 baseline — pending Android confirmation. |
| Registration/login/token lifecycle | IN PROGRESS | `RegisterTest`, `LoginTest`, `TokenLifecycleTest` pass (Phase 2). Open: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| Forgot/reset/change password | IN PROGRESS | `PasswordResetTest`, `ProfileTest` pass (Phase 2). Open: real mail transport untested. |
| Profile/stats | IN PROGRESS | `ProfileTest`, `OverviewEndpointsTest::test_me_stats` pass. Only open item: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| Account export/deletion | IN PROGRESS | `ExportTest`, `ProfileTest` pass. Open: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS) and staging validation. |
| URL normalization | IN PROGRESS | `UrlNormalizer` with matrix in `Tests\Unit\Links\UrlNormalizerTest` (Phase 3). Decisions DEC-004 and DEC-026 Final; reused by the metadata fetch (Phase 6). |
| Link preview/save/idempotency/duplicate handling | IN PROGRESS | `SaveLinkTest`, `IdempotencyTest`, `LinkPreviewTest` pass (Phase 3), including the simulated concurrent duplicate; live preview metadata (Phase 6) and collections/tags on save (Phase 5, DEC-024 superseded) are implemented and tested. DEC-005 Final. Open: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| Link list/search/filter/cursor pagination | IN PROGRESS | `ListLinksTest`, `SearchTextTest`, `LinkRelationsTest` (query count) pass. DEC-027: Approved v1 baseline — pending Android confirmation. Open: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| Link edit/delete/favorite | IN PROGRESS | `LinkItemTest` passes (Phase 4). Only open item: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| Collections and relationship updates | IN PROGRESS | `CollectionTest`, `CollectionLinksTest`, `LinkRelationsTest` pass (Phase 5). DEC-028: Approved v1 baseline — pending Android confirmation. Open: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS), especially collation. |
| Tags and normalization | IN PROGRESS | `LinkRelationsTest`, `OverviewEndpointsTest` pass (Phase 5). DEC-028: Approved v1 baseline — pending Android confirmation. Open: MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| Home and search filters | IN PROGRESS | `OverviewEndpointsTest` passes (Phase 5). DEC-028 and DEC-029: Approved v1 baseline — pending Android confirmation. |
| Metadata extraction job | IN PROGRESS | `MetadataJobTest`, `MetadataExtractorTest`, `StaleMetadataTest` pass (Phase 6); live HTTPS lookup verified manually. DEC-030: Approved v1 baseline — pending Android confirmation. Open: worker deployment and MySQL run unverified (DEC-015 Closed: target MySQL 8.4 LTS). |
| SSRF protections | IN PROGRESS | `IpPolicyTest`, `SsrfTest` (88), `CurlTransportTest` (real curl vs local server) pass; 19 mutation checks caught. Open: network-level worker isolation and egress limits (deployment, UNVERIFIED), alert delivery for blocked attempts (logging done in Phase 7). Known limit: DNS lookup time is not bounded (DEC-030: Approved v1 baseline — pending Android confirmation). |
| Rate limiting | IN PROGRESS | `RateLimitTest`, per-endpoint tests and `RouteMatrixTest` (every one of the 32 routes has the contract limiters) pass. DEC-003 Final. Open: per-IP keys need `TRUSTED_PROXIES` set in production. |
| Password reset page and legal pages | IN PROGRESS | Reset page tested (`PasswordResetTest`). Draft Terms/Privacy pages (`draft-1`) and `/config` defaults tested (`LegalPagesTest`, `ConfigTest`). DEC-021 Closed (draft pages). Open: final legal copy must replace the drafts before launch. |
| Export, monitoring, scheduled cleanup | IN PROGRESS | Export, scheduled cleanup, failed-job and backlog logging done and tested. Alert delivery and production cron are deployment tasks (UNVERIFIED). |
| Production hardening | IN PROGRESS | CORS closed, security headers, HSTS, https URL forcing, trusted proxies, `stashly:preflight`, safe DB-error logging done and tested (Phase 7). Open: TLS, DB privileges, worker isolation, backup/restore, alerting (deployment). |
