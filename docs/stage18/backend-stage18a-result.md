# Stage 18A — Backend result and Stage 18B preparation

## Scope and inspected architecture

Baseline: clean `main`, `2aef224118ad4bad371e0d36da96450970a5683f`.
AGENTS.md was read before implementation. No branch, worktree, PR, GitHub access,
Frontend access, `.env` modification or retained-database reset was performed.

Reviewed architecture/domain/business rules, database review notes and ADR-001–005;
ToolRegistry, ToolExecutor, ReadOnlyTools, ToolResultPresenter,
ToolArgumentValidator, AgentOrchestrator; StudyAvailabilityService, controller,
Resource, model, User relation, Request, Policy and relevant routes/migrations;
AI pending-action model, proposal preparation/finalization and confirmation
Services; provider schema adapters and inline HTTP fixtures; existing AI unit,
integration, chat, action, smoke and isolated MySQL test infrastructure.

Discovery assembles ToolRegistry reads, Task proposals, management reads/proposals,
planning proposals and Lesson proposals. The baseline had 27 definitions (9 reads,
18 proposals). ToolArgumentValidator rejects unknown keys and nonexact scalar types
against server definitions, then delegates date/comparison rules. ToolExecutor
checks the trusted User and sanitizes all failures. ReadOnlyTools reloads persisted
owner/timezone and dispatches through an explicit match. ToolResultPresenter masks
Task/Lesson associations; request-independent Resources already present free slots
and sessions. OpenAI adapts optional properties to nullable strict wire parameters;
Gemini preserves optional omission. Existing tests use fake HTTP/provider responses,
not paid calls. No material architecture conflict was found for this extension.

Availability has an owned Eloquent relation, exact local TIME strings and ISO
weekdays. The Resource already exposes exactly the four requested fields. Its REST
index sorts weekday/start/ID but is not paginated. StudyAvailabilityService contains
transactional owner serialization and same-day overlap/interval validation; Request
rules supply strict weekday and lexical time validation. Those writers are not
called by the new Tool. Following the existing convention against Services for
trivial persistence, the adapter queries the owned relation directly and reuses the
Resource; no new persistence/read abstraction or duplicate domain validator is
needed.

## Implemented changes

- Added `list_study_availability_windows` to ToolRegistry and the fixed read match.
  The unchanged strict validator/executor enforce the new scalar schema and safe
  errors. No arbitrary class or method selection exists.
- Added optional weekday filtering, explicit page/per-page defaults (1/20), a
  50-row maximum, integer offset overflow rejection, weekday/start/ID sorting and
  exact totals/pages. Only the four public window columns are selected.
- Reused StudyAvailabilityWindowResource without HTTP/authentication simulation.
  Local seconds, `00:00:00` and `ends_at=24:00:00` remain exact. Fresh profile
  timezone accompanies every response, including empty pages.
- Updated server instructions to distinguish weekly configuration from calculated
  free slots and to prohibit interpreting a read as mutation authorization/success.
  Discovery is now 28 definitions: 10 reads and the unchanged 18 proposals.
- Added focused schema/real-MySQL/fake-provider tests, extended existing all-read
  snapshots and chat datasets, and updated assertions that count registry entries.
  The versioned pre-change JSON fixture verifies all prior 27 definitions, including
  names, descriptions, schemas and relative order, remain exactly unchanged.
- Added [the exact Tool contract](availability-read-tool.md) and current-stage notes
  in AI Tool foundation, architecture and provider portability documentation.

Production changes are limited to `ToolRegistry.php`, `ReadOnlyTools.php` and
`AgentOrchestrator.php`. No controller, route, Request, Policy, Resource, model,
StudyAvailabilityService writer, provider adapter, confirmation service, database
constraint or migration changes. No new proposal types are registered.

## MySQL isolation and provider safety

Tests run only on newly created disposable schema
`student_planner_isolated_s18a_20261108_153825_ba08`, MySQL **8.4.10**.
Provisioning checked the name against the existing isolated-name convention and
verified it did not already exist. Before test migrations, a separate runtime
preflight verified **zero tables**, `APP_ENV=testing`, mysql driver, configured
connection database, `PLANNER_TEST_DATABASE` and actual `SELECT DATABASE()` equality.
The existing Tests\TestCase guard repeats these checks before destructive test
traits run. The retained `student_planner` and earlier-stage schemas were not reset,
truncated, migrated or copied. The new isolated schema is left available.

