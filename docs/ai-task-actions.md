# Stage H3a — confirmed conversational Task actions

H3c adds three planning proposals through this same pending-action lifecycle.
The four Task definitions and empty-body Task confirmation contract are unchanged.
One attempted proposal per turn now spans fourteen mutation definitions; private
planning tokens and distinct client consent apply only to H3c planning actions.
Historical H3a results below remain unchanged. See [H3c](ai-study-planning-actions.md).

H3b extends this H3a baseline through the same table, status policy, expiration,
confirmation/rejection endpoints, durable receipts and history. Seven Subtask/
Reminder proposals share the global one-mutation-per-turn limit with the unchanged
four Task schemas. Two separate discovery reads leave H1 untouched. Error codes
remain compatible; action-level messages use domain-neutral wording. The later
CHECK expansion and Reminder transaction core are documented in
[H3b](ai-subtask-reminder-actions.md). Historical H3a verification below is retained.

H3a adds one two-phase capability: the agent proposes one Task mutation, and the
student explicitly confirms or rejects the persisted proposal. All four mutations
require confirmation. The server owns this policy; neither a model argument nor a
conversational “yes” authorizes execution. H1's seven definitions, Executor,
validation and read-only Services are unchanged. H2 read-only turns retain their
existing public shape, idempotency, provider continuation and finite lease.

The audited starting repository HEAD was `0d669260ba6b871f258add190b640e7a2e3a1e50`.
The working tree already contained the H3a draft (20 modified tracked files and
19 untracked files); those changes were reviewed and completed in place. No
commit or push is part of this stage.

## Application components and execution boundary

```text
Sanctum-authenticated student -> AiConversationService claim (short transaction)
  -> AgentOrchestrator -> LlmProvider (no open transaction)
     -> H1 ToolExecutor for the original seven read tools
     -> TaskActionProposalService for four server-owned proposal definitions
        -> strict argument validation + read-only TaskService candidate checks for edits
  -> AiConversationService finish transaction
     -> transcript + pending action + committed turn result

Explicit POST confirm -> conversation/action locks + active-turn exclusion
  -> owner/Task locks + fresh validation + target fingerprint comparison
  -> existing TaskService create/update/complete/reopen
  -> receipt + executed state + deterministic history event, in the same transaction
```

`TaskActionRegistry` is a fixed allowlist; no model-selected PHP class, method,
Service name or confirmation mode is accepted. `TaskActionArgumentValidator`
shares `TaskInputRules` with `SaveTaskRequest`. `TaskService::prepare()` is the
read-only extraction of the same complete-candidate Subject and child-deadline
checks used by the create/update writers. Lifecycle proposals check target status
and preserve unrelated legacy fields, following complete/reopen semantics rather
than applying edit-time validation. It does not perform a speculative write
or run a writer followed by rollback. Proposal dispatch never creates an action
row; only successful H2 finalization does that.

The orchestrator discovers eleven tools, but H1 calls still execute exclusively
through H1's Executor. At most one proposal-tool invocation is permitted per turn,
including invalid attempts. A batch requesting two proposals aborts before either
is prepared. A second proposal in a later round aborts the whole uncommitted turn.
Earlier in-memory preparation/read calls have no business effects. Multiple read
calls can precede or accompany the single proposal.

## Four action contracts

| Tool | Required | Optional/editable | Confirmation writer |
| --- | --- | --- | --- |
| `propose_task_create` | `title` | description, subject_id, priority, estimated_minutes, deadline_at | `TaskService::create()` |
| `propose_task_update` | positive integer task_id and at least one editable field | title, description, subject_id, priority, estimated_minutes, deadline_at | `TaskService::update()` |
| `propose_task_complete` | positive integer task_id | none; target must be pending | `TaskService::complete()` |
| `propose_task_reopen` | positive integer task_id | none; target must be completed | `TaskService::reopen()` |

