# ADR-001: Laravel Layered Modular Monolith for Backend

- Status: accepted; Stage 02 persistence, Authentication/Profile, Planning Settings, and Academic Context implemented
- Date: 2026-10-01

## Context

The system needs a separately deployable backend for a Vue frontend. The backend owns authentication, schedules, tasks, planning, reminders, statistics, imports, and AI-agent orchestration.

Many rules are deterministic and must remain testable independently of the LLM. The project is developed as one student project/team, not as independently operated services.

## Decision

Build the backend as a **Laravel layered modular monolith** with:

- REST API;
- MySQL;
- Eloquent ORM;
- thin Controllers;
- Form Requests;
- Policies/Gates;
- API Resources where useful;
- business Services grouped by capability;
- Jobs/queues for asynchronous work where appropriate;
- AI agent as an orchestrator over structured tools that call Services.

Use Laravel conventions before adding custom architectural abstractions.

This decision defines the architecture. Stage 02 MySQL persistence is complete. Authentication + User Profile implements thin Controllers, Form Requests, and a shared UserResource using Sanctum SPA cookie/session authentication with the standard web guard. No Service wraps trivial persistence/auth calls. The canonical profile endpoint is `/api/profile`; `/api/user` is removed. Planning Settings adds focused invariant/overlap Services and an auto-discovered availability ownership Policy using not-found denials; see [the planning settings contract](../planning-settings.md). Academic Context follows the same conventions with reusable relationship/lifecycle Services and ownership Policies; see [its contract](../academic-context.md). API versioning and application capabilities beyond these completed verticals remain deferred. See [the authentication contract](../auth-profile.md).

See [backend architecture](../architecture.md), [the conceptual domain model](../domain-model.md), [business rules](../business-rules.md), and [Stage 02 database review questions](../database-review-notes.md).

## Alternatives considered

### Microservices

Rejected for now because they add deployment, networking, distributed consistency, observability, and testing complexity without a current requirement for independent scaling/ownership.

### Strict clean/hexagonal architecture with repositories for every model

Not selected as the default because it would add significant ceremony around Eloquent and could make a student project harder to implement and explain. Small boundaries are still appropriate around genuine external dependencies such as the LLM provider.

### Fat Active Record models

Rejected for business logic because planning algorithms, imports, conflict detection, and rescheduling would become difficult to test and reason about if hidden inside Eloquent models/events.

## Consequences

Positive:

- simple single deployment;
- natural fit for Laravel;
- clear place for deterministic Services;
- good testability;
- easy to explain at defense;
- can later move queue/storage/database to AWS services without rewriting domain logic.

Trade-offs:

- module boundaries are enforced by code discipline rather than network boundaries;
- one application scales as a unit initially;
- large Services must be split by cohesive use case as the code grows.

## Review trigger

Reconsider this ADR only if a concrete requirement appears for independent service deployment/scaling, separate team ownership, hard isolation, or a substantially different integration boundary.