Private runtime settings/credentials, generated app key, isolated storage and logs
remain ignored under `.codex/stage18a/`. `.env` and existing config cache are
untouched. `APP_CONFIG_CACHE`/route cache/storage overrides isolate test state;
provider keys are blank in runtime, paid/live flags are 0. HTTP fake tests install
fake keys/models and prevent stray requests; no paid Gemini or OpenAI requests run.

## Verification and exact outcomes

All PHPUnit commands use Bash `source .codex/stage18a/runtime.env` first. This
private file selects only the isolated schema above; it is not committed or needed
by CI, which can use the repository's documented guarded test database setup.

Focused command F:

```bash
vendor/bin/phpunit tests/Unit/AiAvailabilityToolSchemaTest.php tests/Unit/AiToolFoundationTest.php tests/Feature/AiStudyAvailabilityToolTest.php
```

Regression command R:

```bash
vendor/bin/phpunit tests/Unit/AgentOrchestratorTest.php tests/Unit/TaskActionTest.php tests/Unit/SubtaskReminderActionTest.php tests/Unit/PlanningActionTest.php tests/Unit/LessonActionTest.php tests/Feature/AiToolsIntegrationTest.php tests/Feature/AiChatTest.php tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php tests/Feature/GeminiGenerateContentProviderTest.php tests/Feature/OpenAiResponsesProviderTest.php tests/Feature/LlmProviderContractTest.php tests/Feature/BackendIntegrationSmokeTest.php tests/Feature/StudyAvailabilityApiTest.php
```

| Check | Exit | Exact outcome |
| --- | --- | --- |
| New isolated MySQL preflight | 0 | testing/mysql, expected database, 0 initial tables, MySQL 8.4.10 |
| F, first run | 1 | 412 tests, 410 passed, 2 failed, 1,624 assertions; test fixture issues below |
| F, corrected fixtures | 0 | 412 passed, 1,646 assertions, 6.892 s |
| R, first run | 1 | 938 tests, 937 passed, 1 failed, 15,410 assertions, 111.235 s; stale OpenAI count fixture below |
| `vendor/bin/phpunit tests/Feature/OpenAiResponsesProviderTest.php`, corrected count | 0 | 37 passed, 297 assertions, 0.889 s |
| `vendor/bin/pint --dirty` | 0 | PASS |
| `vendor/bin/pint --test` | 0 | repository-wide PASS |
| `git diff --check` | 0 | PASS |

The final ordered combined outcome and documentation verification are recorded below.

Coverage includes exact schema/discovery and preserved baseline definitions;
no-argument defaults; ISO Monday/Sunday filtering and absent matches; weekday,
start-time and ID ordering; repeated reads and filter+pagination composition;
20/50 bounds and all 51 records across pages; accurate empty/out-of-range metadata;
maximum representable page with size 1 and overflowing page*size rejection;
foreign-user isolation despite ambient foreign authentication and unsaved timezone;
malformed/null/string/float/bool/nested/nonpositive/unknown arguments before queries;
unsaved, deleted and stale owner identities; local seconds and end-of-day strings;
all-table snapshots and nonlocking SELECT-only queries for every read; no external
HTTP/Bus/Queue/Notification effects at the Tool layer; no default materialization;
fake OpenAI and Gemini chat round trips, correlated tool content, provider discovery,
server instructions, unchanged business-table snapshots and zero pending actions.
Existing chat/security/action regressions retain authentication, cross-user
conversation isolation and explicit confirmation behavior.

## Diagnostics

**D18A-01 — read-only source discovery (tooling).** Expected a `scripts` directory;
`rg -n 'runtime|isolated|mysql' scripts docs/stage15/backend-local-readiness.md .gitignore`
reported `scripts: No such file or directory`, exit 2. Root cause: that repository
path does not exist, not a MySQL/application failure. Remediation: use `rg --files`
and the actual ignored `.codex/stage17/provision.php`/runtime as the verified local
isolation pattern. Repeat: actual files inspected, new schema provisioning and
zero-table preflight passed, exit 0.

