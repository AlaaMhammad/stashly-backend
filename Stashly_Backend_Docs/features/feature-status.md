# Feature Status Tracker

Initial state is `NOT STARTED`. Update status only with implementation and test evidence.

| Feature | Status | Acceptance evidence |
|---|---|---|
| API foundation and response envelope | IN PROGRESS | `/api/v1` prefix, JSON-only API, error envelope with `request_id`, ULID users, UTC dates. `ErrorEnvelopeTest`, `UlidAndDateTest`, `Tests\Unit\ApiFormattingTest` pass (Phase 1). Open: DEC-018; MySQL migration run unverified. |
| Health/config | IN PROGRESS | `HealthTest`, `ConfigTest` pass (Phase 1). Open: DEC-008, DEC-010, DEC-017, DEC-019. |
| Registration/login/token lifecycle | IN PROGRESS | `RegisterTest`, `LoginTest`, `TokenLifecycleTest` pass (Phase 2). Open: MySQL run (DEC-015). |
| Forgot/reset/change password | IN PROGRESS | `PasswordResetTest`, `ProfileTest` pass (Phase 2). Open: real mail transport untested. |
| Profile/stats | IN PROGRESS | `GET/PATCH /me` tested (`ProfileTest`). `GET /me/stats` deferred until links/collections exist. |
| Account export/deletion | IN PROGRESS | Deletion tested (`ProfileTest`); export is Phase 7. |
| URL normalization | IN PROGRESS | `UrlNormalizer` with matrix in `Tests\Unit\Links\UrlNormalizerTest` (Phase 3). Open: DEC-004, DEC-026; reuse before fetch in Phase 6. |
| Link preview/save/idempotency/duplicate handling | IN PROGRESS | `SaveLinkTest`, `IdempotencyTest`, `LinkPreviewTest` pass (Phase 3), including the simulated concurrent duplicate. Open: preview metadata (Phase 6), collections/tags on save (Phase 5, DEC-024), DEC-005. |
| Link list/search/filter/cursor pagination | IN PROGRESS | `ListLinksTest`, `SearchTextTest` pass (Phase 4). Open: collection/tag filters (Phase 5), DEC-027, MySQL run. |
| Link edit/delete/favorite | IN PROGRESS | `LinkItemTest` passes (Phase 4). Only open item: MySQL run (DEC-015). |
| Collections and relationship updates | NOT STARTED | |
| Tags and normalization | NOT STARTED | |
| Home and search filters | NOT STARTED | |
| Metadata extraction job | NOT STARTED | |
| SSRF protections | NOT STARTED | |
| Rate limiting | IN PROGRESS | Named limiters defined (api, login, register, forgot-password, link-preview, link-save, export) with 429 `RATE_LIMITED` + `Retry-After`; `RateLimitTest` passes (Phase 1). Open: refresh-preview (DEC-003); wiring to real endpoints in later phases. |
| Password reset page and legal pages | BLOCKED | Reset page done and tested (`PasswordResetTest`). Terms/Privacy pages blocked on legal content (DEC-021). |
| Export, monitoring, scheduled cleanup | NOT STARTED | |
| Production hardening | NOT STARTED | |