The update vocabulary is exactly title, description, subject_id, priority,
estimated_minutes and deadline_at. Update cannot set status, completion metadata,
owner, timestamps, soft deletion or arbitrary attributes. Lifecycle actions use
separate definitions. Completion/reopening are conservative proposals even though
the underlying direct lifecycle operations are idempotent.

Create uses the existing Task defaults: pending status, normal priority (2), null
completion metadata and null nullable fields. All proposal fields are genuine JSON
scalars. Integers reject strings, floats and booleans. Priority is the existing
integer enum (1/2/3); estimated_minutes is null or 1–65535. Title is meaningful
UTF-8 with at most 255 characters; description is UTF-8 with at most 65535 bytes.
Nullable description, subject_id, estimated_minutes and deadline_at accept explicit
null; omission preserves fields on update. Title/priority cannot be null.
Deadlines must round-trip the exact `Y-m-dTH:i:sZ` UTC format, years 1000–9999;
offsets, fractional seconds, date-only strings and invalid calendar dates fail.
No rule invents a deadline, a local clock time or a Subject ID. System instructions
require clarification for ambiguous dates and unavailable authorized references.
H1 does not add Subject discovery in this stage; omit an optional Subject or ask
for clarification when existing authorized context cannot establish it.

Unknown keys, owner fields, nested values, empty updates and unsupported statuses
fail before candidate preparation. Proposal preparation uses only owned current
Tasks and Subjects. Confirmation repeats validation from stored arguments against
fresh owned data. The schema below describes the provider wire contract; PHP is
authoritative even when a provider does not follow it.

## Exact provider-facing schemas

H1 retains its existing `strict: true` optional-null adaptation. H3a explicitly
uses `strict: false` so optional omission and explicit clearing-null remain
separate values. H3a schemas are passed through without inserting or stripping
nulls. Server validation is strict for both paths. This avoids treating the null
placeholders required by OpenAI strict mode as permission to clear Task fields.

The generated definitions below are the actual `OpenAiToolSchema` output for the
four H3a tools (on 64-bit PHP, PHP_INT_MAX = 9223372036854775807).

```json
[
    {
        "type": "function",
        "name": "propose_task_create",
        "description": "Propose creating one task. Required explicit confirmation; no task is created. Never invent Subject IDs or ambiguous deadlines.",
        "parameters": {
            "type": "object",
            "properties": {
                "title": {
                    "type": "string",
                    "minLength": 1,
                    "maxLength": 255
                },
                "description": {
                    "type": [
                        "string",
                        "null"
                    ],
                    "description": "At most 65535 UTF-8 bytes. Null clears; omission preserves on update."
                },
                "subject_id": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "priority": {
                    "type": "integer",
                    "enum": [
                        1,
                        2,
                        3
                    ]
                },
                "estimated_minutes": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 65535
                },
                "deadline_at": {
                    "type": [
                        "string",
                        "null"
                    ],
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$",
                    "description": "Exact UTC instant, years 1000–9999. Clarify ambiguous dates or missing local clock time. Null clears."
                }
            },
            "required": [
                "title"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_task_update",
        "description": "Propose editing one task. Include at least one editable field. Omit unchanged fields; null explicitly clears nullable fields. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "task_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "title": {
                    "type": "string",
                    "minLength": 1,
                    "maxLength": 255
                },
                "description": {
                    "type": [
                        "string",
                        "null"
                    ],
                    "description": "At most 65535 UTF-8 bytes. Null clears; omission preserves on update."
                },
                "subject_id": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "priority": {
                    "type": "integer",
                    "enum": [
                        1,
                        2,
                        3
                    ]
                },
                "estimated_minutes": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 65535
                },
                "deadline_at": {
                    "type": [
                        "string",
                        "null"
                    ],
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$",
                    "description": "Exact UTC instant, years 1000–9999. Clarify ambiguous dates or missing local clock time. Null clears."
                }
            },
            "required": [
                "task_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_task_complete",
        "description": "Propose completing one pending task, cancelling dependent scheduled reminders. Subtasks and study sessions retain their statuses. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "task_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                }
            },
            "required": [
                "task_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_task_reopen",
        "description": "Propose reopening one completed task. Does not recreate reminders or retired sessions. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "task_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                }
            },
            "required": [
                "task_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    }
]
```