**D18A-02 — deleted identity fixture (test).** F exited 1 with expected `not_found`
but actual `access_denied` in
`test_offset_overflow_and_invalid_identity_have_safe_errors`. Eloquent delete sets
`exists=false`; ToolExecutor correctly rejects that instance before owner reload.
Remediation: expect `access_denied` for the deleted instance and separately clone
its persisted-looking state before deletion to test a stale row lookup returning
`not_found`. No application guard changed. Repeat F: exit 0, all 412 passed.

**D18A-03 — fake OpenAI model configuration (test runtime).** The same F run exited
1: expected HTTP 200 but received 503 for the OpenAI dataset. Sanitized isolated log:
`ai.failure {"code":"ai_unavailable"}`. Confirmed root cause: the new fake setup
set provider/keys but omitted `ai.model`; OpenAiResponsesProvider requires a valid
model before HTTP dispatch and `config/ai.php` supplies no default. Gemini dataset
passed. Remediation: explicitly set the same fake test model used in existing
AiChatTest. No `.env`, provider, guard or business change. Repeat F: exit 0, both
fake-provider datasets pass, all 412 tests pass. This was not an application bug or
MySQL connectivity problem.

**D18A-04 — decision Request filename discovery (tooling).** Read-only inspection
attempted `cat app/Http/Requests/AiActionDecisionRequest.php`, expected a decision
Request but got `No such file or directory` (cat exit 1; the surrounding batched
inspection ended 0). A follow-up
`rg --files app/Http/Requests | rg 'Ai|Action'; cat app/Http/Requests/DecideAiActionRequest.php`
also exited 1 for the guessed name. Root cause: this repository uses
`AiTaskActionRequest.php` for the shared action routes. Remediation: read that
listed real file. Repeat `cat app/Http/Requests/AiTaskActionRequest.php`: exit 0;
confirmed empty nonplanning decision bodies and existing owner-scoped authorization.
No implementation or runtime fault is implicated. A later read-only inspection also\ntried `cat app/Services/Tasks/SubtaskPreparationService.php` (exit 1: absent);\n`rg -n -A 42 'function prepare' app/Services/Tasks/SubtaskService.php` then\nlocated/read the actual implementation (exit 0).

**D18A-05 — previous read count in OpenAI wire fixture (test).** R exited 1,
expected 7 ToolRegistry read definitions but actually received 8 in
OpenAiResponsesProviderTest's native contract test. Root cause: an existing hardcoded
read count was missed during count updates. The other 937 regression tests passed;
the actual 8 definitions are correct after this milestone. Remediation: change
that assertion to 8 while retaining all strict/native contract assertions and the
new exact 27-definition baseline comparison. Repeat
`vendor/bin/phpunit tests/Feature/OpenAiResponsesProviderTest.php`: exit 0,
37 passed / 297 assertions. The final combined F + R rerun is recorded below.

**D18A-06 — interleaved PHPUnit application lifetime (test harness/order).** The
first combined F + R command listed the Feature file from F before the plain Unit
files from R and exited 2: 1,350 tests, 1,349 passed, 17,082 assertions, one error
in the existing SubtaskReminderActionTest at line 233:
`Target class [validator] does not exist.` Expected all checks to pass. The exact
failed command was:

```bash
vendor/bin/phpunit tests/Unit/AiAvailabilityToolSchemaTest.php tests/Unit/AiToolFoundationTest.php tests/Feature/AiStudyAvailabilityToolTest.php tests/Unit/AgentOrchestratorTest.php tests/Unit/TaskActionTest.php tests/Unit/SubtaskReminderActionTest.php tests/Unit/PlanningActionTest.php tests/Unit/LessonActionTest.php tests/Feature/AiToolsIntegrationTest.php tests/Feature/AiChatTest.php tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php tests/Feature/GeminiGenerateContentProviderTest.php tests/Feature/OpenAiResponsesProviderTest.php tests/Feature/LlmProviderContractTest.php tests/Feature/BackendIntegrationSmokeTest.php tests/Feature/StudyAvailabilityApiTest.php
```

