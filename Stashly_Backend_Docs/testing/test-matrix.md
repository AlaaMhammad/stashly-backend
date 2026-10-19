# Test Matrix

| Area | Positive cases | Negative/boundary cases |
|---|---|---|
| Health/config | healthy checks, config fields, cache | failed dependency gives 503; no secrets |
| Register/login | valid account and token | duplicate email, bad terms, password mismatch, generic invalid credentials, rate limit |
| Token lifecycle | logout, refresh, expiry | old/revoked token rejected; all-device logout |
| Password reset | queued email, valid single-use token | unknown email same outward response, expired/reused token |
| Profile | get/update/stats | invalid display name, attempt to change email/internal fields |
| Export/delete | own data export; confirmed deletion | wrong password; other users' data excluded |
| URL preview | valid HTTP/HTTPS and normalized output | unsupported scheme, credentials, >2048 chars, timeout |
| Link save | creates pending link and dispatches job | duplicate 409; idempotency replay; cross-user duplicate must not leak |
| Link list/search | filters, sort, Arabic matching | malformed cursor; cursor reused with changed filters; pagination bounds |
| Link edit/delete | permitted fields work | read-only field rejected; cross-user ID returns 404 |
| Link collections | replacement and empty array | foreign collection ID rejected; atomicity |
| Link tags | normalized tags and replacement | >20 tags, >40 chars, foreign ownership isolation |
| Collections | create/list/detail/update/delete | duplicate case-insensitive name, >100 collections, invalid lengths |
| Attach/detach | atomic mixed operation | >100 IDs, both arrays empty, foreign IDs, partial failure rollback |
| Tags | list prefix/sort/count | limit >100; user isolation |
| Search filters/home | counts and recent links | no data leakage; recent limit bounds |
| Metadata | successful parse; ready state | SSRF, non-HTML, too large, timeout, redirects, no title/note overwrite |
| Rate limits | correct keys and reset behavior | 429 code and Retry-After |
| Errors | stable envelope and request ID | no trace/SQL/internal detail in production |
