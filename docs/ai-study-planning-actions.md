# Stage H3c — confirmed AI study planning

H3c adds three fixed proposals to H1/H2/H3a/H3b. The provider sees 23 tools:
nine existing reads and fourteen mutation proposals. Existing twenty definitions
and read behavior remain unchanged. One attempted mutation call per turn is
allowed across every domain, including invalid attempts and later provider rounds.
The complete batch is checked before any proposal preparation. An E2 batch is one
reviewed domain operation, not permission for another mutation proposal.

Starting HEAD: `4823f2381f017ee0a5c2e597da9223e5511d1aa2`, clean working tree.
No commit, push, frontend, provider, planning algorithm or background transport is
part of this stage. See [ADR-004](decisions/ADR-004-ai-planning-confirmation-transaction.md).

## Tools, arguments and deterministic Services

| Tool | Required | Optional | Preview / authoritative execution |
| --- | --- | --- | --- |
| `propose_study_plan_create` | start_date, end_date | task_id, subtask_id | StudyPlanPersistenceService::preview / confirmWithinTransaction |
| `propose_adaptive_replanning` | start_date, end_date | task_id, subtask_id | StudyReplanningPersistenceService::preview / confirmWithinTransaction |
| `propose_session_reschedule` | study_session_id, starts_at, ends_at | none | StudySessionReschedulingService::preview / rescheduleWithinTransaction |

All arguments are flat JSON objects with no unknown fields. IDs are actual
integers 1..PHP_INT_MAX; numeric strings, floats, booleans and null fail. Optional
scope is omitted rather than null. A child requires its explicit owned parent.
Dates are exact real YYYY-MM-DD local labels, ordered and limited to 31 inclusive
calendar dates through PlanningContextService::validateDates. The persisted owner
timezone, including DST, controls availability and workload. UTC intervals are
exact real whole-second YYYY-MM-DDTHH:MM:SSZ in years 1000..9999; offsets,
fractional seconds, date-only values and end <= start fail through the existing
StudySessionLifecycleRules and TimeIntervalService.

Arguments never contain preview_token, user_id, timezone, acceptance flags,
replacement arrays or a Service name. Existing native provider adaptation is
unchanged. These three definitions explicitly use strict=false, preserving
optional omission; application validation is strict and authoritative.
The exact generated definitions appear below.

Relative dates use the trusted application clock and persisted timezone in the
orchestrator instructions. Missing clock times and ambiguous session/work identity
require clarification. Read tools may identify owned targets and free slots before
the proposal; they do not establish mutation authority or guaranteed feasibility.
Scope supports one Task/Subtask or all eligible work, as the existing Services do.
Subject-name requests require discovery/clarification to that supported scope;
this stage does not introduce Subject filtering or arbitrary Task-ID batches.

## Read-only preparation and complete reviews

The path is provider -> fixed proposal adapter -> strict validation -> deterministic
domain preview -> in-memory proposal -> successful H2 finalization -> AiPendingAction.
No domain writer is called during preparation, even speculatively under rollback.
No transaction spans provider I/O. Plan and E2 previews use their existing Services
and token issuance; manual preview shares SELECT-only source/context/chain discovery
and preliminary rules with the existing rescheduling writer.

Plan review uses StudyPlanResource without its token: as_of_utc, date range,
timezone, every proposed session's attribution/UTC interval/exact seconds, all
work_results, excluded_items, warnings and summary including fully_allocated.
An empty session list returns ok=true/state=no_actionable_plan with the full safe
review and no action UUID or private payload persisted.

E2 review uses StudyReplanningResource without token/expiry: every inspected source
identity, valid/cannot_replan/requires_replanning classification, original interval,
replacement interval or null, exact released_session_ids, untransferred seconds,
reason codes, work results, exclusions, warnings and complete summary. Valid and
cannot-replan sources are preserved. An empty affected set is not actionable.
Sources are never silently omitted or treated as arbitrary cancellation permission.

Manual review contains the safe original session, requested interval and identical
Task/Subtask attribution, original/requested/untransferred durations, and explicit
validation=preliminary / feasibility_confirmed=false. Shared preflight verifies
owned source/history, planned lifecycle, future original/replacement starts,
active work references and transfer duration. Remaining effort and C10 feasibility
are authoritative only after source retirement inside confirmation. The preview
never promises an interval will fit availability, Lessons, reservations, breaks,
workload or deadlines. Effects explicitly explain source retirement, one successor,
scheduled source Reminder cancellation, preserved history and no Reminder cloning.