Confirmed cause: the unchanged Unit test calls `Validator::getFacadeRoot()` before
installing its standalone factory. After a Laravel Feature teardown, the retained
facade application container no longer has a validator binding. The Unit test
passes before a Feature test, consistent with phpunit.xml's Unit-then-Feature suite
order. No database connectivity/application Tool failure occurred.

Minimal reproduction:

```bash
vendor/bin/phpunit tests/Feature/AiStudyAvailabilityToolTest.php tests/Unit/SubtaskReminderActionTest.php --filter 'test_empty_availability|test_shared_subtask_preparation'
```

Reproduced exit 2, 2 tests / 1 passed / 19 assertions / the same missing-validator
error. Remediation: order the existing plain Unit tests before Laravel Feature
tests, matching the repository suite. Reverse-path repeat command:

```bash
vendor/bin/phpunit tests/Unit/SubtaskReminderActionTest.php tests/Feature/AiStudyAvailabilityToolTest.php --filter 'test_empty_availability|test_shared_subtask_preparation'
```

Repeat exit 0: 2 passed / 20 assertions / 4.528 s. No unrelated Service, assertion or
test harness behavior was changed. The full ordered combined verification below
uses every same test, rather than omitting the affected test. Arbitrarily
interleaving standalone Unit tests after Laravel teardown remains an inherited
suite-order limitation, not an unresolved Stage 18A behavior failure.

## Stage 18B proposal architecture — design only

Future actions are `propose_availability_create`, `propose_availability_update` and
`propose_availability_delete`. None is implemented/registered here. They should be
thin fixed adapters around StudyAvailabilityService, sharing H3 persistence and
confirmation rather than introducing another mutation engine.

### Proposal preparation and deterministic authority

Create requires explicit ISO weekday/start/end values. Update/delete require an
exact owned window ID grounded in a successful read or explicit user input;
ambiguous descriptions require clarification. Update preserves omission and merges
only allowed supplied fields with the current complete target. No owner/timezone,
SQL target, generic service selector, consent or client-editable execution payload
may be accepted. Delete is one identified window, never implicit bulk replacement.

StudyAvailabilityService currently keeps `validateInterval()` private, combines
interval checks with a locking overlap query, and depends on the Form Request for
strict weekday/lexical-time validation. Stage 18B must deliberately expose shared
read-only preparation and reusable field rules, so REST and AI validate the same
complete candidate. Preparation must not call a writer and roll it back. Keep the
single overlap predicate, self-exclusion on update, adjacency acceptance, overnight
split requirement and exact `24:00:00` semantics authoritative in the Service/shared
rules. A nonlocking preparation snapshot may advise; it never reserves a slot.
Confirmation must apply the same scalar/time rules again as well as the existing
transactional overlap checks. Do not merely copy Form Request regexes into a second
independent AI implementation or assume the current Service alone validates every
malformed scalar input.

Produce a bounded before/after review with exact local clock values, weekday,
window identity and persisted timezone. New IDs remain absent/null until actual
creation. Explain that changing allowed recurring periods does not move, delete or
replan existing StudySessions or adjust reminders/preferences. Keep any subsequent
planning/recovery action separately reviewed and confirmed.

### Existing pending action and stale-state protocol

Integrate the three definitions into the existing global one-proposal-per-turn
counter, fixed prepare/execute allowlists and domain reporting. A successful
originating turn alone persists one AiPendingAction with immutable arguments,
preview, target_hash, call/turn provenance, pending state and MySQL 15-minute expiry.
Failed turns leave no confirmable action. No new action table, workflow framework,
planning token or protected payload is required for these local values.

