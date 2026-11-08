# Stage 18B — AI availability action contract

The agent can propose one recurring weekly availability create, update or delete.
Only the existing authenticated confirmation endpoint executes it. Discovery now
contains **31 definitions: 10 reads and 21 proposals**. All previous 28 definitions
and their relative order are preserved; these three are appended after Lesson
proposals. [The Stage 18A read Tool](availability-read-tool.md) remains unchanged.

## Exact provider-independent Tool definitions

The following definitions include every accepted field and required property.
`availability_window_id.maximum` is PHP_INT_MAX (shown for the verified 64-bit
runtime). The registry's `strict: false` follows the existing mutation convention:
OpenAI preserves genuine omission for partial edits. Server validation is strict;
nulls never stand for omitted availability fields. Gemini receives each unchanged
`parameters` schema as `parametersJsonSchema`; OpenAI wraps it as a non-strict
native function definition without widening types or removing null arguments.

### `propose_availability_create`

```json
{
    "name": "propose_availability_create",
    "description": "Propose one recurring weekly study availability window. Supply the ISO weekday and exact local HH:MM:SS times explicitly; only the end may be 24:00:00. Requires confirmation; does not reschedule existing sessions.",
    "strict": false,
    "parameters": {
        "type": "object",
        "properties": {
            "day_of_week": {
                "type": "integer",
                "minimum": 1,
                "maximum": 7
            },
            "starts_at": {
                "type": "string",
                "pattern": "^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$"
            },
            "ends_at": {
                "type": "string",
                "pattern": "^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]|24:00:00)$"
            }
        },
        "required": [
            "day_of_week",
            "starts_at",
            "ends_at"
        ],
        "additionalProperties": false
    }
}
```

### `propose_availability_update`

```json
{
    "name": "propose_availability_update",
    "description": "Propose editing one exact owned recurring availability window. Discover IDs with list_study_availability_windows. Include at least one editable field; omission preserves values, null is invalid. Requires confirmation; does not reschedule existing sessions.",
    "strict": false,
    "parameters": {
        "type": "object",
        "properties": {
            "availability_window_id": {
                "type": "integer",
                "minimum": 1,
                "maximum": 9223372036854775807
            },
            "day_of_week": {
                "type": "integer",
                "minimum": 1,
                "maximum": 7
            },
            "starts_at": {
                "type": "string",
                "pattern": "^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$"
            },
            "ends_at": {
                "type": "string",
                "pattern": "^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]|24:00:00)$"
            }
        },
        "required": [
            "availability_window_id"
        ],
        "additionalProperties": false
    }
}
```

### `propose_availability_delete`

```json
{
    "name": "propose_availability_delete",
    "description": "Propose deleting exactly one owned availability configuration record identified by list_study_availability_windows. Requires confirmation. Does not delete Tasks, Lessons, StudySessions or Reminders, or reschedule sessions. No bulk deletion.",
    "strict": false,
    "parameters": {
        "type": "object",
        "properties": {
            "availability_window_id": {
                "type": "integer",
                "minimum": 1,
                "maximum": 9223372036854775807
            }
        },
        "required": [
            "availability_window_id"
        ],
        "additionalProperties": false
    }
}
```

## Arguments and shared domain validation

- Create requires all three editable fields. It creates exactly one window.
- Update requires one positive integer `availability_window_id` plus at least one
  editable field. Omitted fields retain the latest reviewed persisted values.
  Including a field with its current value is valid; no-op updates still require
  confirmation and cannot bypass cancellation/persistence checks.
- Delete accepts only the exact positive integer ID and removes one configuration
  record. No ID lists, filters, implicit selection or bulk deletion are supported.

Weekdays are genuine integers, Monday=1 through Sunday=7. Strings, floats, booleans,
nulls, nested values, nonpositive IDs and unknown properties (including `user_id`,
`timezone`, `class`, `method` and planning consent) are rejected without coercion.
The agent must obtain exact owned IDs through `list_study_availability_windows` or
explicit user input and clarify ambiguous matches. Missing weekdays/times must
never be invented. Collection ownership is established by trusted authentication,
not by the ID supplied by the provider.

