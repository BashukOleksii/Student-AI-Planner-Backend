# Stage A — Remaining Effort and Planning Work Selection

Stage A implements read-only work accounting and selection for the Stage B
planner. It does not allocate time, split sessions, persist plans, add endpoints,
change work lifecycle/progress, or orchestrate an LLM. Existing PF1/PF2 and
[C9/C10](planning-integration.md) behavior remains unchanged.

Stage B is now implemented in [automatic study planning](automatic-study-planning.md).
It consumes this unchanged A1–A6 contract, including unallocated seconds,
effective deadlines, deterministic ordering and the full-precision `as_of_utc`
clock. Exclusions and warnings pass through unchanged; proposed sessions exist
only in memory. Historical Stage A verification below remains its original record.

## Service contracts

```php
RemainingEffortService::calculate(
    mixed $estimatedMinutes,
    array $completedSessions,
    array $futureReservations,
): array;

PlanningWorkSelectionService::select(
    User $user,
    mixed $taskId = null,
    mixed $subtaskId = null,
): array;
```

`RemainingEffortService` is pure PHP: no database, Eloquent models, clock,
authentication or HTTP dependency. `mixed` preserves the conceptual nullable
integer estimate contract while preventing scalar coercion in non-strict callers,
matching existing planning Services. Estimates/actual minutes are null or genuine
positive integers whose multiplication by 60 fits a PHP integer. MySQL fields
already bound persisted minutes to 1–65535; the pure arithmetic contract additionally
supports other representable positive integers without imposing product defaults.

Completed inputs are a list of exactly
`['scheduled_seconds' => int, 'actual_minutes' => ?int]` records. Reservation
inputs are a list of exactly `['scheduled_seconds' => int]` records. Scheduled
seconds must be positive genuine integers, even when actual minutes supply credit.
Lists contain each intended database row once; distinct equal/overlapping source
records are independent. The application caller must classify statuses, ownership,
work attribution, current time and deadline validity before reservation inputs.
Neither input is changed. Malformed shapes/types, nonpositive values and multiplication
or aggregate overflow throw `InvalidArgumentException`, without float arithmetic.

The pure result has exactly six fields:

| Field | Type | Meaning |
| --- | --- | --- |
| `estimated_seconds` | `?int` | Configured estimate × 60, or null. |
| `completed_credit_seconds` | `int` | Actual-first completed credit, including any excess over the estimate. |
| `remaining_work_seconds` | `?int` | `max(0, estimated - completed)`, or null when estimate is unknown. |
| `future_reserved_seconds` | `int` | Sum of supplied valid scheduled reservations, separately from completion. |
| `unallocated_work_seconds` | `?int` | `max(0, remaining - reserved)`, or null. |
| `over_reserved_seconds` | `?int` | `max(0, reserved - remaining)`, or null. |

Known completed/reserved totals remain available even when the estimate is missing.
No estimate-derived field substitutes zero for a genuinely unknown estimate.

## Application output

`select()` accepts the trusted application User, never a payload owner ID. Optional
Task/Subtask IDs must be genuine positive integers. A child ID requires its explicit
parent. Task selection restricts work to that parent; child selection returns only
that active unit, preserving the parent context for attribution/integrity.

```php
[
    'as_of_utc' => '2026-10-12T10:20:30.500000Z',
    'work_items' => [
        [
            'task_id' => 12,
            'subtask_id' => 34, // null for Task-level work
            'work_type' => 'subtask', // or 'task'
            'effective_deadline_utc' => 1792400400, // ?int UTC Unix seconds
            'priority' => 3, // inherited parent priority: 1, 2 or 3
            'position' => 2, // ?int; null for Task-level work
            'effort' => [
                'estimated_seconds' => 3600,
                'completed_credit_seconds' => 900,
                'remaining_work_seconds' => 2700,
                'future_reserved_seconds' => 900,
                'unallocated_work_seconds' => 1800,
                'over_reserved_seconds' => 0,
            ],
        ],
    ],
    'excluded_items' => [],
    'warnings' => [],
];
```

