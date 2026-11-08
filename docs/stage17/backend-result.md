# Stage 17A — workload diagnostics and priority advice

## Architecture analysis before implementation

Baseline: clean `main`, `e9a510d40aa83aaaea59cd5c0786dece177e4bde`.

StudyPlanningService::plan is read-only and already composes PlanningWorkSelectionService, RemainingEffortService, PlanningContextService and StudySessionAllocationService. Its summary is authoritative for eligible **unallocated remaining** work, and its ordered work_results already provide Task/Subtask identifiers, requested/allocated/unscheduled seconds and greedy-search reason_codes. Selection exclusions and warnings are separate. The allocator checks duration, availability, breaks, daily/weekly scheduled workload and effective deadlines through PlanningConstraintService; no second planning algorithm is needed.

Existing reasons directly provide no_available_study_time, deadline_capacity_exhausted, daily_study_limit_exceeded, weekly_study_limit_exceeded, duration/break reasons and planning_horizon_exhausted. Unscheduled effort is evidence of this greedy result, never a proof of global impossibility or existing overload. Priority order is effective deadline (non-null first, ascending), Task priority descending, Task ID, child position, child ID. No action rank is assigned to excluded work.

Implementation decision: one read-only application Service wraps the direct planner in the existing nonlocking MySQL REPEATABLE READ transaction pattern; query-only range Request, thin Controller and Resource expose a new GET endpoint. No persistence-preview adapter or token is used. Advice adds stable recommendation codes, preserves planner reasons/order and separates allocation/selection alerts from actual persisted-workload violations.

A small pure WorkloadConstraintService method can reuse its existing calendar splitting/period totals with **no candidate contribution**. The advice Service reuses owner-scoped PlanningContextService sources in the same snapshot and exact local-date membership. This enables a separate persisted diagnostic without a second capacity algorithm or a change to existing planning output. Overlapping records remain additive; null limits remain absent; whole ISO-week workload is counted even outside the requested dates. Context is read again in a fixed batched query set, not once per Task; this trades a bounded repeated context read for preserving all existing Service contracts.

Implementation results, verification and diagnostics follow. Final status: **PASS**.

## Implemented behavior

The authenticated GET endpoint uses WorkloadAdviceRequest (existing strict query-only range validation), WorkloadAdviceController and WorkloadAdviceResource. WorkloadAdviceService derives the response directly from StudyPlanningService in one nonlocking read transaction. All existing planner/selection/effort/allocator output contracts remain unchanged. It returns no proposal sessions or confirmation token and never calls StudyPlanPersistenceService.

Summary and clock are copied from the planner. Work results retain their exact Task/Subtask identities, seconds, reasons and relative order; only rank and stable recommendation codes are added. Exclusions preserve all original fields/reasons and receive only safe nonterminal recommendations. Completed, fully credited/reserved and parent-authoritative exclusions never become actionable priorities. Selection warnings are returned unchanged rather than reinterpreted as capacity violations.

Alerts explicitly separate allocation evidence, informational exclusions and measured persisted workload. Partial greedy allocation alone cannot produce a persisted-overload claim. Per-code unscheduled seconds can overlap and are not additive between alerts. Unknown excluded estimates remain unknown, not zero. Advice never asserts global infeasibility or a conflict between a specific pair of Tasks.

WorkloadConstraintService::existingViolations adds a pure use of existing periodTotals with includeRange=false: no imaginary candidate workload is introduced. Source selection remains PlanningContextService's owned planned/completed scheduled sessions, including compatible historical work. Missed/cancelled/rescheduled rows do not contribute. Overlapping distinct records remain additive; actual_minutes do not replace scheduled workload. Daily totals use requested local dates, weekly totals use their complete ISO weeks. Exact date membership and keyed period de-duplication preserve DST, repeated/skipped days and ISO week-year semantics. Null limits produce no invented maximum; equality is not overload.

The response has the required seven sections plus two explicit provenance sections: unchanged planning `warnings` and `persisted_workload_violations` with scheduled/limit/excess seconds. This supports accurate Frontend wording without fabricating missing fields from work_results or another snapshot.

## Changed files