## Preview, action identity and turn commit

A successful internal proposal-tool result has the existing success envelope:

```json
{
  "ok": true,
  "tool": "propose_task_update",
  "data": {
    "state": "confirmation_required",
    "action_id": "server-generated-uuid",
    "call_id": "provider_call_id",
    "preview": {
      "action": "propose_task_update",
      "confirmation_required": true,
      "before": {"id": 42, "title": "Original", "status": "pending"},
      "changes": {"description": null},
      "effects": ["Update the supplied fields. Deadline changes reconcile relative reminders using current lifecycle rules."]
    }
  }
}
```

The `before` object above is abbreviated: actual existing-target previews include
id, all six editable fields, status and completed_at with canonical UTC instants.
Malformed legacy foreign Subject references are masked in `before` and receipts,
including when an explicit nullable update repairs that association through
TaskService. Completion/reopening preserve legacy Subject links and child-deadline
inconsistencies, matching the existing lifecycle API; they do not silently repair
those fields or disclose foreign identifiers.
Create has `before: null` and complete proposed defaulted values in `changes`.
Complete previews a completed status; the future actual completion instant is not
invented. Reopen previews pending status and null completion time. Effects are
fixed server wording, with no promised count of future Reminder changes.

Proposal-bearing turns replace every public intermediate assistant text with null
and their final answer with:

> Task action proposed. Confirmation is required; no task has changed.

If preparation fails, the wording is “No task action was prepared. No task has
changed.” Provider message items are removed from proposal-turn continuation state;
function calls, correlated outputs and encrypted reasoning continuation remain.
The final event uses the deterministic application answer without opaque provider
message state. A model's false “created” or “completed” text cannot become the
public success message for these turns.

Successful H2 send results additionally include `action` with `id`, `state`,
`call_id`, `preview` and `expires_at`. Existing `tools` summaries keep their H2
shape. No action ID is returned as a successful public result before commit.
An action UUID is generated by the server, persisted once and bound to one turn
and provider call. No client-signed mutation payload is accepted: identity resolves
to protected server data instead. UUID randomness is not authorization.

Provider failure, limits, duplicate proposals, transcript failure, action insertion
failure or a lost/expired lease leave no confirmable action. Transcript inserts,
action insertion, conversation touch and durable H2 replay result commit together.
Lease currency is checked both before and after transcript/action persistence.
An expired worker cannot commit or clear its successor's claim. Replaying a
committed H2 key returns the same originating action UUID without another proposal.
The replay represents the original turn; GET the action for its current status,
especially after confirmation, rejection or expiration.

## Additive persistence

Migration `2026_10_17_000001_create_ai_pending_actions_table.php` adds one table:

| Column | Storage/purpose |
| --- | --- |
| id | UUID primary key, not auto-increment |
| conversation_id | owning conversation FK, cascade delete |
| turn_id | originating H2 turn FK, unique, cascade delete; one action per turn |
| call_id | bounded provider correlation identifier (128 characters) |
| type | allowlisted proposal name (32 characters) |
| arguments | validated canonical JSON; no owner/confirmation override |
| preview | safe reviewed JSON, immutable through the API |
| target_hash | nullable SHA-256 of sorted raw Task attributes; null for create |
| status | pending, executed, rejected or expired; default pending |
| expires_at | UTC DATETIME(6), MySQL clock at turn commit plus 15 minutes |
| receipt | nullable durable actual Task result JSON |
| resolved_at | nullable UTC DATETIME(6), MySQL resolution clock |
| created_at / updated_at | normal Eloquent timestamps |

