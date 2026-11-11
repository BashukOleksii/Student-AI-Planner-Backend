# Backend testing

Current execution results, baseline failures, replacement coverage and limitations are recorded in [the rebuild report](testing/backend-test-rebuild-report.md). Historical Stage/CI totals are not current evidence.

## Organization

The default `phpunit.xml` discovers every test under `tests/Unit` and `tests/Feature` exactly once. No critical directory or failed group is excluded. Keep pure deterministic tests in Unit and Laravel/REST/MySQL/fake-provider workflows in Feature. Existing high-value allocation, authorization, provider-wire, persistence and real-process concurrency regressions are retained.

Feature classes have non-duplicating PHPUnit group attributes:

| Selector | Purpose |
|---|---|
| `mysql` | All Laravel Feature tests using the guarded MySQL application bootstrap |
| `migration` | Current constraints/persistence and explicit historical predecessor upgrades |
| `concurrency` | Independent MySQL clients, including real InnoDB lock waits |
| `ai` | Fake-provider transport, orchestration, proposal/confirmation and Tool execution |
| `security` | Authentication, authorization, isolation and database-safety probes |
| `recovery` | Session recovery, adaptive replanning, durable automatic reviews and scans |
| `workflow` | Authenticated cross-module workflows |
| `smoke` | Essential methods, also retained in the default complete suite |

`phpunit-smoke.xml` discovers the same Feature directory and includes only methods/classes tagged `smoke`. It includes the mixed calendar read, nine semester actions, automatic recovery ownership/replay and complete semester-edit/recovery workflow. A smoke pass does not replace the complete suite.

`tests/Concerns` contains reusable browser, fake-provider, MySQL assertion/worker and recovery fixtures. `tests/Support/AiCapabilities.php` derives current Tool manifests from registries; tests compare names/schema semantics and uniqueness rather than historical counts. Native provider-specific null/omission and continuation checks remain separate. Fixed clocks and explicit historical migration boundaries prevent calendar and schema drift.

## Safe local execution

Prefer a fresh process-owned server over an existing database:

```sh
vendor/bin/phpunit --testsuite Unit
scripts/testing/phpunit-disposable-mysql.sh --group migration --group concurrency
scripts/testing/phpunit-disposable-mysql.sh --group ai
scripts/testing/phpunit-disposable-mysql.sh --group security
scripts/testing/phpunit-disposable-mysql.sh --group workflow
scripts/testing/phpunit-disposable-mysql.sh --configuration phpunit-smoke.xml
scripts/testing/phpunit-disposable-mysql.sh
vendor/bin/pint --test
```

The runner requires local MySQL 8.x binaries, PHP/extensions, OpenSSL and Composer dependencies. It initializes an empty private temporary datadir, verifies the connected server's datadir and absence of application schemas, creates a new randomly named empty database, exports testing/MySQL selectors and fake delivery configuration, and shuts down/removes only that temporary server on exit. Port defaults to 13380; set `PLANNER_TEST_PORT` to an unused local port if necessary. It neither discovers nor connects to a production/development instance. A missing prerequisite or identity mismatch stops execution.

For an independently provisioned environment, establish equivalent evidence of disposal before running any Feature test. `APP_ENV`, database naming and `.env` values alone are insufficient. The existing testing/mysql/exact selected-database guards remain in `tests/TestCase.php`. An additional server-UUID/database keyed local file lease refuses a second PHPUnit process against that same database before cleanup. Intentional worker clients inside a concurrency test do not run suite cleanup. Separate machines/runners must still use separate disposable MySQL instances or schemas; a local file lease is not a distributed lock.

Run database suites sequentially. PHPUnit 12 uses repeated selectors (`--group migration --group concurrency`), not one comma-joined group name. Preserve mail/notifications fakes; the offline bootstrap prevents stray HTTP requests. Test workers carry the trusted connection configuration explicitly.

## External and historical boundaries

`tests/Live` remains explicitly opt-in for paid provider observations and is outside the offline suite. Do not run it during routine verification. The transport/orchestration/action contracts are tested with deterministic providers instead. `tests/Deployment` contains separate Python safety checks; these are not business PHPUnit tests and do not deploy anything. No real browser, external email/push or paid LLM capability is implied by a local pass.

Current forward migrations must upgrade cleanly and preserve history. Historical reversible migrations are tested against explicitly rebuilt predecessors on the disposable schema. Never claim the entire current application can downgrade through forward-only migrations, remove migration guards, or rewrite an applied migration to satisfy a historical test.

## Independent Vue + Laravel browser verification

The independent frontend's real-browser suite can be run against a separately
provisioned MySQL process (not the regression suite's resetting database):

```sh
scripts/testing/fullstack-disposable-mysql.sh /absolute/path/to/Student-AI-Planner-Frontend
vendor/bin/phpunit tests/Unit/SystemHarnessEntrypointTest.php
```

The frontend checkout must contain `playwright.fullstack.config.ts`. The runner
proves a new server/datadir, zero application schemas and an empty new database,
then applies migrations, starts Laravel HTTP on localhost:18000 and Vite on
localhost:15173, and runs Chromium with actual cookie/CSRF requests. It refuses
occupied HTTP ports and never connects to the retained development database.
An ephemeral APP_KEY, database sessions/cache/queue, isolated storage/log mail and
synthetic browser-registered accounts confine all writes and delivery. The test
HTTP entry rechecks UUID/datadir/database on every request and prohibits stray
provider HTTP requests. Production bootstrap/configuration remains unchanged.

`tests/Support/SystemTestProvider.php` replaces only model selection at the
`LlmProvider` boundary in the private test router. Registered Tools, orchestrator,
confirmation Services and persisted receipts execute normally. Guarded CLI
fixtures establish elapsed history and expiry cases only on that exact server.
The ordinary app does not gain a fake provider or test endpoints. This suite is
not evidence of paid natural-language model selection. The real five-minute
scheduler observation may add up to five minutes to runtime.

Results, UUID/process evidence, browser checks and scheduler logs are stored in
the frontend's ignored `fullstack-results/`, outside ordinary Playwright's cleanup
root. Private mail/token-bearing storage is deleted with the temporary server;
never commit it. Detailed coverage and normal retained-demo startup commands are
in the frontend's `docs/system-integration-report.md` and
`docs/local-full-stack-setup.md`.
