# AI Coding Assistant Rules

Use these rules for Copilot, Claude, ChatGPT, or any coding agent working in this repository.

## Before editing
1. Read root `README.md`, `plan.md`, and the relevant docs under `docs/`.
2. Inspect current routes, models, migrations, controllers, middleware, tests, package versions, and git status.
3. Search for existing implementations before creating new ones.
4. State the files expected to change and the tests that will prove the change.

## While editing
- Follow Laravel 12 and PHP 8.2 syntax/runtime compatibility.
- Do not invent requirements absent from the API contract or approved decisions.
- Do not duplicate routes, classes, migrations, relationships, services, or middleware.
- Do not refactor unrelated code or change established UI/client contracts without approval.
- Do not silently alter response keys, status codes, error codes, rate limits, or ownership semantics.
- Never weaken security checks to make tests pass.
- Use transactions for multi-write invariants.
- Do not perform external network calls in unit tests.
- Do not log credentials, tokens, reset links, or full URLs.
- Keep docs synchronized with implementation.

## After editing
1. Run focused tests.
2. Run the full relevant suite if feasible.
3. Inspect `git diff` and `git status`.
4. Report actual commands/results; never claim tests passed if not run.
5. Update endpoint/feature status with evidence.
6. List unresolved ambiguities and avoid claiming completion if any acceptance gate remains open.

## If blocked
Stop and document the missing information in `docs/decisions/open-questions.md`. Ask for clarification when the decision changes contract, security, data model, or user-visible behavior.
