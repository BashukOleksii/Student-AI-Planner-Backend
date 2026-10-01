# ADR-001: Laravel Layered Modular Monolith for Backend

- Status: Proposed baseline for Stage 01
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
- API Resources;
- business Services grouped by capability;
- Jobs/queues for asynchronous work;
- AI agent as an orchestrator over structured tools that call Services.

Use Laravel conventions before adding custom architectural abstractions.

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
