# Stage G2b — Read-only progress period comparisons

G2b implements deterministic comparison of two explicitly selected local-date
activity periods. It preserves BR-STAT-001 through BR-STAT-005 and the existing G1
and G2a contracts. It adds no schema, statistics table, snapshot persistence,
planning/lifecycle writer, dependency, LLM integration or export.

## REST contract and validation

`GET /api/statistics/reports/comparison` (`statistics.reports.comparison`) requires
`auth:sanctum`. Laravel also registers the standard HEAD method. Other write methods
have no route.

```http
GET /api/statistics/reports/comparison?baseline_start_date=2026-09-01&baseline_end_date=2026-09-07&current_start_date=2026-10-01&current_end_date=2026-10-07
Accept: application/json
```

All four **query** parameters are required. Each is a string in exact real
`YYYY-MM-DD` format, including valid leap dates and years 0001–9999. Whitespace,
arrays, timestamps, alternate formats, invalid calendar dates and year zero fail.
Validation reuses G1/G2a's `FreeSlotsRequest` calendar rules, query-only input and
exact-input middleware convention. No input trimming silently repairs bad dates.

Both ranges are inclusive, ordered, and at most 31 nominal calendar dates long.
Their nominal lengths must match, and `baseline_end_date < current_start_date`.
Identical, overlapping and reversed pairs fail. Adjacent, non-adjacent and
year-over-year periods are supported; there is no maximum gap. “Current” names
the later selected period, not a current-state population or implicit date range.
Like G1/G2a, labels are not restricted relative to the application clock; selecting
a later date does not produce forecasts or inferred activity.

Unknown query parameters receive 422 field errors, including `user_id`, `timezone`,
`start_date`, `end_date`, status filters, pagination and proposed totals. GET bodies
are ignored and cannot supply missing dates or override query values. Ownership
and timezone always come from authenticated persisted context.

Per-period order/length errors attach to that period's `*_end_date`; cross-period
order/equal-length errors attach to `current_start_date`. The Service independently
validates both ranges and pair constraints for trusted non-HTTP callers.

| Status | Meaning |
| --- | --- |
| 200 | Complete report, including explicit zero totals for empty membership/data. |
| 401 | Unauthenticated; authentication runs before validation. |
| 422 | Normal Laravel validation errors for missing, invalid or unknown query fields. |
| 500 | Unsafe selected references, invalid persisted timezone/timestamps, integer overflow, SQL/internal failure. |

Internal failures withhold the entire report and share G1/G2a's response:
`{"message":"The progress statistics could not be processed."}`. Debug mode does
not expose SQL, private identifiers, source attributes or stack traces.

## Exact response schema

Laravel's normal `data` envelope contains the following fields in this order.
This empty one-day comparison illustrates every key; populated responses have the
same shape. Every metric leaf is a JSON integer. Boolean metadata and observation
flags are distinct from metrics. Delta integers may be negative.

```json
{
  "data": {
    "as_of_utc": "2026-10-15T12:00:00.123456Z",
    "timezone": "Europe/Kyiv",
    "comparison": {
      "equal_nominal_days": true,
      "session_attribution": "starts_at",
      "historical_snapshot": false
    },
    "baseline": {
      "period": {"start_date": "2026-09-01", "end_date": "2026-09-01"},
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
    },
    "current": {
      "period": {"start_date": "2026-10-01", "end_date": "2026-10-01"},
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
    },
    "delta": {
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
    },
    "observations": {
      "task_completions": "unchanged",
      "subtask_completions": "unchanged",
      "missed_sessions": "unchanged",
      "known_actual_minutes": "unchanged",
      "baseline_has_unknown_actual_minutes": false,
      "current_has_unknown_actual_minutes": false
    }
  }
}
```

## Shared metric definitions and reconciliation

`ProgressComparisonController` orchestrates only the Request, Service and Resource.
`ProgressComparisonService` reuses `ProgressStatisticsService::loadActivity()` once
and `activityMetrics()` independently for each membership. These are the exact
bounded source selection, UTC parsing, completion and session reductions already
shared by G1 and G2a. It does not call either endpoint internally, load current
lifetime populations, or duplicate SQL/metric formulas.

| Per-period field | Persisted activity definition | G1 equivalent | G2a equivalent |
| --- | --- | --- | --- |
| `tasks_completed` | Active owned Tasks, currently completed, `completed_at` in membership. | `tasks.completed_in_period` | `totals.tasks_completed` |
| `subtasks_completed` | Active Subtasks under active owned Tasks, currently completed, own `completed_at` in membership. | `subtasks.completed_in_period` | `totals.subtasks_completed` |
| `study_sessions` | Owned sessions whose original `starts_at` is in membership; all five persisted statuses. | `study_sessions.started_in_period` | `totals.study_sessions` |

