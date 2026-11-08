# Stage 18B — Backend result

## Architecture review and baseline

Implementation started on clean `main` at Stage 18A commit
`9521448c6f76dd0d366aff5382e89c41968df11b`. AGENTS.md was read before editing.
Reviewed the Stage 18A read contract and its **Stage 18B proposal architecture —
design only**, relevant architecture/domain/business/database documentation and
ADRs, actual availability writer/Request/Policy/model/Resource/controller, User
relation and routes; TaskActionProposalService, TaskActionConfirmationService,
ActionTargetFingerprint, LessonActionService/Registry/argument validator,
AgentOrchestrator, AiConversationService finalization/history, AiPendingAction,
AiTaskActionRequest, Resources, provider schema adapters, all AI action migrations
and the existing offline, concurrency and migration tests.

Stage 18A hosted CI status was not supplied and no local failure artifact was
available. GitHub was not accessed, per the explicit repository restriction, so no
hosted CI status is claimed. The checked-in workflow was inspected read-only: it
runs the ordinary Unit-then-Feature offline MySQL suite and a separate fake Gemini
action matrix. Before implementation, the focused Stage 18A/schema/read/availability
baseline was reverified locally: **450 passed / 1,959 assertions**, exit **0**.
No baseline application failure blocked this task.

The Stage 18A design matched current code. Availability lexical validation was in
the Form Request, while interval/overlap rules and owner serialization were in
StudyAvailabilityService. Existing proposal Services already prepare in read-only
snapshots and persist only through successful turn finalization. Existing H3
confirmation supplies outer transaction, conversation/action/owner lock prefix,
explicit decisions, lease exclusion, MySQL expiry, immutable receipt and replay.
The latest action-type CHECK allowed 18 names, requiring the planned expansion to
21. No second mutation engine or persistence framework was warranted.

Before editing, the implementation scope was identified as shared availability
input rules and Service preparation, three fixed action adapter classes,
AgentOrchestrator, TaskActionConfirmationService, one new migration, existing
migration-test overlay helpers/discovery fixtures, focused tests and documentation.
Controllers, endpoints, pending-action model/Resource/finalization and provider
implementations needed no change.

## Implemented changes and reused Services

1. `StudyAvailabilityInputRules` holds exact integer weekday and local-clock rules.
   SaveStudyAvailabilityWindowRequest and AI argument validation both use it.
   StudyAvailabilityService validates complete candidates with those same rules,
   then the original single interval/overlap implementation. It exposes a
   nonlocking read-only `prepare()` and retains the three existing transactional
   writers and owner serialization. Adjacent periods, self-exclusion on updates,
   overnight rejection and `24:00:00` end semantics are preserved. Cancelled create,
   update and delete events now throw rather than pretending to persist a write.
2. AvailabilityActionRegistry appends exactly three provider-independent proposal
   schemas. AvailabilityActionArgumentValidator rejects unknown fields, nulls,
   nonexact types, missing fields and target-only updates without coercion. Mutation
   schemas use the existing `strict: false` provider convention to preserve true
   omission; the server remains strict. No new provider or provider behavior.
3. AvailabilityActionService is a thin adapter around StudyAvailabilityService and
   StudyAvailabilityWindowResource. Preparation reloads fresh profile/configuration,
   uses existing ownership/Policy protection, builds an exact material review and
   ActionTargetFingerprint, and returns a transient proposal. It never calls a
   writer, locks rows, inserts a pending action, or speculatively saves/rolls back.
4. AgentOrchestrator appends these schemas after the existing 28 definitions,
   dispatches preparation explicitly and counts attempts in the existing global
   one-mutation-per-turn limit. Server-owned availability wording replaces false
   provider success claims. Instructions distinguish weekly configuration/free
   slots, require exact owned ID discovery and clarification, and preserve explicit
   confirmation and separate replanning.
