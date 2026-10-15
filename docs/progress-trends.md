# Stage G2a — Read-only daily progress trends

G2a adds deterministic daily activity using exactly the source and metric rules
from [G1 progress statistics](progress-statistics.md). It preserves BR-STAT-001–005
and G1's existing endpoint/response. No migration, historical snapshot, stored
aggregate, cache, chart, export, period comparison or AI integration is introduced.

## Endpoint and request

```http
GET /api/statistics/trends?start_date=2026-10-01&end_date=2026-10-31
Accept: application/json
```

The route is `statistics.trends`, protected by `auth:sanctum`. Only GET/HEAD reads
are registered. `ProgressTrendsRequest` inherits `ProgressStatisticsRequest`:

- Both dates are required query fields, exact real `YYYY-MM-DD` calendar labels.
- The inclusive range is ordered and contains at most 31 nominal dates.
- No whitespace trimming, timestamp formats, date arrays or nonexistent leap dates.
- Unknown query fields are rejected, including `aggregation=day`, owner/timezone,
  status, IDs, pagination and computed totals. Daily aggregation is fixed.
- GET bodies are ignored and cannot provide missing dates or override the query.

The Service independently validates dates for trusted non-HTTP callers. The
current user comes from trusted application context; mutable User attributes and
relationships are reloaded, including the persisted IANA timezone.

401 precedes validation. Malformed requests receive Laravel 422 field errors.
Source corruption, invalid persisted timezone/timestamps, SQL and internal failures
withhold the entire result and return only HTTP 500 with
`{"message":"The progress statistics could not be processed."}`. This shares
G1's secure statistics renderer, including with debug enabled; no private source
identifiers, metadata, SQL or exception traces enter the response.

## Stable response

Laravel's normal `data` envelope contains `as_of_utc`, `timezone`, `period`, `days`
and `totals`. The clock uses UTC with six fractional digits. The period contains
exactly `start_date`, `end_date`, `aggregation: "day"`, `session_attribution: "starts_at"`.

Every requested date has one bucket in ascending calendar-label order. There are
no missing zero days or duplicate labels. This one-day example shows all fields;
a longer request includes the identical bucket shape for every nominal date.

```json
{
  "data": {
    "as_of_utc": "2026-10-15T12:00:00.123456Z",
    "timezone": "Europe/Kyiv",
    "period": {
      "start_date": "2026-10-01",
      "end_date": "2026-10-01",
      "aggregation": "day",
      "session_attribution": "starts_at"
    },
    "days": [{
      "date": "2026-10-01",
      "tasks_completed": {"total": 0, "on_time": 0, "late": 0, "without_deadline": 0},
      "subtasks_completed": {"total": 0, "on_time": 0, "late": 0, "without_deadline": 0},
      "study_sessions": {
        "planned": {"count": 0, "scheduled_seconds": 0},
        "completed": {"count": 0, "scheduled_seconds": 0},
        "missed": {"count": 0, "scheduled_seconds": 0},
        "rescheduled": {"count": 0, "scheduled_seconds": 0},
        "cancelled": {"count": 0, "scheduled_seconds": 0},
        "completed_actual_minutes": 0,
        "completed_with_actual_minutes": 0,
        "completed_without_actual_minutes": 0
      }
    }],
    "totals": {
      "tasks_completed": {"total": 0, "on_time": 0, "late": 0, "without_deadline": 0},
      "subtasks_completed": {"total": 0, "on_time": 0, "late": 0, "without_deadline": 0},
      "study_sessions": {
        "planned": {"count": 0, "scheduled_seconds": 0},
        "completed": {"count": 0, "scheduled_seconds": 0},
        "missed": {"count": 0, "scheduled_seconds": 0},
        "rescheduled": {"count": 0, "scheduled_seconds": 0},
        "cancelled": {"count": 0, "scheduled_seconds": 0},
        "completed_actual_minutes": 0,
        "completed_with_actual_minutes": 0,
        "completed_without_actual_minutes": 0
      }
    }
  }
}
```

Every metric leaf is a nonnegative integer count, second total or minute total.
There are no scores, grades, percentages, forecasts or guessed values. Totals are
the exact sums of the corresponding daily leaves. Checked integer accumulation
rejects unrepresentable totals, negative values and noninteger metric input instead
of coercing or emitting floats.

## Daily attribution and metric definitions

| Population | Source and date attribution |
| --- | --- |
| Tasks | Owned, active/non-soft-deleted, currently completed; persisted `completed_at` belongs to the day. |
| Subtasks | Active/non-soft-deleted, currently completed, with an active owned parent; own persisted `completed_at` belongs to the day. |
| StudySessions | Owned sessions of all five persisted statuses; original `starts_at` belongs to the day. Compatible deleted work remains valid history. |