Every preview also includes action, confirmation_required, required_consent and
fixed effect explanations. Full preview and full Tool envelope must fit configured
max_tool_result_bytes, with normal total-context/request budgets still enforced.
No session, cancellation or partial result is truncated. Oversized preparation
returns review_too_large and no action; the student must narrow scope. The encrypted
private payload has an additional 262144-byte serialized-input bound. Large
otherwise domain-valid reviews may therefore fail safely at the AI boundary.

## Protected tokens, lifetime and persistence

One additive migration, `2026_10_19_000002_add_ai_planning_actions.php`, expands the
action-type CHECK from eleven to fourteen and adds nullable LONGTEXT protected_payload.
Existing columns, UUIDs, unique originating-turn FK, call IDs, states, fingerprints,
receipts, ownership FKs and H3b's stable owner-reference index remain unchanged.
Applied migrations are not edited. Down refuses while any H3c history or non-null
protected payload remains. Compatible down removes the field and restores eleven
types without deleting/relabeling history or changing old receipts.

AiPendingAction casts protected_payload as encrypted:array and marks it Hidden.
For plan/E2 it contains only server-issued preview_token and validated absolute
UTC expires_at. The domain token is itself authenticated Laravel-encrypted JSON
with its existing purpose/version/owner/review/lifetime. Decode with the original
confirmation Service and bind the protected parameters to canonical stored action
arguments. No custom token format or cryptographic primitive is introduced.
Manual actions use null protected_payload and a source fingerprint.

Tokens never enter public previews, Tool results, provider requests, generic chat
messages, H2 replay results, action Resources or receipts. The model and client
cannot supply or replace one. The only serialized public expiry is the action's
effective deadline, not a raw protected payload.

At successful turn finalization, validate token currency again, then set action
expires_at to LEAST(MySQL UTC_TIMESTAMP(6)+15 minutes, domain absolute expiry).
The domain lifetime remains its original 900 seconds from issuance, floored to
whole seconds; a slow AI turn never renews it. Reject a domain deadline already
elapsed according to MySQL even if PHP's clock is slow. Recheck after action and
transcript writes so slow events cannot leave a confirmable expired review.
Existing H2 lease checks also run before/after finalization. Provider, lease,
transcript or action insertion failure rolls back all pending-action persistence.
Committed Idempotency-Key replay returns the same UUID/review without recomputing.

Confirmation checks the MySQL action deadline and retains all existing domain
UTC application-clock token checks after contention and after business writes.
Both must permit execution; clock skew can shorten eligibility, never extend a
review's action deadline. Expiry while blocked or during slow multi-session work
rolls back every business effect and receipt. A later decision can persist the
expired terminal outcome without rerunning a writer.

## Explicit authenticated consent and action API

Use the existing Sanctum/conversation-authorized endpoints:

- GET /api/ai/conversations/{conversation}/actions
- GET /api/ai/conversations/{conversation}/actions/{action}
- POST /api/ai/conversations/{conversation}/actions/{action}/confirm
- POST /api/ai/conversations/{conversation}/actions/{action}/reject

Only plan confirmation accepts optional accept_partial. Only E2 confirmation
accepts optional accept_partial and accept_unplaced_cancellation. Each requires an
actual JSON boolean and defaults to false. Strings, numbers, null, unknown keys,
query parameters, replacement tokens/intervals/IDs, owner overrides and nested
payloads fail. Manual/Task/Subtask/Reminder confirmation and every rejection retain
the empty-object/absent-body contract. Foreign routes are rejected before body
validation. Trusted direct confirmation callers undergo the same consent policy.

The existing domain acceptanceError rules determine required_consent. A non-full
plan needs accept_partial=true. An adaptive shorter replacement needs partial
acceptance; an unplaced source additionally needs explicit unplaced-cancellation
acceptance. Generic confirmation and conversational yes do not supply either flag.
Manual shorter transfers show exact untransferred duration and use the ordinary
empty-body confirmation, preserving Stage D's existing semantics.

Public pending action fields and H2 tools summaries retain their existing shapes.
Raw arguments, protected metadata, provider continuation and fingerprints remain
private. Current GET state reflects lazy MySQL expiry. Rejection is idempotent and
cannot reverse an executed action. No new confirmation route or workflow table exists.

## Transactions, locks and stale-state protection

Public StudyPlanPersistenceService::confirm, StudyReplanningPersistenceService::confirm
and StudySessionMutationContextService::run keep their fresh outermost guards.
The extracted internal methods require an existing trusted transaction and reuse
the same owner reload, validation and mutation bodies. Rescheduling shares one
writer closure between public/internal paths. Nothing commits independently.

H3 owns conversation -> action -> owner before any consistent read of turn/domain
data. REPEATABLE READ snapshots therefore form only after owner contention. The
domain paths preserve their existing locking:

1. Plan creation: owner serialization, existing Stage A/B/C9 nonlocking source reads,
   complete-batch revalidation, then session insert/FK locks in proposal order.
2. E2: ascending owned Tasks, ascending Subtasks, connected session/history records
   by ID, then scheduled Reminders in deterministic source/Reminder order.
3. Manual: owned parent, optional child, connected predecessor/successor records by
   ID, then scheduled source Reminders.
4. Receipt, action state and conversation history remain under the held AI locks.

Planning context retains existing Lesson, availability, preference, workload and
source-read policies; no second cross-table/gap-lock scheme is added. Cooperating
domain writers and delivery acquire owner first and never request AI locks.
Delivery alone may insert Notifications after its Reminder lock. Planning creates
no Reminder or inbox record and never marks Notifications read.

Plan/E2 confirmation decodes and regenerates the exact domain review, comparing all
material fields except root as_of_utc. It repeats complete batch, effort, attribution,
future-start and constraint gates; E2 additionally preserves exact release sets,
one-to-one successors and valid/cannot-replan classifications. A material change
causes conflict, never insertion of a newly regenerated alternative.
Manual fingerprints hash sorted raw parent/child and all connected session records,
including actual intervals, attribution, lifecycle, graph links and timestamps.
Same-second changes are detected from actual values, not updated_at alone. All final
remaining-effort, eligibility and candidate constraints still run in the Service.

A successful operation commits all sessions/source retirement/Reminder cancellation,
executed state, safe receipt and version-2 outcome event together. Exceptions, save
vetoes, receipt/history failures and expired slow writes roll back the entire
operation. A same-connection savepoint permits a known planning failure to roll back
its domain attempt while retaining the AI/owner locks for a truthful failed-attempt
event. That event commits with no domain effects, pending status and null receipt;
the safe HTTP error is thrown afterward. Unexpected failures roll back the outer
transaction. No active AI turn can interleave before a known failure is recorded.

## Receipts, history and errors

Receipts always include action_id, type and applied=true. Plan adds safe created
sessions and actual planning summary. E2 adds original affected IDs/terminal states,
safe replacements and actual summary/untransferred seconds. Manual adds safe
original, replacement and actual transfer summary. Session fields follow the exact
StudySessionResource allowlist; raw models/graph/provider/token metadata are excluded.
Receipts remain immutable after later domain changes.

This guarantees at-most-once committed business execution per action with durable
result replay, not exactly-once HTTP delivery. Reconfirmation of executed state
returns the stored receipt without domain/provider calls or another successor.
Direct REST confirmation retains its existing conservative conflict replay policy.
Separate independently confirmed proposals remain separate operations.

Successful/rejected/expired outcomes reuse version-2 action_event metadata.
Known stale/conflict/token/consent failures record status=failed and a fixed error
code as an attempt event; AiPendingAction still has only pending/executed/rejected/
expired states. The bounded latest-ten projection includes applied/count/identity
summaries and only status/error_code from the last planning attempt, never a token
or full receipt. It survives transcript trimming and lets future turns distinguish
pending from failed attempts and authoritative terminal outcomes. Proposal-bearing
turns discard provider success claims and use server-controlled no-change wording.

| Case | HTTP / result |
| --- | --- |
| Unauthenticated / foreign or missing action route | 401 / indistinguishable 404 |
| Invalid arguments or consent | 422 Laravel validation; no mutation |
| Missing required partial/unplaced acceptance | 422 errors for the distinct consent field |
| MySQL action expiry, including after slow work | 410 / expired state or action_expired rollback error |
| Invalid/expired private token while action clock remains live | 422 action_token_invalid |
| Material plan/source change | 409 action_stale |
| Authoritative manual feasibility conflict | 409 action_conflict |
| Active H2 turn / reject executed action | 409 conversation_busy / action_executed |
| No actionable review | ok=true, no_actionable_plan, no pending action |
| Oversized complete review | safe review_too_large Tool error; no pending action |
| Proposal lookup/validation/source failure | not_found / invalid_arguments / execution_failed Tool error |
| Provider or persistence failure | existing safe H2/H3 categories; no partial mutation/receipt |

## Security, limitations and verification

Authentication supplies the owner. Owned work/source reloads, Policies, canonical
stored arguments, protected tokens, distinct client consent, domain checks and
atomic receipts enforce authority independently of prompt compliance. User text,
titles, warnings, past messages and tool output are untrusted data. They cannot
install tools, select owners, inject consent, bypass confirmation or forge execution.
No token plaintext/decrypted review, SQL, traces or foreign data is echoed in errors.