There are exactly four top-level keys, and all three collections are lists.
Work-item keys are exactly those shown. `effort` contains the pure six-field result.
Excluded planning units have the same fields plus `reason_codes: list<string>`.
A decomposed parent is represented as an excluded container with `effort: null`:
its estimate is not another planning unit. In explicit-child mode that unrequested
parent container is omitted. Deleted work is not enumerated as current work;
relevant linked history is diagnosed separately.

Warnings have exactly `code: string`, `task_id: int`, `subtask_id: ?int`,
`study_session_id: ?int`, and `seconds: int`. Session warnings identify an owned
source and its scheduled duration (completed ambiguity/deletion warnings use
actual-first credit). Aggregate excess warnings have a null session ID and the
excess seconds. No titles, descriptions, subjects or foreign records are returned.

## Approved policies A1–A6

- **A1 — Estimate authority:** a Task without non-deleted children is its own unit.
  Any non-deleted children, including completed ones, replace parent authority.
  Pending children use their own estimates; missing estimates never fall back to
  the parent. Completed parent Tasks exclude their children even when pending;
  completed children are excluded independently. No statuses or progress change.
- **A2 — Completion:** only `completed` rows supply credit. Positive actual minutes
  take precedence over scheduled duration; null actual uses exact original
  `ends_at - starts_at` seconds. All relevant history counts without a date-window
  or current-time filter. Distinct overlapping rows contribute independently.
  Credit exceeding estimate is retained, with remaining effort clamped at zero.
- **A3 — Reservations:** only owned, correctly attributed `planned` sessions with
  `start >= captured now` and `end <= effective deadline` (when present) reduce
  unallocated work. A start already past, including within the captured second or
  with an end still future, is stale and contributes zero reservation. Completed
  parent/child work is not a valid reservation target. Equality with a configured
  deadline passes. There is no future lookahead cap. Missing-estimate pending units
  retain known valid reserved seconds but still cannot be selected for new planning.
- **A4 — Ambiguity:** parent-level completed/planned records are not reassigned to
  children after decomposition. Records attached to deleted children are not
  reassigned to a surviving child or newly authoritative Task. Both produce coded
  warnings with exact source credit/duration. Valid deleted references are history,
  not corruption. Stale/deadline/complete-target diagnostics can coexist with these
  attribution warnings.
- **A5 — Partial eligibility:** eligible units require pending work, a known positive
  estimate, positive unallocated seconds and a deadline strictly after captured
  now, if configured. Missing-estimate siblings do not exclude valid children.
  Completed, credit-satisfied, fully reserved and expired work is excluded explicitly.
  Metrics describe estimate/credit accounting even when lifecycle status independently
  excludes work; they do not infer completion or reopen completed work.
- **A6 — Order:** effective UTC deadline ascending; null deadlines last; parent
  priority descending; Task ID ascending; child position ascending; Subtask ID
  ascending. A child's deadline is the earliest non-null parent/child value, either
  sole value or null. Parent priority is inherited. Explicit no-deadline work remains
  eligible. Existing Task-list presentation order is unchanged.

Eligible and excluded lists use this deterministic comparator. Within exclusions,
Task containers use null position/child ID as zero tie-breakers. Warning order is
Task ID, child ID (null first), session ID (null first), then code lexically.
For a unit, exclusion reasons appear in the table order below; all applicable
reasons are retained, except fully reserved is used only when unfinished effort
is positive. Decomposed-parent reasons are `subtasks_authoritative`, followed by
`completed_task` when applicable.

## Reason and warning vocabulary

| Exclusion code | Meaning |
| --- | --- |
| `subtasks_authoritative` | Parent container estimate is superseded by current children. |
| `completed_task` | Task lifecycle is completed. |
| `parent_task_completed` | Child is blocked by completed parent lifecycle. |
| `completed_subtask` | Child lifecycle is completed. |
| `missing_estimate` | Planning-unit estimate is null. |
| `deadline_expired` | Effective deadline is at or before full-precision captured now. |
| `completed_effort_satisfies_estimate` | Completed credit covers the estimate. |
| `fully_reserved` | Positive unfinished effort is entirely covered by valid reservations. |