5. TaskActionConfirmationService adds one fixed availability execution branch and
   accurate domain history wording. All existing decision endpoints, status/replay,
   expiry, ownership and transaction guards remain intact. Its ordinary outer
   transaction calls the same StudyAvailabilityService methods as REST. Current
   profile and all owned windows are reloaded/locked before hash/review comparison
   and complete domain revalidation. Receipts re-read actual persistence; delete
   verifies absence. Business write, executed action, receipt and history share one
   durable commit; unexpected failures roll everything back.
6. The existing isolated independent-process worker gained only availability
   REST operations and model-event gates. New tests use real MySQL lock waits,
   not timing-only claims. Legacy forward-only migration test helpers recognize
   the new overlay instead of attempting its forbidden downgrade.

The conservative fingerprint includes the owner's selected ID/timezone and all
owned weekly rows, keyed deterministically by ID, with canonical raw attributes.
**Any owned weekly-window edit intentionally stales all pending availability
proposals**, including create and unrelated weekdays. Other users' configuration
and unrelated owner profile names/emails do not stale them. Actual values, not
second-resolution timestamps alone, participate. Fingerprints stay private.

## Persistence and migration

Exactly one application migration was added:
`database/migrations/2026_11_08_000002_add_ai_availability_actions.php`.
It uses one ALTER to replace only `ai_actions_valid_type`, retaining all 18 existing
names and adding `propose_availability_create`, `propose_availability_update`,
`propose_availability_delete`. All fit the existing VARCHAR(32). The constraint
now accepts exactly 21 action types. No table, column, index, FK, other CHECK,
applied migration, pending action or receipt is rewritten. `down()` always throws
before DDL/data changes; correction/downgrade must preserve history using a
compatible new forward migration.

Migration tests restore the immediate 18-type predecessor **only on the guarded
disposable test schema**, insert all 18 legacy executed action/receipt types,
exercise the real upgrade, compare every raw record and unchanged schema metadata,
accept all 21 names, reject invalid types/states/receipt combinations and prove
rollback refusal preserves availability history. Legacy migration tests exclude
three forward-only overlays before historical rollback exercises; their underlying
assertions and database safety guards remain intact.

## Isolated environment and paid-provider exclusion

A new disposable schema was provisioned:
`student_planner_isolated_s18b_20261108_160055_9560`. Name absence was verified before
creation; preflight then verified **0 tables**, APP_ENV testing, mysql driver,
configured database, actual SELECT DATABASE() and PLANNER_TEST_DATABASE agreement.
MySQL **8.4.10**, **REPEATABLE-READ**, performance_schema **1** were confirmed before
migrations. Tests\TestCase repeats database guards before destructive test traits;
independent workers repeat the guards before any HTTP work. Real lock contention
is observed in performance_schema.data_lock_waits on this schema.

Retained `student_planner`, earlier-stage schemas, `.env` and existing runtime
configuration caches were not reset, migrated, truncated or edited. Private
credentials/key, isolated storage/cache paths and test logs live only in ignored
`.codex/stage18b/`. Provider keys are blank in that runtime and both live/paid flags
are 0. Tests install fake keys/models with `Http::preventStrayRequests()` and native
fake responses or the existing fake LlmProvider. Confirmation has no provider
call. No paid Gemini/OpenAI request was made. The isolated schema is left available;
no retained data was copied or destroyed.

## Verification commands and results

Commands run from Backend root in Bash with
`source .codex/stage18b/runtime.env` before PHPUnit. The private runtime selects the
verified schema above; it is not committed or required by CI. Ordinary CI keeps
the existing guarded disposable MySQL configuration.

Command B (baseline, before implementation):

```bash
vendor/bin/phpunit tests/Unit/AiAvailabilityToolSchemaTest.php tests/Unit/AiToolFoundationTest.php tests/Feature/AiStudyAvailabilityToolTest.php tests/Feature/StudyAvailabilityApiTest.php
```

Command S (shared-rule integration):

```bash
vendor/bin/phpunit tests/Unit/AgentOrchestratorTest.php tests/Feature/StudyAvailabilityApiTest.php tests/Feature/AiStudyAvailabilityToolTest.php
```

Command A (first new action checks):

```bash
vendor/bin/phpunit tests/Unit/AvailabilityActionTest.php tests/Feature/AiAvailabilityActionsTest.php
```

