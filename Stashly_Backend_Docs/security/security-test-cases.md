# Security Test Cases

| ID | Scenario | Expected result |
|---|---|---|
| SEC-001 | No token on protected endpoint | 401 `UNAUTHENTICATED` |
| SEC-002 | Expired/revoked token | 401 `UNAUTHENTICATED` |
| SEC-003 | User requests another user's link ID | 404 `NOT_FOUND` |
| SEC-004 | User attaches another user's collection/link | 404 or contract-defined validation failure without data leakage |
| SEC-005 | PATCH link includes `user_id`, `normalized_url`, `metadata` | Reject read-only fields |
| SEC-006 | Unknown email vs wrong password | Same outward login error |
| SEC-007 | Forgot password for unknown email | Same 200 response as known email |
| SEC-008 | Reuse password-reset code | Reject (single use, DEC-040) |
| SEC-009 | URL uses `file:`, `data:`, `javascript:`, `intent:` | 422 `INVALID_URL` |
| SEC-010 | URL contains username/password | 422 `INVALID_URL` |
| SEC-011 | URL resolves to private/loopback/link-local address | Block fetch |
| SEC-012 | Public URL redirects to private address | Block redirect |
| SEC-013 | Metadata response exceeds 512 KB | Stop reading/reject |
| SEC-014 | Metadata endpoint returns non-HTML | Reject |
| SEC-015 | Rate limit exceeded | 429 `RATE_LIMITED` plus `Retry-After` |
| SEC-016 | Production unexpected exception | Generic 500 `SERVER_ERROR`, request ID, no stack trace |
| SEC-017 | Logs inspected after auth/link operations | No passwords/tokens/full URLs |
| SEC-018 | Account deletion | User data and tokens are removed |
| SEC-019 | Refresh token rotation | Old token is unusable |
| SEC-020 | Idempotency key replay | Same result within 24h; no duplicate side effect |
| SEC-021 | Google ID token with a wrong signature, `alg` none/HS256, wrong `aud`/`iss`, or expired | 401 `INVALID_GOOGLE_TOKEN`; no account or token created |
| SEC-022 | Google token with `email_verified` false/missing/string | 401; an existing account is never linked |
| SEC-023 | Google e-mail matches an account already linked to another Google id | 401; no takeover |
| SEC-024 | Password login on a Google-only account | Same 401 `INVALID_CREDENTIALS` and hashing time as an unknown e-mail |
| SEC-025 | Logs after Google sign-in attempts | No token, claims or e-mail; reason code only |
| SEC-026 | Pre-registered password after Google linking (DEC-037) | Linking clears the password and revokes sessions; the attacker's password and token both fail |
| SEC-027 | Delete a Google-only account (DEC-036) | Needs a valid `id_token` for the linked Google account; foreign, expired, wrong-audience, unverified and missing tokens fail and nothing is deleted |
| SEC-028 | AI endpoints and other users' links (DEC-038) | Summarize on another user's link is 404 and sends nothing; ask never sends or cites another user's links |
| SEC-029 | AI key and content hygiene | The Gemini key is only in a request header, never in logs/responses/errors; link content, questions and tokens are not logged |
| SEC-030 | AI prompt injection and made-up ids | Saved text stays in DATA sections; ids the model invents are dropped from `referenced_links` |
| SEC-031 | AI enhancements and other users' data (DEC-039) | key-points/action-plan/related on another user's link are 404 and send nothing; related and suggest-collection never send or return another user's links/collections |
| SEC-032 | AI output guards (DEC-039) | Quotes not in the supplied text, ids not owned by the caller, bad enums and unnumbered/over-long lists are dropped or rejected (502) |
| SEC-033 | Guessing the 6-digit reset code (DEC-040) | 5 wrong guesses delete the code; expiry after 15 minutes; limits per e-mail and IP; wrong / unknown e-mail / expired answer identically |
| SEC-034 | Reset code at rest and in transit | Stored only as a keyed HMAC; never logged; mail failure logs carry no address or code and do not change the API response |
| SEC-035 | Dead-link check and SSRF (DEC-041) | A link or redirect to a private address is never requested; result `blocked`, stored state unchanged; limit 10/min |
| SEC-036 | Public collection privacy (DEC-041) | Notes, tags, AI data, ids, e-mail never appear; private/unknown/switched-off slugs all give the same 404 |
| SEC-037 | Bookmark import abuse (DEC-041) | Non-text, oversized, NUL-containing or markup-laden files are refused or neutralised; javascript:/file:/data: links skipped; limit 3/min |
