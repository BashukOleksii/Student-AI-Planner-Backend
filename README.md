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
- Laravel Sanctum `^4.0` provides first-party SPA cookie/session authentication.

`.env.example` selects the MySQL database `student_planner`, and `phpunit.xml`
selects the separate MySQL test database `student_planner_testing`.

## Project Status

Stage 01 architecture and Stage 02 MySQL persistence are complete. The completed
application/API verticals implement registration, login, logout, current-user
profile retrieval/update, Planning Preferences, Study Availability Windows, and
Academic Context CRUD for institutions, periods, subjects, and teachers, plus manual
Lessons, cancellation/replacement lifecycle, and timezone-aware schedule views.
See [Authentication + User Profile](docs/auth-profile.md)
for routes, responses, and the Vue SPA local configuration and CSRF flow.

The repository is not yet feature-complete. Tasks, planning, imports, reminders, reports, and AI orchestration remain planned work.
See [Planning settings API](docs/planning-settings.md) for singleton preferences,
recurring local availability, overlap validation, and ownership protection.
See [Academic Context API](docs/academic-context.md) for the completed Stage 04
CRUD contracts, same-owner institution rules, and guarded deletion/history behavior.

See [Lesson and Schedule API](docs/schedule.md) for UTC manual input, lifecycle,
local date/week views, DST boundaries, and presentation timestamps.

## Documentation

- [Lesson and Schedule API](docs/schedule.md)
- [Academic Context API](docs/academic-context.md)
- [Backend architecture](docs/architecture.md)
- [Conceptual domain model](docs/domain-model.md)
- [Business rules and stable rule IDs](docs/business-rules.md)
- [Database review and Stage 02 questions](docs/database-review-notes.md)
- [ADR-001: Backend architecture decision](docs/decisions/ADR-001-backend-architecture.md)

[AGENTS.md](AGENTS.md) is the primary instruction file for repository work.
