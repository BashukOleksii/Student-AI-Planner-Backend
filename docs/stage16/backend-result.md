# Stage 16A — Backend Task missed status

## Dependency audit and intended behavior (before implementation)

Baseline: `c438fc99d059a62cfe348f832d0d1ca798bb39a2`, clean `main`.

- TaskStatus is shared with the Subtask cast. Add `missed` for Tasks only; keep Subtask SQL vocabulary, input rules and lifecycle unchanged. A shared `isUnfinished()` predicate is used only where a Task can be pending or missed.
- TaskService writes under User → owned Task locks. Add idempotent `miss`, reject completed → missed with existing HTTP 409/message convention, complete either unfinished state, reopen completed/missed to pending. No child/session/reminder changes when marking missed.
- TaskController/TaskPolicy/TaskResource already supply lifecycle orchestration, ownership hiding and enum serialization. Add only POST `/api/tasks/{task}/miss`. ListTasksRequest derives its filter vocabulary from TaskStatus. SaveTaskRequest/TaskInputRules deliberately ignore status/completed_at in ordinary POST/PATCH.
- PlanningWorkSelectionService excludes completed work, not all non-pending work. StudyRecoveryPreviewService composes existing selection/remaining effort/persistence preview. Preserve those algorithms; verify missed Tasks through REST preview and secured confirmation.
- ReminderTargetService currently requires parent Task pending. Broaden only the parent predicate to unfinished; keep child pending/session planned/future checks and completion/deletion cancellation unchanged. Preserve sent Inbox history.
- ProgressStatisticsService shares current aggregate code for Tasks/Subtasks. Add missed/overdue_missed only to Task current counts; pending/overdue_pending keep their exact-status meanings. Completion percentage still uses all active Tasks. Daily trends/comparisons remain completion/session activity.
- AI Task proposal guards currently assume pending completion and completed reopening. Align existing complete/reopen proposals with the REST transitions; raw-state fingerprints already include status and reject reviewed-state changes. Existing ToolRegistry derives list_tasks status enum automatically. Add no Tool/action type/provider change.
- Replace only the two Task status/completion CHECKs in one MySQL ALTER through a new forward-only migration. Preserve rows/IDs, unrelated checks/indexes/FKs; down refuses without changing data, following the existing Stage 11 convention.
- Tests must use a newly verified `student_planner_isolated_*` schema through existing TestDatabase safeguards. No migration or destructive test may target retained `student_planner` or the Stage 15 integration database.

Implementation, verification and diagnostics follow. Final result: **PASS**, with no unresolved Backend blocker. The operator rollout migration remains deliberately unapplied to retained databases.

## Implementation

Stage 16A introduces persisted Task `missed`, authenticated `POST /api/tasks/{task}/miss`, complete/reopen support for missed work and additive Task statistics counters. All writes remain in TaskService, serialized by the existing owner/Task locks. The Controller calls existing Policy update authorization and returns the unchanged TaskResource shape. The empty lifecycle request follows existing complete/reopen conventions; generic POST/PATCH never becomes a status writer.

`TaskStatus::isUnfinished()` centralizes the Task pending/missed predicate for completion, parent Reminder eligibility and existing AI proposals. Subtask SQL/API remain pending/completed despite their shared PHP cast enum. Planning and recovery Services require no new algorithm: their completed-work exclusion already admits missed Tasks; tests now exercise the same flow for both unfinished statuses.

The single forward-only migration atomically replaces `tasks_valid_status` and `tasks_completion_consistency`. It preserves existing active/deleted rows and IDs, unrelated CHECKs, FKs, indexes and defaults. `down()` refuses before any SQL, matching the existing Stage 11 policy. No migration was executed against retained/application databases.

Completion still cancels eligible dependent scheduled reminders. Marking missed preserves reminders, completed children and every StudySession; deadline edits and delivery accept missed parents. Sent Reminder/Inbox history remains intact. Statistics add only `tasks.current.missed` and `overdue_missed`; current totals/percentage and period/trend/comparison semantics remain unchanged.

Existing AI complete/reopen proposal guards and descriptions now match TaskService. Raw-state fingerprints already include status, so an externally marked-missed reviewed target becomes `action_stale` with no receipt or write. Tool count remains 27 and action-type count remains 18. No provider, Tool, pending-action type, autonomous job, migration of AI storage or alternate planning confirmation was introduced.

The existing test-only forward-overlay helper now excludes both Stage 11 and Stage 16 ledger entries before exercising legacy destructive rollback on the isolated schema. Exact expected removed-entry counts change from 1 to 2. Production down is never bypassed; dedicated migration coverage asserts it refuses and preserves missed rows. This preserves the original legacy rollback coverage without pretending the new migration is reversible.

## Changed files

