# Database Readiness

- **Date:** 2026-10-20
- **Basis:** static inspection of migrations, queries and configuration, plus one read-only fact: the local server's handshake reports `10.4.32-MariaDB`. No database was connected to with credentials, no `.env` value was read, no migration was run against any MySQL/MariaDB server. Everything marked "SQLite-verified" ran only on in-memory or file SQLite.

## 1. Required engine for the target environment

**Recommended and documented target: MySQL 8.0 or later (8.4 LTS preferred), InnoDB, `utf8mb4`.** The project owner must confirm the production version (DEC-015); until then this is the minimum the code needs.

Why not lower (derived from the code, not guessed):

| Code feature | Where | Minimum engine |
|---|---|---|
| Window function `row_number() over (partition by ...)` for collection cover images | `app/Services/Collections/CollectionPresenter.php` | **MySQL 8.0** (MariaDB 10.2) |
| `utf8mb4_bin` per-column collation for case-insensitive-by-key uniqueness | `2026_10_20_000004_create_collections_and_tags_tables.php` | MySQL 5.5.3+ |
| `SELECT ... FOR UPDATE` row locks (100-collection cap, metadata job) | `CollectionService`, `MetadataProcessor` | InnoDB |
| `LIKE ... ESCAPE '!'` | `LinkSearch`, `TagController` | any |
| Foreign keys with `ON DELETE CASCADE` on `char(26)` ULID columns | all user-owned tables | InnoDB |
| PHP 8.2 / Laravel 12.70 | `composer.json` | Laravel 12 supports MySQL 5.7+ and MariaDB 10.3+ (per Laravel's documentation; confirm against the installed version's docs) |

So the binding constraint is the window function: **MySQL 5.7 is not supported by this code.**

## 2. Schema inspection (static, verified from the migrations)

