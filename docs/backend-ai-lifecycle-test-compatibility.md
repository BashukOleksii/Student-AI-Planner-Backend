# AI lifecycle test compatibility — 2026-11-11

Baseline local/remote main: `56f20c7987bd34f39f4d874ddb0f74dcb2ad74eb`.
Working tree was clean. Reviewed the latest completed CI verify job 130571870846
(run 43035141620): 6598 tests, 55018 assertions, 45 errors, 60 failures,
2 risky tests. That run was still running at initial inspection; its completed
log was retrieved during this task. Also reviewed the previous failed job.

## Root causes and bounded fixes

All implementation changes in this task are in tests/fixtures; application code,
REST contracts, authorization, MySQL migrations and planning algorithms are unchanged.

- `InteractsWithPlanningActions` treated every action other than reschedule as a
  date-range operation. The two lifecycle tools need an owned integer session ID
  and an eligible planned session in the past. Build the reminder through its real
  Service first, then move the session fixture into history. This preserves real
  service validation rather than relaxing it.
- Generic action tests assumed every source session was retired with a successor
  and every non-reschedule action used an encrypted planning token. Lifecycle
  actions instead fingerprint existing state and update the same identity. Check
  their explicit statuses, cancelled reminders, null protected payload and absence
  of successors. Inject business failures on `updated` for lifecycle actions and
  `created` for session allocation, retaining rollback and retry assertions.
- The cookie/CSRF smoke matrix had neither lifecycle arguments nor a source
  fixture. Create its source through REST planning, advance the application clock,
  refresh CSRF and log in normally again after browser-session expiry. Lifecycle
  actions still require explicit authenticated confirmation, receipt readback and
  replay. No middleware is disabled for those cases.
- Current discovery has 34 tools: 11 reads and 23 proposals. Old count assertions
  omitted the recovery read and two lifecycle proposals. Extend the read-only
  fixture list and update exact counts, including both native provider adapters.
- Historical migration tests must not derive the old three planning types from
  today's five-entry registry. Use the three historical literals, and explicitly
  assert that both later lifecycle types remain rejected by the historical CHECKs.
  Retain receipt/row preservation and downgrade checks. Add the new forward-only
  overlay to the existing disposable-schema rollback helper, so legacy round-trip
  tests reapply it without invoking its intentionally unsupported production down.
- `PlanningActionTest` constructed the service with nine dependencies instead of
  twelve. Supply the lifecycle Service/rules and Reminder dependency mocks. Add
  valid lifecycle argument cases and strict rejection cases for invalid IDs,
  unknown fields, coerced/out-of-range minutes and missed-action minutes.
- Preserve historical JSON snapshots unchanged. Add a separately named current
  snapshot for the 31 non-availability-proposal definitions, including the recovery
  read, lifecycle schemas, current schedule descriptions and Task status vocabulary.
  Both snapshot tests continue to compare exact definitions.

These are obsolete test assumptions rather than demonstrated application defects.
The existing missing-CHECK implementation defect was fixed in the baseline commit;
this task does not change or bypass it.

## Remaining scope

The complete-suite results below determine the remaining groups. In particular,
the shared effective-calendar presentation is not identical to the old legacy
schedule REST resource in some AI read parity assertions. Resolving that contract
requires its own focused review; assertions have not been weakened here. Semester
import/management, calendar constructor fixtures and fixed query budgets remain
outside this task.

## Verification

All database test runs used configured MySQL `student_planner_testing`; no database
test processes overlapped. Tests use existing guarded destructive-test setup.

- Complete suite: `vendor/bin/phpunit --no-progress --log-junit /tmp/backend-lifecycle-full.xml`.
  **6691 tests, 67722 assertions, 5 errors, 23 failures, 2 risky tests**;
  6663 tests passed. Duration: 635 seconds. All **2791 Unit tests passed**.
- In that full run, the nine suites covering AI planning actions, independent-process
  planning-action concurrency, historical planning/lesson/availability migrations,
  session lifecycle actions/migration, Gemini and OpenAI providers passed:
  **280 tests, 3505 assertions, zero errors/failures**. This includes all 55 planning
  concurrency cases and all 119 planning-action feature cases.