A `(conversation_id, created_at)` index supports owned action history. CHECKs
constrain action/status vocabulary, executed/receipt pairing and resolution
metadata. A receipt cannot exist on a non-executed row. Ownership is derived
through the conversation; no redundant user_id or Task FK is added. The immutable
arguments/history survive normal Task soft deletion; deleted targets fail stale
confirmation rather than making history disappear. The application inserts both
FKs from the same locked conversation/claim and confirmation verifies that the
referenced turn belongs to that conversation and has a committed result. Two
independent FKs do not independently enforce that cross-row equality.

Rollback drops only this additive table. Round-trip tests retain old conversations,
messages and H2 turns. Account/conversation purge cascades actions; there is no new
public deletion/pruning endpoint. Administrative hard purge remains outside normal
Task/chat operations and existing restrictive historical FK rules still apply.

## Authenticated REST contract

All routes retain Sanctum SPA authentication and conversation Policy authorization.
The action is additionally scoped to that exact owned conversation before payload
validation. Foreign, missing, malformed and mismatched action/conversation IDs
return indistinguishable `404 {"message":"Not found."}`.

| Method | Endpoint suffix under `/api/ai/conversations/{conversation}` | Input |
| --- | --- | --- |
| GET | `/actions` | query page, per_page (default 20; maximum 50) |
| GET | `/actions/{action}` | no fields |
| POST | `/actions/{action}/confirm` | empty JSON object or absent body, no query fields |
| POST | `/actions/{action}/reject` | empty JSON object or absent body, no query fields |

POST lists/scalars/malformed JSON, form fields, unknown keys, query arguments,
replacement titles/arguments/Service names and confirmation-policy flags are 422.
GET bodies and unknown query fields fail consistently with the existing AI API.
List order is created_at descending then UUID descending; standard Laravel
pagination applies. Confirmation does not accept a fresh mutation payload.

Public action Resource fields are `id`, `conversation_id`, `call_id`, `type`,
`status`, `state`, `confirmation_required`, `preview`, `expires_at`, `receipt`,
`resolved_at`, `created_at`. Internal arguments, fingerprint, turn coordination,
provider state and prompts are not exposed. Pending `state` is
`confirmation_required`; terminal `state` equals status. Only live pending actions
have `confirmation_required: true`.

Decisions return `{"data": <action resource>, "replayed": false}`. Successful
confirm replay returns the same stored Resource/receipt with `replayed: true`;
it does not reload a subsequently edited Task to fabricate a new result.
The receipt is `{action_id, type, task}`, where task is the actual canonical Task
result with id, six editable fields, status and completed_at.

| Outcome | HTTP | Contract |
| --- | --- | --- |
| Pending detail/list | 200 | pending / confirmation_required, null receipt |
| First successful confirmation | 200 | executed, durable receipt |
| Already executed confirmation | 200 | same receipt, replayed true |
| Reject pending / repeat reject | 200 | rejected, null receipt, no Task writes |
| Confirm rejected | 409 | rejected Resource, null receipt |
| Expired decision | 410 | expired Resource, null receipt |
| Expiry crossed during business work | 410 | fixed action_expired error; entire transaction rolled back |
| Reject executed | 409 | action_executed; no reversal |
| Stale/missing/foreign current target | 409 | action_stale; no mutation |
| New domain validation failure | 422 | action_validation_failed; no mutation |
| Active H2 turn | 409 | conversation_busy; no mutation/history interleaving |
| Invalid HTTP arguments | 422 | Laravel message/errors |
| Unexpected persistence/dependency failure | 500 | safe persistence_error |

Fixed errors use `{message, code}`. Tool preparation errors retain the H1-style
`ok: false, tool, error: {code, message}` shape: invalid_arguments, not_found,
access_denied or execution_failed. A duplicate mutation turn is 422 multiple_actions.
Underlying SQL, traces, upstream bodies and foreign titles are never public.
Stale/domain failures roll back and leave the stored action pending without a
receipt; request a fresh proposal. Server checks prevent an invalid action from
executing even if a client keeps showing its old preview.

## Atomicity, idempotency and stale-state checks

