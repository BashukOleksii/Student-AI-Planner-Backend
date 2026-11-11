# Small follow-up tasks

> Current implementation supersession: Historical tasks 2/5 and unsupported-semester statements below predate implemented unified calendar, AI semester actions and automatic owner reviews. Do not repeat those implementation tasks. Prioritize the dedicated disposable-MySQL regression/concurrency stage, then browser review integration and scheduler/delivery observation; [current required checks](../automatic-learning-recovery.md#deferred-verification). Product decision: automatic proposals with explicit confirmation; no unattended relocation.

Order prioritizes correctness and integration. Each item is independently reviewable and grounded in [integration gaps](integration-gaps.md); no item authorizes production changes or a new architecture. Optional capabilities follow source/contract decisions.

## 1. Establish current-head database regression evidence

- **Goal:** determine whether existing MySQL contracts hold at the audited commit.
- **Context:** I1/I6; [SemesterPlanningCalendarTest](../../tests/Feature/SemesterPlanningCalendarTest.php), [AiToolsIntegrationTest](../../tests/Feature/AiToolsIntegrationTest.php), [tests/TestCase.php](../../tests/TestCase.php) guard critical integration. Prior session failures concern an older commit.
- **Requirements:** provision and positively identify a disposable MySQL database; record engine/version/isolation and exact commit; run focused ownership/calendar/lifecycle tests, then smoke, then full suite sequentially; retain JUnit output and classify each failure by demonstrated cause.
- **Constraints:** no shared/personal database, no parallel suites against one database, no weakening assertions or automatic migration rollback against populated data.
- **Acceptance criteria:** reproducible command/result manifest; every failure linked to source/test and a separate minimal repair proposal. Passing is not assumed.
- **Required tests:** existing `SemesterPlanningCalendarTest`, `AiToolsIntegrationTest`, `AiSessionLifecycleActionsTest`, `BackendIntegrationSmokeTest`; `phpunit-smoke.xml`; complete offline MySQL suite when focused verification permits.

## 2. Resolve calendar presentation contract and correct stale architecture prose

- **Goal:** make frontend calendar expectations agree with the shared planner source policy.
- **Context:** I1/I7; [ScheduleService](../../app/Services/Schedule/ScheduleService.php) remains legacy-only while [EffectiveScheduleReadService](../../app/Services/AI/Tools/EffectiveScheduleReadService.php) is combined; [SemesterPlanningCalendarTest](../../tests/Feature/SemesterPlanningCalendarTest.php) captures source rules.
- **Requirements:** document a deliberate choice between separate legacy/semester views and a future combined read API; specify source identity, suppression, local today/week boundaries, ordering, truncation and null classification; amend stale stage summaries with dated scope notes.
- **Constraints:** this task is documentation/contract only; preserve current REST responses and planner algorithm.
- **Acceptance criteria:** frontend can choose correct routes without presenting an incomplete calendar as combined; no claim that semester planning integration is absent.
- **Required tests:** review existing `ScheduleViewTest`, `SemesterScheduleReadApiTest`, `SemesterPlanningCalendarTest`, `AiToolsIntegrationTest`; validate all documentation links. An eventual combined endpoint needs its own implementation task and owned mixed-source parity tests.

## 3. Verify scheduler-to-inbox operation in a disposable staging setup

- **Goal:** demonstrate one real due reminder reaches the owned inbox once.
- **Context:** I4; [ReminderDeliveryService](../../app/Services/Reminders/ReminderDeliveryService.php) and [ReminderDeliveryTest](../../tests/Feature/ReminderDeliveryTest.php).
- **Requirements:** use synthetic owners/targets; record scheduler heartbeat, command counts, dispatch latency, receipt and authenticated inbox read; agree backlog/error thresholds and monitoring ownership.
- **Constraints:** no real student data or external email/push, no changes to production cron/infrastructure; do not run dispatch against an unidentified database.
- **Acceptance criteria:** owned delivery observed, duplicate dispatch preserves one receipt, foreign inbox access denied, corruption remains private; operational settings documented.
- **Required tests:** existing `ReminderDeliveryTest`, `ReminderDeliveryConcurrencyTest`, `NotificationInboxApiTest`; one staging scheduler/inbox observation separately labelled from PHPUnit.

## 4. Verify real browser authentication and password recovery delivery

- **Goal:** close browser/mail verification gaps without inferring them from HTTP fakes.
- **Context:** U2/U3 and I4/I6; [PasswordManagementService](../../app/Services/Auth/PasswordManagementService.php), [PasswordManagementTest](../../tests/Feature/PasswordManagementTest.php), [AuthRateLimitTest](../../tests/Feature/AuthRateLimitTest.php).
- **Requirements:** synthetic account on approved staging frontend/backend origins, real browser cookies/CSRF/CORS/throttle checks and approved test mailbox; verify reset-link worker delivery, expiry, single use and revoked sessions.
- **Constraints:** no credentials in artifacts, no production account changes; establish mailbox and infrastructure authorization before external delivery.
- **Acceptance criteria:** browser trace and redacted mailbox receipt distinguish acknowledgement, queued job and delivered mail; deployment origin/queue/session decisions recorded.
- **Required tests:** existing `AuthProfileTest`, `FrontendApiContractTest`, `PasswordManagementTest`, `AuthRateLimitTest`, plus real-browser staging flow (a PHP browser helper alone is insufficient).

## 5. Confirm recovery autonomy scope before adding a trigger

- **Goal:** resolve T4/X2 ambiguity with a reviewable decision rather than a background writer.
- **Context:** [TaskService::miss](../../app/Services/Tasks/TaskService.php), [StudyRecoveryPreviewService](../../app/Services/Planning/StudyRecoveryPreviewService.php), [TaskMissedStatusTest](../../tests/Feature/TaskMissedStatusTest.php).
- **Requirements:** decide whether explicit preview/confirmation satisfies automatic relocation; if unattended operation is required, define opt-in, lifecycle authority, horizon, trigger, consent, stale checks, idempotency and user-visible failure handling in a separate brief.
- **Constraints:** decision-only; no silent status inference, no duplicate planner, no scheduler/writer in this task.
- **Acceptance criteria:** approved mandatory/optional classification and a single bounded next implementation slice, or explicit acceptance of existing interactive recovery.
- **Required tests:** review `StudyRecoveryApiTest`, `AiSessionLifecycleActionsTest`, `StudyReplanningConfirmationTest`; any subsequent trigger needs concurrency, ownership, no-consent/no-write and retry regression coverage.

## 6. Close original-source and deletion/report wording decisions

- **Goal:** validate transcript fidelity and settle remaining ambiguous acceptance semantics.
- **Context:** S1/F3/N3/G1/G4; [routes/api.php](../../routes/api.php), [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php), [ReminderApiTest](../../tests/Feature/ReminderApiTest.php), [ProgressStatisticsApiTest](../../tests/Feature/ProgressStatisticsApiTest.php).
- **Requirements:** obtain original Project statement and ODT; compare extraction with the recorded source-bundle hash; confirm cancellation versus physical deletion, raw-slot alternatives versus task-aware allocation, and current metrics versus historical reports. Record deltas without inventing exports or retrospective snapshots.
- **Constraints:** documentation-only; preserve reminder history, API contracts and unknown actual minutes.
- **Acceptance criteria:** original attachment provenance and story-to-matrix deltas recorded; every disputed story has an explicit decision or remains labelled unresolved.
- **Required tests:** link/path checks and review of `ReminderApiTest`, `StudyPlanningServiceTest`, `ProgressComparisonApiTest`; implementation tests belong to later approved behavior changes.

## 7. Prepare an offline AI intent evaluation set before any paid evaluation

- **Goal:** make A1/A2/A4 live verification measurable and expose unsupported operations honestly.
- **Context:** [AgentOrchestrator](../../app/Services/AI/AgentOrchestrator.php), [PlanningActionRegistry](../../app/Services/AI/Actions/PlanningActionRegistry.php), [AgentOrchestratorTest](../../tests/Unit/AgentOrchestratorTest.php).
- **Requirements:** specify Ukrainian requests for combined calendar/free-time, ambiguous catalog names, semester edits, incomplete tasks, recovery with unknown lifecycle, reminder changes and progress reports; define expected tool names/arguments, clarification, confirmation and privacy outcomes.
- **Constraints:** fixture/specification task only; no paid calls, semester legacy-workaround writes, or exact-language answer matching. Future live evaluation needs explicit provider/budget authorization.
- **Acceptance criteria:** offline cases distinguish transport/orchestration correctness from real-model selection; unsupported semester writes route to management guidance. Optional team planning/export/AI import remain deferred pending scope approval.
- **Required tests:** existing `AgentOrchestratorTest`, `AiReadinessHardeningTest`, `AiSessionLifecycleActionsTest`, provider contract fixtures; live scoring later records model/version and structured behavior separately.
