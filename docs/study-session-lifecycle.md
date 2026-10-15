# Stage D — StudySession Lifecycle and Single-Session Rescheduling

Stage D adds explicit authenticated lifecycle actions and safe transfer of one
upcoming reservation. Stage A–C and PF1/PF2/C9/C10 remain authoritative and
unchanged. No schema, status, background job, effort total or planning-history
table is added. A session's history is retained as ordinary lifecycle rows.

Stage E2 confirms adaptive one-to-one transfers and explicitly accepted unplaced
cancellations atomically, reusing Stage D's pure lifecycle/chain rules and scheduled
Reminder dependency Service. E2 uses only the exposed chain-locking helper inside
its own fresh outermost transaction; it never nests `run()`. Manual Stage D
contracts and future/terminal policies remain unchanged. See [adaptive replanning](adaptive-replanning.md#stage-e2-confirmation-contract).

F1/F2 manage absolute and relative reminders through the authenticated
[Reminder API](reminders.md). Relative offsets resolve from exact persisted UTC
anchors. F2 atomically reconciles Task/Subtask deadline and completion changes;
completion cancels invalidated reminders without changing StudySessions or other
work. The strict single-target dependency helper and existing S3/Stage D/E2 cleanup
policies remain intact. Session retirement never transfers/clones reminders to a
successor. F3a delivers due reminders to the in-app inbox under the same owner-first boundary.
Retirement committed first prevents delivery; delivery committed first preserves
sent Reminder and immutable inbox history. No successor notification is cloned.
Read state never alters work or sessions. External delivery and hard purge remain
unimplemented.

## Public endpoints

Stage E1 now provides separate read-only adaptive analysis and suggested intervals.
It creates no successor, cancels no reminder and changes no lifecycle state. The
manual Stage D contracts and history invariants below remain unchanged; see
[adaptive replanning](adaptive-replanning.md).

All endpoints use `auth:sanctum`, owner-scoped lookup and `StudySessionPolicy`.
Ownership is resolved before body validation and independently reloaded under
the transaction lock. GET list/detail and Stage C contracts remain unchanged.

| Method | Path suffix under `/api/study-sessions/{studySession}` | Route name | Success |
| --- | --- | --- | --- |
| POST | `/complete` | `study-sessions.complete` | 200 |
| POST | `/miss` | `study-sessions.miss` | 200 |
| POST | `/cancel` | `study-sessions.cancel` | 200 |
| POST | `/reschedule` | `study-sessions.reschedule` | 201 |

Completion accepts `{}` or `{"actual_minutes": 45}`. A supplied value must be a
genuine positive JSON integer, 1–65535 (the existing unsigned SMALLINT).
Omission stores null; explicit null, zero, floats, numeric strings, booleans,
arrays and objects return 422. Null preserves Stage A's scheduled-second fallback;
there is no rounding of the scheduled interval into integer minutes.

Miss/cancel accept `{}` only. Reschedule accepts exactly:

```json
{
  "starts_at": "2026-10-20T09:00:00Z",
  "ends_at": "2026-10-20T10:00:00Z"
}
```

Timestamps are real, exact UTC `YYYY-MM-DDTHH:MM:SSZ` values within MySQL DATETIME
years 1000–9999. Offsets (including `+00:00`), fractions, whitespace, normalized
invalid dates and ambiguous local input are rejected. End must exceed start.
POST validation reads the body, not query parameters. Unknown body fields are
rejected even when null, including ownership, work references, status, completion,
predecessor and reminder attributes. Existing exact-input middleware exemptions
prevent silent trimming or empty-string coercion.

Complete/miss/cancel return `data` containing the existing `StudySessionResource`:
`id`, `task_id`, `subtask_id`, UTC `starts_at`/`ends_at`, `duration_seconds`,
`status`, `completed_at`, `actual_minutes`. No owner/audit/graph fields are added.
Reschedule returns:

```json
{
  "data": {
    "original": {"id": 42, "task_id": 12, "subtask_id": null,
      "starts_at": "2026-10-20T08:00:00Z", "ends_at": "2026-10-20T09:00:00Z",
      "duration_seconds": 3600, "status": "rescheduled",
      "completed_at": null, "actual_minutes": null},
    "replacement": {"id": 43, "task_id": 12, "subtask_id": null,
      "starts_at": "2026-10-20T09:00:00Z", "ends_at": "2026-10-20T09:30:00Z",
      "duration_seconds": 1800, "status": "planned",
      "completed_at": null, "actual_minutes": null},
    "summary": {
      "original_duration_seconds": 3600,
      "replacement_duration_seconds": 1800,
      "not_transferred_seconds": 1800,
      "unallocated_work_seconds": 5400
    }
  }
}
```

`not_transferred_seconds` is original duration minus replacement duration.
`unallocated_work_seconds` is the exact unit's Stage A total reloaded after the
replacement is inserted in the transaction. It includes other estimates, completed
credit and current reservations; it is not necessarily the transfer shortfall.
No work is silently marked finished. Additional allocation uses Stage C preview
and confirmation.

## Transitions and effort

G1 [progress statistics](progress-statistics.md) reads the persisted result of these
actions without invoking them. Sessions belong to the requested local period by
original `starts_at`, with full scheduled seconds grouped by their current stored
status. Completed actual minutes come exclusively from `actual_minutes`; null is
reported as unknown, without Stage A's scheduled-credit fallback. Predecessors and
successors remain distinct records, while only completed rows supply known actual
time. Valid soft-deleted Task/Subtask references remain historical data under the
existing C9/Stage C ownership checks. G1 does not infer missed/completed states or
change session, reminder or inbox history.

All public actions require the current source state to be `planned`.

| Action | Time condition against captured UTC now | New state | Completion fields |
| --- | --- | --- | --- |
| complete | start ≤ now; late completion allowed | completed | whole-second `completed_at`; optional actual minutes |
| miss | end ≤ now | missed | both null |
| cancel | start > now | cancelled | both null |
| reschedule | original start > now; replacement start > now | rescheduled, plus new planned row | both null on both rows |

The action clock is captured with microseconds after lock contention. Interval
endpoints retain whole seconds. Completion floors its timestamp to a UTC second.
Manual cancellation rejects exact start equality; S3 deletion deliberately still
cancels `starts_at >=` its captured full-precision clock, including equality.
Neither policy is changed by the other.

Completed, missed, rescheduled and cancelled sources are terminal. Repeated or
incompatible public commands return 409 with no further writes. There is no
reopening, arbitrary-status PATCH, interval editing or historical actual-effort
overwrite. Explicit miss never creates a replacement. GET, preview and planning
never infer missed status.

Owned soft-deleted Task/Subtask references are legitimate history for completion,
miss and cancel. Exact Task/child/owner compatibility remains mandatory. None of
these actions changes estimates, Task/Subtask status, completion timing or progress.

Stage A continues to credit only completed rows: actual minutes × 60 when present,
otherwise the exact original scheduled seconds. C9 continues to count planned and
completed **scheduled** duration toward local daily/ISO-week workload. Completed
rows no longer block PF1 time or act as break anchors. Missed/cancelled/rescheduled
rows supply neither credit nor reservations nor workload. A planned successor
reserves its own duration and contributes workload once. No A1–A6 formula or C9
source filter has changed.

## Services and transfer validation

```php
StudySessionLifecycleService::complete(User $user, string $id, mixed $actualMinutes = null): StudySession;
StudySessionLifecycleService::miss(User $user, string $id): StudySession;
StudySessionLifecycleService::cancel(User $user, string $id): StudySession;
StudySessionReschedulingService::reschedule(User $user, string $id, mixed $startsAt, mixed $endsAt): array;
// original, replacement, summary
```

These Services accept a trusted application User, independently of HTTP auth
globals. The direct completion contract uses null for omitted actual effort;
HTTP deliberately rejects a supplied null. Invalid direct scalar arguments throw
`InvalidArgumentException`; Requests map malformed public input to 422.

`StudySessionLifecycleRules` supplies pure transition, timestamp, chain and summary
checks. `StudySessionMutationContextService` shares the transaction and source
locking boundary between the two application Services. Controllers remain thin.

Rescheduling preflights the owned source, compatible chain and affected reminders.
It changes only the source status to `rescheduled` inside the transaction, retaining
its interval and work attribution. Then `PlanningWorkSelectionService::select()`
recalculates effort after the old reservation is released. The exact original
Task/Subtask unit must be eligible: pending, active, known estimate, positive
unallocated effort and unexpired effective deadline. A decomposed parent is never
silently reassigned to a child. Deleted/completed targets conflict.

The requested duration cannot exceed either original duration or current unallocated
effort. `PlanningCandidateEvaluationService::evaluate()` supplies unchanged C10
ownership/source assembly and PF1/PF2 duration, availability, effective Lesson,
other planned-session, break, daily/ISO-week workload and earliest parent/child
deadline checks. The retired source is excluded naturally by C9 status selection;
it cannot block or count against its own replacement. Exact break/deadline equality
passes. Null constraints have no defaults, including no invented minute minimum.
C10's existing bounded local-date/query-envelope rules also apply.

One server-controlled replacement is inserted: trusted owner, identical Task/child,
requested UTC interval, planned status, immediate predecessor ID, null completion
and actual fields. Stage C's existing field-construction helper is reused. The
post-insert Stage A total is reported, and a final fresh-clock check rolls back if
the replacement has started during a slow operation. This final check supplements
the one captured source-action clock; no arbitrary future-start buffer is added.
All failures roll back the retired source, successor and reminder cancellation.

## Chain and reminder history

Connected predecessor/successor records are inspected in both directions. Visited
IDs guarantee termination on cycles. Before locking, incompatible ownership/work
edges fail closed; connected compatible rows are locked by ascending ID. Pure
validation prohibits self/cyclic references, missing predecessors, multiple direct
successors and attribution changes. Every predecessor must be rescheduled and have
exactly one direct successor; other states have none. A valid upcoming planned
successor can be transferred again, linking its immediate predecessor. Ancestors
are never rewritten. The existing unique predecessor key remains a final DB defense.

`ReminderDependencyService` extracts the compatible-target preflight shared with
S3 deletion. Scheduled reminders directly targeting the source are locked by
ascending ID, including potentially malformed foreign-owner references. Each must
be owned, have exactly one target, and have either null anchor/offset or compatible
`session_start` anchor with an integer offset. Unsafe relationships fail atomically.

Only these scheduled reminders become cancelled, with null `sent_at`. Targets,
message, trigger, anchor and offset remain unchanged. Sent/cancelled history,
Task/Subtask/general reminders and other sessions' reminders are preserved.
No reminder is cloned or redirected to the successor. BR-REM-005 still governs
edits to an existing deadline/session anchor. This operation retires the original
session identity without editing its anchor time; a new identity requires separately
defined reminder creation. It does not implement general trigger recalculation,
hard-purge reconciliation or delivery.

## Transactions, security and concurrency

Each action owns a fresh outermost `DB::transaction()` with one attempt, rejecting
ambient transactions. The first read locks the persisted User. Subsequent locks
follow User → historical/active parent Task → optional Subtask → connected
StudySessions ascending ID → affected scheduled Reminders ascending ID.
Cached route models, relations, status and user timezone are not trusted.

The initial locking read does not establish a MySQL repeatable-read consistent
snapshot. Ordinary source reads occur only after the prior owner-lock holder commits.
Cooperating Task/Subtask deletion/lifecycle, Lesson/import, preference/availability,
Stage C confirmation and Stage D mutations use the same owner-first protocol;
timezone UPDATE acquires the same User row lock. New rows and empty collections
are therefore protected without additional gap-lock protocols. Production isolation
is unchanged, and no automatic retry or network operation occurs inside transactions.

Separate-process MySQL tests run under REPEATABLE READ and inspect actual owner-row
waits through `performance_schema.data_lock_waits`. They cover competing completions,
completion/cancellation, competing transfers, deletion/transfer, actual Lesson
mutation, both directions of Stage C interaction, and visibility after rollback of
a written predecessor transition. Sequential requests alone are not the evidence.
The guarantee applies to cooperating application writers; arbitrary direct database
writers and administrative hard purges are outside it. A future clock advance after
commit cannot be prevented by a transaction.

| Status | Meaning |
| --- | --- |
| 401 | Unauthenticated, before validation/lookup |
| 404 | Missing/foreign/inaccessible explicitly targeted session |
| 422 | Malformed body, unsupported field or value |
| 409 | Terminal/time/eligibility/capacity/constraint conflict; refresh before another action |
| 500 | Generic unsafe source/reminder graph, SQL or internal failure |

Errors expose no foreign IDs, SQL, source details or private metadata, even in debug
mode. Corruption is never treated as ordinary no-capacity. Session reads remain
read-only and preserve the Stage C representation.

Single-session operations make two Stage A reads and one C10 evaluation for successful
transfers, with no candidate-search SQL loops. Chain discovery grows with the connected
history depth; there is no hidden iteration cap or unrelated whole-history graph load.
Long chains or large effort histories can hold the owner lock longer. No throughput
or protection from non-cooperating writers is claimed.

Bulk rescheduling, automated missed detection, session reopening/editing, Reminder
CRUD/cloning/delivery, automatic Task completion, reports, AI tools and frontend
remain deferred. Existing Stage C reviewed preview/confirmation stays independently
usable for all eligible unfinished work, including missed-session work.

## Verification and changed files

Starting local/remote main HEAD: `158eec4ced9b442686dc90653705bc6c8cd94111`.
Ending HEAD is the same. The initial working tree was clean; the final tree has
14 modified tracked files and 15 new files, all from this task, with nothing
staged, committed or pushed. No migration, enum, dependency or DB configuration
change was required. Remote verification used `git ls-remote` outside the sandbox
after its network restrictions prevented DNS resolution.

Tests use actual MySQL `student_planner_testing` and the existing configured/live
database guards. Mutation/concurrency Features use DatabaseTruncation with committed
fixture cleanup; Unit rules run without Laravel/database bootstrap. Tests ran
serially against the shared testing database. No SQLite or weakened baseline tests
were used.

| Final command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| Focused Stage D filter below | 0 | 303 | 1,543 |
| Required regression filter below | 0 | 2,059 | 16,915 |
| `vendor/bin/phpunit` | 0 | 3,150 | 26,141 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Focused command (includes eight separate-process contention tests):

```sh
vendor/bin/phpunit --filter='StudySessionLifecycleRulesTest|StudySessionLifecycleTest|StudySessionConcurrencyTest'
```

Required regression command:

```sh
vendor/bin/phpunit --filter='TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceTest|PlanningWorkSelectionServiceTest|StudyPlanningServiceTest|StudySessionAllocationServiceTest|WorkloadCapacityTest|StudyPlan|StudySessionApiTest|TaskLifecycleTest|SubtaskLifecycleTest|TaskSecurityTest|SubtaskSecurityTest|TaskDeletionTest|SubtaskDeletionTest|LessonLifecycleTest|LessonSecurityTest|ScheduleImport|StudySessionPersistenceTest|ReminderPersistenceTest|AuthProfileTest|PlanningPreferenceApiTest|StudyAvailabilityApiTest'
```

Development verification also ran `vendor/bin/phpunit tests/Unit/StudySessionLifecycleRulesTest.php`
(exit 0, 158 tests / 300 assertions), the initial concurrency subset (exit 0,
7 tests / 110 assertions), and the initial lifecycle Feature file three times:
exit 2 (125 tests / 634 assertions), exit 1 (125 / 1,030), then exit 0 (125 / 1,033).
Those early failures exposed a test snapshot helper's schema/key-order assumptions
and Laravel's cross-field date comparison throwing for array input. The helper
now uses the established current-schema snapshot convention; timestamps are
compared only after strict validation. Expanded final focused verification passed
after the post-insert effort reload and all implementation changes.

All 29 changed files were also audited for whitespace and local documentation-link
targets, including untracked files; zero errors. The final architecture audit
confirms unchanged Stage A formulas, Stage B allocation, C9 source policies, C10
diagnostics, Stage C protocol and existing session Resource representation.
Historical verification records and stable business-rule IDs remain intact.

| Changed file | Responsibility |
| --- | --- |
| `app/Exceptions/StudySessionConflictException.php` | Generic 409 domain conflict. |
| `app/Services/Planning/StudySessionLifecycleRules.php` | Pure transition, strict UTC, chain and summary checks. |
| `app/Services/Planning/StudySessionMutationContextService.php` | Fresh owner-first transaction and source/chain locks. |
| `app/Services/Planning/StudySessionLifecycleService.php` | Complete, miss, cancel and atomic reminder cleanup. |
| `app/Services/Planning/StudySessionReschedulingService.php` | Exact-unit transfer, Stage A/C10 reuse and successor insertion. |
| `app/Services/Reminders/ReminderDependencyService.php` | Shared compatible-target preflight and session reminder cleanup. |
| `app/Services/Tasks/TaskDependencyCleanupService.php` | Reuse extracted preflight, retaining S3 deletion behavior/errors. |
| `app/Http/Requests/StudySessionActionRequest.php` | Owned authorization, body-only input and unknown-field rejection. |
| `app/Http/Requests/CompleteStudySessionRequest.php` | Strict optional positive actual minutes. |
| `app/Http/Requests/RescheduleStudySessionRequest.php` | Canonical UTC endpoints and safe positive-interval comparison. |
| `app/Http/Resources/StudySessionReschedulingResource.php` | Original/replacement Resources and partial summary. |
| `app/Http/Controllers/Api/StudySessionController.php` | Thin adapters for four actions, preserving reads. |
| `app/Policies/StudySessionPolicy.php` | Owned mutation authorization with 404 denial. |
| `bootstrap/app.php` | Generic rendering of the new 409 conflict. |
| `routes/api.php` | Four authenticated POST routes. |
| `tests/Unit/StudySessionLifecycleRulesTest.php` | Transition matrix, precision, input, graph and summary tests. |
| `tests/Feature/StudySessionLifecycleTest.php` | API/security, planning, reminder, history and rollback coverage. |
| `tests/Feature/StudySessionConcurrencyTest.php` | Actual MySQL contention and committed/rollback visibility. |
| `tests/Support/study_session_mutation_worker.php` | Independent guarded PDO/process for contention tests. |
| `docs/study-session-lifecycle.md` | Stage D contracts, guarantees, limits and actual verification. |
| `docs/architecture.md` | Stage D architecture and current implementation scope. |
| `docs/domain-model.md` | Session state/history and reminder identity semantics. |
| `docs/business-rules.md` | Stable rule IDs with explicit Stage D enforcement. |
| `docs/database-schema.md` | Current application enforcement and actual-minute omission semantics. |
| `docs/database-review-notes.md` | Resolve stale current-baseline statements, retaining draft history. |
| `docs/tasks.md` | Shared locking and manual/deletion cancellation distinction. |
| `docs/planning-integration.md` | Current Stage C/D consumers of unchanged C9/C10 policies. |
| `docs/planning-work-selection.md` | Current Stage C/D consumers, unchanged A1–A6. |
| `docs/study-plan-persistence.md` | Separate Stage D consumer, unchanged Stage C contracts. |

**Stage D acceptance: complete.** There is no material deviation from D1–D5 or
the single-session transfer/reminder policy. The fresh outermost transaction,
explicit-null rejection, final replacement future check and depth-dependent
chain cost are documented contract details. Guarantees cover cooperating writers;
general reminder behavior, hard purge, bulk workflows and automatic lifecycle
inference remain deferred.