Confirmation owns a fresh outermost MySQL transaction; it rejects an enclosing
transaction rather than returning before durable commit. It locks and reloads
the owned conversation, action, owner and current target. It validates the stored
allowlisted arguments, verifies the committed originating turn, compares the raw
Task fingerprint and delegates authoritative validation to the matching TaskService
writer. Create/update repeat complete-candidate checks; lifecycle writers retain
their existing field-preservation semantics. The fingerprint includes actual
fields/status/deletion/completion/timestamps, detecting material same-second edits
instead of relying on second-resolution updated_at alone. No cached Task model or
model-written target description establishes current state. A new child/deadline
constraint is separately revalidated even when Task attributes did not change.

The existing TaskService writer runs as a nested transaction/savepoint on the same
connection. Its business writes, Reminder synchronization, action executed state,
receipt and deterministic history event all commit in the outer transaction.
Any exception, cancelled required save or history failure rolls all of them back.
No provider call, queue dispatch or unrelated background work occurs here.

A second confirmer waits for the conversation/action lock, sees executed and
returns the receipt. It never calls TaskService again. A crash before commit has
no committed business effect; a lost response after commit is recovered from the
receipt by any application process. This is durable at-most-once business execution
per action with idempotent result replay, not a promise of exactly-once HTTP
response delivery. Separate independently confirmed proposals remain separate
actions; there is no deduplication of similar natural-language requests.

## Transaction order, H2 coordination and expiration

Supported writers acquire locks in this order:

1. Owned AiConversation, then its AiPendingAction for a decision.
2. User owner row, then current owned Task.
3. Existing TaskService Subject/child constraint locks, then Subtasks,
   StudySessions and Reminders in the established dependency order.
4. Receipt/history writes under the already-held conversation/action locks.

Pending confirmations acquire the owner lock before any consistent SELECT of turn
metadata or domain data. Under MySQL REPEATABLE READ, this prevents a snapshot
established before an owner wait from hiding newly committed session/Reminder
dependencies. Conversation/action acquisition uses locking reads and does not
establish that snapshot. A dedicated independent-process regression inserts a
session reminder during the owner wait and verifies completion cancels it.

H2 claim/finish locks the conversation without acquiring domain writer locks.
Proposals read a snapshot without writer locks and no transaction crosses provider
I/O. Direct Task/Reminder/session writers acquire owner first and never request
conversation/action locks. Thus adding the conversation prefix does not invert
supported domain lock order. H2 claim/finish and decisions serialize on the same
conversation; a live lease causes new decisions to return conversation_busy.
A committed receipt replay has no new history/business write and may be returned
while another turn is active. Terminal read/reject replay similarly adds no event.
Different conversations have separate coordination locks; domain mutations for
the same owner still intentionally serialize on that owner's existing row.
Different owners do not share those locks. Rejection writes no domain data and
does not acquire the owner lock. No global application mutex is added.

Expiry is derived from MySQL UTC_TIMESTAMP(6), independent of PHP test clocks,
worker timezone or process-local timers. It is rechecked after owner contention
and after business work before receipt persistence. Crossing expiry during a slow
writer rolls back the whole mutation. Reads show effective expired status even
if the physical row is still pending. A later decision persists expired status
and its history event. Expiry/rejection need no scheduler and never mutate Tasks;
there is no automatic reversal. Terminal rows/receipts remain until conversation
or account deletion. After an expiry rollback, the next expired decision can
persist the terminal event without rerunning business work.

## Conversation history and domain side effects

Original proposal calls remain correlated through call_id and the public action ID.
Executed, rejected and persisted-expired outcomes append a deterministic assistant
message without a provider request. `tool_calls` version 2 holds an `action_event`
with action_id, status and receipt; public message history exposes that safe event.
Version 1 H2 provider call/continuation metadata is unchanged. Outcome events are
chronologically ordered by message ID and touch conversation updated_at. Replays
do not append duplicate outcome events.

