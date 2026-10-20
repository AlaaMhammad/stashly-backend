# Endpoint Implementation Status

Update only after checking code and tests. Initial status is intentionally `NOT STARTED`; no implementation has been verified from this documentation task.

| ID | Endpoint | Status | Evidence / test command | Notes |
|---|---|---|---|---|
| API-SYS-001 | GET /health | IN PROGRESS | `php artisan test --filter=HealthTest` (6 passed) | Implemented and tested on SQLite. DEC-017 and DEC-019 accepted provisionally (2026-10-20). Open: MySQL run unverified. |
| API-SYS-002 | GET /config | IN PROGRESS | `php artisan test --filter=ConfigTest` (3 passed) | Implemented and tested. Open: `limits` key names (DEC-008), release values and legal URLs unset (DEC-010). |
| API-AUTH-001 | POST /auth/register | IN PROGRESS | `php artisan test --filter=RegisterTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-AUTH-002 | POST /auth/login | IN PROGRESS | `php artisan test --filter=LoginTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-AUTH-003 | POST /auth/logout | IN PROGRESS | `php artisan test --filter=TokenLifecycleTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-AUTH-004 | POST /auth/refresh | IN PROGRESS | `php artisan test --filter=TokenLifecycleTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-AUTH-005 | POST /auth/forgot-password | IN PROGRESS | `php artisan test --filter=PasswordResetTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). Real mail transport not tested (no SMTP configured). |
| API-AUTH-006 | POST /auth/reset-password | IN PROGRESS | `php artisan test --filter=PasswordResetTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-AUTH-007 | PUT /me/password | IN PROGRESS | `php artisan test --filter=ProfileTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-PROF-001 | GET /me | IN PROGRESS | `php artisan test --filter=ProfileTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-PROF-002 | PATCH /me | IN PROGRESS | `php artisan test --filter=ProfileTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). Sending `email` is rejected (DEC-022). |
| API-PROF-003 | GET /me/stats | NOT STARTED | — | Deferred: needs links/collections tables (Phases 3-5). |
| API-PROF-004 | GET /me/export | NOT STARTED | — | |
| API-PROF-005 | DELETE /me | IN PROGRESS | `php artisan test --filter=ProfileTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). Later user-owned tables must cascade and extend the test. |
| API-LINK-001 | POST /links/preview | NOT STARTED | — | |
| API-LINK-002 | POST /links | NOT STARTED | — | |
| API-LINK-003 | GET /links | NOT STARTED | — | |
| API-LINK-004 | GET /links/{link} | NOT STARTED | — | |
| API-LINK-005 | PATCH /links/{link} | NOT STARTED | — | |
| API-LINK-006 | DELETE /links/{link} | NOT STARTED | — | |
| API-LINK-007 | PUT /links/{link}/collections | NOT STARTED | — | |
| API-LINK-008 | PUT /links/{link}/tags | NOT STARTED | — | |
| API-LINK-009 | POST /links/{link}/metadata/refresh | NOT STARTED | — | |
| API-COLL-001 | GET /collections | NOT STARTED | — | |
| API-COLL-002 | POST /collections | NOT STARTED | — | |
| API-COLL-003 | GET /collections/{collection} | NOT STARTED | — | |
| API-COLL-004 | PATCH /collections/{collection} | NOT STARTED | — | |
| API-COLL-005 | DELETE /collections/{collection} | NOT STARTED | — | |
| API-COLL-006 | PATCH /collections/{collection}/links | NOT STARTED | — | |
| API-TAG-001 | GET /tags | NOT STARTED | — | |
| API-SRCH-001 | GET /search/filters | NOT STARTED | — | |
| API-HOME-001 | GET /home | NOT STARTED | — | |

Status meanings: `NOT STARTED`, `IN PROGRESS`, `BLOCKED`, `DONE`. `DONE` requires code review, passing tests, and evidence.