Reuse ActionTargetFingerprint over canonical raw owned records and the reviewed
persisted timezone. Update/delete must fingerprint the original target's actual
weekday/times/identity rather than second-resolution updated_at alone. A
conservative design also fingerprints the sorted complete owned weekly set for
all three types, including create (which has no target yet). Include the owner's
selected ID/timezone, not unrelated profile fields. This detects concurrent inserts,
deletes or changes to the reviewed configuration. It intentionally makes unrelated
weekly edits stale; document this tradeoff. A bounded hash is internal; the public
review need only show the selected operation. Regenerate the complete material
review at confirmation and compare it with the stored review. Missing/foreign or
changed targets, changed timezone or configuration require a new proposal; never
silently adapt an old proposal to a different target or changed time.

Explicit authenticated confirm/reject endpoints remain authoritative. Conversational
"yes", successful reads, provider output and proposal text cannot confirm anything.
Keep empty confirmation/rejection bodies for these types; existing planning-only
consent flags must remain planning-only. Rejection/expiry produce no domain writes.
Preserve current live-turn exclusion, ownership-scoped conversation/action lookup,
expiry checks after lock waits and durable decision history.

### Transaction, locks and receipts

Use TaskActionConfirmationService's existing outermost transaction and lock order:
owned conversation, pending action, then owner before any consistent snapshot;
load/lock owned availability rows in deterministic ID order before fingerprint and
candidate revalidation. Never open a snapshot before waiting for the owner lock.
The existing StudyAvailabilityService already serializes even empty collections
through the owner row; its public writers accept ambient transactions and use
same-connection nested DB::transaction calls. They can participate in this outer
transaction without an independent commit or a guard bypass. Repeated locks refer
to already held records. Re-check ownership through the owned relation and existing
update/delete Policy as appropriate; target IDs alone never establish ownership.

Under the retained owner lock, revalidate the complete current candidate and
same-day siblings using the authoritative overlap predicate. A new overlapping
window cannot slip between validation and commit when cooperating REST/AI writers
share owner serialization. Current invalid time/day, overlap, missing target or
stale review fails safely and leaves no executed receipt or partial domain effects.
Keep current expected-domain-failure rollback conventions; unexpected writer,
receipt or history failures roll back the outer transaction. No provider I/O enters
this transaction, and no second connection or separately committed availability
write is allowed.

On success, verify actual persistence before recording the receipt, including
model-event cancellation cases. Create/update receipts contain the actual saved
window and timezone; delete receipts retain the reviewed deleted identity and
before-fields, with deletion verified against the owned relation. Commit business
write, executed action state, immutable receipt and server history together.
Confirmation replay returns the saved receipt without invoking a writer/provider,
even if that window is later changed/deleted. Never reconstruct an old receipt
from the live availability table.

### Required forward-only migration

The latest constraint is `ai_actions_valid_type`, expanded by
`2026_11_02_000001_add_ai_lesson_actions.php` to **18** allowed proposal names.
The three future availability names are not accepted. Stage 18B therefore requires
**one new forward-only migration expanding 18 to 21 allowed action types**, preserving
all existing names/rows/receipts, FKs, statuses and receipt/resolution constraints.
All three names fit the existing VARCHAR(32); current JSON/hash fields suffice.
Do not edit any applied migration or rewrite the current constraint in Stage 18A.
Deploy the expansion before enabling proposal persistence; downgrade/correction
must use a compatible new forward migration and preserve availability history.
Add guarded disposable-MySQL CHECK/migration tests alongside stale/concurrency,
ownership, rejection/expiry, rollback, replay and existing-action regressions in
Stage 18B. This report introduces neither the migration nor mutation adapters.

## Remaining limitations

This milestone reads recurring configuration only. It adds no availability
mutation, proposal, bulk operation, planning integration change or R22 completion
claim. Reads are live offset pagination, not an immutable multi-page snapshot.
Instruction/schema/fake-provider tests validate structured behavior; no paid-model
selection quality or live provider behavior is claimed. Existing application
confirmation, overlap, time and security rules remain authoritative. No unresolved
application or environment blocker remains. The inherited out-of-order PHPUnit
facade limitation is documented in D18A-06; the supported Unit-then-Feature run passes.

## Changed-file inventory