Existing greedy allocation limitations, 31-local-date scopes, future-start checks,
large-review limits, bounded history and cooperating-writer assumptions remain.
Arbitrary SQL writers and administrative purge are outside the concurrency contract.
The final pre-commit clock check cannot freeze subsequent wall-clock advancement.
Deterministic action wording is English. Automated provider tests use fakes;
paid live-model clarification/selection evaluation remains separate. Deployment must
apply the additive migration. No deletion, arbitrary bulk session writer, availability/
preference AI editing, automatic recovery, delivery, inbox mutation, frontend,
new provider, streaming, voice, embeddings or event-sourcing infrastructure is added.

All database tests use real guarded student_planner_testing MySQL. Independent
workers use separate PHP processes/PDO connections and inspect actual
performance_schema row-lock waits. See the verification report below for final
counts and commands.

## Exact provider-facing definitions

```json
[
    {
        "type": "function",
        "name": "propose_study_plan_create",
        "description": "Propose a server-calculated study plan over 1–31 inclusive local dates in the persisted timezone. Optional owned task/subtask scope; a subtask requires its parent. Never invent IDs or intervals. Partial plans require explicit client acceptance. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "start_date": {
                    "type": "string",
                    "format": "date",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"
                },
                "end_date": {
                    "type": "string",
                    "format": "date",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"
                },
                "task_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "subtask_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                }
            },
            "required": [
                "start_date",
                "end_date"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_adaptive_replanning",
        "description": "Propose deterministic adaptive replanning over 1–31 inclusive local dates. Preserve valid/cannot-replan sessions; review all affected sources, shorter replacements and unplaced cancellations. Separate partial and cancellation consent comes only from the client confirmation request. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "start_date": {
                    "type": "string",
                    "format": "date",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"
                },
                "end_date": {
                    "type": "string",
                    "format": "date",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"
                },
                "task_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "subtask_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                }
            },
            "required": [
                "start_date",
                "end_date"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_session_reschedule",
        "description": "Propose moving one identified future planned session to an explicit UTC interval. Only preliminary lifecycle/duration checks are available; feasibility is validated by the server at confirmation. Never invent a clock time. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "study_session_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "starts_at": {
                    "type": "string",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$",
                    "description": "Real whole-second UTC instant in years 1000–9999. Clarify missing or ambiguous local clock times."
                },
                "ends_at": {
                    "type": "string",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$",
                    "description": "Real whole-second UTC instant in years 1000–9999. Clarify missing or ambiguous local clock times."
                }
            },
            "required": [
                "study_session_id",
                "starts_at",
                "ends_at"
            ],
            "additionalProperties": false
        },
        "strict": false
    }
]
```

## Verification report

Starting and ending HEAD: `4823f2381f017ee0a5c2e597da9223e5511d1aa2`.
The initial tree was clean. Final changes are uncommitted: 30 modified tracked files
and 13 new files (43 total), enumerated below. No commit or push was made.

All suites below passed with zero failures, errors or skipped cases. Database
suites use the guarded MySQL database, not SQLite. Provider behavior is scripted;
no paid upstream request is needed or made.

| Verification | Tests | Assertions |
| --- | ---: | ---: |
| Pure H3c `PlanningActionTest` | 104 | 255 |
| MySQL `AiPlanningActionsTest` | 101 | 1137 |
| MySQL `AiPlanningActionMigrationTest` | 1 | 17 |
| Independent PHP/MySQL `AiPlanningActionConcurrencyTest` | 41 | 602 |
| Final combined H3c run | 247 | 2011 |
| Broader previous AI/planning/Reminder/security/migration regression checkpoint | 3090 | 21621 |
| Complete PHPUnit suite, final implementation | 5340 | 44764 |

The 41 process cases cover duplicate confirmations for all three operations,
confirmation/rejection, AI/direct REST races in both orders, current-state discovery
after owner contention, availability/preferences/Task/Subtask deadline changes,
Reminder edits and delivery in both orders, owner/domain wait expiration, slow
multi-session action/token expiration, receipt rollback with a waiting confirmer,
lost committed responses, expired H2 workers, active-turn exclusion and independent
owners progressing while another confirmation transaction holds its locks.
Tests query MySQL performance_schema for actual row-lock waits and assert separate
connection IDs; gates run after real inserts/updates or committed responses.

Commands:

