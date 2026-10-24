# Release Candidate Readiness — Phase 8

- **Date:** 2026-10-20
- **Verdict: NOT APPROVED for release.** All 32 operations are implemented and pass automated acceptance tests on SQLite, but the plan's gate requires real-database and deployment evidence that does not exist yet. No endpoint is marked `DONE` for that reason.

## Verified (evidence)

| Check | Command / test | Result |
|---|---|---|
| Full suite | `php artisan test` | 610 passed (4904 assertions) |
| Formatting | `vendor/bin/pint --test app config routes tests database bootstrap/app.php` | passed |
| Dependency advisories | `composer audit` | none |
| Manifest | `composer validate --no-check-publish` | valid |
| Clean-database migration | `migrate`, `migrate:rollback`, `migrate:fresh`, `db:seed` on a file SQLite database | all succeed; rollback removes every table |
| Backfill migrations | smoke-tested with pre-existing rows (search text, metadata tracking) | succeed |
| 32 operations vs API reference | `Tests\Feature\Contract\ApiReferenceContractTest` (one journey, 193 assertions) + `RouteMatrixTest` | every documented status code and response shape checked; exactly 32 routes, correct auth and limiters |
| Scheduler registration | `php artisan schedule:list` | `links:expire-stale-metadata` (5 min), `queue:monitor` (1 min), `sanctum:prune-expired`, `model:prune` (daily) |
| Security gates | `SsrfTest` (88), `CurlTransportTest` (real curl, 11), `HardeningTest`, `ObservabilityTest`, auth/enumeration/IDOR tests | pass; ~50 mutation checks across phases were all caught |
| Live outbound lookup | one real HTTPS fetch; self-signed and expired certificates | works; both certificates rejected (manual, not automated) |

## Update 2026-10-20 — readiness review (no code changed)

Verified findings only; details in [`database-readiness.md`](database-readiness.md) and [`../api/android-contract-review.md`](../api/android-contract-review.md).

- **Engine requirement derived from the code:** MySQL 8.0 or later (window function in `CollectionPresenter`); MySQL 5.7 is not supported. Production version still to be confirmed by the owner (DEC-015, DEC-033).
- **Local MariaDB 10.4.32** is suitable for *preliminary* checks only (schema, FKs, indexes, queries, row locks); it is not release evidence. Collation, accent handling, lock behavior and plans must be re-run on the target MySQL.
- **No database was connected to with credentials, no `.env` value was read, and no migration was run on MySQL/MariaDB** during this review.
- **New finding:** ULIDs are emitted in lowercase (canonical text is uppercase) — DEC-032 for the Android owner.
- **Android contract:** 5 endpoints compared against the reference using real captured responses; every difference is provisional and listed for confirmation.

## Requirements still missing, by type

### Content or decisions only you (or others outside the code) can supply
| Item | What is needed | Blocks |
|---|---|---|
| Terms of Service text | Final legal text (or a hosted URL), its version identifier, and who maintains it | Terms page; `STASHLY_TERMS_URL`, `STASHLY_TERMS_VERSION` |
| Privacy Policy text | Final legal text (or a hosted URL) | Privacy page; `STASHLY_PRIVACY_URL` |
| Hosting choice for legal pages | Hosted by this backend (then a Blade page per document is an implementation task) or elsewhere (then only config values) — DEC-021 | Contract section 5 |
| App versions | `STASHLY_MIN_SUPPORTED_APP_VERSION`, `STASHLY_LATEST_APP_VERSION` for the first release (DEC-010) | `/config` |
| Production MySQL version | Exact version and managed or self-hosted (DEC-015, DEC-033) | all DB evidence |
| Mail provider | SMTP host/port/credentials or provider, the From address and name, SPF/DKIM for the sending domain | reset e-mail delivery |
| Public base URL | HTTPS API host (`APP_URL`), used in reset links | reset page, https forcing |
| Android confirmations | Yes/no on X1–X5 and each "Decisions" line, ideally real payload fixtures | contract sign-off (DEC-002, DEC-008, DEC-017, DEC-028, DEC-029, DEC-030, DEC-032, DEC-034) |
| Open product decisions | DEC-009 deletion strategy at scale, DEC-023 bcrypt vs Argon2id (needs production PHP version) | scale and password policy |