| Files | Change |
| --- | --- |
| `app/Services/AI/Tools/ToolRegistry.php` | New exact read schema |
| `app/Services/AI/Tools/ReadOnlyTools.php` | Owned filtered/paginated Resource adapter |
| `app/Services/AI/AgentOrchestrator.php` | Counts and availability/free-slot/authorization instructions |
| `tests/Feature/AiStudyAvailabilityToolTest.php` | New isolated MySQL, security, bounds, exact-time and fake-provider coverage |
| `tests/Unit/AiAvailabilityToolSchemaTest.php` | New exact schema, preserved baseline and both provider adapters |
| `tests/Fixtures/AI/tool-definitions-before-stage18a.json` | All 27 original definitions captured before editing |
| `tests/Unit/AiToolFoundationTest.php` | New read included in strict contract/dispatch validation datasets |
| `tests/Unit/AgentOrchestratorTest.php` | New read dispatch and inherited chat dataset |
| `tests/Feature/AiToolsIntegrationTest.php` | All-table read-only snapshots and empty/owner cases include availability |
| `tests/Feature/GeminiGenerateContentProviderTest.php` | 28-definition wire contract, unchanged 18 proposals |
| `tests/Feature/OpenAiResponsesProviderTest.php` | Eight registry-read wire definitions |
| `tests/Feature/BackendIntegrationSmokeTest.php` | 28-definition discovery |
| `tests/Feature/AiPlanningActionsTest.php` | Updated total discovery count |
| `tests/Feature/AiSubtaskReminderActionsTest.php` | Updated total discovery count |
| `tests/Unit/TaskActionTest.php` | Updated registry-read count |
| `tests/Unit/SubtaskReminderActionTest.php` | Updated registry-read count |
| `tests/Unit/PlanningActionTest.php` | Updated composed pre-Lesson registry count |
| `tests/Unit/LessonActionTest.php` | Updated total discovery count |
| `docs/stage18/availability-read-tool.md` | Exact schema, semantics, security and example |
| `docs/stage18/backend-stage18a-result.md` | Implementation, outcomes, diagnostics and future mutation design |
| `docs/ai-tools-foundation.md` | Current Stage 18A capability/link |
| `docs/ai-provider-portability.md` | Current discovery count and compatibility/link |
| `docs/architecture.md` | Current read-only capability/link |

## Final verification command

This runs the same focused and affected-regression cases with all plain Unit tests
before Laravel Feature tests, as in phpunit.xml:

```bash
source .codex/stage18a/runtime.env
vendor/bin/phpunit tests/Unit/AiAvailabilityToolSchemaTest.php tests/Unit/AiToolFoundationTest.php tests/Unit/AgentOrchestratorTest.php tests/Unit/TaskActionTest.php tests/Unit/SubtaskReminderActionTest.php tests/Unit/PlanningActionTest.php tests/Unit/LessonActionTest.php tests/Feature/AiStudyAvailabilityToolTest.php tests/Feature/AiToolsIntegrationTest.php tests/Feature/AiChatTest.php tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php tests/Feature/GeminiGenerateContentProviderTest.php tests/Feature/OpenAiResponsesProviderTest.php tests/Feature/LlmProviderContractTest.php tests/Feature/BackendIntegrationSmokeTest.php tests/Feature/StudyAvailabilityApiTest.php
```

Final ordered result: exit **0**, **1,350 passed**, **17,083 assertions**,
**122.712 s**, with no failures, errors or skips reported. This reruns the same
938 affected regression cases and 412 focused cases together and resolves the
earlier count-fixture and command-order checks.

Final quality checks: `vendor/bin/pint --test` exit **0** (repository-wide PASS);
`git diff --check` exit **0**; Bash-invoked PHP validation exit **0** confirming
all **3** JSON examples parse, the documented full Tool definition exactly equals
the registry, and Stage 18 relative documentation links resolve. Read-only diff
inspection confirms migrations, StudyAvailabilityService mutation behavior,
ToolExecutor and ToolArgumentValidator are unchanged.

Final acceptance: **PASS**. The safe availability read is implemented, all existing
Tool contracts are preserved, no new mutation Tool or migration is introduced,
and the Stage 18B design identifies the required future 18-to-21 CHECK expansion.
No Stage 18A blocker remains. Changes are committed directly on `main`; the final
commit SHA is supplied in the completion report rather than self-embedded here.