Command M (second action/migration check):

```bash
vendor/bin/phpunit tests/Unit/AvailabilityActionTest.php tests/Feature/AiAvailabilityActionsTest.php tests/Feature/AiAvailabilityActionMigrationTest.php
```

Command C (feature/migration/concurrency check):

```bash
vendor/bin/phpunit tests/Feature/AiAvailabilityActionsTest.php tests/Feature/AiAvailabilityActionMigrationTest.php tests/Feature/AiAvailabilityActionConcurrencyTest.php
```

Command F (focused checks after test fixture corrections):

```bash
vendor/bin/phpunit tests/Unit/AvailabilityActionTest.php tests/Feature/AiAvailabilityActionsTest.php tests/Feature/AiAvailabilityActionMigrationTest.php tests/Feature/AiAvailabilityActionConcurrencyTest.php
```

Command E (two additional persistence/orchestration edge checks):

```bash
vendor/bin/phpunit tests/Feature/AiAvailabilityActionsTest.php --filter 'test_owned_read|test_cancelling_a_no_op'
```

| Check | Exit | Exact result |
| --- | --- | --- |
| New isolated MySQL preflight | 0 | Empty new schema, verified mysql/testing/actual database, 8.4.10, REPEATABLE-READ, instrumentation enabled |
| B | 0 | 450 passed / 1,959 assertions / 7.756 s |
| S | 0 | 107 passed / 901 assertions / 7.434 s |
| A, first run | 1 | 406 tests / 370 passed / 36 failed / 1,581 assertions / 14.996 s; D18B-01 |
| M | 2 | 407 tests / 373 passed / 33 failures / 1 error / 1,777 assertions / 24.515 s; D18B-02/03 |
| C | 1 | 55 tests / 51 passed / 4 failed / 841 assertions / 42.880 s; D18B-04/05 |
| F, corrected fixtures | 0 | 414 passed / 2,146 assertions / 36.302 s |
| E, initial edge checks | 1 | 2 tests / 1 passed / 1 failed / 34 assertions / 3.555 s; D18B-06 |
| E, corrected no-op event hook | 0 | 2 passed / 36 assertions / 3.634 s |

The full-suite, corrected smoke, provider-matrix and quality outcomes are recorded below. Every same failing case remains present; none was dropped or skipped.

Coverage includes exact new schemas, unchanged 28-contract fixture and order,
Gemini/OpenAI omission compatibility; strict values, missing/unknown fields,
invalid exact times/weekdays/IDs; 126 cross-domain/round combinations enforcing
one proposal, including invalid attempts; read-only SELECTs and exact all-table
snapshots; no pending action or external effects during preparation; partial
update retention, self-exclusion, adjacency/end-of-day and complete-candidate
validation; foreign/missing target equivalence and conversation ownership;
explicit decisions, conversational yes, no planning-only consent, active-turn
exclusion, rejection, expiry, idempotent chat and immutable decision replay;
changed target/deletion/siblings/timezone and material reviews; Service delegation
exactly once; actual persistence versus in-memory models, event cancellation,
post-write/receipt/history/finalization failures and full rollback; no unrelated
Tasks/Lessons/StudySessions/Reminders/preferences changes; owned read-to-proposal
sequencing; and the forward-only 18-to-21 CHECK upgrade.

Concurrency cases use independent guarded PHP/MySQL connections to prove:
competing overlapping creates and competing updates serialize on owner and leave
one executed action; same-action confirmations serialize on conversation and
replay one receipt; confirmation waiting for a real REST edit sees fresh committed
state and becomes stale; expiry during a held business write rolls back the window,
receipt and history. Existing domain/action concurrency regressions are also part
of the ordinary suite.

## Diagnosed checks

**D18B-01 — fake test rate limit; component: test configuration; severity: low.**
A expected successful fake-provider turns but got HTTP 503 `ai_unavailable` in 36
cases (exit 1). Sanitized evidence: isolated log `ai.failure {"code":"ai_unavailable"}`
and AgentLimits::get caps requests_per_minute at 60. Confirmed cause: the new
fixture set it to 100. Correction: use the established test value 60. No server
limit or `.env` changed. Safe reverification: M no longer returned these 503s;
F and E subsequently passed, and the full-suite outcome is below.