Task and Subtask metrics are separate; no combined parent/child count is added.
Parent completion does not imply child completion. Each uses its **own** persisted
deadline: non-null `completed_at <= deadline_at` is on time, `>` is late, and null
deadline is `without_deadline`. Equality is on time, and the three categories sum
exactly to `total`. Planning's effective parent/child deadline inheritance is not
used. Neither `updated_at` nor a status alone invents a completion event.

Each session contributes its **full** original `ends_at - starts_at` elapsed UTC
seconds to its start-date status bucket. A session crossing midnight is not split
or clipped; one starting before the period is excluded even if it overlaps it.
A start at an exclusive upper boundary is excluded. All five statuses remain
independent; elapsed time never implies missed/completed state.

Only completed sessions contribute `completed_actual_minutes`, summing non-null
persisted `actual_minutes`. They also increment exactly one of
`completed_with_actual_minutes` / `completed_without_actual_minutes`. Those two
counts sum to the completed-session count. Null means unknown, not zero actual
work and not scheduled-time substitution. Actual 25 minutes in a scheduled hour
means 25 actual minutes and 3,600 scheduled seconds in separate fields.

Rescheduled predecessors and successors are distinct persisted sessions, grouped
by their own original starts and current statuses. Their scheduled time is never
represented as independent completed effort. Stage A effort fallback, C9 workload,
planning, lifecycle and Reminder/inbox writers are unchanged.

## Calendar membership and snapshot semantics

Nominal calendar labels are iterated in UTC solely to visit each date once. Every
label's actual membership comes from the existing
`PlanningContextService::dateIntervals(date, date, persistedTimezone)` and its
half-open UTC interval union. Assignment uses G1's shared `contains()` predicate.
There is no server-timezone inference, fixed 24-hour day, ambiguous-midnight
resolution, or broad-envelope approximation.

A skipped local date such as Apia 2011-12-30 remains an explicit zero bucket.
Repeated/disjoint historical date occurrences, including Juneau 1867-10-18, all
contribute to one bucket for that label; gaps do not belong to that date. Kyiv
spring/autumn days preserve 82,800/90,000 elapsed seconds and both fold occurrences.
Installed IANA timezone data remains authoritative.

Raw DATETIME values are parsed by G1's existing strict UTC parser. Real date labels
0001–9999 are accepted; shared query membership intersects UTC DATETIME storage
bounds 1000–9999. Labels outside stored UTC membership remain zero. The upper
storage boundary preserves `9999-12-31 23:59:59` without sending a five-digit SQL
boundary to MySQL. Timezone offsets can move storage-edge instants to another label.

The Service opens a transaction containing only nonlocking reads under the current
MySQL REPEATABLE READ convention. Its first SELECT reloads the persisted owner and
establishes the coherent snapshot; one full-precision UTC clock is captured next.
A concurrent committed change during the report does not split the daily metrics
or totals. The next request sees the new state. No isolation setting or row lock is
introduced. Ambient trusted callers inherit their existing transaction snapshot;
READ COMMITTED deployments would lose the cross-query consistency guarantee.

Each daily bucket describes completion/session activity visible in **this current
persisted snapshot**, not the historical state displayed on that date. Reopened
work loses its visible completion; recompletion uses the new timestamp. Soft-deleted
Tasks/Subtasks and children under deleted parents are excluded from work activity.
Changed deadlines can reclassify earlier completions. Immutable status/deadline
history is absent, so historical pending/overdue/completed populations and original
deadlines cannot be reconstructed. Compatible historical sessions on deleted work
remain visible; hard-purged history cannot be recovered.

## Shared G1 implementation and reconciliation

`ProgressStatisticsService::loadActivity()` now owns the bounded Task/Subtask
completion queries, session query and existing batch historical-reference validation
for both endpoints. `activityMetrics()`, `completionInstant()`, completion/session
reducers, strict UTC parsing, membership and storage-boundary predicates are shared.
G1's existing public constructor, pure helper signatures and HTTP contract remain
available. Count increments also use its existing checked addition routine.

`ProgressTrendsService` adds only daily calendar construction, grouping and checked
summing. It does not call G1's complete report endpoint or fetch lifetime current
counts. Each source record is assigned at most once, then each bucket delegates to
the shared G1 reducers. Totals are summed from those actual daily buckets.

For one unchanged snapshot and authoritative clock:

| G2a total, equivalently sum of its daily leaves | G1 field |
| --- | --- |
| `tasks_completed` | `tasks.completed_in_period` |
| `subtasks_completed` | `subtasks.completed_in_period` |
| `study_sessions` (every status/count/duration and actual-time field) | `study_sessions.started_in_period` |

