# Backend test rebuild and verification report

Baseline: `4412f3d7bee49996d4d9792c243b481aa87ca749`, clean `main` at inspection. Work remains on `main`. The current repository and source requirement transcript, rather than historical Stage assessments, determine coverage. This report records executed local evidence, including baseline failures, corrections and the final complete rerun.

## Inventory and audit

[Baseline inventory](baseline-inventory.json) records every path: 37 Unit files (260 test methods), 115 Feature files (1,344 methods), one opt-in Live test, 12 Support files, six Concerns, four fixture files and one separate Deployment Python source file (ignored bytecode caches excluded). Data providers expand the baseline to 2,817 Unit + 3,929 Feature cases (6,746 total). This inventory predates the added cases; actual final expanded counts are in the execution table.

Audited routes, Services, Form Requests, Policies/owner-scoped lookups, Resources, factories, migrations, PHPUnit configurations, helpers and original-requirement transcript. The original ODT attachment is unavailable; its source-bundle transcription remains the requirement provenance limitation described in [functional coverage](../requirements-audit/functional-coverage.md). No paid/live provider was invoked.

Baseline execution found:

- Unit: 2,817 tests, 8,761 assertions, 1 failure.
- Feature: 3,929 tests, 63,195 assertions, 34 failures, 8 errors, 2 risky tests.
- Most Feature failures were drift in fixtures/contracts, not missing application behavior. Existing correct regression matrices were retained rather than broadly rewritten.

### Replacement coverage and retained assertions

| Old scenario/problem | Modernized equivalent coverage |
|---|---|
| Unit scaffold `true === true` | Removed `tests/Unit/ExampleTest.php`; [AiCapabilityContractTest](../../tests/Unit/AiCapabilityContractTest.php) now checks actual registered schema closure/uniqueness and unsafe database selectors. |
| Feature scaffold root HTTP 200 | Renamed/replaced by [ApplicationBootstrapTest](../../tests/Feature/ApplicationBootstrapTest.php): preserves root 200 and adds `/up` health and unauthorized personal-resource assertions. |
| Three historical Tool JSON snapshots; two already unreferenced | Removed `tests/Fixtures/AI/tool-definitions-before-stage18a.json`, `tool-definitions-before-stage18b.json`, `tool-definitions-lifecycle-before-availability-actions.json`. Current schema semantics, registry prefix preservation, unique identities, bounds, null/omission and provider adaptation remain covered by [AiAvailabilityToolSchemaTest](../../tests/Unit/AiAvailabilityToolSchemaTest.php), [AvailabilityActionTest](../../tests/Unit/AvailabilityActionTest.php), other action schema tests and [AiCapabilityContractTest](../../tests/Unit/AiCapabilityContractTest.php). Natural-language description snapshots are no longer treated as business contracts. |
| Historical numeric Tool counts | [AiCapabilities](../../tests/Support/AiCapabilities.php) derives the complete current manifest. Discovery/orchestrator tests assert exact names, uniqueness and provider schemas; no global historical count is hardcoded. Counts of actual calls in a scenario remain meaningful. |
| Legacy-only query totals and FreeTime mocks | [AssertsBatchedReads](../../tests/Concerns/AssertsBatchedReads.php) compares sparse/dense per-source query families and SELECT-only behavior. Existing ownership, overlap, workload and no-write assertions remain. Calendar graph/provenance queries intentionally load complete owned sources; identity followups are distinguished from interval queries. The empty-availability case verifies trusted-owner plus availability reads and absence of blocker expansion. |
| Rollback assumes every future migration is reversible | [UsesForwardOnlyMigrations](../../tests/Concerns/UsesForwardOnlyMigrations.php) rebuilds explicit historical predecessors. Legacy AI/schema down/up tests retain preservation assertions; [PlanningMigrationTest](../../tests/Feature/PlanningMigrationTest.php) additionally upgrades the scaffold through **all current** forward migrations. Production downgrade guards are unchanged. |
| Semester fixture lacks a teacher or mutates an assigned subject to another teacher | Management/concurrency tests now create valid owned single-teacher data before capturing management tokens. Null/conflicting teacher inputs are explicitly rejected; valid edits, frozen dates and concurrent stale rejection remain tested. |
| Import assumes missing catalogs block preview or client parity controls upload | [SemesterScheduleImportWorkflowTest](../../tests/Feature/SemesterScheduleImportWorkflowTest.php) verifies prepared catalog identities without writes, explicit commit, manual catalog repair/revalidation, ambiguity errors, server-owned parity and explicit subsequent semester settings changes. Invalid file/owner/ID cases remain. |
| REST semester occurrence snapshot omits current fields | [SemesterScheduleReadApiTest](../../tests/Feature/SemesterScheduleReadApiTest.php) asserts the complete current teacher/classification contract and stable virtual identity. Column reordering in migration tests is normalized while values/types remain strictly compared. |
| AI fixture sends JSON `[]` as an empty object | Semester action tests send `{}` for conversation/decision endpoints. Strict object validation remains unchanged. |
| Recovery refresh expects 200 for a newly created Resource; writer mock installed after controller construction | [AutomaticLearningRecoveryTest](../../tests/Feature/AutomaticLearningRecoveryTest.php) expects 201 on first refresh and 200 on replay, and installs the injected failure before the first request resolves the controller. Actual rollback/private error assertions now execute. |
| Fragile/cross-clock fixtures | Recovery and academic cases use fixed UTC clocks; HTTP smoke captures/fixes the server clock to align PHP planning previews with database-owned AI leases. Calendar tests explicitly cover DST and cross-midnight overlap. |

