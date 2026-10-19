# Stage H3b — confirmed Subtask and Reminder actions

H3b extends H3a's existing two-phase confirmation path. The model may propose one
mutation per turn across Task, Subtask and Reminder domains. Only an authenticated
explicit action-confirmation request executes a stored proposal. A conversational
yes, injected database text or model-selected confirmation flag cannot authorize
execution. H1 remains seven unchanged read-only definitions; H3a remains four
unchanged Task definitions. The Agent now discovers twenty tools: nine reads and
eleven proposals. No frontend or additional provider is implemented.

The existing [H1 definitions](ai-tools-foundation.md), [H2 chat contract](ai-agent-chat.md)
and [H3a Task schemas](ai-task-actions.md) remain the compatibility references.

Final provider discovery order:

| Tool | Boundary |
| --- | --- |
| `get_schedule_for_date` | H1 read-only |
| `list_tasks` | H1 read-only |
| `find_free_study_slots` | H1 read-only |
| `list_study_sessions` | H1 read-only |
| `get_progress_statistics` | H1 read-only |
| `get_daily_progress_trends` | H1 read-only |
| `compare_progress_periods` | H1 read-only |
| `propose_task_create` | H3a Task proposal |
| `propose_task_update` | H3a Task proposal |
| `propose_task_complete` | H3a Task proposal |
| `propose_task_reopen` | H3a Task proposal |
| `list_subtasks_for_task` | H3b read-only |
| `list_reminders` | H3b read-only |
| `propose_subtask_create` | H3b confirmed proposal |
| `propose_subtask_update` | H3b confirmed proposal |
| `propose_subtask_complete` | H3b confirmed proposal |
| `propose_subtask_reopen` | H3b confirmed proposal |
| `propose_reminder_create` | H3b confirmed proposal |
| `propose_reminder_update` | H3b confirmed proposal |
| `propose_reminder_cancel` | H3b confirmed proposal |

The starting HEAD was `11fd88efef7c6471ce2ce0d612de540a584985be`. This completion
started with the H3b implementation already present as 22 modified tracked files
and 16 untracked files. Those changes were preserved and audited, with additional
domain-lock expiration regressions added during review. No commit or push is part
of this stage.

## Components and ownership boundaries

```text
Sanctum User -> AiConversationService claim (short conversation transaction)
  -> AgentOrchestrator -> provider (outside transactions)
     -> unchanged H1 ToolExecutor for its seven reads
     -> ManagementReadTools for the two H3b reads
     -> unchanged TaskActionProposalService for the four Task proposals
     -> SubtaskReminderActionService for seven H3b proposals
        -> strict scalar/schema checks + shared read-only domain preparation
  -> successful H2 finish transaction: transcript + pending action + replay result

Explicit confirm -> existing TaskActionConfirmationService
  -> conversation/action/owner locks + committed-turn/lease/expiry checks
  -> fixed Task, Subtask or Reminder dispatch and fresh target fingerprint check
  -> existing TaskService / SubtaskService / shared ReminderMutationService core
  -> domain effects + executed receipt + deterministic history in one transaction
```

The legacy confirmation class/controller/route names remain for compatibility;
they coordinate all eleven actions. There is one status policy, one table, one
receipt/rejection/expiration protocol and one set of action endpoints. No generic
workflow engine, runtime registration or model-selected class/method dispatch exists.
`SubtaskReminderActionRegistry` is the complete H3b allowlist. Action arguments
cannot select owners, confirmation policies, SQL, Service names or arbitrary fields.

## Action contracts and shared validation

| Proposal | Required | Optional/editable | Authoritative writer |
| --- | --- | --- | --- |
| propose_subtask_create | task_id, title | description, estimated_minutes, deadline_at | SubtaskService::create |
| propose_subtask_update | task_id, subtask_id, at least one editable field | title, description, estimated_minutes, deadline_at | SubtaskService::update |
| propose_subtask_complete | task_id, subtask_id | none; child must be pending | SubtaskService::complete |
| propose_subtask_reopen | task_id, subtask_id | none; child must be completed | SubtaskService::reopen |
| propose_reminder_create | absolute trigger_at, or relative anchor + offset_minutes + one target | message; task_id, subtask_id, study_session_id subject to target/mode rules | ReminderMutationService::create |
| propose_reminder_update | reminder_id, at least one editable field | absolute: message/trigger_at; relative: message/offset_minutes | ReminderMutationService::update |
| propose_reminder_cancel | reminder_id | none; scheduled only | ReminderMutationService::cancel |