```sh
vendor/bin/phpunit tests/Unit/PlanningActionTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiPlanningActionMigrationTest.php tests/Feature/AiPlanningActionConcurrencyTest.php --log-junit /tmp/h3c-focused-final.xml
vendor/bin/phpunit --filter='PlanningAction|AiPlanning|StudyPlan|PlanningPersistence|StudyReplanning|StudySession|PlanningCandidate|PlanningConstraint|Workload|AiTask|TaskAction|SubtaskReminderAction|AiSubtaskReminder|AgentOrchestrator|OpenAiResponsesProvider|AiChat|AiToolFoundation|AiToolsIntegration|AiPersistence|Reminder|AuthProfile|Security|PlanningMigration' --log-junit /tmp/h3c-regression.xml
vendor/bin/phpunit --log-junit /tmp/h3c-full.xml --log-events-text /tmp/h3c-full-events.log
vendor/bin/pint --dirty
vendor/bin/pint --test
git diff --check
```

The broad checkpoint preceded the last source-corruption/independent-owner cases;
the final focused run and complete suite verify the final implementation. Exact
provider schemas in this document were compared programmatically to the adapter's
output, and H3c/ADR relative documentation links were checked.

Pint `--dirty`, Pint `--test` and `git diff --check` all passed after final
verification. The full suite completed successfully in approximately 410 seconds.
H1/H2/H3a/H3b, Stage C/D/E2, planning constraints/workload, Task/Subtask lifecycle,
Reminder delivery/synchronization, authentication/authorization and migration
regressions all remain green; no existing test was removed, skipped or weakened.
Existing constructor expectations and migration rollback steps were updated for
the additional registered tools, private column and additive migration.

**Stage H3c acceptance: complete.** All three proposal tools, protected review
storage, explicit consent, shared atomic confirmation, receipts, stale-state and
owner isolation, process concurrency and required regression/format checks are
implemented and verified. No remaining implementation or verification blocker is
known. Limits and live-provider evaluation boundaries are documented above.

## Changed-file inventory

All changes remain uncommitted; no commit or push was made. Paths below are
relative to the repository root. Existing applied migrations are unchanged.

- `app/Exceptions/AiAgentException.php`
- `app/Http/Controllers/Api/AiTaskActionController.php`
- `app/Http/Requests/AiTaskActionRequest.php`
- `app/Http/Resources/AiMessageResource.php`
- `app/Models/AiPendingAction.php`
- `app/Services/AI/Actions/PlanningActionArgumentValidator.php` (new)
- `app/Services/AI/Actions/PlanningActionConsent.php` (new)
- `app/Services/AI/Actions/PlanningActionRegistry.php` (new)
- `app/Services/AI/Actions/PlanningActionService.php` (new)
- `app/Services/AI/Actions/TaskActionConfirmationService.php`
- `app/Services/AI/AgentOrchestrator.php`
- `app/Services/AI/AiConversationService.php`
- `app/Services/Planning/StudyPlanPersistenceService.php`
- `app/Services/Planning/StudyReplanningPersistenceService.php`
- `app/Services/Planning/StudySessionMutationContextService.php`
- `app/Services/Planning/StudySessionReschedulingService.php`
- `database/migrations/2026_10_19_000002_add_ai_planning_actions.php` (new)
- `docs/adaptive-replanning.md`
- `docs/ai-agent-chat.md`
- `docs/ai-study-planning-actions.md` (new)
- `docs/ai-subtask-reminder-actions.md`
- `docs/ai-task-actions.md`
- `docs/architecture.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- `docs/database-schema.dbml`
- `docs/database-schema.md`
- `docs/decisions/ADR-004-ai-planning-confirmation-transaction.md` (new)
- `docs/domain-model.md`
- `docs/study-plan-persistence.md`
- `docs/study-session-lifecycle.md`
- `tests/Concerns/InteractsWithPlanningActions.php` (new)
- `tests/Feature/AiChatPersistenceTest.php`
- `tests/Feature/AiPlanningActionConcurrencyTest.php` (new)
- `tests/Feature/AiPlanningActionMigrationTest.php` (new)
- `tests/Feature/AiPlanningActionsTest.php` (new)
- `tests/Feature/AiSubtaskReminderActionsTest.php`
- `tests/Feature/AiTaskActionPersistenceTest.php`
- `tests/Support/ai_planning_action_worker.php` (new)
- `tests/Unit/AgentOrchestratorTest.php`
- `tests/Unit/PlanningActionTest.php` (new)
- `tests/Unit/SubtaskReminderActionTest.php`
- `tests/Unit/TaskActionTest.php`
