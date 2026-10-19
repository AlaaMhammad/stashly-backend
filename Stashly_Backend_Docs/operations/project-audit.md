# Project Audit — Phase 0 (Repository Reconnaissance and Baseline)

- **Date:** 2026-10-19
- **Phase:** 0 — project inspection and setup
- **Result:** Laravel 12 application created in the project root; Sanctum installed; baseline tests pass on SQLite in-memory. MySQL connectivity **not verified** (no credentials provided).

## 1. Initial state (before any change)

| Item | Finding |
|---|---|
| Project root | `C:\Users\LENOV\Desktop\Stashly` |
| Contents | `README.md`, `plan.md`, `Stashly_API_Reference.docx`, `Stashly_Backend_Docs/` (21 Markdown files) — 24 files total |
| Laravel application | None |
| Git | **Not a git repository** (`fatal: not a git repository`) |
| Documentation path | Docs live in `Stashly_Backend_Docs/`; `plan.md` and the Phase 0 brief refer to `docs/`. See DEC-014. |

Integrity control: SHA-256 checksums of all 24 pre-existing files were captured before generation and re-verified after the merge and after Sanctum installation — all unchanged at that point. (`plan.md` and files in `Stashly_Backend_Docs/` were later edited intentionally; see §8.)

## 2. Local environment

| Component | Version / detail | Status |
|---|---|---|
| OS | Windows 11 Pro Education 10.0.26200 | — |
| PHP (CLI) | 8.2.12 ZTS x64, XAMPP (`C:\xampp\php\php.exe`, ini `C:\xampp\php\php.ini`), `memory_limit=512M` | Meets Laravel 12 (`^8.2`) |
| PHP extensions relevant to Laravel | bcmath, ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, PDO, pdo_mysql, pdo_sqlite, session, tokenizer, xml | All required extensions present |
| Composer | 2.9.5 | OK |
| Database server | **MariaDB 10.4.32** (XAMPP `mysqld.exe`), listening on `0.0.0.0:3306` / `[::]:3306`. Version read from the server handshake without logging in. | Running; **not MySQL**; see DEC-015 |
| MySQL client | `C:\xampp\mysql\bin\mysql.exe` (not on PATH) | — |
| Docker | Installed, daemon not running | Not used |
| Git | 2.47.0.windows.2 | Available; repo not initialized |
| Node.js | 22.14.0 | Not needed for the API; `npm install` not run |

### Support-window risks (informational)
- PHP 8.2 is in security-fixes-only support, ending 31 Dec 2026. The local build (8.2.12, Oct 2023) is behind the latest 8.2 patch release.
- Laravel 12 bug-fix support has ended per Laravel's published policy; security fixes continue until Feb 2027.
- MariaDB 10.4 is end-of-life.

These do not block development but should be weighed before production.

## 3. Generated application

| Item | Value |
|---|---|
| Skeleton | `laravel/laravel` `^12.0` via `composer create-project` |
| Framework | **laravel/framework 12.70.0** (`php artisan --version`) |
| PHP constraint | `"php": "^8.2"` in `composer.json` — compatible with local PHP 8.2.12 |
| Timezone | UTC (`php artisan about`) |

### Direct dependencies (`composer show --direct`)

| Package | Version | Type |
|---|---|---|
| laravel/framework | 12.70.0 | require |
| laravel/sanctum | 4.3.3 | require (added in Phase 0) |
| laravel/tinker | 2.11.1 | require |
| fakerphp/faker | 1.24.1 | dev |
| laravel/pail | 1.2.7 | dev |
| laravel/pint | 1.30.4 | dev |
| laravel/sail | 1.69.0 | dev |
| mockery/mockery | 1.6.15 | dev |
| nunomaduro/collision | 8.9.5 | dev |
| phpunit/phpunit | 11.5.57 | dev |

`composer validate`: valid. `composer audit`: no security vulnerability advisories found.

## 4. Setup commands executed

```bash
# 1. Generate in a sibling temp dir (no scripts: no .env, no SQLite file, no migrations)
cd C:/Users/LENOV/Desktop
composer create-project "laravel/laravel:^12.0" stashly-laravel-tmp --prefer-dist --no-scripts --no-interaction

# 2. Merge: move each top-level entry into the project root only if it does not already exist.
#    Only collision: README.md — Laravel's boilerplate README was NOT moved; the project README is kept.

# 3. Verify the 24 original files against pre-merge SHA-256 checksums (all unchanged), then remove the temp dir.

# 4. In the project root
composer dump-autoload            # package discovery at the new location
php artisan install:api --without-migration-prompt --no-interaction   # Sanctum 4.3.3 + routes/api.php
cp .env.example .env              # local only; .env did not exist and is git-ignored
php artisan key:generate          # local development key only
php artisan test
```

## 5. Phase 0 changes to generated files

| File | Change | Reason |
|---|---|---|
| `app/Models/User.php` | Added `Laravel\Sanctum\HasApiTokens` trait | Required by Sanctum (`install:api` instruction) |
| `routes/api.php` | Removed scaffolded `GET /api/user` route | Not in the API contract; it returned the raw User model, which violates "serialize only documented fields" |
| `bootstrap/app.php` | `api:` routes file registered (done by `install:api`) | Sanctum/API scaffolding |
| `config/sanctum.php` | Published by `install:api`; unmodified | — |
| `database/migrations/2026_10_19_165918_create_personal_access_tokens_table.php` | Published by `install:api`; unmodified | — |
| `.env.example` | `APP_NAME=Stashly`; `DB_CONNECTION=mysql` with `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=stashly`, empty `DB_USERNAME`/`DB_PASSWORD` | Target engine is MySQL (`plan.md`); placeholders only, no credentials |
| `tests/Feature/SanctumAuthenticationTest.php` | New | Verifies Sanctum bearer-token wiring using a test-only route (no contract endpoint invented) |