G1 `current` metrics are lifetime populations and are not compared with daily sums.
Separate requests with concurrent writes may legitimately differ. Frozen-clock
MySQL tests compare all corresponding fields on unchanged datasets, including DST,
historical date-line membership, storage boundaries and actual lifecycle history.
A complete integration scenario asserts **every** bucket plus totals after real
Task/Subtask completion, session completion with known actual minutes, and session
rescheduling into a different date with a planned successor.

## Security, read-only guarantees and query strategy

`auth:sanctum` provides trusted owner context; every data query uses owned relations
or active-parent ownership predicates. No client owner/timezone is accepted. G1's
existing `PlanningContextService::validateSessionReferences()` batch validates owned
historical Tasks and exact Task/Subtask attribution, including deleted history.
Selected foreign/wrong-parent references fail the whole response for every status;
no foreign metadata is loaded or exposed. Unrelated session history outside the
exact requested membership is not globally audited. Rescheduling graphs are not
recursively audited, matching G1 session read conventions.

The bounded sources are fetched once with set-based queries, with no SQL inside
daily or per-record loops. G2a uses **four SELECTs with empty session data, at most
six with valid session history**: fresh owner, Task completions, Subtask completions,
start-attributed sessions, and up to two historical-reference batches. G1 retains
its six/eight SELECT behavior including its two current lifetime aggregates.

Tests measure six SELECTs for one-day and 31-day reports, and for one versus 151
Tasks/Subtasks/sessions. SQL inspection confirms owned predicates, completion/start
range filters, no lifetime current aggregate, no per-day fetch and no row locks.
At most 31 calendar buckets are built. Grouping checks at most 31 exact date
memberships per source; reductions process each assigned record once. Runtime is
linear in selected records with the fixed 31-date ceiling; memory is linear in
selected activity/referenced work plus bucket data. There is no artificial record
cap, extra full-period fetch, lifetime history load, cache or reporting index.
Existing indexes may scan more owned rows than they return; no throughput/latency
guarantee is claimed.

Statistics never modify work, sessions, reminders, notifications, plans, timezone
or account state. No jobs, domain delivery operations, AI/network calls, cached
aggregates or snapshots are dispatched/persisted. Tests inspect SELECT-only SQL,
compare exact all-table snapshots after repeated reads and after corrupt-source
failures, and assert no jobs/notifications/HTTP calls. Actual lifecycle integration
preserves cancelled Reminder and sent/unread inbox state. The independent MySQL
connection test commits changes after the first completion query, advances the
clock mid-report, and proves consistent old results followed by a new snapshot.

Existing framework authentication-session maintenance is unchanged and remains
outside both statistics Services. Tests retain the array session driver. Deploying
with database-backed stateful sessions may write authentication housekeeping rows;
G2a does not suppress or redefine that behavior.

## G2b and remaining boundaries

Period comparisons, immutable historical snapshots, historical daily pending or
overdue counts, original-deadline reconstruction, forecasting/scores, subject
analytics, reports/exports, scheduled generation, frontend charts, AI explanation/
tools, Reminder/notification analytics and additional aggregation modes remain
unimplemented. G2b must define comparison/report semantics explicitly. Existing
missing actual minutes and mutable work-history limitations remain visible rather
than being replaced by invented values.

## Verification and changed-file review

Starting and ending HEAD: `427cadc3ef90514821376c8dbf54103ffc72c460`.
The initial working tree was clean. The final scope is seven modified tracked
files and eight new files (15 total), all unstaged; nothing is committed or pushed.
No models, enums, factories, migrations, dependencies, auth/database configuration,
calendar algorithms, planning or lifecycle/Reminder/inbox writers are changed.

| Changed file | Responsibility |
| --- | --- |
| `app/Http/Controllers/Api/ProgressTrendsController.php` (new) | Thin authenticated HTTP orchestration. |
| `app/Http/Requests/ProgressTrendsRequest.php` (new) | Inherit G1's exact bounded query contract. |
| `app/Http/Resources/ProgressTrendsResource.php` (new) | Stable public daily response. |
| `app/Services/Progress/ProgressTrendsService.php` (new) | Snapshot, exact daily grouping and checked totals. |
| `app/Services/Progress/ProgressStatisticsService.php` | Share G1 source selection, activity reduction and membership helpers; checked count increments. |
| `routes/api.php` | One authenticated GET/HEAD trends route. |
| `bootstrap/app.php` | Extend G1 exact-input and secure exception conventions to trends. |
| `tests/Unit/ProgressTrendsServiceTest.php` (new) | 24 pure calendar, classification, duration, actual-time, totals/overflow and immutability cases. |
| `tests/Feature/ProgressTrendsApiTest.php` (new) | 63 guarded MySQL API/ownership/history/calendar/query/read-only cases. |
| `tests/Feature/ProgressTrendsIntegrationTest.php` (new) | Two real lifecycle/reconciliation and independent-connection snapshot cases. |
| `docs/progress-trends.md` (new) | Contract, behavior, limits and actual verification. |
| `docs/progress-statistics.md` | Shared G1/G2a helpers and unchanged G1 contract; original verification retained. |
| `docs/architecture.md` | Daily Service/read snapshot and query strategy. |
| `docs/business-rules.md` | Daily enforcement under preserved BR-STAT-001–005 identifiers. |
| `docs/domain-model.md` | Derived daily view without new persistence entities or historical populations. |