No high-value edge-case matrix, sensitive ownership assertion, concurrency case or planning algorithm regression was deleted. Constructor mocks that already matched current dependencies were retained. No test was skipped to obtain a pass. Whole-file rewrites were limited to scaffold/redundant test artifacts and migration setup that no longer matched the forward-only architecture.

## Organization and safeguards

Default `phpunit.xml` still discovers all Unit and Feature tests exactly once. `phpunit-smoke.xml` still selects the same Feature directory through the `smoke` group. Class-level groups enable `mysql`, `migration`, `concurrency`, `ai`, `security`, `recovery`, `workflow`; Unit remains pure PHPUnit without Laravel/database bootstrap. The smoke group now includes unified calendar reads, all nine semester actions, owner-confirmed automatic recovery and the complete academic-recovery workflow.

Shared helpers provide current Tool manifests, recovery fixtures, sparse/dense read assertions and real MySQL worker synchronization. Existing browser/provider/domain helpers remain in use. New [DatabaseIsolationGuardTest](../../tests/Feature/DatabaseIsolationGuardTest.php) proves a second suite is refused before cleanup. `tests/TestCase.php` retains testing/mysql/exact selected-database checks, adds a local server-UUID/database keyed process lease, and prevents stray HTTP in offline tests. Deliberate concurrency clients remain allowed inside one sequential suite. Live paid tests retain explicit opt-in guards and remain outside the offline definition.

[Disposable runner](../../scripts/testing/phpunit-disposable-mysql.sh) provisions a new private MySQL datadir/server/database, verifies server identity and emptiness, supplies fake delivery configuration, and removes only its own temporary instance afterward. See [routine commands and prerequisites](../test-strategy.md). This does not change deployment workflows, production infrastructure or application APIs.

## Functional requirement coverage

All referenced Unit/Feature classes below were included in the final complete offline execution. Their existence alone is not used as pass evidence.