| File | Responsibility |
| --- | --- |
| `app/Http/Requests/WorkloadAdviceRequest.php` | Reuse strict two-date, query-only, unknown-field and 31-date validation |
| `app/Http/Controllers/Api/WorkloadAdviceController.php` | Thin authenticated orchestration |
| `app/Http/Resources/WorkloadAdviceResource.php` | Exact standard Resource response formatting |
| `app/Services/Planning/WorkloadAdviceService.php` | One focused read-only application Service; planner-derived ranks/recommendations/alerts and source snapshot |
| `app/Services/Planning/WorkloadConstraintService.php` | Small additive pure persisted-workload diagnostic using unchanged calendar totals |
| `routes/api.php` | Authenticated GET registration |
| `bootstrap/app.php` | Preserve strict untrimmed date query and sanitize this endpoint's failures, including debug mode |
| `tests/Feature/WorkloadAdviceApiTest.php` | Real isolated-MySQL API/Service, semantics, safety, ownership, dates and query-count coverage |
| `tests/Unit/ExistingWorkloadDiagnosticsTest.php` | No phantom candidate/default limit and DST/constraint parity checks |
| `docs/stage17/workload-advice-api-contract.md` | Exact response schema, examples, code dictionary, limitations and Stage 17B handoff |
| `docs/stage17/backend-result.md` | This analysis and execution evidence |
| `docs/architecture.md`, `docs/automatic-study-planning.md` | Current read-only capability and planner-reuse links |

No migration, dependency, provider, AI Tool, pending-action type, lifecycle writer, queue, scheduler or Frontend file changed. Existing user changes were absent at the baseline and none were reset/discarded. Work remained on main; no branches/worktrees/PRs, GitHub access or Frontend inspection occurred.

## Isolation evidence

Tests ran only on a newly created **`student_planner_isolated_s17_20261108_140712_e79d`** schema. Its name did not previously exist; scoped information_schema metadata confirmed **zero tables** before tests. No retained schema was overwritten or copied. MySQL **8.4.10**, **REPEATABLE-READ**, APP_ENV testing, mysql driver, actual SELECT DATABASE(), DB_DATABASE and PLANNER_TEST_DATABASE matched the existing TestDatabase guard before RefreshDatabase could execute.

Credentials, generated key, logs and runtime overrides stayed in ignored `.codex/stage17/`. The original `.env` and config cache were not modified. No paid AI flags were enabled. Existing test-only mail/session/cache/queue configuration and current migrations were reused; no schema migration was added to this milestone. Retained `student_planner`, Stage 15 fixtures and the Stage 16 isolated schema were not modified. The Stage 17 schema is left available rather than automatically dropped; test cleanup remained inside it.

## Verification and exact outcomes

Commands ran from Backend root with private `source .codex/stage17/runtime.env` for PHPUnit.

| Check | Command and exact result |
| --- | --- |
| Initial new feature/unit coverage | `vendor/bin/phpunit tests/Feature/WorkloadAdviceApiTest.php tests/Unit/ExistingWorkloadDiagnosticsTest.php` — exit **0**, **30 passed**, **373 assertions**, 5.853 s |
| Existing affected regressions | `vendor/bin/phpunit tests/Unit/WorkloadConstraintServiceTest.php tests/Unit/WorkloadCapacityTest.php tests/Unit/PlanningConstraintServiceTest.php tests/Unit/StudySessionAllocationServiceTest.php tests/Feature/StudyPlanningServiceTest.php tests/Feature/PlanningWorkSelectionServiceTest.php tests/Feature/StudyRecoveryApiTest.php tests/Feature/ProgressStatisticsApiTest.php` — exit **0**, **618 passed**, **3,741 assertions**, 23.952 s |
| Final focused coverage, including the added over-reservation non-overload case and existing GET free-slot validation | `vendor/bin/phpunit tests/Feature/WorkloadAdviceApiTest.php tests/Unit/ExistingWorkloadDiagnosticsTest.php tests/Feature/FreeSlotsApiTest.php` — exit **0**, **117 passed**, **1,504 assertions**, 6.547 s |
| Formatting | `vendor/bin/pint --dirty` — exit **0**, fixed import/spacing conventions; explicit Pint over all nine changed/new PHP files — exit **0** |
| Repository-wide style | `vendor/bin/pint --test` — exit **0**, PASS |
| Whitespace | `git diff --check` — exit **0**, PASS |
| Contract examples/links | PHP read-only validation — exit **0**; all **3** complete JSON examples parse, summary arithmetic and exact top-level keys reconcile; local links resolve |

