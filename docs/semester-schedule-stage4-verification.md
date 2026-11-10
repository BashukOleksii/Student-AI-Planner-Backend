# Stage 4 delivery and verification

Baseline: local `main` and `git ls-remote origin refs/heads/main` both at
`9a00da6abcaa43d7acd67f534c3b4ececee0b86b`; initial working tree clean. Read
AGENTS.md, current architecture/domain/business/persistence docs, foundation,
Stage 2/3 contracts, ADR-006/007 and relevant implementations before changes.
Work remains on main, with no branch/worktree/PR or push.

## Delivered behavior

See [the full API contract](semester-schedule-management.md) for all 16 added
endpoints, exact field/response definitions and JSON examples. The four existing
Stage 2 reads and all legacy workflows retain their contracts.

[ADR-008](decisions/ADR-008-semester-schedule-management.md) was recorded before
schema/import reconciliation edits. The later monotonic revision decision was
recorded before implementing its column and write integration, to close a rapid
edit/revert ABA case under second-precision timestamps.

- Manual semester creation, metadata edits and explicit dependency-safe deletion.
- Recurring-rule CRUD with both-parity, duplicate-pair, half-open overlap and
  whole-semester timezone/transition validation through the existing calculator.
- Full snapshots, copy-base plus partial lesson edits/removals/additions, empty
  cancellation, restoration and stable supplied snapshot child IDs for pair swaps.
- Working Saturday copies the source date's effective parity/replacement state
  in the same Monday-first week; later source changes do not propagate.
- Shared authoritative graph loading for management/import; owner-first locking,
  full graph fingerprint and monotonic revision reject stale/lost writes.
- Permanent manual flags preserve original import baseline/key/provenance. Deletion
  and imported-identity moves persist suppressed slots, with explicit confirmed
  release required before reimport recreation. Historical receipts are unchanged.
- Source deletion detaches only nullable snapshot links; all writes and resulting
  state are transactional, with checked model saves/deletes and safe error messages.

One additive migration:
`2026_11_10_000003_add_semester_schedule_management_protections.php` adds revision,
manual flag/CHECK and owned suppression table/FK/unique/CHECKs. Original migrations
were not edited. Old migration round-trip tests remove/reapply the later overlay
without weakening their existing assertions. No retained application DB migration,
reset or legacy data conversion was performed.

## Test environment and commands

Database runs use only the guarded disposable MySQL schema:

```bash
export DB_DATABASE=student_planner_isolated_semester_foundation
export PLANNER_TEST_DATABASE=student_planner_isolated_semester_foundation
```

Workers independently validate this selector and their actual MySQL database
before access. Tests never use SQLite or reset the retained application database.

Early verification passed 91 existing Stage 2/3 tests (906 assertions), then 52
management API cases (810 assertions). The combined core passed 99 tests / 1271
assertions before final monotonic-revision and repair tests.

Final targeted run:

```bash
vendor/bin/phpunit tests/Feature/SemesterScheduleManagementTest.php tests/Feature/SemesterScheduleManagementImportTest.php tests/Feature/SemesterScheduleManagementConcurrencyTest.php tests/Feature/SemesterScheduleManagementPersistenceTest.php tests/Feature/SemesterScheduleImportWorkflowTest.php tests/Feature/SemesterScheduleImportConcurrencyTest.php tests/Feature/SemesterScheduleImportPersistenceTest.php tests/Feature/SemesterScheduleMigrationTest.php tests/Feature/EffectiveScheduleServiceTest.php tests/Feature/SemesterScheduleReadApiTest.php tests/Feature/SemesterScheduleReadConsistencyTest.php tests/Unit/EffectiveScheduleCalculatorTest.php tests/Unit/SemesterScheduleImportPlannerTest.php tests/Feature/PlanningMigrationTest.php tests/Feature/LessonApiTest.php tests/Feature/LessonSecurityTest.php tests/Feature/LessonLifecycleTest.php tests/Feature/ScheduleViewTest.php tests/Feature/ScheduleImportCommitTest.php tests/Feature/ScheduleImportPreviewApiTest.php tests/Feature/ScheduleImportPreviewRevalidationTest.php tests/Feature/ScheduleOccurrenceResolverTest.php tests/Unit/ScheduleWorkbookParserTest.php --no-progress --log-junit /tmp/semester-stage4-targeted.xml
```

**PASS: 611 tests, 8676 assertions**, 70422 ms; zero errors, failures or skips.

Full regression command (same guarded disposable MySQL environment):

```bash
vendor/bin/phpunit --no-progress --log-junit /tmp/semester-stage4-full.xml
```

**PASS: 6513 tests, 64044 assertions**, 422365 ms; zero errors, failures or skips.
The final application/test code was unchanged between targeted and full runs.
JUnit totals were checked directly in both generated reports. Additional checks:

```bash
vendor/bin/pint --test
git diff --check
php artisan route:list --path=api/semester-schedules --except-vendor
```