IDs are genuine positive JSON integers up to PHP_INT_MAX. Numeric strings, floats,
booleans, nested values and unknown keys fail. Subtask title is meaningful UTF-8,
1–255 characters. Description is nullable UTF-8, at most 65535 bytes. Estimated
minutes is null or an integer from 1 to 65535. Deadlines are null or exact real UTC
`YYYY-MM-DDTHH:MM:SSZ` strings in years 1000–9999. No offsets, fractional seconds,
date-only input, missing clock-time invention or invalid calendar dates are accepted.

`SubtaskInputRules` reuses Task's text/effort/deadline wire rules, restricted to four
Subtask fields, and is used by SaveSubtaskRequest and AI validation. SubtaskService's
read-only prepare extraction shares complete-candidate parent-deadline validation
with create/update. Lifecycle operations preserve unrelated legacy fields, parent
status and session history, as the direct API does. Active parent means nondeleted;
this stage adds no pending-parent restriction to manual Subtask operations.

Subtask updates cannot change parent identity, position, status, completion/deletion
metadata or ownership. Identity arguments locate the owned parent/child pair; they
are never editable payload fields. Omitted fields are preserved. Explicit null
clears description, estimate or deadline. Create appends a pending child, without
reserving or guessing a position in the preview. Only SubtaskService allocates the
actual next position and enforces its 65535 capacity at confirmation.

Completion affects only the selected child and cancels appropriate scheduled child
and child-session reminders through existing synchronization. It does not complete
the parent or sessions. Reopening clears completion metadata and never resurrects
cancelled reminders or retired sessions. Deadline edits retain existing relative
Reminder reconciliation and absolute Reminder rules.

Reminder validation reuses ReminderRules::errors and the existing target/rule core.
Message is null or a UTF-8 string of at most 500 characters. General absolute
reminders require a meaningful message. Targeted absolute reminders accept at most
one non-null owned Task/Subtask/StudySession ID. Relative creation requires exactly
one non-null target, its matching non-null anchor and a signed integer offset from
-32768 through 32767; trigger_at must be omitted. Anchors are task_deadline,
subtask_deadline and session_start. Nullable target fields may explicitly be null;
anchor, offset and trigger cannot be null when supplied. Omission versus clearing
null is preserved, including message-only updates.

Only scheduled reminders may be updated or cancelled. Update cannot convert mode,
retarget, modify anchor/status/sent timestamps or alter delivery history. Relative
offset changes resolve against the current persisted anchor. Message-only changes
preserve the stored trigger, following direct REST semantics. Creation and explicit
updates require strictly future triggers; target creation/offset-edit eligibility
uses existing pending work and future planned-session checks. Missing deadlines,
invalid attribution, unavailable targets, anchor mismatch and nonfuture triggers
fail without writes. Cancellation preserves trigger/mode/offset and delivered
Notifications, changes no work/session records and performs no delivery.

## Read-only discovery

`list_subtasks_for_task` accepts exactly required task_id. It scopes the parent to
the authenticated owner and uses SubtaskService::all. It returns task_id, up to 100
SubtaskResource-equivalent rows in position/ID order and meta with total, limit,
returned, truncated and complete. Soft-deleted children and foreign parents are
excluded. The existing unpaginated Service loads the active collection before
bounding presentation; this is not a claim of bounded source-memory usage.

`list_reminders` accepts optional status (scheduled/sent/cancelled), page (integer
1–2147483647) and per_page (integer 1–50, default 20). It uses ReminderService::listing,
retains trigger/ID order and returns ReminderResource-equivalent rows plus
current_page/per_page/total/last_page. It never recalculates historical triggers,
delivers, cancels, repairs history or marks inbox records read. Historical invalid
foreign target references are masked; malformed scheduled relationships fail closed.
The H1 ToolRegistry and Executor are not extended into new execution paths.

## Proposal preview and conversation commit

All proposals return the established envelope: ok, tool and data containing
state=confirmation_required, action_id, call_id and preview. The server-generated
UUID carries no client-editable mutation arguments. Preview includes action,
confirmation_required, before, changes and fixed effects. Subtasks add safe parent
identity/title/status/deadline; Reminder previews add mode and the effective proposed
UTC trigger. The trigger is calculated by the shared Reminder core, never the LLM.
Completion previews do not invent a future completed_at or exact dependency count.

