# Open Questions and Decisions Register

Record an owner, decision date, rationale, and affected tests for each resolved item. Do not silently guess.

| ID | Question / ambiguity | Why it matters | Safe next step | Status |
|---|---|---|---|---|
| DEC-001 | Source says 32 endpoints; counting listed operations appears to yield 33 operations. Which count is intended? | Scope/release completeness | Preserve every listed operation; confirm with API owner | OPEN |
| DEC-002 | What exact fields constitute `metadata` and how are null values represented in each endpoint response? | Android JSON compatibility | Use shared object definitions; obtain sample fixtures from app owner | OPEN |
| DEC-003 | Does “refresh-preview 5/min” mean metadata refresh, preview refresh, or a combined action? | Rate limiter key and limits | Enforce explicit endpoint limits from source; confirm intended meaning | OPEN |
| DEC-004 | Which exact query parameters are included in “similar” tracking parameters beyond `utm_*`, `fbclid`, `gclid`? | URL normalization consistency | Start with explicitly named parameters plus documented list approved by owner | OPEN |
| DEC-005 | What is the exact `Idempotency-Key` replay behavior if the same key is sent with a different request body? | Correctness/security | Store request fingerprint; define conflict behavior with API owner before finalizing | OPEN |
| DEC-006 | What should stuck `pending` metadata become after 15 minutes: `failed`, retry, or inspect job status? | Stable state and client polling | Implement a documented deterministic cleanup policy after approval | OPEN |
| DEC-007 | What exact content-type classifier rules determine video/article/product/social/other? | Product behavior | Keep classifier isolated and fixture-tested; agree on rules | OPEN |
| DEC-008 | What is the exact response schema for `GET /config`, `/home`, `/search/filters`, and preview? | Contract validation | Obtain Android models/sample payloads if available; do not add guessed fields. **Phase 1 provisional:** `/config` `limits` uses the contract "Limits" row with keys `url_max_length`, `title_max_length`, `note_max_length`, `collection_name_max_length`, `collection_description_max_length`, `tag_max_length`, `tags_per_link_max`, `collections_per_user_max`, `page_size_max` (`config/stashly.php`) | OPEN |
| DEC-009 | Should account deletion hard-delete every related record immediately, or use a staged transaction/job for large accounts? | Reliability and privacy | Ensure eventual complete deletion and token revocation; choose based on scale/DB constraints | OPEN |
| DEC-010 | How are app minimum/latest versions and legal document URLs configured and changed? | Release management | Use configuration/environment or managed settings; no hard-coded production assumptions | OPEN |
| DEC-011 | What is the required token cleanup mechanism for Sanctum tokens and how does it align with 60-day expiry? | Auth behavior | Verify Sanctum token schema and enforce expiry explicitly | OPEN |
| DEC-012 | How should cover images be selected from collection links and what happens with missing preview images? | Collection response behavior | Define deterministic behavior and test after product confirmation | OPEN |
| DEC-013 | Which fields are included in data export and what stable JSON file schema does the client expect? | Data portability and compatibility | Export all user-owned data; define versioned schema with API owner | OPEN |
| DEC-014 | Documentation lives in `Stashly_Backend_Docs/`, but `plan.md` and the Phase 0 brief refer to `docs/`. Rename the folder to `docs/`, or update references? | Broken references; risk of two diverging doc trees | Phase 0 audit written into the existing `Stashly_Backend_Docs/operations/`; no folder renamed or duplicated | OPEN |
| DEC-015 | Local database is MariaDB 10.4.32 (XAMPP, end-of-life); target is MySQL. Which MySQL version is used in production and CI, and should local dev move to MySQL? | Collation, case-insensitive uniqueness, JSON and index behavior differ between engines | `.env.example` uses `mysql`; run MySQL-specific tests against the agreed MySQL version in CI | OPEN |
| DEC-016 | Framework routes outside the contract are registered: `GET /`, `GET /up`, `GET sanctum/csrf-cookie`, `GET`/`PUT storage/{path}`. Keep or disable? | Plan excludes unlisted endpoints; attack surface | Left as-is through Phase 1; awaiting owner decision. Recommendation: disable `sanctum/csrf-cookie` (Android uses bearer tokens) and keep `/up` for infrastructure probes | OPEN |
| DEC-017 | `GET /health` 503 body and status values are not specified. | Monitoring and client parsing | **Phase 1 provisional:** 200 → `{data:{status:"ok", time, checks:{database,cache,queue}}}`, each check `"ok"`/`"fail"`; 503 → error envelope `SERVICE_UNAVAILABLE` with the same `{status:"fail", time, checks}` in `details` | ACCEPTED (provisional) — project owner, 2026-10-20 |
| DEC-018 | Error codes for HTTP statuses the contract does not list (e.g. 405, 413), and `details` when there are none. | Stable client branching on `code` | **Phase 1 provisional:** 405 keeps HTTP 405 with `NOT_FOUND`; other unlisted 4xx keep their status with `VALIDATION_FAILED`; unlisted 5xx → `SERVER_ERROR`; `details` is always an object (`{}` when empty); validation `details` maps each field to an array of messages | ACCEPTED (provisional) — project owner, 2026-10-20 |
| DEC-019 | Contract says "everything else 120/min/user", but throttling `/health` makes it depend on the cache, so it would answer 500 instead of 503 when the cache is down. | Contract deviation vs. reliable monitoring | **Phase 1 provisional:** `/health` is exempt from the `api` limiter; rely on infrastructure-level limits for it | ACCEPTED (provisional) — project owner, 2026-10-20 |
| DEC-020 | Sanctum plain-text tokens start with the token row id followed by a separator and the secret, and the token table uses an auto-increment id, so tokens reveal how many tokens have been issued. | Minor information disclosure | Decide in Phase 2 (token issuance): ULID token ids or accept as-is | OPEN |

## Decision template

### DEC-XXX — Short title
- Status: OPEN / ACCEPTED / REJECTED / SUPERSEDED
- Owner:
- Date:
- Source facts:
- Options:
- Decision:
- Rationale:
- Security/privacy impact:
- Tests/docs affected:
