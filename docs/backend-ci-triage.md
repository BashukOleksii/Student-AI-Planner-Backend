# Backend CI triage — 2026-11-11

Baseline local and remote main: `7bbd4de6d7d625022aea78cb660da71e0255a1ed`.
Reviewed verify job 130514641997 in run 43018245376. CI reported 6593 tests,
54908 assertions, 42 errors, 60 failures and 2 risky tests; deployment was skipped.

## Selected defect: lifecycle action persistence

`PlanningActionRegistry::NAMES` and `PlanningActionService` support
`propose_session_mark_missed` and `propose_session_complete`, but the latest
existing type CHECK migration (2026_11_08_000002) accepts only 21 earlier types.
A valid lifecycle proposal therefore cannot be stored in `ai_pending_actions`.
The CI log contains MySQL 3819 violations, alongside missing action IDs and
subsequent confirmation errors. Some lifecycle failures also have independently
stale test fixtures: `InteractsWithPlanningActions` supplies date-range arguments
and future sessions to the two lifecycle tools. The migration alone does not fix
those fixtures.

One new forward-only migration expands only `ai_actions_valid_type` to 23 literal
values in a single ALTER. Existing migration files are unchanged. It preserves
columns, indexes, FKs, rows, receipts and the other CHECKs. Downgrade refuses
without writes, consistent with the existing availability/lesson overlays.
Deploy this migration before serving lifecycle proposals. No domain algorithm,
REST contract, authorization or confirmation transaction is changed.

New focused tests use valid past-session lifecycle arguments and verify proposal
read-only behavior, owned confirmation, reminder cancellation, durable replay,
stale fingerprints, and rollback of session/reminder/receipt/history effects after
an injected history failure. Migration coverage exercises the 21-type predecessor,
retained executed receipts, all 23 accepted types, invalid types, other CHECKs,
unchanged schema metadata and refusal to downgrade.

## Remaining independent groups

- AI contract/fixture drift: tool-count and definition snapshots in Unit tests,
  discovery/history expectations, mutable registry lists used to test historical
  migrations, and legacy planning fixtures automatically expanded to lifecycle
  actions. These are demonstrably stale assumptions; update to explicit historical
  sets and dedicated lifecycle scenarios without weakening assertions.
- Constructor fixtures: PlanningActionTest passes nine dependencies to the now
  twelve-argument PlanningActionService. PlanningCandidateEvaluationServiceTest
  also has a delegation fixture error; inspect its current calendar dependency.
- AI HTTP integration: BackendIntegrationSmokeTest failures span old action types
  too, so they cannot all be attributed to the missing lifecycle CHECK. Inspect
  exact response payloads and expectations separately. AiToolsIntegrationTest also
  has schedule/read-only snapshots and unexpected-error fixture failures.
- Semester contracts: required singular teacher inputs versus old management and
  concurrency fixtures, additional teacher/lesson-type response metadata, import
  auto-detection versus old validation/preview assumptions. ADR-009/010 and current
  implementation must guide whether each is stale or a real defect.
- Migration assertions: semester schema/column-order expectations and AI historical
  type sets require isolated migration review; do not alter old migrations merely
  to satisfy snapshots.
- Query budgets: semester read/management, study planning and adaptive preview
  exceed old fixed counts after calendar/teacher loading. Verify bounded scaling
  and absence of N+1 reads before updating budgets.
- Two risky tests leave handlers installed; CI also reports one deprecation and
  353 notices. These are separate test-hygiene work.

Recommended next isolated task: repair AI lifecycle fixture/contract drift,
including constructor wiring and explicit historical migration type sets, then
run both provider matrices and independent-process confirmation tests.

## Verification for this change

- `vendor/bin/phpunit tests/Feature/AiSessionLifecycleActionMigrationTest.php tests/Feature/AiSessionLifecycleActionsTest.php tests/Feature/StudySessionLifecycleTest.php --no-progress`: **142 passed**, 1280 assertions, zero errors/failures.
- `vendor/bin/phpunit tests/Feature/EffectiveScheduleServiceTest.php tests/Feature/FreeTimeServiceTest.php tests/Feature/StudyRecoveryApiTest.php --no-progress`: **129 tests, 125 passed, 4 failures**, 1526 assertions, no errors. All four failures are FreeTimeServiceTest's empty-expansion query-count assertion (2 SELECTs versus expected 1); calendar/recovery behavior checks passed. These are outside the action-type schema fix.
- Pint on all three new PHP files passed; `git diff --check` passed.
- Complete post-change suite has no valid result. An accidentally overlapping local suite attempt was stopped because both processes shared the disposable schema; its results were discarded. The focused checks above were rerun sequentially. The CI baseline counts above remain the only complete-suite counts reported here.
