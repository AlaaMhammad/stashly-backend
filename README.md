# Stashly Backend Documentation

**Stack target:** Laravel 12, PHP 8.2, MySQL, Laravel Sanctum, queued jobs  
**API contract:** Stashly API v1.0, dated 19 October 2026  
**Base path:** `/api/v1`

## Purpose

This documentation is the implementation source of truth for the Stashly Android backend. The uploaded `Stashly_API_Reference.docx` is the authoritative API contract. If implementation convenience conflicts with that contract, do not silently change the contract: document the discrepancy and obtain a decision.

## Start here

1. [`plan.md`](plan.md) — complete implementation roadmap and acceptance gates.
2. [`analysis/source-contract.md`](analysis/source-contract.md) — requirements extracted from the API reference.
3. [`architecture/architecture.md`](architecture/architecture.md) — proposed Laravel structure and design boundaries.
4. [`api/endpoints.md`](api/endpoints.md) — endpoint inventory and build order.
5. [`security/security-plan.md`](security/security-plan.md) — mandatory security controls.
6. [`testing/testing-strategy.md`](testing/testing-strategy.md) — test approach and release gates.
7. [`decisions/open-questions.md`](decisions/open-questions.md) — points that need an explicit decision.

## Documentation rules for humans and AI coding assistants

- Read `README.md`, `plan.md`, relevant feature docs, and relevant security/testing docs before coding.
- Do not invent endpoints, request fields, response fields, error codes, permissions, business rules, or dependencies.
- Preserve the exact contract unless a documented decision authorizes a change.
- Inspect existing code before editing. Avoid duplicate routes, migrations, models, middleware, or services.
- Make changes in small vertical slices: migration/model/service/request/resource/route/tests/docs as applicable.
- Add or update tests with every behavior change.
- Do not mark work complete because code was written; mark it complete only after relevant tests and acceptance checks pass.
- If the source does not specify a detail, record it in `decisions/open-questions.md` and choose the safest reversible default only when implementation must proceed.
- Never store secrets, real user tokens, passwords, production data, or sensitive URLs in documentation or logs.

## Status legend

- `NOT STARTED` — no implementation has been verified.
- `IN PROGRESS` — implementation exists but acceptance checks are incomplete.
- `BLOCKED` — a documented decision/dependency prevents safe completion.
- `DONE` — implementation, tests, documentation, and acceptance criteria are verified.

All feature checklists start as `NOT STARTED`. Do not assume any feature already exists.
