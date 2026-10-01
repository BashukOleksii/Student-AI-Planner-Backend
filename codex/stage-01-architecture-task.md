# Codex Task — Stage 01 Architecture Documentation Integration

## Goal

Integrate the approved Stage 01 backend architecture documentation into the existing Laravel repository without changing application behavior or the database schema.

## Context

The project is the backend for a student AI planner. Frontend and backend are separate repositories and communicate through REST API. Backend uses Laravel + MySQL + Eloquent ORM. The AI agent acts as an orchestrator and must call deterministic Services for business rules.

Source documentation to integrate:

- `docs/architecture.md`
- `docs/domain-model.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- `docs/decisions/ADR-001-backend-architecture.md`

## Requirements

1. Inspect the current repository first, including:
   - `AGENTS.md`;
   - `README.md`;
   - `composer.json`;
   - `app/` structure;
   - `routes/`;
   - current database config/migrations;
   - test setup.
2. Do not assume the repository matches the documentation. Report material conflicts.
3. Add the Stage 01 documentation under `docs/` with the structure provided.
4. Merge only the necessary architecture-reference section into the existing `AGENTS.md`; preserve all existing repository-specific instructions.
5. If README already has a project documentation section, add links to the new docs there. Otherwise, make the smallest reasonable README update.
6. Do not create empty architecture directories in `app/` just to match a proposed structure.
7. Do not create or edit database migrations/models for Stage 01.
8. Do not install packages.
9. Do not change runtime configuration, API routes, controllers, services, authentication, or behavior.
10. Keep the working tree limited to documentation/instruction changes.

## Constraints

- Laravel conventions have priority over invented abstractions.
- Existing working code has priority over assumptions.
- Critical architectural conflicts must be reported rather than silently rewritten.
- The current SQL/ER draft is reference material only and is not a migration specification.

## Acceptance criteria

- `docs/architecture.md` exists and documents the modular monolith, REST boundary, Eloquent, Service layer, agent/tool boundary, time handling, async work, testing, and deferred AWS deployment.
- `docs/domain-model.md` clearly states that it is conceptual and not the final schema.
- `docs/business-rules.md` contains stable rule IDs for schedule, tasks, planning, rescheduling, reminders, statistics, security, and AI orchestration.
- `docs/database-review-notes.md` records the known problems in the current SQL draft without changing migrations.
- `docs/decisions/ADR-001-backend-architecture.md` records the modular-monolith decision and rejected alternatives.
- `AGENTS.md` references the docs and preserves pre-existing instructions.
- No production code or migration behavior changes.
- Repository tests/configuration remain unchanged and the working tree contains only intended documentation changes.

## Verification

Run:

```bash
git diff --check
git status --short
```

If the repository has a documentation linter already configured, run it. Do not add a new linter for this task.

Then report:

1. files changed;
2. any conflicts between current repository structure and the architecture docs;
3. verification commands and results;
4. open questions to carry into Stage 02 Database Design.