Times are recurring local wall-clock strings with whole seconds. Start accepts
`00:00:00`–`23:59:59`; end additionally accepts exactly `24:00:00`. Short times,
whitespace, fractions, offsets and concrete dates are invalid. End must be strictly
after start. Overnight periods must be split explicitly into separate windows;
no automatic split, timezone conversion or midnight normalization occurs.

`StudyAvailabilityInputRules` supplies the same weekday/lexical rules to the REST
Form Request, AI argument validation and complete-candidate Service preparation.
`StudyAvailabilityService::prepare()` merges partial updates with an owned current
row, then uses the same interval and overlap code as `create()`/`update()`.
Preparation performs only nonlocking SELECTs. The original overlap predicate is
unchanged: same owner/day, `existing.starts_at < candidate.ends_at` and
`existing.ends_at > candidate.starts_at`, excluding the edited row. Adjacent
windows remain valid. Confirmed writes repeat this complete validation inside the
existing owner-serialized transaction; proposals never reserve time.

## Proposal and material review

Successful Tool output uses the existing proposal envelope:

```json
{
  "ok": true,
  "tool": "propose_availability_update",
  "data": {
    "state": "confirmation_required",
    "action_id": "11111111-1111-4111-8111-111111111111",
    "call_id": "availability_1",
    "preview": {
      "type": "propose_availability_update",
      "confirmation_required": true,
      "before": {"id": 12, "day_of_week": 1, "starts_at": "16:00:00", "ends_at": "20:00:00"},
      "after": {"id": 12, "day_of_week": 1, "starts_at": "17:00:00", "ends_at": "21:00:00"},
      "timezone": "Europe/Kyiv",
      "effects": {
        "changes_recurring_availability": true,
        "removes_availability_window": false,
        "reschedules_existing_sessions": false,
        "changes_planning_preferences": false,
        "deletes_tasks": false,
        "deletes_lessons": false,
        "deletes_study_sessions": false,
        "changes_reminders": false
      }
    }
  }
}
```

Identifiers in examples are illustrative; runtime IDs come only from owned reads,
persisted rows and server-issued action UUIDs. JSON object key order is not a
contract. Window fields retain their exact scalar types and clock values.

Create has `before: null` and `after.id: null` until actual insertion. Update has
both complete four-field window objects and retains the target ID. Delete has
`after: null` and the complete previous window in `before`, with
`removes_availability_window: true`. Every review includes the persisted timezone
and exactly the eight effect flags shown above. Other effect values remain the
same for all three actions.

The proposal's immutable validated arguments and target fingerprint are internal.
They are not returned by the public pending-action Resource or Tool output. A
successful originating turn alone persists the proposal with the ordinary MySQL
15-minute pending-action expiry, call/turn provenance and review. Provider failures
or finalization failures leave no confirmable action or partial transcript. A
proposal's server-owned final answer explicitly says confirmation is required;
provider wording cannot claim the mutation already happened.

## Existing REST confirmation and rejection

No new endpoint or controller is introduced. Use existing Sanctum cookie/session
authentication and the established CSRF flow:

| Method | Endpoint | Behavior |
| --- | --- | --- |
| POST | `/api/ai/conversations/{conversation}/messages` | Existing content body and `Idempotency-Key`; may return one proposed action |
| GET | `/api/ai/conversations/{conversation}/actions` | Owned paginated pending/history list; `page`, `per_page` (maximum 50) |
| GET | `/api/ai/conversations/{conversation}/actions/{action}` | Owned action detail |
| POST | `/api/ai/conversations/{conversation}/actions/{action}/confirm` | Explicit decision; send JSON object `{}` |
| POST | `/api/ai/conversations/{conversation}/actions/{action}/reject` | Explicit rejection; send JSON object `{}` |

An absent empty decision body is accepted by the existing Request, but clients
should send `{}`. `[]`, client-edited arguments, query parameters, null fields and
all consent flags are rejected. `accept_partial` and
`accept_unplaced_cancellation` remain planning-only. Conversational "yes", a read,
a proposal, provider output or an automatic retry of chat is never confirmation.

The existing send response has `data.action` with `id`, `state`, `call_id`,
`preview`, `expires_at` only when finalization persists an action. Its `preview.type`
discriminates the three actions. Pending-action list/detail and decisions expose
the unchanged AiPendingActionResource fields:

`id`, `conversation_id`, `call_id`, `type`, `status`, `state`,
`confirmation_required`, `preview`, `expires_at`, `receipt`, `resolved_at`,
`created_at`. Status is `pending`, `executed`, `rejected` or `expired`; pending
state is `confirmation_required`. A pending record past its MySQL deadline is
presented as expired even before a decision persists that status. Expiration is
not extended by reads or retries.

Decision responses use `{ "data": <action Resource>, "replayed": false }`.
Confirmation succeeds with HTTP 200 and executed state. Repeated confirmation of
an executed action returns HTTP 200, `replayed: true` and the original receipt,
without any writer/provider call. Repeated rejection is harmless; confirming a
rejected action or rejecting an executed action returns 409. Expired decisions
return 410 and no business mutation. Active conversation turns exclude decisions.

## Stale-state, transactions and concurrency

The fingerprint includes the fresh owner's ID/timezone and **every owned weekly
window's canonical raw attributes**, keyed and loaded in ascending ID order. It
intentionally invalidates a proposal when **any other owned weekly window** is
created, edited or deleted, even on an unrelated weekday. This conservative rule
also protects create proposals without a target row. Unrelated profile name/email
changes and other users' configuration do not invalidate it.

Confirmation uses the existing outermost H3 transaction and locks:

1. Owned conversation.
2. Its pending action.
3. Authenticated owner.
4. Complete owned availability configuration in ascending ID order.

No consistent-read snapshot is established before the owner lock. It reloads the
current profile/rows, checks the fingerprint, reconstructs/compares the complete
material review and revalidates current rules. Missing or changed targets,
configuration or timezone require a new proposal; no old action is silently
adapted. Ownership and the existing update/delete Policy remain authoritative.

Only StudyAvailabilityService's existing transactional methods write the window.
They reuse the same connection and owner lock as REST; nested transactions do not
commit independently of the outer action transaction. Overlapping concurrent AI
proposals cannot both execute. A waiting confirmation sees committed REST edits
only after obtaining the owner lock and rejects its stale review. No provider I/O
occurs in the confirmation transaction.

Successful business persistence, action status, immutable receipt and decision
history commit together. Invalid/stale operations leave the action pending and
unexecuted under the existing nonplanning failure convention; they do not append a
planning-specific failed-attempt event. Unexpected write/event/receipt/history
failures roll back the entire transaction. The existing post-write expiry check
also rolls back a change if the deadline is crossed during the domain write.

## Immutable receipt

The existing confirmation service adds `action_id` and `type` to this verified
payload. Create/update example:

```json
{
  "action_id": "11111111-1111-4111-8111-111111111111",
  "type": "propose_availability_create",
  "window": {"id": 13, "day_of_week": 1, "starts_at": "20:00:00", "ends_at": "24:00:00"},
  "deleted_window": null,
  "timezone": "Europe/Kyiv",
  "effects": {
    "changes_recurring_availability": true,
    "removes_availability_window": false,
    "reschedules_existing_sessions": false,
    "changes_planning_preferences": false,
    "deletes_tasks": false,
    "deletes_lessons": false,
    "deletes_study_sessions": false,
    "changes_reminders": false
  }
}
```

Delete receipt example:

```json
{
  "action_id": "22222222-2222-4222-8222-222222222222",
  "type": "propose_availability_delete",
  "window": null,
  "deleted_window": {"id": 12, "day_of_week": 1, "starts_at": "16:00:00", "ends_at": "20:00:00"},
  "timezone": "Europe/Kyiv",
  "effects": {
    "changes_recurring_availability": true,
    "removes_availability_window": true,
    "reschedules_existing_sessions": false,
    "changes_planning_preferences": false,
    "deletes_tasks": false,
    "deletes_lessons": false,
    "deletes_study_sessions": false,
    "changes_reminders": false
  }
}
```

Create/update re-read the owned database row and compare all four public fields
with the material review (assigning a create ID only after persistence). A returned
in-memory model alone cannot prove success. Delete verifies that the owned target
no longer exists, preserving its previous values in the receipt. Cancelled model
writes, including a cancelled no-op save, cannot produce success receipts. Replay
returns stored history even after later window edits/deletion; it never reconstructs
the historical receipt from live records.

## Safe errors