- Offline second-provider matrix:
  `AI_TEST_PROVIDER=gemini vendor/bin/phpunit tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php tests/Feature/AiAvailabilityActionsTest.php --no-progress`:
  **321 passed, 4494 assertions**.
- The complete run identified two final test-only compatibility assertions: the
  chat-persistence overlay count and the recovery overview's nested
  `period.timezone`. After correcting them, reran the additive chat migration
  round-trip, trusted-owner/fresh-timezone checks across every registered read,
  and read-only exact snapshots/no side effects across every registered read:
  **3 passed, 759 assertions**. Command:
  `vendor/bin/phpunit tests/Feature/AiChatPersistenceTest.php tests/Feature/AiToolsIntegrationTest.php --filter 'test_additive_migration_round_trip_preserves_existing_conversations_and_messages|test_explicit_trusted_owner_and_fresh_persisted_timezone_override_global_auth_and_stale_profile|test_every_registered_tool_is_read_only_with_exact_database_snapshots_and_no_external_side_effects' --no-progress`.
- Pint and `git diff --check` passed. No full-suite rerun after those final two
  assertions; the complete-suite counts above are the measured result, not a claim
  that the final tree has a green full suite.

## Known unresolved failures

After the two targeted corrections, 21 failure cases and 5 error cases from the
full report remain unresolved (this is a case accounting, not another suite run):

- AI calendar integration: schedule projection differs from the legacy REST
  resource; corrupted historical foreign links fail closed; a failure-injection
  mock targets the old ScheduleService instead of the effective-calendar reader.
  Two AiToolsIntegrationTest failures and one browser read-parity failure remain.
- Fixed query budgets: four empty-expansion free-time cases and five planning /
  semester read/management budget assertions.
- Semester contract/schema expectations: nine assertions spanning auto-import
  defaults/catalog handling, teacher inputs, metadata and migration column order.
- Five errors: planning delegation mock missing `calendarSources()`, ambiguous
  teacher import expectation, two semester concurrency fixtures lacking a valid
  singular teacher, and semester migration FK teardown ordering.
- Two risky tests still leave handlers installed (the calendar mock/error cases).

Next smallest isolated task: reconcile the three AI effective-calendar read
integration fixtures with the established shared-calendar contract, retaining
cross-user masking, fail-closed corrupt-history behavior and SQL error sanitizing.
The semester migration FK teardown also warrants a separate correctness-focused
review before changing any migration or downgrade contract.

## Changed files

- `docs/backend-ai-lifecycle-test-compatibility.md`
- `tests/Concerns/InteractsWithPlanningActions.php`
- `tests/Concerns/UsesForwardOnlyMigrations.php`
- `tests/Feature/AiAvailabilityActionMigrationTest.php`
- `tests/Feature/AiAvailabilityActionsTest.php`
- `tests/Feature/AiChatPersistenceTest.php`
- `tests/Feature/AiLessonActionMigrationTest.php`
- `tests/Feature/AiPlanningActionConcurrencyTest.php`
- `tests/Feature/AiPlanningActionMigrationTest.php`
- `tests/Feature/AiPlanningActionsTest.php`
- `tests/Feature/AiStudyAvailabilityToolTest.php`
- `tests/Feature/AiSubtaskReminderActionsTest.php`
- `tests/Feature/AiTaskActionPersistenceTest.php`
- `tests/Feature/AiToolsIntegrationTest.php`
- `tests/Feature/BackendIntegrationSmokeTest.php`
- `tests/Feature/GeminiGenerateContentProviderTest.php`
- `tests/Feature/OpenAiResponsesProviderTest.php`
- `tests/Fixtures/AI/tool-definitions-lifecycle-before-availability-actions.json`
- `tests/Unit/AiAvailabilityToolSchemaTest.php`
- `tests/Unit/AiToolFoundationTest.php`
- `tests/Unit/AvailabilityActionTest.php`
- `tests/Unit/LessonActionTest.php`
- `tests/Unit/PlanningActionTest.php`
- `tests/Unit/SubtaskReminderActionTest.php`
- `tests/Unit/TaskActionTest.php`
