# Business Rules

Status: baseline synchronized with the approved Stage 02 design\
Rule IDs are stable references for implementation and tests. Physical persistence details are recorded in [database-schema.md](database-schema.md); Migration Groups 1–4 implement `users.timezone`, typed planning preferences, recurring availability windows, education institutions, academic periods, subjects, teachers, schedule import batches/rows, their current Eloquent relationships, import-status PHP backed enums, MySQL constraints, factories, and persistence tests. Groups 5–9 persistence remains design-only, with Lessons next after resolving the documented implementation issues. Academic/planning/import API and business capabilities are not yet implemented: persisted import staging does not provide Excel parsing, normalization/fingerprint generation, preview/validation/commit Services, duplicate resolution, or Lesson creation. Same-owner compatibility for optional institution references and import-period references (BR-IMP-005) remains an application-level invariant; its validation Service is not yet implemented.

These are intended requirements, not a claim that the backend implements all of them. References to "V1" mean the initial product release, not an implemented `/api/v1` route prefix. Deterministic rules must be shared by REST and AI-tool entry points through application Services. Preserve rule IDs when refining requirements so implementation and tests can trace them.

## 1. Global and ownership rules

**BR-GEN-001 — Ownership isolation**  
A user may read or mutate only resources that belong to that user, directly or through an owned parent. Education institutions, subjects, and teachers are user-owned in V1, not shared global catalogs. Optional institution references must resolve to the same owner.

**BR-GEN-002 — Trusted user context**  
Public API requests and AI tool arguments must not be able to choose another user's ID. The authenticated user is obtained from trusted server context.

**BR-GEN-003 — Deterministic business logic**  
Deadline checks, free-time calculation, conflicts, priority ordering, workload limits, progress metrics, and schedule import validation are implemented in PHP Services, not delegated to the LLM.

**BR-GEN-004 — Timezone awareness**  
All user-facing date concepts are evaluated in the user's IANA timezone persisted in `users.timezone`. Concrete domain instants use UTC DATETIME values; recurring local availability uses TIME values. Server timezone must not change planning results.

**BR-GEN-005 — Valid intervals**  
For every time interval, start must be earlier than end.

**BR-GEN-006 — Atomic multi-record operations**  
An operation that would leave an invalid partial state if interrupted must execute atomically where practical.

## 2. Schedule rules

**BR-SCH-001 — Lesson ownership**  
Every personal schedule lesson is scoped to one user.

**BR-SCH-002 — Required planning interval**  
A lesson considered by planning has a valid date/time interval.

**BR-SCH-003 — Cancelled lesson does not block time**  
Lesson statuses are `active`, `cancelled`, and `replaced`. Cancelled, replaced, and soft-deleted lessons do not block free time. Normal application deletion of a lesson is soft deletion.

**BR-SCH-004 — Replacement precedence**  
The replacement lesson references the original through `replaces_lesson_id`. Both belong to the same user; the original becomes `replaced` and the replacement is `active`. Planning must not double-block them as two active occurrences of the same change.

**BR-SCH-005 — Schedule conflicts are deterministic**  
Overlapping effective lessons are detected by a Service. Manual creation/import must return a conflict result rather than silently accepting inconsistent scheduling.

**BR-SCH-006 — Duplicate detection**  
Imported lessons use a deterministic SHA-256 fingerprint based on normalized subject identity/name and the lesson's UTC start/end instants. Teacher, room, and lesson type are excluded because changes can update the same occurrence. Imported duplicate identity is scoped to user and academic period. Exact normalization/serialization remains implementation work; manual-entry duplicate handling is a Stage 03 decision.

**BR-SCH-007 — Schedule views**  
Today's, a specific date's, and a week's schedule are calculated using the user's timezone and return only effective schedule entries for the requested range.

## 3. Excel import rules

**BR-IMP-001 — Preview before commit**  
Import parsing/validation produces a preview before active schedule data is changed. Persist `schedule_import_batches` and `schedule_import_rows` to support preview, row-level errors, commit history, and duplicate detection. Batch states are `uploaded`, `validated`, `committed`, `failed`, and `cancelled`; row states are `valid`, `invalid`, and `duplicate`.

**BR-IMP-002 — Row-level validation**  
Invalid rows produce understandable errors identifying the row and problem.

**BR-IMP-003 — No partial silent import**  
The commit policy must be explicit: either all accepted rows are committed atomically or the API clearly reports which rows were accepted/rejected. V1 should prefer an atomic confirmed commit for a validated batch.