| Warning code | Meaning |
| --- | --- |
| `ambiguous_parent_session_attribution` | Parent source cannot be assigned to authoritative children. |
| `session_targets_deleted_subtask` | Retained deleted-child source is not current work. |
| `stale_planned_session` | Session has already started; no reservation credit. |
| `reservation_deadline_exceeded` | Planned end exceeds effective deadline; no reservation credit. |
| `reservation_targets_completed_work` | Planned source references completed Task/child; no reservation credit. |
| `completed_effort_exceeds_estimate` | Raw completed credit exceeds estimate. |
| `future_reservation_exceeds_remaining_work` | Valid reservations exceed unfinished effort. |

Ambiguity is diagnostic and does not discard valid sibling credit or forbid other
otherwise eligible units. Missed/rescheduled/cancelled rows contribute neither
completion nor reservations and are not read as accounting sources; elapsed time
never infers a status transition. `actual_minutes` is not read as planned effort.

## Ownership, source queries and integrity

Reload the trusted persisted User identity (`id`) before owned reads, ignoring
cached attributes/relationships and authentication globals. Timezone is not needed
for elapsed UTC effort accounting. Load current non-deleted owned Tasks, including
completed ones for exclusions, and batch-load their children through `subtasks()`
with deleted history for authority and reference validation. Only required fields
are selected; presentation data and planning preferences are not read.

Read owned planned/completed StudySessions matching either selected Task IDs or
any of those Tasks' child IDs, including deleted children. The child-side OR
catches a corrupted source whose Task points elsewhere but whose child touches
selected work. Each database row is retrieved once, without joins or interval
merging. Explicit child selection validates the full selected parent's source
context, then suppresses unrelated sibling diagnostics/accounting; parent-level
ambiguity still matters. Unrelated/deleted Tasks and their source history are not
loaded as current planning work. There is no date cutoff on required completed history.

Validate every retrieved session owner and exact Task/child compatibility against
fresh owned maps before attribution. No global foreign model lookup occurs.
Invalid relevant references throw the generic
`UnexpectedValueException('Invalid planning source relationships.')`; never drop
a selected corrupt row, return partial work, leak private identifiers, or repair
references. Invalid arguments/stored timestamps/effort values throw
`InvalidArgumentException`. Missing, foreign, deleted or mismatched explicit
work targets throw `ModelNotFoundException` without IDs, using existing generic
owned not-found conventions. Non-array arguments violate PHP array parameter types.
No HTTP-specific responses or validation envelopes are constructed.

Parse raw original UTC DATETIME values with exact whole-second format round-trip
checks and years 1000–9999. C1 validates session intervals/duration; the pure effort
Service guards minute conversion and sums. A single `CarbonImmutable::now('UTC')`
instant is captured before reads and retained with microseconds for all temporal
comparisons. Do not floor it to Unix seconds before classifying reservations.
DST, server timezone and user presentation timezone cannot change elapsed seconds.
Repeated selection with unchanged data and captured time returns identical results;
as time advances, `as_of_utc` and temporal eligibility naturally change.

Queries remain bounded in number rather than per-record lazy reads: four SELECTs
for a non-empty typical selection (User, Tasks, children, sessions), independent of
collection size. Existing Task/child/session indexes support owned work selection;
no schema changes, cache or truncation occurs. All required completed history must
be counted, so memory/processing and query row counts can still grow with current
work and its history. Explicit Task/child targeting reduces work context. Corruption
outside the selected source context is not a global database audit.

## Read-only boundary and Stage B consumer

Selection executes SELECTs only: no transaction/locks, status changes, cleanup,
preferences, sessions, reminders, results or hidden repair. Tests inspect SQL and
all-table snapshots. The result is advisory, not an atomic commit authorization.

Stage B consumes positive `effort.unallocated_work_seconds`, the effective
UTC deadline and deterministic work order to allocate slots through existing
PF1/C10 constraints. Stage A does not prove slot feasibility or independently
revalidate existing reservations against availability, overlaps, breaks or workload
limits: its reservation validity is the approved ownership/attribution/status/start/
deadline contract. Stage B must determine actual feasible whole-second slots;
a future writer must revalidate current work/accounting/constraints under an
appropriate owner-first transaction and lock strategy before persistence.

