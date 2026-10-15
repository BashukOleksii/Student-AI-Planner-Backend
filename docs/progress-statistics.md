# Stage G1 — Read-only progress statistics

G1 implements deterministic, owner-scoped progress statistics from existing Tasks,
Subtasks and StudySessions. It preserves BR-STAT-001–005. No migration, statistics
entity, stored counter, percentage, cache, report file, AI analysis/tool, forecasting
or frontend is introduced.

## API and validation

`GET /api/statistics/progress` (`statistics.progress`) requires `auth:sanctum`.

```http
GET /api/statistics/progress?start_date=2026-10-01&end_date=2026-10-31
Accept: application/json
```

Both query dates are required, exact real `YYYY-MM-DD` local calendar labels. The
end cannot precede the start; at most **31 inclusive nominal calendar dates** are
accepted. A one-day, week, calendar month or custom range uses the same endpoint.
A 31-date range is valid; 32 dates are not. Leap dates must exist. Whitespace,
arrays, alternate date formats and timestamps fail validation without trimming.
Unknown query parameters, including `user_id`, `timezone`, `status`, `task_id`,
computed totals and pagination, receive Laravel 422 field errors. GET bodies are
ignored: they cannot provide missing query dates or override accepted input.

`ProgressStatisticsRequest` inherits the existing `StudySessionRangeRequest` /
`FreeSlotsRequest` calendar and unknown-field policy. The Service independently
validates dates for trusted non-HTTP callers. No client-selected owner or timezone
is passed into the Service. This is a current-user-only endpoint, so authenticated
context and owned queries provide authorization without a separate target Policy.

| HTTP status | Meaning |
| --- | --- |
| 200 | Complete summary, including exact zero totals when empty. |
| 401 | Unauthenticated; authentication precedes query validation. |
| 422 | Invalid dates, range or unknown query field; standard Laravel errors. |
| 500 | Unsafe selected source relationships, invalid persisted timezone/timestamps, SQL/internal failure. |

Internal failures withhold the entire result and use only
`{"message":"The progress statistics could not be processed."}`, including in
debug mode. Foreign IDs, private metadata, SQL and exception details are not returned.

## Stable response contract

Laravel's standard `data` envelope contains exactly the following structure. Values
below describe an empty dataset. All counts, seconds and minutes are JSON integers;
`as_of_utc` retains six fractional digits so the clock used for strict overdue
comparisons is visible. Stored domain DATETIME fields remain whole-second UTC.

