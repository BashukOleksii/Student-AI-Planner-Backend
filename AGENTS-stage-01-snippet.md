# Suggested section to merge into the existing AGENTS.md

> Do not replace the existing AGENTS.md automatically. Merge this section after reviewing the current repository instructions.

## Architecture and project documentation

Before making architectural, database, or business-logic changes, read:

- `docs/architecture.md`
- `docs/domain-model.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- relevant ADRs under `docs/decisions/`

The backend is a Laravel layered modular monolith exposed through a REST API to a separate frontend repository.

### Required implementation rules

- Follow Laravel conventions unless the repository already documents a deliberate exception.
- Use Eloquent ORM for application persistence.
- Keep Controllers thin.
- Put request validation in Form Requests.
- Put deterministic business logic in Services.
- Use Policies/Gates for authorization.
- Use API Resources when they provide a stable API response boundary.
- Do not let the AI agent calculate free time, deadlines, schedule conflicts, priorities, statistics, or planning feasibility. The agent orchestrates tools; tools call deterministic Services.
- Never trust a `user_id` supplied by an AI tool argument or public request when the authenticated user can be obtained from server context.
- Do not add repositories, interfaces, DTOs, events, value objects, or other abstractions without a concrete need.
- Do not create microservices.
- Do not change the physical database design during Stage 01. Database schema work belongs to Stage 02.
- When a business rule changes, update `docs/business-rules.md` and add/adjust tests in the implementation stage.

### Before editing

1. Inspect the current repository structure and relevant files.
2. State the intended files to change and why.
3. Reuse existing patterns if they are compatible with the architecture documents.
4. If the repository conflicts with these docs, report the conflict before silently changing a critical architectural decision.

### After editing

1. Run the smallest relevant test set.
2. Run formatting/static checks already configured by the repository.
3. Report changed files, tests run, and unresolved decisions.