| Files | Purpose |
| --- | --- |
| `app/Enums/TaskStatus.php` | Missed case and unfinished predicate |
| `app/Services/Tasks/TaskService.php` | Miss, complete/reopen transitions, failed-save protection |
| `app/Http/Controllers/Api/TaskController.php`, `routes/api.php` | Thin authenticated miss operation |
| `bootstrap/app.php` | Existing HTTP conflict rendered as safe message/409 for Task routes, including debug mode |
| `app/Services/Reminders/ReminderTargetService.php` | Missed parent remains unfinished/eligible |
| `app/Services/Progress/ProgressStatisticsService.php` | Two additive Task-only current counters in the same aggregate query |
| `app/Services/AI/Actions/TaskActionProposalService.php`, `TaskActionRegistry.php` | Existing complete/reopen actions accept correct source states |
| `database/migrations/2026_11_08_000001_add_missed_task_status.php` | Atomic CHECK expansion; forward-only refusal |
| `tests/Feature/TaskMissedMigrationTest.php` | Actual legacy upgrade, preserved rows, constraints and downgrade refusal |
| `tests/Feature/TaskMissedStatusTest.php` | Explicit lifecycle, Task-only effects, list/PATCH behavior and completed conflict |
| `tests/Feature/TaskLifecycleTest.php`, `TaskPersistenceTest.php` | Reused ownership/locking/idempotency cases for miss and exact enum vocabulary |
| `tests/Feature/StudyRecoveryApiTest.php` | Reused read-only preview, effort/reservations, history, deadline and estimate cases for pending/missed |
| `tests/Feature/ReminderDeliveryTest.php` | Eligibility for Task/child/session targets, synchronization, delivery and sent-history preservation |
| `tests/Feature/ProgressStatisticsApiTest.php`, `ProgressStatisticsIntegrationTest.php` | Additive exact responses, missed/overdue ownership and denominator checks |
| `tests/Feature/AiTaskActionsTest.php` | Missed complete/reopen receipts, replay and stale proposal protection |
| `tests/Concerns/UsesForwardOnlyMigrations.php`, `tests/Feature/AiChatPersistenceTest.php`, `AiTaskActionPersistenceTest.php` | Existing disposable-schema legacy rollback coverage recognizes the second forward-only overlay |
| `docs/stage16/task-status-api-contract.md`, `backend-result.md` | Exact handoff, implementation and execution evidence |
| `docs/tasks.md`, `business-rules.md`, `domain-model.md`, `database-schema.md`, `database-review-notes.md`, `progress-statistics.md`, `reminders.md` | Update current status/constraint/eligibility definitions without altering historical migrations |

Task Model, Requests, Resource, Policy, deterministic planning/recovery, AI confirmation and ToolRegistry reuse their existing implementations; no edits are needed there. Frontend was not accessed. No branches, worktrees, PRs, GitHub inspection, original `.env` modification or user-change resets occurred.

## Verified test isolation

Created a previously absent empty MySQL schema **`student_planner_isolated_s16_20261108_124554_8d02`** using a private operational bootstrap under ignored `.codex/stage16/`. No real data copied; this schema was not the Stage 15 fixture database. Before any destructive tests, verified:

- APP_ENV testing, MySQL driver; DB_DATABASE and PLANNER_TEST_DATABASE match actual `SELECT DATABASE()` and existing TestDatabase accepted-name policy.
- Selected schema had **0 tables** via `information_schema.tables WHERE table_schema = DATABASE()`.
- MySQL **8.4.10**, **REPEATABLE-READ**, performance_schema enabled and lock-instrumentation reads allowed.
- Original config cache untouched; isolated APP_CONFIG_CACHE and storage paths; private generated key and connection credentials never printed or committed.
- Mail array, queue sync, sessions/cache array, provider credentials absent, paid flags disabled. AI provider behavior is exercised with existing offline fixtures only.

Tests retain the existing pre-RefreshDatabase guard and use the supported isolation selector; no guard changes or fake hosted-CI environment variables were used. Retained `student_planner`, its jobs and the Stage 15 database were not migrated, reset, truncated or used as test fixtures. The isolated schema is not automatically dropped at completion; individual test cleanup remains confined to it.

## Tests and exact outcomes

Commands run from Backend root after `source .codex/stage16/runtime.env`:

| Check | Command / outcome |
| --- | --- |
| Initial Task-focused run | `vendor/bin/phpunit tests/Feature/TaskMissedMigrationTest.php tests/Feature/TaskMissedStatusTest.php tests/Feature/TaskLifecycleTest.php tests/Feature/TaskPersistenceTest.php`: exit 1, 66 tests / 65 passed / 1 failed / 663 assertions; see D16A-01 |
| First Task recheck | Same command: exit 1, 66 tests / 65 passed / 1 failed / 669 assertions; see D16A-02 |
| Corrected Task recheck | Same command: exit **0**, **66 passed**, **669 assertions**, 0 failures |
| Focused integrations | `vendor/bin/phpunit tests/Feature/StudyRecoveryApiTest.php tests/Feature/ReminderDeliveryTest.php tests/Feature/ProgressStatisticsApiTest.php tests/Feature/ProgressStatisticsIntegrationTest.php tests/Feature/AiTaskActionsTest.php`: exit **0**, **188 passed**, **2421 assertions**, 0 failures |
| Pint formatting | `vendor/bin/pint --dirty`: exit **0**; import ordering fixed in bootstrap |
| Changed/new PHP style | Explicit `vendor/bin/pint --test` over all 22 changed/new PHP files: exit **0**, PASS |
| Complete offline suite | `vendor/bin/phpunit`: exit **0**, **5,676 passed**, **55,708 assertions**, 0 failures, 437.083 s; one full-suite execution |
| Offline Gemini action matrix | `AI_TEST_PROVIDER=gemini vendor/bin/phpunit tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php`: exit **0**, **250 passed**, **3,456 assertions**, 54.001 s |
| Repository-wide style | `vendor/bin/pint --test`: exit **0**, PASS |
| Whitespace/doc links | `git diff --check` and Stage 16 local Markdown-link existence check: exit **0**, PASS |
| Final schema/routes | Read-only SQL: **27 applied migrations**, Stage 16 applied only in the isolated schema; Task CHECKs include missed, Subtask CHECKs remain two-state. `php artisan route:list --path=api/tasks --except-vendor --no-ansi`: exit **0**, tasks.miss/complete/reopen registered |

The final full suite includes the final test-only expired-deadline fixture extension for missed Tasks. The local CI workflow requires a full offline suite and a separate Gemini action fixture matrix; both ran only on the independently verified isolated MySQL schema and passed. Hosted `.github/scripts/verify-ci-migrations.php` was not run locally: it intentionally requires real GitHub-hosted service identity, which must not be fabricated. No claim of a new hosted CI run is made. The migration upgrade/downgrade-refusal tests and full suite provide the local migration evidence.

## Diagnostics

### D16A-01 — new miss case inherited an inapplicable SQL-field assertion

- Component: lifecycle test; category **testing environment / test expectation**, severity LOW.
- Command: initial 4-file Task-focused PHPUnit command above; exit **1**.
- Expected by the inherited assertion: every transition updates `completed_at` in SQL.
- Observed: pending → missed correctly wrote status only; `completed_at` was already null and Eloquent left it clean. Failure: SQL did not contain `` `completed_at` = ``.
- Confirmed cause: the old complete/reopen assertion was applied to a new state-preserving timestamp case. No database consistency or business-data loss occurred.
- Fix: retain the exact original assertion for complete/reopen; assert **no completed_at write** plus persisted null for miss, while retaining single-write, lock-order and transaction-depth checks.
- Reverification: corrected Task suite 66/66 PASS. Application behavior was not altered to satisfy the assertion.

### D16A-02 — verification read contaminated the observed transaction query list

- Component: lifecycle test instrumentation; category **testing environment**, severity LOW.
- Command: first 4-file Task recheck; exit **1**.
- Expected: every captured Service query belongs to the Service transaction depth.
- Observed: added `fresh()` null verification executed after Service returned, while the listener was still collecting; its outer depth 1 differed from expected 2.
- Confirmed cause: test instrumentation timing, not loss of transactional locking.
- Fix: perform the persisted-null read after asserting the captured Service queries. Single-write and all original complete/reopen lock/depth assertions remain unchanged.
- Reverification: corrected Task suite exit **0**, 66 passed / 669 assertions.

### D16A-03 — documentation patch context mismatch

- Component: editing operation; category **tooling / documentation**, severity LOW.
- Command: Bash `apply_patch` batch; exit **1**, expected `#` context absent in `docs/tasks.md`.
- Observed: patch verification refused the whole batch before writing; source and fixtures were not partly changed.
- Fix: inspect the actual heading and apply exact file contexts; later diff review confirmed intended edits only.
- Reverification: subsequent patches and `git diff --check` passed. No application correction required.

Read-only inspection also encountered several guessed historical file names that do not exist (for example `StoreTaskRequest`, `StudyRecoveryPreviewTest`). `rg --files` identified the actual shared `SaveTaskRequest`, `StudyRecoveryApiTest` and migration/persistence tests. These were inspection-path misses, not application defects; no tests or functionality were removed to work around them.

## Limitations and Stage 16B requirements

- No `missed_at` or status transition history is introduced; current state does not reconstruct historical missed Task counts.
- Marking missed does not recover work automatically or create a StudySession; the existing preview and explicit token confirmation remain mandatory.
- No additional Subtask status, AI Tool or action type. Natural-language marking missed needs a separate reviewed proposal/confirmation capability; details are in the API contract.
- Frontend Stage 16B must extend the Task union/labels/filter/actions and additive Task statistics counts, keep Subtask types unchanged, handle completed → missed conflict, and reuse existing recovery/confirmation UI.
- Operator rollout must apply the new forward migration to an approved database before using missed. It was deliberately not applied to retained `student_planner`. Old code cannot read missed enum values after new writes; use a reviewed forward compatibility correction rather than lossy downgrade.
- No live Gemini, Frontend/browser acceptance, production migration or hosted CI execution is claimed for this Backend milestone.

See [the exact Task-status API contract](task-status-api-contract.md) for endpoint payloads, error bodies, transition matrix and all compatibility semantics.