**BR-IMP-004 — Re-import safety**  
Repeating the same import must not silently duplicate unchanged schedule entries. The same file may be uploaded again for preview; file hashes are not unique. Commit resolves the lesson fingerprint and restores/updates a matching soft-deleted lesson rather than inserting a conflicting duplicate.

**BR-IMP-005 — Semester replacement is explicit**  
Every import batch targets a persisted AcademicPeriod owned by the user. Importing a new semester must not implicitly delete prior periods or schedule history. Academic periods with schedule/import history are protected from destructive deletion; any replacement operation requires explicit user intent and normal confirmation policy.

## 4. Task rules

**BR-TSK-001 — Task ownership**  
Every task belongs to one user.

**BR-TSK-002 — Planning estimate requirement**  
A task may exist without a complete planning estimate if the product allows it, but automatic scheduling requires a positive remaining duration.

**BR-TSK-003 — Deadline semantics**  
When a deadline exists, no generated study session for the task may end after that deadline.

**BR-TSK-004 — Priority is deterministic**  
Task priority is LOW = 1, NORMAL = 2, HIGH = 3, with NORMAL as the persisted default. Store it as TINYINT UNSIGNED and use a PHP integer-backed enum when implemented. It is not arbitrary LLM text.

**BR-TSK-005 — Completion**  
Task and subtask statuses are only `pending` and `completed`, stored in bounded VARCHAR columns and represented by PHP backed enums when implemented. Completed work requires `completed_at`; pending work has no completion timestamp. A completed task is excluded from future automatic planning unless explicitly reopened.

**BR-TSK-006 — Overdue is derived**  
An unfinished task whose deadline has passed is overdue. Derive this from completion state, deadline, and current time; V1 persists neither an overdue status nor an overdue flag.

**BR-TSK-007 — Subject association**  
Subject association is optional. If a task references a subject, it must belong to the same user. Subjects referenced by lessons cannot be hard-deleted; task subject references may become null on subject removal.

**BR-TSK-008 — Deletion preserves session history**\
Normal task deletion soft-deletes the task and its subtasks, cancels affected future planned study sessions, and preserves historical completed/missed/rescheduled sessions. Scheduled reminders targeting the task are cancelled or removed by the Reminder service. Administrative/account hard purge is separate from normal application deletion.

## 5. Subtask rules

**BR-SUB-001 — Single parent**  
A subtask belongs to exactly one task.

**BR-SUB-002 — Ownership inheritance**  
A subtask inherits ownership from its parent task and cannot be moved across users.

**BR-SUB-003 — Ordered decomposition**  
Every subtask has a positive, deterministic `position` within its parent task. Reordering is transactional.

**BR-SUB-004 — Deadline bound**  
If both task and subtask deadlines exist, the subtask deadline must not be later than the parent's. Warning-only behavior requires a later explicit product decision; it is not the approved baseline.

**BR-SUB-005 — Progress calculation**  
For V1, when a task has active (non-soft-deleted) subtasks, task progress percentage is calculated from completed active subtasks over total active subtasks. Do not persist a separate progress percentage.

**BR-SUB-006 — Avoid double-counting effort**  
When active (non-soft-deleted) subtasks exist, their estimates are authoritative schedulable work; completed subtasks contribute no remaining effort. Do not also schedule the parent's full `estimated_minutes` for that same work. If no active subtasks exist, use the task's estimate directly. Missing required subtask estimates are not a reason to double-count the parent estimate.

**BR-SUB-007 — Completion and deletion**\
Subtasks use the same `pending`/`completed` vocabulary and completion-timestamp consistency as tasks. Normal subtask deletion is soft deletion; ownership and historical session references remain tied to the parent task.

## 6. Availability and free-time rules

**BR-FREE-001 — Free time is calculated**  
Free time is a computed result, not user-entered schedule data.

**BR-FREE-002 — Base study windows**  
The calculation begins from the user's recurring `study_availability_windows` and typed numeric `planning_preferences`. There is one preferences row per user; deterministic constraints are not kept in one opaque JSON column. Availability uses ISO weekdays 1-7; overnight windows are split into two rows and overlapping windows for the same user/day are rejected by a Service.

**BR-FREE-003 — Blocking intervals**  
Effective lessons and already reserved study sessions are subtracted from allowed study windows.

**BR-FREE-004 — Breaks**  
Configured break requirements are applied by the deterministic calculation.

**BR-FREE-005 — Minimum slot**  
Slots shorter than the requested/configured minimum are not returned.