The orchestrator validates a complete provider batch and counts every registered
proposal call, including invalid attempts, before dispatch. More than one across
domains aborts the turn. A second proposal in a later round also aborts the entire
uncommitted turn. Multiple reads may precede the single proposal. Proposal execution
does not insert an action or mutate any business table. No writer is invoked then,
even speculatively under rollback.

Only AiConversationService's existing successful finish transaction inserts the
pending action together with the transcript, conversation touch and durable H2
replay result. Provider failure, invalid output, budget failure, expired/replaced
lease or any transcript/action persistence failure leaves no confirmable action.
Replaying a committed Idempotency-Key returns the same action UUID. Call IDs remain
associated with the original assistant call, tool output, action and transcript.

Proposal-bearing turns discard intermediate provider assistant text/message items
and use deterministic confirmation-required final wording. Failed proposals use
deterministic no-change wording. A model's claim that something already executed or
was delivered cannot become a successful action statement. H3a's Task wording stays
compatible. No simple conversational yes executes an action.

## Existing action API and receipts

All endpoints retain Sanctum SPA authentication and conversation Policy checks:

| Method | Path under /api/ai/conversations/{conversation} | Body/filters |
| --- | --- | --- |
| GET | /actions | page, per_page; existing pagination |
| GET | /actions/{action} | no fields |
| POST | /actions/{action}/confirm | empty JSON object or absent body |
| POST | /actions/{action}/reject | empty JSON object or absent body |

The existing action Resource returns id, conversation_id, call_id, type, status,
state, confirmation_required, preview, expires_at, receipt, resolved_at and
created_at. Raw arguments, fingerprints and provider coordination state stay
private. Confirmation accepts no replacement arguments, IDs or policy flags.
Foreign, missing and mismatched conversation/action routes have indistinguishable
404 responses, authorized before payload validation.

Decisions return data plus replayed. An executed receipt is
`{action_id, type, subtask: {...}}` or `{action_id, type, reminder: {...}}`, preserving
the Task receipt contract for H3a. Subtask fields are id, task_id, title, description,
position, status, estimated_minutes, deadline_at and completed_at. Reminder fields
are id, task_id, subtask_id, study_session_id, message, trigger_at, anchor,
offset_minutes, status and sent_at. These are explicit Resource allowlists, not
unrestricted Eloquent attributes. Times are canonical UTC; unsafe foreign targets
are masked. Later domain edits do not rewrite historical receipts.

| Outcome | HTTP | Contract |
| --- | --- | --- |
| Pending detail / successful confirm | 200 | confirmation_required / executed with receipt |
| Confirm executed | 200 | identical durable receipt, replayed=true; no writer |
| Reject pending / repeat reject | 200 | rejected, no receipt or business effects |
| Confirm rejected | 409 | rejected Resource, no receipt |
| Expired decision | 410 | expired Resource, no receipt |
| Expiry crossed during business work | 410 | action_expired; entire transaction rolls back |
| Stale target / reject executed | 409 | action_stale / action_executed |
| Active AI turn | 409 | conversation_busy; no decision/history interleaving |
| Current domain validation failure | 422 | action_validation_failed; no receipt |
| Invalid HTTP payload / multiple proposals | 422 | Laravel message/errors / multiple_actions |
| Unexpected persistence/dependency failure | 500 | safe persistence_error |

Errors contain fixed safe messages, never SQL, traces, upstream bodies or foreign
titles. Proposal failures retain ok=false, tool, error with invalid_arguments,
not_found, access_denied or execution_failed. State values stay pending/executed/
rejected/expired. Stale/domain failures leave a pending row without a receipt;
request a new proposal. MySQL expiry cannot be revived by application-clock changes.

## Persistence and migration rollback

`2026_10_19_000001_expand_ai_pending_action_types.php` replaces the existing
ai_actions_valid_type CHECK, expanding four approved types to eleven. It also adds
`ai_conversations_owner_reference_index(user_id)` and rebuilds the existing
`(user_id, updated_at)` sorting index so InnoDB binds the owner FK to the stable
single-column index. It does not edit the applied H3a migration, add a table,
change columns, remove history or alter Task/Subtask/Reminder schema. UUIDs, unique originating-turn FK, conversation FK,
call IDs, JSON arguments/previews/receipts, 64-character fingerprint, MySQL expiry
and terminal-state CHECKs are reused unchanged.

