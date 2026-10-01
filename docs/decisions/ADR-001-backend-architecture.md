# ADR-001: Laravel Layered Modular Monolith for Backend

- Status: Stage 01 architecture baseline; domain implementation pending
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

This decision defines the intended architecture. The current repository remains a Laravel scaffold with Sanctum installed and the default authenticated `GET /api/user` route; full authentication and domain capabilities are not implemented. API versioning and the physical MySQL domain schema are deferred. Stage 01 creates no placeholder application classes or directories and changes no runtime behavior or migrations.

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
