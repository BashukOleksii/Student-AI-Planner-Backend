# Stage 3 verification: Complete semester Excel import

Baseline: `main` at `a3df77520a6a5bb7a7110a79ee67f472c21a42dc`, verified against
`git ls-remote origin refs/heads/main`. The tree was initially clean, including
after the interrupted task start. Work stayed on main; no new branch/worktree/PR.

## Delivered workflow and decisions

[ADR-007](decisions/ADR-007-semester-schedule-import.md) was written before code.
The [API contract](semester-schedule-import.md) documents multipart/JSON requests,
complete response fields, sample preview/receipt, statuses, reconciliation and
migration requirements.

- POST `/api/semester-schedule-imports`: parse the real XLSX, resolve owned names,
  persist the weekly review, errors, impact and random revision token.
- GET `/api/semester-schedule-imports/{semesterScheduleImport}`: read stored state.
- POST `/api/semester-schedule-imports/{semesterScheduleImport}/revalidate`: refresh
  the saved parsed template against current owned state, with a new token.
- POST `/api/semester-schedule-imports/{semesterScheduleImport}/commit`: require
  boolean confirmation + exact revision, recheck locked state, atomically apply
  recurring rules and store a durable idempotent receipt.

The immutable slot key and last imported baseline distinguish managed/unmodified
rules from protected manual edits. All reviewed deletions are explicit; a snapshot
loses only its nullable deleted-source link, with its independent fields/ID intact.
No force-overwrite, hidden catalog creation, Legacy Lesson adoption or materialized
occurrence copies were introduced. A stale commit changes only its preview and
requires a new review/token. Committed same-token retries perform no domain writes.

One additive migration adds the separate import table and nullable weekly-rule
provenance/key/baseline. Legacy provenance points to a different batch type and is
not repurposed. The new FK support index is explicitly named for safe empty-domain
round trips; DATETIME(6) and the model date format preserve committed microseconds.
Retained import-history downgrade refuses. No application database was migrated,
reset or backfilled. Existing period deletion now returns a validation error for
this new history dependency, instead of leaking an FK failure.

The old exact-name resolver has an optional locking-read flag, false by default;
old behavior and JSON contracts are preserved. The original foundation round-trip
test now removes/reapplies the later dependent overlay while checking the same
original ten constraints; no assertions were weakened.

## Measured verification

Environment: PHP 8.5.11, MySQL 8.4.10. Every database test used the existing guard
with the explicitly disposable `student_planner_isolated_semester_foundation`
schema. Independent workers validate the same database selector before access.
No production/retained development data or migration reset was involved.

Process environment for the commands:

```bash
export DB_DATABASE=student_planner_isolated_semester_foundation
export PLANNER_TEST_DATABASE=student_planner_isolated_semester_foundation
```

The first combined planner/workflow/migration run passed 44 tests / 514 assertions.
The core run with process concurrency and persistence passed 54 / 605. Final broad
targeted coverage, after boundary/stale/rollback cases were added:

```bash
vendor/bin/phpunit tests/Feature/SemesterScheduleImportWorkflowTest.php tests/Feature/SemesterScheduleImportConcurrencyTest.php tests/Feature/SemesterScheduleImportPersistenceTest.php tests/Unit/SemesterScheduleImportPlannerTest.php tests/Feature/SemesterScheduleMigrationTest.php tests/Feature/PlanningMigrationTest.php tests/Feature/SemesterScheduleReadApiTest.php tests/Feature/SemesterScheduleReadConsistencyTest.php tests/Unit/EffectiveScheduleCalculatorTest.php tests/Feature/ScheduleImportPreviewApiTest.php tests/Feature/ScheduleImportCommitTest.php tests/Feature/ScheduleImportPreviewRevalidationTest.php tests/Feature/ScheduleOccurrenceResolverTest.php tests/Feature/AcademicPeriodApiTest.php --no-progress --log-junit /tmp/semester-stage3-targeted.xml
```

**PASS: 358 tests, 5736 assertions**, 41261 ms.

New coverage: 37 workflow cases, two independent-process concurrency cases, two
MySQL persistence/migration cases and 15 plain PHPUnit planner/calendar cases.
Existing legacy Excel tests and Stage 2 reads remain in this targeted run.

```bash
vendor/bin/pint --test
git diff --check
php artisan route:list --path=api/semester-schedule-imports --except-vendor
```

Pint and diff checks passed. Exactly four new workflow routes were listed.
Full regression, with the same disposable database environment:

```bash
vendor/bin/phpunit --no-progress --log-junit /tmp/semester-stage3-full.xml
```

**PASS: 6430 tests, 62804 assertions**, 421877 ms. No failures or skipped tests.
The final application/test code was unchanged between targeted and full runs.
Six JSON examples parse successfully, relative links in the three new documents
resolve, and this inventory matches all 35 changed files. Final Pint and diff
checks also passed. The full patch is available locally at
`/tmp/semester-stage3.diff`; the local commit is recorded in the delivery report.

## Acceptance evidence and limits

The real fixture creates 16 weekly rules, zero dated Lessons, and 15 Stage 2
occurrences in each of two weeks. Tuesday pair 4 alternates, configurable first
parity is honored, pair labels map to numbers 1–4, and no type is inferred.
Missing/foreign/ambiguous catalogs produce review errors; real existing subject/
teacher POST/PATCH APIs repair them before revalidate/confirm succeeds.

Tests verify duplicate-free retry/re-import, no rule/metadata timestamp touches for
identical imports, in-place updates, combined add/update/delete, manual preservation
without adoption, protected edited imports, stale catalog/profile/period/metadata/
rule/snapshot revisions, two previews of one period, explicit strict confirmation,
file/field/storage bounds, guest/cross-user denial and read-only GET state.

Snapshot tests keep one-date replacements and working Saturdays, retain source links
on ordinary updates, detach only reviewed deletion links, and keep snapshot fields
and IDs. Injected insert failure, second-delete failure and a cancelled final
receipt save prove full rollback, including metadata, earlier deletion/detachment,
new schedule/rules and receipt. Real competing PHP processes prove one successful
application and identical replay, plus current-state rechecking after waiting for
an owner lock. Timezone validation detects a skipped weekday well beyond the first
two weeks and frozen DST-invalid snapshots without storing dated copies.

The old Excel API still creates its independent 30 concrete Lessons after a new
semester import. No FreeTimeService/planning, AI Tool/schema, frontend,
reminder/statistics, old Lesson/commit implementation or Stage 2 Resource contract
was changed.

Limits: the known XLSX dialect only; 5 MiB uploads; exact current owned names; no
force-overwrite, auto-create/mapping UI, cancellation/deletion endpoint or automatic
legacy conversion. Changed workbook/options require a new upload. Fingerprinting
all owned catalogs is deliberately conservative, so unrelated catalog changes can
require re-review. Broken saved dependencies can require repair before revalidation.
Imported semester rules are not yet a source for the unchanged legacy planner/AI.

## Changed files

Application and migration:

- `app/Http/Controllers/Api/SemesterScheduleImportController.php`
- `app/Http/Requests/CommitSemesterScheduleImportRequest.php`
- `app/Http/Requests/ReviewSemesterScheduleImportRequest.php`
- `app/Http/Requests/StoreSemesterScheduleImportRequest.php`
- `app/Http/Resources/SemesterScheduleImportResource.php`
- `app/Models/AcademicPeriod.php`
- `app/Models/SemesterScheduleImport.php`
- `app/Models/User.php`
- `app/Models/WeeklyLessonRule.php`
- `app/Policies/SemesterScheduleImportPolicy.php`
- `app/Services/Academic/AcademicPeriodService.php`
- `app/Services/Imports/ScheduleOccurrenceResolver.php`
- `app/Services/Imports/SemesterScheduleImportPlanner.php`
- `app/Services/Imports/SemesterScheduleImportService.php`
- `app/Services/Schedule/EffectiveScheduleCalculator.php`
- `bootstrap/app.php`
- `database/migrations/2026_11_10_000002_create_semester_schedule_imports.php`
- `routes/api.php`

Tests:

- `tests/Feature/SemesterScheduleImportConcurrencyTest.php`
- `tests/Feature/SemesterScheduleImportPersistenceTest.php`
- `tests/Feature/SemesterScheduleImportWorkflowTest.php`
- `tests/Feature/SemesterScheduleMigrationTest.php`
- `tests/Support/semester_import_worker.php`
- `tests/Unit/SemesterScheduleImportPlannerTest.php`

Documentation:

- `docs/architecture.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- `docs/database-schema.md`
- `docs/decisions/ADR-007-semester-schedule-import.md`
- `docs/domain-model.md`
- `docs/schedule.md`
- `docs/semester-schedule-foundation.md`
- `docs/semester-schedule-import.md`
- `docs/semester-schedule-read-api.md`
- `docs/semester-schedule-stage3-verification.md`