Down restores the original four-type constraint and removes the additional owner
reference index when all stored rows are compatible; the sorting index remains.
If any H3b history remains, it refuses before DDL rather than deleting or relabeling
reviewed actions/receipts. An administrative data-retention/downgrade decision is
required before removing that history. Both down/up preservation and refusal are
tested on MySQL. Existing H2/H3a round-trip tests now account for the additive
migration while retaining their preservation assertions.

## Transaction ownership, locks and stale-state checks

See [ADR-003](decisions/ADR-003-reminder-confirmation-transaction.md). Public
ReminderService still rejects ambient transactions, starts its own outermost
transaction and locks owner before any source reads. Its shared internal
ReminderMutationService requires an existing transaction and never commits one.
Both public REST and AI confirmation use the same create/update/cancel bodies,
validation, target resolution, UTC arithmetic and final clock/eligibility checks.
No independent Reminder commit or second database connection can escape the receipt.

Confirmation owns one outermost transaction, with lock order:

1. Owned AiConversation, then AiPendingAction.
2. User owner row, before any consistent SELECT of turn metadata/domain data.
3. Owned Task, then Subtask, then StudySession when used by the target.
4. Reminder after its work/session context; existing synchronization locks child/
   session/reminder dependency batches in ascending ID order as before.
5. Receipt/state and deterministic conversation history under the held locks.

Delivery's existing Notification insert follows its held Reminder lock. AI
confirmation does not acquire inbox locks or create delivery receipts.

No domain writer acquires conversation/action locks. Direct writers and delivery
remain owner-first and never invert that prefix. Re-locking already-held records
in the shared core does not acquire a new earlier target. REPEATABLE READ snapshots
are established after the owner wait, so newly committed targets and session
Reminder dependencies are visible. No transaction spans a provider request.

Rejection acquires no owner/domain writer lock and still updates conversation
activity atomically with its outcome. Review reproduced a hidden InnoDB shared
User lock when changing updated_at in the index originally supporting the owner
FK. Same-second timestamps had concealed the issue in the existing H3a test.
The stable owner-reference index removes that dependency while preserving the FK,
sort index and actual conversation timestamp change. A separate-process regression
holds User exclusively and forces a changed conversation timestamp during rejection.

ActionTargetFingerprint hashes sorted raw persisted attributes with named record
identities. Subtask actions capture parent Task and child when applicable, including
actual fields, status, position, deletion/completion metadata and timestamps. Child
creation captures parent state but does not capture/reserve an append position.
New child capacity/position is checked by the writer at confirmation.

Reminder actions capture the Reminder when present plus resolved Task/Subtask/
StudySession records. This includes trigger, mode, anchor, offset, identity,
eligibility fields and actual persisted anchor sources. Even same-second material
edits change the hash. Missing/foreign/deleted targets, retargeting corruption,
delivery, cancellation and changed deadlines/anchors fail safely. Conservative
rejection of unrelated target edits is intentional. Clock-dependent eligibility
and future triggers are separately revalidated even when the fingerprint matches.

The existing confirmation service serializes competing decisions, calls the fixed
domain writer and persists business effects, receipt, terminal state and history
atomically. Exceptions/save vetoes/history failure roll back all effects. Expiry
uses MySQL UTC_TIMESTAMP(6), fifteen minutes from successful turn finalization,
checked after owner contention and after business work. Crossing expiry during a
slow mutation rolls back that mutation and receipt. A later request can persist
expired history without rerunning a writer. Reads show effective lazy expiry.

At-most-once committed business execution is per action, with durable result replay;
it is not guaranteed exactly-once HTTP delivery. A lost response is recoverable from
another process without repeating a writer. Separately confirmed similar proposals
are separate actions. Rejection never reverses an executed mutation.

Delivery races serialize on owner/work/Reminder. If delivery wins, confirmation
detects stale sent state and preserves its Notification. If cancellation wins,
delivery skips the cancelled Reminder. No AI Tool schedules manual delivery,
deletes inbox history or marks Notifications read. Deadline edits after a confirmed
relative creation may legitimately synchronize the new Reminder; the receipt still
records its original committed execution result.

## Conversation state and security

