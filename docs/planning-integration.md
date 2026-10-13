# Planning Integration — C9 / C10

C9 V1 policies are developer-approved. C10 implements the read-only
`App\Services\Planning\PlanningCandidateEvaluationService`, integrating existing
MySQL persistence with unchanged PF1 and pure C1–C8. The original local proposal
is preserved as historical context in [the approved policy](planning-integration-proposal.md).
No new HTTP endpoint, AI Tool or write operation is added.

Stage B now extracts C10 source assembly into `PlanningContextService`, preserving
`evaluate()` and the approved policies below. C10 supplies its original candidate
anchor envelope; Stage B supplies the requested local-date envelope. Stage B adds
previously accepted in-memory proposals to free-time, break and workload inputs
before C8 validation. Repeated C10 evaluation alone still sees persisted state.
See [automatic study planning](automatic-study-planning.md).

[Stage C](study-plan-persistence.md) now reuses these sources for independent batch
revalidation inside owner-locked confirmation. C10 diagnostic behavior is unchanged;
new persistence resides in a separate application Service.

## Public contract

```php
public function evaluate(
    User $user,
    array $candidate,
    mixed $taskId = null,
    mixed $subtaskId = null,
): array;
```

The application caller supplies a trusted User, never a public owner ID.
`$candidate` is exactly `['start' => int, 'end' => int]`, using half-open UTC
Unix seconds. Optional IDs must be genuine positive integers, without numeric
string, boolean or float coercion. A child ID requires the explicit parent ID.
C1 validates increasing endpoints and representable integer duration. Candidate
and expanded query boundaries must fit canonical UTC DATETIME years 1000–9999.
Arrays remain unchanged; repeated evaluation of unchanged state is deterministic.

Return C8's unique ordered violation codes unchanged:

1. `below_minimum_session_duration`
2. `above_maximum_session_duration`
3. `outside_available_study_time`
4. `overlapping_break_anchor`
5. `insufficient_break_before`
6. `insufficient_break_after`
7. `daily_study_limit_exceeded`
8. `weekly_study_limit_exceeded`
9. `deadline_exceeded`

`[]` means no violation of these assembled inputs. It does not establish
remaining work, work eligibility, batch feasibility or permission to persist.
See [the pure constraint contract](planning-constraints.md).

## Trusted ownership and source fields

Reload the supplied User's identity with `id` and persisted `timezone` through
its Eloquent query. Cached attributes/relationships and authentication globals
cannot override the trusted owner. All personal sources are queried anew through
`planningPreference()`, `studyAvailabilityWindows()` (inside PF1), `lessons()`,
`studySessions()` and `tasks()`. Optional child targets use the exact owned
Task's `subtasks()` relationship. No foreign target is loaded globally.

Read exactly the five typed nullable preference fields:
`min_session_minutes`, `max_session_minutes`, `preferred_break_minutes`,
`max_daily_study_minutes`, `max_weekly_study_minutes`. A missing row produces
exactly five nulls, without materializing preferences or inventing defaults.
The pure Services retain configuration validation.

Active work targets mean non-soft-deleted Tasks/Subtasks; completed work may
be inspected diagnostically. C10 does not impose future creation eligibility
or use estimates/statuses to derive remaining effort. Task-only evaluation uses
its nullable `deadline_at`. For a specified child use the earlier non-null
parent/child deadline, either sole present value or null. End equality passes;
one second later yields `deadline_exceeded`. Legacy child deadlines cannot weaken
a parent deadline; the existing write-side deadline invariant is unchanged.

## Calendar horizon and UTC boundaries

Candidate start and `end - 1` determine covered local dates in the persisted
IANA timezone. Labels on both sides of any intervening offset transitions are
also considered, preserving unusual historical date-line reversals. The nominal
inclusive minimum/maximum label envelope may contain at most 31 dates. Count
calendar dates encoded in UTC solely as labels, not UTC elapsed duration divided
by 86400. An exclusive local-midnight endpoint adds no extra date. Skipped date
labels remain part of the nominal envelope while contributing no real instants.

No current-clock check occurs: historical candidates are valid diagnostics.
C10 itself remains historical-capable; Stages B–D apply their documented write-side clocks without changing diagnostic evaluation.

Expand the first/last covered dates to their nominal ISO Monday/Sunday labels.
Use the existing C2 `AvailabilityOccurrenceService` with all seven full-day
`00:00:00–24:00:00` definitions to find complete real UTC week membership. Its
first start/last end provide the workload query envelope. This handles Kyiv
23/25-hour days, ISO week-year changes, ambiguous/skipped midnights, skipped dates
and non-hour offsets without fixed 604800-second weeks or automatic midnight
normalization. Workload envelopes cover at most 42 nominal dates for the approved
31-date candidate span. Arithmetic and serialized query bounds are checked.