The provider history receives safe server-recorded outcome data as an ordinary
assistant event, never user text promoted to system instructions. Additionally,
one compact event projects the latest ten action identities/types/effective
statuses/result Task IDs from MySQL. This bounded projection survives transcript
trimming and informs the next turn of lazy expiry without pretending a pending
proposal executed. It consumes the existing byte budget; no full unbounded action
history or provider call is fabricated. H2 conversations without actions retain
their previous history shape.

TaskService is the sole business writer. Create inserts one pending Task. Update
preserves omitted fields and revalidates owned Subject/child deadline rules.
Deadline changes reconcile existing relative reminders; absolute reminders keep
their established rules. Completion records actual UTC completion and atomically
cancels dependent scheduled Task/Subtask/session reminders after dependency
preflight. It does not complete children or change StudySession statuses.
Reopening resets Task status/completion metadata without recreating cancelled
reminders or retired sessions. Both lifecycle operations preserve unrelated legacy
Subject/deadline fields as the direct API does; foreign Subject references remain
masked in previews and receipts. No AI Subtask/StudySession/Reminder writer is added.

## Security boundaries and future work

Authentication supplies the trusted owner; model/client data cannot select another
owner or install a tool. Action IDs must match their owned conversation. Stored
Task/lesson titles, descriptions, historical text and tool results remain untrusted
data; embedded instructions cannot change the allowlist or confirmation path.
Preview/receipt text must be rendered safely by the future client, not as trusted
HTML. Strict authorization, stored arguments, lifecycle checks and atomic receipts
are the execution boundary even when a model ignores prompt instructions.

This stage excludes Task deletion, bulk/multi-action workflows, AI Subtask mutation,
schedule imports, study-plan confirmation, session rescheduling, Reminder or inbox
mutation, new providers, frontend controls and long-running mutation jobs. Future
H3b/H3c capabilities require their own domain/confirmation review; no generalized
workflow engine or automatic low-risk execution policy is introduced here.
Existing H2 request/context/tool-result byte budgets still apply and can reject
a large otherwise domain-valid description/preview. Deterministic proposal and
outcome wording is English in V1; localization is not introduced here.
No paid live provider inference is part of automated verification. Deterministic
mocked-provider tests validate the native adapter and orchestration contracts;
real-model clarification/tool-selection evaluation remains separate work.

## Verification

Final verification completed on 2026-10-19. Tests use the existing guarded
`student_planner_testing` MySQL database, never SQLite. Independent workers have
separate PHP processes and MySQL
connections and assert performance_schema row-lock waits. They cover duplicate
confirmation, both confirm/reject orderings, expiry after owner wait and during a
slow mutation, both direct update/lifecycle orderings, expired H2 proposal workers,
TaskService rollback with a waiter, lost response after commit, active-turn
exclusion and independent conversation/owner progress.

| H3a class | Tests | Assertions |
| --- | ---: | ---: |
| `TaskActionTest` (pure Unit) | 82 | 125 |
| `AiTaskActionsTest` (MySQL Feature) | 39 | 501 |
| `AiTaskActionPersistenceTest` (MySQL schema/rollback) | 2 | 46 |
| `AiTaskActionConcurrencyTest` (independent processes) | 15 | 224 |
| Total H3a | 138 | 896 |

| Verification | Exit | Tests | Assertions | Result |
| --- | ---: | ---: | ---: | --- |
| H3a focused | 0 | 138 | 896 | Passed |
| H1/H2 compatibility | 0 | 484 | 3264 | Passed |
| Task/Reminder/planning/auth/security regressions | 0 | 3228 | 23313 | Passed |
| Entire PHPUnit suite | 0 | 4867 | 40951 | Passed |
| `vendor/bin/pint --dirty` | 0 | — | — | Passed |
| `vendor/bin/pint --test` | 0 | — | — | Passed |
| `git diff --check` | 0 | — | — | Passed |