Committed decisions append deterministic assistant events using existing version-2
action_event metadata; no provider request/call is fabricated. Provider history
receives those outcomes as data and a compact latest-ten action-state projection
including safe Task/Subtask/Reminder result IDs. This survives transcript trimming
and lazy expiry. H2 version-1 calls, encrypted continuation, chronology, limits and
Idempotency-Key behavior remain supported. Ordinary conversations without actions
retain their public shape.

The User comes only from trusted authentication. Every parent/child, Reminder,
target and action is owner-scoped and reloaded. User-authored titles, messages,
descriptions and prior tool results remain untrusted data, never system authority.
Injected requests cannot expand the allowlist, change owner, bypass confirmation,
forge receipts or select PHP methods. Clients must render user-authored text safely.
No credentials, hidden prompts, SQL or raw upstream errors are stored in receipts.

## Known limits and H3c boundary

One proposal per turn, fifteen-minute expiry, bounded transcript/latest-ten action
projection and existing provider argument/context/result budgets remain. Large
otherwise domain-valid descriptions can exceed AI budgets and fail safely. H3b
discovery bounds presentation; SubtaskService still loads its active collection.
Reminder listing retains existing per-record structural checks. Separate read calls
do not promise one global snapshot. Supported writers cooperate through owner locks;
arbitrary SQL/model writers are outside that guarantee. Normal clock advancement
after the last check and before commit remains an existing bounded limitation.

Deterministic action wording is English. Automated tests use provider fakes and no
paid live inference; real-model selection/clarification evaluation is separate.
Deployment must apply the additive migration. Downgrade with H3b history is guarded.
H3c remains separate: no AI study planning/confirmation, session mutation, deletion,
bulk/reordering workflow, inbox mutation, direct delivery, new transport/provider,
streaming/voice or frontend is introduced.

## Verification

Final verification completed on 2026-10-19. All database tests use guarded
student_planner_testing MySQL, never SQLite. Independent workers use separate PHP
processes/PDO connections and assert performance_schema row-lock waits.
The shared Subtask candidate/deadline regression runs without a Laravel application
or database, with an isolated validation factory for Laravel error construction. Automated
verification uses HTTP/provider fakes, without a real provider key or live inference.

| H3b focused class | Tests | Assertions |
| --- | ---: | ---: |
| SubtaskReminderActionTest (pure Unit) | 128 | 229 |
| AiSubtaskReminderActionsTest (MySQL Feature) | 68 | 1110 |
| AiManagementActionMigrationTest (MySQL CHECK/index/rollback) | 1 | 24 |
| AiManagementActionConcurrencyTest (independent processes) | 29 | 439 |
| Total H3b | 226 | 1802 |

| Verification | Exit | Tests | Assertions | Result |
| --- | ---: | ---: | ---: | --- |
| Reminder REST extraction checkpoint | 0 | 209 | 2362 | Passed |
| Final H3b focused run | 0 | 226 | 1802 | Passed |
| H1/H2/H3a compatibility | 0 | 622 | 4160 | Passed |
| Domain/security/migration regressions | 0 | 3455 | 25164 | Passed |
| Entire PHPUnit suite | 0 | 5093 | 42753 | Passed |
| vendor/bin/pint --dirty | 0 | — | — | Passed after formatting |
| vendor/bin/pint --test | 0 | — | — | Passed |
| git diff --check | 0 | — | — | Passed |

All final PHPUnit runs had zero errors, failures and skips. Existing tests were not
removed or weakened. Existing orchestrator tests receive the added fixed adapters
and assert the complete discovery union; migration round-trip tests account for
one new migration while retaining preservation checks. The nine documented native
provider schemas were compared with generated definitions and matched exactly.

Review added a pure shared-candidate deadline test and two real domain-lock expiry
cases. The pure fixture explicitly supplies date formats and an isolated validation
factory rather than booting Laravel or connecting to a database. An initial
compatibility run then reported one worker timeout (622 tests, 4156 assertions,
exit 2). An isolated same-second retry passed, but a forced timestamp-change test
reproduced a shared InnoDB User FK lock. The stable owner-reference index fixes
the cause; the unchanged H3a compatibility suite passed 622 / 4160 afterward.
The new H3b regression verifies that rejection records history and updates the
conversation timestamp while another process holds User exclusively. No timeout
was increased and no existing concurrency assertion was removed or weakened.