| Requirement group | Principal coverage |
|---|---|
| Registration, login/logout, session continuity, profile/timezone, throttling | [AuthProfileTest](../../tests/Feature/AuthProfileTest.php), [AuthRateLimitTest](../../tests/Feature/AuthRateLimitTest.php), [FrontendApiContractTest](../../tests/Feature/FrontendApiContractTest.php), [TrustedProxyTest](../../tests/Feature/TrustedProxyTest.php) |
| Password change/reset generation, queued fake mail, expiry/single use, session revocation | [PasswordManagementTest](../../tests/Feature/PasswordManagementTest.php) |
| Academic ownership and associations | [AcademicContextSecurityTest](../../tests/Feature/AcademicContextSecurityTest.php), academic period/subject/teacher API and persistence tests |
| Legacy lessons, cancellations, replacements, local dates | [LessonApiTest](../../tests/Feature/LessonApiTest.php), [LessonLifecycleTest](../../tests/Feature/LessonLifecycleTest.php), [LessonSecurityTest](../../tests/Feature/LessonSecurityTest.php), [ScheduleViewTest](../../tests/Feature/ScheduleViewTest.php) |
| Semester parity, snapshots, working Saturdays, frozen exceptions, editing | [EffectiveScheduleCalculatorTest](../../tests/Unit/EffectiveScheduleCalculatorTest.php), [SemesterScheduleManagementTest](../../tests/Feature/SemesterScheduleManagementTest.php), [SemesterScheduleReadApiTest](../../tests/Feature/SemesterScheduleReadApiTest.php), management/import concurrency and persistence suites |
| Unified today/date/week, mixed sources, identity, timezone/DST/cross-midnight, no self-study | [AcademicCalendarApiTest](../../tests/Feature/AcademicCalendarApiTest.php), [SemesterPlanningCalendarTest](../../tests/Feature/SemesterPlanningCalendarTest.php), [AiToolsIntegrationTest](../../tests/Feature/AiToolsIntegrationTest.php) |
| Excel preview/commit/history, repeated imports, suppression, validation/rollback | [ScheduleWorkbookParserTest](../../tests/Unit/ScheduleWorkbookParserTest.php), [ScheduleImportCommitTest](../../tests/Feature/ScheduleImportCommitTest.php), [SemesterScheduleImportWorkflowTest](../../tests/Feature/SemesterScheduleImportWorkflowTest.php), [SemesterScheduleManagementImportTest](../../tests/Feature/SemesterScheduleManagementImportTest.php), import persistence/concurrency tests |
| Task/Subtask CRUD, deadlines, estimates, priority, independent lifecycle and ownership | [TaskApiTest](../../tests/Feature/TaskApiTest.php), [TaskLifecycleTest](../../tests/Feature/TaskLifecycleTest.php), [TaskMissedStatusTest](../../tests/Feature/TaskMissedStatusTest.php), [SubtaskLifecycleTest](../../tests/Feature/SubtaskLifecycleTest.php), [TaskProgressTest](../../tests/Feature/TaskProgressTest.php), task/subtask security/deletion/order tests |
| Remaining effort and unknown estimates/duration | [RemainingEffortServiceTest](../../tests/Unit/RemainingEffortServiceTest.php), [PlanningWorkSelectionServiceTest](../../tests/Feature/PlanningWorkSelectionServiceTest.php), recovery and progress tests |
| Availability/free time, limits, breaks, deadline/priority selection, splitting, conflicts/capacity | Unit interval/availability/duration/break/workload/allocation suites; [FreeTimeServiceTest](../../tests/Feature/FreeTimeServiceTest.php), [StudyPlanningServiceTest](../../tests/Feature/StudyPlanningServiceTest.php), [SemesterPlanningCalendarTest](../../tests/Feature/SemesterPlanningCalendarTest.php) |
| Preview tokens, stale/conflicting/partial plans, explicit transactional persistence | Unit plan/replanning token validators; [StudyPlanApiTest](../../tests/Feature/StudyPlanApiTest.php), [StudyPlanConcurrencyTest](../../tests/Feature/StudyPlanConcurrencyTest.php), [StudyReplanningConfirmationTest](../../tests/Feature/StudyReplanningConfirmationTest.php) |
| Session lifecycle, actual minutes, unknown outcomes, manual/adaptive recovery | [StudySessionLifecycleTest](../../tests/Feature/StudySessionLifecycleTest.php), [StudySessionConcurrencyTest](../../tests/Feature/StudySessionConcurrencyTest.php), [StudyRecoveryApiTest](../../tests/Feature/StudyRecoveryApiTest.php), [StudyReplanningPreviewTest](../../tests/Feature/StudyReplanningPreviewTest.php), [StudyReplanningConcurrencyTest](../../tests/Feature/StudyReplanningConcurrencyTest.php) |
| Automatic scan selection, bounds/fairness, no business writes, no unnecessary/duplicate review, fingerprints/availability | [AutomaticRecoveryDetectionTest](../../tests/Feature/AutomaticRecoveryDetectionTest.php) |
| Durable create/replan/decision/warning reviews, expiry/reject/refresh, consent, stale data, replay and rollback | [AutomaticLearningRecoveryTest](../../tests/Feature/AutomaticLearningRecoveryTest.php), [RecoverySuggestionPersistenceTest](../../tests/Feature/RecoverySuggestionPersistenceTest.php), [AutomaticRecoveryConcurrencyTest](../../tests/Feature/AutomaticRecoveryConcurrencyTest.php) |
| Reminders, relative deadlines, dispatch, retries/dedupe, inbox, historical notification retention | Reminder/relative/delivery/inbox API, rule and concurrency suites; [AcademicRecoveryWorkflowTest](../../tests/Feature/AcademicRecoveryWorkflowTest.php) |
| Completion/missed statistics, planned vs unknown/actual time, subject workload, trends/comparison/overload/deadline risk | Progress statistics/comparison/trends Unit, API and integration suites; [WorkloadAdviceApiTest](../../tests/Feature/WorkloadAdviceApiTest.php), [AcademicRecoveryWorkflowTest](../../tests/Feature/AcademicRecoveryWorkflowTest.php) |
| Every current read/proposal definition, strict arguments, ownership, multistep orchestration, provider limits/errors | [AiCapabilityContractTest](../../tests/Unit/AiCapabilityContractTest.php), [AgentOrchestratorTest](../../tests/Unit/AgentOrchestratorTest.php), [LlmProviderContractTest](../../tests/Feature/LlmProviderContractTest.php), [BackendIntegrationSmokeTest](../../tests/Feature/BackendIntegrationSmokeTest.php), [RecoveryReadToolsTest](../../tests/Feature/RecoveryReadToolsTest.php), [AiSemesterActionsTest](../../tests/Feature/AiSemesterActionsTest.php) and existing per-action suites |
| Confirm/reject/expiry/stale/replay/immutable receipts and atomic failures for AI actions | Existing Task, Subtask/Reminder, planning/session, Lesson, availability and semester action Feature/Unit/concurrency suites. No conversational “yes” applies business writes. |
| Current migrations, CHECKs, FK/unique constraints and preserved history | `migration` group, particularly [PlanningMigrationTest](../../tests/Feature/PlanningMigrationTest.php), [AiSemesterActionMigrationTest](../../tests/Feature/AiSemesterActionMigrationTest.php), [RecoverySuggestionPersistenceTest](../../tests/Feature/RecoverySuggestionPersistenceTest.php) |
| Full register → semester/task/availability → plan/confirm → academic edit → detect/review/confirm → reminders/statistics/AI read | [AcademicRecoveryWorkflowTest](../../tests/Feature/AcademicRecoveryWorkflowTest.php), using real Laravel HTTP/cookie/CSRF middleware and persisted state assertions. This is not a real browser run. |

