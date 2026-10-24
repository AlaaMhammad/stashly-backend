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
| SEC-008 | Reuse password-reset token | Reject |
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