## Workload selection — approved policy A

Select current-owner StudySession records with status `planned` or `completed`,
using strict overlap with the complete relevant week envelope:

```text
starts_at < queryEndUtc AND ends_at > queryStartUtc
```

An earlier/later session on the same local date or another day in its ISO week
can exhaust a budget. Sessions starting before a query boundary and ending
inside it are included. Querying only candidate overlap or only start timestamps
would undercount. Missed/rescheduled/cancelled rows are excluded. There is no
current-time or active-parent filter; historical planned/completed rows count.

Retrieve only `id`, `task_id`, `subtask_id`, `starts_at`, `ends_at`. Parse raw
original DATETIME endpoints explicitly in UTC with exact format round-trip
validation, preserving whole seconds independently of Eloquent presentation
casts or PHP's default timezone. Pass each selected database row once and its
full original interval to C7; do not clip, merge, or deduplicate equal intervals
from distinct records. C7 splits actual elapsed seconds into candidate-local
days/weeks and adds overlapping records independently. `actual_minutes` and
`completed_at` are not fetched or substituted for scheduled duration; actual
effort remains a separate statistics concern.

## Break selection — approved policy B

Anchors are owned active non-soft-deleted Lessons and owned planned sessions.
Active replacements participate; cancelled/replaced originals and deleted
Lessons do not. No Lesson type, Subject, Teacher, Room or AcademicPeriod
exemption exists. Completed/missed/rescheduled/cancelled sessions are excluded.

For configured `preferred_break_minutes`, multiply by 60 in integers and extend
both candidate bounds by that elapsed-second buffer, checking overflow and UTC
DATETIME representation before queries. Null gives a zero buffer, with no default.
Query strict overlap with the expanded envelope; preceding/following anchors may
be outside the candidate's local day or PF1 availability. The entire approved
1–65535-minute range is supported, with no smaller hidden cap. Anchors exactly
at gap equality can be omitted because they cannot violate C6.

Lesson reads fetch only raw endpoints. Planned-session reads fetch identity,
work reference keys and endpoints for integrity validation. Preserve original
anchor endpoints and delegate merging, overlap and separation checks to C6.
Exact elapsed separation of 900 seconds satisfies a 15-minute break; 899 fails.
Adjacency and overlap remain distinct. Null removes separation requirements
but retains C6 overlap checks.

PF1 continues subtracting effective Lessons and planned sessions. C10 calls
`FreeTimeService::calculate($user, $startDate, $endDate, null)` and passes its
returned intervals directly to C8. The optional PF1 minimum-slot filter is not
a configured candidate minimum. PF1 reserves no break time; its API is unchanged.
Overlap may therefore produce both `outside_available_study_time` and
`overlapping_break_anchor`, in C8's existing order.

## Integrity and errors

Foreign/nonexistent/deleted targets use owned `whereKey()->firstOrFail()` and
throw Laravel `ModelNotFoundException` without record IDs/details; HTTP adapters
may use Laravel's standard generic 404 handling. A child of another parent has
the same not-found outcome. Invalid direct arguments, unsafe timestamps/ranges,
malformed persisted DATETIME values and invalid configuration throw
`InvalidArgumentException`; no HTTP response is built in the Service.

Database FKs do not prove same-owner session/work compatibility. For the union
of workload and planned-anchor session IDs, batch-query the owned Tasks with
`withTrashed()`, and eager-load only referenced Subtasks with `withTrashed()`.
Validate every selected session's owned Task and exact child parent. Missing,
foreign or incompatible source references throw the same generic
`UnexpectedValueException('Invalid planning source relationships.')`. Future
adapters should map source corruption to a generic internal/domain failure,
without exposing IDs or details. Never turn it into an empty violation result,
skip the invalid row, fetch foreign metadata, or repair persistence.

Owned soft-deleted work references are legitimate historical sources and remain
countable. A row appearing in workload and anchor queries is validated once by
ID but can serve both roles. Its workload contribution is still supplied once.
Session predecessor graph reconciliation and arbitrary corrupt metadata beyond
selected fields are outside this diagnostic scope.

## Read-only guarantees, query bounds and limitations

Evaluation performs SELECTs only. It creates no preferences, sessions, reminders,
results or caches; changes no intervals, work statuses or Lesson lifecycle;
performs no cleanup, locks, write-side transaction or hidden repair. MySQL tests
compare all table snapshots and inspect SQL after repeated evaluations.