No LLM calculates authoritative conflicts or effort in these tests: provider responses only choose registered Tools, which delegate to existing deterministic Services. No new scheduling engine or altered planning heuristic is introduced.

## Demonstrated application defects and fixes

1. **Cutoff issuance type coercion.** The baseline Unit failure showed a floating cutoff encoded as an integer before token decode could reject its original type. `StudyPlanConfirmationService::issue` now checks the optional server cutoff before JSON encoding. Existing planning entry points already validate cutoffs; this closes an inconsistent internal issuance boundary without changing valid allocation behavior. Four-parameter tokens and valid cutoffs remain compatible; malformed-cutoff and token regression tests execute.
2. **Action identity capacity.** Native semester workflow tests and MySQL constraint reproduction showed registered 33/34-character action names exceeded VARCHAR(32), producing MySQL 1406. New forward migration `2026_11_12_000003_expand_ai_action_type_length.php` widens to VARCHAR(64), preserves all names/history/constraints/indexes, and refuses narrowing. No applied migration was edited. The current type contract and explicit predecessor upgrade/history tests cover the fix.
3. **Nested provider argument mismatch.** Both native fake providers retain nested JSON objects; the semester validator previously rejected those objects in date-edit arrays. `SemesterActionArgumentValidator` now recursively normalizes only schema-declared objects and preserves strict scalar, list, nullable, required-field and unknown-field checks. Dual-provider nested-argument and full semester action workflows cover the fix. No provider protocol or confirmation behavior was weakened.

