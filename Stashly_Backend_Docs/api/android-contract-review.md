# Android API Contract Review

- **Date:** 2026-10-20
- **Method:** documented schema = `Stashly_API_Reference.docx` v1.0 (sections 1–3). Actual = real responses captured from the running application (in-memory SQLite, faked DNS/HTTP, sample data), not from memory. Shapes below show field names and JSON types.
- **Rule:** nothing here was changed. Each difference is listed for the Android client owner to confirm or reject.

## 0. Cross-cutting assumptions to confirm (affect every endpoint)

| # | Assumption in the implementation | Source of doubt |
|---|---|---|
| X1 | Every success body is wrapped as `{"data": ...}`, including endpoints the reference writes without the wrapper (`200 {user, token}`, `200 {status, time, checks}`, `200 {message}`, `200 {links_count, ...}`). | Reference 1.1 says "Success: `{ "data": ... }`" in general; per-endpoint rows omit it. |
| X2 | **ULIDs are emitted in lowercase** (e.g. `01m5c5d3dyxw3k38...`). Incoming ids are accepted in either case. | Reference says "ULIDs for all ids"; canonical ULID text is uppercase. A client that compares ids as strings must use one case. |
| X3 | Timestamps are `YYYY-MM-DDTHH:MM:SSZ`, no fractional seconds. | Matches the reference example. |
| X4 | Absent values are JSON `null` (never omitted). | DEC-002. |
| X5 | Private responses carry `Cache-Control: no-store, private`; `/config` carries `max-age=300, public`. | Reference only says `/config` is cacheable 5 min. |

## 1. `GET /config` (public)

- **Documented:** `200 {api_version, min_supported_app_version, latest_app_version, terms{version,url}, privacy{url}, limits{...}}`. Cacheable 5 minutes.
- **Actual** (`data`): `api_version: string ("1.0")`, `min_supported_app_version: string|null`, `latest_app_version: string|null`, `terms: {version: string (default "draft-1"), url: string}`, `privacy: {url: string}` (the URLs default to the draft pages `/terms` and `/privacy` served by the backend, DEC-021; environment variables override), `auth: {google_enabled: boolean}` (new, additive, DEC-035), `limits: {url_max_length, title_max_length, note_max_length, collection_name_max_length, collection_description_max_length, tag_max_length, tags_per_link_max, collections_per_user_max, page_size_max}` (all integers: 2048, 300, 2000, 60, 300, 40, 20, 100, 50).
- **Provisional / undocumented:** all nine `limits` key names (DEC-008). `api_version` value. The five release fields are `null` until the environment sets them (DEC-010).
- **DEC-036/037 (Android must handle):** the shared user object now has `password_set` (boolean). `DELETE /me` sends `{password}` when `password_set` is true, otherwise `{id_token}` (a fresh Google ID token; `401 INVALID_GOOGLE_TOKEN` if it is not the linked account). After a Google sign-in that linked an existing account, `password_set` is false and that account's older sessions are gone.
- **Decisions for the Android owner:** exact `limits` keys the app reads; whether `null` release fields are acceptable or the app needs real values before launch; whether the app also wants other limits exposed (e.g. rate limits).

## 2. `GET /home` (Bearer; query `recent_limit`, default 10, max 20)

- **Documented:** `200 {user{display_name}, links_count, favorites_count, quick_access[collection], recent_links[link]}`.
- **Actual** (`data`): exactly those five keys. `user: {display_name}`; counts are integers; `quick_access`: array of the shared *collection* object (`id, name, description, links_count, cover_images[], created_at, updated_at`); `recent_links`: array of the shared *link* object (15 fields, `metadata` with 8 fields, `collections[{id,name}]`, `tags[string]`).
- **Provisional:** which collections are in `quick_access` — up to 4, most recently active first (activity = collection edit or link added/removed) (DEC-029). `recent_links` = newest saved first. `recent_limit` outside 1–20 → 422 `VALIDATION_FAILED`.
- **Decisions:** the rule and size of `quick_access` (and whether "Unsorted" should appear); whether `recent_links` should honour any privacy filter.

## 3. `GET /search/filters` (Bearer)

- **Documented:** `200 {content_types[], sources[], collections[], tags[], date_presets[]}`, "each option with its count". Option fields are **not specified**.
- **Actual** (`data`): `content_types: [{value: string, count: int}]` (all five types always, zero counts included, fixed order video, article, product, social, other); `sources: [{value: domain, count}]` (count desc, then name); `collections: [{id, name, count}]` (by name); `tags: [{name, count}]` (count desc, then name); `date_presets: [{value: 7|30, count}]`.
- **Provisional (all of it):** option field names `value`/`count`/`id`/`name`, ordering, zero-count inclusion, `date_presets` as integers of days (DEC-028). Counts are over all of the user's links, not narrowed by other active filters.
- **Decisions:** the option object shape the app expects; whether counts must reflect currently selected filters (faceted counts); whether `date_presets` should be `{value, label}`.

