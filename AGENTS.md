# Backend Agent Instructions

## Project

This repository contains the backend for:

"Student Personal Learning Planning Service with an AI Agent".

The complete system consists of two independent applications:

- Backend: Laravel + PHP + MySQL
- Frontend: Vue 3 + TypeScript + Vite

This repository contains only the backend.

The frontend communicates with this application through a REST API.

## Product goal

The system helps a student:

- manage their class schedule;
- manage study tasks, subtasks, deadlines, priorities, and statuses;
- import schedules from Excel;
- find available study time;
- create personal study plans;
- reschedule unfinished work;
- manage reminders;
- analyze study progress and workload;
- communicate with the system using natural language through an AI agent.

The AI agent must be capable of performing real application actions through tools, not only generating textual recommendations.

## Main backend responsibilities

The backend is responsible for:

- authentication and authorization;
- user profiles and planning settings;
- educational institutions;
- subjects and teachers;
- lesson schedule management;
- lesson substitutions;
- Excel schedule import;
- tasks and subtasks;
- deadlines, priorities, and statuses;
- study sessions;
- free-time calculation;
- automatic study planning;
- planning constraints;
- rescheduling unfinished work;
- reminders;
- statistics and progress reports;
- AI conversations and messages;
- AI tools and their execution.

## Technology

Use the technology already configured in the repository.

Primary backend stack:

- Laravel;
- PHP;
- MySQL.

MySQL is the project's relational database.

Do not replace MySQL with another database unless explicitly requested.

Use Laravel migrations for database schema changes.

## Architecture

Follow standard Laravel conventions.

Prefer:

- Controllers for HTTP orchestration;
- Form Requests for validation;
- Services for business logic;
- Policies for authorization;
- Eloquent models for persistence;
- API Resources for response transformation where useful;
- Jobs / Queues for background processing where appropriate;
- Events / Notifications where appropriate.

Controllers must remain thin.

Do not put significant business logic inside controllers.

Do not introduce repository abstractions, interfaces, DTO layers,
or additional architectural layers unless they solve a concrete
problem in the existing codebase.

Avoid abstractions created only for theoretical architectural purity.

## Business logic

Deterministic business rules must be implemented in application code.

Examples include:

- finding free time;
- detecting schedule conflicts;
- validating deadlines;
- calculating available study periods;
- respecting user time constraints;
- selecting valid study slots;
- splitting tasks into study sessions;
- calculating remaining task duration;
- determining whether work can be completed before a deadline;
- rescheduling unfinished work;
- calculating workload and progress statistics.

Business logic must be reusable.

Do not duplicate the same rules in:

- Controllers;
- AI Tools;
- Console commands;
- Jobs.

Move reusable rules into Services or other appropriate domain/application code.

## AI agent architecture

The AI agent acts as an orchestrator.

The LLM must decide which application tools are required to satisfy
a natural-language request, but it must not replace deterministic
application logic.

Conceptually:

User
→ AI Agent
→ Tool
→ Application Service
→ Models / Database

Tools should be thin adapters around application capabilities.

A Tool must not reimplement business logic already available in a Service.

The same Service should be reusable from REST controllers and AI Tools.

Examples of expected AI capabilities include:

- retrieving the user's schedule;
- retrieving tasks and deadlines;
- creating and updating tasks;
- finding free study slots;
- creating study plans;
- rescheduling unfinished work;
- creating reminders;
- generating progress information.

Exact tool names and schemas may evolve during development.

Do not treat an early list of tool names as immutable API design.

## Multi-step agent behavior

Some user requests require multiple tools.

Example:

"Find two hours for my coursework before Monday and do not schedule
anything after 20:00."

Possible execution flow:

1. retrieve relevant schedule;
2. retrieve relevant tasks;
3. determine user planning constraints;
4. calculate valid free slots;
5. create or update the study plan;
6. optionally create reminders.

The sequence must depend on the actual request and available data.

Do not hard-code one fixed workflow for every planning request.

## Confirmation for destructive or sensitive actions

Actions that can cause significant or difficult-to-reverse changes
should require explicit user confirmation when appropriate.

Examples:

- deleting many tasks;
- deleting schedules;
- replacing imported schedule data;
- bulk rescheduling;
- other destructive bulk operations.

Read-only operations normally do not require confirmation.

Individual low-risk actions may be executed directly when the user's
intent is explicit.

## User constraints

Planning logic must respect persisted user preferences and constraints.

Examples may include:

- available study hours;
- maximum study hours per day;
- maximum study hours per week;
- desired breaks;
- prohibited planning periods;
- task deadlines.

