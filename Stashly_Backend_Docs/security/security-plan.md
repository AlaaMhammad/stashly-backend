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