## 4. `POST /links/preview` (Bearer)

- **Documented:** request `{url*}`; `200 {url, normalized_url, source_domain, content_type, duplicate, existing_link, metadata}`; `422 INVALID_URL`. Returns "title/image if found within 4 s".
- **Actual** (`data`): exactly those seven keys. `url`: the user's trimmed input; `normalized_url`; `source_domain`; `content_type: string`; `duplicate: bool`; `existing_link: link|null`; `metadata: object|null`. `metadata` when found: `{status:"ready", title, description, site_name, favicon_url, preview_image_url, fetched_at, error_code:null}`; for a saved URL: that link's stored metadata; `null` when nothing was found within 4 s or the target was blocked.
- **Provisional:** `metadata: null` on failure instead of a failed-status object (DEC-030); metadata shape reused from the link object (DEC-002); `content_type` may be refined from the fetched page; a private/IP-literal URL is accepted syntactically and answered with `metadata: null`, not an error.
- **Decisions:** whether the app needs the failure reason (e.g. `blocked`, `timeout`) in preview; whether private-network URLs should instead be rejected with `INVALID_URL`.

## 5. `GET /me/export` (Bearer)

- **Documented:** "200 JSON file (`Content-Disposition: attachment`). 1 per 10 min." No schema.
- **Actual:** `200`, `Content-Disposition: attachment; filename=stashly-export-YYYYMMDD-HHMMSS.json`, `application/json`, no wrapper. Top-level keys: `schema_version: 1`, `exported_at`, `user{id, display_name, email, created_at, terms_version, terms_accepted_at}`, `collections[{id, name, description, created_at, updated_at, link_ids[]}]`, `tags[string]`, `links[<link object>]`. Excludes password hash, tokens, reset tokens, sessions, idempotency records, internal columns.
- **Provisional:** the whole schema (DEC-013, accepted provisionally by the owner on 2026-10-20 — the Android owner has not confirmed it); filename pattern; the file is **not** wrapped in `{"data": ...}` (a deliberate exception to X1 since it is a file).
- **Decisions:** whether the app parses the file (then it needs a frozen schema) or only hands it to the user; any required fields (e.g. device list); size limits for very large libraries.

## 6. Also unconfirmed: `GET /health`

Documented `200 {status, time, checks{database, cache, queue}}; 503 when a check fails`. Actual `data.status` / `checks.*` are `"ok"`/`"fail"`; a 503 uses the error envelope with the health body in `error.details` (DEC-017). Operations tooling, not the app, normally reads this.

## 6b. `POST /auth/google` (new, DEC-035; not in the original reference)

- **Request:** `id_token*` (Google ID token from Credential Manager, max 8192 chars), `device_name*` (max 255 - required here, optional on login), `terms_accepted` and `terms_version` (required `true` only when the Google account has no Stashly account yet).
- **Response:** exactly the login shape `{"data": {"user", "token"}}`: `200` for an existing or newly linked account, `201` for a new account.
- **Errors:** `401 INVALID_GOOGLE_TOKEN` (bad/expired/wrong-audience token, unverified Google e-mail, e-mail already linked to another Google account), `422 VALIDATION_FAILED` (`details.terms_accepted` for a new account), `429 RATE_LIMITED` (10/min per IP), `503 SERVICE_UNAVAILABLE` (server not configured or Google keys unreachable). `INVALID_GOOGLE_TOKEN` is a **new error code**.
- **Availability:** `GET /config` returns `auth.google_enabled`; hide the Google button when it is `false`.
- **Audience:** the server accepts tokens whose `aud` equals its `GOOGLE_CLIENT_ID`. With Credential Manager that is the *web* client id passed as `serverClientId`; the Android owner must use the same id.
- **Decisions for the Android owner:** the new error code and its handling (do not log the user out on it); required `device_name`; the `201`/`200` split; DEC-036 (a Google-only user has no password, so `DELETE /me` and `PUT /me/password` cannot be used as they are).

## 7. What is needed to close this

Real Android model classes or sample payload fixtures for the five endpoints, and a yes/no on X1–X5 and each "Decisions" line. Then add the fixtures to `ApiReferenceContractTest`.