Work classifications use each record's **own** deadline: equality or earlier
completion is `on_time`, later is `late`, null is `without_deadline`.
`on_time + late + without_deadline = total`. Task/Subtask counts stay independent;
parent status does not imply child status. No planning-effective inherited deadline
is substituted.

Each session contributes its full original elapsed UTC `ends_at - starts_at`
seconds to its status bucket, including beyond the period end. A session starting
before membership is excluded even if it overlaps it; the exclusive upper start
boundary is excluded. Scheduled seconds never imply completion or actual effort.
A rescheduled predecessor and planned successor remain separate historical records,
each attributed to its own original start and persisted status; their durations
are not combined into independently completed effort.

Completed actual minutes sum only non-null recorded `actual_minutes`.
`completed_with_actual_minutes + completed_without_actual_minutes = completed.count`.
Known 25 minutes in a scheduled hour means 25 actual minutes and 3,600 scheduled
seconds in separate fields. Null increments the unknown counter, contributes no
known minutes, and never triggers a scheduled-time fallback.

Tests reconcile **every field** with G1 and G2a for both periods on unchanged data
and clock, including historical calendar cases, storage boundaries and actual
lifecycle operations. Separate requests with intervening commits may legitimately
differ. G1 lifetime `current` counts are never used as historical-period snapshots.

## Arithmetic and factual observations

For every corresponding metric leaf:

```text
delta = current.totals[leaf] - baseline.totals[leaf]
```

Source accumulation retains G1's checked integer addition. Pure `delta()` validates
the complete canonical metric shape and both nonnegative integer operands before
subtraction. Both operands lie in `[0, PHP_INT_MAX]`; their difference lies in
`[-PHP_INT_MAX, PHP_INT_MAX]`, so subtraction cannot overflow. Floats (including
already overflowed values), numeric strings, negative operands, booleans, nulls and
missing/extra keys are rejected. No floating arithmetic, ratios, percentages,
division or productivity score is used. Inputs are not mutated, and canonical
metric key ordering is independent of input ordering.

| Observation | Source |
| --- | --- |
| `task_completions` | Sign of `delta.tasks_completed.total`. |
| `subtask_completions` | Sign of `delta.subtasks_completed.total`. |
| `missed_sessions` | Sign of `delta.study_sessions.missed.count`. |
| `known_actual_minutes` | Sign of `delta.study_sessions.completed_actual_minutes`. |
| `baseline_has_unknown_actual_minutes` | Baseline `completed_without_actual_minutes > 0`. |
| `current_has_unknown_actual_minutes` | Current `completed_without_actual_minutes > 0`. |

Direction is exactly `increased`, `decreased`, or `unchanged` for positive,
negative, or zero delta. Numerical increases do not imply improvement; increased
missed sessions remains a factual count direction. Known-minute direction describes
**known recorded minutes**, not total effort. If either unknown flag is true,
the known-minute delta is an incomplete comparison of actual study duration.
The report remains available and exposes the limitation without estimating values.
No motivation, quality, productivity judgment, recommendation or prediction is made.

## One snapshot, timezone and clock

One nonlocking transaction contains only reads under the repository's MySQL
**REPEATABLE READ** convention. Its first SELECT reloads the authenticated User's
persisted `id` and trusted IANA timezone, establishing the snapshot. Cached User
attributes and loaded relationships do not define reporting context. One
full-precision `CarbonImmutable::now('UTC')` supplies `as_of_utc`, with six fractional
digits. It labels this calculation, not a historical database version.

Both coverages use the existing `PlanningContextService::dateIntervals()` exact
half-open UTC unions. `TimeIntervalService::merge()` combines those memberships
for the single source load; it preserves all gaps and never reads the intervening
years as one envelope. Both reductions use their own coverage against that same
loaded snapshot. No SQL runs inside period/day/record/metric loops.

Equal nominal date counts do not imply equal elapsed UTC durations. Kyiv spring
and autumn days retain 82,800/90,000 seconds, including both fold occurrences.
Apia's skipped date has empty membership; Juneau's historical disjoint date includes
all its occurrences and excludes the envelope gap. Server timezone does not define
membership. Installed IANA data is authoritative. Shared G1 storage intersection
handles UTC DATETIME years 1000–9999 and preserves the final representable second;
out-of-storage nominal labels can have zero activity.

