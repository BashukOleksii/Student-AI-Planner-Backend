# Stage 19A-BE — Backend requirements audit

Audited application baseline: `1912ff45be03d5eed3dfeabe5fa6a1a2f13958a4`, branch `main`, 2026-11-08. This is a Backend assessment; no system-wide compliance verdict is assigned.

## Sources, scope and assessment rules

The independent Frontend sources were retrieved read-only through GitHub at commit **`a3bfbde90f9d1a5d1e30a80155153f824f03b068`**:

- [Original source bundle](https://github.com/BashukOleksii/Student-AI-Planner-Frontend/blob/a3bfbde90f9d1a5d1e30a80155153f824f03b068/docs/requirements/Stage10_Requirements_Source_Bundle.md), Git blob `09e73fb26b1a279216b68a8c5ea26c3510e72842`.
- [Original 48-group coverage matrix](https://github.com/BashukOleksii/Student-AI-Planner-Frontend/blob/a3bfbde90f9d1a5d1e30a80155153f824f03b068/docs/stage10/requirements-coverage.md). Its group identifiers and source references are preserved below; its historical implementation statuses are reassessed.

`S1`, `S2`, `S3` and `L` references follow that matrix's source/line convention. They identify the original copied source lines, not lines in this new document. Titles below are English semantic summaries. S1 is the project statement, S2 the detailed stories, and S3 development ideas. S3 examples do not automatically become mandatory. The Backend [Stage 10 coverage](../stage10/requirements-coverage.md) and [capability matrix](../stage10/ai-capability-matrix.md) are scoped Stage 11 addenda, not the original full requirements list.

Implementation vocabulary: `IMPLEMENTED`, `PARTIALLY IMPLEMENTED`, `NOT IMPLEMENTED`, `NOT BACKEND RESPONSIBILITY`, `UNDETERMINED`. Verification vocabulary, assessed independently: `VERIFIED`, `PARTIALLY VERIFIED`, `NOT VERIFIED`, `BLOCKED`. `VERIFIED` means the stated Backend contract has source and passing automated evidence at the audited baseline; it does not imply deployment, browser acceptance, exhaustive security assurance or real-model language quality. Absence claims use bounded source/route/registry inspection. A missing feature has no positive execution test, even when its absence is established.

All 48 groups include some Backend responsibility; none is assigned `NOT BACKEND RESPONSIBILITY`. Rendering, interaction design and browser integration remain Frontend responsibilities and are not Backend failures. The optional development group mixes Backend, Frontend and operations work.

Evidence links are relative to this repository. Every REST path below has the `/api` prefix and is declared in [routes/api.php](../../routes/api.php); all personal-data routes use `auth:sanctum`. Relevant Models, Form Requests, Policies and Resources are part of the evidence, rather than endpoint presence alone. The [result report](backend-stage19a-result.md) supplies exact CI/local commands, outcomes, architecture and the complete Tool inventory. The [gap register](backend-functional-gaps.md) separates confirmed absences from ambiguities and operational evidence limits.

## R01 — Store, view and edit the class schedule

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S1:L12. Backend responsibility: persist owned schedule records and expose safe reads/writes.
- Evidence: [LessonService](../../app/Services/Schedule/LessonService.php)::create/update/delete; [ScheduleService](../../app/Services/Schedule/ScheduleService.php)::today/date/week; [Lesson](../../app/Models/Lesson.php) uses soft deletion and typed lifecycle fields.
- REST: `POST /lessons`, `GET/PATCH/DELETE /lessons/{lesson}`, `GET /schedule/today`, `/schedule/date/{date}`, `/schedule/week/{date}`. AI: schedule read and four Lesson proposals.
- PHPUnit: [LessonApiTest](../../tests/Feature/LessonApiTest.php), [LessonPersistenceTest](../../tests/Feature/LessonPersistenceTest.php), [ScheduleViewTest](../../tests/Feature/ScheduleViewTest.php), included in baseline CI.
- Gap / Frontend: no confirmed core Backend gap. Check display, edit forms and refresh after successful mutations independently.

## R02 — Today, date and week views with Lesson details

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L26–34. Backend responsibility: effective schedule selection in the persisted timezone; Subject, Teacher, room, type and interval presentation.
- Evidence: [ScheduleService](../../app/Services/Schedule/ScheduleService.php)::range uses active rows, half-open overlap and stable start/end/ID ordering; [ScheduleLessonResource](../../app/Http/Resources/ScheduleLessonResource.php)::toArray masks foreign related metadata; [ScheduleDateRequest](../../app/Http/Requests/ScheduleDateRequest.php).
- REST: `GET /schedule/today`, `/schedule/date/{date}`, `/schedule/week/{date}`. AI: `get_schedule_for_date`, one date per call.
- PHPUnit: [ScheduleViewTest](../../tests/Feature/ScheduleViewTest.php), [AiToolsIntegrationTest](../../tests/Feature/AiToolsIntegrationTest.php), including timezone/ownership boundaries.
- Gap / Frontend: week means ISO Monday-first week; cancelled/replaced originals are excluded. Validate local offsets and empty days in Vue.

## R03 — Manually create, edit and delete Lessons

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L38–50. Backend responsibility: validate owned associations and active conflicts, then apply one Lesson operation.
- Evidence: [LessonController](../../app/Http/Controllers/Api/LessonController.php)::store/update/destroy delegates to LessonService; [SaveLessonRequest](../../app/Http/Requests/SaveLessonRequest.php), [LessonInputRules](../../app/Services/Schedule/LessonInputRules.php), [LessonPolicy](../../app/Policies/LessonPolicy.php).
- REST: `POST /lessons`, `PATCH/DELETE /lessons/{lesson}`. AI: create/update/cancel proposals; AI hard/soft delete Tool is absent by design.
- PHPUnit: [LessonApiTest](../../tests/Feature/LessonApiTest.php), [LessonSecurityTest](../../tests/Feature/LessonSecurityTest.php), [LessonLifecycleTest](../../tests/Feature/LessonLifecycleTest.php).
- Gap / Frontend: API deletion is soft deletion. Concrete UTC Lesson instances are editable; XLSX weekly rules are materialized into instances, not a general recurring-series editor.

## R04 — Excel import with preview and useful errors

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L54–58. Backend responsibility: parse supported XLSX, materialize dates, resolve owned references, persist a review and validate before commit.
- Evidence: [ScheduleWorkbookParser](../../app/Services/Imports/ScheduleWorkbookParser.php)::parse; [ScheduleOccurrenceMaterializer](../../app/Services/Imports/ScheduleOccurrenceMaterializer.php)::materialize; [ScheduleImportPreviewService](../../app/Services/Imports/ScheduleImportPreviewService.php)::create; [StoreScheduleImportRequest](../../app/Http/Requests/StoreScheduleImportRequest.php); [ScheduleImportBatchPolicy](../../app/Policies/ScheduleImportBatchPolicy.php).
- REST: `POST /schedule-imports`, `GET /schedule-imports/{scheduleImportBatch}`, `POST /schedule-imports/{scheduleImportBatch}/revalidate|commit`. AI import Tool: absent.
- PHPUnit: [ScheduleWorkbookParserTest](../../tests/Unit/ScheduleWorkbookParserTest.php), [ScheduleImportPreviewApiTest](../../tests/Feature/ScheduleImportPreviewApiTest.php), [ScheduleImportCommitTest](../../tests/Feature/ScheduleImportCommitTest.php).
- Gap / Frontend: supported template XLSX parsing is implemented; arbitrary spreadsheet recognition is not promised. Render row errors and require review before commit.

## R05 — Re-import and duplicate avoidance

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L60–62. Backend responsibility: stable imported identity per owner/academic period and safe reconciliation.
- Evidence: [ScheduleImportFingerprint](../../app/Services/Imports/ScheduleImportFingerprint.php)::generate; [ScheduleImportCandidateClassifier](../../app/Services/Imports/ScheduleImportCandidateClassifier.php)::classify; [ScheduleImportPreviewRevalidator](../../app/Services/Imports/ScheduleImportPreviewRevalidator.php)::refreshLocked; [ScheduleImportCommitService](../../app/Services/Imports/ScheduleImportCommitService.php)::commit/apply. Operations include create/update/restore/preserve/noop.
- REST: existing import revalidate/commit routes. AI: none.
- PHPUnit: [ScheduleImportFingerprintTest](../../tests/Unit/ScheduleImportFingerprintTest.php), [ScheduleImportPreviewRevalidationTest](../../tests/Feature/ScheduleImportPreviewRevalidationTest.php), [ScheduleImportCommitTest](../../tests/Feature/ScheduleImportCommitTest.php), [ScheduleImportPersistenceTest](../../tests/Feature/ScheduleImportPersistenceTest.php).
- Gap / Frontend: changed reviewed state yields a refreshed preview requiring another review; new-semester import does not purge historical semesters. Render the conflict/review cycle.

## R06 — Create, edit and remove replacements

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L66–70. Backend responsibility: maintain original/replacement identity and validate restoration conflicts.
- Evidence: [LessonService](../../app/Services/Schedule/LessonService.php)::createReplacement/update/delete/replacementOriginal; [Lesson](../../app/Models/Lesson.php) original/replacement relations; [LessonResource](../../app/Http/Resources/LessonResource.php).
- REST: `POST /lessons/{lesson}/replacement`, `PATCH/DELETE /lessons/{lesson}`. AI: `propose_lesson_replacement`, update/cancel proposals; removal/restoration remains REST.
- PHPUnit: [LessonLifecycleTest](../../tests/Feature/LessonLifecycleTest.php)::test_delete_replacement_restores_original_and_history_can_be_reused and test_revert_conflict_rolls_back_both_rows; [AiLessonActionsTest](../../tests/Feature/AiLessonActionsTest.php).
- Gap / Frontend: cancellation retains the original as replaced; deleting a replacement restores it only after conflict checks. Test these distinct buttons and history states.

## R07 — Tasks, Subjects, deadlines, estimates and priorities

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S1:L12; S2:L76–92. Backend responsibility: owned Task CRUD with deterministic field/association validation.
- Evidence: [TaskService](../../app/Services/Tasks/TaskService.php)::create/update/delete/prepare; [TaskInputRules](../../app/Services/Tasks/TaskInputRules.php), [SaveTaskRequest](../../app/Http/Requests/SaveTaskRequest.php), [TaskPolicy](../../app/Policies/TaskPolicy.php), [TaskResource](../../app/Http/Resources/TaskResource.php), [Task](../../app/Models/Task.php).
- REST: `GET/POST /tasks`, `GET/PATCH/DELETE /tasks/{task}`. AI: Task create/update/complete/reopen proposals.
- PHPUnit: [TaskApiTest](../../tests/Feature/TaskApiTest.php), [TaskDeletionTest](../../tests/Feature/TaskDeletionTest.php), [TaskSecurityTest](../../tests/Feature/TaskSecurityTest.php), [AiTaskActionsTest](../../tests/Feature/AiTaskActionsTest.php).
- Gap / Frontend: estimates and deadlines can be null; planning excludes missing estimates instead of inventing effort. Status changes use lifecycle endpoints.

## R08 — Decompose Tasks into named, estimated, deadline-bound Subtasks

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L96–102. Backend responsibility: owned parent-child CRUD, child effort and deadlines within parent constraints.
- Evidence: [SubtaskService](../../app/Services/Tasks/SubtaskService.php)::create/update/prepare/validateDeadline; [SubtaskInputRules](../../app/Services/Tasks/SubtaskInputRules.php), [SaveSubtaskRequest](../../app/Http/Requests/SaveSubtaskRequest.php), [SubtaskPolicy](../../app/Policies/SubtaskPolicy.php), [Subtask](../../app/Models/Subtask.php).
- REST: `GET/POST /tasks/{task}/subtasks`, `GET/PATCH/DELETE /tasks/{task}/subtasks/{subtask}` with scoped bindings. AI: Subtask create/update proposals.
- PHPUnit: [SubtaskApiTest](../../tests/Feature/SubtaskApiTest.php), [SubtaskSecurityTest](../../tests/Feature/SubtaskSecurityTest.php), [AiSubtaskReminderActionsTest](../../tests/Feature/AiSubtaskReminderActionsTest.php).
- Gap / Frontend: active children become authoritative planning work; do not also allocate the parent estimate.

## R09 — Child statuses, aggregate progress and ordering

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L104–108. Backend responsibility: independent child completion/reopening, exact permutation reordering and count-based progress.
- Evidence: [SubtaskService](../../app/Services/Tasks/SubtaskService.php)::complete/reopen/reorder; [TaskService](../../app/Services/Tasks/TaskService.php)::progress; [ReorderSubtasksRequest](../../app/Http/Requests/ReorderSubtasksRequest.php); [TaskProgressResource](../../app/Http/Resources/TaskProgressResource.php).
- REST: child `/complete|reopen`, `POST /tasks/{task}/subtasks/reorder`, `GET /tasks/{task}/progress`. AI: read/create/update/complete/reopen children; reorder/delete remain REST.
- PHPUnit: [SubtaskLifecycleTest](../../tests/Feature/SubtaskLifecycleTest.php), [SubtaskReorderTest](../../tests/Feature/SubtaskReorderTest.php), [TaskProgressTest](../../tests/Feature/TaskProgressTest.php).
- Gap / Frontend: progress is null with no active children, otherwise rounded completed-child percentage. Parent status remains independent; no missed Subtask state is supported.

## R10 — Planned, completed and unfinished work for recovery

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L112–118. Backend responsibility: distinguish unfinished work and allow deterministic recovery of remaining effort.
- Evidence: [TaskStatus](../../app/Enums/TaskStatus.php)::isUnfinished (`pending`, `missed`); [TaskService](../../app/Services/Tasks/TaskService.php)::miss/complete/reopen; [StudyRecoveryPreviewService](../../app/Services/Planning/StudyRecoveryPreviewService.php)::preview; [RemainingEffortService](../../app/Services/Planning/RemainingEffortService.php).
- REST: `POST /tasks/{task}/miss|complete|reopen`, `/planning/recovery/preview`, `/planning/study-plans/confirm`. AI: missed filter and existing complete/reopen/create-plan proposals.
- PHPUnit: [TaskMissedStatusTest](../../tests/Feature/TaskMissedStatusTest.php), [TaskMissedMigrationTest](../../tests/Feature/TaskMissedMigrationTest.php), [StudyRecoveryApiTest](../../tests/Feature/StudyRecoveryApiTest.php).
- Gap / Frontend: assessment covers status plus interactive recovery. Marking missed alone schedules nothing. Pending is the existing planned-work interpretation; unattended relocation is a separate, optional interpretation requiring a scope decision.

## R11 — List, nearest deadline, ordering and filters

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L122–128. Backend responsibility: owned, paginated AND-filtered Task selection.
- Evidence: [TaskService](../../app/Services/Tasks/TaskService.php)::paginate; [ListTasksRequest](../../app/Http/Requests/ListTasksRequest.php) supports status/priority/Subject/deadline bounds. Ordering is deadline ascending, null last, ID ascending.
- REST: `GET /tasks`. AI: `list_tasks` supports status/priority/Subject/page/per_page; deadline bounds are REST-only.
- PHPUnit: [TaskIndexTest](../../tests/Feature/TaskIndexTest.php), [AiToolsIntegrationTest](../../tests/Feature/AiToolsIntegrationTest.php), [TaskMissedStatusTest](../../tests/Feature/TaskMissedStatusTest.php).
- Gap / Frontend: no arbitrary sort direction is required by the source. REST maximum page size is 100; Tool maximum is 50. Preserve pagination rather than treating a page as the whole dataset.

## R12 — Free intervals for a day or week with a minimum duration

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L134–140. Backend responsibility: recurring availability expansion and subtraction of effective Lessons/planned reservations.
- Evidence: [FreeTimeService](../../app/Services/Planning/FreeTimeService.php)::calculate/sources; [AvailabilityOccurrenceService](../../app/Services/Planning/AvailabilityOccurrenceService.php)::expand; [TimeIntervalService](../../app/Services/Planning/TimeIntervalService.php); [FreeSlotsRequest](../../app/Http/Requests/FreeSlotsRequest.php), [FreeSlotResource](../../app/Http/Resources/FreeSlotResource.php).
- REST: `GET /planning/free-slots`. AI: `find_free_study_slots`.
- PHPUnit: [FreeTimeServiceTest](../../tests/Feature/FreeTimeServiceTest.php), [FreeSlotsApiTest](../../tests/Feature/FreeSlotsApiTest.php), [AvailabilityOccurrenceServiceTest](../../tests/Unit/AvailabilityOccurrenceServiceTest.php).
- Gap / Frontend: up to 31 local dates; free geometry does not guarantee deadline/break/workload feasibility. No configured availability means no invented study hours.

## R13 — Weekly windows, daily/weekly limits and breaks

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L144–152. Backend responsibility: persist personal constraints and enforce them in planning.
- Evidence: [StudyAvailabilityService](../../app/Services/Planning/StudyAvailabilityService.php)::prepare/create/update/delete and shared [StudyAvailabilityInputRules](../../app/Services/Planning/StudyAvailabilityInputRules.php); [PlanningPreferenceService](../../app/Services/Planning/PlanningPreferenceService.php); [PlanningConstraintService](../../app/Services/Planning/PlanningConstraintService.php)::evaluate; [StudyAvailabilityWindow](../../app/Models/StudyAvailabilityWindow.php), [PlanningPreference](../../app/Models/PlanningPreference.php).
- REST: `/study-availability-windows` GET/POST/PATCH/DELETE; `/planning-preferences` GET/PUT. AI: weekly read and three availability proposals; numeric preference changes are REST-only.
- PHPUnit: [StudyAvailabilityApiTest](../../tests/Feature/StudyAvailabilityApiTest.php), [PlanningPreferenceApiTest](../../tests/Feature/PlanningPreferenceApiTest.php), [PlanningConstraintServiceTest](../../tests/Unit/PlanningConstraintServiceTest.php).
- Gap / Frontend: ISO weekdays 1–7, exact local clocks, end may be `24:00:00`; overnight windows require explicit splitting. Null limits remain unconfigured.

## R14 — Find time for work, considering period, priority and deadline

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L156–162. Backend responsibility: combine free-time geometry with authoritative work selection and allocation.
- Evidence: [StudyPlanningService](../../app/Services/Planning/StudyPlanningService.php)::plan; [PlanningWorkSelectionService](../../app/Services/Planning/PlanningWorkSelectionService.php)::select/compareItems; [StudySessionAllocationService](../../app/Services/Planning/StudySessionAllocationService.php)::allocate; FreeTimeService::calculate.
- REST: `/planning/free-slots`, `POST /planning/study-plans/preview`. AI: free-slot read and `propose_study_plan_create`.
- PHPUnit: [StudyPlanningServiceTest](../../tests/Feature/StudyPlanningServiceTest.php), [PlanningWorkSelectionServiceTest](../../tests/Feature/PlanningWorkSelectionServiceTest.php), [StudySessionAllocationServiceTest](../../tests/Unit/StudySessionAllocationServiceTest.php).
- Gap / Frontend: priority/deadline enforcement comes from the planner. Free-slot results alone do not select work or persist a session.

## R15 — Automatically form a personal plan for a chosen horizon

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S1:L14; S2:L168–176. Backend responsibility: generate deterministic multi-session proposals and persist only the reviewed result.
- Evidence: [StudyPlanningService](../../app/Services/Planning/StudyPlanningService.php)::plan; [StudyPlanPersistenceService](../../app/Services/Planning/StudyPlanPersistenceService.php)::preview/confirm/validateBatch; [StudyPlanConfirmationService](../../app/Services/Planning/StudyPlanConfirmationService.php); [PreviewStudyPlanRequest](../../app/Http/Requests/PreviewStudyPlanRequest.php), [ConfirmStudyPlanRequest](../../app/Http/Requests/ConfirmStudyPlanRequest.php).
- REST: `POST /planning/study-plans/preview|confirm`. AI: `propose_study_plan_create` with separate explicit action confirmation.
- PHPUnit: [StudyPlanApiTest](../../tests/Feature/StudyPlanApiTest.php), [StudyPlanConcurrencyTest](../../tests/Feature/StudyPlanConcurrencyTest.php), [AiPlanningActionsTest](../../tests/Feature/AiPlanningActionsTest.php).
- Gap / Frontend: horizon is 1–31 inclusive local dates; partial plans require explicit acceptance. Preview is not persistence; direct REST replay may conflict, whereas executed AI actions replay stored receipts.

## R16 — Deadline ordering, warnings and splitting large work

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L180–186. Backend responsibility: deadline-first work order, no post-deadline placement and exact unallocated effort diagnostics.
- Evidence: [PlanningWorkSelectionService](../../app/Services/Planning/PlanningWorkSelectionService.php)::compareItems/effectiveDeadline; [StudySessionAllocationService](../../app/Services/Planning/StudySessionAllocationService.php)::allocate; [PlanningConstraintService](../../app/Services/Planning/PlanningConstraintService.php)::evaluate; [StudyPlanResource](../../app/Http/Resources/StudyPlanResource.php).
- REST/AI: study-plan preview/confirm and create-plan proposal.
- PHPUnit: [StudyPlanningServiceTest](../../tests/Feature/StudyPlanningServiceTest.php), [StudySessionAllocationServiceTest](../../tests/Unit/StudySessionAllocationServiceTest.php), [PlanningConstraintServiceTest](../../tests/Unit/PlanningConstraintServiceTest.php).
- Gap / Frontend: greedy failure reports this allocation's limitations, not a proof of global impossibility. Show unplanned/partial work and reasons; do not hide excluded work when fully_allocated is true.

## R17 — Natural-language chat without special commands

**Implementation: IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S1:L14; S2:L192–198. Backend responsibility: bounded conversational orchestration and provider integration.
- Evidence: [AgentOrchestrator](../../app/Services/AI/AgentOrchestrator.php)::run/instructions; [AiConversationService](../../app/Services/AI/AiConversationService.php)::send; [LlmProvider](../../app/Services/AI/LlmProvider.php); [OpenAiResponsesProvider](../../app/Services/AI/OpenAiResponsesProvider.php), [GeminiGenerateContentProvider](../../app/Services/AI/GeminiGenerateContentProvider.php).
- REST: `/ai/conversations` and `POST /ai/conversations/{conversation}/messages` with Idempotency-Key. AI: 31 definitions.
- PHPUnit: [AgentOrchestratorTest](../../tests/Unit/AgentOrchestratorTest.php), [AiChatTest](../../tests/Feature/AiChatTest.php), [LlmProviderContractTest](../../tests/Feature/LlmProviderContractTest.php).
- Gap / Frontend: protocol is verified with fakes; real free-form Ukrainian intent recognition, clarification and answer quality were not exercised. Provider availability and chat UI remain separate evidence requirements.

## R18 — Recognize schedule, Task, planning and rescheduling intent

**Implementation: IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S2:L202–210. Backend responsibility: expose capabilities and server instructions, validate every selected operation.
- Evidence: [AgentOrchestrator](../../app/Services/AI/AgentOrchestrator.php)::instructions/run provides trusted clock/timezone, ambiguity rules, explicit dispatch and global limits; [ToolArgumentValidator](../../app/Services/AI/Tools/ToolArgumentValidator.php), domain action argument validators.
- REST: existing message endpoint; AI: relevant read/proposal registries, no client-selected PHP dispatch.
- PHPUnit: [AgentOrchestratorTest](../../tests/Unit/AgentOrchestratorTest.php)::test_parallel_calls_and_dependent_rounds_preserve_order_and_previous_turn_context; [PlanningActionTest](../../tests/Unit/PlanningActionTest.php); native provider contract tests.
- Gap / Frontend: fake call selection is supplied by fixtures. It proves validation/dispatch, not real intent accuracy. The client must render clarification and safe errors without inventing a proposed action.

## R19 — AI reads the student's schedule and Tasks

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L214–216. Backend responsibility: read only authenticated owned data with bounded presentation.
- Evidence: [ToolRegistry](../../app/Services/AI/Tools/ToolRegistry.php)::definitions; [ToolExecutor](../../app/Services/AI/Tools/ToolExecutor.php)::execute; [ReadOnlyTools](../../app/Services/AI/Tools/ReadOnlyTools.php)::schedule/tasks; [ToolResultPresenter](../../app/Services/AI/Tools/ToolResultPresenter.php).
- REST: corresponding schedule/Task reads; AI: `get_schedule_for_date`, `list_tasks`.
- PHPUnit: [AiToolFoundationTest](../../tests/Unit/AiToolFoundationTest.php), [AiToolsIntegrationTest](../../tests/Feature/AiToolsIntegrationTest.php) all-table snapshots and foreign-user isolation.
- Gap / Frontend: unknown fields, owner overrides and malformed types fail; displayed personal facts must come from successful reads. Session/subtask caps and Task pagination are explicit.

## R20 — AI manages Tasks

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L218. Backend responsibility: proposed create/update/complete/reopen through existing Task/Subtask Services and explicit decisions.
- Evidence: [TaskActionProposalService](../../app/Services/AI/Actions/TaskActionProposalService.php)::prepare; [SubtaskReminderActionService](../../app/Services/AI/Actions/SubtaskReminderActionService.php)::prepare/execute; [TaskActionConfirmationService](../../app/Services/AI/Actions/TaskActionConfirmationService.php)::decide; [AiPendingActionResource](../../app/Http/Resources/AiPendingActionResource.php).
- REST: message then `POST /ai/conversations/{conversation}/actions/{action}/confirm|reject`. AI: four Task and four Subtask proposals.
- PHPUnit: [AiTaskActionsTest](../../tests/Feature/AiTaskActionsTest.php), [AiTaskActionConcurrencyTest](../../tests/Feature/AiTaskActionConcurrencyTest.php), [AiSubtaskReminderActionsTest](../../tests/Feature/AiSubtaskReminderActionsTest.php).
- Gap / Frontend: management does not imply every REST operation is an AI Tool. AI delete/reorder/miss Tools are absent; exact supported operations are server-controlled. Never interpret chat “yes” as execution.

## R21 — AI manages Lessons and replacements

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L220–222. Backend responsibility: safe Lesson proposals and confirmed domain execution.
- Evidence: [LessonActionRegistry](../../app/Services/AI/Actions/LessonActionRegistry.php); [LessonActionArgumentValidator](../../app/Services/AI/Actions/LessonActionArgumentValidator.php); [LessonActionService](../../app/Services/AI/Actions/LessonActionService.php)::prepare/execute delegates to LessonService; [LessonActionPresenter](../../app/Services/AI/Actions/LessonActionPresenter.php).
- REST: existing AI decisions. AI: `propose_lesson_create`, `propose_lesson_update`, `propose_lesson_cancel`, `propose_lesson_replacement`.
- PHPUnit: [LessonActionTest](../../tests/Unit/LessonActionTest.php), [AiLessonActionsTest](../../tests/Feature/AiLessonActionsTest.php), [AiLessonActionConcurrencyTest](../../tests/Feature/AiLessonActionConcurrencyTest.php), [AiLessonActionMigrationTest](../../tests/Feature/AiLessonActionMigrationTest.php).
- Gap / Frontend: Stage 11 resolved the historical missing adapters. Catalog discovery is absent (G19-05); missing Subject/Teacher/period IDs require clarification. Verify all four reviews in Vue; Backend evidence does not establish their UI implementation.

## R22 — AI finds and manages study availability

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L224. Backend responsibility: distinguish computed free slots from recurring configuration; allow owned window reads and confirmed CRUD.
- Evidence: [ReadOnlyTools](../../app/Services/AI/Tools/ReadOnlyTools.php)::availabilityWindows; [AvailabilityActionRegistry](../../app/Services/AI/Actions/AvailabilityActionRegistry.php); [AvailabilityActionService](../../app/Services/AI/Actions/AvailabilityActionService.php)::prepare/execute/load; [StudyAvailabilityService](../../app/Services/Planning/StudyAvailabilityService.php).
- REST: availability CRUD and existing AI decisions. AI: `list_study_availability_windows`, `find_free_study_slots`, three availability proposals.
- PHPUnit: [AiStudyAvailabilityToolTest](../../tests/Feature/AiStudyAvailabilityToolTest.php), [AiAvailabilityActionsTest](../../tests/Feature/AiAvailabilityActionsTest.php), [AiAvailabilityActionConcurrencyTest](../../tests/Feature/AiAvailabilityActionConcurrencyTest.php), [AiAvailabilityActionMigrationTest](../../tests/Feature/AiAvailabilityActionMigrationTest.php).
- Gap / Frontend: Stages 18A/B resolve the Backend gap. All owned weekly changes and timezone changes intentionally stale pending availability proposals. No automatic session rescheduling; preserve `24:00:00` in review and receipt.

## R23 — AI forms and manages the study plan

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L226. Backend responsibility: wrap authoritative planning/replanning/rescheduling with existing pending actions.
- Evidence: [PlanningActionRegistry](../../app/Services/AI/Actions/PlanningActionRegistry.php); [PlanningActionService](../../app/Services/AI/Actions/PlanningActionService.php)::prepare/execute/expiration delegates to transaction-aware planning Services; [PlanningActionConsent](../../app/Services/AI/Actions/PlanningActionConsent.php).
- REST: existing AI confirm/reject. AI: `propose_study_plan_create`, `propose_adaptive_replanning`, `propose_session_reschedule`.
- PHPUnit: [PlanningActionTest](../../tests/Unit/PlanningActionTest.php), [AiPlanningActionsTest](../../tests/Feature/AiPlanningActionsTest.php), [AiPlanningActionConcurrencyTest](../../tests/Feature/AiPlanningActionConcurrencyTest.php).
- Gap / Frontend: tokens are private/encrypted. Partial plans and unplaced cancellations require distinct client-supplied boolean consents. No model-owned session batches or automatic multiple-action execution.

## R24 — AI relocates unfinished work into suitable future time

**Implementation: IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S1:L14; S2:L228,244–248. Backend responsibility: compute remaining effort and find deadline-respecting placements, retaining historical work.
- Evidence: [RemainingEffortService](../../app/Services/Planning/RemainingEffortService.php), [PlanningWorkSelectionService](../../app/Services/Planning/PlanningWorkSelectionService.php)::select; [StudyRecoveryPreviewService](../../app/Services/Planning/StudyRecoveryPreviewService.php)::preview; [StudyReplanningPersistenceService](../../app/Services/Planning/StudyReplanningPersistenceService.php)::confirmWithinTransaction; PlanningActionService::execute.
- REST: recovery preview + ordinary study-plan confirmation; adaptive preview/confirm. AI: create-plan for unallocated effort, adaptive for affected future sources, manual reschedule for one eligible source.
- PHPUnit: [StudyRecoveryApiTest](../../tests/Feature/StudyRecoveryApiTest.php), [StudyReplanningConfirmationTest](../../tests/Feature/StudyReplanningConfirmationTest.php), [AiPlanningActionsTest](../../tests/Feature/AiPlanningActionsTest.php).
- Gap / Frontend: interactive domain paths are tested separately; no dedicated AI recovery-history read or targeted end-to-end missed-Task chat fixture was found. Missed/elapsed history is not an adaptive source. Unattended triggering is absent; real-language recovery selection remains unverified.

## R25 — Dynamic multi-Tool sequencing and understandable outcomes

**Implementation: IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S2:L232–238. Backend responsibility: iterative provider handoff, structured outcomes, limits and authoritative execution history.
- Evidence: [AgentOrchestrator](../../app/Services/AI/AgentOrchestrator.php)::run; [AgentLimits](../../app/Services/AI/AgentLimits.php); [AiConversationService](../../app/Services/AI/AiConversationService.php)::claim/finish/history; [ProviderHistory](../../app/Services/AI/ProviderHistory.php).
- REST: idempotent message turns and explicit decisions. AI: multiple reads and at most one proposal attempt per turn across domains.
- PHPUnit: [AgentOrchestratorTest](../../tests/Unit/AgentOrchestratorTest.php), [AiChatConcurrencyTest](../../tests/Feature/AiChatConcurrencyTest.php), [AiChatPersistenceTest](../../tests/Feature/AiChatPersistenceTest.php), [AiAvailabilityActionsTest](../../tests/Feature/AiAvailabilityActionsTest.php)::test_owned_read_can_feed_a_later_availability_proposal_in_the_same_turn.
- Gap / Frontend: dependent structured rounds are verified, natural-language explanation quality is not. Proposal text cannot manufacture execution success; rely on action state/receipt and retain unknown-type guards.

## R26 — Move one plan element without rebuilding the plan

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L252. Backend responsibility: safe single-session transfer with exact remaining effort, constraints and predecessor/successor history.
- Evidence: [StudySessionReschedulingService](../../app/Services/Planning/StudySessionReschedulingService.php)::preview/reschedule/rescheduleWithinTransaction; [StudySessionMutationContextService](../../app/Services/Planning/StudySessionMutationContextService.php); [RescheduleStudySessionRequest](../../app/Http/Requests/RescheduleStudySessionRequest.php), [StudySessionPolicy](../../app/Policies/StudySessionPolicy.php).
- REST: `POST /study-sessions/{studySession}/reschedule`. AI: `propose_session_reschedule`.
- PHPUnit: [StudySessionLifecycleTest](../../tests/Feature/StudySessionLifecycleTest.php), [StudySessionConcurrencyTest](../../tests/Feature/StudySessionConcurrencyTest.php), [AiPlanningActionsTest](../../tests/Feature/AiPlanningActionsTest.php).
- Gap / Frontend: only future planned sources qualify. AI preliminary review is not full feasibility; confirmation revalidates. Historical missed/elapsed sessions use remaining-work planning instead.

## R27 — Rebuild after deadline, Task or schedule changes

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L254–258. Backend responsibility: analyze current reservations against current constraints and produce a safe reviewed replacement batch.
- Evidence: [StudyReplanningPreviewService](../../app/Services/Planning/StudyReplanningPreviewService.php)::preview; [StudyReplanningAnalysisService](../../app/Services/Planning/StudyReplanningAnalysisService.php); [StudyReplanningPersistenceService](../../app/Services/Planning/StudyReplanningPersistenceService.php)::preview/confirm; ordinary StudyPlanningService handles newly unallocated work.
- REST: `POST /planning/replanning/preview|confirm` and study-plan preview/confirm. AI: adaptive/create-plan proposals.
- PHPUnit: [StudyReplanningPreviewTest](../../tests/Feature/StudyReplanningPreviewTest.php), [StudyReplanningConfirmationTest](../../tests/Feature/StudyReplanningConfirmationTest.php), [StudyReplanningConcurrencyTest](../../tests/Feature/StudyReplanningConcurrencyTest.php).
- Gap / Frontend: launched interactively; valid/cannot-replan reservations remain. Adaptive replanning alone does not append all new work. A deadline or schedule edit does not itself trigger a background job.

## R28 — Planning conflict warnings, capacity warnings and priority advice

**Implementation: PARTIALLY IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S2:L262–266. Backend responsibility: explain deterministic planning limitations and priority order.
- Evidence: [WorkloadAdviceService](../../app/Services/Planning/WorkloadAdviceService.php)::analyze/recommendations; [PlanningWorkSelectionService](../../app/Services/Planning/PlanningWorkSelectionService.php)::compareItems; [PlanningConstraintService](../../app/Services/Planning/PlanningConstraintService.php)::evaluate; [WorkloadAdviceResource](../../app/Http/Resources/WorkloadAdviceResource.php).
- REST: `GET /planning/workload-advice`, planning previews. AI: planning diagnostics through proposals; no dedicated workload-advice Tool.
- PHPUnit: [WorkloadAdviceApiTest](../../tests/Feature/WorkloadAdviceApiTest.php)::test_priority_rank_preserves_deadlines_parent_priority_and_child_positions_without_parent_duplicates; allocator/constraint tests.
- Gap / Frontend: Stage 17 resolves deterministic rank, capacity alerts and recommendation codes. Literal Task-to-Task conflict identities/explanations are absent (G19-01); the response cannot name conflicting pairs. Whether aggregate constraint evidence satisfies that wording remains a scope decision. Do not call greedy shortfall mathematical impossibility.

## R29 — Task, deadline-relative and session reminders

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L272–278. Backend responsibility: validate one target, absolute/relative mode, future trigger and synchronization.
- Evidence: [ReminderService](../../app/Services/Reminders/ReminderService.php)::create/update; [ReminderMutationService](../../app/Services/Reminders/ReminderMutationService.php)::prepareCreate/prepareUpdate; [ReminderRules](../../app/Services/Reminders/ReminderRules.php), [ReminderTargetService](../../app/Services/Reminders/ReminderTargetService.php), [ReminderSynchronizationService](../../app/Services/Reminders/ReminderSynchronizationService.php); [StoreReminderRequest](../../app/Http/Requests/StoreReminderRequest.php).
- REST: `POST /reminders`, `PATCH /reminders/{reminder}`. AI: create/update reminder proposals.
- PHPUnit: [ReminderApiTest](../../tests/Feature/ReminderApiTest.php), [RelativeReminderApiTest](../../tests/Feature/RelativeReminderApiTest.php), [RelativeReminderConcurrencyTest](../../tests/Feature/RelativeReminderConcurrencyTest.php).
- Gap / Frontend: creation is scheduling, not delivery; relative triggers come from persisted anchors. Sent/cancelled history is retained and targets/mode cannot be changed through scheduled editing.

## R30 — AI creates, edits and cancels reminders

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L282–286. Backend responsibility: proposed Reminder operations delegated to the same validated writer at confirmation.
- Evidence: [SubtaskReminderActionRegistry](../../app/Services/AI/Actions/SubtaskReminderActionRegistry.php); [SubtaskReminderArgumentValidator](../../app/Services/AI/Actions/SubtaskReminderArgumentValidator.php); [SubtaskReminderActionService](../../app/Services/AI/Actions/SubtaskReminderActionService.php)::prepare/execute; shared ReminderMutationService.
- REST: existing AI confirm/reject. AI: `propose_reminder_create`, `propose_reminder_update`, `propose_reminder_cancel`, `list_reminders`.
- PHPUnit: [SubtaskReminderActionTest](../../tests/Unit/SubtaskReminderActionTest.php), [AiSubtaskReminderActionsTest](../../tests/Feature/AiSubtaskReminderActionsTest.php), [AiManagementActionConcurrencyTest](../../tests/Feature/AiManagementActionConcurrencyTest.php).
- Gap / Frontend: no delivery/cancel-all/bulk-delete Tool. Relative “after the last Lesson” needs grounded schedule/time resolution or clarification; real-language interpretation is outside fake-provider proof.

## R31 — View active reminders and edit them

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L290–292. Backend responsibility: bounded owned lists/detail and scheduled-only editing.
- Evidence: [ReminderController](../../app/Http/Controllers/Api/ReminderController.php)::index/show/update; [ReminderService](../../app/Services/Reminders/ReminderService.php)::listing/find; [ReminderIndexRequest](../../app/Http/Requests/ReminderIndexRequest.php), [UpdateReminderRequest](../../app/Http/Requests/UpdateReminderRequest.php), [ReminderPolicy](../../app/Policies/ReminderPolicy.php), [ReminderResource](../../app/Http/Resources/ReminderResource.php).
- REST: `GET /reminders[/{reminder}]`, `PATCH /reminders/{reminder}`. AI: reminder read/update proposal.
- PHPUnit: [ReminderApiTest](../../tests/Feature/ReminderApiTest.php), [ReminderConcurrencyTest](../../tests/Feature/ReminderConcurrencyTest.php), [ReminderPersistenceTest](../../tests/Feature/ReminderPersistenceTest.php).
- Gap / Frontend: filter scheduled status for active items. Do not offer mutable sent/cancelled history or confuse a notification read marker with Reminder editing.

## R32 — Delete reminders

**Implementation: PARTIALLY IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S2:L294. Backend responsibility: implement the agreed meaning of removal while preserving historical integrity.
- Evidence: [ReminderService](../../app/Services/Reminders/ReminderService.php)::cancel and [ReminderMutationService](../../app/Services/Reminders/ReminderMutationService.php)::cancel retain the record; [ReminderController](../../app/Http/Controllers/Api/ReminderController.php)::cancel; routes contain no Reminder DELETE.
- REST: `POST /reminders/{reminder}/cancel`. AI: `propose_reminder_cancel`; no deletion proposal.
- PHPUnit: [ReminderApiTest](../../tests/Feature/ReminderApiTest.php), [AiSubtaskReminderActionsTest](../../tests/Feature/AiSubtaskReminderActionsTest.php) verify cancellation/history; no literal deletion test exists.
- Gap / Frontend: G19-02 records the confirmed absence of literal removal. Acceptance of cancellation as the source's “delete” meaning is unresolved; do not invent a mandatory hard purge. Label the existing action as cancellation and preserve sent inbox history.

## R33 — Actually receive scheduled reminders

**Implementation: IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S2:L278. Backend responsibility: due dispatch, target revalidation and atomic in-app delivery.
- Evidence: [ReminderDeliveryService](../../app/Services/Reminders/ReminderDeliveryService.php)::dispatchDue/process; [DispatchDueReminders](../../app/Console/Commands/DispatchDueReminders.php)::handle; [routes/console.php](../../routes/console.php) schedules every minute; [NotificationInboxService](../../app/Services/Notifications/NotificationInboxService.php)::listing/read.
- REST: `GET /notifications`, `POST /notifications/{notification}/read`. AI: no dispatch/inbox mutation Tool.
- PHPUnit: [ReminderDeliveryTest](../../tests/Feature/ReminderDeliveryTest.php)::test_all_target_modes_deliver_atomically_and_preserve_every_other_table; [ReminderDeliveryConcurrencyTest](../../tests/Feature/ReminderDeliveryConcurrencyTest.php), [NotificationInboxApiTest](../../tests/Feature/NotificationInboxApiTest.php).
- Gap / Frontend: code and isolated dispatch tests pass; no live scheduler-to-inbox delivery was observed in this audit (V19-01). This path is synchronous database delivery and does not need a queue worker. External email/push channels are optional and absent.

## R34 — Completed/unfinished counts by chosen periods

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L300–302,306. Backend responsibility: explicit current populations and date-bounded completion/session activity.
- Evidence: [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php)::calculate/currentMetrics/completionMetrics; [ProgressTrendsService](../../app/Services/Progress/ProgressTrendsService.php)::calculate/summarize; [ProgressStatisticsRequest](../../app/Http/Requests/ProgressStatisticsRequest.php).
- REST: `GET /statistics/progress|trends`, `/statistics/reports/comparison`. AI: three statistics reads.
- PHPUnit: [ProgressStatisticsApiTest](../../tests/Feature/ProgressStatisticsApiTest.php), [ProgressTrendsApiTest](../../tests/Feature/ProgressTrendsApiTest.php), [TaskMissedStatusTest](../../tests/Feature/TaskMissedStatusTest.php).
- Gap / Frontend: current counts are lifetime active records, not historical population snapshots. Pending/missed/overdue counters are distinct; period activity covers 1–31 local dates. Do not label current counts as counts existing at a historical date.

## R35 — Overall Task completion percentage

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L304. Backend responsibility: authoritative numerator/denominator and empty behavior.
- Evidence: [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php)::calculate computes `tasks.current.completion_percent` as completed/all active Tasks, rounded to one decimal, null for zero population; [ProgressStatisticsResource](../../app/Http/Resources/ProgressStatisticsResource.php)::toArray.
- REST: `GET /statistics/progress`. AI: `get_progress_statistics` receives the shared additive field.
- PHPUnit: [ProgressStatisticsApiTest](../../tests/Feature/ProgressStatisticsApiTest.php); [Stage 13 contract](../stage13/statistics-api-contract.md) defines exact current-state semantics; baseline CI covers current implementation.
- Gap / Frontend: historical Stage 10 gap resolved. This metric differs from child progress and from a period-cohort completion rate. A whole-number JSON value remains a valid number; null must not become zero.

## R36 — Planned hours and daily workload distribution

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L310–312. Backend responsibility: integer scheduled-duration totals and zero-filled local-day activity.
- Evidence: [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php)::sessionMetrics; [ProgressTrendsService](../../app/Services/Progress/ProgressTrendsService.php)::summarize/totals; [ProgressTrendsResource](../../app/Http/Resources/ProgressTrendsResource.php).
- REST: `GET /statistics/progress|trends`. AI: progress/trends reads.
- PHPUnit: [ProgressTrendsServiceTest](../../tests/Unit/ProgressTrendsServiceTest.php), [ProgressTrendsIntegrationTest](../../tests/Feature/ProgressTrendsIntegrationTest.php), [ProgressStatisticsServiceTest](../../tests/Unit/ProgressStatisticsServiceTest.php).
- Gap / Frontend: reporting attributes a full session duration to its start date, while constraint workload splits real local-day/week contributions. Display status categories and convert seconds to hours without relabeling them actual effort.

## R37 — Subjects with the greatest workload

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L314. Backend responsibility: owned Subject aggregation with unassigned work and deterministic ordering.
- Evidence: [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php)::subjectWorkload; [ProgressStatisticsResource](../../app/Http/Resources/ProgressStatisticsResource.php) exposes `subject_workload_in_period`. Planned/completed scheduled seconds are grouped by current Task Subject, with historical Task compatibility checks.
- REST: `GET /statistics/progress`. AI: existing progress read includes the groups.
- PHPUnit: [ProgressStatisticsApiTest](../../tests/Feature/ProgressStatisticsApiTest.php), [ProgressStatisticsIntegrationTest](../../tests/Feature/ProgressStatisticsIntegrationTest.php), [AiToolsIntegrationTest](../../tests/Feature/AiToolsIntegrationTest.php).
- Gap / Frontend: Stage 13 resolved the missing aggregate. Sort is workload descending, Subject ID tie-break, unassigned last on ties. Retired session states are excluded. Current Subject assignment is not a historical Subject snapshot.

## R38 — Excessive-workload warnings

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L316. Backend responsibility: distinguish allocation shortfalls from measured persisted daily/weekly overload.
- Evidence: [WorkloadAdviceService](../../app/Services/Planning/WorkloadAdviceService.php)::analyze/persistedViolations; [WorkloadConstraintService](../../app/Services/Planning/WorkloadConstraintService.php)::existingViolations; [WorkloadAdviceRequest](../../app/Http/Requests/WorkloadAdviceRequest.php), [WorkloadAdviceResource](../../app/Http/Resources/WorkloadAdviceResource.php).
- REST: `GET /planning/workload-advice`. AI: no dedicated advice Tool; planning proposals expose their own diagnostics.
- PHPUnit: [WorkloadAdviceApiTest](../../tests/Feature/WorkloadAdviceApiTest.php)::test_existing_overload_has_separate_measured_evidence_and_excludes_retired_sessions; [ExistingWorkloadDiagnosticsTest](../../tests/Unit/ExistingWorkloadDiagnosticsTest.php).
- Gap / Frontend: Stage 17 implements on-demand warnings. Null limits do not imply an invented maximum; daily and weekly excess cannot be summed. Background warning delivery or a future forecast beyond the requested horizon is not specified as mandatory.

## R39 — Planned versus actual progress and timely/late completion

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L320–326. Backend responsibility: factual current persisted activity reduction and comparison.
- Evidence: [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php)::completionMetrics/sessionMetrics; [ProgressComparisonService](../../app/Services/Progress/ProgressComparisonService.php)::calculate/compare/delta; [ProgressComparisonRequest](../../app/Http/Requests/ProgressComparisonRequest.php).
- REST: progress/trends/comparison statistics endpoints. AI: corresponding three reads.
- PHPUnit: [ProgressStatisticsServiceTest](../../tests/Unit/ProgressStatisticsServiceTest.php), [ProgressComparisonServiceTest](../../tests/Unit/ProgressComparisonServiceTest.php), [ProgressComparisonIntegrationTest](../../tests/Feature/ProgressComparisonIntegrationTest.php).
- Gap / Frontend: known actual minutes and unknown actual counts remain separate; missing actual is not zero. Reopening or deadline edits affect current persisted facts. There is no immutable historical analytics snapshot or inferred productivity judgment.

## R40 — Period progress reports and AI analysis

**Implementation: IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S1:L14; S2:L330–334. Backend responsibility: queryable structured reports and safe AI statistical reads.
- Evidence: [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php)::calculate; [ProgressTrendsService](../../app/Services/Progress/ProgressTrendsService.php)::calculate; [ProgressComparisonService](../../app/Services/Progress/ProgressComparisonService.php)::calculate; [ReadOnlyTools](../../app/Services/AI/Tools/ReadOnlyTools.php)::execute.
- REST: three statistics endpoints. AI: `get_progress_statistics`, `get_daily_progress_trends`, `compare_progress_periods`.
- PHPUnit: [ProgressComparisonApiTest](../../tests/Feature/ProgressComparisonApiTest.php), [ProgressStatisticsIntegrationTest](../../tests/Feature/ProgressStatisticsIntegrationTest.php), [AiToolsIntegrationTest](../../tests/Feature/AiToolsIntegrationTest.php).
- Gap / Frontend: report data is verified; useful real-model narrative analysis is not. The source does not require PDF/XLSX export; absence of downloadable report files is a limitation, not a mandatory failure.

## R41 — Registration, validation and duplicate-email feedback

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L340–348. Backend responsibility: validate registration, hash password and establish the first-party session.
- Evidence: [AuthController](../../app/Http/Controllers/Api/AuthController.php)::register; [RegisterRequest](../../app/Http/Requests/Auth/RegisterRequest.php); [User](../../app/Models/User.php) hashed password cast; [UserResource](../../app/Http/Resources/UserResource.php) omits secrets.
- REST: `POST /auth/register`, preceded by standard SPA CSRF initialization. AI: not applicable to authentication.
- PHPUnit: [AuthProfileTest](../../tests/Feature/AuthProfileTest.php)::test_registration_persists_hash_defaults_and_authenticates_a_rotated_session and test_duplicate_email_registration_is_rejected; [BackendIntegrationSmokeTest](../../tests/Feature/BackendIntegrationSmokeTest.php).
- Gap / Frontend: registration returns 201 and rotates the session. Email verification is not an explicit requirement in the source. G19-06 records missing application registration throttling. Browser errors/navigation require Frontend verification.

## R42 — Login, session continuity, logout and invalid credentials

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L352–358. Backend responsibility: secure first-party cookie authentication and session invalidation.
- Evidence: [AuthController](../../app/Http/Controllers/Api/AuthController.php)::login/logout; [LoginRequest](../../app/Http/Requests/Auth/LoginRequest.php); [bootstrap/app.php](../../bootstrap/app.php)::statefulApi; [config/sanctum.php](../../config/sanctum.php), [config/session.php](../../config/session.php).
- REST: `POST /auth/login|logout`, `GET /profile`; Sanctum CSRF cookie route is vendor-provided. AI: none.
- PHPUnit: [AuthProfileTest](../../tests/Feature/AuthProfileTest.php)::test_login_authenticates_and_rotates_session_without_issuing_a_token, test_logout_invalidates_session_and_regenerates_csrf_token and CSRF/CORS cases; [FrontendApiContractTest](../../tests/Feature/FrontendApiContractTest.php).
- Gap / Frontend: finite server sessions are supported; perpetual/remember-me login is not promised. No personal access token issuance API exists. G19-06 records missing application login throttling; gateway limits and deployed cookies/CORS/HTTPS were not inspected.

## R43 — Change password

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L362. Backend responsibility: current-password validation, atomic password replacement and other-session revocation.
- Evidence: [PasswordController](../../app/Http/Controllers/Api/PasswordController.php)::change; [ChangePasswordRequest](../../app/Http/Requests/Auth/ChangePasswordRequest.php); [PasswordManagementService](../../app/Services/Auth/PasswordManagementService.php)::change/savePassword; [BcryptPassword](../../app/Rules/BcryptPassword.php).
- REST: `PATCH /profile/password`, authenticated, CSRF-protected and throttled. AI: no credential Tools.
- PHPUnit: [PasswordManagementTest](../../tests/Feature/PasswordManagementTest.php)::test_change_checks_current_password_preserves_current_login_and_revokes_only_other_owned_sessions and test_authentication_validation_and_existing_spa_csrf_protections.
- Gap / Frontend: Stage 12 resolves the missing Backend operation. The current SPA session rotates and survives; other owned sessions and reset links are revoked. Database sessions/Broker must share the User connection; unsupported configuration fails closed.

## R44 — Recover a forgotten password

**Implementation: IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S2:L364. Backend responsibility: uniform asynchronous request, trusted reset links, expiring single-use tokens and session revocation.
- Evidence: [PasswordController](../../app/Http/Controllers/Api/PasswordController.php)::forgot/reset; [PasswordManagementService](../../app/Services/Auth/PasswordManagementService.php)::requestReset/sendResetLink/reset/resetUrl; [SendPasswordResetLink](../../app/Jobs/SendPasswordResetLink.php), [ForgotPasswordRequest](../../app/Http/Requests/Auth/ForgotPasswordRequest.php), [ResetPasswordRequest](../../app/Http/Requests/Auth/ResetPasswordRequest.php).
- REST: `POST /auth/forgot-password|reset-password`, throttled. AI: none.
- PHPUnit: [PasswordManagementTest](../../tests/Feature/PasswordManagementTest.php)::test_recovery_is_uniform_queued_encrypted_broker_throttled_and_uses_only_the_trusted_url and test_reset_rejects_bad_expired_unknown_and_reused_tokens_revokes_sessions_and_never_logs_in.
- Gap / Frontend: Stage 12 code is implemented/tested; actual worker-to-mailbox delivery is unverified (V19-02). A 202 acknowledgement is not delivery. Production needs supported asynchronous queue, direct mail transport, HTTPS trusted frontend origin and database sessions.

## R45 — Only the authenticated owner can access personal data

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L366–368. Backend responsibility: authentication, scoped relations, association validation and private error/presentation boundaries.
- Evidence: [routes/api.php](../../routes/api.php) auth group; [User](../../app/Models/User.php) owned relations; [TaskPolicy](../../app/Policies/TaskPolicy.php), [LessonPolicy](../../app/Policies/LessonPolicy.php), [AiConversationPolicy](../../app/Policies/AiConversationPolicy.php), [StudyAvailabilityWindowPolicy](../../app/Policies/StudyAvailabilityWindowPolicy.php); domain Services repeat owner scoping. [bootstrap/app.php](../../bootstrap/app.php) sanitizes sensitive failures.
- REST/AI: all personal APIs and all 31 definitions; model-supplied owners/class/method dispatch cannot become authority.
- PHPUnit: [TaskSecurityTest](../../tests/Feature/TaskSecurityTest.php), [LessonSecurityTest](../../tests/Feature/LessonSecurityTest.php), [AcademicContextSecurityTest](../../tests/Feature/AcademicContextSecurityTest.php), [AiReadinessHardeningTest](../../tests/Feature/AiReadinessHardeningTest.php), cross-user domain/action tests.
- Gap / Frontend: no confirmed P0 ownership/confirmation defect found in inspected paths. G19-06 identifies absent login/registration throttling, without an observed ownership bypass. This is not a penetration-test certificate; browser, logging and deployment controls need independent verification.

## R46 — Profile, personal information and planning settings

**Implementation: IMPLEMENTED. Verification: VERIFIED.**

- Source: S2:L372–378. Backend responsibility: own profile and persisted timezone/preferences/weekly constraints.
- Evidence: [ProfileController](../../app/Http/Controllers/Api/ProfileController.php)::show/update; [UpdateProfileRequest](../../app/Http/Requests/UpdateProfileRequest.php); [PlanningPreferenceService](../../app/Services/Planning/PlanningPreferenceService.php)::replace; [StudyAvailabilityService](../../app/Services/Planning/StudyAvailabilityService.php); [PlanningPreferenceResource](../../app/Http/Resources/PlanningPreferenceResource.php).
- REST: `/profile` GET/PATCH; `/planning-preferences` GET/PUT; availability CRUD. AI: recurring-window proposals only, no profile/numeric-preference mutation Tool.
- PHPUnit: [AuthProfileTest](../../tests/Feature/AuthProfileTest.php), [PlanningPreferenceApiTest](../../tests/Feature/PlanningPreferenceApiTest.php), [StudyAvailabilityApiTest](../../tests/Feature/StudyAvailabilityApiTest.php).
- Gap / Frontend: changing timezone changes local interpretation and stales relevant reviews; it does not rewrite UTC events or replan sessions. Profile PATCH is not the password endpoint.

## R47 — Development ideas: team time, calendar export, AI import and deployment

**Implementation: PARTIALLY IMPLEMENTED. Verification: PARTIALLY VERIFIED.**

- Source: S3:L387–end, explicitly examples/development directions. Backend responsibility: any later approved integrations; CI/operations are shared responsibilities. This is not a mandatory composite acceptance gate.
- Evidence: [Backend verification workflow](../../.github/workflows/backend-verification.yml) implements dependency/platform, formatting, offline PHPUnit and migration checks; [routes/api.php](../../routes/api.php) and the six Tool registries contain no team availability/calendar export/AI Excel import operation. [ScheduleImportController](../../app/Http/Controllers/Api/ScheduleImportController.php) supports manual REST import.
- REST/AI: core manual import exists; optional team/export/import Tools are absent. Automated staging/container/promotion/rollback deployment is absent from the sole checked-in workflow.
- PHPUnit: [CiMigrationVerificationTest](../../tests/Unit/CiMigrationVerificationTest.php) guards disposable migrations; current CI success is observed. No tests implement the absent optional modules.
- Gap / Frontend: G19-04 records optional omissions. Stage 19A-FE must distinguish its own build/E2E/hosting evidence from Backend CI; do not classify shared or UI-only development ideas as Backend mandatory failures.

## R48 — Autonomous background relocation of unfinished work

**Implementation: NOT IMPLEMENTED. Verification: NOT VERIFIED.**

- Source: S3 automatic relocation wording, broader interpretation of S2:L116,246. Backend responsibility, if explicitly adopted: choose a safe trigger/policy and execute background replanning without an interactive request. Mandatory status is unresolved.
- Evidence of absence: [routes/console.php](../../routes/console.php) schedules only due reminders; [app/Jobs/SendPasswordResetLink.php](../../app/Jobs/SendPasswordResetLink.php) is the sole application Job; [TaskService](../../app/Services/Tasks/TaskService.php)::miss does not enqueue planning; recovery/replanning Services run only through explicit flows.
- REST/AI: interactive recovery/plan/replanning paths exist. No unattended relocation endpoint, scheduled action or registered execution Tool.
- PHPUnit: recovery/missed/replanning tests verify interactive behavior; no positive test verifies unattended relocation. Source absence is established, not an executed autonomous capability.
- Gap / Frontend: G19-03 is an optional P3 scope decision, not a newly declared mandatory failure. Do not describe user-confirmed recovery as background autonomy or infer that missed status silently moves sessions.