Do not rely on the LLM to remember these constraints.

Constraints that affect planning should be stored and enforced by
application code.

## Security and data isolation

All personal data belongs to a specific authenticated user.

Never expose or modify another user's:

- lessons;
- tasks;
- subtasks;
- study sessions;
- reminders;
- AI conversations;
- planning settings;
- statistics;
- reports.

Use Laravel authentication and authorization mechanisms.

Use Policies or equivalent Laravel mechanisms where appropriate.

Never rely only on identifiers supplied by the client to establish ownership.

Security tests must cover cross-user access attempts for sensitive resources.

## Database

Use MySQL.

Database schema will evolve during development.

Do not silently introduce breaking schema changes.

For schema changes:

- use Laravel migrations;
- preserve existing data where practical;
- define foreign keys where appropriate;
- define indexes where useful;
- use appropriate unique constraints;
- consider nullable relationships explicitly;
- explain non-trivial relationship decisions.

Do not treat early database diagrams as immutable specifications.

Before changing the schema, inspect existing migrations and models.

## API

Use REST-style endpoints where appropriate.

Use appropriate HTTP status codes.

Validation errors should follow Laravel conventions.

Avoid unnecessary custom response wrappers unless the existing project
already consistently uses one.

Do not expose internal implementation details through API responses.

Maintain consistent resource naming.

## Excel import

Excel schedule import must be treated as application functionality,
not as direct database insertion.

The import process should eventually support:

- validation;
- preview before final import where required;
- useful error reporting;
- duplicate handling;
- repeated imports;
- transaction safety where appropriate.

Do not implement import behavior without considering existing schedule data.

## Testing strategy

Testing is an important part of the project.

Different layers require different types of tests.

### Unit tests

Use Unit tests for deterministic business logic such as:

- free-time calculation;
- conflict detection;
- priority calculations;
- study-session duration calculations;
- planning algorithms;
- deadline constraints;
- rescheduling algorithms;
- progress calculations.

### Feature / Integration tests

Use Laravel Feature tests for:

- REST API endpoints;
- authentication;
- authorization;
- database interaction;
- complete application use cases involving several components.

### AI tests

AI-agent behavior should eventually have tests that verify:

- correct tool selection;
- correct tool arguments;
- multi-tool sequencing;
- required clarification when information is missing;
- respect for user constraints;
- confirmation before destructive operations.

Do not test AI behavior only by comparing exact natural-language responses.

Prefer validating structured behavior and tool calls.

### Security tests

Security-sensitive functionality should test:

- unauthorized access;
- cross-user resource access;
- invalid authentication;
- ownership checks.

### Regression tests

Planning algorithms should retain regression coverage when their behavior changes.

When fixing a bug, add a regression test when practical.

### Smoke tests

Critical system flows should eventually have smoke coverage, including:

- authentication;
- schedule retrieval;
- task creation;
- AI-agent request execution.

## Tests required during development

For every significant backend change:

1. add or update relevant tests;
2. run the smallest relevant test set;
3. run broader tests when the change affects shared functionality;
4. fix failures caused by the requested change.

Important planning algorithms must include edge cases.

Do not delete or weaken tests merely to make a change pass.

## CI/CD compatibility

Code should remain suitable for automated CI execution.

Changes must not depend on:

- developer-specific absolute paths;
- manually configured local state;
- secrets committed to the repository;
- services unavailable in the documented development environment.

Configuration and secrets must use environment variables where appropriate.

Future CI/CD is expected to include:

- automated build/validation;
- Unit and Integration tests;
- AI behavior tests;
- code-quality checks;
- Docker image creation;
- staging deployment;
- smoke tests;
- controlled production deployment.

Do not implement the entire deployment pipeline unless explicitly requested.

## Development workflow

Before modifying code:

1. inspect the relevant existing implementation;
2. inspect related routes, models, migrations, services, controllers,
   requests and tests;
3. follow existing conventions;
4. determine the smallest coherent change required.

When implementing a feature:

1. understand the requested behavior;
2. identify affected application layers;
3. explain significant architectural decisions;
4. implement the smallest coherent change;
5. add or update tests;
6. run relevant tests;
7. report what was changed;
8. report tests that were run and their result.

Do not implement unrelated features.

Do not attempt to build the entire application in one task.

## Source of truth

The current repository is the primary source of truth for implemented code.

Before making changes, inspect the repository instead of assuming that
previous descriptions still match the current implementation.

Requirements and documentation describe intended behavior, but existing
code must be examined before proposing modifications.

Do not silently change critical architectural decisions.