No PHPUnit assertion failed. The final focused run includes the final extra test proving that `future_reservation_exceeds_remaining_work` is not promoted into daily/weekly overload. The wider 618-case regression set was justified by the new C7 public diagnostic and verifies existing candidate, capacity, allocation, effort, recovery and statistics behavior. A redundant full unrelated suite and paid/live provider tests were not run; no new hosted CI execution is claimed.

### Coverage against the requested cases

- Exact empty response, fully allocated, partial, unavailable, deadline-clipped, daily/weekly constrained and below-minimum allocation.
- Missing estimate, expired deadline, completed and missed Tasks; no actionable completed priority.
- Stable effective-deadline/Task-priority/ID/child-position order with authoritative Subtasks and no parent double count.
- Actual persisted daily overload from **overlapping** scheduled records, independently of small actual_minutes; retired statuses excluded.
- Actual full-ISO-week overload outside a one-day requested period, including a week spanning December/January.
- Allocation daily/weekly reasons with **no persisted violation**; effort over-reservation warning with **no daily/weekly overload**; null/equal limits; 25-hour DST workload.
- Actual-first completed credit, valid reservations outside the horizon, foreign Tasks/Lessons/sessions isolated.
- Query-only strict dates, invalid dates/ranges/unknown parameters, 31-date acceptance, auth-before-validation, body spoofing ignored, persisted timezone, 23/25-hour days and skipped Apia date.
- Direct planner parity for the same frozen clock/source inputs; snapshot repeatability; SELECT-only queries without FOR UPDATE; all-table snapshots unchanged; no preferences materialized, tokens, provider calls, jobs or notifications.
- Query count identical with one and twenty-one Tasks; sources remain batched rather than per-work queries.
- Generic source-corruption failure even with APP_DEBUG=true, without exposing identifiers, SQL or private data.

## Diagnostics

No application, isolation or environment check failed. Expected negative API responses (401/422/500 corruption case) passed their assertions and are not unresolved defects.

**D17A-01 — source inspection alias**: LOW, tooling/read-only. A batched Bash inspection attempted `cat app/Http/Requests/StudyPlanPreviewRequest.php` (individual cat exit 1: file absent); expected the preview Request under an incorrect historical name. The actual repository uses `PreviewStudyPlanRequest.php`, identified by `rg --files` and subsequently inspected. No application issue or alternative database/network cause is implicated; no mutation or application correction was needed. Reverification: the actual Request and inherited StudySessionRangeRequest were read; new Request validation and negative cases pass. No secrets or private records were involved.

Formatting-only corrections followed existing Pint rules; no assertion was removed or weakened, no guard bypassed, and no behavior changed to manufacture a passing capacity result.

## Limitations and exact Stage 17B guidance

1. Greedy failure is not mathematical impossibility. Alert reasons can coexist and do not establish a unique root cause. Render them as observed limitations of this allocation attempt.
2. Summary covers eligible unallocated remaining effort only. Display exclusions and warnings separately; fully_allocated can be true when completed/expired/unestimated work is excluded.
3. Preserve rank; do not re-sort or derive a second priority score. A nullable Subtask ID is the authoritative work-unit distinction.
4. Distinguish `source=allocation` (candidate/projected constraint evidence) from `source=persisted_workload` (measured existing scheduled totals). Only the latter supports wording that persisted scheduled workload exceeds a configured limit.
5. Never add seconds across reason alerts or between daily/weekly excess categories. Display unknown excluded duration as unknown.
6. Persisted workload uses scheduled record contributions and whole ISO-week membership, not completed actual-time reports or a Task-pair conflict detector. Wholly skipped dates produce no artificial period duration.
7. Additional context sources are read in the same MySQL snapshot using a fixed batched query set. No existing planner return contract was expanded to expose internal context. Work_results lack title/deadline/priority metadata; this endpoint intentionally does not invent them.
8. Local dates retain existing planner/MySQL query-bound representability limits. No new defaults or data-repair writes are introduced for malformed persisted configuration.
9. Translate recommendation codes; do not mutate priority, deadline, availability or sessions automatically. Recovery/plan saving still requires a separate existing preview and explicit token confirmation. This endpoint cannot be used as confirmation evidence.
10. The exact Stage 17B types, alert/recommendation dictionaries and empty/partial/excluded JSON examples are in [the API contract](workload-advice-api-contract.md). Frontend implementation remains a separate milestone.

Final acceptance: **PASS**. The endpoint and all requested read-only diagnostics are implemented with focused isolated-MySQL coverage. No unresolved Backend blocker remains.
