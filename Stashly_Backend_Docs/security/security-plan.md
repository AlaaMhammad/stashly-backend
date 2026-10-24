# Security Plan

## Identity and access
- Protect all non-public endpoints with Sanctum bearer auth.
- Apply user ownership constraints to every link, collection, tag, pivot, stats, home, and export query.
- Cross-user resource access returns 404, not 403.
- Do not trust IDs, ownership fields, user IDs, or role-like fields from client input.
- Explicitly allow-list mutable request fields.
- Revoke tokens at required password/account lifecycle events.

## Passwords and tokens
- Use Laravel's password hashing APIs.
- Never return or log password/hash/reset token/access token.
- Use generic login errors.
- Forgot-password response must not disclose account existence.
- Reset token is single-use and expires after 60 minutes.
- Token expiry is 60 days; refresh rotates and revokes old token.
- Consider token storage and expiry behavior carefully: verify Sanctum token model/schema supports required expiration semantics; do not assume global Sanctum defaults automatically implement per-token 60-day expiry.

## Google Sign-In (DEC-035)
- The ID token is verified locally: RS256 only (never `none`/HS256), signature against Google's key set, `iss`, `aud` = `GOOGLE_CLIENT_ID`, strict `exp`, `email_verified` must be boolean true. Nothing from the token is trusted before the signature verifies.
- An unverified Google e-mail never creates or links an account. An e-mail already linked to another Google id is never taken over.
- Linking to an existing password account revokes that account's earlier sessions and clears its password (DEC-037), so a pre-registered attacker keeps no way in. E-mail verification at registration is still absent.
- `DELETE /me` for an account without a password requires a fresh Google `id_token` of the linked Google account (DEC-036); a stolen bearer token alone cannot delete such an account.
- The raw `id_token`, its claims and the e-mail are never logged or echoed; failures log a reason code only.
- Google-only accounts have no password; password login treats them exactly like unknown e-mails (same 401, same hashing time).
- Google's key endpoint is a fixed host (not user-supplied), fetched with short timeouts; an unknown `kid` refetches at most once a minute.

## SSRF
Follow `security/ssrf.md` as a mandatory implementation checklist. Remote metadata extraction is a high-risk boundary.

## Input and output
- Validate schemes, URL length, collection/tag limits, enum values, cursor values, and filter values.
- Do not render arbitrary remote metadata as trusted HTML.
- Treat extracted metadata as untrusted text.
- Use explicit resources/serialization allowlists.
- Do not expose stack traces or internal exception details in production.

## Abuse prevention
Implement all rate limits in `analysis/source-contract.md`; verify keys match the intended subject (email+IP, IP, email, user, or link).
Return 429 `RATE_LIMITED` and `Retry-After`.

## Logging and privacy
- Include a request ID in error responses and safe structured logs.
- Never log tokens, passwords, reset tokens, or full URLs.
- Prefer sanitized host/domain, link ULID, request ID, error category, and timing.
- Account export and deletion must be authenticated and audited without logging exported content.
- Ensure account deletion removes all associated records and tokens.

## Deployment
- HTTPS only in production.
- `APP_DEBUG=false`.
- Secrets in environment/secret manager, not repository.
- Least-privilege database credentials.
- Queue worker isolation from internal services.
- Keep dependencies patched; run dependency/security checks available in the project.
- Configure backups and test restore.
- CORS must allow only intended app/web origins; do not use permissive wildcard credentials.

## On-demand AI (DEC-038)

- Gemini key only in `GEMINI_API_KEY` (never in the URL: sent as `x-goog-api-key`), never logged or returned; provider errors become generic 503/504/502 without provider text.
- Data sent to Google: title, note, page description, domain, AI summary, tags, and the user's question. Not sent: full URLs (they can hold tokens), e-mail, tokens, other users' data. Disclosed in the draft privacy page.
- Saved page text is untrusted (prompt injection): it is sent inside quoted DATA sections with a system instruction not to follow it; the model's output is validated; `referenced_links` can only contain ids of the caller's own links; tags are normalized and capped.
- Cost/abuse: 10 AI calls per minute per user (limiter `ai`), 20 s timeout, no retries, input fields clipped, at most 30 links per question.

### AI link enhancements (DEC-039)

- Same key handling, rate limit (shared `ai` limiter) and prompt-injection stance as DEC-038. Extra guards: `memorable_quotes` must occur in the supplied text, `related` and `suggest-collection` only ever return ids of the caller's own links/collections, `step_number` is server-assigned, enums are validated.
- `suggest-collection` sends the host, never the full URL; logs hold only the reason code, never titles, URLs or the request body.
- A GET (`related`) triggers a provider call; it is authenticated and rate limited like the POSTs.
