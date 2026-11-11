# Implementation handoff: unified calendar and semester AI actions

Implemented on `main` from source baseline `7434480c41856d63820a62c297d7921a4b106e42`. This is application functionality, with runtime verification deliberately deferred by the task. The API, tool schemas, confirmation lifecycle, compatibility policy and frontend requirements are in [the complete contract](unified-academic-calendar.md); architecture is recorded in [ADR-011](decisions/ADR-011-unified-calendar-and-ai-semester-actions.md).

## Completed paths

- Unified academic date/week/today reads reuse effective occupancy; study sessions stay separate. New API Resources expose opaque source identities and explicit editing references, offsets and truncation.
- Nine semester proposals and three bounded academic discovery reads are integrated into both provider discovery and the existing one-proposal-per-turn orchestrator.
- Management methods now expose a server-only read-only projection branch before persistence, sharing the exact candidate validation used by confirmed writes. Targeted occurrence updates/cancellations preserve the other lessons through date snapshots.
- Confirmation reuses existing pending actions, graph fingerprints/revision, authenticated ownership, owner lock order, stale checks, expiry, immutable replay receipts and rollback with history. New safe REST error handling excludes SQL/debug detail.
- Legacy schedule/semester APIs, Lesson actions, XLSX imports and deterministic planner algorithms retain their contracts. Existing free-time/planning/replanning/rescheduling/workload paths already share authoritative calendar context; no second planner or recurrence engine was added.

## Verification boundary

Only local PHP syntax inspection was performed on the changed/new PHP files. No test suite, migration, database fixture, paid provider, CI, browser, deployment or production operation was executed. The authored and updated tests remain unexecuted. In particular, SQL migration compatibility, live MySQL locking/rollback and real-model tool selection remain unverified. The new forward migration must be applied later to an approved environment before persisting the new pending-action types; it was not applied here.

Dense complete-day AI previews fail existing byte budgets rather than truncating consent. Calendar responses explicitly truncate at 100 lessons; unusually dense days still require source-specific inspection. Existing invalid/teacherless historical writes require valid associations/manual repair. Academic changes do not automatically repair existing study-session conflicts. Semester creation/deletion, import and suppression release remain existing manual REST operations. These are deliberate boundaries, not fallback legacy writes.

## Exact frontend work

Adopt `/api/academic-calendar/today`, `/date/{date}` and `/week/{date}` for the combined academic view. Use source_identity as a render key and opaque occurrence_id for a single-date semester target. Keep numeric legacy lesson editing and study-session UI separate. Support nullable type/teacher and truncation. Extend existing action cards to the nine new semester types, rendering scope and complete before/after data. Continue existing message Idempotency-Key behavior and authenticated CSRF confirm/reject endpoints with empty bodies. Refresh calendar/context and planning diagnostics after successful confirmation; stale/expired actions need a fresh proposal, never silent token replacement. See the contract tables for exact schemas and errors.

## Changed and created files

- [app/Http/Controllers/Api/AcademicCalendarController.php](../app/Http/Controllers/Api/AcademicCalendarController.php)
- [app/Http/Requests/AcademicCalendarDateRequest.php](../app/Http/Requests/AcademicCalendarDateRequest.php)
- [app/Http/Resources/AcademicCalendarLessonResource.php](../app/Http/Resources/AcademicCalendarLessonResource.php)
- [app/Services/AI/Actions/SemesterActionArgumentValidator.php](../app/Services/AI/Actions/SemesterActionArgumentValidator.php)
- [app/Services/AI/Actions/SemesterActionRegistry.php](../app/Services/AI/Actions/SemesterActionRegistry.php)
- [app/Services/AI/Actions/SemesterActionService.php](../app/Services/AI/Actions/SemesterActionService.php)
- [app/Services/AI/Actions/TaskActionConfirmationService.php](../app/Services/AI/Actions/TaskActionConfirmationService.php)
- [app/Services/AI/AgentOrchestrator.php](../app/Services/AI/AgentOrchestrator.php)
- [app/Services/AI/Tools/EffectiveScheduleReadService.php](../app/Services/AI/Tools/EffectiveScheduleReadService.php)
- [app/Services/AI/Tools/SemesterReadTools.php](../app/Services/AI/Tools/SemesterReadTools.php)
- [app/Services/Schedule/SemesterScheduleManagementService.php](../app/Services/Schedule/SemesterScheduleManagementService.php)
- [bootstrap/app.php](../bootstrap/app.php)
- [database/migrations/2026_11_12_000001_add_ai_semester_schedule_actions.php](../database/migrations/2026_11_12_000001_add_ai_semester_schedule_actions.php)
- [docs/architecture.md](architecture.md)
- [docs/business-rules.md](business-rules.md)
- [docs/database-review-notes.md](database-review-notes.md)
- [docs/decisions/ADR-011-unified-calendar-and-ai-semester-actions.md](decisions/ADR-011-unified-calendar-and-ai-semester-actions.md)
- [docs/domain-model.md](domain-model.md)
- [docs/unified-academic-calendar-implementation.md](unified-academic-calendar-implementation.md)
- [docs/unified-academic-calendar.md](unified-academic-calendar.md)
- [routes/api.php](../routes/api.php)
- [tests/Feature/AcademicCalendarApiTest.php](../tests/Feature/AcademicCalendarApiTest.php)
- [tests/Feature/AiAvailabilityActionsTest.php](../tests/Feature/AiAvailabilityActionsTest.php)
- [tests/Feature/AiPlanningActionsTest.php](../tests/Feature/AiPlanningActionsTest.php)
- [tests/Feature/AiSemesterActionsTest.php](../tests/Feature/AiSemesterActionsTest.php)
- [tests/Feature/AiStudyAvailabilityToolTest.php](../tests/Feature/AiStudyAvailabilityToolTest.php)
- [tests/Feature/AiSubtaskReminderActionsTest.php](../tests/Feature/AiSubtaskReminderActionsTest.php)
- [tests/Feature/BackendIntegrationSmokeTest.php](../tests/Feature/BackendIntegrationSmokeTest.php)
- [tests/Unit/AgentOrchestratorTest.php](../tests/Unit/AgentOrchestratorTest.php)
- [tests/Unit/AvailabilityActionTest.php](../tests/Unit/AvailabilityActionTest.php)
- [tests/Unit/LessonActionTest.php](../tests/Unit/LessonActionTest.php)
- [tests/Unit/PlanningActionTest.php](../tests/Unit/PlanningActionTest.php)
- [tests/Unit/SemesterActionSchemaTest.php](../tests/Unit/SemesterActionSchemaTest.php)
- [tests/Unit/SubtaskReminderActionTest.php](../tests/Unit/SubtaskReminderActionTest.php)
- [tests/Unit/TaskActionTest.php](../tests/Unit/TaskActionTest.php)