**D18B-02 — empty decision JSON shape; component: HTTP test helper; severity: low.**
M expected 200/409/410/500 decisions but received standard 422
`A JSON object with only accepted fields is required.` (33 failures, command exit
2 together with D18B-03). Confirmed cause: the new helper used postJson(..., []),
which encodes `[]`; AiConversationRequest intentionally requires a JSON object.
Correction: use the same raw POST pattern as existing action tests, encoding the
body as an object (`{}` for an empty decision). Preserve tests rejecting extra
fields and planning consent. Safe reverification: C reached the decision handlers;
F passed all preserved cases. No Request or security guard was weakened.

**D18B-03 — selected profile columns in a test; component: stale-rule fixture;
severity: low.** M expected the internal-fingerprint rebind test to reach domain
revalidation; actual error was `Call to undefined relationship [id] on model
[App\Models\User]` (exit 2). Confirmed cause: Eloquent fresh([...]) takes relationship
names, not selected columns. Correction: use newQuery()->findOrFail(id,
['id', 'timezone']) in that fixture. Safe reverification: C/F exercise and pass
current overlap revalidation even with a test-rebound internal fingerprint.
This is not a MySQL/application failure.

**D18B-04 — repeated fake provider call IDs; component: multi-turn fixture;
severity: low.** C expected the second proposal after rejection to return 200;
three datasets returned 502 instead (exit 1 together with D18B-05). Confirmed cause:
the new helper reused `call_1` already present in conversation history; the existing
orchestrator correctly rejects reused IDs. Correction: generate unique fake call
IDs like existing Lesson tests. Safe reverification: F passes second proposals,
rejection and expiry without changing duplicate-call or history guards.

**D18B-05 — cached test authentication guard; component: security test setup;
severity: low.** C expected 404 after switching to a foreign test user; actual was
200 (exit 1). Confirmed cause: the same application instance retained the Sanctum
request guard's previously resolved user; changing only the web actingAs user did
not clear it. Existing security tests explicitly forgetGuards before switching.
Correction: follow that established reset before actingAs, then assert foreign
conversation, foreign action and unauthenticated attempts. Safe reverification:
F passes 404/401 isolation while all ownership code/Policies remain unchanged.
No real cross-user exposure was established by this test-fixture failure.

**D18B-06 — no-op cancellation event; component: persistence edge test; severity:
low.** E expected a cancelled no-op update to return 500, actual 200 (exit 1).
Confirmed cause: Eloquent skips the `updating` hook when there are no dirty fields,
so the test's cancellation callback never ran. Correction: cancel `saving`, which
also runs for a no-op save; retain the exact all-table rollback and 500 assertions.
No production writer was changed to force an event or manufacture a pass. Safe
reverification: E exit 0, both edge tests pass / 36 assertions. Valid no-op updates
remain allowed when an editable field is explicitly supplied; cancelled saves
cannot receive a successful receipt.