The route list contains 20 semester routes: 16 added management routes and the
four preserved Stage 2 reads. Pint, working/staged diff checks passed. Nine fenced
JSON examples parse, new-document relative links resolve, and the inventory below
matches all 41 changed files. No old migration or prohibited legacy implementation
was changed. The complete patch is available locally at `/tmp/semester-stage4.diff`;
the final local commit SHA is provided in the delivery response.

## Acceptance coverage

New Feature cases cover manual creation/uniqueness/owned institutions, CRUD and
stable rule IDs, date propagation/parity, rejected collisions and accepted adjacent
intervals, catalog ownership, all new endpoints' guest/foreign denial, nested
same-owner parent isolation, date validation, replacements and single/full-day
cancellations, additions, pair swaps, frozen snapshots and restored current base.
Working Saturdays cover all five source weekdays, alternating parity, source
replacements/empty cancellations, independent editing, source bounds and isolation.

Integration cases cover manual imported edits/identity moves, immutable baselines,
permanent protection after restoring baseline values, manual deletion blocked on
same XLSX, explicit release + revalidate + confirmed recreation, snapshots retained
with detached source links, compatible changed reimport, six kinds of management
staleness and historical receipt replay. Monotonic revisions also catch edit/revert
within one frozen clock second; no-op patches/identical imports preserve state.

Four separate-process MySQL races prove competing edits, delete versus snapshot
copy, simultaneous period creation, and management versus import serialization.
Injected child insert failures and cancelled source deletion roll back rewrite,
suppression and detachment. MySQL tests enforce owner FK, unique slots, scalar
CHECKs, manual flag domain, additive round trips and guarded downgrade. Plain unit
cases cover suppressed all/alternating intersections and permanent flag behavior.

Management reads are SELECT-only and have a bounded graph query count. Persisted
profile timezone wins over request/model input; Kyiv gaps/folds and valid transition
spans are checked, as is an Apia skipped weekday months beyond the first two cycles.
An externally invalidated snapshot can be viewed as owned definitions and repaired
through a valid full mutation; unchanged Stage 2 effective reads fail closed.

## Limits and deployment

Apply the additive migration through the normal deployment process. Existing
uncommitted import previews conservatively refresh/reconfirm after deployment;
committed receipt replay is unchanged. No automatic legacy adoption/backfill,
force import, suppression release, history purge, bulk rule editing or frontend/AI/
planning integration is included. Import history intentionally blocks schedule
DELETE; explicit per-rule/exception/suppression cleanup only permits deleting a
schedule without such history. Broken catalog/period/provenance ownership must be
repaired first. Existing catalogs keep their current lifecycle APIs.

## Changed files

Application:

- `app/Http/Controllers/Api/SemesterScheduleManagementController.php`
- `app/Http/Requests/SaveScheduleDateExceptionRequest.php`
- `app/Http/Requests/SaveSemesterScheduleRequest.php`
- `app/Http/Requests/SaveWeeklyLessonRuleRequest.php`
- `app/Http/Requests/SemesterScheduleManagementRequest.php`
- `app/Http/Requests/StoreWorkingSaturdayRequest.php`
- `app/Http/Resources/ScheduleDateExceptionResource.php`
- `app/Http/Resources/SemesterScheduleImportResource.php`
- `app/Http/Resources/SemesterScheduleLessonDefinitionResource.php`
- `app/Http/Resources/SemesterScheduleManagementResource.php`
- `app/Http/Resources/SemesterScheduleSuppressedSlotResource.php`
- `app/Models/SemesterSchedule.php`
- `app/Models/SemesterScheduleSuppressedSlot.php`
- `app/Models/WeeklyLessonRule.php`
- `app/Policies/SemesterSchedulePolicy.php`
- `app/Services/Imports/SemesterScheduleImportPlanner.php`
- `app/Services/Imports/SemesterScheduleImportService.php`
- `app/Services/Schedule/SemesterScheduleManagementService.php`
- `app/Services/Schedule/SemesterScheduleStateService.php`
- `routes/api.php`

Migration:

- `database/migrations/2026_11_10_000003_add_semester_schedule_management_protections.php`

Tests:

- `tests/Feature/SemesterScheduleImportPersistenceTest.php`
- `tests/Feature/SemesterScheduleManagementConcurrencyTest.php`
- `tests/Feature/SemesterScheduleManagementImportTest.php`
- `tests/Feature/SemesterScheduleManagementPersistenceTest.php`
- `tests/Feature/SemesterScheduleManagementTest.php`
- `tests/Feature/SemesterScheduleMigrationTest.php`
- `tests/Support/semester_management_worker.php`
- `tests/Unit/SemesterScheduleImportPlannerTest.php`

Documentation:

- `docs/architecture.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- `docs/database-schema.md`
- `docs/decisions/ADR-008-semester-schedule-management.md`
- `docs/domain-model.md`
- `docs/schedule.md`
- `docs/semester-schedule-foundation.md`
- `docs/semester-schedule-import.md`
- `docs/semester-schedule-management.md`
- `docs/semester-schedule-read-api.md`
- `docs/semester-schedule-stage4-verification.md`
