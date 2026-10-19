# Backend integration and readiness — Stage H4

H4 stabilizes the existing Laravel/MySQL backend; it adds no Tool, domain action,
planning algorithm, provider, schema, frontend or deployment system. The independent
Vue application is not implemented. The client contract is
[frontend-api-contract.md](frontend-api-contract.md).

## Initial audit and scope

Starting HEAD: `1335fcd19ed9675d75452ee8752b3920f4c474c6`, initially clean.
The repository already had nine reads, fourteen confirmed proposals, native Responses
continuation, H2 leases/idempotency, H3 durable receipts and Stage C/D/E2 deterministic
planning. ADR-002/003/004 define the existing atomic transaction boundaries.

Reviewed AGENTS, README, environment/dependency/DB/CORS/Sanctum/bootstrap/provider
configuration, all API routes, AI Requests/Resources/Policies/models, registries,
executors/proposals/confirmation and relevant domain writers/concurrency helpers.
Read architecture/business rules (BR-AI-001–009), auth and H1–H3c contracts and ADRs.
There was no .github/workflows directory. README still described implemented
Tasks/planning/imports/Reminders/statistics/AI as future work.

Actual gaps and justified changes:

| Finding | Evidence / minimal resolution |
| --- | --- |
| Separate feature tests did not prove a full cookie-only student workflow across AI and ordinary domain HTTP endpoints | New database-session/CSRF browser harness, 14-action HTTP matrix plus relative Reminder/partial plan variants, nine-read REST comparisons and explicit domain readback |
| Numeric environment casts silently accepted values such as 30oops, fractions or exponents | Regression reproduced coercion; one strict config parser preserves invalid values as null for safe 503 |
| Some invalid unused budgets could reach a provider on a greeting | Validate all existing budget bounds before claiming a new turn; retain every configured default/maximum |
| Completed response could contain unchecked unfinished reasoning item | Focused regression first; reject non-completed optional reasoning status, preserving accepted stateless continuation |
| Debug-enabled AI CSRF/throttle errors exposed framework internals | Keep safe 419/429 envelopes and existing 429 headers, independent of APP_DEBUG |
| Unexpected AI exceptions reached raw Laravel error reporting (potential SQL/prompt/protected data) | Regression captured default error report; suppress raw reporting only on AI paths, log fixed failure category instead |
| Credential echo guard inspected final text only | Three regressions reproduced a credential in plain/escaped arguments or continuation reaching a successful turn; extend the existing guard across sanitized parsed strings before persistence |
| No bounded operational lifecycle records | One ai.request event with server route/status/duration; safe ai.failure category; no request/response bodies or identifiers. Logging failures cannot break committed responses |
| Credentialed browser JS could not read Retry-After | Regression reproduced missing exposed header; expose existing retry/rate-limit headers, without changing limits |
| Array-cache tests did not prove cross-process throttling | New separate-PHP/database-cache test, same-user saturation and independent-user progress; no duplicate domain races |
| No automated CI and stale project status | Small verification-only workflow, current README, frontend contract and offline/live instructions |
| Root declares PHP ^8.3, but locked Symfony 8.1 packages require >=8.4.1 | Document actual locked-platform minimum; CI chooses current PHP 8.4. Dependency versions and Composer requirements remain unchanged |

Before hardening, the new regression checkpoint had 17 failures and one reporting
error out of 24 cases (six already passed). The CORS Retry-After test separately
failed before its config change. The later credential-echo regression failed in all
three cases before its focused fix. These were reproduced gaps, not observed paid
provider failures. Early new-test harness corrections (empty JSON objects, dynamic
as_of_utc values, cookie refresh and JSON object ordering) did not change domain rules.

Deliberately untouched: all 23 definitions/dispatch names, Tool argument/domain
rules, schema/migrations, public domain Resources/routes, planning algorithms,
transaction ownership, lock ordering, stale fingerprints, protected tokens, receipt
formats, delivery/inbox behavior and public authentication mechanism. The only H2
Service change validates budgets before claim. No automatic provider retry was added.

## Runtime and local setup

Use PHP **8.4.1+ compatible with composer.lock**, Composer 2, MySQL 8.4/InnoDB with
CHECK enforcement and REPEATABLE READ. The local verification runtime is PHP
**8.5.11**, Laravel **13.33.0**, Composer **2.10.3**, MySQL **8.4.10**;
performance_schema is enabled. Root Laravel/PHP constraints are ^13.17/^8.3;
Composer installs the lock, not just those lower bounds. Do not substitute SQLite
or MariaDB for verification.

