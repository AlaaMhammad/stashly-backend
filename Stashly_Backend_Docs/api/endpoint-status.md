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
| API-AUTH-008 | POST /auth/google | IN PROGRESS | `php artisan test --filter="GoogleAuthTest\|GoogleIdTokenVerifierTest"` | Implemented and tested on SQLite with real RSA-signed tokens and a faked Google key endpoint (DEC-035). **Never exercised with a real Google token** (needs the real `GOOGLE_CLIENT_ID`). DEC-036 and DEC-037 implemented (Approved v1 baseline - pending Android confirmation). Open: Android confirmation, MySQL run. |
| API-AUTH-007 | PUT /me/password | IN PROGRESS | `php artisan test --filter=ProfileTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-PROF-001 | GET /me | IN PROGRESS | `php artisan test --filter=ProfileTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). |
| API-PROF-002 | PATCH /me | IN PROGRESS | `php artisan test --filter=ProfileTest` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). Sending `email` is rejected (DEC-022). |
| API-PROF-003 | GET /me/stats | IN PROGRESS | `php artisan test --filter=OverviewEndpointsTest` | SQLite only; MySQL unverified (DEC-015). |
| API-PROF-004 | GET /me/export | IN PROGRESS | `php artisan test --filter=ExportTest` | JSON attachment, schema version 1 (DEC-013), streamed, own data only, 1/10 min. SQLite only; MySQL unverified (DEC-015). |
| API-PROF-005 | DELETE /me | IN PROGRESS | `php artisan test --filter="ProfileTest\|GoogleAuthTest"` | Implemented and tested on SQLite; MySQL run unverified (DEC-015). Later user-owned tables must cascade and extend the test. |
| API-LINK-001 | POST /links/preview | IN PROGRESS | `php artisan test --filter=LinkPreviewTest` | Live lookup within 4 s for unsaved URLs; saved URLs return their stored metadata; failures give `metadata: null` (DEC-030). Plan Phase 3 item for the 4 s fetch is now met, DNS time excluded. SQLite only; MySQL unverified (DEC-015). |
| API-LINK-002 | POST /links | IN PROGRESS | `php artisan test --filter="SaveLinkTest\|IdempotencyTest\|LinkRelationsTest\|MetadataJobTest"` | Queues the metadata job on the `metadata` queue after commit; the real job now fetches. SQLite only; MySQL unverified (DEC-015). |
| API-LINK-003 | GET /links | IN PROGRESS | `php artisan test --filter=ListLinksTest` | All filters incl. collection_id (OR) and tags (AND); fixed query count. SQLite only; MySQL unverified (DEC-015). |
| API-LINK-004 | GET /links/{link} | IN PROGRESS | `php artisan test --filter=LinkItemTest` | Includes collections and tags. SQLite only; MySQL unverified (DEC-015). |
| API-LINK-005 | PATCH /links/{link} | IN PROGRESS | `php artisan test --filter=LinkItemTest` | title/note/is_favorite only; all other body fields rejected. Only open item: MySQL run. |
| API-LINK-006 | DELETE /links/{link} | IN PROGRESS | `php artisan test --filter=LinkItemTest` | Only open item: MySQL run. |
| API-LINK-007 | PUT /links/{link}/collections | IN PROGRESS | `php artisan test --filter=LinkRelationsTest` | Full replace; empty = Unsorted; foreign collection → 422. SQLite only; MySQL unverified (DEC-015). |
| API-LINK-008 | PUT /links/{link}/tags | IN PROGRESS | `php artisan test --filter=LinkRelationsTest` | Normalized, max 20 (LIMIT_EXCEEDED), unused tags pruned. SQLite only; MySQL unverified (DEC-015). |
| API-LINK-009 | POST /links/{link}/metadata/refresh | IN PROGRESS | `php artisan test --filter="LinkItemTest\|MetadataJobTest"` | 202 + pending + new lookup; superseded jobs are no-ops; 1/min per link and 5/min per user (DEC-003). SQLite only; MySQL unverified (DEC-015). |
| API-AI-001 | POST /links/{link}/summarize | IN PROGRESS | `php artisan test --filter=LinkSummarizeTest` | Gemini is faked in every test (DEC-038). **No real Gemini call made.** Persists `ai_*`, attaches tags, 503/504/502 handling. SQLite only; MySQL unverified. |
| API-AI-002 | POST /ai/ask | IN PROGRESS | `php artisan test --filter=AskAiTest` | Gemini faked (DEC-038). Own links only, 30 most recent, references validated. **No real Gemini call made.** SQLite only; MySQL unverified. |
| API-COLL-001 | GET /collections | IN PROGRESS | `php artisan test --filter=CollectionTest` | Sort recent/name, keyset cursor, counts, covers (DEC-012). SQLite only; MySQL unverified (DEC-015). |
| API-COLL-002 | POST /collections | IN PROGRESS | `php artisan test --filter=CollectionTest` | Case-insensitive unique name; 100 limit under concurrency. Collation behavior needs the MySQL run (DEC-015). |
| API-COLL-003 | GET /collections/{collection} | IN PROGRESS | `php artisan test --filter=CollectionTest` | SQLite only; MySQL unverified (DEC-015). |
| API-COLL-004 | PATCH /collections/{collection} | IN PROGRESS | `php artisan test --filter=CollectionTest` | SQLite only; MySQL unverified (DEC-015). |
| API-COLL-005 | DELETE /collections/{collection} | IN PROGRESS | `php artisan test --filter=CollectionTest` | Links kept. SQLite only; MySQL unverified (DEC-015). |
| API-COLL-006 | PATCH /collections/{collection}/links | IN PROGRESS | `php artisan test --filter=CollectionLinksTest` | Atomic; foreign ids fail the whole request. SQLite only; MySQL unverified (DEC-015). |
| API-TAG-001 | GET /tags | IN PROGRESS | `php artisan test --filter=OverviewEndpointsTest` | Defaults provisional (DEC-028). SQLite only; MySQL unverified (DEC-015). |
| API-SRCH-001 | GET /search/filters | IN PROGRESS | `php artisan test --filter=OverviewEndpointsTest` | Option shapes provisional (DEC-028). SQLite only; MySQL unverified (DEC-015). |
| API-HOME-001 | GET /home | IN PROGRESS | `php artisan test --filter=OverviewEndpointsTest` | quick_access rule provisional (DEC-029). SQLite only; MySQL unverified (DEC-015). |

Status meanings: `NOT STARTED`, `IN PROGRESS`, `BLOCKED`, `DONE`. `DONE` requires code review, passing tests, and evidence.
