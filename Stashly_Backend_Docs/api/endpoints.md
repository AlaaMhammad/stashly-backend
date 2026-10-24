# Endpoint Inventory — Stashly API v1

Base prefix: `/api/v1`. `Public` means no bearer token. All other endpoints require `Authorization: Bearer {token}`.

## System (2)

| ID | Method + path | Auth | Purpose |
|---|---|---|---|
| API-SYS-001 | `GET /health` | Public | Database/cache/queue checks; 200 healthy, 503 on check failure |
| API-SYS-002 | `GET /config` | Public | API/app versions, Terms/Privacy URLs, limits; cache 5 min |

## Authentication (9)

| ID | Method + path | Auth | Key behavior |
|---|---|---|---|
| API-AUTH-001 | `POST /auth/register` | Public | Register, accept terms, issue token; 201 |
| API-AUTH-002 | `POST /auth/login` | Public | Generic invalid credentials; 200 |
| API-AUTH-003 | `POST /auth/logout` | Bearer | Revoke current token or all tokens; 204 |
| API-AUTH-004 | `POST /auth/refresh` | Bearer | Rotate token; revoke old; 200 |
| API-AUTH-005 | `POST /auth/forgot-password` | Public | Always 200; emails a **6-digit code** (15 min) immediately after the response (no queue worker needed); repeat within 60 s sends nothing new; 3/hour per email and 10/hour per IP (DEC-040) |
| API-AUTH-006 | `POST /auth/reset-password` | Public | Body `email, code (6 digits), password, password_confirmation`; single-use 15-minute code, 5 wrong guesses allowed; revoke all tokens; 422 `TOKEN_INVALID_OR_EXPIRED`; 20/hour per email, 40/hour per IP (DEC-040: replaces the reset-link `token`) |
| API-AUTH-007 | `PUT /me/password` | Bearer | Verify current password; revoke other tokens |
| API-AUTH-009 | `POST /auth/verify-reset-code` | Public | Body `email, code`; 200 `{valid: true}` or 422 `TOKEN_INVALID_OR_EXPIRED`; does not use the code up; same limits as reset (DEC-040, owner-requested extension) |
| API-AUTH-008 | `POST /auth/google` | Public | Google ID token sign-in, link or sign-up; 200 or 201 (DEC-035, owner-requested extension) |

## Profile/account (5)

| ID | Method + path | Auth | Key behavior |
|---|---|---|---|
| API-PROF-001 | `GET /me` | Bearer | Current user/session check |
| API-PROF-002 | `PATCH /me` | Bearer | Update display name, 1–100 |
| API-PROF-003 | `GET /me/stats` | Bearer | Link, collection, favorite counts |
| API-PROF-004 | `GET /me/export` | Bearer | JSON attachment; 1 per 10 min |
| API-PROF-005 | `DELETE /me` | Bearer | Password check (or, for an account without a password, a fresh Google `id_token`, DEC-036), permanent data/token deletion; 204 |

## Saved links (9)

| ID | Method + path | Auth | Key behavior |
|---|---|---|---|
| API-LINK-001 | `POST /links/preview` | Bearer | Validate/normalize URL; preview within 4 sec |
| API-LINK-002 | `POST /links` | Bearer | Save immediately; metadata queued; idempotency key |
| API-LINK-003 | `GET /links` | Bearer | Cursor list/search/filter |
| API-LINK-004 | `GET /links/{link}` | Bearer | Link details and metadata |
| API-LINK-005 | `PATCH /links/{link}` | Bearer | Title/note/favorite only |
| API-LINK-006 | `DELETE /links/{link}` | Bearer | Delete link; 204 |
| API-LINK-007 | `PUT /links/{link}/collections` | Bearer | Full replacement; empty means Unsorted |
| API-LINK-008 | `PUT /links/{link}/tags` | Bearer | Full replacement; max 20 tags |
| API-LINK-009 | `POST /links/{link}/metadata/refresh` | Bearer | Retry metadata; 202; max once/min/link |

## AI (6) - DEC-038, DEC-039

| ID | Method + path | Auth | Key behavior |
|---|---|---|---|
| API-AI-001 | `POST /links/{link}/summarize` | Bearer | Gemini summary + 3-5 tag suggestions for an owned link; stored in `ai`, tags attached; 200 `{link}`; 404/422/502/503/504; 10 AI calls/min/user |
| API-AI-002 | `POST /ai/ask` | Bearer | Body `query` (2-500); answer from the 30 most recent links; 200 `{query, answer, referenced_links[{id,title,url}]}`; same errors and limit |
| API-AI-003 | `POST /links/{link}/key-points` | Bearer | Reader insights `{link_id, insights{key_takeaways[{point,explanation}], memorable_quotes[], estimated_read_time_minutes, difficulty_level}}`; cached, `?refresh=true` recomputes |
| API-AI-004 | `POST /links/{link}/action-plan` | Bearer | `{link_id, action_plan{objective, steps[{step_number,title,description,action_item}]}}`; cached, `?refresh=true` recomputes |
| API-AI-005 | `GET /links/{link}/related` | Bearer | `{source_link_id, related_links[{id,title,url,similarity_reason}]}` from the user's 30 most recent other links; not cached |
| API-AI-006 | `POST /ai/suggest-collection` | Bearer | Body `title*`, `url*`, `description`; `{suggested_collection_id|null, suggested_collection_name|null, confidence, reason}` |

All six AI endpoints share the 10 calls/min/user `ai` limiter; errors 503 `AI_SERVICE_UNAVAILABLE`, 504 `AI_TIMEOUT`, 502 `AI_INVALID_RESPONSE`.

## Collections (6)

| ID | Method + path | Auth | Key behavior |
|---|---|---|---|
| API-COLL-001 | `GET /collections` | Bearer | Cursor list, counts, up to four cover URLs |
| API-COLL-002 | `POST /collections` | Bearer | Unique case-insensitive name; max 100 |
| API-COLL-003 | `GET /collections/{collection}` | Bearer | Collection details |
| API-COLL-004 | `PATCH /collections/{collection}` | Bearer | Rename/description |
| API-COLL-005 | `DELETE /collections/{collection}` | Bearer | Delete collection; keep links |
| API-COLL-006 | `PATCH /collections/{collection}/links` | Bearer | Atomic attach/detach, max 100 each |

## Tags, search, home (3)

| ID | Method + path | Auth | Key behavior |
|---|---|---|---|
| API-TAG-001 | `GET /tags` | Bearer | User tags with usage counts |
| API-SRCH-001 | `GET /search/filters` | Bearer | Filter options and counts |
| API-HOME-001 | `GET /home` | Bearer | Home aggregate; recent limit default 10/max 20 |

## Common response details

- Use the exact shared objects and error envelope from `analysis/source-contract.md`.
- Do not return model objects wholesale; serialize only documented fields.
- Use 404 for missing or cross-user resources.
- List pagination uses `limit`, `cursor`; default 20/max 50 where applicable.
- Check the source reference for each endpoint's exact request fields/status code before implementation.

## Source caveat

**DEC-035:** `POST /auth/google` (API-AUTH-008) was added at the owner's request after the reference was written, so the implemented API has **33** operations. `Stashly_API_Reference.docx` has not been changed.

The reference says 32 endpoints, while counting the listed operations above may produce a different count depending on whether the contract counts individual operations or groups. Preserve every listed operation and track the count discrepancy in `decisions/open-questions.md` rather than omitting an operation.
