# Feature: Authentication and Account Lifecycle

## Contract endpoints
- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/logout`
- `POST /auth/refresh`
- `POST /auth/forgot-password`
- `POST /auth/reset-password`
- `PUT /me/password`
- `GET /me`
- `PATCH /me`
- `GET /me/stats`
- `GET /me/export`
- `DELETE /me`

## Rules
- Register requires `display_name`, `email`, `password` (8–128), `password_confirmation`, `terms_accepted=true`; `terms_version` and `device_name` are supported fields.
- Login errors must be indistinguishable for unknown email and wrong password.
- Token lifetime is 60 days. Refresh is authenticated, issues a new token, and revokes the old token.
- Logout revokes current token by default; `all_devices=true` revokes all tokens.
- Forgot password always returns 200 whether account exists; email delivery is queued.
- Reset token is single-use and valid for 60 minutes. Successful reset revokes all tokens.
- Password change requires current password; new password must differ; revoke other tokens.
- Email cannot be changed in v1 via `PATCH /me`.
- Account deletion requires password confirmation and permanently removes user data and tokens.
- Never serialize password/hash/reset token/access token in user responses or logs.
- Use generic outward responses to avoid account enumeration.

## Tests
- Registration valid/invalid terms, duplicate email, password mismatch, boundary lengths.
- Login success, wrong password, unknown email with equivalent status/code/message shape.
- Current-token logout; all-token logout; revoked token fails.
- Refresh invalidates old token and returns valid replacement.
- Reset expired/invalid/used token; successful reset revokes sessions.
- Change password wrong current password, same password, and token revocation behavior.
- Profile cannot mass-assign email or internal fields.
- Export and deletion scope only the authenticated user.
