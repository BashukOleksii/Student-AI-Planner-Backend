# Stage 19A-BE — Backend audit result

## Identity, scope and sources

Audited Backend: **`1912ff45be03d5eed3dfeabe5fa6a1a2f13958a4`**, `main`, inspected 2026-11-08. The working tree was initially clean. Recent main history includes Stage 18B (`1912ff4`), 18A (`9521448`), workload advice (`2aef224`), missed Task status (`e9a510d`) and operational readiness (`c438fc9`). This milestone changes only three documentation files; it does not implement the recommendations.

Requirements source Frontend commit: **`a3bfbde90f9d1a5d1e30a80155153f824f03b068`**, retrieved read-only from GitHub:

- [Stage10_Requirements_Source_Bundle.md](https://github.com/BashukOleksii/Student-AI-Planner-Frontend/blob/a3bfbde90f9d1a5d1e30a80155153f824f03b068/docs/requirements/Stage10_Requirements_Source_Bundle.md), source blob `09e73fb26b1a279216b68a8c5ea26c3510e72842`.
- [requirements-coverage.md](https://github.com/BashukOleksii/Student-AI-Planner-Frontend/blob/a3bfbde90f9d1a5d1e30a80155153f824f03b068/docs/stage10/requirements-coverage.md), original 48-group numbering and source-line references, source blob `c15279f6521659c0641accb6f1cbffd868052e08`. Its historical statuses are not copied as current findings.

S1/S2 define core intended behavior; S3 contains development directions. Optional ideas and ambiguous wording remain explicit decisions. The Backend [Stage 10 coverage](../stage10/requirements-coverage.md) is a scoped Stage 11 addendum, not the original complete matrix. No Frontend implementation or test files were intentionally audited or modified. Retrieving its main commit identity does not establish any Frontend capability.

## Method and coverage summary

Read AGENTS.md before editing; checked actual branch/status/full SHA/recent history and repository layout. Reviewed architecture, domain/business/database notes, [automatic planning](../automatic-study-planning.md), [adaptive replanning](../adaptive-replanning.md), [AI foundation](../ai-tools-foundation.md), ADR-001–005 and Stages 11–18 contracts/results. Traced current routes through Controllers, Requests, Services, Policies, Resources, Models, migrations and relevant tests. Current executable code governs every implementation claim; historical reports supply context and provenance.

The [requirements audit](backend-requirements-audit.md) has exactly one primary entry for each R01–R48, with original meaning/reference, Backend responsibility, source/method evidence, REST/AI mapping, PHPUnit evidence, separate statuses, remaining gap and Frontend guidance. Counts:

| Backend implementation assessment | Groups |
| --- | ---: |
| IMPLEMENTED | 44 |
| PARTIALLY IMPLEMENTED | 3 |
| NOT IMPLEMENTED | 1 |
| NOT BACKEND RESPONSIBILITY | 0 |
| UNDETERMINED | 0 |
| **Total** | **48** |

Partial implementation: **R28** (literal Task-pair conflict explanation), **R32** (cancellation exists; literal deletion absent), **R47** (optional development bundle, including implemented CI). **R48** unattended background relocation is absent and optional pending explicit scope adoption. Every group contains some Backend responsibility, so no group as a whole is classified outside Backend responsibility. Pure presentation and shared operational work are nevertheless identified separately.

| Verification assessment | Groups |
| --- | ---: |
| VERIFIED | 37 |
| PARTIALLY VERIFIED | 10 |
| NOT VERIFIED | 1 |
| BLOCKED | 0 |
| **Total** | **48** |

Partially verified groups: R17, R18, R24, R25, R28, R32, R33, R40, R44, R47. R48 has no positive execution verification. Verification covers the stated Backend contract and automated scope; no row is a claim of complete deployed-system acceptance. For example, R33/R44 have implemented and tested delivery code but lack live delivery evidence. No database check was attempted and blocked during this audit.

## Historical problem areas reassessed

| Groups | Current source-based finding | Remaining distinction / handoff |
| --- | --- | --- |
| R10 | Stage 16 persists Task `missed`, includes it in unfinished selection/completion/reopening and statistics; Stage 14 recovery computes remaining work with missed/past-plan diagnostics. | Marking missed changes no sessions. Recovery is an explicit preview/confirmation workflow; pending is the existing planned-work interpretation, not a new scheduled Task state. |
| R24 / R48 | Remaining-work plan creation recovers eligible effort; adaptive replanning replaces affected future planned reservations and retains valid/cannot-replan/history. | Interactive automatic placement is implemented. There is no background relocation trigger. Dedicated AI missed-work composition evidence and real-language selection are incomplete; autonomous execution is an optional product decision. |
| R21 | Stage 11 adds four confirmed Lesson/replacement proposals through LessonService. | Historical missing-adapter finding resolved for Backend. Catalog ID lookup, AI delete/import/bulk Tools are absent; review support and actual UI flow require Frontend inspection. |
| R22 | Stage 18A adds owned recurring-window discovery; Stage 18B adds confirmed create/update/delete using shared availability rules and writer. | Configured weekly windows differ from computed free slots. Changing availability does not reschedule sessions or mutate preferences. Frontend must preserve local clock/end-of-day semantics and stale handling. |
| R28 / R38 | Stage 17 exposes deterministic order, per-work recommendations, allocation shortfalls and separately measured daily/weekly persisted overload. | Generic workload-advice Tool and literal conflicting Task-pair identities are absent. On-demand warnings are implemented; global optimality and proactive/background alerts are not claimed. R28 remains partial under literal pair wording. |
| R32 / R33 | Cancellation retains Reminder/inbox history. Minute dispatch and atomic native database notifications implement in-app delivery. | No literal Reminder deletion route. Whether cancellation satisfies “delete” remains unresolved. Tested isolated dispatch does not establish a deployed scheduler or a received live notification. |
| R35 / R37 | Stage 13 supplies current overall Task completion percentage and sorted Subject scheduled-workload groups, also visible through the existing statistics Tool. | Historical missing-aggregate findings resolved. Percentage is current active population, not a period cohort; Subject is current assignment, not a historical snapshot. |
| R43 / R44 | Stage 12 adds password change, uniform queued recovery and Broker reset with session/token revocation and sanitized failures. | Historical missing-route findings resolved. Actual asynchronous mail delivery and current deployment prerequisites remain unverified. |

Historical Stage 15 HTTP/SMTP/worker failures are not replayed as current application defects: this audit did not probe today's retained runtime. Stages 11/18 reports' then-pending Frontend work is also historical and requires reassessment in the separate repository. Older seven/nine/27/28 Tool counts and pre-missed enum examples remain in historical paragraphs of general documentation; the current registries and this report establish today's count and vocabulary.

## Architecture and security assessment

### REST, domain and persistence boundaries

The Laravel modular monolith matches [ADR-001](../decisions/ADR-001-backend-architecture.md). Reviewed domain Controllers perform authorization/request orchestration and Resource presentation. Auth/profile use normal short Laravel authentication/model operations; they do not need artificial Service wrappers. Nontrivial interval rules, planning, import reconciliation, lifecycle transitions, reminder scheduling/delivery and metric calculations live in Services. No inspected calculation is delegated to LLM reasoning, and no architectural refactor is justified merely for style.

- **Authentication:** [AuthController](../../app/Http/Controllers/Api/AuthController.php) registers/logs in using the web guard, rotates sessions and logs out by invalidating the session/CSRF token. [bootstrap/app.php](../../bootstrap/app.php) enables Sanctum stateful middleware; [config/sanctum.php](../../config/sanctum.php) applies session/CSRF checks. This application issues no personal access tokens. Login/registration have no application attempt limiter in the inspected routes/Requests/Controller/middleware; G19-06 records that security hardening gap. Current deployment gateway/cookie/TLS settings were not read from `.env` or verified live.
- **Password security:** [PasswordManagementService](../../app/Services/Auth/PasswordManagementService.php) uses current-password verification under owner locking, Laravel Broker token hashing/expiry/single-use validation, session revocation and a trusted configured frontend origin. [SendPasswordResetLink](../../app/Jobs/SendPasswordResetLink.php) is encrypted queued work, with uniform request acknowledgement and fixed diagnostics. Unsupported production delivery/session configurations fail closed. Actual SMTP delivery remains a separate operational check.
- **Ownership:** personal routes use `auth:sanctum`; [User](../../app/Models/User.php) owns domain relations; Policies hide foreign resources and Services reselect owned records. Nested Subtasks use scoped route bindings and parent validation. Resources mask incompatible related metadata or Services fail closed; owner IDs supplied by clients/models do not establish access.
- **Schedule/import:** [LessonService](../../app/Services/Schedule/LessonService.php) owns concrete Lesson lifecycle, replacement graph and active-overlap rules. [ScheduleService](../../app/Services/Schedule/ScheduleService.php) selects effective timezone-aware views. Weekly/parity import rules materialize concrete UTC occurrences; XLSX parser, owned resolver, classifier, revalidator and [ScheduleImportCommitService](../../app/Services/Imports/ScheduleImportCommitService.php) enforce reviewed atomic reconciliation. A general recurring-series editor is not claimed.
- **Tasks/Subtasks:** [TaskService](../../app/Services/Tasks/TaskService.php), [SubtaskService](../../app/Services/Tasks/SubtaskService.php) and dependency/synchronization Services own writes and cleanup. Task pending/missed are unfinished; Subtasks retain pending/completed. Completing work does not infer session completion. Child count-based progress is distinct from Task status and overall completion percentage.
- **Planning:** [FreeTimeService](../../app/Services/Planning/FreeTimeService.php) subtracts effective Lessons/planned reservations from expanded availability. [PlanningWorkSelectionService](../../app/Services/Planning/PlanningWorkSelectionService.php), [RemainingEffortService](../../app/Services/Planning/RemainingEffortService.php), [PlanningContextService](../../app/Services/Planning/PlanningContextService.php), [StudySessionAllocationService](../../app/Services/Planning/StudySessionAllocationService.php) and pure constraints determine eligibility, actual-first credit, reservations, effective deadline/priority, breaks and daily/weekly limits. Greedy allocation is deterministic, not a global optimizer.
- **Plan/session writes:** [StudyPlanPersistenceService](../../app/Services/Planning/StudyPlanPersistenceService.php), [StudyReplanningPersistenceService](../../app/Services/Planning/StudyReplanningPersistenceService.php), [StudySessionLifecycleService](../../app/Services/Planning/StudySessionLifecycleService.php) and [StudySessionReschedulingService](../../app/Services/Planning/StudySessionReschedulingService.php) validate current state in owner-first transactions. Stateless encrypted direct-review tokens expire; confirmation regenerates/compares material output and validates projected batches. Historical source/successor identity and Reminder cleanup commit atomically. Direct REST replay semantics differ intentionally from durable AI receipt replay.
- **Availability:** [StudyAvailabilityInputRules](../../app/Services/Planning/StudyAvailabilityInputRules.php) shares exact weekday/clock rules between REST/AI/service preparation. [StudyAvailabilityService](../../app/Services/Planning/StudyAvailabilityService.php) is the single writer and interval/overlap validator; adjacency is allowed, overnight windows require explicit split, end `24:00:00` is retained. REST and AI serialize through the same owner row.
- **Reminders:** [ReminderService](../../app/Services/Reminders/ReminderService.php) owns public transaction boundaries; the shared [ReminderMutationService](../../app/Services/Reminders/ReminderMutationService.php) permits the same writer inside durable AI confirmation. [ReminderDeliveryService](../../app/Services/Reminders/ReminderDeliveryService.php) revalidates due targets, inserts one native database notification and transitions sent status atomically. [routes/console.php](../../routes/console.php) runs bounded dispatch each minute. This is local database delivery, without a reminder queue Job. Inbox read state is independent of work lifecycle.
- **Statistics/advice:** [ProgressStatisticsService](../../app/Services/Progress/ProgressStatisticsService.php), trends and comparisons use scoped nonlocking read snapshots and deterministic reducers. Current populations, period completions, start-attributed scheduled durations, known/unknown actual effort and current Subject attribution remain explicit. [WorkloadAdviceService](../../app/Services/Planning/WorkloadAdviceService.php) reuses the planner; it does not mutate priorities, settings or sessions.

MySQL constraints enforce scalar intervals/statuses, FKs, uniqueness and action receipt/resolution consistency. Services enforce cross-row ownership/overlap/history rules where SQL CHECKs cannot. [Stage 18B migration](../../database/migrations/2026_11_08_000002_add_ai_availability_actions.php) replaces only `ai_actions_valid_type`, preserving 18 old names and adding three; all 21 fit VARCHAR(32). Its `down()` refuses before changing history. [AiAvailabilityActionMigrationTest](../../tests/Feature/AiAvailabilityActionMigrationTest.php) verifies legacy records/receipts, unrelated metadata/constraints, invalid names and refusal. This audit ran no migration or retained-schema inspection.

### AI authority, confirmation and concurrency

[AgentOrchestrator](../../app/Services/AI/AgentOrchestrator.php)::run assembles server-owned schemas, validates call shape/IDs/limits and uses explicit branches. [ToolExecutor](../../app/Services/AI/Tools/ToolExecutor.php) plus [ReadOnlyTools](../../app/Services/AI/Tools/ReadOnlyTools.php) and [ManagementReadTools](../../app/Services/AI/Tools/ManagementReadTools.php) expose fixed reads. No model field selects a PHP class/method, container binding, SQL, authenticated owner or confirmation endpoint.

[ToolArgumentValidator](../../app/Services/AI/Tools/ToolArgumentValidator.php) and domain action validators reject unknown arguments and malformed strict types. Shared Task/Subtask/Lesson/availability/Reminder rules remain authoritative. Proposal preparation performs no domain writes and creates no pending record itself. [AiConversationService](../../app/Services/AI/AiConversationService.php)::finish persists a proposal only with a successful originating turn/transcript/idempotent result. Global one-mutation-attempt-per-turn applies even to invalid attempts and mixed domains. Multiple reads remain allowed.

[TaskActionConfirmationService](../../app/Services/AI/Actions/TaskActionConfirmationService.php)::decide reuses the owned conversation/action lifecycle and explicit authenticated REST decisions. Conversation → pending action → owner locks precede domain reads/locks, so no stale REPEATABLE READ snapshot precedes the owner wait. Cooperating domain writers then lock relevant records in deterministic domain order; availability reloads the complete owned weekly set by ID. No provider call runs inside confirmation transactions. Arbitrary external SQL writers are outside this serialization contract.

- Conversational “yes”, provider prose, tool results and prompt injection cannot authorize execution. The provider is not given a confirmation execution Tool. Proposal turns use server-owned public wording rather than claiming a completed write.
- Active-turn leases exclude decisions; completed-turn provenance, conversation ownership, genuine consent fields, MySQL-clock expiry and target state are checked. Pending lifespan is normally 15 minutes; planning actions are capped by their original private domain-token expiry.
- [ActionTargetFingerprint](../../app/Services/AI/Actions/ActionTargetFingerprint.php) hashes canonical actual persisted values. Domain adapters reselect current owned state, check fingerprints/material reviews and repeat domain validation. Availability fingerprints include owner identity/timezone and all owned windows: even an unrelated weekly edit intentionally stales a review. Foreign windows and unrelated profile name/email do not.
- Confirmed execution invokes existing deterministic writers. Domain persistence, executed state, immutable saved receipt and conversation history share one commit. Expiry after a wait/write causes rollback. Rejected/expired actions do not execute. Expected planning failures use a same-connection savepoint before durable failure history; unexpected write/receipt/history failures roll back the outer transaction.
- Reconfirming an executed action returns its stored receipt with no new write/provider request; it is not reconstructed from current records. [AvailabilityActionService](../../app/Services/AI/Actions/AvailabilityActionService.php)::execute independently rereads saved windows or verifies deletion before success. Historical receipts survive later edits/deletions. Domain/action tests verify rollback and replay across other domains.
- [AiPendingActionResource](../../app/Http/Resources/AiPendingActionResource.php), [AiMessageResource](../../app/Http/Resources/AiMessageResource.php) and scoped exception handling omit private arguments/hashes/encrypted planning tokens/provider state/SQL as appropriate. Native provider continuation is private transport state, not public authoritative mutation history.

Source and passing tests support these boundaries. **No ownership/confirmation bypass or P0 data-integrity defect was confirmed.** G19-06 identifies the concrete missing application login/registration rate limiter; it is a priority security follow-up, with gateway protections unverified. This is a bounded code/test audit, not live adversarial or infrastructure certification.

## Exact AI Tool inventory

Registry execution without Laravel bootstrap/database access yielded **31 unique definitions: 10 reads + 21 proposals**. The entire preceding 28-definition sequence equals `tests/Fixtures/AI/tool-definitions-before-stage18b.json`; all definitions reject additional properties. Both provider schema adapters accepted the definitions. [AvailabilityActionTest](../../tests/Unit/AvailabilityActionTest.php) asserts the same compatibility and exact new contracts.

| Registry method | Reads | Proposals |
| --- | ---: | ---: |
| [ToolRegistry](../../app/Services/AI/Tools/ToolRegistry.php)::definitions | 8 | 0 |
| [TaskActionRegistry](../../app/Services/AI/Actions/TaskActionRegistry.php)::definitions | 0 | 4 |
| [SubtaskReminderActionRegistry](../../app/Services/AI/Actions/SubtaskReminderActionRegistry.php)::readDefinitions / definitions | 2 | 7 |
| [PlanningActionRegistry](../../app/Services/AI/Actions/PlanningActionRegistry.php)::definitions | 0 | 3 |
| [LessonActionRegistry](../../app/Services/AI/Actions/LessonActionRegistry.php)::definitions | 0 | 4 |
| [AvailabilityActionRegistry](../../app/Services/AI/Actions/AvailabilityActionRegistry.php)::definitions | 0 | 3 |
| **Total** | **10** | **21** |

Provider discovery order is the eight registry reads, four Task proposals, two management reads, seven management proposals, three planning, four Lesson, then three availability proposals. Tables below group reads/proposals for review; they do not redefine discovery order.

### Read-only Tools

Every row below uses **ToolArgumentValidator**. The first eight run through ToolExecutor/ReadOnlyTools; the two management reads use ManagementReadTools. **Confirmation: none; domain mutation: none.** Trusted Laravel owner is authoritative; no owner argument is accepted. Domain methods and their presentation checks retain ownership and safe reference behavior.

| Tool | Deterministic delegation | Ownership boundary / limitation | PHPUnit evidence |
| --- | --- | --- | --- |
| `get_schedule_for_date` | ScheduleService::date | Owned active effective Lessons; one local date; related foreign metadata masked. | AiToolFoundationTest, AiToolsIntegrationTest, ScheduleViewTest |
| `list_tasks` | TaskService::paginate | Owned active Tasks; AND filters; default 15/max 50; deadline/null/ID order. No deadline-bound Tool arguments. | AiToolFoundationTest, AiToolsIntegrationTest, TaskIndexTest |
| `list_study_availability_windows` | ReadOnlyTools::availabilityWindows → User::studyAvailabilityWindows; StudyAvailabilityWindowResource | Owned weekly windows; optional ISO weekday; default 20/max 50; weekday/start/ID order, total/page metadata. Exact local end-of-day retained. | AiAvailabilityToolSchemaTest, AiStudyAvailabilityToolTest |
| `find_free_study_slots` | FreeTimeService::calculate | Owned availability/effective Lessons/planned sessions; at most 31 local dates. Geometry does not enforce full planning feasibility. | AiToolFoundationTest, AiToolsIntegrationTest, FreeSlotsApiTest |
| `list_study_sessions` | StudySessionReadService::range | Owned validated history; at most 31 dates; 100 displayed with explicit total/truncated metadata. Complete source selection is loaded before cap. | AiToolsIntegrationTest, StudySessionApiTest |
| `get_progress_statistics` | ProgressStatisticsService::calculate | Owned current work and bounded activity; current completion percentage/Subject groups; unknown actual preserved. | AiToolsIntegrationTest, ProgressStatisticsApiTest, ProgressStatisticsIntegrationTest |
| `get_daily_progress_trends` | ProgressTrendsService::calculate | Owned 1–31 local-date zero-filled buckets; no historical population snapshots. | AiToolsIntegrationTest, ProgressTrendsApiTest, ProgressTrendsIntegrationTest |
| `compare_progress_periods` | ProgressComparisonService::calculate | Owned equal-length ordered nonoverlapping periods, each ≤31 dates; factual deltas, no productivity inference. | AiToolsIntegrationTest, ProgressComparisonApiTest, ProgressComparisonIntegrationTest |
| `list_subtasks_for_task` | SubtaskService::all → ManagementActionPresenter | Exact owned parent; ordered children; max 100 displayed with complete/truncated metadata. No pagination to later children. | SubtaskReminderActionTest, AiSubtaskReminderActionsTest |
| `list_reminders` | ReminderService::listing → ManagementActionPresenter | Owned stored history, default 20/max 50, status filter; no recalculation, delivery or inbox write. | SubtaskReminderActionTest, AiSubtaskReminderActionsTest, ReminderApiTest |

Date/output limits are contract bounds; the orchestrator additionally rejects oversized serialized results/context. Some existing reads load full matching sources before presentation limits, so output limits are not universal query-memory limits. Separate Tool calls do not share a global snapshot. Unknown/truncated/failed results must not become invented IDs or facts.

### Mutation proposal Tools

Every row below is **proposal-only** and requires **explicit authenticated REST confirmation** through the same decision Service/endpoints. Preparation never writes domain data; successful turn finalization can persist one pending action. Common owner/lease/expiry/stale/replay/receipt protections above apply to every row. REST direct CRUD permissions do not allow the LLM to skip this path.

Validation components:

- **TV:** [TaskActionArgumentValidator](../../app/Services/AI/Actions/TaskActionArgumentValidator.php) with TaskInputRules and TaskService preparation.
- **MV:** [SubtaskReminderArgumentValidator](../../app/Services/AI/Actions/SubtaskReminderArgumentValidator.php) with SubtaskInputRules / ReminderRules and shared preparation.
- **PV:** [PlanningActionArgumentValidator](../../app/Services/AI/Actions/PlanningActionArgumentValidator.php) with PlanningContextService date bounds and lifecycle/range checks; confirmation-only [PlanningActionConsent](../../app/Services/AI/Actions/PlanningActionConsent.php).
- **LV:** [LessonActionArgumentValidator](../../app/Services/AI/Actions/LessonActionArgumentValidator.php) with LessonInputRules and LessonService preflight.
- **AV:** [AvailabilityActionArgumentValidator](../../app/Services/AI/Actions/AvailabilityActionArgumentValidator.php) with StudyAvailabilityInputRules and StudyAvailabilityService::prepare.

| Tool | Validator; proposal adapter → confirmed writer | Owned boundary / principal limitation | PHPUnit evidence |
| --- | --- | --- | --- |
| `propose_task_create` | TV; TaskActionProposalService → TaskService::create | One owned Task, validated owned optional Subject. No ID exists before saving. | TaskActionTest, AiTaskActionsTest, AiTaskActionConcurrencyTest |
| `propose_task_update` | TV; TaskActionProposalService → TaskService::update | Exact owned Task fingerprint; ≥1 field, omission preserves, supported null clears. Parent/child deadlines rechecked. | TaskActionTest, AiTaskActionsTest |
| `propose_task_complete` | TV; TaskActionProposalService → TaskService::complete | Owned pending/missed Task; dependent scheduled Reminder cleanup; children/sessions keep status. | AiTaskActionsTest, TaskMissedStatusTest |
| `propose_task_reopen` | TV; TaskActionProposalService → TaskService::reopen | Owned completed/missed Task to pending; no reminder/session resurrection. | AiTaskActionsTest, AiTaskActionConcurrencyTest |
| `propose_subtask_create` | MV; SubtaskReminderActionService → SubtaskService::create | Exact owned parent; position assigned on execution, deadline bounded by parent. | SubtaskReminderActionTest, AiSubtaskReminderActionsTest |
| `propose_subtask_update` | MV; SubtaskReminderActionService → SubtaskService::update | Owned parent and nested child; ≥1 editable field; full candidate validation. | AiSubtaskReminderActionsTest, AiManagementActionConcurrencyTest |
| `propose_subtask_complete` | MV; SubtaskReminderActionService → SubtaskService::complete | Exact owned pending child; parent/session status retained, dependent Reminder cleanup. | AiSubtaskReminderActionsTest |
| `propose_subtask_reopen` | MV; SubtaskReminderActionService → SubtaskService::reopen | Exact owned completed child; no cancelled Reminder recreation. | AiSubtaskReminderActionsTest |
| `propose_reminder_create` | MV; SubtaskReminderActionService → ReminderMutationService::create | Owned eligible target or meaningful general message; absolute/relative modes, resolved future trigger; scheduling only. | AiSubtaskReminderActionsTest, RelativeReminderApiTest |
| `propose_reminder_update` | MV; SubtaskReminderActionService → ReminderMutationService::update | Exact owned scheduled Reminder; same target/mode; supported message/time/offset edits only. | AiSubtaskReminderActionsTest, AiManagementActionConcurrencyTest |
| `propose_reminder_cancel` | MV; SubtaskReminderActionService → ReminderMutationService::cancel | Exact owned scheduled Reminder; retains row/inbox history; no literal deletion. | AiSubtaskReminderActionsTest, AiManagementActionConcurrencyTest |
| `propose_study_plan_create` | PV; PlanningActionService → StudyPlanPersistenceService::confirmWithinTransaction | Owned selected work and server-generated encrypted review token; 1–31 dates; client-only partial consent. | PlanningActionTest, AiPlanningActionsTest, AiPlanningActionConcurrencyTest |
| `propose_adaptive_replanning` | PV; PlanningActionService → StudyReplanningPersistenceService::confirmWithinTransaction | Owned affected future sources, valid/cannot-replan protected; separate partial/unplaced cancellation consent. | AiPlanningActionsTest, AiPlanningActionConcurrencyTest, StudyReplanningConfirmationTest |
| `propose_session_reschedule` | PV; PlanningActionService → StudySessionReschedulingService::rescheduleWithinTransaction | Exact owned future planned source/history fingerprint; explicit interval; preview preliminary, confirmation checks full feasibility. | AiPlanningActionsTest, StudySessionLifecycleTest |
| `propose_lesson_create` | LV; LessonActionService → LessonService::create | One owned manual Lesson; explicit owned Subject/type/UTC interval, optional owned associations; no catalog name lookup. | LessonActionTest, AiLessonActionsTest |
| `propose_lesson_update` | LV; LessonActionService → LessonService::update | Owned target/associations/graph fingerprint; partial fields; import identity/links uneditable. | AiLessonActionsTest, AiLessonActionConcurrencyTest |
| `propose_lesson_cancel` | LV; LessonActionService → LessonService::cancel | Owned active target; retain history; cancelling replacement does not restore original. | AiLessonActionsTest, AiLessonActionConcurrencyTest |
| `propose_lesson_replacement` | LV; LessonActionService → LessonService::createReplacement | Owned active original; complete explicit fields; graph/reference checks, original retired only on confirm. | AiLessonActionsTest, AiLessonActionMigrationTest |
| `propose_availability_create` | AV; AvailabilityActionService → StudyAvailabilityService::create | Owned full weekly configuration/timezone fingerprint; exact weekday/local clocks; adjacent valid, overlap rejected; no new ID before save. | AvailabilityActionTest, AiAvailabilityActionsTest, AiAvailabilityActionConcurrencyTest |
| `propose_availability_update` | AV; AvailabilityActionService → StudyAvailabilityService::update | Exact owned window plus full weekly fingerprint; ≥1 editable field, no null, omitted fields retained; complete candidate checked again. | AiAvailabilityActionsTest, AiAvailabilityActionConcurrencyTest |
| `propose_availability_delete` | AV; AvailabilityActionService → StudyAvailabilityService::delete | One exact owned configuration record; reviewed before fields retained; verify absence, no sessions/work/reminders/preferences changed. | AiAvailabilityActionsTest, AiAvailabilityActionMigrationTest |

Test names in these tables resolve under `tests/Unit/` or `tests/Feature/`; links to the corresponding domain suites appear in the [requirements audit](backend-requirements-audit.md). All are included by baseline `phpunit.xml`, apart from separately opt-in Live tests. No paid provider execution is represented by this inventory.

[OpenAiToolSchema](../../app/Services/AI/OpenAiToolSchema.php) preserves read-schema optional omission through wire null normalization; proposal schemas use existing `strict: false` to retain omission versus explicit null. Server validation remains strict. [GeminiToolSchema](../../app/Services/AI/GeminiToolSchema.php) emits parametersJsonSchema and fails closed on unsupported vocabulary. Provider independence is implemented and offline-tested; these are not live provider acceptance results.

## PHPUnit, migration, concurrency and CI evidence

### Exact-baseline hosted evidence

Latest accessible main run at inspection: [Backend verification #15](https://github.com/BashukOleksii/Student-AI-Planner-Backend/actions/runs/42501369813), run **42501369813**, job **128794637938**, event `push`, attempt 1, head **`1912ff45be03d5eed3dfeabe5fa6a1a2f13958a4`**. Created 2026-11-08 **16:43:52 UTC**, completed by **16:53:13 UTC**; run/job and all listed verification steps conclude **success**. Jobs and decoded job logs were fetched read-only. Stage 18A run [42497103904](https://github.com/BashukOleksii/Student-AI-Planner-Backend/actions/runs/42497103904) also concludes success; its old unknown-CI limitation is resolved as historical evidence.

Hosted [.github/workflows/backend-verification.yml](../../.github/workflows/backend-verification.yml) provisions an ephemeral MySQL 8.4 service/database `student_planner_testing`, PHP 8.4 (observed 8.4.27), blank provider keys and both paid/live flags disabled. The initialization of `.env` in that disposable runner is workflow evidence, not a local edit made during this audit.

| Hosted command / step | Observed outcome at audited SHA |
| --- | --- |
| `composer validate --strict --no-check-publish`, `composer install --no-interaction --prefer-dist --no-progress`, `composer check-platform-reqs` | Dependency/platform step success. |
| `php .github/scripts/verify-ci-migrations.php` | Disposable MySQL migration/instrumentation step success. Guard requires real hosted service identity, approved environment and empty disposable schema; it was not invoked locally. |
| `vendor/bin/pint --test` and `git diff --check` | Formatting step success; Pint reports **464 files**. |
| `vendor/bin/phpunit --log-junit phpunit-full.xml` | **6,214 tests, 60,976 assertions**, **07:13.438**, zero reported failures/errors; step success. Log says “OK, but there were issues”: **1 PHPUnit deprecation and 320 PHPUnit notices**. Do not summarize this as diagnostics-free. |
| `AI_TEST_PROVIDER=gemini vendor/bin/phpunit tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php --log-junit phpunit-gemini-actions.xml` | **250 tests, 3,456 assertions**, **00:50.023**, `OK`; step success. Native fake fixtures; not paid requests. This explicit hosted matrix omits the availability class. |
| Upload `backend-phpunit-results` | Step success, workflow requests both JUnit files with seven-day retention. Artifact contents were not downloaded/recounted in this audit; counts above come directly from job logs. |

Full-suite discovery includes both [Unit and Feature suites](../../phpunit.xml), the new availability read/actions, migration upgrades/refusals and independent-process concurrency classes. Dedicated MySQL evidence includes plan/replanning/session/Reminder/AI concurrency tests, not merely in-memory mocks: their workers use guarded schemas and performance_schema lock waits. [AiAvailabilityActionConcurrencyTest](../../tests/Feature/AiAvailabilityActionConcurrencyTest.php) covers overlapping proposals, duplicate confirmation, REST owner-lock serialization and expiry rollback. Migration tests preserve legacy action receipts and unrelated CHECKs. This is executable CI evidence, not a claim that retained deployment schema was inspected.

Default native action fixtures choose OpenAI unless `AI_TEST_PROVIDER=gemini` is explicitly set by the helper. The workflow's `AI_PROVIDER=gemini` environment does not make every default action fixture a Gemini case. The broader local Stage 18B matrix is historical evidence in [its result](../stage18/backend-stage18b-result.md); it is not substituted for current hosted coverage.

### Checks run in this audit

No local MySQL connection, database test, migration, retained-data read, dispatch, queue consumption, SMTP or paid provider call was run. Local database-mutating checks are **NOT RUN**: no new disposable database was positively provisioned/verified for Stage 19, and exact-baseline hosted isolation evidence was already available. No safety guard was bypassed or spoofed.

The Unit directory was inspected first: all classes extend PHPUnit Framework TestCase rather than the Laravel database TestCase; database Services/providers are mocked or pure. Configuration tests use their own temporary cache path; migration-guard negative tests fail before bootstrap; live-smoke guard tests explicitly disable both opt-in flags before database bootstrap. Running this existing Unit suite does not access retained student records.

Commands ran in Bash at the audited source baseline. Private local logs/scripts live under ignored `.codex/stage19/`, outside the three committed documents.

| Local command | Exit / exact observation |
| --- | --- |
| `git status --short`, `git branch --show-current`, `git rev-parse HEAD`, recent commit inspection | 0; initially clean, main, full baseline SHA recorded above. |
| `vendor/bin/phpunit --testsuite=Unit --no-progress --display-phpunit-deprecations --display-phpunit-notices` (output redirected to `.codex/stage19/phpunit-unit.log`) | **0**, observed runner summary: **2,589 passed, 8,028 assertions, 6,152 ms**. This is a local database-free Unit result, not a local Feature-suite run. |
| Registry extraction via PHP autoload only | **0**; **10 reads / 21 proposals / 31 unique**; preceding **28 exact contracts preserved**; additionalProperties false and both schema adapters accepted all definitions. Reproduction below. |
| `git diff --check` and `git diff --cached --check` | **0**, no whitespace errors; the staged check covers all three newly added documents. |
| Documentation validation below | **0**; 48 unique requirement entries, separate status counts, existing local evidence links, exact Tool inventory verified. Separate static checks passed for 193 linked method references, referenced test classes, six gap IDs, seven verification limits and complete per-requirement field sets. Final staging checks confirm docs-only scope. |

No existing dedicated Markdown linter/documentation test command was found in Composer scripts or the sole workflow. The audit therefore uses whitespace, local-link/source existence, requirement/status/inventory consistency and scope checks. Repository-wide Pint at the exact source SHA is already verified by hosted CI; Markdown-only changes do not require rewriting PHP formatting.

### Diagnostics and verification limitations

No new local check failed. Deliberately unrun operational/database checks are recorded rather than fabricated:

| Diagnostic | Component / severity | Expected versus observed, cause | Correction / safe reverification |
| --- | --- | --- | --- |
| D19-01 | Local database/operational verification, informational | No local Feature suite/migration command was invoked; exit **N/A (NOT RUN)**. A newly positively verified disposable schema would be required. Existing retained databases were deliberately not inspected or reused. | Use hosted exact-baseline MySQL results above. Any later local run must provision and positively verify a fresh disposable schema under Tests\Support\TestDatabase and Tests\TestCase guards first. No local DB result is claimed. |
| D19-02 | Hosted PHPUnit diagnostics, P2 quality | Full command above completed successfully but reported 1 deprecation/320 notices. Detailed warning causes are **unconfirmed** from the retrieved summary, so no application bug is assigned. Assertions were not removed or weakened. | A follow-up can rerun with detailed PHPUnit diagnostics on disposable CI MySQL and address evidenced causes; this audit made no correction. Local Unit run passed, but does not diagnose Feature-suite notices. |
| D19-03 | Hosted native Gemini parity, P2 coverage | Expected full new-domain parity; actual explicit matrix lists four classes and omits AiAvailabilityActionsTest. Confirmed cause is the unchanged command in the workflow, not a failing availability implementation. Existing hosted command succeeds; missing case has no execution/exit code. | V19-05 recommends extending that existing offline command in a later task. Stage 18B historical broader local Gemini run is available; no new hosted availability/Gemini result is invented. |
| D19-04 | Live delivery, provider and browser evidence, P2 verification | Expected complete operational acceptance would need isolated accounts, scheduler/mail/worker/browser and separately authorized provider evaluation. Actual commands were **NOT RUN**, exit **N/A**; this audit is source/offline evidence only. | V19-01–04/V19-06 define safe authorized future checks. Historical Stage 15 failures remain historical; no current application/runtime root cause can be inferred. |

GitHub read calls succeeded for both sources, run metadata, jobs and logs. No inaccessible-source fallback, guessed requirement grouping or paid-model result was used. GitHub runner action-runtime deprecation warnings are infrastructure maintenance signals, not evidence of an application security failure.

## Confirmed gaps, priorities and next Backend work

The [gap register](backend-functional-gaps.md) contains evidence, affected components, dependencies, security considerations, acceptance criteria and required tests for each item:

| Finding | Requirement | Priority / decision |
| --- | --- | --- |
| G19-01 literal Task-pair conflict explanation absent | R28 | P2; clarify required semantics before adding a read explanation contract. |
| G19-02 literal Reminder deletion absent; cancellation is implemented | R32 | P3 pending agreement on removal/history semantics. Do not invent a hard-delete obligation. |
| G19-03 autonomous background relocation absent | R48; wider R10/R24 interpretation | P3 optional product/consent decision. |
| G19-04 optional team/calendar/AI import/deployment bundle incomplete | R47 | P3 future scope; core REST import and offline CI exist. |
| G19-05 AI academic catalog lookup absent | R21 and natural-name creation usability | P2 integration limitation; exact-ID confirmed Lesson operations already work. |
| G19-06 login/registration application rate limiter absent | R41/R42/R45 | P2 security hardening; address before optional functionality. Gateway protection is unverified. |

No confirmed P0 or unambiguously mandatory P1 omission is assigned. V19-01–07 separately track live Reminder delivery, password email, real natural-language performance, Frontend/browser acceptance, CI diagnostics/Gemini availability parity, deployment prerequisites and a focused missed-work AI composition test.

**Recommended next Backend implementation task:** address G19-06 with shared, tested Laravel login/registration rate limiting while preserving session/CSRF and uniform credential responses. Then close the small offline CI/test gaps V19-05/V19-07: add AiAvailabilityActionsTest to the existing Gemini matrix, add an existing-framework missed-Task planning/confirmation fixture, and diagnose the reported PHPUnit notices/deprecation. Preserve all guards and assertions. Operational isolated delivery verification should follow; optional deletion/autonomy/integration features require their own scope decisions. No such fix is part of this documentation commit.

## Stage 19A-FE handoff

Read these exact Backend repository paths at the resulting documentation commit (full commit SHA is reported with delivery):

1. `docs/stage19/backend-requirements-audit.md` — all 48 Backend-only assessments and source references.
2. `docs/stage19/backend-functional-gaps.md` — confirmed gaps, scope decisions and separate verification limits.
3. `docs/stage19/backend-stage19a-result.md` — this source/CI/architecture/31-Tool evidence and handoff.

These are the published repository paths in `BashukOleksii/Student-AI-Planner-Backend`; pin the documentation commit when consuming them. Do not use the Backend Stage 10 addendum as the complete requirements matrix or treat historical Frontend limitations in Stage 11/18 reports as current UI evidence.

Then inspect the separate Vue repository at its actual current SHA and combine evidence, without assigning system-wide status from this Backend audit alone:

- **R10/R24/R48:** missed Task controls, remaining-work recovery versus future-source replanning, explicit confirmation, unchanged historical sessions and honest autonomy wording. Use [Stage 14 recovery contract](../stage14/recovery-api-contract.md) and [Stage 16 status contract](../stage16/task-status-api-contract.md).
- **R21/R22:** supported Lesson/availability review types, exact owned IDs, before/after/timezone/effects, ambiguity and stale/expired states, unknown-type confirmation block, replayed receipts and post-execution refresh. Use [Stage 11 REST contract](../stage11/frontend-rest-contract.md), [Stage 18 read](../stage18/availability-read-tool.md) and [action contract](../stage18/availability-action-contract.md). Availability end `24:00:00` must survive rendering; no automatic replanning or client-supplied mutation arguments.
- **R28/R38:** preserve rank and provenance of advice, show exclusions separately, avoid adding alert seconds or claiming global impossibility. Use [Stage 17 contract](../stage17/workload-advice-api-contract.md). A dedicated workload-advice AI Tool is not present.
- **R32/R33:** accurate cancellation label/history, active Reminder filters, inbox/read experience and separately observed isolated scheduler delivery. Do not equate creation, due dispatch tests or a cancel button with live receipt or hard deletion.
- **R35/R37/R39/R40:** current versus period semantics, null percentage/unknown actual, Subject grouping, scheduled versus actual durations and bounded date ranges. Use [Stage 13 contract](../stage13/statistics-api-contract.md) and current Resources.
- **R43/R44:** current-password form, forgot/reset pages, trusted URL parameters, generic acknowledgement, errors, revoked-session handling and explicit new login after reset. Use [Stage 12 password contract](../stage12/password-api-contract.md); do not log reset URLs/tokens in analytics.
- **R41/R42/R45/R46:** real credentialed cookie/CSRF/CORS behavior, session recovery, logout and user switching; Backend simulated-browser tests do not replace deployed browser evidence.
- **R47:** inspect Frontend build/E2E/hosting separately. Keep S3 integrations optional until explicitly adopted.

Backend responses, states and receipts remain authoritative; do not duplicate planning/overlap/percentage rules in Vue or authorize mutations through chat prose. Agree interpretations of conflict pairs, Reminder removal and unattended relocation before a final system-wide requirement verdict.

## Reproducible documentation and inventory checks

Run from Backend root; these commands load registry definitions or inspect files only. They do not bootstrap Laravel or connect to MySQL:

```bash
php <<'PHP'
<?php
require 'vendor/autoload.php';
use App\Services\AI\Tools\ToolRegistry;
use App\Services\AI\Actions\{TaskActionRegistry, SubtaskReminderActionRegistry, PlanningActionRegistry, LessonActionRegistry, AvailabilityActionRegistry};
$m = new SubtaskReminderActionRegistry;
$reads = [...(new ToolRegistry)->definitions(), ...$m->readDefinitions()];
$before = [...(new ToolRegistry)->definitions(), ...(new TaskActionRegistry)->definitions(), ...$m->readDefinitions(), ...$m->definitions(), ...(new PlanningActionRegistry)->definitions(), ...(new LessonActionRegistry)->definitions()];
$all = [...$before, ...(new AvailabilityActionRegistry)->definitions()];
$check = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$check(count($reads) === 10 && count($all) === 31 && count(array_unique(array_column($all, 'name'))) === 31, 'Tool counts');
$check($before === json_decode(file_get_contents('tests/Fixtures/AI/tool-definitions-before-stage18b.json'), true, flags: JSON_THROW_ON_ERROR), 'Previous contracts');
foreach ($all as $d) { $check($d['parameters']['additionalProperties'] === false, 'Unknown fields'); }
(new App\Services\AI\GeminiToolSchema)->definitions($all);
(new App\Services\AI\OpenAiToolSchema)->definitions($all);
$audit = file_get_contents('docs/stage19/backend-requirements-audit.md');
preg_match_all('/\bR[0-9]{2}\b/', $audit, $ids);
$expected = array_map(fn ($i) => sprintf('R%02d', $i), range(1, 48));
$check($ids[0] === $expected, '48 requirements exactly once');
preg_match_all('/\*\*Implementation: (.+?)\. Verification: (.+?)\.\*\*/', $audit, $statuses, PREG_SET_ORDER);
$check(count($statuses) === 48, 'Separate statuses');
$impl = $verify = [];
foreach ($statuses as $row) { $impl[$row[1]] = ($impl[$row[1]] ?? 0) + 1; $verify[$row[2]] = ($verify[$row[2]] ?? 0) + 1; }
$check($impl === ['IMPLEMENTED' => 44, 'PARTIALLY IMPLEMENTED' => 3, 'NOT IMPLEMENTED' => 1], 'Implementation summary');
$check($verify === ['VERIFIED' => 37, 'PARTIALLY VERIFIED' => 10, 'NOT VERIFIED' => 1], 'Verification summary');
foreach (glob('docs/stage19/*.md') as $path) {
    preg_match_all('/\]\(([^)]+)\)/', file_get_contents($path), $links);
    foreach ($links[1] as $target) {
        if (preg_match('~^https?://~', $target)) { continue; }
        $target = explode('#', $target)[0];
        $check($target === '' || file_exists(dirname($path).'/'.$target), 'Missing local evidence: '.$target);
    }
}
$report = file_get_contents('docs/stage19/backend-stage19a-result.md');
foreach (array_column($all, 'name') as $name) { $check(str_contains($report, '| `'.$name.'` |'), 'Missing inventory row: '.$name); }
echo "PASS: 48 requirements; separate status counts; evidence links; 10 reads / 21 proposals / 31 Tools; 28 previous contracts.\n";
PHP
git diff --check
git status --short
git diff --name-only
```

Scope verification must show only the three Stage 19 Markdown files; new files may be listed by `git status` before staging. No application source, migration, route, test, `.env`, Frontend file or retained database was changed. Full Unit/Feature runtime evidence refers to the audited application SHA above; this documentation commit does not claim a new live execution result.