- **Primary keys:** `ulid()` → `char(26)` for `users`, `personal_access_tokens`, `links`, `idempotency_records`, `collections`, `tags`. Pivots use composite primary keys (`collection_link`, `link_tag`).
- **Foreign keys (all `ON DELETE CASCADE`):** `links.user_id`, `idempotency_records.user_id`, `collections.user_id`, `tags.user_id`, `collection_link.{collection_id,link_id}`, `link_tag.{link_id,tag_id}`. `sessions.user_id` is an indexed column with **no** foreign key (Laravel default); `personal_access_tokens.tokenable_id` is polymorphic (no FK), so tokens are deleted explicitly by `AccountDeletionService`.
- **Unique indexes:** `users.email`; `links (user_id, normalized_url_hash)`; `collections (user_id, name_key)`; `tags (user_id, name)`; `idempotency_records (user_id, key)`; `personal_access_tokens.token`.
- **Case-insensitive uniqueness** does not rely on the server collation: `collections.name_key` is stored lowercased; on MySQL/MariaDB `name_key` and `tags.name` use `utf8mb4_bin` so "Café" and "Cafe" stay distinct. **This is the highest-risk MySQL-only behavior** and has never run on MySQL.
- **Index sizes:** the longest indexed text columns are `links.source_domain` (253 chars) and `users.email` (255). Both are far below InnoDB's 3072-byte key limit under `utf8mb4`.
- **Long URLs:** `links.original_url/normalized_url` are `varchar(2048)` and are *not* indexed (a SHA-256 hash column is used instead) — by design for MySQL's key limit.
- **Defaults** (`config/database.php`): charset `utf8mb4`, collation `utf8mb4_unicode_ci`, `strict => true`, `prefix_indexes => true`.
- **Test configuration:** `phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, array cache/mail, sync queue. **No MySQL test run exists today.**
- **`.env.example`** is set to `DB_CONNECTION=mysql`, database `stashly`, empty username/password.

## 3. Is the existing MariaDB 10.4.32 suitable?

| Question | Answer |
|---|---|
| Suitable for **preliminary** testing? | **Yes, with caveats.** MariaDB 10.4 supports window functions, InnoDB, FKs, `utf8mb4_bin`, row locks. It can catch schema, FK, index-length and most query errors earlier than SQLite can. |
| Suitable as release evidence? | **No.** It is a different product, end-of-life, and not the target. |
| What it can verify | Migrations run and roll back; FK cascades; unique-index behavior (links URL hash, collection name key, tags); `LIKE ... ESCAPE`; the window-function query; row-lock based cap under two real connections; ULID char(26) keys. |
| What still needs the **target MySQL version** | Collation/accent behavior of `utf8mb4_unicode_ci` and `utf8mb4_bin` (differs between MySQL 8 `0900` collations and MariaDB); default `sql_mode`/strictness; deadlock and lock-wait behavior; optimizer plans for the list/search queries; `createOrFirst` unique-violation handling under real MySQL error codes; migration timing on the real server version. |
| Driver note | Laravel 12 has a separate `mariadb` driver. For MariaDB preliminary runs use `DB_CONNECTION=mariadb`; the committed target is `mysql`. Results from `mariadb` do not prove `mysql`. |
| Compatibility risk | `mysql` driver on MariaDB 10.4 usually works but is not guaranteed; any failure there must be classified "MariaDB-specific" vs "real bug" before acting. |

## 4. Safe setup of a dedicated local test database (NOT executed)

Principles: a database that exists only for tests, a dedicated user with rights on that database alone, a name that cannot be mistaken for real data, and credentials supplied through environment variables or a git-ignored file — never committed, never pasted into chat.

Proposed SQL (run by you in the MySQL/MariaDB client as an administrator; choose your own password):

```sql
CREATE DATABASE stashly_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stashly_test'@'localhost' IDENTIFIED BY '<choose-a-strong-password>';
GRANT ALL PRIVILEGES ON stashly_test.* TO 'stashly_test'@'localhost';
FLUSH PRIVILEGES;
```

Why `ALL` on this one database only: migrations and `RefreshDatabase` create and drop tables. In production the app user needs only `SELECT, INSERT, UPDATE, DELETE` (migrations run with a separate deploy user).

Repository additions — **implemented and committed in `60e3662`:**
1. `phpunit.mysql.xml`: same suites and settings as `phpunit.xml`. `DB_DATABASE` is forced to `stashly_test` and `DB_URL` is forced empty; `DB_CONNECTION` defaults to `mariadb` (set `DB_CONNECTION=mysql` in the shell for a MySQL run). Host, port, user and password are **not** in the file; they come from the environment. The default `phpunit.xml` (in-memory SQLite) is unchanged.
2. `tests/Support/TestDatabaseGuard.php`, called from `tests/TestCase.php::createApplication()`. It runs before `setUpTraits()`, so before `RefreshDatabase` can migrate or drop anything, and it checks the **effective** Laravel configuration (including a `DB_URL` override). It fails closed unless the target is in-memory SQLite or a `mysql`/`mariadb` connection to a database named **exactly** `stashly_test`. Covered by `tests/Unit/TestDatabaseGuardTest.php` and `tests/Unit/PhpunitConfigurationTest.php`. The guard protects the test runner only; it does not stop a manual `artisan` command against another database.

Status: the files exist and are tested with the SQLite suite (647 passed). **No MariaDB or MySQL run has been performed yet.**

Proposed commands (**not executed; each needs your approval first**), with the database name **`stashly_test`**:

```bash
# 1. read-only: can we connect, which engine/version, is the database empty?
php artisan db:show --database=mysql          # (env DB_* set for this shell only)

# 2. create the schema in the empty test database only
php artisan migrate --force                    # DB_DATABASE=stashly_test

# 3. verify rollback, then rebuild
php artisan migrate:rollback --force
php artisan migrate --force

# 4. full suite against it (uses phpunit.mysql.xml; the guard refuses any database but stashly_test)
php vendor/bin/phpunit --configuration=phpunit.mysql.xml
```

`migrate:fresh` / `db:wipe` and any command against a database not named `stashly_test` are out of scope without explicit approval.

## 5. Checks that stay UNVERIFIED until run

MySQL run of migrations and rollback; every feature test on MySQL; collation/accent behavior for collection names and tags; concurrent duplicate save/collection/idempotency with two real processes; query plans for `GET /links` search at volume.