`.env` was created locally from `.env.example` with a generated `APP_KEY`. It is listed in `.gitignore` and contains no database credentials.

## 6. Verified results

| Check | Command | Result |
|---|---|---|
| Laravel version | `php artisan --version` | Laravel Framework 12.70.0 |
| Baseline + Sanctum tests | `php artisan test` | **5 passed (6 assertions)**, 1.93 s |
| Routes | `php artisan route:list` | 5 routes; no API routes (see §7) |
| Composer manifest | `composer validate --no-check-publish` | valid |
| Dependency advisories | `composer audit` | none |
| Original documentation intact | `sha256sum -c` against the pre-merge snapshot | 24/24 unchanged (before the intentional doc updates in §8) |
| Secrets in committable files | grep for `APP_KEY=base64` / non-null passwords outside `vendor`/`storage` | none found |

Tests executed:
- `Tests\Unit\ExampleTest` — that true is true
- `Tests\Feature\ExampleTest` — the application returns a successful response (`GET /`)
- `Tests\Feature\SanctumAuthenticationTest` — request without token rejected (401); valid bearer token authenticated (200); revoked token rejected (401)

Tests run on **SQLite in-memory** (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, array cache/session, sync queue, array mail). They do not verify MySQL behavior.

## 7. Database configuration requirements and setup limitations

1. **Database connectivity is unverified.** No credentials were provided and none were invented. To connect, set `DB_USERNAME` / `DB_PASSWORD` (and `DB_DATABASE` if different) in the local `.env`, create the database, then run `php artisan migrate`. Until then any command touching the default connection (migrations, the `database` session/cache/queue drivers, `GET /`) fails outside tests.
2. **Engine mismatch:** the local server is MariaDB 10.4.32; the documented target is MySQL. Laravel 12 provides a dedicated `mariadb` driver. Collation, case-insensitive uniqueness (collection names) and JSON behavior can differ between engines, so `testing-strategy.md` requires MySQL-specific tests against MySQL in CI. See DEC-015.
3. **ULID keys are not in place yet.** The skeleton `users` migration uses `$table->id()` (bigint) and the Sanctum migration uses `$table->morphs('tokenable')` (bigint). Both must become ULID (`ulid()` / `ulidMorphs()`) in Phase 1 **before migrations are ever run on a shared database**. No migration has been run against MySQL/MariaDB.
4. **Token expiry not configured:** `config/sanctum.php` has `'expiration' => null`. The contract's 60-day expiry is not enforced yet (DEC-011, Phase 2).
5. **Default drivers** (from `.env.example`): session, cache and queue all use `database`; mail uses `log`. These are Laravel defaults, not decisions.
6. **Routes outside the contract** currently registered: `GET /` (welcome page), `GET /up` (framework health), `GET sanctum/csrf-cookie` (SPA cookie auth; not needed for Android bearer tokens), and `GET|PUT storage/{path}` (local disk serving). They need a keep/disable decision in Phase 1 (DEC-016). `routes/api.php` registers no routes; the API prefix is still Laravel's default `api` (not `api/v1`).
7. **Frontend scaffolding** (`package.json`, `vite.config.js`, `resources/css|js`) came with the skeleton. It is unused by the API and was left untouched; `npm install` was not run.
8. **No version control:** the directory is not a git repository, so Phase 0 could not be reviewed with `git diff`/`git status`. File-level checksum verification was used instead. Initializing git before Phase 1 is recommended.

## 8. Documentation updates made in Phase 0

- `Stashly_Backend_Docs/operations/project-audit.md` — this file (new).
- `plan.md` — Phase 0 checklist items with evidence ticked; items without evidence left open.
- `Stashly_Backend_Docs/operations/deployment-and-operations.md` — "PHP 8.2 and Laravel 12 compatibility verified" ticked (local CLI only).
- `Stashly_Backend_Docs/decisions/open-questions.md` — added DEC-014 (docs path), DEC-015 (database engine/version), DEC-016 (non-contract framework routes).

Endpoint and feature status trackers are unchanged: no endpoint or feature has been implemented.

## 9. Phase 0 acceptance criteria

| Criterion | Status | Evidence |
|---|---|---|
| Laravel 12 application exists in the project root | Met | `php artisan --version` → 12.70.0 |
| PHP and Composer compatibility checked | Met | PHP 8.2.12 satisfies `^8.2`; Composer 2.9.5; required extensions present |
| Original Markdown documentation remains intact | Met | 24/24 checksums matched after merge; later edits listed in §8 are intentional additions/ticks |
| `.env.example` contains placeholders and no secrets | Met | `APP_KEY=` empty; DB username/password empty |
| Application can run its available baseline tests | Met (SQLite) | `php artisan test` → 5 passed |
| Setup limitations and DB configuration requirements documented | Met | §7 |
| Git diff and project structure reviewed | **Partially met** | Structure reviewed; git diff impossible (no repository). Checksums used instead |
| MySQL connectivity | **Unverified** | No credentials; local engine is MariaDB 10.4.32 |