### Implementation tasks (I can do them once the input exists or you approve)
| Task | Depends on |
|---|---|
| `phpunit.mysql.xml` and a test-database-name guard | your approval (proposed in `database-readiness.md`) |
| Run migrations, rollback and the full suite on a dedicated `stashly_test` database | the database exists and you approve the exact commands |
| MySQL-specific fixes, if the run finds any | results of the run |
| Terms and Privacy pages (Blade views + routes), if hosted here | legal text (DEC-021) |
| Contract fixtures from real Android payloads and any shape changes | Android owner answers |
| A real-SMTP smoke test of the reset e-mail | mail provider settings in a git-ignored environment |
| Optional: CI workflow (suite + Pint + audit + MySQL job) | where CI will run |

### Infrastructure / staging tasks (outside this repository)
| Item | Needed |
|---|---|
| Staging host | PHP 8.2+ with curl (CA bundle), MySQL 8.x, HTTPS with a valid certificate, `APP_ENV=production`, `APP_DEBUG=false`, `TRUSTED_PROXIES` set |
| Queue workers | Supervised `php artisan queue:work --queue=metadata` (isolated, outbound 80/443 only, no route to database admin, cache or cloud metadata address) and a separate worker for `default` (mail) |
| Scheduler | Cron `* * * * * php artisan schedule:run` |
| Mail | Real transport configured; queue worker running for mail |
| Monitoring | Something that polls `GET /health` and reads the log for `Queue backlog`, `Queue job failed`, `Database query failed`, `BLOCKED_HOST` |
| Database | Dedicated least-privilege app user (`SELECT, INSERT, UPDATE, DELETE`), separate migration user, backups and a tested restore |
| Smoke tests | The list in `deployment-and-operations.md`, plus `php artisan stashly:preflight` |

## Update 2026-10-21 - Phase 9 (Google Sign-In, DEC-035)

- The API now has **33 operations**: the reference's 32 plus the owner-requested `POST /auth/google`. `RouteMatrixTest` pins all 33 with their authentication and limiters; the Postman collection covers all 33.
- Verified: `GoogleAuthTest` (41) and `GoogleIdTokenVerifierTest` (53) with real RSA-signed tokens and a faked Google key endpoint; 18 mutation checks on the security logic were all caught; the migration was checked forward and backward on SQLite. SQLite suite: 756 passed.
- **Not verified:** the endpoint has **never seen a real Google ID token or Google's real key endpoint** (tests fake Google); that needs the real `GOOGLE_CLIENT_ID` and a device. The migration's `password` change on MySQL/MariaDB is untested. The Postman request was executed only against a mock (skipped by default without `google_id_token`).
- **Productivity suite (DEC-041):** import/export, public sharing, dead-link check and advanced search added (47 operations in total); not tested with real browser bookmark files or on MySQL; the 50 ms search target is unmeasured. Release verdict unchanged: not approved.
- **Phase 12 (DEC-040):** password reset now uses a 6-digit code sent immediately (breaking change for the Android reset screen); never tested with a real SMTP server. Release verdict unchanged: not approved.
- **Phase 11 (DEC-039):** four more on-demand AI endpoints (39 operations in total); never run against the real Gemini API. Release verdict unchanged: not approved.
- **Phase 10 (DEC-038):** on-demand Gemini AI added (2 endpoints, 35 operations in total); never run against the real Gemini API. Release verdict unchanged: not approved.
- **Decisions DEC-036 and DEC-037 implemented** (Approved v1 baseline - pending Android confirmation): a Google-only user deletes the account with a fresh Google `id_token` (the user object now carries `password_set`), and linking Google to an existing account clears its password and revokes its sessions. Android must handle the `id_token` delete body.
- The verdict is unchanged: **not approved for release**.

## Not verified — blocks release