**BR-FREE-006 — Range clipping**  
Free slots must lie completely within the requested date range and daily allowed-study windows.

**BR-FREE-007 — No overlap**  
Returned free slots must not overlap blocking intervals or each other.

## 7. Workload constraint rules

**BR-LIM-001 — Daily maximum**  
Generated study sessions must not make planned study workload exceed the user's daily maximum.

**BR-LIM-002 — Weekly maximum**  
Generated study sessions must not make planned study workload exceed the user's weekly maximum.

**BR-LIM-003 — User restrictions override convenience**  
The planner may not violate configured study hours merely to produce a full plan. It must report infeasibility instead.

**BR-LIM-004 — Constraint defaults**  
If a user has not configured a limit, a documented system default may be used. Exact default values belong to configuration and tests, not to the LLM prompt.

## 8. Automatic planning rules

**BR-PLAN-001 — Deterministic priority order**  
Candidate work is ordered using a deterministic strategy. Baseline V1: earlier deadline first, then higher explicit priority, then a stable tie-breaker such as creation/order ID. Tasks without deadlines are ordered after deadline-bound tasks unless the requested planning operation specifically targets them.

**BR-PLAN-002 — Deadline protection**  
No generated session may be placed after the work item's deadline.

**BR-PLAN-003 — No conflicts**  
Generated sessions may not overlap effective lessons or other active study sessions.

**BR-PLAN-004 — Split large work**  
Work that does not fit one valid slot may be divided into multiple study sessions.

**BR-PLAN-005 — Remaining effort only**  
Planning uses remaining required effort, not total historical estimate already completed.

**BR-PLAN-006 — Feasibility reporting**  
If all required effort cannot fit before the deadline under current constraints, the planner returns a structured infeasibility result including unscheduled duration and relevant blocking constraints.

**BR-PLAN-007 — No fabricated capacity**  
The AI response may explain planner output but may not claim that capacity exists when the deterministic planner reports none.

**BR-PLAN-008 — Plan creation is transactional**  
If a generated plan is accepted and creates multiple sessions, the persistence step should not leave only a random subset of sessions because of a mid-operation failure.

### Study-session persistence semantics

**BR-SES-001 — Task and subtask consistency**\
A study session has a required `task_id` and optional `subtask_id`. When present, the subtask must belong to that task. The task, subtask, and session must resolve to the same user; a single FK must not reference both work tables.

**BR-SES-002 — Lifecycle and retention**\
Study-session statuses are `planned`, `completed`, `missed`, `rescheduled`, and `cancelled`. Sessions use lifecycle states rather than soft deletes to preserve history.

**BR-SES-003 — Completion and actual effort**\
Scheduled start/end define planned duration. `completed_at` is present exactly when status is `completed`; optional `actual_minutes` is positive when provided and is allowed only for completed sessions. Actual effort and planned duration are separate measures.

## 9. Rescheduling rules

**BR-RES-001 — Preserve completed work**  
Rescheduling must not move completed sessions.

**BR-RES-002 — Deadline remains binding**  
A missed/unfinished session may only be moved to a slot that still satisfies the associated deadline.

**BR-RES-003 — Recalculate capacity**  
Rescheduling uses current schedule, existing future sessions, user constraints, and workload limits rather than assuming the old slot is still representative.

**BR-RES-004 — Partial reschedule reporting**  
If only part of unfinished work can be rescheduled, the system reports the unscheduled remainder rather than silently dropping it.

**BR-RES-005 — Local adaptation first**  
When the user asks to change one plan element, prefer changing the affected future sessions rather than regenerating unrelated completed/past plan history.

**BR-RES-006 — Rescheduling history**\
Rescheduling creates a new session referencing its predecessor through `rescheduled_from_session_id`; the previous row becomes `rescheduled`. The approved unique predecessor reference allows at most one direct successor per row. Completed sessions are preserved as required by BR-RES-001.

## 10. Reminder rules

**BR-REM-001 — Reminder ownership**  
Every reminder belongs to one user.

**BR-REM-002 — Valid target**  
A reminder uses optional `task_id`, `subtask_id`, and `study_session_id` foreign keys, with at most one target set. The target must resolve to the same user. All three may be null for a general absolute reminder; there is no polymorphic-FK target design.

**BR-REM-003 — Trigger validity**  
A newly created scheduled reminder should have a future trigger unless the use case explicitly means "notify now".

**BR-REM-004 — Idempotent delivery**  
Retries must not intentionally produce duplicate user notifications for the same delivery event.