Independent-process cases cover two simultaneous creations in both domains,
confirm/reject orderings, direct Subtask/Reminder edit and lifecycle races, both
cancellation/delivery orderings, relative creation/deadline races, fresh target and
new Reminder dependency discovery after owner waits, expiry while blocked on owner
and Task domain rows and after business writes, rejection with an actual
conversation timestamp update under an owner lock, rollback after business/receipt
persistence, lost response and cross-process replay, active turn exclusion,
independent conversations/owners and
expired H2 proposal finalization. Contention assertions inspect actual MySQL locks.

```sh
vendor/bin/phpunit --filter='ReminderApiTest|RelativeReminderApiTest' --log-junit /tmp/h3b-reminder-core.xml
vendor/bin/phpunit --filter='SubtaskReminderAction|AiManagementAction' --log-junit /tmp/h3b-focused-final.xml
vendor/bin/phpunit --filter='AgentOrchestratorTest|OpenAiResponsesProviderTest|AiChat|AiToolFoundationTest|AiToolsIntegrationTest|AiPersistenceTest|TaskAction|AiTaskActions' --log-junit /tmp/h3b-ai-compatibility.xml
vendor/bin/phpunit --filter='Task|Subtask|Reminder|Notification|StudySession|StudyReplanning|StudyPlan|AuthProfile|SecurityTest|RemainingEffortServiceTest|TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|PlanningWorkSelectionServiceTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningMigrationTest|AiManagementAction' --log-junit /tmp/h3b-domain-regressions.xml
vendor/bin/phpunit --log-junit /tmp/h3b-full-final.xml
vendor/bin/pint --dirty
vendor/bin/pint --test
git diff --check
```

Stage H3b acceptance: complete. Starting and ending HEAD are
`11fd88efef7c6471ce2ce0d612de540a584985be`. The starting tree already contained
the H3b draft described above; the final tree has 22 modified tracked files and
16 new files, 38 total, all left uncommitted. No commit or push was performed.
The migration was exercised on the
test database; deployment must apply it with Laravel migrations. Live-model
clarification evaluation, localization and the H3c/non-goal capabilities remain
the documented limitations, not additional implemented scope.

## Exact provider-facing definitions

The generated definitions below are the actual OpenAiToolSchema output for the nine
H3b tools. Mutation definitions use explicit strict=false to preserve omission
versus clearing-null, as H3a does. The two reads use the existing strict=true
optional-null adaptation. Server validation remains authoritative for conditional
absolute/relative requirements, mutually exclusive modes/targets, real calendar
dates, byte bounds and all current domain constraints.