Required PHP capabilities are checked by composer check-platform-reqs; include
pdo_mysql, mbstring, dom/xml/simplexml/xmlreader/xmlwriter, fileinfo, gd, zip,
openssl and normal Laravel/PhpSpreadsheet dependencies. CLI process spawning is
required for the concurrency tests. CI explicitly provisions PHP 8.4 with these
extensions and no coverage overhead.

Create separate databases, with utf8mb4 as configured in config/database.php:

```sql
CREATE DATABASE student_planner CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE student_planner_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Use local credentials appropriate to your machine. Prefer a dedicated test account
with DDL/DML privileges only on student_planner_testing and SELECT on
performance_schema.* for data_locks/data_lock_waits/threads. The tests need real
row-lock visibility, not mocked tables. CI uses a privileged account only inside
its disposable MySQL service. No production credential is needed or supplied.

```sh
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
# Configure local DB_USERNAME/DB_PASSWORD and the intended application database.
php artisan migrate
php artisan serve --host=localhost --port=8000
```

Do not regenerate APP_KEY on a populated deployment: sessions, existing encrypted
planning tokens and protected action metadata depend on it. Store secrets in ignored
local files or a secret manager. `.env.*` is ignored except the placeholder
.env.example; no real credentials belong in Git. Composer's scaffold setup script
is not the documented backend smoke path and is not run by CI; no frontend is added.

For tests, use an ignored .env.testing or exported process environment, with
DB_CONNECTION=mysql, DB_DATABASE=student_planner_testing, the correct host/port and
test-only credentials. phpunit.xml selects that database and array session/cache
by default. Every Laravel TestCase checks testing environment, driver, configured
name and SELECT DATABASE() **before** destructive migrations. Do not run suites
concurrently against the same test database. DatabaseMigrations/Truncation tests
intentionally reset it; never point them at student_planner or production.

APP_URL/stateful domains/CORS/cookies follow the
[frontend authentication contract](frontend-api-contract.md#authentication-and-browser-configuration).
The offline browser harness enables real CSRF, carries encrypted cookies, reloads
session/auth state per request and uses database sessions. It does not use actingAs
or bearer tokens in the main end-to-end workflows. Tests also cover registration,
login after expiry, logout, invalid/non-stateful origins, Referer, CSRF errors and
credentialed preflight.

## OpenAI compatibility assessment

Reviewed official OpenAI documentation on 2026-10-19, using the OpenAI Docs skill.
This is a documentation/code/fixture assessment, not proof of account access:

- [GPT-5.4 Mini](https://developers.openai.com/api/docs/models/gpt-5.4-mini)
  documents Responses and function calling. Keep the configured gpt-5.4-mini alias;
  no model migration is necessary. Account entitlement, billing, rate limits and
  actual inference remain deployment-specific and unverified here.
- [Function calling](https://developers.openai.com/api/docs/guides/function-calling)
  supports native function definitions, correlated outputs and multiple calls.
  Strict schemas require all declared keys; null adapts optional H1/read fields.
  Existing non-strict H3 definitions preserve omission versus clearing-null and
  strict server validation. Explicit strict=false remains appropriate for them.
- [Reasoning](https://developers.openai.com/api/docs/guides/reasoning) documents
  stateless encrypted continuation. Current documentation says encrypted content
  is included by default with store=false and the existing include value remains
  accepted for compatibility. Retain it; do not introduce remote retention.
- [Conversation state](https://developers.openai.com/api/docs/guides/conversation-state)
  supports client-managed history. Local replay retains sanitized call/reasoning/
  message items and matching function_call_output, including safe phase metadata.
- [Error codes](https://developers.openai.com/api/docs/guides/error-codes) informs
  safe upstream classification; the application keeps its existing public mapping.

The fixed POST endpoint is https://api.openai.com/v1/responses, with store=false,
include reasoning.encrypted_content, native tools, bounded max_output_tokens,
parallel_tool_calls=true and truncation=disabled. No previous_response_id or remote
conversation is required. Redirects and automatic retries remain disabled.
Unknown model Tool names cannot dispatch arbitrary URLs/classes/methods.

Existing and new fixtures cover text/refusal, multi-call/sequential continuation,
strict optional null versus H3 omitted/nullable arguments, malformed/object JSON,
missing/duplicate IDs, unsupported items, unfinished reasoning, failed/incomplete
responses, HTTP 400/401/403/404/429/5xx, timeouts, body/item limits and safe recovery.
The large generated API reference could not be fetched in full by the documentation
tool; the fetched official guides above and model page support the assessment.
The fixture-backed adapter is intentionally a restricted text/function subset;
new built-in tools/modalities fail closed rather than silently being executed.

## Configuration, budgets and shared rate limits

.env.example defaults are unchanged:

```dotenv
AI_PROVIDER=openai
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.4-mini
AI_REQUEST_TIMEOUT=30
AI_MAX_TOOL_ROUNDS=5
AI_MAX_TOOL_CALLS=8
AI_MAX_HISTORY_MESSAGES=20
AI_MAX_CONTEXT_BYTES=131072
AI_MAX_TOOL_RESULT_BYTES=65536
AI_MAX_OUTPUT_TOKENS=2048
AI_MAX_TURN_SECONDS=180
AI_TURN_LEASE_SECONDS=240
AI_REQUESTS_PER_MINUTE=10
```

All numeric values must be canonical positive decimal integers, without padding,
fractions, exponent notation, leading zeros or suffixes. Invalid settings become
safe ai_unavailable/503; every budget is checked before turn claim/provider work,
including unused budgets on a greeting. Existing maxima remain 120 seconds timeout,
20 rounds, 32 calls, 100 history messages, 1048576 context bytes, 262144 result bytes,
8192 output tokens, 600 turn seconds, 900 lease seconds and 60 messages/minute.
Lease must exceed turn duration. Empty/missing key/model, invalid model syntax or
unsupported provider also fail safely. Do not silently change model or limits to
repair an upstream failure. Apply php artisan config:clear locally; deploy/cache
configuration using secrets without printing config:show ai.

Use CACHE_STORE=database (example default) or a correctly shared Redis store across
all web processes/nodes, with consistent prefix/storage. Array/file/local stores
do not prove multi-node enforcement. phpunit.xml's array store is only for isolated
fixtures. The new process test explicitly boots separate workers with database cache,
shows same-user 429 before any provider call and independent-user success.
Laravel throttling is not a strict atomic billing-quota reservation at simultaneous
cold boundaries. Set provider account/project limits operationally as well.

Message requests, including failed validation and replay requests, pass the throttle.
Action reads/decisions do not use that message throttle. Retry-After and the existing
rate-limit headers are exposed through credentialed CORS for Vue backoff.
Conversation leases/idempotency/domain correctness depend on MySQL, not cache affinity.

Output safeguards remain: 1 MiB provider body before JSON parsing (after download),
64 output items, 16 KiB argument objects, 16000 assistant characters, configured
complete-result/context budgets and one attempted mutation per turn. Oversized full
planning reviews fail safely, never truncate material effects. No new byte defaults
or hidden continuation compression is introduced. Streaming download caps and token
estimation remain limitations; PHP memory and upstream infrastructure still need
appropriate operational bounds.

## Observability and errors

Only AI paths receive the new logging policy. `ai.request` contains operation from
a fixed route-name allowlist, HTTP status and integer duration_ms. `ai.failure`
contains only a fixed code/category: conflict, expired, invalid_request, provider_error,
ai_unavailable or persistence_error. Expected planning attempt outcomes remain
server-recorded version-2 conversation events. The adapter rejects verbatim transport-key echoes in sanitized text, arguments,
identifiers and continuation, including decoded Unicode escapes, before a successful
turn can persist them or expose a proposal. No raw exception or SQL context is
forwarded to default Laravel reporting for an AI path. One small event per request
replaces speculative detailed telemetry. Use normal Laravel log routing/retention;
LOG_LEVEL=warning can suppress informational timing events.

Never log prompts, history, transport headers/keys, cookies/CSRF, descriptions,
provider responses, instructions, user identifiers, encrypted/decrypted tokens or
protected payloads. Tests reproduce raw reporting before the fix, assert safe
metadata afterward and prove logging outages do not break committed responses.
No new monitoring vendor, event bus or tracing layer is added. This policy does
not audit unrelated application logs, reverse proxies or an operator's custom
HTTP debug middleware; do not enable sensitive payload logging there.

Safe public contracts and recovery are listed in
[frontend errors](frontend-api-contract.md#error-handling-and-recovery): 401/404,
409 conflict/stale/busy, 410 expiry, 419 CSRF, 422 validation/consent/budgets,
429 throttling, 502 upstream failure, 503 unavailable config and safe 500 persistence.
H4 removes debug trace fields from AI 419/429 responses and preserves throttle
headers/messages. Existing error codes, successful Resource shapes and routes stay
compatible. Failed planning attempts remain pending/null receipt plus a failed
history event, never fabricated execution. No domain Service was refactored.

## Reproducible offline smoke

No OpenAI key, account access or real inference is required. Use only the guarded
disposable test database, with migrations/privileges above:

```sh
vendor/bin/phpunit tests/Feature/BackendIntegrationSmokeTest.php
vendor/bin/phpunit tests/Feature/FrontendApiContractTest.php tests/Feature/AiReadinessHardeningTest.php tests/Feature/AiSharedRateLimitTest.php tests/Unit/AiEnvironmentConfigurationTest.php
```

The smoke suite creates disposable students through registration, initializes
CSRF/session cookies, retrieves timezone, creates Subject/Lesson/Task/Subtask and
availability/preferences through REST, discovers all 23 definitions through the
real adapter, compares all nine reads, prepares/reads/confirms all fourteen actions,
checks ordinary REST readback, Reminder/history preservation, required consent,
receipt/message replay and logout protection. HTTP fixtures assert zero open
transactions on every provider call and prevent stray network requests. Test
cleanup is confined to student_planner_testing; no persistent demo user remains.
The contract suite verifies rejection/expiry, failed attempt versus pending state,
foreign cookie ownership, expired session approval, timeout recovery and adversarial
Tool output/owner/URL dispatch. Normal PHPUnit output is a clear pass/fail report.

## Optional paid live smoke — explicit operator opt-in

**Status: NOT RUN — credentials/explicit authorization unavailable.** No paid
request was attempted in H4. A passing guard test or scripted adapter is not a
passing live-provider test.

The live file is outside both default PHPUnit directories; normal PHPUnit, CI,
Composer scripts and setup do not invoke real inference. Before app bootstrap it
requires both process-environment flags to equal 1. It then uses the same strict
testing/MySQL/student_planner_testing guards, requires existing migrations and a
securely configured key/model, and registers its own isolated student. Do not run
it alongside another suite on that database. Operator invocation:

```sh
# Supply OPENAI_API_KEY securely through the operator environment, not Git or CLI output.
AI_LIVE_SMOKE=1 AI_LIVE_ALLOW_PAID=1 vendor/bin/phpunit tests/Live/OpenAiLiveSmokeTest.php
```

This is a billable, deliberate operation. Six small scenarios cover greeting,
owned reads, sequential discovery, clarification, a pending proposal and missing
targets. The suite caps all actual transport requests at twelve, each output at
384 tokens and timeout at fifteen seconds, with three rounds/three calls per turn.
A pre-transport middleware refuses request thirteen. No automatic retry, background
job or action confirmation is used. Model differences can make the smoke fail;
do not loosen validation or turn flags on by default to hide it.

Output is structured scenario/status/tool-name/count/pending/no-business-write
observations, not exact answer comparisons or raw messages/keys/cookies/tokens.
Finally it deletes only the newly created student's rows and associated database sessions,
never truncates the database. Guard behavior is tested offline with both flags
false and an intentionally unusable database name, proving no bootstrap/connection
occurs without opt-in. Live behavior/account access/billing remain unverified until
an authorized operator runs it; do not report them as passed.

## Minimal CI

`.github/workflows/backend-verification.yml` runs on main pushes, pull requests
and workflow_dispatch with read-only contents permission. It uses ubuntu-24.04,
PHP 8.4, Composer lock installation and a disposable mysql:8.4 service.
No production credentials/OpenAI key are used; both live flags are zero.

CI validates dependency consistency/platform requirements, application config cache/
clear and API routes, requires REPEATABLE READ/performance_schema and queries actual
lock tables, migrates/rolls back/remigrates an empty dedicated database, checks Pint
and whitespace, and runs the **complete** PHPUnit suite including all independent
process tests. No concurrency skip, continue-on-error, testsuite exclusion, SQLite
substitute or green fallback exists. Missing instrumentation or missing JUnit
artifact fails the job. JUnit results are retained seven days. No deployment/CD,
Docker application stack or frontend build is included.

Workflow YAML and safety configuration are validated locally; local equivalents
are run as documented in the final report. **Hosted GitHub Actions execution is
NOT RUN in this workspace** because this task does not commit/push the workflow.
No hosted green run, PHP-8.4 hosted result or full containerized runner result is
claimed. The first remote run remains an external verification step. Local MySQL
8.4.10 has the required capabilities; no demonstrated database runner limitation
requires excluding a critical test.

## Troubleshooting and remaining operational limits

- 401 after login: inspect browser credentials, host/domain, stateful Origin/Referer
  and shared session storage. Expired POSTs can first return 419; refresh CSRF/login.
- 419: use the current URL-decoded XSRF cookie and include credentials. Do not copy
  stale CSRF headers or disable CSRF. CORS success alone is not authentication.
- 503: check key/model/provider and canonical integer configuration; never log key
  values. Model support in documentation does not imply account authorization.
- 502/incomplete response: inspect safe categories and operator-side account
  diagnostics. Retry deliberately with the same unchanged key, not an automatic
  server retry. A different message needs a new key.
- 429: read the exposed Retry-After, verify a shared production cache/prefix and
  provider quotas. Request-boundary throttles are not strict spend reservations.
- Busy conversation after process death: wait for its finite MySQL lease; no
  manual global unlock or replacement transcript is required.
- Stale/expired/conflicting planning: obtain a fresh proposal. Manual previews are
  preliminary; no suggested interval bypasses final deterministic constraints.
- Failed consent: keep pending/null receipt distinct from a failed event; only a
  separate explicit confirmation can authorize partial/unplaced effects.
- DB test refusal: correct the dedicated database/credentials/instrumentation,
  never remove the guard or swap SQLite. Never parallelize suites on one database.

Deployment prerequisites (not verified production readiness): appropriate HTTPS,
stateful/CORS/session settings, stable protected APP_KEY, shared rate storage,
MySQL backups/privileges/migrations, scheduler for actual Reminder delivery,
process/time/memory limits, safe logs/retention and optional live account validation.
Greedy planning limitations, 31-date horizons, complete-preview caps, bounded chat
history and cooperating-owner-lock assumptions remain. Arbitrary external SQL,
hard purge, external delivery transports, exports, frontend, streaming, voice and
new providers are outside H4. No production deployment was performed.

## Verification and final inventory

Historical H3c totals are not H4 verification evidence. The following executed
checkpoints and the final complete suite passed with zero failures, errors or skips.

| Automated verification | Tests | Assertions |
| --- | ---: | ---: |
| BackendIntegrationSmokeTest (all 23 definitions through HTTP) | 17 | 5477 |
| FrontendApiContractTest | 8 | 246 |
| AiReadinessHardeningTest | 19 | 161 |
| AiSharedRateLimitTest (independent processes/shared DB cache) | 1 | 24 |
| AiEnvironmentConfigurationTest (including paid opt-out gate) | 11 | 23 |
| New H4 cases combined | 56 | 5931 |
| Final focused run including existing OpenAiResponsesProviderTest | 93 | 6225 |
| Previous AI/auth/security regression checkpoint, before credential-echo extension | 2521 | 22252 |
| First complete-suite checkpoint, before credential-echo extension | 5393 | 50665 |
| **Final complete PHPUnit suite** | **5396** | **50695** |
| Independent-process classes within final complete suite | 184 | 2598 |

Commands executed (database suites serialized against the guarded database):

```sh
composer validate --strict --no-check-publish
composer check-platform-reqs
vendor/bin/phpunit tests/Feature/BackendIntegrationSmokeTest.php tests/Feature/FrontendApiContractTest.php tests/Feature/AiReadinessHardeningTest.php tests/Feature/AiSharedRateLimitTest.php tests/Unit/AiEnvironmentConfigurationTest.php
vendor/bin/phpunit tests/Feature/AiReadinessHardeningTest.php tests/Feature/OpenAiResponsesProviderTest.php tests/Feature/BackendIntegrationSmokeTest.php tests/Feature/FrontendApiContractTest.php tests/Feature/AiSharedRateLimitTest.php tests/Unit/AiEnvironmentConfigurationTest.php --log-junit /tmp/h4-focused-final.xml
vendor/bin/phpunit --filter='Ai|AgentOrchestrator|OpenAi|TaskAction|SubtaskReminderAction|PlanningAction|AuthProfile|Security|BackendIntegration|FrontendApiContract' --log-junit /tmp/h4-ai-regression.xml
vendor/bin/phpunit --log-junit /tmp/h4-full-final.xml --log-events-text /tmp/h4-full-final-events.log
vendor/bin/pint --dirty
vendor/bin/pint --test
git diff --check
```

Manual/local checks: Composer consistency/platform checks passed; application
bootstrap reported Laravel 13.33.0/PHP 8.5.11/Composer 2.10.3. A guarded SELECT
verified MySQL 8.4.10, student_planner_testing, REPEATABLE READ and enabled
performance_schema. Isolated temporary config cache/clear produced the same 90 API
routes as uncached registration and left the normal app cache untouched. Guarded
Artisan migrate:fresh, migrate:rollback and migrate all passed on the disposable
test database. No H4 migration was introduced; existing migration/history rollback
tests remain in the complete suite.

Workflow validation passed: YAML parsing, safety configuration assertions,
bash -n for every run block and php -l for embedded capability checks. This is
static/local validation, not a hosted runner result. New README/readiness/contract
links resolve. Git-tracked environment/credential paths were checked: only
.env.example, with no real key/credential files. .env.testing/.env.live ignore
patterns were checked without creating credential files. No lock/dependency
update, commit, push, paid inference or production deployment was performed.

Paid live-provider status: **NOT RUN** (no explicit paid opt-in; account access and
billing were not checked). Hosted GitHub Actions status: **NOT RUN**. The offline
live-gate test is a guard verification, not a successful paid/live scenario.


## Final acceptance status

**H4 implementation and reproducible offline backend acceptance: COMPLETE.**
The final full suite, focused tests, schema round trip, configuration/routes,
Composer checks, Pint dirty/check mode and whitespace checks passed. No existing
test was removed, weakened or skipped. The final full run took approximately
401 seconds; all 56 new H4 cases contribute 5931 assertions. All earlier AI/domain
regressions remain green after the final credential-echo fix.

Independent-process coverage in that full run: AiChat 4/61, AiManagement 29/439,
AiPlanning 41/602, AiSharedRateLimit 1/24, AiTaskAction 15/224,
RelativeReminder 23/293, Reminder 15/202, ReminderDelivery 29/396,
StudyPlan 5/61, StudyReplanning 14/174 and StudySession 8/122
(tests/assertions). Total 184/2598. These include the retained real MySQL
contention assertions and separate PDO/PHP workers; no concurrency suite was excluded.

This acceptance describes implemented backend integration and executed offline
verification. **Hosted CI remains unverified until the workflow is committed/pushed
and runs; paid live smoke remains optional and NOT RUN.** No hosted green result,
account/model entitlement, PHP-8.4 hosted runtime result or production-readiness
certification is claimed. Those external checks are clearly separate from the
passing local automated evidence. There is no known remaining offline implementation
or verification defect, and no additional domain/schema/Tool scope was introduced.

## Changed-file inventory

Starting and ending HEAD: `1335fcd19ed9675d75452ee8752b3920f4c474c6`.
The initial tree was clean; final work remains uncommitted and unstaged: 12 modified
tracked files and 12 new files (24 total). No commit or push was made.

- `.env.example`
- `.github/workflows/backend-verification.yml` (new)
- `.gitignore`
- `README.md`
- `app/Http/Middleware/ObserveAiRequest.php` (new)
- `app/Services/AI/AgentLimits.php`
- `app/Services/AI/AiConversationService.php`
- `app/Services/AI/OpenAiResponsesProvider.php`
- `bootstrap/app.php`
- `config/ai.php`
- `config/cors.php`
- `docs/ai-agent-chat.md`
- `docs/architecture.md`
- `docs/auth-profile.md`
- `docs/backend-integration-readiness.md` (new)
- `docs/frontend-api-contract.md` (new)
- `tests/Concerns/InteractsWithSpaBrowser.php` (new)
- `tests/Feature/AiReadinessHardeningTest.php` (new)
- `tests/Feature/AiSharedRateLimitTest.php` (new)
- `tests/Feature/BackendIntegrationSmokeTest.php` (new)
- `tests/Feature/FrontendApiContractTest.php` (new)
- `tests/Live/OpenAiLiveSmokeTest.php` (new)
- `tests/Support/ai_rate_limit_worker.php` (new)
- `tests/Unit/AiEnvironmentConfigurationTest.php` (new)