Independent-connection integration tests commit changes to **both** periods at two
points: after the owner SELECT and after the Task activity SELECT. The second
connection is guarded against the live testing database and uses a different PDO.
Tasks/Subtasks reopen, sessions change from completed to missed, and the timezone
changes. The in-progress comparison retains all old values for both periods; the
next HTTP request sees all committed changes. A mid-report clock advance does not
change the captured reference instant. No mutation locks or isolation changes are
needed. Trusted callers with an ambient transaction inherit that snapshot; changing
deployment isolation to READ COMMITTED would lose cross-query snapshot consistency,
just as for G1/G2a.

## Historical limitations

`historical_snapshot: false` is permanent V1 metadata. The report describes activity
visible in the current persisted snapshot. Task/Subtask transition and deadline
history is not immutable: reopening removes a completion, recompletion records a
new instant, changed deadlines can reclassify lateness, and soft deletion removes
work (including active children under deleted parents). Later requests for a past
period may change. `updated_at` never reconstructs old completions or deadlines.
There are no historical pending/overdue populations or inferred historical states.
Compatible sessions attached to deleted work remain visible; hard-purged history
cannot be recovered. Missing actual minutes cannot be inferred.

## Security, read-only behavior and performance

All source queries use the authenticated owner's relations or active-parent
ownership. The shared `validateSessionReferences()` batches owned `withTrashed`
Task/Subtask attribution checks for selected sessions of every status. Foreign
parents/children and wrong-parent children fail the entire report. No private
presentation data or identifier enters the result. Unrelated unselected history
is not globally audited, and the rescheduling graph is not recursively reconstructed,
matching G1/G2a's read policy.

The source query count is **4 SELECTs without sessions, at most 6 with valid session
history**: fresh owner, bounded Task completions, bounded Subtask completions,
start-attributed sessions, then up to two historical-reference batches. Tests
measure six SELECTs for two one-day periods and two 31-day periods, with one and
101 records per population **per period**. SQL inspection verifies two range
predicates joined by OR for separated periods, no lifetime aggregates and no locks.
A corrupt session in the interperiod gap, or a disjoint local-date envelope gap,
is not loaded. The query count does not scale with records or date count.

Memory and reduction work scale with selected activity/referenced work plus bounded
calendar interval unions. The two requested periods total at most 62 nominal dates;
the gap adds no loaded source volume. There is no artificial record cap, cache,
materialized aggregate or new reporting index. Existing MySQL indexes can scan more
owned entries than returned; no latency/throughput guarantee is claimed.

Repeated reads and corrupt-source failures preserve exact all-table snapshots.
Queries contain only SELECTs and no row locks. Bus/Notification/HTTP fakes verify
no jobs, notifications or network requests; realistic lifecycle integration also
preserves cancelled Reminders and delivered unread inbox state. No Task/Subtask,
session, Reminder, notification, preference, plan or report snapshot is written.
Authentication framework housekeeping stays outside the statistics Service,
consistent with G1/G2a; database-backed stateful sessions may maintain auth rows.
Tests use the existing array session driver and guarded real MySQL database.

## Scope and future work

The structured Service result is reusable by future Vue presentation and trusted
AI orchestration adapters. PDF/Excel/CSV export, frontend charts, AI summaries,
forecasting, percentages, arbitrary multi-period comparison, scheduled generation,
immutable history/event sourcing, notification analytics and persisted snapshots
remain future work. They require their own contracts and must preserve the source,
unknown-duration and historical limitations here.

## Verification

Starting and ending HEAD: `d37a33709290d8d0354cbf9bb482890526a5b179`.
The initial working tree was clean. Changes remain uncommitted and unpushed.
No models, migrations, factories, dependencies, database/auth configuration,
planning rules, lifecycle writers or existing tests are changed.

Final verification and changed-file review are recorded below.

| Changed file | Responsibility |
| --- | --- |
| `app/Http/Controllers/Api/ProgressComparisonController.php` (new) | Thin authenticated orchestration. |
| `app/Http/Requests/ProgressComparisonRequest.php` (new) | Strict query-only four-date and period-pair validation. |
| `app/Http/Resources/ProgressComparisonResource.php` (new) | Stable response representation. |
| `app/Services/Progress/ProgressComparisonService.php` (new) | Shared-source snapshot, checked pure differences and observations. |
| `routes/api.php` | Authenticated comparison GET/HEAD route. |
| `bootstrap/app.php` | Extend exact-date input and generic statistics failure handling. |
| `tests/Unit/ProgressComparisonServiceTest.php` (new) | Pure pair validation, metric/delta/observation, calendar and integer-boundary tests. |
| `tests/Feature/ProgressComparisonApiTest.php` (new) | Guarded MySQL contract, reconciliation, authorization, history, query and read-only tests. |
| `tests/Feature/ProgressComparisonIntegrationTest.php` (new) | Actual lifecycle fixture and two independent-connection commit points. |
| `tests/Concerns/AssertsProgressComparison.php` (new) | Test-only exact reconciliation, arithmetic and all-table snapshots. |
| `docs/progress-comparisons.md` (new) | Complete contract, limits, evidence and verification. |
| `docs/progress-statistics.md` | Link shared G2b behavior while preserving original G1 checkpoint. |
| `docs/progress-trends.md` | Link shared G2b behavior while preserving original G2a checkpoint. |
| `docs/architecture.md` | New read-only layers and shared snapshot/source design. |
| `docs/domain-model.md` | Derived two-period report without a new persistence entity. |
| `docs/business-rules.md` | G2b enforcement under unchanged BR-STAT-001–005 identifiers. |

