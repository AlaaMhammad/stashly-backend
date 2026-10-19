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
