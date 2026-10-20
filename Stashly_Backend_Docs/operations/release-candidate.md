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

## Not verified — blocks release

1. **MySQL (DEC-015).** No credentials were provided; the local server is MariaDB 10.4. Never run against MySQL: migrations, the unique indexes (links URL hash, collection name key, tag name), binary-collation behavior for case-insensitive names and accents, `LIKE` search behavior, the 100-collection row lock, the window function used for collection covers, and idempotency/lock behavior under real concurrency. The plan requires this (`testing-strategy.md`).
2. **Real concurrency.** Races (duplicate save, duplicate collection, idempotency key) are simulated by injecting the second write; no multi-process test was run.
3. **Real e-mail delivery.** Reset mail is queued and rendered in tests; no SMTP transport was exercised.
4. **Production queue worker.** `queue:work --queue=metadata` was not run against a real queue backend; only job logic is tested.
5. **Worker network isolation and egress limits** (`ssrf.md` runtime isolation): infrastructure task.
6. **TLS termination/HTTPS enforcement, DB privileges, backups and restore, alert delivery, production cron.**
7. **Android client compatibility.** Checked against the written API reference only. No Android models or real payload fixtures exist (DEC-002, DEC-008); response shapes for `/config`, `/home`, `/search/filters`, `/health`, preview and export are provisional.
8. **Automated TLS-verification test** (needs a TLS server); checked manually only.

## Open decisions that affect the client contract

DEC-002, DEC-008 (response shapes), DEC-003 (refresh-preview limit meaning), DEC-005, DEC-007, DEC-013, DEC-017, DEC-018, DEC-019, DEC-025, DEC-027, DEC-028, DEC-029, DEC-030, DEC-031 are accepted or implemented **provisionally**; DEC-009 (deletion strategy for very large accounts), DEC-010 (release config), DEC-021 (Terms/Privacy page content — pages not built), DEC-023 (bcrypt vs Argon2id) remain open. See `decisions/open-questions.md`.

## Known gaps and limits

- **Terms and Privacy pages are not built** (DEC-021, needs legal text). `GET /config` serves whatever URLs are configured. This is a contract requirement ("Also Needed").
- DNS lookup time is not bounded by the 8 s / 4 s limits (DEC-030).
- Search is a substring scan over the user's own links; large libraries may need a FULLTEXT index.
- Account deletion is one transaction (DEC-009); very large accounts are untested.
- Local PHP 8.2.12 is old and 8.2 is security-fixes-only until 31 Dec 2026; MariaDB 10.4 is end-of-life.
- No CI configuration exists; the commands above are the manual equivalent.

## Steps to reach release approval

1. Provide MySQL (target version) and run: `php artisan migrate:fresh --seed`, then the whole suite with `DB_CONNECTION=mysql` and a dedicated test database (the phpunit config uses SQLite today; add a MySQL run to CI).
2. Confirm or change the provisional decisions with the Android owner; supply real payload fixtures and add them to the contract test.
3. Staging deployment: run `php artisan stashly:preflight`, the metadata worker with restricted egress, the scheduler, real SMTP; walk the smoke tests in `deployment-and-operations.md`.
4. Provide Terms/Privacy content or hosting (DEC-021).
5. Re-run this checklist; only then mark endpoints `DONE`.