Proposal errors use `{ "ok": false, "tool": <name>, "error": {"code": <code>,
"message": <safe message>} }`, with no proposed action. Known codes:

| Proposal code | Meaning |
| --- | --- |
| `invalid_arguments` | Missing/unknown/malformed fields, invalid interval or current overlap |
| `not_found` | Missing or foreign target; identical sanitized result |
| `access_denied` | Invalid trusted persisted User context |
| `execution_failed` | Unexpected preparation failure; no internal details |

| Decision HTTP/code | Meaning and client response |
| --- | --- |
| 401 | Authenticate using the existing session contract |
| 404 | Conversation/action is missing or belongs to another owner |
| 422 standard `errors` | Invalid body shape/fields/consent; send only `{}` |
| 422 `action_validation_failed` | Current domain validation failed; obtain a fresh review |
| 409 `action_stale` | Reviewed configuration/timezone/target changed; read current state and request a new proposal |
| 409 `conversation_busy` | Active chat turn; retry the decision after it finishes |
| 409 `action_executed` | An executed action cannot be rejected |
| 410 action Resource with expired state | Pending action expired before execution; request a new proposal |
| 410 `action_expired` | Deadline crossed during execution; entire attempted write rolled back |
| 500 `persistence_error` | The attempt failed; inspect safe action detail/receipt before retrying |

Errors never expose SQL, raw fingerprints, credentials, provider keys, internal
exception messages or foreign record attributes. Existing chat provider/idempotency
errors and retry behavior remain unchanged; see [AI chat](../ai-agent-chat.md).

## Migration and deployment

One new forward-only migration,
`2026_11_08_000002_add_ai_availability_actions.php`, expands
`ai_pending_actions.ai_actions_valid_type` from the previous 18 names to 21,
retaining all old names. All three fit VARCHAR(32). No columns, indexes, FKs,
statuses, receipt/resolution CHECKs, old migration files or stored actions are
rewritten. There is no new persistence table or token format.

Deploy this expansion before enabling proposal persistence. `down()` always
refuses without DDL or data changes, including on an empty table. Application or
schema rollback must preserve availability action history and use a compatible
forward correction. Only guarded disposable MySQL databases were migrated in
this milestone; retained `student_planner` was not migrated.

## Exact Stage 18C Frontend handoff

1. Support all three action types in the existing confirmation UI. Until supported,
   keep the existing unknown-action confirmation block. No Frontend implementation
   or inspection is part of Stage 18B.
2. Render the server `preview`, with create/update/delete labels, ISO weekday,
   exact local start/end, complete before/after values and persisted timezone.
   Do not parse these clock strings as UTC dates or convert `24:00:00` to the same
   day's midnight. Create ID remains null until execution; delete preserves the
   reviewed removed identity and fields.
3. Clearly state that availability deletion removes the configuration record only.
   Tasks, Lessons, StudySessions, Reminders and numeric preferences are unchanged.
   Availability edits do not automatically move any session; replanning is separate
   and requires its own review/confirmation.
4. Show separate explicit Confirm and Reject controls only for supported pending
   actions. Post `{}` to the existing decision endpoint, with existing auth/CSRF.
   Never submit arguments, time fields, owner IDs or planning consent flags. Do not
   convert conversational approval or successful reads into an API decision, and
   do not confirm multiple actions automatically.
5. Treat only a successful executed decision/receipt as an applied change. Use
   `replayed` as durable retry information, not as a second mutation. Render the
   saved receipt without fetching current data to rewrite its historical values.
6. After successful confirmation, refresh recurring availability and invalidate
   derived free-slot/planning/advice views or previews as needed. This is read-cache
   invalidation, not a rescheduling operation. Rejection/expiry/stale responses must
   not display an applied configuration change.
7. On stale state, re-read current windows and request a new proposal. Do not
   automatically retarget an old review, merge changed values, extend expiry or
   retry it as a different action. All owned weekly edits intentionally stale old
   proposals. Preserve pending/expired/rejected/executed states and safe error UX.
8. Keep the existing chat request idempotency key/content binding and action UUID
   for retries. The action list/detail APIs recover decision state after response
   loss; no new endpoint, background poller or action framework is required.

See [implementation, test results and diagnostics](backend-stage18b-result.md).