The final scope is seven modified tracked files and nine new files (16 total),
all unstaged. Pure tests do not bootstrap Laravel or a database. Feature tests use
RefreshDatabase; lifecycle/concurrency integration uses committed DatabaseTruncation
fixtures so real outermost application transactions execute. The existing testing
guards validate configured and live MySQL `student_planner_testing` before database
setup; the independent writer separately verifies its database. No SQLite, skipped
tests, deleted tests or weakened baseline assertions are used.

Development verification: the initial sandboxed database run could not connect;
the authorized focused run passed 138 tests / 2,562 assertions. Adding final-storage
and half-open boundary coverage plus the second concurrent commit point produced
331 passing G1/G2a/G2b tests / 4,526 assertions. The final standalone pure run passed
30 tests / 62 assertions. No application or fixture test failures occurred after
MySQL access was available.

New G2b coverage comprises **140 tests**: 30 pure Unit cases, 107 guarded MySQL API
cases, and three lifecycle/concurrency integration cases. The latter include one
real lifecycle fixture and independent commits at two different read points.
All 191 existing G1/G2a cases remain unchanged and pass in the combined run.

| Verification command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit tests/Unit/ProgressComparisonServiceTest.php` | 0 | 30 | 62 |
| `vendor/bin/phpunit --filter='ProgressComparison\|ProgressTrends\|ProgressStatistics'` | 0 | 331 | 4,526 |
| Affected regression filter below | 0 | 2,994 | 22,664 |
| `vendor/bin/phpunit` | 0 | 4,257 | 36,924 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Exact affected regression command:

```sh
vendor/bin/phpunit --filter='Progress|TaskLifecycleTest|TaskProgressTest|SubtaskLifecycleTest|RemainingEffortServiceTest|StudySession|StudyPlan|StudyReplanning|TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|PlanningWorkSelectionServiceTest|Reminder|NotificationInbox|SecurityTest'
```

Combined statistics tests took 11.817 seconds; affected regressions took 167.503
seconds. Both runs exited zero with no skipped tests. They cover shared calendar
and timezone helpers, Task/Subtask lifecycle/progress, remaining effort, work
selection, Stage C/D/E1/E2 planning and sessions, constraints, reminders, inbox and
security behavior. The complete suite additionally exercises the rest of the
repository, including persistence, imports and authentication.

The complete PHPUnit suite passed in **198.142 seconds**, with all 4,257 cases
passing and none skipped. Final Pint dirty/global checks and whitespace checks
exited zero. HEAD remains unchanged, and all 16 changed files remain unstaged.
No commit or push was performed.

Final architecture/scope review confirms unchanged G1/G2a metric definitions and
HTTP contracts; one shared source load within one snapshot; exact per-period
membership; bounded source query count; checked signed arithmetic; deterministic
observations; explicit unknown-duration and mutable-history limits; and no schema,
planning, lifecycle, authentication or AI behavior changes. Cross-owner selected
history fails closed without private details, and exact all-table snapshots plus
side-effect assertions confirm read-only application behavior.

**Stage G2b acceptance: complete.** All 17 acceptance criteria are satisfied:
authenticated strict equal-length/non-overlapping periods; one owner/timezone/clock/
snapshot; shared activity definitions; exact signed deltas; factual observations;
explicit missing actual durations and mutable history; no performance judgments;
owner isolation; bounded queries; no business-state writes; full G1/G2a
reconciliation; two independent-connection snapshot scenarios; passing full suite,
Pint and whitespace checks; and documentation matching the implementation.

Known limits remain the current MySQL REPEATABLE READ requirement, mutable
Task/Subtask history, unknown actual minutes, unavailable hard-purged records,
installed IANA timezone data, and memory/index-scan cost proportional to selected
activity rather than a fixed latency guarantee. Exports, charts, AI narratives,
forecasting and historical state reconstruction remain outside G2b.