1. **MySQL (DEC-015).** No credentials were provided; the local server is MariaDB 10.4. Never run against MySQL: migrations, the unique indexes (links URL hash, collection name key, tag name), binary-collation behavior for case-insensitive names and accents, `LIKE` search behavior, the 100-collection row lock, the window function used for collection covers, and idempotency/lock behavior under real concurrency. The plan requires this (`testing-strategy.md`).
2. **Real concurrency.** Races (duplicate save, duplicate collection, idempotency key) are simulated by injecting the second write; no multi-process test was run.
3. **Real e-mail delivery.** Reset mail is queued and rendered in tests; no SMTP transport was exercised.
4. **Production queue worker.** `queue:work --queue=metadata` was not run against a real queue backend; only job logic is tested.
5. **Worker network isolation and egress limits** (`ssrf.md` runtime isolation): infrastructure task.
6. **TLS termination/HTTPS enforcement, DB privileges, backups and restore, alert delivery, production cron.**
7. **Android client compatibility.** Checked against the written API reference only. No Android models or real payload fixtures exist (DEC-002, DEC-008); response shapes for `/config`, `/home`, `/search/filters`, `/health`, preview and export are provisional.
8. **Automated TLS-verification test** (needs a TLS server); checked manually only.

## Decision status (updated 2026-10-20)

All 11 previously open decisions are **closed** (DEC-001, 002, 008, 009, 010, 014, 015, 021, 032, 033, 034). The internal provisional decisions are **final** (DEC-003, 004, 005, 007, 011, 016, 019, 020, 022, 026, 031). The Android-facing decisions are an **approved v1 baseline pending final confirmation by the Android client owner** (DEC-013, 017, 018, 025, 027, 028, 029, 030); DEC-002, 008, 032 and 034 are closed but still recommended for Android confirmation. DEC-023: bcrypt kept. Production target engine: **MySQL 8.4 LTS**. See `decisions/open-questions.md`.

Approving decisions does not by itself allow `DONE`: the MySQL run, staging, real mail, worker/scheduler and Android confirmations below are still required.

## Known gaps and limits

- **Terms and Privacy pages are drafts** (DEC-021): `GET /terms` and `GET /privacy` serve placeholder text (version `draft-1`, visible banner, unfilled `[BRACKETS]`). Final legal copy and values for `STASHLY_TERMS_*` / `STASHLY_PRIVACY_URL` must replace them before launch; `stashly:preflight` warns while the draft is active.
- DNS lookup time is not bounded by the 8 s / 4 s limits (DEC-030).
- Search is a substring scan over the user's own links; large libraries may need a FULLTEXT index.
- Account deletion is one transaction (DEC-009); very large accounts are untested.
- Local PHP 8.2.12 is old and 8.2 is security-fixes-only until 31 Dec 2026; MariaDB 10.4 is end-of-life.
- No CI configuration exists; the commands above are the manual equivalent.

## Prioritized checklist and next safe action

| # | Action | Who | Status |
|---|---|---|---|
| 1 | **Next safe action:** approve (or amend) the dedicated database `stashly_test` and the exact commands in `database-readiness.md` section 4; create the database and user yourself | you | waiting |
| 2 | Approve adding `phpunit.mysql.xml` and the `_test` database-name guard | you | waiting |
| 3 | Run migrate, rollback, migrate, then the full suite on `stashly_test` (MariaDB 10.4 = preliminary) | me, after 1–2 | blocked on 1–2 |
| 4 | Confirm the production MySQL version; repeat step 3 on it | you, then me | blocked |
| 5 | Answer the Android contract questions (`android-contract-review.md`); supply payload fixtures | you / Android owner | blocked |
| 6 | Supply final Terms/Privacy copy (or URLs) and app versions; draft pages exist meanwhile | you | blocked for launch (drafts in place) |
| 7 | Provide mail provider settings (kept out of the repository) | you | blocked |
| 8 | Stand up staging: workers, scheduler, mail, monitoring; run smoke tests and `stashly:preflight` | you / ops, me for fixes | blocked |
| 9 | Re-run this record; only then consider marking endpoints `DONE` | both | not started |

Nothing in this checklist has been executed except items already listed under "Verified (evidence)".

## Steps to reach release approval

1. Provide MySQL (target version) and run: `php artisan migrate:fresh --seed`, then the whole suite with `DB_CONNECTION=mysql` and a dedicated test database (the phpunit config uses SQLite today; add a MySQL run to CI).
2. Confirm or change the provisional decisions with the Android owner; supply real payload fixtures and add them to the contract test.
3. Staging deployment: run `php artisan stashly:preflight`, the metadata worker with restricted egress, the scheduler, real SMTP; walk the smoke tests in `deployment-and-operations.md`.
4. Provide Terms/Privacy content or hosting (DEC-021).
5. Re-run this checklist; only then mark endpoints `DONE`.