**D18B-07 — new smoke action cases; component: cookie/REST smoke fixture; severity:
low.** `vendor/bin/phpunit --log-junit .codex/stage18b/phpunit-full.xml` exited **2**:
**6,214 tests / 6,211 passed / 59,813 assertions / 747.555 s**, with exactly three
errors in BackendIntegrationSmokeTest datasets #18–20: `Unhandled match case of
type string` at its argument builder. Expected all supported action types to have
smoke arguments/readback. Confirmed cause: adding AvailabilityActionRegistry to the
smoke discovery/data matrix exposed three missing fixture branches; these cases
had not reached the application. Every other full-suite case passed, including
all new availability tests, migrations/concurrency and existing domain/security
regressions. This does not establish a Stage 18A application or CI failure.

Correction: retain all three cases; capture the ordinary REST-created availability
identity, build create/update/delete arguments, discover windows in the fake turn,
verify actual owned collection readback/deletion, preserve all unrelated business
snapshots, and retain cookie/CSRF authentication, replay, receipts, history and
logout assertions. Also include Stage 18A's tenth read in the all-read smoke case,
encoding empty Tool arguments as `{}`. No application code changed after the full
run. Safe reverification:
`vendor/bin/phpunit tests/Feature/BackendIntegrationSmokeTest.php` exit **0**,
**24 passed / 9,541 assertions / 16.830 s**. The unchanged 6,190 non-smoke cases
passed in the full run, and all 24 smoke cases pass after fixture completion;
together every one of the 6,214 full-suite cases has passing verification with the
final application implementation. A second unrelated 12-minute full rerun was not
needed after changing only the fully rerun smoke fixture; no clean single-run full
suite result is claimed.

**D18B-08 — Gemini private continuation versus public history; component: new
public-claim test; severity: low.** The native fake Gemini matrix command in Final
verification evidence exited **1**: **303 tests / 300 passed / 3 failures / 4,181
assertions / 93.309 s**. Expected the false provider phrase `Already changed` to be
absent from serialized messages; actual matches were exclusively inside private
Gemini native continuation `tool_calls.state.content`, not message content or the
public transcript. Confirmed cause: the new test serialized complete Eloquent
models, including provider-private native state that the existing portability
contract intentionally retains. Editing that signed/native state would violate
the existing provider contract rather than protect the public UI.

Correction: retain the false-claim assertion over persisted message `content`,
add the same assertion over the actual public messages endpoint, and additionally
assert that private Gemini model metadata is absent from that public response.
This tests the intended authority/privacy boundary directly; no public assertion,
provider code or security/confirmation guard was removed/weakened. Safe
reverification: the same 303-case native fake Gemini matrix exits **0**,
**303 passed / 4,199 assertions / 93.173 s**. The corresponding OpenAI public-history
cases are reverified separately below. No paid request or application mutation
failure was involved.

Formatting-only Pint fixes followed repository rules. Window comparisons in tests
sort object keys before strict scalar comparison because MySQL JSON object key
order is not a contract; no field, value or type assertion was removed.

## Remaining limitations and Stage 18C

Availability changes do not reschedule or delete study sessions, alter Tasks or
Lessons, create/cancel Reminders or modify numeric preferences. Replanning remains
an explicit separate workflow. No bulk edit/delete, automatic multi-action
confirmation, provider, queue, scheduler, persistence abstraction or Frontend code
was added. Proposals read a live configuration snapshot, not a reservation; the
complete owned weekly set is loaded internally for conservative fingerprinting,
while public reviews/receipts contain only the one target operation.

Structured fake-provider tests verify contracts and sequencing; no paid-model
selection quality or live hosted-provider behavior is claimed. Hosted CI was not
queried under the GitHub restriction. Use the ordinary Unit-then-Feature suite
order documented in Stage 18A; unrelated arbitrary test-order fragility was not
changed. No retained schema was migrated for deployment.

Stage 18C must add support for these three types to the existing confirmation UI,
render complete before/after/weekday/local time/timezone/effects, preserve end-of-day
`24:00:00`, send only `{}` to existing explicit decisions, respect safe stale/expiry
states and stored receipts, and refresh availability/derived read caches only after
execution. Unknown types must remain blocked until supported. No conversational
confirmation, client-owned arguments, bulk confirmation or automatic rescheduling.
The exact endpoints, schemas, errors and handoff are in
[availability-action-contract.md](availability-action-contract.md).

## Final verification evidence

The complete suite used the ordinary phpunit.xml Unit-then-Feature ordering:

```bash
source .codex/stage18b/runtime.env
vendor/bin/phpunit --log-junit .codex/stage18b/phpunit-full.xml
```

That run verified 6,190 non-smoke cases passing and 21 smoke cases passing before
three new smoke fixture errors. D18B-07 records the exact failure and correction.
The entire corrected 24-case smoke class was then rerun successfully; all 6,214
cases have passing verification against the unchanged final application code.
This distinction is deliberate: an initial full-run failure is not relabeled PASS.

Native fake Gemini regression matrix command:

```bash
source .codex/stage18b/runtime.env
AI_TEST_PROVIDER=gemini vendor/bin/phpunit tests/Feature/AiAvailabilityActionsTest.php tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php --log-junit .codex/stage18b/phpunit-gemini.xml
```

## Changed-file inventory

All changed files (relative to Stage 18A):

```text
app/Http/Requests/SaveStudyAvailabilityWindowRequest.php
app/Services/AI/Actions/AvailabilityActionArgumentValidator.php
app/Services/AI/Actions/AvailabilityActionRegistry.php
app/Services/AI/Actions/AvailabilityActionService.php
app/Services/AI/Actions/TaskActionConfirmationService.php
app/Services/AI/AgentOrchestrator.php
app/Services/Planning/StudyAvailabilityInputRules.php
app/Services/Planning/StudyAvailabilityService.php
database/migrations/2026_11_08_000002_add_ai_availability_actions.php
docs/ai-provider-portability.md
docs/ai-tools-foundation.md
docs/architecture.md
docs/database-review-notes.md
docs/database-schema.md
docs/planning-settings.md
docs/stage18/availability-action-contract.md
docs/stage18/availability-read-tool.md
docs/stage18/backend-stage18b-result.md
tests/Concerns/UsesForwardOnlyMigrations.php
tests/Feature/AiAvailabilityActionConcurrencyTest.php
tests/Feature/AiAvailabilityActionMigrationTest.php
tests/Feature/AiAvailabilityActionsTest.php
tests/Feature/AiChatPersistenceTest.php
tests/Feature/AiPlanningActionsTest.php
tests/Feature/AiStudyAvailabilityToolTest.php
tests/Feature/AiSubtaskReminderActionsTest.php
tests/Feature/AiTaskActionPersistenceTest.php
tests/Feature/BackendIntegrationSmokeTest.php
tests/Feature/GeminiGenerateContentProviderTest.php
tests/Fixtures/AI/tool-definitions-before-stage18b.json
tests/Support/ai_task_action_worker.php
tests/Unit/AgentOrchestratorTest.php
tests/Unit/AvailabilityActionTest.php
tests/Unit/LessonActionTest.php
tests/Unit/PlanningActionTest.php
tests/Unit/SubtaskReminderActionTest.php
tests/Unit/TaskActionTest.php
```

Final native fake Gemini matrix: exit **0**, **303 passed / 4,199 assertions /
93.173 s**. It preserves all existing Task/Subtask/Reminder/Planning/Lesson
confirmation workflows and covers the complete new availability action Feature
suite with native Gemini schemas/responses. The preceding three private-state
test assertion failures and their correction are recorded in D18B-08.

Final repository quality: `vendor/bin/pint --test` exit **0**, repository-wide
PASS; `git diff --check` exit **0**. Bash-invoked PHP contract verification exit
**0**: all **6** complete JSON blocks parse, all **3** documented definitions
exactly equal AvailabilityActionRegistry output and local Stage 18 links resolve.
No previously applied migration was modified. No Frontend, GitHub, `.env`, provider
implementation, queue/scheduler, controller or new endpoint was involved.

Final OpenAI public-history/receipt check:
`vendor/bin/phpunit tests/Feature/AiAvailabilityActionsTest.php --filter test_explicit_confirmation_delegates_once`
— exit **0**, **3 passed / 207 assertions / 4.787 s**. All public-claim, hidden
provider metadata, Service delegation, persistence, immutable receipt and replay
assertions pass with both provider fixtures.

Final acceptance: **PASS**. All three proposal Tools, unchanged preceding 28
contracts, explicit confirmation, shared domain validation/writes, conservative
stale protection, owner-serialized concurrency, atomic receipts/history and the
forward-only CHECK expansion are implemented and verified. All encountered failed
checks have a confirmed diagnosis and successful targeted reverification. No
Stage 18B implementation blocker remains. The deployment migration is intentionally
not applied to retained `student_planner`; deploy the supplied forward expansion
before enabling new action persistence. Stage 18C remains Frontend work governed by
the exact handoff contract.

The **37** changed files are inventoried above. Implementation is committed
directly to `main`; the final commit SHA is supplied in the completion report.