All successful PHPUnit runs had zero errors, failures or skips. The initial
sandboxed focused attempt could not connect to local MySQL; the approved rerun
passed. No test was deleted or weakened. Lifecycle review found and corrected
edit-time candidate validation incorrectly applied to complete/reopen; two new
Feature cases verify the direct lifecycle's legacy-field preservation and safe
Subject masking. The documented four provider schemas were also compared with
the generated `OpenAiToolSchema` definitions and matched exactly.

```sh
vendor/bin/phpunit --filter='TaskAction|AiTaskActions' --log-junit /tmp/h3a-focused-final.xml
vendor/bin/phpunit --filter='AgentOrchestratorTest|OpenAiResponsesProviderTest|AiChat|AiToolFoundationTest|AiToolsIntegrationTest|AiPersistenceTest' --log-junit /tmp/h3a-h1-h2-review.xml
vendor/bin/phpunit --filter='Task|Subtask|Reminder|NotificationInbox|StudySession|StudyReplanning|StudyPlan|AuthProfile|SecurityTest|RemainingEffortServiceTest|TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|PlanningWorkSelectionServiceTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest' --log-junit /tmp/h3a-domain-final.xml
vendor/bin/phpunit --log-junit /tmp/h3a-full-final.xml
vendor/bin/pint --dirty
vendor/bin/pint --test
git diff --check
```

Stage H3a acceptance: complete. Starting and ending HEAD are
`0d669260ba6b871f258add190b640e7a2e3a1e50`. The final working tree contains 20
modified tracked files and 19 new files (39 files total), left uncommitted. The
starting tree already contained those H3a files; this completion reviewed them,
fixed lifecycle compatibility and completed verification/documentation. No commit
or push was performed. The additive migration was exercised on the test database;
deployment must apply it through Laravel migrations. Live-model evaluation,
localization and the deferred H3b/H3c domains remain the boundaries above.

## Changed-file review

The reviewed H3a file set (including the pre-existing draft) is:

- `app/Exceptions/AiAgentException.php`
- `app/Http/Controllers/Api/AiTaskActionController.php`
- `app/Http/Requests/AiTaskActionRequest.php`
- `app/Http/Requests/SaveTaskRequest.php`
- `app/Http/Resources/AiMessageResource.php`
- `app/Http/Resources/AiPendingActionResource.php`
- `app/Models/AiPendingAction.php`
- `app/Services/AI/Actions/TaskActionArgumentValidator.php`
- `app/Services/AI/Actions/TaskActionConfirmationService.php`
- `app/Services/AI/Actions/TaskActionPresenter.php`
- `app/Services/AI/Actions/TaskActionProposalService.php`
- `app/Services/AI/Actions/TaskActionRegistry.php`
- `app/Services/AI/Actions/TaskActionState.php`
- `app/Services/AI/AgentOrchestrator.php`
- `app/Services/AI/AiConversationService.php`
- `app/Services/AI/OpenAiToolSchema.php`
- `app/Services/Tasks/TaskInputRules.php`
- `app/Services/Tasks/TaskService.php`
- `database/migrations/2026_10_17_000001_create_ai_pending_actions_table.php`
- `docs/ai-agent-chat.md`
- `docs/ai-task-actions.md`
- `docs/ai-tools-foundation.md`
- `docs/architecture.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- `docs/database-schema.dbml`
- `docs/database-schema.md`
- `docs/decisions/ADR-002-ai-task-confirmation.md`
- `docs/domain-model.md`
- `docs/tasks.md`
- `routes/api.php`
- `tests/Concerns/AssertsMysqlPersistence.php`
- `tests/Feature/AiChatPersistenceTest.php`
- `tests/Feature/AiTaskActionConcurrencyTest.php`
- `tests/Feature/AiTaskActionPersistenceTest.php`
- `tests/Feature/AiTaskActionsTest.php`
- `tests/Support/ai_task_action_worker.php`
- `tests/Unit/AgentOrchestratorTest.php`
- `tests/Unit/TaskActionTest.php`