**BR-REM-005 — Target changes**  
When a deadline/session changes, a deterministic Service recalculates relative reminders from the persisted anchor and offset. Hard-purge handling must reconcile relative reminders before a target is removed; the schema records an unresolved CHECK/SET NULL implementation issue.

**BR-REM-006 — Relative and absolute triggers**\
Supported relative anchors are `task_deadline`, `subtask_deadline`, and `session_start`, each requiring its matching target. Persist the anchor, signed `offset_minutes`, and resolved `trigger_at = anchor_time + offset_minutes`; negative offsets mean before the anchor. Anchor and offset are both present or both null. For absolute reminders they are null and `trigger_at` is authoritative.

**BR-REM-007 — Reminder lifecycle**\
Reminder statuses are `scheduled`, `sent`, and `cancelled`. Sent reminders require `sent_at`; non-sent reminders have no sent timestamp. V1 does not introduce reminder-delivery-history tables.

## 11. Progress and report rules

**BR-STAT-001 — Statistics are data-derived**  
Counts, percentages, workload totals, and lateness are calculated from stored user data using deterministic queries/services.

**BR-STAT-002 — Period boundaries**  
Day/week/month/custom-period statistics use the user's timezone.

**BR-STAT-003 — Planned vs actual separation**  
Planned study duration comes from scheduled start/end; actual completed duration comes from `actual_minutes`. These are separate measures, not a single stored workload total.

**BR-STAT-004 — Late completion**  
Tasks and subtasks persist `completed_at` to support on-time versus late completion. A completed item whose completion instant is after its deadline is completed late. Do not infer historical completion timing from its current status alone.

**BR-STAT-005 — AI analysis grounded in metrics**  
The AI may summarize or explain a progress report only from tool-returned metrics; it must not invent unseen statistics.

## 12. AI agent rules

**BR-AI-001 — Orchestrator role**  
The AI agent decides what tool(s) to call and in what sequence, but deterministic Services decide business validity.

**BR-AI-002 — Structured tool contract**  
Tool input/output is structured and validated before a Service call.

**BR-AI-003 — Authorization cannot be bypassed**  
Every tool operation executes under the authenticated user's trusted context and normal authorization rules.

**BR-AI-004 — Missing required information**  
If a mutating request lacks a required business parameter that cannot be safely derived, the agent asks for clarification instead of inventing it.

**BR-AI-005 — Destructive confirmation**  
Bulk deletion, large replacement operations, or similarly destructive actions require explicit confirmation before execution.

**BR-AI-006 — Tool result verification**  
The agent should use the returned tool result as the source of truth before moving to the next dependent action.

**BR-AI-007 — No hidden mutation**  
The agent must clearly report successful mutations and failures. It must not claim a task, plan, or reminder was changed unless the tool confirmed the mutation.

**BR-AI-008 — Mutation policy is server-controlled**  
Which actions require confirmation is controlled by backend/tool policy, not solely by prompt wording.

**BR-AI-009 — Conversation metadata**\
Persist user-owned AI conversations and their messages. Message roles are `system`, `user`, `assistant`, and `tool`; structured tool-call metadata may remain JSON because it is orchestration metadata rather than deterministic planning constraints.

## 13. Authentication and security rules

**BR-SEC-001 — Unique email**  
Account email is unique according to the system's normalized email policy.

**BR-SEC-002 — Password storage**  
Passwords are never stored in plaintext and are handled by Laravel's standard hashing/authentication facilities.

**BR-SEC-003 — Unauthorized access denied**  
Unauthenticated users cannot access private planning endpoints.

**BR-SEC-004 — Cross-user tests**  
Every resource category with private data requires at least one authorization test showing that another user cannot read or mutate it.

**BR-SEC-005 — Upload validation**  
Excel imports enforce allowed file type/size and parse content as untrusted input.

## 14. Rules intentionally deferred

Stage 02 status, priority, targeting, effort, deletion/history, and fingerprint identity decisions are documented above and in [database-schema.md](database-schema.md). The following remain Stage 03 or later decisions:

- default study-window and break values;
- minimum/maximum study-session duration;
- exact normalization/serialization of the approved duplicate fingerprint identity;
- manual schedule-entry duplicate handling;
- whether schedule conflict blocks saving or can be force-confirmed;
- later requirements for report-history retention (no V1 report-history table).

No product defaults or conflict override policy are invented here. MySQL enforcement issues are recorded in the physical schema's implementation gate and require resolution before affected migrations. Each resolved rule should retain its ID and receive implementation tests.