Other changes repair tests/helpers/organization/documentation. Fixture mistakes discovered while writing new tests (wrong priority representation, missing institution, primary-key assumptions, duplicate import/discovery syntax) are recorded as intermediate failed runs, not application bugs. Temporary reflection diagnosis was removed. The first complete fresh run also exposed calendar fixture leakage into subsequent academic-period tests (17 failures and 4 errors); teardown cleanup fixes that test-order defect. The affected 117-case sequence was rerun without changing its assertions. Smoke discovery also caught a misplaced method tag during that edit; the tag was restored and the intended calendar, recovery, workflow and all nine semester cases were explicitly rediscovered.

## Database safety and environment evidence

Initial verification server: MySQL **8.4.10**, private freshly initialized datadir `/tmp/planner-test-rebuild.Gl8zM7oY/data/`, socket `/tmp/planner-test-rebuild.Gl8zM7oY/mysql.sock`, loopback port **13379**, database **student_planner_isolated_rebuild**, isolated local root with no password. Before schema creation, the server contained only MySQL system schemas. The newly created database had zero tables. A Laravel read-only bootstrap explicitly confirmed `testing`, driver `mysql`, exact selected database, port, fake mail `array` and queue `sync`. The proof is creation of the entire fresh server/datadir and observed emptiness, not its name or environment file.

Commands used an external temporary wrapper exporting the verified connection and `PLANNER_TEST_DATABASE`; no credentials or developer `.env` files were committed. Destructive PHPUnit processes ran sequentially. Separate MySQL worker clients were used only inside intentional concurrency tests. No development/staging/production database was connected or migrated. Existing migrations were applied only to disposable databases.

PHP runtime: **8.5.11**; PHPUnit **12.5.36**. A PHP 8.4 binary was unavailable, so no PHP 8.4-specific execution is claimed. Two complete fresh-run server identities: first `student_planner_isolated_cd4c2d8c85577693` at `/tmp/planner-phpunit.EvDZKTsm/data/`; rerun `student_planner_isolated_5bb22cd0d4814a9b` at `/tmp/planner-phpunit.FJoXqSb5/data/`, both loopback port 13380, separately verified empty and initialized by the disposable runner. These instances are shut down and removed on runner exit. Mail/queues/cache/session use local deterministic configuration. No real email, paid AI, browser or deployment verification was performed.

## Actual verification commands and results

The companion [machine-readable run ledger](verification-runs.json) includes baseline, intermediate failures, zero-test/aborted invocations and final reruns. Counts are expanded PHPUnit cases, not test methods. Intermediate failures are retained as evidence and do not represent the final readiness result. Categories overlap intentionally; never sum their counts to infer unique full-suite coverage.

| Executed selection | Tests passed | Assertions | Fail / error / skip / risky | Seconds |
|---|---:|---:|---|---:|
| Unit (final focused) | 2,818 | 9,012 | 0 / 0 / 0 / 0 | 7.153 |
| Calendar/recovery/provider focused | 84 | 1,269 | 0 / 0 / 0 / 0 | 19.679 |
| Migration + real concurrency | 519 | 6,219 | 0 / 0 / 0 / 0 | 213.485 |
| AI fake providers | 753 | 10,890 | 0 / 0 / 0 / 0 | 199.766 |
| Security including suite isolation | 143 | 2,021 | 0 / 0 / 0 / 0 | 26.994 |
| Cross-module workflows | 27 | 15,325 | 0 / 0 / 0 / 0 | 9.386 |
| Semester actions with Gemini fakes | 15 | 284 | 0 / 0 / 0 / 0 | 5.465 |
| Calendar → academic API isolation rerun | 117 | 1,218 | 0 / 0 / 0 / 0 | 4.472 |
| Smoke, intended selection restored | 103 | 1,428 | 0 / 0 / 0 / 0 | 20.885 |
| Complete offline, fresh server rerun | 6,782 | 74,032 | 0 / 0 / 0 / 0 | 447.786 |

