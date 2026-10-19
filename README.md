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

The backend implements authenticated academic/schedule CRUD and reviewed Excel
schedule import; Tasks/Subtasks and lifecycle/progress; free-time calculation,
deterministic study planning, adaptive replanning and session rescheduling;
absolute/relative Reminders with in-app delivery/inbox; and progress statistics,
daily trends and period comparisons.

H1–H3c provide cookie-authenticated AI conversations, nine read-only Tools and
fourteen mutation proposal Tools through the OpenAI Responses adapter. Every AI
mutation requires explicit authenticated confirmation, deterministic domain
validation and atomic durable receipt/history persistence. The LLM never writes
business records directly. H4 adds cross-domain REST verification, safe operational
boundaries, frontend API documentation and a minimal offline verification workflow.

The independent Vue 3 frontend, production deployment, external notification
transports, streaming/voice and additional providers are not implemented. Paid
live-provider testing is opt-in and was not run for H4.

Use **PHP 8.4.1+ for the current lock file**, Composer 2 and MySQL 8.4. Root PHP
^8.3 is the declared constraint; locked Symfony packages impose the higher actual
minimum. See [backend setup, smoke and readiness](docs/backend-integration-readiness.md)
for dependencies, database guards, configuration, CI and known limitations.
See [the frontend REST contract](docs/frontend-api-contract.md) for Sanctum/CSRF,
conversations, action review/consent, errors and domain refresh endpoints.

Useful feature contracts:

- [Authentication + User Profile](docs/auth-profile.md)
- [Planning settings](docs/planning-settings.md)
- [Academic Context](docs/academic-context.md)
- [Lessons, schedule and import](docs/schedule.md)
- [Tasks/Subtasks](docs/tasks.md)
- [Study plan persistence](docs/study-plan-persistence.md)
- [Adaptive replanning](docs/adaptive-replanning.md)
- [Session lifecycle](docs/study-session-lifecycle.md)
- [Reminders and inbox delivery](docs/reminders.md)
- [Progress statistics](docs/progress-statistics.md)
- [Daily trends](docs/progress-trends.md) and [period comparisons](docs/progress-comparisons.md)
- [AI chat](docs/ai-agent-chat.md) and [confirmed planning actions](docs/ai-study-planning-actions.md)

The reproducible offline smoke (guarded disposable MySQL only; no OpenAI key needed):

```sh
vendor/bin/phpunit tests/Feature/BackendIntegrationSmokeTest.php
```

## Documentation

- [Backend integration and readiness](docs/backend-integration-readiness.md)
- [Frontend REST API contract](docs/frontend-api-contract.md)

- [Lesson and Schedule API](docs/schedule.md)
- [Academic Context API](docs/academic-context.md)
- [Backend architecture](docs/architecture.md)
- [Conceptual domain model](docs/domain-model.md)
- [Business rules and stable rule IDs](docs/business-rules.md)
- [Database review and Stage 02 questions](docs/database-review-notes.md)
- [ADR-001: Backend architecture decision](docs/decisions/ADR-001-backend-architecture.md)

[AGENTS.md](AGENTS.md) is the primary instruction file for repository work.
