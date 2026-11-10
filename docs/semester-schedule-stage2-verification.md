# Stage 2 verification: Semester Schedule Read API

Baseline: `main` at `e25754c3b22f8b50c152b91fa3de0595efdef058`, verified with
`git ls-remote origin refs/heads/main`; the working tree was initially clean.
Work stayed on `main`, without a new branch, worktree or PR.

## Implementation

Four read-only routes under the existing Sanctum middleware:

- `GET /api/semester-schedules`
- `GET /api/semester-schedules/{semesterSchedule}`
- `GET /api/semester-schedules/{semesterSchedule}/date/{date}`
- `GET /api/semester-schedules/{semesterSchedule}/week/{date}`

See [the API contract and complete JSON examples](semester-schedule-read-api.md)
for list/detail/date/week responses, stable occurrence IDs and error semantics.

`EffectiveScheduleService` now supplies owned list/detail and one batch-loaded,
transactionally consistent date/week projection. The existing internal `date()`
list contract remains available. The calculator owns calendar arithmetic and all
original parity, snapshot, interval and DST rules. Resources expose only explicit
public fields. Target ownership is checked before date validation and again via
owned queries inside the reader. Route-specific error handling hides internal
messages, SQL and traces even with debug enabled.

No new migration, application-data reset or legacy conversion was performed.
Legacy Lesson endpoints/Resource/CRUD, import commit, planning/free time,
reminders/statistics, AI tools/schemas and frontend were unchanged.

## Changed files

Application code:

- `app/Http/Controllers/Api/SemesterScheduleController.php` — new thin read controller.
- `app/Http/Requests/SemesterScheduleDateRequest.php` — owned target authorization and strict date/week validation, reusing the existing date request.
- `app/Http/Resources/SemesterScheduleResource.php` — owned academic context and anchor.
- `app/Http/Resources/SemesterScheduleOccurrenceResource.php` — virtual occurrence whitelist, without numeric Lesson ID/type.
- `app/Policies/SemesterSchedulePolicy.php` — not-found denial for foreign targets.
- `app/Services/Schedule/EffectiveScheduleCalculator.php` — pure ISO week label helper.
- `app/Services/Schedule/EffectiveScheduleService.php` — batched reads, eager catalogs, frozen snapshot projection and trusted timezone metadata.
- `bootstrap/app.php` — error response boundary limited to new route prefix.
- `routes/api.php` — four separate authenticated GET routes.

Tests:

- `tests/Feature/SemesterScheduleReadApiTest.php` — 39 API tests including provider cases.
- `tests/Feature/SemesterScheduleReadConsistencyTest.php` — two-connection read snapshot test.
- `tests/Unit/EffectiveScheduleCalculatorTest.php` — ten additional pure week-boundary/validation cases.

Documentation:

- `docs/semester-schedule-read-api.md`
- `docs/semester-schedule-stage2-verification.md`
- `docs/semester-schedule-foundation.md`
- `docs/schedule.md`
- `docs/architecture.md`
- `docs/domain-model.md`
- `docs/business-rules.md`

## Actual commands and targeted results

PHP 8.5.11, MySQL 8.4.10. Tests used only the explicitly selected disposable
`student_planner_isolated_semester_foundation` database under the existing
`Tests\Support\TestDatabase` guard. The migration test trait rebuilds that isolated
test schema, never retained application data.

Commands below used these environment selectors (no credentials were changed):

```bash
export DB_DATABASE=student_planner_isolated_semester_foundation
export PLANNER_TEST_DATABASE=student_planner_isolated_semester_foundation
```

The initial foundation compatibility run passed 84 tests / 379 assertions. The
first combined API/foundation/legacy-schedule run passed 162 / 1076. After adding
the real read-consistency test and legacy CRUD/security coverage, 231 / 1953 passed.
The final focused run, including corrupt foreign-catalog scenarios:

```bash
vendor/bin/phpunit tests/Feature/SemesterScheduleReadConsistencyTest.php tests/Feature/SemesterScheduleReadApiTest.php tests/Feature/EffectiveScheduleServiceTest.php tests/Unit/EffectiveScheduleCalculatorTest.php tests/Feature/ScheduleViewTest.php tests/Feature/LessonApiTest.php tests/Feature/LessonSecurityTest.php --no-progress --log-junit /tmp/semester-stage2-targeted.xml
```

**PASS: 233 tests, 1971 assertions**, 5718 ms. No test assertions were weakened;
no existing production consumer was adapted to the new source.

```bash
vendor/bin/pint --test
git diff --check
php artisan route:list --path=api/semester-schedules --except-vendor
```

Pint and diff validation passed. Laravel listed exactly four GET|HEAD routes.

## Evidence and limits

The API tests cover owned/empty listings, exact detail/occurrence shapes, 401/404
and authorization before 422, strict route dates and query spoofing, Monday-first
weeks across boundaries, alternating Tuesday pair 4, stable virtual identity,
complete/frozen/empty date snapshots and restoration, independent working Saturday,
partial semesters and out-of-period emptiness, persisted timezone changes, Kyiv
DST offsets/gap/fold handling, controlled 409/500 with debug enabled, SELECT-only
GETs and unchanged stored rows, corruption isolation, separate legacy reads and
bounded week query counts without lazy loading or duplicate identities.

The consistency test uses a second independent MySQL PDO connection to commit
profile timezone, subject, rule and snapshot changes after the read transaction's
first SELECT. Under the configured MySQL REPEATABLE READ isolation, the in-flight
week remains wholly old, and the next request returns the wholly new state. The
external writer belongs to the test harness, not the GET implementation.

The API only reads already saved Stage 1 records. There is no write/import/copy
workflow, no merging of legacy Lessons, and no new schema change. List output is
unpaginated, following existing academic-list conventions. A bad required period
or institution makes the list/detail fail with a safe 409; invalid effective source
data fails the entire requested date/week rather than returning partial entries.
DST-ambiguous/nonexistent endpoints retain the foundation's rejection policy.

## Final full regression

```bash
vendor/bin/phpunit --no-progress --log-junit /tmp/semester-stage2-full.xml
vendor/bin/pint --test
git diff --cached --check
git diff --check
```

**PASS: 6374 tests, 62182 assertions, 0 failures/errors/skips**, 336966 ms.
All final application and test code was present before full-suite discovery.
Pint and both staged/working diff checks passed. Six JSON examples parsed
successfully, new-document links resolved, and the report includes all 19 changed
files. The implementation is committed locally to `main`; no PR or extra branch
was created and no deployment/push or application-data migration was performed.
