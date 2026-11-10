# Semester schedule foundation verification

Date: 2026-11-10. Baseline: `main` at
`5b7d138480c77a87219939930c2d3dcccf40d4c4`, verified against GitHub and fast-forwarded
from the initial local `f1fc757…` (upstream changed README only).

Environment: PHP 8.5.11, MySQL 8.4.10. Tests used the deliberately isolated,
disposable `student_planner_isolated_semester_foundation` database and the existing
`Tests\Support\TestDatabase` guard. No application data migration or `migrate:fresh`
was run against retained development/production data. Existing test traits rebuild
only the explicitly selected disposable test schema.

## Commands and measured results

The initial compatibility check, before adding the migration:

```bash
vendor/bin/phpunit tests/Feature/ScheduleViewTest.php tests/Unit/ScheduleWorkbookParserTest.php --no-progress
```

Result: **47 passed, 416 assertions**.

Subsequent MySQL commands used these process environment selectors:

```bash
export DB_DATABASE=student_planner_isolated_semester_foundation
export PLANNER_TEST_DATABASE=student_planner_isolated_semester_foundation
```

Initial new-domain tests:

```bash
vendor/bin/phpunit tests/Unit/EffectiveScheduleCalculatorTest.php tests/Feature/EffectiveScheduleServiceTest.php tests/Feature/SemesterScheduleMigrationTest.php --no-progress
```

Result: **84 passed, 392 assertions**, before the final import-provenance inverse
relationship regression was added.

The first full offline suite ran **6323 tests**, with **6321 passing** and two
failures: the historical AI chat/action migration round-trip tests assumed fixed
rollback counts of four and three. The additional migration shifted those counts;
no assertion about AI behavior or persisted content was changed. Both tests now
calculate the rollback count from their exact target migration names, preserving
all original before/after data and schema assertions.

The focused compatibility rerun:

```bash
vendor/bin/phpunit tests/Feature/AiChatPersistenceTest.php tests/Feature/AiTaskActionPersistenceTest.php tests/Feature/PlanningMigrationTest.php tests/Unit/EffectiveScheduleCalculatorTest.php tests/Feature/EffectiveScheduleServiceTest.php tests/Feature/SemesterScheduleMigrationTest.php --no-progress
```

Result at that point: **89 passed, 530 assertions**. The final relation regression
and final full-suite results are recorded below.

## Coverage and changed files

- New migration: `database/migrations/2026_11_10_000001_create_semester_schedule_foundation.php`.
  Four tables, ownership/provenance FKs, candidate keys, UNIQUEs and ten enforced
  CHECKs. Tests exercise actual MySQL rejection, legacy row preservation and a
  non-destructive populated-domain downgrade refusal.
- New models: `SemesterSchedule`, `WeeklyLessonRule`, `ScheduleDateException`,
  `ScheduleDateExceptionLesson`. Inverse relationships added to User, AcademicPeriod,
  Subject, Teacher and ScheduleImportBatch. No legacy fields or contracts changed.
- New services: `EffectiveScheduleCalculator` (pure calendar/conflict rules) and
  `EffectiveScheduleService` (owned persisted read adapter).
- New tests: `tests/Unit/EffectiveScheduleCalculatorTest.php`,
  `tests/Feature/EffectiveScheduleServiceTest.php`, `tests/Feature/SemesterScheduleMigrationTest.php`.
  Existing AI persistence tests only have their migration rollback boundaries adapted.
- Domain decisions: [foundation contract](semester-schedule-foundation.md) and
  [ADR-006](decisions/ADR-006-semester-schedule-foundation.md); linked from the
  existing architecture, domain, business, database/review and schedule documents.

Coverage includes 16 real-fixture rules / 15 occurrences per week, Tuesday parity,
configurable and backwards anchors, year boundary, inclusive semester boundaries,
Kyiv offsets, DST gaps/folds and real elapsed transition durations, skipped dates,
whole-day/empty snapshots, isolated Saturdays, base restoration, duplicate pair
and half-open overlap rejection across both parities, SQL ownership on inserts
and updates, cross-schedule provenance, trusted persisted timezone, stable identity,
SELECT-only reads and unchanged persisted rows.

## Rollout boundary

No legacy data migration is needed for existing behavior. Adoption of legacy
semesters later requires an explicitly tested forward data migration or confirmed
re-import with a legacy one-off Lesson policy. The new migration only adds the
foundation. Its deployment has not been performed on the application database.
REST/import/frontend/planning/AI integration remains a separate task.

The actual attachment named `Приклад розкладу (2).xlsx` was unavailable; verification
used the repository fixture `Приклад розкладу.xlsx`, whose SHA-256 and limitations
are documented in the foundation contract.

## Final measured results

```bash
vendor/bin/phpunit --no-progress --log-junit /tmp/student-planner-semester-phpunit.xml
```

**PASS: 6323 tests, 61798 assertions, 0 failures/errors/skips**, 349830 ms.
This full run discovered its tests before the final import-provenance inverse
relationship regression was added. The existing `ScheduleImportBatch` inverse now
explicitly uses `source_import_batch_id`; the final focused run below verifies it
and reruns all new-domain and affected migration tests against the final code.

```bash
vendor/bin/phpunit tests/Feature/AiChatPersistenceTest.php tests/Feature/AiTaskActionPersistenceTest.php tests/Feature/PlanningMigrationTest.php tests/Unit/EffectiveScheduleCalculatorTest.php tests/Feature/EffectiveScheduleServiceTest.php tests/Feature/SemesterScheduleMigrationTest.php --no-progress --log-junit /tmp/student-planner-semester-targeted.xml
vendor/bin/pint --test
git diff --check
git diff --cached --check
```

**PASS: 90 tests, 537 assertions**, 15083 ms. Pint and both diff checks passed.
The focused tests include the final added regression; no additional test is left
unexecuted. Production AI, REST, import and planning code/contracts are unchanged.