New G2a coverage comprises **89 tests**: 24 pure Unit tests and 65 guarded real
MySQL Feature/integration tests. All 102 existing G1 tests remain unchanged.
Database tests use `student_planner_testing` with the existing configured/live
MySQL guards; no SQLite, skipped coverage or weakened baseline tests are used.
API tests use RefreshDatabase. Integration tests use DatabaseTruncation/committed
fixtures, so actual Stage D and Reminder writers execute real outermost transactions.
The second connection verifies its live database name before committing its fixture
changes. Pure Unit tests do not bootstrap Laravel or a database.

| Verification command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit tests/Unit/ProgressTrendsServiceTest.php` | 0 | 24 | 119 |
| `vendor/bin/phpunit --filter='ProgressTrends\|ProgressStatistics'` | 0 | 191 | 1,775 |
| Affected regression filter below | 0 | 1,955 | 13,987 |
| `vendor/bin/phpunit` | 0 | 4,117 | 34,173 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Exact affected regression command:

```sh
vendor/bin/phpunit --filter='Progress|TaskLifecycleTest|TaskProgressTest|SubtaskLifecycleTest|RemainingEffortServiceTest|StudySession|StudyPlan|StudyReplanning|TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|WorkloadConstraintServiceTest|PlanningCandidateEvaluationServiceTest|SecurityTest'
```

Final focused, affected-regression and full-suite durations: 7.009, 99.081 and
219.307 seconds. No tests were skipped. The regression filter includes G1/G2a, Task/Subtask lifecycle and progress, Stage A
remaining effort, planning/session Stages C/D/E1/E2, ownership and timezone/interval
coverage. The full suite additionally verifies the remaining constraints,
work-selection, Reminder/inbox, persistence and separate-process concurrency tests.

Reconciliation compares every activity field with G1 across normal, DST,
historical-date-line and storage-edge datasets. The real lifecycle fixture asserts
all three daily buckets and totals after completed parent/child work, actual
25-minute session completion and rescheduling to a planned next-day successor.
The independent writer fixture reopens work and changes a session after the first
activity SELECT; all remaining report reads retain the old snapshot and captured
microsecond clock, while the next calculation observes the committed changes.

Query inspection measures **six SELECTs** for one-day/31-day ranges and one versus
151 records in each population. Repeated reads, failed corrupt-source reads and
post-lifecycle reads preserve exact all-table snapshots. No row locks, business
writes, queued work, notifications or HTTP calls occur during the reads. Delivered
unread inbox state and historical cancelled Reminder state remain unchanged.

Development verification: the first combined pure run passed 50 / 229 (26 existing
G1 plus 24 new cases). A sandboxed database attempt failed to connect (exit 2),
then authorized MySQL execution reported 189 tests / 1,692 assertions, exit 1,
with two fixture failures. One parent deadline edit crossed a child's 12:00:01
bound; the fixture now clears child deadlines before narrowing the parent, leaving
the application invariant intact. One-day query-count fixtures retained the factory's
October 5 session default; they now explicitly place sessions on October 15 in both
compared ranges. No assertion or production rule was relaxed. Expanded integration
coverage passed 191 / 1,774, exit 0. A result-immutability assertion and clearer
snapshot-clock fixture produced the final 191 / 1,775 result.

Final architecture and changed-file review confirms one shared definition of each
metric/source rule, unchanged G1 API/query behavior, no new persistence or writer,
no per-day database reads, preserved BR-STAT IDs and historical verification records,
and the documented G2b boundary.


**Stage G2a acceptance: complete.** All fourteen criteria pass for authenticated
daily buckets, shared G1 completion/session definitions, exact known/unknown actual
time, local-date membership, no invented historical statuses, G1 reconciliation,
constant owner-scoped queries, read-only verification, regression/full-suite, Pint,
whitespace and documentation checks. G2b comparisons/reports, immutable historical
snapshots, exports, frontend and AI integration remain outside this stage.