C9 workload remains planned + completed **scheduled** duration in local days/weeks,
including historical planned rows. Stage A completed credit is actual-first, and
stale plans do not reserve unallocated work. These are different measures; neither
changes PF1 blocking or C10 workload/break behavior.

Still deferred: session creation/lifecycle, transactional
plan acceptance, rescheduling, reminder delivery, REST/AI adapters and LLM calls.
Historical attribution needs an explicit future user/data decision; Stage A never
redistributes it. Whole rescheduling-graph validation and retrospective estimate/
credit reconciliation are outside this selection checkpoint.

## Verification

Pure arithmetic/shape/overflow tests run without application or MySQL bootstrap.
Estimate authority, statuses, attribution, eligibility, deadlines and order are
covered by real MySQL Feature tests because they depend on persisted lifecycle
and relationships. No extra pure framework or duplicate policy layer is added
solely to label those scenarios Unit tests. Feature coverage includes full-precision
clock changes during reads, all five historical/future statuses, owned deleted
history, foreign/corrupted references, reversed database retrieval order, larger
collections, read-only snapshots and explicit compatibility with C10 scheduled
workload. Final command results follow.

### Final verification record

Starting/local/remote main HEAD: `642dcb717c675897b030fd724c42864c07dccead`.
Ending local HEAD is unchanged. Initial working tree was clean, with no unrelated
changes to preserve. No commit/push, schema migration, package, endpoint or
existing planning algorithm change was made.

Environment: PHP 8.5.11, required extensions (including PDO MySQL), and Composer
platform requirements pass. Tests use MySQL `student_planner_testing`; the existing
TestCase validates the effective connection and live database before destructive
RefreshDatabase operations. MySQL runs used authorized execution outside the
sandbox; the development database was not used.

| Executed command | Exit | Tests | Assertions | Outcome |
| --- | ---: | ---: | ---: | --- |
| `php artisan test` before changes | 0 | 2316 | 21144 | Pass |
| `php artisan test --filter=RemainingEffortServiceTest` | 0 | 68 | 120 | Pass |
| `vendor/bin/phpunit tests/Unit/RemainingEffortServiceTest.php` | 0 | 68 | 120 | Pass without application/database bootstrap |
| `php artisan test --filter=PlanningWorkSelectionServiceTest` (final) | 0 | 90 | 601 | Pass on MySQL |
| Combined regression command below | 0 | 865 | 5340 | Pass |
| `php artisan test` after implementation | 0 | 2474 | 21865 | Pass |
| `vendor/bin/pint --test` | 0 | — | — | Pass |
| `git diff --check` | 0 | — | — | Pass |
| `composer check-platform-reqs` | 0 | — | — | Pass |
| `php -v` / `php -m` | 0 | — | — | Version/extensions verified |

```bash
php artisan test --filter='TaskLifecycleTest|SubtaskLifecycleTest|TaskProgressTest|TaskDeletionTest|SubtaskDeletionTest|StudySessionPersistenceTest|FreeTimeServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|TaskSecurityTest|SubtaskSecurityTest|WorkloadConstraintServiceTest|BreakConstraintServiceTest|SessionDurationConstraintServiceTest|FreeSlotsApiTest'
```

Early new Feature runs failed (exit 2, then exit 1) while implementation was in
progress: Support Collection/Eloquent Collection handling, a deleted-Task fixture
factory lookup, and initial-versus-reloaded model snapshot shape. These were
corrected; final focused and full results above pass without weakening assertions
or altering existing tests/Services.

The query fixture measured four SELECTs with one Task/child/source and four with
31 Tasks, 31 children and 61 sources. No latency/throughput claim is made.
An additional final audit covers whitespace and local file links in every changed
and untracked file, preserves the archived C9 proposal verbatim, retains stable
business-rule IDs, and verifies no schema/routes or existing Services changed.

**Stage A acceptance: complete.** All functional, architectural, security and
verification gates pass. Stages B/C remain future allocation/persistence work,
not incomplete Stage A accounting or selection.