Queries use existing `(user_id, status, starts_at)` indexes on Lessons/sessions.
The strict `ends_at` predicate still requires filtering. There is no migration,
new index, cache, source truncation or whole-history load. Ownership validation
uses batches rather than per-session lazy loading. Tests compare query counts
with one and 41 relevant sessions across different work parents/children.
Bounded time selection does not guarantee few scanned/returned rows; no latency
or throughput target is claimed. Full intervals of exceptionally long persisted
sessions may still require substantial pure C7 processing.

Separate reads are advisory and not an atomic current-state snapshot. A future
writer must reload/revalidate current owner, work, preferences, sources and
candidate constraints under an appropriate owner-first transaction/lock strategy
before committing. Accepted in-transaction batch candidates must also contribute
to later validation. Diagnostic success cannot authorize a later stale write.

Stage A now implements [work selection and remaining effort](planning-work-selection.md)
separately, without changing C10. Stages C/D implement transactional acceptance, session persistence/lifecycle,
single-session rescheduling and REST adapters. Reminder delivery, AI adapters
and LLM orchestration remain deferred. Future product defaults and write-side creation
rules remain separate decisions. C10 stops at evaluation.

Stage B implements advisory splitting/allocation and rejects new past starts
relative to Stage A's captured clock, with no additional lookahead beyond the
requested 31-date maximum. C10 historical diagnostics remain unchanged. Stages C/D implement session
persistence/lifecycle and transactional acceptance separately from the unchanged
read-only Services.

## Verification

The MySQL Feature suite covers policy/source selection, genuine integer validation,
foreign and malformed references, scheduled-vs-actual workload, complete local
weeks, exact break boundaries including the maximum configuration, raw UTC and
server timezone independence, Kyiv gaps/folds, 31/32-date horizons, historical
and deleted-work diagnostics, ordered violations, repeated read-only snapshots
and bounded query counts. Focused/full regression results are recorded in the
C9/C10 task report; unchanged C1–C8 retain their existing regression suites.

### C9/C10 checkpoint verification record

Starting and final HEAD: `27ff86e7df1bb0de1382aa52bb8dc00571299e4b`.
Initial working tree: only `?? docs/planning-integration-proposal.md`; the original
proposal was found and preserved verbatim in its historical section. No commit
was created. Tests use MySQL `student_planner_testing`; the test bootstrap checks
the effective connection and live database before RefreshDatabase migrations.
PHP 8.5.11, Composer platform requirements and loaded required extensions pass.
The sandbox initially blocked MySQL access; authorized runs outside the sandbox
passed without changing test configuration or using the development database.

| Test filter | Baseline tests / assertions | Final tests / assertions |
| --- | ---: | ---: |
| TimeIntervalServiceTest | 75 / 733 | Unchanged; included in full regression |
| AvailabilityOccurrenceServiceTest | 89 / 433 | Unchanged; included in full regression |
| FreeTimeServiceTest | 72 / 639 | 72 / 639 |
| FreeSlotsApiTest | 86 / 1121 | 86 / 1121 |
| SessionDurationConstraintServiceTest | 71 / 151 | 71 / 151 |
| BreakConstraintServiceTest | 95 / 291 | 95 / 291 |
| WorkloadConstraintServiceTest | 101 / 276 | 101 / 276 |
| PlanningConstraintServiceTest | 137 / 260 | 137 / 260 |
| PlanningCandidateEvaluationServiceTest | Not present | 138 / 843 |
| Full suite | 2178 / 20301 | 2316 / 21144 |

All listed runs pass. Baseline and final Pint pass. `git diff --check` passes;
an additional audit covers all changed/untracked files for whitespace and local
link targets and confirms the original proposal text remains intact.

- **Checkpoint 0:** passed before application changes.
- **C9:** approved policies A–E explicit, stable rule IDs preserved, existing
  fields/enums aligned, write-side policies deferred, links/whitespace passed.
- **C10-A:** strict input assembly, persisted ownership/timezone, nullable
  preferences, horizon and effective deadlines implemented.
- **C10-B:** bounded workload/anchor queries, batch source integrity and unchanged
  PF1/C8 delegation implemented; no C1–C8 fixes were needed.
- **C10-C:** 138 MySQL Feature tests / 843 assertions passed, including read-only
  SQL/table snapshots and query-count stability with larger source collections.
- **C10-D:** all required focused and full regressions, Pint and final audit passed.

**C9 acceptance: complete. C10 acceptance: complete.** Work stops at C10.
Automatic planning, candidate/session persistence, rescheduling and AI
orchestration remain outside the implemented capability.
