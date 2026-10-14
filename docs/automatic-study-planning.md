# Stage B — Deterministic Automatic Study Planning

Stage B generates advisory StudySession proposals entirely in memory. It uses
Stage A's approved A1–A6 work accounting/order and the existing PF1/PF2/C9
policies. It is independent of an LLM. There is no schema change, endpoint,
session writer, cache entry, reminder, planning history or lifecycle mutation.

[Stage C](study-plan-persistence.md) now exposes preview and explicit transactional
confirmation through separate Services/REST adapters. Stage B itself remains
unchanged and read-only. Stage C regenerates under the owner lock, compares the
reviewed result and validates the full batch before any persistence. The Stage B
design and historical verification record below retain their checkpoint meaning.

Stage E2 now confirms E1's one-to-one allocator results through a separate
owner-first atomic persistence Service. Stage B generation/splitting remains
unchanged; unfinished work released by accepted unplaced cancellation can use
fresh Stage C planning. See [adaptive replanning](adaptive-replanning.md#stage-e2-confirmation-contract).

## Public application contract

Stage E1 reuses the pure allocator with an optional fourth `singlePlacement`
boolean (default false). True permits one largest feasible placement per work
request and disables balancing for a second session. Default Stage B splitting,
ordering, diagnostics and read-only behavior remain compatible. E1 separately
maintains shared work budgets and complete-batch validation; see
[adaptive replanning](adaptive-replanning.md).

```php
StudyPlanningService::plan(
    User $user,
    string $startDate,
    string $endDate,
    mixed $taskId = null,
    mixed $subtaskId = null,
): array;
```

The caller supplies a trusted application User. Both dates must be exact, real
`YYYY-MM-DD` calendar labels, increasing and containing at most 31 inclusive
local dates. Interpret them using the reloaded persisted IANA timezone, ignoring
cached attributes/relationships and authentication globals. Never extend the
requested date horizon. Missing preferences mean five null constraints, without
creating a preference row or inventing defaults.

Identifiers are null or genuine positive PHP integers. Strings, floats, booleans,
zero, negatives and arrays are rejected without scalar coercion. A Subtask ID
requires its explicit Task ID. Owned Stage A selection resolves the targets and
provides generic not-found failures for missing, foreign, deleted or incorrectly
nested work. There is no change to the suggested public signature.

The returned `as_of_utc` is Stage A's one captured microsecond-precision clock.
The first permitted integer-second start is its ceiling, not its floor. All
time classifications and availability clipping use that instant even if the
application clock advances during source reads. Requested historical time is
removed; a wholly historical range returns no proposals. Expired work retains
Stage A exclusions, without historical allocation or status repair.

## Architecture and shared context

`StudyPlanningService` orchestrates date validation, persisted identity/timezone
reload, Stage A selection, trusted context loading and pure allocation.
`StudySessionAllocationService` owns slot search and projected state, using plain
arrays without Laravel, Eloquent, database or clock dependencies.

`PlanningContextService` extracts the existing C10 source assembly. C10's public
`evaluate()` signature, ordered C8 diagnostics, historical-candidate behavior and
C9 policies are preserved. Its container constructor now receives the shared
context Service. This is a focused extraction, not a new persistence abstraction.

```php
PlanningContextService::load(
    User $user,
    string $startDate,
    string $endDate,
    ?array $anchorEnvelope = null,
): array;
```

The context is an internal trusted application contract. Callers must supply the
fresh persisted User, as C10 and Stage B do. It returns:

| Field | Representation |
| --- | --- |
| `range` | Full requested local-date UTC envelope `{start: int, end: int}`, or null for a wholly skipped local date. |
| `timezone` | Persisted IANA name. |
| `preferences` | Exactly the five nullable configured minute fields. |
| `free_intervals` | PF1 chronological allowed-free-time union. |
| `break_anchors` | Original effective Lesson/planned-session intervals. |
| `workload_sessions` | Original planned/completed scheduled intervals, each source record once. |

Use C2 full-day membership expansion for the date range and the complete nominal
ISO Monday–Sunday week envelope. Do not resolve ambiguous local midnights or
assume fixed day/week lengths. A 31-date horizon can require at most 42 local date
labels for workload source loading. Query strict UTC overlap, including sources
starting before a boundary and ending inside it.

Stage B uses the full date envelope, padded by the configured elapsed break, for
anchor selection. C10 passes its original candidate envelope, preserving its
narrower source selection exactly. The entire approved break range is supported.
Active non-deleted Lessons, including active replacements, and planned sessions
are anchors. Completed/missed/rescheduled/cancelled sessions are not anchors.
Planned and completed sessions contribute scheduled workload; actual minutes and
completion timestamps do not replace scheduled intervals. Distinct equal or
overlapping workload records remain additive. History on soft-deleted owned work
remains valid. No source is filtered by parent completion or current time in C9.

PF1 remains authoritative for recurring availability and effective blockers:
`FreeTimeService::calculate($user, $startDate, $endDate, null)`. Its optional
minimum-slot filter is not used as a session preference. Its public API and source
policies remain unchanged. The shared context preserves C10's separate PF1 and
anchor/workload reads, rather than introducing a second availability algorithm.

For selected workload and anchor sessions, batch-load owned Tasks and referenced
Subtasks with deleted history. Fail closed on foreign or incompatible parents or
children, using `UnexpectedValueException('Invalid planning source relationships.')`.
Do not load foreign metadata, drop corrupt rows or repair them. Raw original
DATETIME values are round-trip validated as whole-second UTC, independent of
presentation casts and server timezone. Expanded query boundaries must fit MySQL
UTC DATETIME years 1000–9999; unsafe boundaries fail explicitly.

## Pure allocation contract and algorithm

```php
StudySessionAllocationService::allocate(
    array $workItems,
    array $context,
    mixed $notBeforeUtc,
): array; // sessions, work_results, summary
```

`$workItems` is the Stage A eligible list in its authoritative order. Each unit
has its original Task/Subtask IDs, nullable effective UTC deadline and positive
integer `effort.unallocated_work_seconds`. Units must be unique. The cutoff must
be a genuine integer. Inputs are not mutated. Invalid context/configuration,
intervals, work shapes or overflowing arithmetic throw `InvalidArgumentException`.
The pure API does not establish ownership; the application Service does.

Work order is effective deadline ascending (null last), priority descending,
Task ID ascending, child position ascending, then Subtask ID ascending. The
allocator preserves that Stage A order rather than sorting again. For each unit:

1. Clip future PF1 free capacity to the captured clock and requested horizon.
2. Subtract the normalized anchors padded on both sides by the configured break.
3. Clip each chronological free interval by the effective deadline.
4. Find the earliest start capable of receiving a minimum-sized valid session.
5. Extend it to the largest feasible duration bounded by remaining effort,
   interval/deadline end, configured maximum and residual daily/weekly budgets.
6. Optionally rebalance a short final tail as described below.
7. Validate with unchanged `PlanningConstraintService::evaluate()` (C8).
8. Accept the proposal into projected state and repeat until work is allocated
   or no valid placement remains. Continue with the next unit in either case.

Free intervals normalize through C1, so retrieval order, duplicate/adjacent
availability and anchor ordering cannot change the result. Proposals are emitted
in **work order, then chronological placement order within that unit**. The full
list need not be chronological: a later unit can fit an earlier remaining gap.

This is deterministic earliest-feasible greedy allocation. It does not reorder
work or globally backtrack, optimize total allocation or prove mathematical
impossibility. Repeating identical inputs with a frozen clock produces identical
arrays. A later strategy may improve allocation without changing constraints.

## Boundary search, duration and termination

There is no per-second scan of a 31-day horizon and no hidden iteration cap.
`WorkloadConstraintService::availableCapacity()` exposes residual budgets and
constant-offset/local-day segments using the same C7 validation, calendar
portions and additive totals as `evaluate()`. Its signature is:

```php
availableCapacity(array $range, array $existingSessions, string $timezone,
    mixed $maxDailyMinutes = null, mixed $maxWeeklyMinutes = null): array;
// segments: [{start, end, day, week, seconds}], days: label => ?int, weeks: label => ?int
```

Null budgets are unconfigured; exhausted or excessive persisted workload permits
zero additional seconds in that period. Existing excessive workload in unrelated
periods does not prevent a candidate elsewhere. C7 `evaluate()` behavior remains
unchanged.

For a minimum-sized sliding session, each day's/week's contribution is affine
between segment boundaries and those boundaries shifted by the minimum duration.
Its slope is -1, 0 or +1 elapsed second. Solve the integer capacity inequalities
within each such region to find the earliest feasible start, including interior
starts where both region endpoints can fail. This avoids discarding a free
interval merely because its first start fails. Once a start is selected, advance
through chronological segments until duration or a daily/weekly budget is spent.
Repeated local labels across unusual transitions share the same totals.

Configured minimum/maximum minutes become exact integer seconds. Null maximum
permits the full available feasible duration, including a session crossing days
or weeks. Null minimum requires only the positive whole-second interval invariant;
it adds no implicit whole-minute session length. Configured equality passes.

If a largest candidate would leave a positive tail below minimum, reduce it to
leave exactly one minimum-sized session **only if** the reduced candidate remains
valid and an actual second minimum-sized placement exists in projected state,
including its break/workload/deadline cost. Otherwise retain the larger valid
candidate and report the exact tail. This focused lookahead does not promise
globally optimal fragmentation.

Every accepted session subtracts a positive integer duration from remaining work
and removes occupied capacity. Each search visits a finite set of event/calendar
boundaries. Failed search ends allocation for that unit. The algorithm therefore
terminates without silently discarding work through a safety limit.

## Projected sessions and constraint consistency

Each accepted proposal is applied exactly once:

- subtract its interval from projected free time;
- append its interval to projected break anchors;
- append its scheduled interval to projected workload records.

Subsequent C8 validation uses those arrays, not repeated persisted C10 calls.
Generated proposals participate regardless of Task/Subtask attribution. Configured
breaks apply both within a unit and between units, in elapsed UTC seconds; exact
gap equality passes. Null break retains overlap protection. Padded search
boundaries are only a search aid: C6/C8 remains the acceptance authority.

Workload is original scheduled duration in persisted user-local days and ISO
week-years. Midnight, spring gaps, autumn folds, non-hour offsets and skipped
dates use C7 real elapsed membership. Neither 24-hour days nor 168-hour weeks are
assumed. Completed actual effort may reduce Stage A work differently from its
scheduled workload contribution; stale plans may stop reserving Stage A effort
while still blocking PF1 and counting toward C9 workload.

Each accepted candidate passes C8 against all earlier proposals. Containment and
duration are unchanged by later allocation, overlap/break relations are symmetric,
and additive workload can only increase to the same complete-batch totals checked
for later candidates touching those periods. This preserves batch consistency.
Tests also revalidate every returned candidate against **all** other proposals,
excluding only itself, plus original persisted sources. A violated allocator
invariant throws `LogicException` rather than returning an invalid plan.

No proposal ends after its effective parent/child deadline; exact end equality is
valid. No proposal starts before the captured clock, extends outside the requested
range or occupies unavailable time. Excluded Stage A units are never allocated.

## Result schema and partial planning

```php
[
    'as_of_utc' => '2026-10-12T08:00:00.500000Z',
    'start_date' => '2026-10-12',
    'end_date' => '2026-10-12',
    'timezone' => 'UTC',
    'sessions' => [
        ['task_id' => 12, 'subtask_id' => null, 'start' => 1791795600,
         'end' => 1791797400, 'duration_seconds' => 1800],
    ],
    'work_results' => [
        ['task_id' => 12, 'subtask_id' => null, 'requested_seconds' => 3600,
         'allocated_seconds' => 1800, 'unscheduled_seconds' => 1800,
         'reason_codes' => ['daily_study_limit_exceeded', 'planning_horizon_exhausted']],
    ],
    'excluded_items' => [],
    'warnings' => [],
    'summary' => ['requested_seconds' => 3600, 'allocated_seconds' => 1800,
                  'unscheduled_seconds' => 1800, 'fully_allocated' => false],
];
```

The example assumes a 30-minute residual daily budget. All four collections are
lists. Session fields are exactly those shown, with correct work attribution and
positive exact integer `end - start` duration. Work-result fields are exactly
those shown and retain Stage A work order. Every item and the summary satisfy
`requested_seconds = allocated_seconds + unscheduled_seconds`. Requested means
Stage A **unallocated** effort, not the original estimate or existing reservations.
Summary allocation equals the sum of proposal durations.

`excluded_items` and `warnings` are passed through from Stage A without changing
their fields, meaning or ordering. Summary totals cover eligible requested work
only: an empty eligible list has zero totals and `fully_allocated: true`, even
when exclusions exist. Empty capacity with eligible work instead has positive
unscheduled seconds and `fully_allocated: false`. Complete units have no reasons.

For a subminimum final remainder, report only `below_minimum_session_duration`:
no workload or horizon search is necessary. Otherwise perform diagnostic C8 probes
at the remaining chronological raw free-interval starts, clipped to the deadline,
using up to the minimum/remainder/interval size. The codes report violations
**observed by those probes**, not universally binding explanations for every
possible placement. Do not interpret them as a minimal set of global blockers.

| Reason code | Evidence and precise meaning |
| --- | --- |
| `below_minimum_session_duration` | Remainder itself, or an inspected free fragment, is below configured minimum. |
| `insufficient_break_before` / `insufficient_break_after` | A diagnostic candidate fails the corresponding C6 elapsed gap. |
| `overlapping_break_anchor` | A diagnostic candidate overlaps an anchor; relevant to explicitly supplied pure contexts. |
| `daily_study_limit_exceeded` / `weekly_study_limit_exceeded` | A diagnostic candidate would exceed that local period's projected scheduled limit. |
| `no_available_study_time` | No positive unoccupied future PF1 seconds remain before the effective deadline; this alone says nothing about alternative rearrangements. |
| `deadline_capacity_exhausted` | Greedy search failed and the effective deadline removes some otherwise unoccupied future free seconds. Not a proof that the deadline alone prevents completion. |
| `planning_horizon_exhausted` | No additional valid placement was found for a minimum-sized remainder in this bounded horizon and projected greedy state. Does not mean every date/second was occupied. |

C8 codes retain their established order before the three search-level codes.
The generic ordering also retains `above_maximum_session_duration`,
`outside_available_study_time` and `deadline_exceeded` if observed by C8; current
valid clipped diagnostic probes do not normally emit these. No unsupported global
optimality or impossibility claim is returned. A future consumer should say that
this algorithm left work unallocated under the supplied constraints.

## Security, read-only guarantees and future Stage C

All persisted personal reads use the trusted owned Eloquent relationships.
Explicit work validation comes from Stage A; shared context validates selected
source integrity consistently with C10. No private foreign identifiers, titles
or metadata enter results or integrity exceptions. Corruption outside selected
source context is not a global audit. Lesson presentation metadata and session
rescheduling graphs are outside the established C9 source checks.

Planning issues SELECTs only. It never locks records, creates transactions for
future persistence, writes caches, cancels reservations or repairs malformed data.
Feature tests inspect query types/ownership predicates and compare all-table
snapshots after repeated planning, including unchanged C10 diagnostics.

Separate reads do not provide an atomic database snapshot. The result is advisory
and immediately subject to concurrent changes. Stage C must reload/revalidate
current identity/timezone, work effort, preferences, Lessons, reservations and
constraints under an appropriate transaction/locking strategy before persisting
any batch. Earlier accepted batch candidates must contribute exactly once to
subsequent checks there too. A Stage B proposal never authorizes a stale write.

## Performance and limitations

MySQL queries occur during selection/context loading, never during inner slot
search. Batch relationship validation keeps query count independent of collection
or proposal count. The test fixture with workload history uses 14 SELECTs for
both 5 and 100 proposals across 1 and 20 eligible Tasks; empty-source branches can
use fewer. No row truncation or hidden proposal limit is introduced.

Search depends on interval/calendar boundaries, not every elapsed second. However,
projected arrays, interval normalization, C8 validation and workload aggregation
are revisited as proposals grow. Runtime can grow quadratically with proposal
count; very many short sessions or extensive Stage A completed history can use
substantial CPU/memory. Full unusually long persisted intervals also increase
C7 processing. This version makes no latency/throughput guarantee. Future
optimization should retain the same pure acceptance semantics and regression
oracles rather than add silent caps.

Greedy order and focused tail rebalancing can leave work unallocated where a
different global arrangement could fit more. Read-only source assembly is not an
atomic snapshot, timezone behavior depends on installed IANA data, and persistence,
rescheduling, HTTP/AI adapters and Stage C remain outside Stage B.

## Verification record

Starting/local/remote `main` HEAD: `6b04b297c0ae0453d3129607070760ec590f9dce`.
The remote was verified using HTTPS `git ls-remote`; the configured SSH remote
failed due to local SSH configuration ownership. Ending HEAD is unchanged; no
commit or push was requested. The initial tree already contained the five Stage B
Service changes and three new test files. Those changes were reviewed and retained,
with diagnostics, public documentation and additional regression coverage completed.
No unrelated working-tree changes were found or discarded.

Tests use real MySQL `student_planner_testing`. The existing TestCase validates
the configured driver, database name and live database before RefreshDatabase.
Pure Unit suites run without Laravel/database bootstrap. Coverage includes exact
contracts, Stage A exclusions/warnings, malformed IDs/dates, source corruption,
cross-user isolation, reversed database order, repeated read-only snapshots,
query counts, duration/break equality, integer-second tails, partial allocation,
complete-batch C8 revalidation, Kyiv DST, midnight and ISO-week-year boundaries.
Small exhaustive C8 test oracles independently verify event-based earliest starts
and largest feasible durations; exhaustive scanning is limited to tests.

All final gates passed. Earlier focused runs also passed: the initial draft had
164 tests / 1,291 assertions; added oracle/diagnostic coverage had 197 / 1,453
before the final boundary/security integration additions. No failed test was
deleted or weakened. The only failed baseline command was SSH remote inspection;
the HTTPS fallback verified the same main HEAD successfully.

| Executed command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit tests/Unit/StudySessionAllocationServiceTest.php tests/Unit/WorkloadCapacityTest.php tests/Feature/StudyPlanningServiceTest.php` (final) | 0 | 202 | 1533 |
| `vendor/bin/phpunit --filter` with the planning filter below | 0 | 1022 | 5468 |
| `vendor/bin/phpunit --filter` with the extended filter below | 0 | 1241 | 7958 |
| `vendor/bin/phpunit` (complete suite) | 0 | 2676 | 23398 |
| `vendor/bin/pint` with the five changed Service and three test paths | 0 | — | — |
| `vendor/bin/pint --test` (repository-wide) | 0 | — | — |
| `git diff --check` | 0 | — | — |

Planning regression command:

```bash
vendor/bin/phpunit --filter 'TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceTest|PlanningWorkSelectionServiceTest'
```

Extended regression command:

```bash
vendor/bin/phpunit --filter 'TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceTest|PlanningWorkSelectionServiceTest|TaskLifecycleTest|SubtaskLifecycleTest|TaskDeletionTest|SubtaskDeletionTest|StudySessionPersistenceTest|TaskSecurityTest|SubtaskSecurityTest|AuthProfileTest|LessonSecurityTest|AcademicContextSecurityTest'
```

Final working tree: nine modified tracked files and seven new untracked files.
Nothing is staged, committed or pushed. All changes belong to Stage B:

| Changed file | Purpose |
| --- | --- |
| `app/Services/Planning/StudyPlanningService.php` | Read-only application orchestration and public contract. |
| `app/Services/Planning/StudySessionAllocationService.php` | Pure projected allocator and structured partial diagnostics. |
| `app/Services/Planning/PlanningContextService.php` | Shared trusted C9 source assembly. |
| `app/Services/Planning/PlanningCandidateEvaluationService.php` | Delegate unchanged public evaluation to shared context. |
| `app/Services/Planning/WorkloadConstraintService.php` | Expose residual budgets using shared C7 portions/totals. |
| `tests/Unit/StudySessionAllocationServiceTest.php` | Determinism, splitting, boundaries, projected batch validity and independent oracles. |
| `tests/Unit/WorkloadCapacityTest.php` | Residual-budget arithmetic and calendar compatibility. |
| `tests/Feature/StudyPlanningServiceTest.php` | MySQL integration, ownership/integrity, read-only snapshots and bounded queries. |
| `docs/automatic-study-planning.md` | Stage B contract, design, limitations and verification report. |
| `docs/architecture.md` | Current Stage B architecture and persistence boundary. |
| `docs/domain-model.md` | Transient Planning Result implementation. |
| `docs/business-rules.md` | Projected constraint enforcement and greedy partial-result semantics. |
| `docs/planning-foundation.md` | Stage B use of unchanged PF1. |
| `docs/planning-constraints.md` | Residual-budget API and projected PF2 use. |
| `docs/planning-integration.md` | Shared context extraction and unchanged C10 diagnostics. |
| `docs/planning-work-selection.md` | Consumption of unchanged Stage A A1–A6 contracts. |

Stage B acceptance status: **complete**. No specification deviation requiring a
new product decision was needed. The optional focused context/allocator components
and clarified reason vocabulary follow the permitted architecture. Stage C, HTTP
exposure and persistence remain unimplemented by design. Historical approved
policies, ADRs and verification records are retained.
