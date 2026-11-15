# Backend testing

The compact suite deliberately replaces the former 7,065-case offline suite. It is a coverage-budget decision, not evidence that the retired suite passed. Historical reports in `docs/testing/` and Stage directories remain historical. Current results are in [the compact-suite report](testing/compact-suite-report.md) and [machine-readable results](testing/compact-suite-results.json).

## Scope and counting

`phpunit.xml` still discovers every class in `tests/Unit` and `tests/Feature`; no old directory or failing group is excluded. Old PHP test classes are removed, rather than retained behind discovery exclusions. Each discovered DataProvider row counts separately. The target is 660, with a hard maximum of 700.

| Primary category | Cases |
| --- | ---: |
| Unit | 220 |
| Feature/REST (`rest`) | 170 |
| Integration | 85 |
| Migration | 45 |
| Concurrency | 30 |
| AI with substituted providers | 55 |
| Cross-module (`workflow`) | 35 |
| Smoke | 20 |
| Total | 660 |

Each case has exactly one primary category. `mysql` is only an execution dependency marker. `phpunit-smoke.xml` selects the same 20 smoke cases already included in the full suite, never another 20 unique cases.

The [manifest](testing/compact-suite-manifest.json) assigns every replacement class and records each reused original case. The [retirement inventory](testing/compact-suite-retirement.json) records original classes, method expansion counts and retained counts. Reused assertions and helper fixtures are intentional: compact replacement does not require rewriting a sound domain oracle. Provider matrices are explicit local arrays containing selected rows; omitted rows and unused helper methods are removed from executable test classes.

Coverage follows the current routes and Services: task/child lifecycle and remaining effort; effective semester and legacy schedules; XLSX parsing and import; available time, constraints, allocation, confirmation and recovery; reminders and inbox; statistical reports; and AI proposals, confirmation receipts and history. Unit cases include exact seconds, boundaries, calendar/DST behavior, integer arithmetic, representative invalid domain inputs and independent allocation oracles. Representative scalar families replace exhaustive combinations. This is not exhaustive combinatorial coverage.

There is no dedicated security/attack suite. Functional login and ordinary account workflows remain. Cross-user probes embedded in retained atomicity flows were removed. Policies, middleware, CSRF, authorization, database guards and production behavior are unchanged.

## Execution and evidence

```sh
vendor/bin/phpunit --list-tests
vendor/bin/phpunit --list-tests-xml /tmp/planner-inventory.xml
vendor/bin/phpunit --testsuite Unit --log-junit /tmp/planner-unit.xml
scripts/testing/phpunit-disposable-mysql.sh --log-junit /tmp/planner-full.xml
scripts/testing/phpunit-disposable-mysql.sh --configuration phpunit-smoke.xml --log-junit /tmp/planner-smoke.xml
php scripts/testing/summarize-compact-suite.php /tmp/planner-inventory.xml /tmp/planner-unit.xml /tmp/planner-full.xml /tmp/planner-smoke.xml
vendor/bin/pint --test
git diff --check
```

Run the database commands sequentially. The existing runner initializes its own temporary MySQL 8.x datadir, checks its identity and emptiness, creates a randomly named schema, and removes only that owned server on exit. Do not substitute SQLite or use a retained development/production database. `tests/TestCase.php`, `tests/Support/TestDatabase.php` and the per-server/schema lease remain unchanged. The bootstrap prevents stray provider HTTP requests. Fake transport/provider responses exercise the real orchestrator, Tools and Services; no paid provider call is needed.

Concurrency cases use independent processes/connections and real InnoDB lock instrumentation with controlled readiness/release, plus committed-state and rollback checks. Migration cases cover current physical constraints and populated forward upgrades. `UsesForwardOnlyMigrations` handles teardown without rolling back forward-only production history. Applied migrations are not rewritten.

CI's existing disposable hosted migration verification and formatting/deployment mechanisms remain unchanged. It has no old individual PHPUnit file selectors to redirect. Local complete/unit/smoke evidence must be checked independently; the existing hosted workflow alone does not establish a full compact-suite pass.

## Shared full-stack and separate deployment checks

Keep `scripts/testing/fullstack-disposable-mysql.sh`, `tests/Support/system-router.php`, `system-fixtures.php`, `SystemTestProvider.php`, the real XLSX fixture and their dependencies. They provision a separate temporary MySQL server, Laravel HTTP and the independently checked-out Vue app. This task does not invoke that runner or modify the frontend. It continues to use actual orchestrator/Tools/Services with the model provider substituted in the private test router.

`tests/Deployment/test_deployment.py` remains a separate Python deployment check, outside PHPUnit and its 660-case budget; it was not moved or hidden by this replacement. The obsolete opt-in paid-provider PHPUnit class was removed. Database and deployment safety mechanisms remain, even though special negative tests for them are outside this requested functional suite.

External paid-model quality, real mail/push delivery, browser rendering, production deployment, every retired scalar/provider-wire combination and dedicated security testing are outside this suite. See the compact report for omitted functional areas and all current verification failures.
