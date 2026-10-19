# Business Rules

H3b extends BR-AI-001–009 across Subtask and Reminder mutations. All eleven
registered mutation calls share one per-turn limit, including invalid attempts.
Only explicit authenticated confirmation executes the stored approved action.
Subtask lifecycle/deadline rules and Reminder F1/F2/F3a identifiers/semantics remain
unchanged. Relative triggers use the shared rules; delivery stays separate.
Public Reminder writes keep their outermost guard; the shared internal core allows
atomic action receipt/history commits. See [H3b](ai-subtask-reminder-actions.md).

Status: approved persistence baseline with current API enforcement recorded\
Rule IDs are stable references for implementation and tests. Physical details are recorded in [database-schema.md](database-schema.md); Migration Groups 1–9 are implemented and Stage 02 persistence is complete, including academic/import/Lesson data, Tasks/Subtasks, StudySessions, Reminders, and AI conversations/messages, with models, relationships, enums, factories, MySQL constraints, and persistence tests. Authentication + User Profile is implemented with Sanctum SPA sessions, trusted current-user context, IANA timezone validation, unique email validation, standard password hashing, and HTTP security tests. Planning Preferences and Study Availability APIs now enforce complete nullable settings replacement, database-safe numeric bounds, cross-field invariants, local-time intervals, overlap rejection, and ownership protection. Academic Context CRUD now enforces ownership Policies, same-owner institution links, uniqueness/date rules, public serialization, protected deletion, and malformed foreign-reference guards. Manual Lesson CRUD, cancellation/replacement, and bounded timezone-aware schedule views are implemented through LessonService/ScheduleService. T1/T2 Task create/detail/PATCH and manual completion/reopening, plus T3 owned filtered/paginated listing, are implemented; S1 adds nested Subtask basic APIs and independent completion/reopening; S2 adds transactional reordering and read-only count-based Task progress. See [tasks.md](tasks.md). S3 implements safe Task/Subtask soft deletion and scoped synchronous dependency cancellation. PF1 free-time calculation and its read-only API are implemented; see [planning-foundation.md](planning-foundation.md). Stages A–C implement deterministic work selection, automatic planning and reviewed transactional persistence; Stage D adds session lifecycle and single-session rescheduling. F3a in-app Reminder delivery is implemented; G1 progress statistics, G2a daily trends and G2b period comparisons are implemented; H1 read-only AI tools are implemented; H2 chat and H3a/H3b confirmed Task/Subtask/Reminder actions are implemented; external delivery, exports and further AI mutation domains remain future work. Import C2b implements confirmed atomic import reconciliation. Import C1 implements authenticated upload and persisted preview. Import B2 adds exact owned reference resolution and fixed fingerprint generation. Import B1 adds transient calendar/parity/timezone materialization. Import A implements deterministic weekly-template parsing only; see [the parser contract](schedule.md#import-a-workbook-parser-foundation).

Lesson association/replacement ownership, self-replacement/chain prohibition, Lesson lifecycle transitions and active overlap validation are implemented. Task Subject ownership, parent deadline bounds and manual completion/reopening are implemented in TaskService. S3 validates ownership, session task/subtask consistency and Reminder target compatibility for affected live deletion dependencies. Stage C implements session reads and reviewed creation; Stage D implements lifecycle, rescheduling chains and scoped scheduled-reminder cleanup. Reminder management, relative recalculation and local inbox delivery are implemented; administrative purge remains future work. MySQL enforces supported scalar/timestamp CHECKs, FK existence/actions, and approved uniqueness; it does not enforce cross-row ownership or checks prohibited by AUTO_INCREMENT/SET NULL restrictions.

These are intended requirements, not a claim that the backend implements all of them. References to "V1" mean the initial product release, not an implemented `/api/v1` route prefix. Deterministic rules must be shared by REST and AI-tool entry points through application Services. Preserve rule IDs when refining requirements so implementation and tests can trace them.

Stage B now implements deterministic advisory allocation in memory, including
duration bounds, projected conflicts/breaks, projected scheduled workload,
captured-clock/horizon protection, effective deadlines and exact partial results.
It preserves Stage A and C9 policies and adds no persistence or endpoint.
See [automatic study planning](automatic-study-planning.md).

Stage C now implements confirmed planned-session creation and read-only session
retrieval through [study plan persistence](study-plan-persistence.md). Preview
remains read-only; confirmation cannot silently substitute a changed proposal.

Stage E2 now enforces BR-GEN-001/002/006, BR-PLAN-002/003/005/008,
BR-SES-001/002/003, BR-RES-001–006 and BR-REM-001/002/005/007 for confirmed adaptive
batches. Full material reviews are owner-bound and short-lived; stale confirmations
conflict. Partial replacements require explicit partial acceptance; unplaced source
cancellation requires that flag and separate cancellation acceptance. Valid and
cannot-replan sources remain unchanged. Exact release-set effort/constraints,
one-successor history and scheduled-reminder cleanup commit or roll back together.
See [the E2 contract](adaptive-replanning.md#stage-e2-confirmation-contract).

## 1. Global and ownership rules

**BR-GEN-001 — Ownership isolation**  
A user may read or mutate only resources that belong to that user, directly or through an owned parent. Education institutions, subjects, and teachers are user-owned in V1, not shared global catalogs. Optional institution references must resolve to the same owner. Academic Context APIs enforce this through Policies, user-scoped relationships and Services. Their destructive guards also prevent FK side effects on foreign malformed children, including soft-deleted Tasks/Lessons; unsafe legacy institution IDs are masked on reads without altering data. Lesson APIs enforce owned Subject/Teacher/AcademicPeriod and replacement links; schedule views query only the trusted owner and mask foreign presentation relations without repairing persistence. C9 requires candidate evaluation source reads through the trusted owner, with fail-closed batch validation of selected session Task/Subtask compatibility, including valid deleted history.

**BR-GEN-002 — Trusted user context**  
Public API requests and AI tool arguments must not be able to choose another user's ID. The authenticated user is obtained from trusted server context. This is enforced by all current identity/profile, planning-settings, Academic Context, and Lesson/Schedule APIs; H1 also enforces it for the seven read-only AI tools; future orchestration must retain this boundary. C9 candidate evaluation accepts a trusted User from an application caller, never a public owner ID; authentication globals cannot substitute another owner.

**BR-GEN-003 — Deterministic business logic**  
Deadline checks, free-time calculation, conflicts, priority ordering, workload limits, progress metrics, and schedule import validation are implemented in PHP Services, not delegated to the LLM. C9 integration assembles Eloquent inputs and delegates calculations to unchanged C1–C8.

**BR-GEN-004 — Timezone awareness**  
All user-facing date concepts are evaluated in the user's IANA timezone persisted in `users.timezone`. Concrete domain instants use UTC DATETIME values; recurring local availability uses TIME values. Server timezone must not change planning results. Implemented schedule views construct local midnight/ISO Monday boundaries first, then convert each boundary to UTC independently; DST days/weeks do not assume fixed UTC durations. Lesson CRUD retains UTC whole-second Z timestamps; schedule presentation uses local numeric offsets and IANA timezone metadata. C9 uses raw UTC DATETIME instants and persisted user timezone, complete local ISO-week membership and exact elapsed break seconds. The candidate horizon is 31 inclusive nominal local dates; the exclusive end is excluded using its preceding second. DST and skipped-date semantics are preserved.

**BR-GEN-005 — Valid intervals**  
For every time interval, start must be earlier than end. C9 candidates use exactly two genuine integer UTC Unix endpoints and representable positive duration/query bounds; malformed arguments fail explicitly.

**BR-GEN-006 — Atomic multi-record operations**  
An operation that would leave an invalid partial state if interrupted must execute atomically where practical.

## 2. Schedule rules

**BR-SCH-001 — Lesson ownership**  
Every personal schedule lesson is scoped to one user.

**BR-SCH-002 — Required planning interval**  
A lesson considered by planning has a valid date/time interval.

**BR-SCH-003 — Cancelled lesson does not block time**  
Lesson statuses are `active`, `cancelled`, and `replaced`. Cancelled, replaced, and soft-deleted lessons do not block free time. Normal application deletion of a lesson is soft deletion. These effective filters and active-to-cancelled transitions are implemented; PF1 free-time calculation uses these filters; see [planning-foundation.md](planning-foundation.md). C9 also excludes cancelled/replaced/deleted Lessons from break anchors.

**BR-SCH-004 — Replacement precedence**  
The replacement lesson references the original through `replaces_lesson_id`. Both belong to the same user; the original becomes `replaced` and the replacement is `active`. Planning must not double-block them as two active occurrences of the same change. LessonService implements this atomically, prohibits chains/self-replacement, and reuses deleted manual replacement history. Cancelling a replacement leaves the original replaced. Deleting a replacement restores the original only if conflict validation succeeds; a current replacement prevents direct original deletion. C9 includes active replacements as break anchors without Lesson exemptions.

**BR-SCH-005 — Schedule conflicts are deterministic**  
Overlapping effective lessons are detected by a Service. Manual creation/import must return a conflict result rather than silently accepting inconsistent scheduling. Manual create/update, replacement, and revert checks are implemented using strict interval overlap against owned active non-deleted Lessons; adjacency is allowed, and conflicts return 422 under schedule. Import B3 implements transient projected conflict classification; confirmed atomic import commit is implemented by C2b.

**BR-SCH-006 — Duplicate detection**  
Imported lessons use the resolved persisted Subject database ID only, expressed as a positive ASCII decimal string without leading zeros or floating-point conversion, and the lesson's UTC start/end instants. Subject names/codes, teacher, room, and lesson type are excluded. Subject resolution is a separate importer responsibility; fingerprinting requires no text normalization. Re-importing the same Subject ID and interval preserves identity across subject renames.

Serialize times explicitly in UTC as `YYYY-MM-DDTHH:MM:SSZ`, with whole-second precision and no fractional seconds, independent of PHP/server default timezone; persisted lesson DATETIME values represent UTC. Canonical serialization is an ordered JSON array of exactly three strings `[subject_id, starts_at_utc, ends_at_utc]`, encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, without pretty printing, BOM, or trailing newline. `hash('sha256', canonical_serialization, false)` produces lowercase 64-character hexadecimal SHA-256. The verified example is recorded in [database-schema.md §3.10](database-schema.md#fixed-v1-import-fingerprint-contract).

Imported duplicate identity is scoped to `(user_id, academic_period_id, import_fingerprint)`. The database requires a period for a non-NULL fingerprint and retains fingerprint uniqueness after soft deletion. The contract is fixed; Import B2 implements its pure generator. Import C2b implements confirmed atomic reconciliation. Exact duplicate manual active intervals are rejected by application overlap validation; Import B3 implements transient identity/duplicate classification; C2b implements persisted reconciliation after locked C2a refresh.

**BR-SCH-007 — Schedule views**  
Today's, a specific date's, and a week's schedule are calculated using the user's timezone and return only effective schedule entries for the requested range. Implemented views use strict YYYY-MM-DD anchors, ISO Monday-first weeks, interval overlap (not starts-at-only selection), exclusive next-midnight boundaries, and starts_at/ends_at/id ordering. They are read-only and mask malformed foreign presentation relations.

## 3. Excel import rules

Import A implements only deterministic workbook parsing into weekly rules;
see [schedule.md](schedule.md#import-a-workbook-parser-foundation). Local times,
parity and normalized literal text come from the detected schedule region.
The parser infers no Lesson type and performs no date expansion, database
access or persistence. Preview/commit rules below remain later-stage work.

**BR-IMP-001 — Preview before commit**  
Import parsing/validation produces a preview before active schedule data is changed. Persist `schedule_import_batches` and `schedule_import_rows` to support preview, row-level errors, commit history, and duplicate detection. Batch states are `uploaded`, `validated`, `committed`, `failed`, and `cancelled`; row states are `valid`, `invalid`, and `duplicate`.

**BR-IMP-002 — Row-level validation**  
Invalid rows produce understandable errors identifying the row and problem.

**BR-IMP-003 — No partial silent import**  
The commit policy must be explicit: either all accepted rows are committed atomically or the API clearly reports which rows were accepted/rejected. V1 uses the atomic confirmed commit implemented by Import C2b for a validated batch.

**BR-IMP-004 — Re-import safety**  
Repeating the same import must not silently duplicate unchanged schedule entries. The same file may be uploaded again for preview; file hashes are not unique. Commit resolves the lesson fingerprint and restores/updates a matching soft-deleted lesson rather than inserting a conflicting duplicate.

**BR-IMP-005 — Semester replacement is explicit**  
Every import batch targets a persisted AcademicPeriod owned by the user. Importing a new semester must not implicitly delete prior periods or schedule history. Academic periods with schedule/import history are protected from destructive deletion (implemented by AcademicPeriodService, including soft-deleted or malformed foreign history); any replacement operation requires explicit user intent and normal confirmation policy.

**BR-IMP-006 — Weekly template materialization**\
Import B1 expands weekly rules only inside the inclusive AcademicPeriod date
range. Its starting ISO week is numerator; parity alternates by relative week
offset, including partial weeks and year boundaries. Every local start/end
resolves independently in the supplied IANA timezone, producing whole-second
UTC candidates; nonexistent/ambiguous endpoints and invalid intervals fail.
The source has no Lesson type: `LessonType::Other` is only a technical domain
fallback. Candidates remain transient until later resolution and validation;
no import row is prematurely classified as valid. See [the B1 contract](schedule.md#import-b1-transient-calendar-occurrences).

**BR-IMP-007 — Exact academic references before imported identity**\
Import B2 reloads an owned period and resolves only exact owned Subject/Teacher
names, using strict PHP equality after batched queries. Missing Subjects are
errors; missing or duplicate exact Teachers are errors, without institution
preference, fuzzy/LLM matching, code aliases or automatic creation. The fixed V1
fingerprint uses only the resolved Subject ID and UTC interval: Subject failure
leaves it null, while Teacher errors may retain it. Candidates/errors remain
transient without import-row status until B3 classification and C1 preview persistence.

**BR-IMP-008 — Transient projected re-import classification**\
Import B3 classifies resolved candidates with the existing row status enum, without
persistence. Same-user/period fingerprints reserve deleted identity and propose
create/update/restore/noop; non-deleted cancelled/replaced identities propose
preserve. Type corrections are retained because Excel supplies no type. Repeated
identical identities project once; contradictory authoritative data invalidates
all repeated rows. Different projected overlaps invalidate both identity groups;
owned effective external Lessons block normally, including exact manual matches.
Matched active histories are excluded together in favor of target positions.
Errors propagate through repeated identities; eventual commit is atomic and must
revalidate. See [the B3 contract](schedule.md#import-b3-transient-identity-and-projected-schedule-classification).

**BR-IMP-009 — Persisted occurrence preview**\
Import C1 accepts authenticated, content/extension-validated XLSX uploads up to
5 MiB for an owned AcademicPeriod. The temporary file is hashed over its bytes
and parsed before owner locks; a transaction reloads/locks User and period and
persists one coherent materialized/resolved/classified preview. One persisted
row is one concrete calendar occurrence; `row_number` is its 1-based preview
ordinal and Excel coordinates live in `raw_data`. Counters count final row
statuses: zero invalids means validated, including duplicates; any invalids
keep the batch uploaded. Controlled workbook/materialization failure persists
a failed batch with zero counters/rows. Request-level rejection creates no batch.
Repeated file hashes are allowed, uploads are not permanently stored, and no
Lessons change. Preview retrieval is owned/read-only; future confirmed commit
must revalidate the current database state. See [the C1 contract](schedule.md#import-c1-xlsx-upload-and-persisted-preview-api).

**BR-IMP-010 — Revalidate the concrete persisted preview**\
Import C2a revalidates only owned uploaded/validated batches under ordered
User/Batch/Period/Rows locks. It preserves row IDs, ordinals and raw source;
period/timezone changes never regenerate or remove occurrences. Existing owned
Subject/Teacher IDs survive renames. Invalid/missing mappings retry exact raw
names through B2; strict equality and Teacher ambiguity rules still apply.
Fingerprints and B3 classification are recomputed, discarding stale diagnostic
IDs/status/operations/errors. Core corruption fails without partial refresh;
invalid UTC intervals become row integrity errors, never normalized instants.
Rows/counters refresh atomically: zero invalids means validated, otherwise
uploaded. No catalog or Lesson mutations occur; commit remains separate and
must revalidate again. See [the C2a contract](schedule.md#import-c2a-persisted-preview-revalidation).

**BR-IMP-011 — Confirm the current preview and apply atomically**\
Import C2b requires explicit POST commit on an owned validated Batch. Its one
transaction holds User/Batch/Period/Rows locks across C2a refresh and Lesson
mutation. Changes to confirmed row/summary semantics persist as a refreshed
preview and return 409 for reconfirmation, without Lesson mutation. Unchanged
validated previews apply create/update/restore/preserve/noop using current
user/period/fingerprint identity, never a cached matched ID. Create uses Other;
update/restore preserve the latest existing type and replacement links; restore
reuses the same physical row as active. Noop/preserve mutate nothing. The Lesson
Batch FK marks the last applying create/update/restore, not the latest duplicate
upload. Any failure rolls back all mutations and the committed transition.
Successful rows retain confirmed operations and committed_at is set once in
UTC; committed retries are idempotent. Ordinary import never deletes missing
historical occurrences. See [the C2b contract](schedule.md#import-c2b-confirmed-atomic-lesson-commit).

## 4. Task rules

**BR-TSK-001 — Task ownership**  
Every task belongs to one user. T3 lists only that authenticated owner's non-deleted Tasks, using AND filters and owned Subject validation. Collection order is deadline ascending then ID, with null deadlines last; this is presentation order, not planning priority ranking. Pagination is ordinary offset pagination; see [tasks.md](tasks.md).

**BR-TSK-002 — Planning estimate requirement**  
A task may exist without a complete planning estimate if the product allows it, but automatic scheduling requires a positive remaining duration. Stage A excludes missing-estimate units with `missing_estimate`, preserving null effort bounds and known credit/reservations without defaults.

**BR-TSK-003 — Deadline semantics**  
When a deadline exists, no generated study session for the task may end after that deadline. C9 diagnostics use the nullable owned active Task deadline and accept end equality; historical candidates are allowed without authorizing past creation.

**BR-TSK-004 — Priority is deterministic**  
Task priority is LOW = 1, NORMAL = 2, HIGH = 3, with NORMAL as the persisted default. Store it as TINYINT UNSIGNED and use a PHP integer-backed enum when implemented. It is not arbitrary LLM text.

**BR-TSK-005 — Completion**  
Task and subtask statuses are only `pending` and `completed`, stored in bounded VARCHAR columns and represented by PHP backed enums when implemented. Completed work requires `completed_at`; pending work has no completion timestamp. A completed task is excluded from future automatic planning unless explicitly reopened. T2 implements owner-locked, idempotent manual Task completion/reopening: completion records the current UTC whole-second instant, reopening clears it, and subsequent completion records a new instant. Late completion is allowed. Task and Subtask completion statuses are independent; no planning/reminder side effects are implemented. Stage A read-only selection excludes completed parents and their pending children without changing independent statuses.

**BR-TSK-006 — Overdue is derived**  
An unfinished task whose deadline has passed is overdue. Derive this from completion state, deadline, and current time; V1 persists neither an overdue status nor an overdue flag.

**BR-TSK-007 — Subject association**  
Subject association is optional. If a task references a subject, it must belong to the same user. SubjectService blocks deletion for all Lesson history, including soft-deleted Lessons. Allowed removal nulls same-owner Task references, including soft-deleted Tasks; foreign Task references block removal to prevent cross-user FK side effects. T1 TaskService validates the complete owned Subject association on create/PATCH; see [the basic Task API](tasks.md).

**BR-TSK-008 — Deletion preserves session history**\
S3 normal task deletion atomically soft-deletes the task and active subtasks. It cancels affected planned sessions whose UTC start is at or after the captured current instant; past planned and historical completed/missed/rescheduled/cancelled sessions remain unchanged. Scheduled reminders targeting the task, any descendant or a newly cancelled session are cancelled, never removed; sent/cancelled reminder history is preserved. Unsafe live dependencies reject the whole operation. Administrative/account hard purge and restore remain separate future work; see [tasks.md](tasks.md#s3-safe-deletion-and-dependency-cleanup).

## 5. Subtask rules

**BR-SUB-001 — Single parent**  
A subtask belongs to exactly one task.

**BR-SUB-002 — Ownership inheritance**  
A subtask inherits ownership from its parent task and cannot be moved across users or Tasks. S1 enforces owned non-deleted parents and exact non-deleted nested children through scoped queries and Policies, with 404 before body validation for foreign/mismatched resources.

**BR-SUB-003 — Ordered decomposition**  
Every subtask has a positive, deterministic `position` within its parent task. S1 allocates maximum non-deleted position + 1 (or 1 when empty) under owner/parent locks, with a 65535 capacity error. Reads order by position then ID; creation does not renumber/restore. S2 accepts a complete active permutation and normalizes positions to 1..N transactionally under owner/parent/child locks; stale/invalid sets return 422 without mutation. Deleted history is untouched and unchanged positions receive no UPDATE.

**BR-SUB-004 — Deadline bound**  
If both task and subtask deadlines exist, the subtask deadline must not be later than the parent's. T1 TaskService enforces this bound for parent updates against non-deleted Subtasks, including completed records. S1 SubtaskService enforces the reciprocal bound on create and complete-candidate PATCH under the same owner/parent lock order; legacy invalid deadlines require explicit repair. Warning-only behavior requires a later explicit product decision; it is not the approved baseline. C9 diagnostics use the earliest non-null parent/child deadline, including parent fallback. This does not change write-side deadline validation or repair stored legacy deadlines.

**BR-SUB-005 — Progress calculation**  
For V1, when a task has active (non-soft-deleted) subtasks, task progress percentage is calculated from completed active subtasks over total active subtasks. Do not persist a separate progress percentage. S2 exposes counts and an integer percentage using nearest, half-up rounding in a separate read-only Resource; no active Subtasks yields null percentage and zero counts. Parent status, positions, estimates and sessions do not define this metric, and no lifecycle changes are inferred.

**BR-SUB-006 — Avoid double-counting effort**  
When active (non-soft-deleted) subtasks exist, their estimates are authoritative schedulable work; completed subtasks contribute no remaining effort. Do not also schedule the parent's full `estimated_minutes` for that same work. If no active subtasks exist, use the task's estimate directly. Missing required subtask estimates are not a reason to double-count the parent estimate. Stage A implements this authority rule, retaining valid pending siblings when a child estimate is missing. Parent-level and deleted-child session history is diagnosed without reassignment.

**BR-SUB-007 — Completion and deletion**\
Subtasks use the same `pending`/`completed` vocabulary and completion-timestamp consistency as tasks. Parent completion/reopening never changes Subtask status; completing all Subtasks does not automatically complete the parent. S1 implements idempotent Subtask completion/reopening with current UTC whole-second completion instants and atomic status/timestamp updates, without parent or planning/reminder side effects. S3 normal subtask deletion is soft deletion with scoped future planned session and scheduled reminder cancellation; ownership and historical session references remain tied to the parent task. Parent status and surviving positions remain unchanged.

## 6. Availability and free-time rules

**BR-FREE-001 — Free time is calculated**  
Free time is a computed result, not user-entered schedule data.

**BR-FREE-002 — Base study windows**  
The calculation begins from the user's recurring `study_availability_windows` and typed numeric `planning_preferences`. There is at most one preferences row per user; GET does not materialize missing rows and complete PUT requires all five nullable minute keys (configured values 1–65535), enforcing session/workload bound ordering. No product defaults are supplied; deterministic constraints are not kept in one opaque JSON column. Availability uses ISO weekdays 1-7 and local `HH:MM:SS`; starts are 00:00:00–23:59:59, and ends also permit 24:00:00. Overnight windows must be explicitly split into two rows, with no automatic split or UTC conversion. `StudyAvailabilityService` rejects same-user/day overlaps using `existing.starts_at < candidate.ends_at AND existing.ends_at > candidate.starts_at`; adjacent windows are valid. PATCH evaluates the complete candidate and excludes itself. Ownership comes only from authentication; the window Policy returns 404 for cross-user update/delete.

**BR-FREE-003 — Blocking intervals**  
Effective lessons and already reserved study sessions are subtracted from allowed study windows. PF1 subtracts active non-deleted Lessons and planned sessions; C9 preserves that behavior and passes unfiltered PF1 intervals to C8.

**BR-FREE-004 — Breaks**  
Configured break requirements are applied by the deterministic calculation. Approved C9 V1 anchors are all effective active Lessons and planned StudySessions only. Null break has no default; configured preferred_break_minutes uses exact elapsed UTC seconds, with equality valid and overlap distinct. PF1 does not reserve break time.

**BR-FREE-005 — Minimum slot**  
Slots shorter than the requested/configured minimum are not returned.

**BR-FREE-006 — Range clipping**  
Free slots must lie completely within the requested date range and daily allowed-study windows.

**BR-FREE-007 — No overlap**  
Returned free slots must not overlap blocking intervals or each other.

## 7. Workload constraint rules

**BR-LIM-001 — Daily maximum**  
Generated study sessions must not make planned study workload exceed the user's daily maximum. Approved C9 V1 planned workload includes planned and completed scheduled StudySession intervals, each database record once, including distinct equal/overlapping intervals; excludes missed/rescheduled/cancelled. Completed actual_minutes is a separate statistics measure. Historical planned/completed sessions count without clock-based status inference.

**BR-LIM-002 — Weekly maximum**  
Generated study sessions must not make planned study workload exceed the user's weekly maximum. The same C9 status/duration policy applies to ISO weeks in the persisted user timezone. Sources cover whole relevant weeks, including earlier/later sessions and those crossing query boundaries; weeks are not fixed 604800-second periods.

**BR-LIM-003 — User restrictions override convenience**  
The planner may not violate configured study hours merely to produce a full plan. It must report infeasibility instead.

**BR-LIM-004 — Constraint defaults**  
If a user has not configured a limit, a documented system default may be used. Exact default values belong to configuration and tests, not to the LLM prompt.

## 8. Automatic planning rules

**BR-PLAN-001 — Deterministic priority order**  
Candidate work is ordered using a deterministic strategy. Baseline V1: earlier deadline first, then higher explicit priority, then a stable tie-breaker such as creation/order ID. Tasks without deadlines are ordered after deadline-bound tasks unless the requested planning operation specifically targets them. Stage A fixes the tie-breakers to ascending Task ID, then Subtask position and Subtask ID. Children inherit parent priority and use the earliest present parent/child UTC deadline; expired deadlines are explicitly excluded. Null deadlines remain last, including explicit eligible targets.

**BR-PLAN-002 — Deadline protection**  
No generated session may be placed after the work item's deadline. C9 diagnostics select the earliest present Task/Subtask deadline in raw UTC seconds; end equality passes and one second later violates the rule.

**BR-PLAN-003 — No conflicts**  
Generated sessions may not overlap effective lessons or other active study sessions. C9 combines PF1 full containment with explicit effective Lesson/planned-session break anchors; overlap and insufficient separation remain distinct C8 codes. Stage B proposals also participate in subsequent free-time, break and scheduled-workload checks. Stage C independently revalidates the complete batch under the owner lock before saving.

**BR-PLAN-004 — Split large work**  
Work that does not fit one valid slot may be divided into multiple study sessions.

**BR-PLAN-005 — Remaining effort only**  
Planning uses remaining required effort, not total historical estimate already completed. Approved Stage A A2 credits completed sessions by positive actual_minutes × 60, or exact scheduled seconds when actual is null. Remaining work is max(0, estimate - completed credit). A3 separately subtracts correctly attributed future planned reservations starting at/after one full-precision captured UTC instant and ending by the effective deadline, clamping unallocated work at zero and reporting excess. Past-start plans never reserve new effort; no status changes occur.

**BR-PLAN-006 — Feasibility reporting**  
If all required effort cannot fit before the deadline under current constraints, the planner returns a structured infeasibility result including unscheduled duration and relevant blocking constraints.

Stage B reports exact algorithmically unallocated seconds with observed constraint
codes and bounded-search exhaustion. Its deterministic greedy allocation is not a
global optimizer; partial output does not prove mathematical impossibility.

**BR-PLAN-007 — No fabricated capacity**  
The AI response may explain planner output but may not claim that capacity exists when the deterministic planner reports none.

**BR-PLAN-008 — Plan creation is transactional**  
If a generated plan is accepted and creates multiple sessions, the persistence step should not leave only a random subset of sessions because of a mid-operation failure.

Stage C requires a valid owner-bound 15-minute protected preview token and an
unchanged canonical planning outcome after transactional regeneration. Partial
plans require explicit acceptance; empty plans cannot be saved. Owner-first
serialization and complete-batch revalidation precede atomic insertion, and
replayed reservations conflict. Every new start must remain strictly future at
the final pre-commit check. The Service owns a fresh outermost transaction.

### Study-session persistence semantics

**BR-SES-001 — Task and subtask consistency**\
A study session has a required `task_id` and optional `subtask_id`. When present, the subtask must belong to that task. The task, subtask, and session must resolve to the same user; a single FK must not reference both work tables. C9 selected source sessions must have an owned Task and, if present, its exact child. Invalid references cause a generic integrity failure rather than dropping workload; valid owned soft-deleted work history remains countable.

**BR-SES-002 — Lifecycle and retention**\
Study-session statuses are `planned`, `completed`, `missed`, `rescheduled`, and `cancelled`. Sessions use lifecycle states rather than soft deletes to preserve history. C9 diagnostics perform no status transitions and allow historical candidates. Stage C creates only new `planned` rows with null completion/actual/predecessor fields after reviewed confirmation. Stage D implements explicit completion after start, miss after end, and cancellation/rescheduling strictly before start. Terminal states cannot reopen or overwrite historical effort; GET never changes status. General editing and automatic missed detection remain deferred.

**BR-SES-003 — Completion and actual effort**\
Scheduled start/end define planned duration. `completed_at` is present exactly when status is `completed`; optional `actual_minutes` is positive when provided and is allowed only for completed sessions. Actual effort and planned duration are separate measures. C9 completed sessions contribute their original scheduled interval to local day/week budgets; actual completed effort does not replace this contribution. Stage A actual-first completed effort credit is separate from C9 scheduled workload. Distinct completed records remain additive, raw excess credit is diagnostic, and future reservations do not constitute completion.

## 9. Rescheduling rules

Stage E1 adds read-only adaptive detection/proposals under these same rule IDs.
Only eligible strictly future planned reservations can be proposed for relocation;
valid sources remain protected. Identity-based self-exclusion removes the candidate
from blocking, breaks, workload and reservation credit. One source has at most one
shorter-or-equal proposal, capped by current exact-unit effort; partial/unplaced
results and the full hypothetical release set are explicit. E2 confirmation and
application are implemented separately with explicit acceptance and atomic
persistence. See [adaptive replanning](adaptive-replanning.md).

Stage D's exact transition and transfer contracts are documented in
[study-session-lifecycle.md](study-session-lifecycle.md). Completion accepts a
positive 1–65535 actual-minute integer or omission, storing null on omission.
It records a whole-second UTC completion instant without changing scheduled time
or work progress. Miss requires end ≤ captured now; manual cancellation and
rescheduling require original start > captured full-precision now. S3 deletion
retains its deliberately inclusive start-equality cleanup.

**BR-RES-001 — Preserve completed work**  
Rescheduling must not move completed sessions. Stage D accepts only upcoming planned sources; all terminal states conflict. Missed history remains intact and unfinished work uses a new Stage C preview.

**BR-RES-002 — Deadline remains binding**  
New allocation of missed/unfinished work must still satisfy the associated deadline. Stage D transfers an upcoming planned session only; missed sessions use fresh Stage C allocation, preserving their original history.

**BR-RES-003 — Recalculate capacity**  
Rescheduling uses current schedule, existing future sessions, user constraints, and workload limits rather than assuming the old slot is still representative.

**BR-RES-004 — Partial reschedule reporting**  
If only part of unfinished work can be rescheduled, the system reports the unscheduled remainder rather than silently dropping it.

**BR-RES-005 — Local adaptation first**  
When the user asks to change one plan element, prefer changing the affected future sessions rather than regenerating unrelated completed/past plan history.

**BR-RES-006 — Rescheduling history**\
Rescheduling creates a new session referencing its predecessor through `rescheduled_from_session_id`; the previous row becomes `rescheduled`. The approved unique predecessor reference allows at most one direct successor per row. Completed sessions are preserved as required by BR-RES-001. Stage D validates connected ownership, exact attribution, predecessor state and acyclic history, and inserts one immediate successor atomically. Replacement duration may not exceed original duration or Stage A unallocated effort after releasing the source.

## 10. Reminder rules

**BR-REM-001 — Reminder ownership**  
Every reminder belongs to one user.

**BR-REM-002 — Valid target**  
A reminder uses optional `task_id`, `subtask_id`, and `study_session_id` foreign keys, with at most one target set. The target must resolve to the same user. All three may be null for a general absolute reminder; there is no polymorphic-FK target design.

**BR-REM-003 — Trigger validity**  
F1/F2 newly created and explicitly edited absolute/relative reminders must have a trigger strictly after full-precision current UTC time. The API requires canonical whole-second UTC timestamps in years 1000–9999; there is no "notify now" exception.

**BR-REM-004 — Idempotent delivery**  
Retries must not intentionally produce duplicate user notifications for the same delivery event.

F3a implements this for committed local inbox records: a nullable unique
`notifications.reminder_id`, owner-first current-state locking and one transaction
for native database-notification insertion plus `scheduled -> sent` with `sent_at`.
Failure rolls back both records; reprocessing terminal history is forbidden.
External email/push exactly-once semantics are outside this guarantee.

**BR-REM-005 — Target changes**  
When a deadline/session changes, a deterministic Service recalculates relative reminders from the persisted anchor and offset. Hard-purge handling must reconcile relative reminders before a target is removed; target compatibility and purge reconciliation are explicitly Service-level because MySQL forbids target CHECKs involving the SET NULL FKs.

Stage D preserves original anchor times while retiring session identities. Its
affected scheduled reminders are cancelled with their metadata retained; no
reminder is cloned or redirected to the new identity. Sent/cancelled and unrelated
reminders remain intact. General anchor editing/recalculation remains separate
from this scoped lifecycle cleanup.

**BR-REM-006 — Relative and absolute triggers**\
Supported relative anchors are `task_deadline`, `subtask_deadline`, and `session_start`, each requiring its matching target. Persist the anchor, signed `offset_minutes`, and resolved `trigger_at = anchor_timestamp_utc + offset_minutes * 60 seconds`; negative offsets mean before the anchor. Anchor and offset are both present or both null. For absolute reminders they are null and `trigger_at` is authoritative.

**BR-REM-007 — Reminder lifecycle**\
Reminder statuses are `scheduled`, `sent`, and `cancelled`. Sent reminders require `sent_at`; non-sent reminders have no sent timestamp. F3a's inbox is the delivered record; no separate delivery-history/outbox table is introduced. Inbox `read_at` is independent of Reminder status and work completion.

F1/F2 enforce BR-REM-001/002/003/005/006/007 through authenticated absolute and
relative Reminder management. General reminders require a meaningful message;
relative reminders require exactly one compatible owned target and a genuine signed
SMALLINT offset. The server resolves the exact persisted Task deadline, Subtask's
own deadline or linked session start using integer UTC arithmetic, without a parent
fallback or successor substitution. Explicit triggers/offset edits must remain
future; incompatible modes and target/anchor/ownership/delivery edits are rejected.

Task/Subtask deadline changes atomically recalculate scheduled direct relative
reminders, preserving absolute and historical rows. Missing, elapsed or
unrepresentable resolutions cancel with the last committed trigger retained.
Actual Task/Subtask completion cancels affected scheduled reminders in both modes,
including invalidated descendant/session targets, without changing sessions or
other work. Reopening never resurrects history. Shared structural integrity checks
fail closed before a reminder batch write and roll back the enclosing mutation.
Fresh owner-first application transactions and current locked sources serialize
public writes. GET never recalculates, cancels or repairs records. S3/Stage D/E2
cleanup remains authoritative, retaining original session identities and metadata.
No existing operation edits a session start in place or exposes hard purge.

F3a processes scheduled triggers at or before full-precision UTC now, including
overdue records. Shared structural checks fail closed on foreign/broken references;
valid but ineligible targets are cancelled with original metadata retained.
Eligible relative reminders must match the exact current anchor-plus-offset trigger;
stale/missing/overflow anchors fail without repair. A still-planned session may
deliver after its start without inferring missed/completed state. Each corrupt
candidate rolls back and is counted independently; the bounded batch continues.
GET is read-only; owned marking-as-read is idempotent and preserves immutable
payload and sent history. Existing retirement never clones reminders/notifications.
Email/push and external retry channels remain future work. Administrative hard
purge must reconcile scheduled dependents before SET NULL and separately account
for polymorphic inbox ownership; no speculative endpoint/hook is introduced.
See [the F1/F2/F3a contract and actual verification](reminders.md).

## 11. Progress and report rules

**BR-STAT-001 — Statistics are data-derived**  
Counts, percentages, workload totals, and lateness are calculated from stored user data using deterministic queries/services.

G1 implements an owned read-only summary through `ProgressStatisticsService`;
see [progress statistics](progress-statistics.md). Task and Subtask current counts
are independent active-record aggregates. Pending work is overdue only when its
own non-null persisted deadline is strictly before the captured UTC clock. No
combined parent/child work total, percentage, snapshot row or inferred event is added.

G2a [daily progress trends](progress-trends.md) shares G1's source selection and
metric reducers. Every requested local date has a bucket, including explicit zero
days; exact integer daily sums reconcile with G1 period activity on the same
snapshot. G1 current lifetime counts are not historical daily populations.

G2b [period comparisons](progress-comparisons.md) reuses the same bounded sources
and reducers for both periods in one current snapshot. Every integer delta is
current minus baseline, with checked nonnegative operands and no float coercion.
Direction observations describe numerical changes without performance judgments.

**BR-STAT-002 — Period boundaries**  
Day/week/month/custom-period statistics use the user's timezone.

G1 requires two exact real local dates, ordered and at most 31 inclusive labels.
The freshly persisted IANA timezone and existing exact half-open UTC membership
expansion handle DST, skipped dates and disjoint historical occurrences. Task and
Subtask completion use `completed_at`; sessions use original `starts_at` with the
full record duration, without boundary clipping or completion-time attribution.

G2a uses the same membership per nominal date: skipped dates remain zero, repeated
or disjoint occurrences of a date share one bucket, and no UTC envelope fills a
membership gap. Query count is independent of calendar-day count.

G2b requires equal nominal date lengths, at most 31 dates per period, with baseline
ending strictly before current begins. The gap is unrestricted. Exact union queries
exclude the gap; each membership is reduced separately within the same snapshot.
Equal nominal lengths may have different UTC durations and are never normalized
as fixed 24-hour dates.

**BR-STAT-003 — Planned vs actual separation**  
Planned study duration comes from scheduled start/end; actual completed duration comes from `actual_minutes`. These are separate measures, not a single stored workload total.

G1 returns counts and full scheduled seconds separately for all five persisted
session statuses. Only completed sessions contribute known actual minutes; null
actual minutes increase an explicit unknown-duration count, never a scheduled-time
fallback. Stage A planning credit and C9 scheduled workload keep their existing rules.
G2b exposes an unknown-actual-minute flag for each period. A known-minute difference
is incomplete as a comparison of actual study duration if either flag is true;
no unknown duration is estimated or suppressed.

**BR-STAT-004 — Late completion**  
Tasks and subtasks persist `completed_at` to support on-time versus late completion. A completed item whose completion instant is after its deadline is completed late. Do not infer historical completion timing from its current status alone.

G1 selects currently completed, non-deleted work with persisted completion in the
period. Equality with its own deadline is on time; absent deadlines are classified
separately. Subtasks require an active owned parent and use their own deadline,
without planning-engine inheritance. Reopening clears completion metadata;
later deletion or deadline changes can alter a later report of past activity.
Immutable status-transition/deadline snapshots do not exist and are not reconstructed.

**BR-STAT-005 — AI analysis grounded in metrics**  
The AI may summarize or explain a progress report only from tool-returned metrics; it must not invent unseen statistics.

G1 adds no AI tool, LLM analysis, forecasting or report generation. That boundary
and the approved BR-STAT-001–005 identifiers remain unchanged.

G2a adds deterministic daily data only, with no AI consumer, forecasting, period
comparison or generated report. It preserves all five approved rule identifiers.

G2b adds deterministic structured comparisons with `historical_snapshot: false`.
It adds no AI narrative, forecast, score, percentage, export or historical
reconstruction. All five approved rule identifiers remain unchanged.

## 12. AI agent rules

**BR-AI-001 — Orchestrator role**  
The AI agent decides what tool(s) to call and in what sequence, but deterministic Services decide business validity.

**BR-AI-002 — Structured tool contract**  
Tool input/output is structured and validated before a Service call.

H1 implements seven fixed read-only definitions with strict JSON scalar types,
explicit required properties and rejection of unknown/null/nested values. The
Executor returns one stable success/error envelope; only allowlisted names may
dispatch. Existing Services retain date, range, filtering and comparison rules.
See [AI tools foundation](ai-tools-foundation.md) for exact schemas and errors.

**BR-AI-003 — Authorization cannot be bypassed**  
Every tool operation executes under the authenticated user's trusted context and normal authorization rules.

H1 accepts the User only from a trusted application caller, reloads persisted
timezone and uses owner-scoped Service reads. Arguments cannot select user/owner
or timezone; foreign presentation references are masked and unsafe session
attribution fails closed without disclosing identifiers or exception details.

**BR-AI-004 — Missing required information**  
If a mutating request lacks a required business parameter that cannot be safely derived, the agent asks for clarification instead of inventing it.

H1 read tools reject missing required parameters with `invalid_arguments` and do
not infer dates, IDs or other business values. The H2 orchestrator owns
clarification or derivation from trusted context before invoking the Executor.

**BR-AI-005 — Destructive confirmation**  
Bulk deletion, large replacement operations, or similarly destructive actions require explicit confirmation before execution.

**BR-AI-006 — Tool result verification**  
The agent should use the returned tool result as the source of truth before moving to the next dependent action.

**BR-AI-007 — No hidden mutation**  
The agent must clearly report successful mutations and failures. It must not claim a task, plan, or reminder was changed unless the tool confirmed the mutation.

All H1 tools are read-only, including execution side effects: no conversation,
message, tool log, snapshot, notification/read-state or reminder persistence,
no plan/session creation, and no external requests or background dispatch.
Bounded task/session outputs expose pagination or explicit truncation metadata.

**BR-AI-008 — Mutation policy is server-controlled**  
Which actions require confirmation is controlled by backend/tool policy, not solely by prompt wording.

**BR-AI-009 — Conversation metadata**\
Persist user-owned AI conversations and their messages. Message roles are `system`, `user`, `assistant`, and `tool`; structured tool-call metadata may remain JSON because it is orchestration metadata rather than deterministic planning constraints.

H3a implements BR-AI-001–009 for four confirmed Task actions. Proposal definitions
are separate from H1 read tools and require exact typed allowlisted arguments.
Missing/ambiguous details require clarification. All four actions require explicit
authenticated REST confirmation under server policy; conversational yes and prompt
injection cannot authorize mutation. Proposals become confirmable only with a
committed turn. Confirmation revalidates ownership, target state and current domain
constraints, and commits TaskService effects, receipt and deterministic history
atomically. Idempotent confirmation replays that receipt. Rejection/expiry/stale or
failed validation never claims a Task mutation. See [H3a](ai-task-actions.md).
Completion/reopening retain the direct Task lifecycle's preservation of unrelated
legacy fields; create/update retain complete-candidate validation. Foreign Subject
references remain masked in action previews and execution receipts.

Existing conversation/message persistence remains separate from H1 discovery and
execution. H1 neither requires nor creates a conversation or message.

H2 implements read-only conversational orchestration through authenticated REST,
without changing BR-AI identifiers or H1 rules. The model dynamically selects H1
tools and explains successful results; every call still uses the trusted owner's
ToolExecutor. Relative dates use a trusted clock and persisted timezone; ambiguous
parameters require clarification. Provider strict schemas adapt optional fields
without weakening H1 validation. Untrusted messages/tool text cannot grant new
capabilities or select owners, models, keys or system instructions.

One active expiring MySQL lease per conversation and durable request-key/content
binding prevent interleaved or duplicate successful turns. Network calls never
run within database transactions. Successful user/assistant/tool history commits
atomically with its replay result; provider failures leave no partial transcript.
Stale workers cannot finalize or release a replacement claim. Provider round,
tool-call, context, timeout and rate limits are server-controlled. Public history
excludes internal prompts, provider state and full tool payloads. H2 stores only
AI conversation/coordination data; study work, reminders and inbox state remain
unchanged by H2 itself. H3a/H3b add the explicit confirmed mutations described above.
See [the H2 contract](ai-agent-chat.md).

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

Stage 02 status, priority, targeting, effort, deletion/history, and fingerprint identity decisions are documented above and in [database-schema.md](database-schema.md). The following remain future planning/product decisions:

- default study-window and break values;
- product defaults for minimum/maximum study-session duration (configured bounds are already enforced);
- any future conflict override policy (current manual Lesson conflicts block saving; no override exists);
- later requirements for report-history retention (no V1 report-history table).

No product defaults or conflict override policy are invented here. Resolved MySQL/application enforcement decisions are recorded in the physical schema's implementation gate; remaining domain application invariants still require future Services. Each resolved rule should retain its ID and receive implementation tests.

## C9/C10 integration implementation status

The C9 V1 source policies above are approved and implemented by the read-only
C10 PlanningCandidateEvaluationService. It reloads trusted ownership/timezone,
assembles bounded Eloquent inputs, and delegates unchanged calculations to
PF1/C8. Missing preferences remain five nulls. No clock restrictions, defaults,
status repair, session persistence or automatic planning are introduced.
See [the complete application contract](planning-integration.md).

## Stage A remaining effort and work selection

Approved A1–A6 policies are implemented by RemainingEffortService (pure accounting)
and PlanningWorkSelectionService (trusted owned reads, eligibility and ordering).
See [planning-work-selection.md](planning-work-selection.md) for exact contracts,
exclusions/warnings, conservative historical attribution and read-only guarantees.
C9/C10 workload remains scheduled-duration accounting and is unchanged. Stages B–D implement automatic
allocation, reviewed session persistence and single-session lifecycle/rescheduling.
AI orchestration remains deferred.