```json
[
    {
        "type": "function",
        "name": "list_subtasks_for_task",
        "description": "Read active subtasks of one owned task ordered by position then ID. At most 100 rows; explicit completeness metadata.",
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
        "strict": true
    },
    {
        "type": "function",
        "name": "list_reminders",
        "description": "Read owned reminder history without recalculation or delivery. Ordered by stored trigger then ID; bounded pagination.",
        "parameters": {
            "type": "object",
            "properties": {
                "status": {
                    "type": [
                        "string",
                        "null"
                    ],
                    "enum": [
                        "scheduled",
                        "sent",
                        "cancelled",
                        null
                    ]
                },
                "page": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 2147483647
                },
                "per_page": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 50
                }
            },
            "required": [
                "status",
                "page",
                "per_page"
            ],
            "additionalProperties": false
        },
        "strict": true
    },
    {
        "type": "function",
        "name": "propose_subtask_create",
        "description": "Propose appending one pending subtask to an identified task. Position is assigned only at confirmation. Never invent IDs. Requires confirmation.",
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
                    "description": "At most 65535 UTF-8 bytes. Null clears; omission preserves."
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
                    "description": "Real whole-second UTC instant, years 1000–9999. Clarify ambiguous dates and missing clock times."
                }
            },
            "required": [
                "task_id",
                "title"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_subtask_update",
        "description": "Propose editing one subtask. Include at least one editable field. Null clears nullable fields; omission preserves. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "task_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "subtask_id": {
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
                    "description": "At most 65535 UTF-8 bytes. Null clears; omission preserves."
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
                    "description": "Real whole-second UTC instant, years 1000–9999. Clarify ambiguous dates and missing clock times."
                }
            },
            "required": [
                "task_id",
                "subtask_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_subtask_complete",
        "description": "Propose completing one pending subtask and cancelling dependent scheduled reminders. Parent and session statuses are preserved. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
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
                "task_id",
                "subtask_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_subtask_reopen",
        "description": "Propose reopening one completed subtask. Cancelled reminders and retired sessions are not recreated. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
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
                "task_id",
                "subtask_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_reminder_create",
        "description": "Propose scheduling one reminder, not delivery. Absolute: trigger_at and at most one target; a general reminder needs a meaningful message. Relative: exactly one target, matching anchor and offset_minutes; omit trigger_at. Server resolves the trigger. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "message": {
                    "type": [
                        "string",
                        "null"
                    ],
                    "maxLength": 500
                },
                "trigger_at": {
                    "type": "string",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$",
                    "description": "Real whole-second UTC instant, years 1000–9999. Clarify ambiguous dates and missing clock times."
                },
                "task_id": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "subtask_id": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "study_session_id": {
                    "type": [
                        "integer",
                        "null"
                    ],
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "anchor": {
                    "type": "string",
                    "enum": [
                        "task_deadline",
                        "subtask_deadline",
                        "session_start"
                    ]
                },
                "offset_minutes": {
                    "type": "integer",
                    "minimum": -32768,
                    "maximum": 32767
                }
            },
            "required": [],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_reminder_update",
        "description": "Propose editing one scheduled reminder. Include message and/or trigger_at for absolute mode, message and/or offset_minutes for relative mode. Cannot change targets or mode. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "reminder_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                },
                "message": {
                    "type": [
                        "string",
                        "null"
                    ],
                    "maxLength": 500
                },
                "trigger_at": {
                    "type": "string",
                    "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$",
                    "description": "Real whole-second UTC instant, years 1000–9999. Clarify ambiguous dates and missing clock times."
                },
                "offset_minutes": {
                    "type": "integer",
                    "minimum": -32768,
                    "maximum": 32767
                }
            },
            "required": [
                "reminder_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    },
    {
        "type": "function",
        "name": "propose_reminder_cancel",
        "description": "Propose cancelling one scheduled reminder. Keep its history and inbox notifications; change no work or sessions. Requires confirmation.",
        "parameters": {
            "type": "object",
            "properties": {
                "reminder_id": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 9223372036854775807
                }
            },
            "required": [
                "reminder_id"
            ],
            "additionalProperties": false
        },
        "strict": false
    }
]
```

## Changed files

- `app/Exceptions/AiAgentException.php`
- `app/Http/Requests/SaveSubtaskRequest.php`
- `app/Services/AI/Actions/ActionTargetFingerprint.php`
- `app/Services/AI/Actions/ManagementActionPresenter.php`
- `app/Services/AI/Actions/SubtaskReminderActionRegistry.php`
- `app/Services/AI/Actions/SubtaskReminderActionService.php`
- `app/Services/AI/Actions/SubtaskReminderArgumentValidator.php`
- `app/Services/AI/Actions/TaskActionConfirmationService.php`
- `app/Services/AI/AgentOrchestrator.php`
- `app/Services/AI/AiConversationService.php`
- `app/Services/AI/Tools/ManagementReadTools.php`
- `app/Services/Reminders/ReminderMutationService.php`
- `app/Services/Reminders/ReminderService.php`
- `app/Services/Tasks/SubtaskInputRules.php`
- `app/Services/Tasks/SubtaskService.php`
- `database/migrations/2026_10_19_000001_expand_ai_pending_action_types.php`
- `docs/ai-agent-chat.md`
- `docs/ai-subtask-reminder-actions.md`
- `docs/ai-task-actions.md`
- `docs/ai-tools-foundation.md`
- `docs/architecture.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- `docs/database-schema.dbml`
- `docs/database-schema.md`
- `docs/decisions/ADR-003-reminder-confirmation-transaction.md`
- `docs/domain-model.md`
- `docs/reminders.md`
- `docs/tasks.md`
- `tests/Feature/AiChatPersistenceTest.php`
- `tests/Feature/AiManagementActionConcurrencyTest.php`
- `tests/Feature/AiManagementActionMigrationTest.php`
- `tests/Feature/AiSubtaskReminderActionsTest.php`
- `tests/Feature/AiTaskActionPersistenceTest.php`
- `tests/Support/ai_management_action_worker.php`
- `tests/Unit/AgentOrchestratorTest.php`
- `tests/Unit/SubtaskReminderActionTest.php`
- `tests/Unit/TaskActionTest.php`