```json
{
  "data": {
    "as_of_utc": "2026-10-15T12:00:00.123456Z",
    "timezone": "Europe/Kyiv",
    "period": {
      "start_date": "2026-10-01",
      "end_date": "2026-10-31",
      "session_attribution": "starts_at"
    },
    "tasks": {
      "current": {"total": 0, "pending": 0, "completed": 0, "overdue_pending": 0},
      "completed_in_period": {"total": 0, "on_time": 0, "late": 0, "without_deadline": 0}
    },
    "subtasks": {
      "current": {"total": 0, "pending": 0, "completed": 0, "overdue_pending": 0},
      "completed_in_period": {"total": 0, "on_time": 0, "late": 0, "without_deadline": 0}
    },
    "study_sessions": {
      "started_in_period": {
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

## Current work and completion activity

Tasks and Subtasks are separate populations. They are never added into a combined
work-item total. Subtask completion is independent of parent completion. A pending
parent may have all completed children; a completed parent may have pending children.
This API does not change [S2b per-Task progress](tasks.md#s2b-derived-task-progress).

For Tasks, the population is the owner's active, non-soft-deleted Tasks. For
Subtasks, it is active, non-soft-deleted children whose parent is an active,
non-soft-deleted Task owned by that user. Ownership is inherited through the parent,
not inferred from a client identifier or a StudySession relationship.

| Current metric | Exact definition |
| --- | --- |
| `total` | All records in the respective active population, across its lifetime. |
| `pending` | Records whose current persisted status is `pending`. |
| `completed` | Records whose current persisted status is `completed`. |
| `overdue_pending` | Pending records with a non-null **own** `deadline_at < as_of_utc`. |

No deadline means no automatic overdue classification. Exact equality with the
clock is not overdue. When the clock is a fraction past a whole-second stored
deadline, that deadline is earlier and the pending record is overdue. These are
current snapshot counts; the requested period does not filter them or reconstruct
what their statuses were on an earlier day.

`completed_in_period` selects currently completed records in the same active
population whose persisted `completed_at` is in the requested local-date membership.
`updated_at` is never used as a completion event. Classifications are exhaustive:

| Completion metric | Exact definition |
| --- | --- |
| `total` | Selected persisted completions. |
| `on_time` | Non-null own deadline and `completed_at <= deadline_at`. |
| `late` | Non-null own deadline and `completed_at > deadline_at`. |
| `without_deadline` | Own persisted deadline is null. |

`on_time + late + without_deadline = total`. No parent deadline is substituted for
a missing Subtask deadline. These are persisted completion metrics; the planning
engine's effective earliest parent/child deadline is a different concept.

Reopening clears completion metadata and removes the work from period completion
statistics. Completing it again records a new completion instant. Soft deletion
removes it from work statistics; children under a deleted parent are also excluded,
even if a legacy active child remains. Subsequent deadline edits can change lateness
classification. There is no immutable Task/Subtask transition or deadline history,
so G1 cannot reconstruct old completions, earlier statuses, deleted work totals or
past deadline snapshots. A later read of a past period may consequently change.
No events are inferred or invented to fill this gap.

## StudySession attribution and duration

Select only the authenticated owner's sessions whose **original `starts_at`** is
in the requested local-date membership. The complete persisted record is attributed
to that start, regardless of its end or completion time. A session starting before
the period is excluded even if it overlaps the period. A selected cross-midnight
session contributes its full original duration, including time beyond the period.
A start exactly at an exclusive upper boundary is excluded.

For every selected session, `scheduled_seconds = ends_at - starts_at`, using exact
elapsed UTC seconds. Counts and scheduled seconds are summed independently for
`planned`, `completed`, `missed`, `rescheduled`, and `cancelled`. Equal/overlapping
records remain distinct persisted records. Statistics do not infer a missed state
from the clock or completion from elapsed/scheduled time.

Only completed records contribute actual-time metrics:

- `completed_actual_minutes`: sum of non-null persisted `actual_minutes`.
- `completed_with_actual_minutes`: number of completed sessions with known actual minutes.
- `completed_without_actual_minutes`: number of completed sessions with null actual minutes.

The two counts sum to the completed-session count. Zero known minutes with an
unknown count means no actual duration is known for those records; it does not
claim zero effort. Scheduled duration is never substituted for missing actual
minutes. An actual 25-minute completion of a scheduled hour contributes 3,600
scheduled seconds and 25 actual minutes. Missed/cancelled/rescheduled/planned rows
supply no completed actual time.

Rescheduled predecessors and successors retain distinct identities and may each
appear in their persisted status buckets. Their scheduled durations are not
combined into completed study time. The session graph is not reconstructed or
recursively audited by this report, matching existing Stage C read conventions.

Compatible historical sessions remain included when their Task/Subtask has been
soft-deleted. G1 reuses `PlanningContextService::validateSessionReferences()` with
owned `withTrashed` Tasks and matching `withTrashed` Subtasks. Foreign parents,
foreign children and wrong-parent children fail the whole report. No foreign
presentation data is loaded, no corrupt row is silently repaired, and unrelated
sessions outside the period are not scanned as a global integrity audit.

[Stage A remaining-effort credit](planning-work-selection.md) deliberately retains
its actual-first, otherwise scheduled-second fallback. C9 planned/completed
scheduled workload also retains its existing rules. Neither is presented as actual
study time by G1. [Stage C](study-plan-persistence.md), [Stage D](study-session-lifecycle.md)
and [E2](adaptive-replanning.md) writers are unchanged.

## Timezone, UTC membership and snapshot

The Service reloads the trusted User's persisted `id` and IANA timezone, ignoring
cached timezone attributes and loaded relationships. One `CarbonImmutable::now('UTC')`
is captured for the calculation, retaining microseconds; there is no `NOW()` or
per-record clock. Changing the application clock while reading cannot change the
same result's overdue reference instant.

`PlanningContextService::dateIntervals()` reuses existing recurring full-day
membership expansion and interval merging. It returns the exact union of half-open
UTC intervals `[start, end)` for the inclusive nominal date labels. It does not
resolve a single local midnight envelope, assume 24-hour days, or fill gaps between
disjoint occurrences. Kyiv spring/autumn days contain 82,800/90,000 real seconds;
Apia's skipped date has empty membership; historical Juneau date-line reversal can
produce disjoint occurrences. Installed IANA timezone data remains authoritative.

Raw MySQL DATETIME values are parsed/round-trip validated by the existing strict
UTC parser, independently of Eloquent display casts and PHP's default timezone.
Real input years 0001–9999 remain calendar labels. Query membership is intersected
with representable UTC DATETIME years 1000–9999; an out-of-storage period has no
activity, while current counts still apply. At the upper storage edge an omitted
impossible upper predicate preserves the last representable second without
passing a five-digit year to MySQL.

One transaction containing only nonlocking reads provides a consistent database
snapshot under the repository's existing MySQL **REPEATABLE READ** isolation. It
acquires no owner or work row locks and does not change the isolation setting.
The first persisted-owner SELECT establishes the report's snapshot. A separately
committed writer during the calculation does not split current and completion
results; the next request sees that commit. `as_of_utc` identifies the calculation
clock, not a historical database version or permission to make a later write.
Trusted callers using an ambient transaction inherit its snapshot. Deployments
changing isolation to READ COMMITTED would lose cross-query snapshot consistency.

## SQL, performance and read-only guarantees

Current lifetime populations use two scoped MySQL aggregates, returning one row
each: `COUNT(*)`, `COALESCE(SUM(status = ?), 0)`, and the explicit nullable deadline /
pending / full-precision-clock predicate. No lifetime Task/Subtask collection is
loaded to count it. The child query uses `whereHas('task')` with owned active parent
scope and the child soft-delete scope.

Period work queries filter current completed status and exact `completed_at`
membership in SQL. Session queries filter owned `starts_at` membership in SQL.
For disjoint coverage, the WHERE clause uses a grouped OR of individual ranges,
not the enclosing minimum/maximum envelope. Only required raw calculation/reference
columns are selected. Pure PHP reductions apply strict UTC timestamp validation,
exact deadline comparison, full elapsed seconds and explicit actual-time handling;
they are directly unit tested without Laravel/database bootstrap. This retains the
existing UTC parser and batch historical-reference implementation instead of adding
another SQL-specific source-validation policy.

The report uses **6 SELECTs with empty session data and at most 8 SELECTs** with
valid session history: fresh owner, two current aggregates, two period completion
queries, one period session query, and up to two batch historical-reference queries.
There are no per-record queries. Tests measure eight SELECTs with one Task / one
session and with 21 Tasks / 41 sessions. Transaction control adds no application
data writes. No new schema/index/cache is required.

Date-bounded queries do not load unbounded lifetime activity histories. Their
record volume is not artificially capped: memory/CPU is linear in the selected
period records and referenced work, plus the small interval union. Existing indexes
scope owners/statuses/parents; there is no dedicated `completed_at` reporting index
or `(user_id, starts_at)` index. MySQL may scan more owned index entries than it
returns. Current aggregate counts inherently scan the active owned lifetime
population. No latency/throughput guarantee or persisted reporting cache is claimed;
a later measured optimization must retain these exact metric/source rules.

The Service never changes Tasks/Subtasks, deadlines, sessions, plans, Reminders,
notifications, preferences or auth settings. It persists no snapshot/cache rows,
creates no delivery/queue job, dispatches no notification and makes no AI/network
call. Repeated frozen-clock HTTP reads compare exact all-table snapshots, inspect
SELECT-only application queries and assert no dispatched jobs/notifications/HTTP
requests. Integration reads repeat these checks after actual C/D/E2/F1–F3a actions,
including sent/unread and read inbox receipts.

Existing Sanctum/web authentication is unchanged. Database-backed stateful session
middleware may maintain authentication-session rows outside the statistics Service.
Tests use the existing array session driver; G1 does not claim to suppress framework
session maintenance when deployed with a database session handler.

## G2 boundary and data limitations

Stage G2a now implements [daily progress trends](progress-trends.md) separately at
`GET /api/statistics/trends`, with one bucket per requested nominal local date.
G1's existing endpoint, fields and definitions are unchanged. Its bounded activity
queries, reducers, UTC membership/storage-boundary helpers and historical-reference
checks are shared with G2a. Daily sums reconcile exactly with G1 period activity on
the same snapshot/clock; G1 current counts remain lifetime-only. G2a does not add
historical states, comparisons, report files or AI integration. Earlier verification
records below retain their original G1 checkpoint meaning.

G1 itself provides no daily/weekly chart series, period comparisons, trend/forecast engine,
subject breakdown, immutable historical snapshots, CSV/PDF/Excel export, scheduled
report, AI summary/tool or notification analytics. G2b must define additional
attribution/history requirements explicitly rather than treat current-state work
counts as past snapshots. Reports cannot infer actual minutes for null records or
reconstruct hard-purged session history. All present metrics derive from the current
authorized persisted snapshot.

## Verification

Starting and ending HEAD: `264eb53bd01dbdf6d388e2781303f92f9fbab808`.
The initial tree was clean. The resulting tree contains seven modified tracked
files and eight new files (15 total), all unstaged. Nothing is committed or pushed.
Existing models/enums/factories, migrations, database/auth configuration, planning
algorithms, lifecycle/cleanup/delivery writers and dependencies remain unchanged.

| Changed file | Responsibility |
| --- | --- |
| `app/Http/Controllers/Api/ProgressStatisticsController.php` (new) | Thin current-user HTTP orchestration. |
| `app/Http/Requests/ProgressStatisticsRequest.php` (new) | Reuse exact bounded query date/unknown-field validation. |
| `app/Http/Resources/ProgressStatisticsResource.php` (new) | Stable public `data` representation. |
| `app/Services/Progress/ProgressStatisticsService.php` (new) | Current SQL aggregates, period selection/reduction, read snapshot and shared historical-reference validation. |
| `routes/api.php` | One authenticated GET endpoint. |
| `bootstrap/app.php` | Exact-date trim exemption and private-data-safe statistics errors. |
| `tests/Unit/ProgressStatisticsServiceTest.php` (new) | Pure status/completion, UTC boundary, duration, actual-time and deterministic reduction coverage. |
| `tests/Feature/ProgressStatisticsApiTest.php` (new) | Guarded MySQL contract, validation, ownership, timezone, history, SQL and all-table read-only checks. |
| `tests/Feature/ProgressStatisticsIntegrationTest.php` (new) | Actual C/D/E2/F1–F3a compatibility and independent-connection snapshot/clock verification. |
| `docs/progress-statistics.md` (new) | Complete contract, limitations and verification record. |
| `docs/architecture.md` | Read-only G1 layers and MySQL snapshot behavior. |
| `docs/business-rules.md` | G1 enforcement under unchanged BR-STAT-001–005 identifiers. |
| `docs/domain-model.md` | Derived metrics without new persistence entities. |
| `docs/tasks.md` | Independent current/period work metrics and mutable history limitations. |
| `docs/study-session-lifecycle.md` | Start attribution and actual-time separation from planning credit. |

All database tests use the existing guarded real MySQL `student_planner_testing`
database. Pure tests do not bootstrap Laravel or connect to a database. API tests
use RefreshDatabase; integration tests use DatabaseTruncation with committed
fixtures so actual C/D/E2/Reminder writers execute their outermost transactions.
The independent writer test uses a second guarded MySQL connection, commits while
the report is in progress, and verifies the report's old consistent snapshot and
the next report's newly committed state. It also advances the clock mid-report.
There is no SQLite substitution, test removal or weakened baseline assertion.

| Verification command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit tests/Unit/ProgressStatisticsServiceTest.php` | 0 | 26 | 111 |
| `vendor/bin/phpunit --filter=ProgressStatistics` | 0 | 102 | 921 |
| Affected regression filter below | 0 | 1,764 | 12,212 |
| `vendor/bin/phpunit` | 0 | 4,028 | 33,319 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Exact affected regression command:

```sh
vendor/bin/phpunit --filter='TaskLifecycleTest|TaskProgressTest|SubtaskLifecycleTest|RemainingEffortServiceTest|StudySession|StudyPlan|StudyReplanning|TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|WorkloadConstraintServiceTest|PlanningCandidateEvaluationServiceTest|SecurityTest'
```

Focused, affected regression and full-suite durations: 4.273, 96.122 and 188.785
seconds. No tests were skipped. The complete suite additionally covers all existing Reminder/inbox and remaining planning,
work-selection, constraint, security and separate-process concurrency tests.
Final G1 coverage comprises 26 Unit tests, 67 MySQL API tests and nine MySQL
integration tests. Query inspection and exact all-table snapshots show no
application writes, no row locks and unchanged Reminder/notification state after
statistics reads; dispatch fakes assert no queued work, notification or HTTP calls.

Development verification: the first pure run passed 26 / 111. A sandboxed focused
attempt could not reach MySQL (exit 2: 88 tests, 26 pure tests passed, 62 guarded
connection/setup errors). Rerunning with authorized MySQL access passed 88 / 635;
expanded lifecycle/integration coverage passed 97 / 889. DST elapsed-duration,
storage-edge and invalid-persisted-timezone additions produced the final 102 / 921
focused result. No implementation or fixture failures were hidden.

Final changed-file review confirms thin HTTP orchestration, reused deterministic
interval/parser/reference policies, unchanged BR-STAT IDs, constant SELECT count,
no schema/writer/authentication changes, and the documented G2 boundary. There
are no missing local documentation links or new whitespace errors.


**Stage G1 acceptance: complete.** All sixteen criteria pass for the authenticated
bounded summary, exact current/completion metrics, separate Task/Subtask populations,
status-specific session scheduled time, known/unknown actual minutes, exact local
membership, preserved history limitations, owner isolation, read-only behavior,
regression/full-suite validation, formatting and documentation. G2 reports/trends,
immutable historical events/snapshots and exports remain outside this stage.
