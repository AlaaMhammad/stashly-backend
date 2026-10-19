# Feature: Asynchronous Link Metadata

## State model
`pending` → `ready`, `failed`, or `blocked`.

## Processing
- Queue after a link is persisted; dispatch after commit.
- Fetch only HTTP/HTTPS HTML using the SSRF controls in `security/ssrf.md`.
- Extract title, description, site name, favicon URL, external preview image URL, and refine content type.
- Do not download/store image or video bytes.
- Never overwrite user-entered title or note.
- Store `fetched_at` and a stable metadata `error_code` when applicable.
- Retry only temporary HTTP 429, HTTP 5xx, or timeout errors after 2 minutes, 15 minutes, and 2 hours; maximum four attempts.
- Do not retry permanent invalid URL, blocked host/IP, non-HTML content, or other permanent failures.
- `POST /links/{link}/metadata/refresh` is limited to once per minute per link and returns 202 with pending status.
- Scheduled cleanup finds jobs/links stuck in pending for more than 15 minutes and moves them to a safe terminal/recovery state. Exact state/error code must be documented as an implementation decision.

## Operational requirements
- Queue worker must be monitored.
- Failed jobs must be observable without exposing full URLs, credentials, or tokens.
- A metadata job must tolerate a link being deleted while queued.
- Tests must fake queue and HTTP interactions; never make tests depend on arbitrary live websites.
