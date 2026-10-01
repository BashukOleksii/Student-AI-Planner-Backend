# Business Rules

Status: Stage 01 baseline  
Rule IDs are stable references for implementation and tests. Exact persistence details are deferred to Stage 02.

## 1. Global and ownership rules

**BR-GEN-001 — Ownership isolation**  
A user may read or mutate only resources that belong to that user, directly or through an owned parent.

**BR-GEN-002 — Trusted user context**  
Public API requests and AI tool arguments must not be able to choose another user's ID. The authenticated user is obtained from trusted server context.

**BR-GEN-003 — Deterministic business logic**  
Deadline checks, free-time calculation, conflicts, priority ordering, workload limits, progress metrics, and schedule import validation are implemented in PHP Services, not delegated to the LLM.

**BR-GEN-004 — Timezone awareness**  
All user-facing date concepts are evaluated in the user's configured timezone. Server timezone must not change planning results.

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
A cancelled lesson is retained for history if required but is excluded from free-time blocking.

**BR-SCH-004 — Replacement precedence**  
When a lesson is replaced, planning uses the effective replacement and must not double-block the original and replacement as two active occurrences of the same change.

**BR-SCH-005 — Schedule conflicts are deterministic**  
Overlapping effective lessons are detected by a Service. Manual creation/import must return a conflict result rather than silently accepting inconsistent scheduling.

**BR-SCH-006 — Duplicate detection**  
Schedule import/manual creation should detect probable duplicates using a deterministic identity/fingerprint defined in Stage 02/03.

**BR-SCH-007 — Schedule views**  
Today's, a specific date's, and a week's schedule are calculated using the user's timezone and return only effective schedule entries for the requested range.

## 3. Excel import rules

**BR-IMP-001 — Preview before commit**  
Import parsing/validation produces a preview before active schedule data is changed.

**BR-IMP-002 — Row-level validation**  
Invalid rows produce understandable errors identifying the row and problem.

**BR-IMP-003 — No partial silent import**  
The commit policy must be explicit: either all accepted rows are committed atomically or the API clearly reports which rows were accepted/rejected. V1 should prefer an atomic confirmed commit for a validated batch.

**BR-IMP-004 — Re-import safety**  
Repeating the same import must not silently duplicate unchanged schedule entries.

**BR-IMP-005 — Semester replacement is explicit**  
Importing a schedule for a new academic period must not implicitly delete prior historical schedule data unless the user explicitly chooses replacement behavior.

## 4. Task rules

**BR-TSK-001 — Task ownership**  
Every task belongs to one user.

**BR-TSK-002 — Planning estimate requirement**  
A task may exist without a complete planning estimate if the product allows it, but automatic scheduling requires a positive remaining duration.

**BR-TSK-003 — Deadline semantics**  
When a deadline exists, no generated study session for the task may end after that deadline.

**BR-TSK-004 — Priority is deterministic**  
Priority is a controlled value, not arbitrary LLM text. The exact enum/scale is defined in Stage 02.

**BR-TSK-005 — Completion**  
A completed task is excluded from future automatic planning unless explicitly reopened.

**BR-TSK-006 — Overdue is derived**  
An unfinished task whose deadline has passed is overdue. This should be derived rather than maintained as a second source of truth unless Stage 02 documents a concrete reason to persist it.

**BR-TSK-007 — Subject association**  
If a task references a subject, that subject must be accessible in the same user's academic context.

## 5. Subtask rules

**BR-SUB-001 — Single parent**  
A subtask belongs to exactly one task.

**BR-SUB-002 — Ownership inheritance**  
A subtask inherits ownership from its parent task and cannot be moved across users.

**BR-SUB-003 — Ordered decomposition**  
Subtasks have a deterministic order within a parent task when the user has chosen an order.

**BR-SUB-004 — Deadline bound**  
If both task and subtask deadlines exist, a subtask deadline should not be later than the parent task deadline unless the application explicitly allows and warns about the inconsistency.

**BR-SUB-005 — Progress calculation**  
For V1, when a task has subtasks, task progress percentage is calculated from completed subtasks over total subtasks. Do not store a second manually editable percentage.

**BR-SUB-006 — Avoid double-counting effort**  
When subtasks are used for planning, the planner must not schedule both the parent's full estimate and the subtasks' estimates for the same work. Stage 02/03 must define which estimate is authoritative.

## 6. Availability and free-time rules

**BR-FREE-001 — Free time is calculated**  
Free time is a computed result, not user-entered schedule data.

**BR-FREE-002 — Base study windows**  
The calculation begins from the user's allowed study windows for each day.

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

## 10. Reminder rules

**BR-REM-001 — Reminder ownership**  
Every reminder belongs to one user.

**BR-REM-002 — Valid target**  
A reminder may target only an entity accessible to that same user.

**BR-REM-003 — Trigger validity**  
A newly created scheduled reminder should have a future trigger unless the use case explicitly means "notify now".

**BR-REM-004 — Idempotent delivery**  
Retries must not intentionally produce duplicate user notifications for the same delivery event.

**BR-REM-005 — Target changes**  
When a deadline/session changes, relative reminders must be recalculated or explicitly marked for user review according to the reminder type.

## 11. Progress and report rules

**BR-STAT-001 — Statistics are data-derived**  
Counts, percentages, workload totals, and lateness are calculated from stored user data using deterministic queries/services.

**BR-STAT-002 — Period boundaries**  
Day/week/month/custom-period statistics use the user's timezone.

**BR-STAT-003 — Planned vs actual separation**  
Planned study duration and actually completed study duration are separate measures.

**BR-STAT-004 — Late completion**  
A task completed after its deadline counts as completed late when sufficient completion timing is available.

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

The following need a concrete decision in Stage 02 or Stage 03:

- exact task status enum values;
- exact priority scale;
- default study-window and break values;
- minimum/maximum study-session duration;
- parent-vs-subtask estimate authority;
- exact duplicate fingerprint for lessons/imports;
- whether schedule conflict blocks saving or can be force-confirmed;
- exact reminder target implementation;
- exact behavior when deleting tasks with historical sessions;
- exact report-retention strategy.

Each resolved item should update this document and receive tests.
