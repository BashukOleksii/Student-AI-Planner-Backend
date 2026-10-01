# Student AI Planner Backend

Backend for the Student Personal Learning Planning Service with an AI Agent. The
intended service helps students manage class schedules, tasks, study plans,
reminders, and progress, with an AI agent that performs application actions
through tools backed by deterministic business logic.

The backend and frontend are independent applications in separate repositories.
The Vue 3 + TypeScript + Vite frontend communicates with this backend through a
REST API.

## Current Stack

- PHP `^8.3` and Laravel `^13.17`.
- Eloquent ORM with MySQL as the application database.
- Laravel Sanctum `^4.0` is installed as the authentication package baseline;
  full authentication flows are not implemented yet.

`.env.example` selects the MySQL database `student_planner`, and `phpunit.xml`
selects the separate MySQL test database `student_planner_testing`.

## Project Status

Stage 01 establishes architecture and conceptual domain documentation. The
application is currently a mostly fresh Laravel scaffold with the default
`User` model, framework migrations, Sanctum's personal-access-token migration,
and the `GET /api/user` route protected by `auth:sanctum`.

The repository is not yet feature-complete. Schedule management, tasks,
planning, imports, reminders, reports, and AI orchestration remain planned work.
Stage 02 will decide the physical MySQL domain schema and persistence details;
the existing migrations are not the final domain schema.

## Documentation

- [Backend architecture](docs/architecture.md)
- [Conceptual domain model](docs/domain-model.md)
- [Business rules and stable rule IDs](docs/business-rules.md)
- [Database review and Stage 02 questions](docs/database-review-notes.md)
- [ADR-001: Backend architecture decision](docs/decisions/ADR-001-backend-architecture.md)

[AGENTS.md](AGENTS.md) is the primary instruction file for repository work.