The complete run contains **2,818 Unit + 3,964 Feature cases**, 6,782 total, with **74,032 assertions**. Every current Unit/Feature test class is included; Live is the explicitly separate paid opt-in boundary. The full run includes the latest semester discovery/orchestration method added after the earlier AI-group pass. No test was disabled or excluded to obtain this result.

Actual commands (connection environment supplied only by the verified temporary wrapper or fresh runner):

```sh
vendor/bin/phpunit --testsuite Unit --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/unit-final.xml
/tmp/planner-test-rebuild.Gl8zM7oY/run vendor/bin/phpunit --group migration --group concurrency --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/mysql-integration2.xml
/tmp/planner-test-rebuild.Gl8zM7oY/run vendor/bin/phpunit --group ai --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/ai-final.xml
/tmp/planner-test-rebuild.Gl8zM7oY/run vendor/bin/phpunit --group security --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/security-rerun.xml
/tmp/planner-test-rebuild.Gl8zM7oY/run vendor/bin/phpunit --group workflow --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/workflow-final.xml
AI_TEST_PROVIDER=gemini /tmp/planner-test-rebuild.Gl8zM7oY/run vendor/bin/phpunit --filter AiSemesterActionsTest --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/semester-gemini-rerun.xml
/tmp/planner-test-rebuild.Gl8zM7oY/run vendor/bin/phpunit --filter 'AcademicCalendarApiTest|AcademicContextPersistenceTest|AcademicContextSecurityTest|AcademicPeriodApiTest' --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/calendar-isolation-rerun.xml
/tmp/planner-test-rebuild.Gl8zM7oY/run vendor/bin/phpunit --configuration phpunit-smoke.xml --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/smoke-verified.xml
scripts/testing/phpunit-disposable-mysql.sh --log-junit /tmp/planner-test-rebuild.Gl8zM7oY/results/offline-rerun.xml
vendor/bin/pint --test
vendor/bin/phpunit --configuration phpunit-smoke.xml --list-tests
bash -n scripts/testing/phpunit-disposable-mysql.sh
git diff --check
```

Outputs were captured with `> <matching-label>.log 2>&1` under the ledger's artifact directory. Baseline and intermediate commands/results, including failed discovery probes, are retained in the ledger. The initial `--group migration,concurrency` selected zero tests under PHPUnit 12; it was corrected to repeated group flags and is **not** counted as a pass. The first complete run failed only on the calendar cleanup leak (6,782 cases, 17 failures, 4 errors); the complete rerun above supersedes it. A 102-case smoke pass during cleanup editing was incomplete discovery, so it was followed by explicit selection assertions and the restored 103-case execution.

Pint passed. PHP syntax inspection covered all 139 changed/new PHP files without errors; Bash syntax and whitespace checks passed. Smoke discovery explicitly confirmed unified calendar, owner recovery/replay, the full workflow and nine semester action cases. [Static checks](static-checks.json), [current inventory](current-inventory.json) and [tested source fingerprint](tested-source.json) record the final checked source set. No code coverage percentage is claimed.


## Remaining limitations and readiness

**Complete offline regression verification passed on PHP 8.5.11 and MySQL 8.4.10. There are no remaining offline test failures, errors, skips or risky cases.** The modernized suite preserves domain/security regressions, exercises every current registered Tool family and verifies the current forward migration path and automatic recovery workflow.

No database-dependent execution was blocked: disposable identity, initial emptiness and selected connection were established before destructive tests. Fresh-run instances were cleaned up by the runner; the initial manually provisioned test instance was shut down after verification. No production database/infrastructure or deployment workflow was changed.

Unverified boundaries remain explicit: PHP 8.4-specific execution (binary unavailable), actual browser/CORS behavior outside the Laravel cookie/CSRF harness, real scheduler operation, external mail/push delivery, paid LLM selection quality and production deployment. The original ODT attachment's fidelity to the available transcript is also unverified. These are not claimed by a passing offline suite. The separate Deployment Python and paid Live suites were inventoried but not executed here. CI itself was not triggered or declared green. Apply the new forward compatibility migration through the normal separately authorized deployment process; it was exercised only on disposable MySQL in this task.
