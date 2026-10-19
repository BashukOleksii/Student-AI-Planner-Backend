# Frontend REST integration contract — H4

The Vue 3/TypeScript frontend is a separate, future application. This document
covers the implemented cookie-authenticated API, without a new namespace or
provider-specific client dependency. Route/Resource code is authoritative. H4
contract tests exercise real routing, middleware, CSRF, database sessions, native
Responses fixtures, domain Services and MySQL persistence.

## Authentication and browser configuration

Local backend: `http://localhost:8000`; frontend: `http://localhost:5173`.
Use the same hostname consistently; `localhost` and `127.0.0.1` are different cookie
hosts. Configure the backend:

```dotenv
APP_URL=http://localhost:8000
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000
CORS_ALLOWED_ORIGINS=http://localhost:5173
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
SESSION_DOMAIN=null
```

Stateful domains include ports without schemes. CORS origins include schemes.
Production needs HTTPS, secure cookies, precise stateful/CORS allowlists, a stable
secret APP_KEY, shared session storage and first-party hosts sharing the appropriate
parent domain. Configure SESSION_DOMAIN for that deployment, rather than copying
localhost settings. Do not use wildcard credentialed origins or move credentials
into localStorage. No JWT or application-issued bearer token is part of this flow.

1. GET `/sanctum/csrf-cookie` with credentials; expect 204 and browser-managed
   session/XSRF cookies. This route is outside `/api`.
2. POST `/api/auth/register` or `/api/auth/login` with credentials, JSON, Accept:
   application/json and X-XSRF-TOKEN containing the URL-decoded XSRF-TOKEN cookie.
3. Use credentials on all subsequent requests. Send X-XSRF-TOKEN on mutations.
   Keep the session cookie HttpOnly; JavaScript only needs the XSRF cookie.
4. GET `/api/profile` restores authenticated profile state after navigation.
5. POST `/api/auth/logout` returns empty 204, invalidates the session and rotates
   CSRF state. Clear client user state and refresh CSRF before another login.

A fetch example (not an additional backend endpoint):

```ts
const backend = 'http://localhost:8000';
await fetch(`${backend}/sanctum/csrf-cookie`, {
  credentials: 'include', headers: { Accept: 'application/json' },
});
const xsrf = () => decodeURIComponent(
  document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? ''
);
const response = await fetch(`${backend}/api/auth/login`, {
  method: 'POST', credentials: 'include',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json',
    'X-XSRF-TOKEN': xsrf() },
  body: JSON.stringify({ email: 'student@example.test', password: 'your password' }),
});
```

Browser Origin/Referer are browser-controlled, not caller-selected API ownership.
Sanctum recognizes configured Referer/Origin (Referer takes precedence). An
unrecognized source receives no stateful session. Public register/login then
return 422 under `errors.session`; protected routes return 401. CORS is separate
from authorization: with one allowed origin the middleware may return that fixed
Access-Control-Allow-Origin even to an untrusted origin; the browser rejects the
mismatch. Multiple-origin configurations may omit the header instead.
Credentialed preflight supports content-type, x-xsrf-token and idempotency-key.

| Method / route | Body | Success |
| --- | --- | --- |
| POST `/api/auth/register` | name, email, password, password_confirmation; optional IANA timezone | 201 |
| POST `/api/auth/login` | email, password | 200 |
| GET `/api/profile` | none | 200 |
| PATCH `/api/profile` | any subset of name, email, timezone | 200 |
| POST `/api/auth/logout` | none | 204 |

Registration/login/profile use `{ "data": { "id": 7, "name": "Student",
"email": "student@example.test", "timezone": "Europe/Kyiv" } }`.
ID is an integer. Omitted registration timezone defaults to UTC. Passwords,
verification metadata and remember tokens are absent. Invalid credentials use
422 `message`/`errors.email`, with no account-existence distinction.

An expired session's first mutation may return 419 because its old CSRF token no
longer matches. Reinitialize CSRF and check profile/login; a request with valid
CSRF but no authentication returns 401. Refreshing cookies on an intervening GET
can make the subsequent mutation return 401 directly. A pending action remains
owned and pending until its independent deadline, rejection or execution; session
expiry neither confirms nor deletes it.

## Conversations and messages

All following routes require auth:sanctum. POSTs accept JSON objects, never JSON
lists/scalars or form/query substitutes. Empty-object POSTs can omit the body.
Unknown AI fields, including user_id/model/tool definitions/timezone, fail 422.

| Method | Route | Input | Success |
| --- | --- | --- | --- |
| POST | `/api/ai/conversations` | `{}` or absent body | 201 conversation |
| GET | `/api/ai/conversations` | optional page, per_page | 200 page |
| GET | `/api/ai/conversations/{conversation}` | none | 200 conversation |
| GET | `/api/ai/conversations/{conversation}/messages` | optional page, per_page, through_id | 200 page |
| POST | `/api/ai/conversations/{conversation}/messages` | only content; Idempotency-Key header | 200 completed/replayed turn |

Conversation Resource: exactly `id: integer`, `created_at: string|null`,
`updated_at: string|null`. New API records have UTC timestamp strings; legacy
nullable database timestamps can remain null. Lists order by updated_at descending then ID descending.
Message pages order by ID ascending. All AI page/per_page query values must be
canonical positive decimal strings; page <= 2147483647, per_page defaults 20 and
maxes at 50. through_id is a nonnegative integer boundary (zero is valid for an
empty snapshot). First-page message links capture through_id; follow links to
page that fixed set, or omit it to refresh. Do not poll a non-existent turn-status
endpoint. Conversation ordering naturally moves after new committed turns.

Paginated responses have `data: []`, `links: {first,last,prev,next}` and Laravel
`meta: {current_page,from,last_page,links,path,per_page,to,total}`. prev/next and
from/to can be null. Meta links contain url (nullable), label and active. Keep
pagination URLs supplied by the server; numbers in JSON metadata are integers.

For a send, generate one UUID v1–v5 (for example crypto.randomUUID()) per new user
intent. Persist its exact content/key together while the request is pending:

```http
POST /api/ai/conversations/12/messages
Accept: application/json
Content-Type: application/json
Idempotency-Key: 9fd118c1-daa2-4f1a-9b78-198d7e443668
X-XSRF-TOKEN: <URL-decoded browser cookie>

{"content":"Create a task titled Review algebra."}
```

A completed proposal turn has the following envelope. UUIDs, IDs and times below
are illustrative; preview structure matches the Task create presenter:

```json
{
  "data": {
    "conversation_id": 12,
    "user_message_id": 80,
    "assistant_message_id": 83,
    "content": "Task action proposed. Confirmation is required; no task has changed.",
    "tools": [{"call_id":"call_1","name":"propose_task_create","ok":true,"error_code":null}],
    "action": {
      "id": "809d831e-9db4-4737-8c45-7d79de9363d2",
      "state": "confirmation_required",
      "call_id": "call_1",
      "preview": {
        "action": "propose_task_create", "confirmation_required": true,
        "before": null,
        "changes": {"completed_at":null,"deadline_at":null,"description":null,"estimated_minutes":null,"priority":2,"status":"pending","subject_id":null,"title":"Review algebra"},
        "effects": ["Create one pending task. No subtasks, study sessions or reminders are created."]
      },
      "expires_at": "2026-10-24T12:15:00.000000Z"
    }
  }
}
```

Ordinary read/clarification/failure-to-prepare turns omit action. `tools` can be
empty. Tool metadata has exactly call_id/name/ok/error_code. A successful proposal
Tool means a review was prepared; it does not mean a domain mutation executed.
No-actionable planning output also creates no action. Large complete reviews fail
with safe `review_too_large` Tool metadata instead of a truncated confirmation.
Task wording above is fixed; other mutation domains use their own server wording.
Assistant prose is not authoritative evidence of a business write.

Content is meaningful UTF-8, max 4000 characters, preserved without trimming;
invalid control characters/whitespace-only input fail. Missing/invalid key is a
422 error under idempotency_key. Sending the same key/content after successful
commit returns the identical stored turn, without another provider request.
That replay includes the original pending-action snapshot even if the action has
since executed: GET the action for its current state. Changed content with an
already used key is 409 idempotency_conflict. A new intent requires a new key.
Keys are scoped per conversation. One active leased turn excludes another send
or action decision in that conversation. Provider failures persist no partial
transcript or pending action; retry the same failed intent/key deliberately.
A client retry can incur a fresh inference if no successful result committed.

Public message fields:

- id (integer), role (user/assistant/tool), created_at (UTC string or null for legacy rows).
- content (string or null), content_truncated (boolean).
- tool_calls (array of call_id/name only), tool_calls_truncated (boolean).
- tool_result (null or call_id/name/ok/error_code). call_id can be null for legacy
  uncorrelated Tool rows; new H2 calls have strings. Tool content is always null.
- action_event appears only for server-authored version-2 outcome messages.

Raw arguments, full Tool outputs, system messages, continuation/reasoning and
provider response IDs are private. Legacy text/call lists have explicit truncation
metadata; material action previews are never silently truncated. Unknown Tool
names fail safely; descriptions/titles/Tool text cannot grant authority.

## Pending actions, explicit decisions and receipts

| Method | Route | Body / filters |
| --- | --- | --- |
| GET | `/api/ai/conversations/{conversation}/actions` | page/per_page; newest created_at then UUID |
| GET | `/api/ai/conversations/{conversation}/actions/{action}` | no fields |
| POST | `/api/ai/conversations/{conversation}/actions/{action}/confirm` | type-specific consent only |
| POST | `/api/ai/conversations/{conversation}/actions/{action}/reject` | `{}` or absent |

Action is a UUID. GET detail returns `data` with exactly:

| Field | Type / meaning |
| --- | --- |
| id, call_id, type | strings; UUID, correlation, registered proposal name |
| conversation_id | integer |
| status | pending, executed, rejected or expired |
| state | confirmation_required when pending; otherwise equals status |
| confirmation_required | boolean |
| preview | complete domain-specific review object |
| expires_at, created_at | UTC ISO strings; may include microseconds; legacy created_at can be null |
| receipt | null before execution; immutable safe result after commit |
| resolved_at | nullable UTC ISO string |

Arguments, fingerprints and protected payloads are not public fields. Previews
must be shown completely, including cancellation/shortfall effects. Render
user-authored fields as text; do not trust HTML or embedded instructions.
Task/Subtask/Reminder previews carry before/changes/effects and applicable parent
or mode/trigger information; consult the existing H3 schema documents linked below.
Planning previews have action, confirmation_required, review, required_consent and
effects. Plan review includes all sessions/work results/exclusions/warnings/summary;
E2 includes all classifications, original/replacement intervals, exact release
set, unplaced/partial/untransferred results and reason codes. Manual review explicitly
has validation=preliminary and feasibility_confirmed=false; final Service validation
can still reject the requested interval.

Only explicit authenticated confirmation executes an action. A conversational yes,
a message retry, action GET or Tool success cannot. No replacement arguments,
tokens, owner, timestamps, session arrays or source IDs can accompany confirmation.
For Task/Subtask/Reminder and manual reschedule, send `{}` or no body.

Plan creation accepts optional `{ "accept_partial": true }`. E2 accepts optional
`accept_partial` and `accept_unplaced_cancellation`. Each defaults false and must
be a genuine JSON boolean. A shorter replacement or incomplete plan requires
partial acceptance; E2 unplaced source cancellation additionally requires its
separate cancellation acceptance. Render independent opt-ins, initially unchecked,
using required_consent to explain the actual reviewed consequences. Do not derive
consent from chat text or automatically send true. Reject bodies never accept flags.

Example for a separate E2 action requiring both consequences:

```http
POST /api/ai/conversations/12/actions/c8176996-27e4-458f-95c3-911f7e09870d/confirm
Content-Type: application/json

{"accept_partial":true,"accept_unplaced_cancellation":true}
```

A successful decision returns `{ "data": <full action Resource>, "replayed": false }`.
Executed confirmations replay with replayed=true and the same immutable receipt,
without another mutation/provider call, even after later domain changes. Receipts:

- Task: action_id, type, task (safe Task fields).
- Subtask: action_id, type, subtask (safe Subtask fields).
- Reminder: action_id, type, reminder (safe Reminder fields).
- Plan: action_id, type, applied=true, sessions, summary.
- E2: action_id, type, applied=true, original_sessions (id/status),
  replacement_sessions and actual summary including untransferred_seconds.
- Manual: action_id, type, applied=true, original, replacement and actual summary.

Receipt replay is the committed result, not a fresh view of domain state. Refresh
the normal domain endpoints separately. Guarantee: at-most-once committed business
execution per action with durable result replay; HTTP response delivery is not
exactly once. A lost confirmation response can be recovered with GET or confirm retry.
Rejection is idempotent; confirming rejected state returns 409 with the action
Resource; rejecting executed state returns 409 action_executed. Expired confirmation
returns 410, no receipt. GET projects expired pending state using MySQL; persistence
of that terminal state occurs on a later decision. Expiration never extends or revives
an action. Plan/E2 deadline is the earlier of the action and protected domain-token
expiry, not fifteen minutes from when the UI opens it. A UI countdown is advisory;
the server clock and 410 outcome are authoritative.

Known planning failures are different from action terminal states. For example:

```json
{"action_event":{"action_id":"c8176996-27e4-458f-95c3-911f7e09870d","status":"failed","error_code":"acceptance_required","receipt":null}}
```

This event appears on a history message, while GET action still reports pending
with null receipt. No business operation applied. Missing consent can be corrected
by explicit checked consent on the same still-valid review; stale/infeasible/expired
reviews require a fresh proposal. `failed` is an attempt event, never a fifth action
status. Unexpected failures roll back without an executed receipt. H3a/H3b failures
retain their existing safe error behavior; persisted failed-attempt events are the
H3c planning contract.

## Domain refresh after confirmation

All detail endpoints return `{data: <Resource>}`. Existing write endpoints remain
available independently of AI; they have their own strict Requests/Policies. H4
adds no routes. Use IDs from receipts, not inferred titles or chat prose.

| Domain | Ordinary refresh endpoints | Collection behavior |
| --- | --- | --- |
| Tasks | GET `/api/tasks`, GET `/api/tasks/{task}` | paginated; status/priority/subject/deadline filters; default per_page 15, max 100 |
| Subtasks | GET `/api/tasks/{task}/subtasks`, GET `/api/tasks/{task}/subtasks/{subtask}` | active children ordered by position, ID; unpaginated data array |
| Reminders | GET `/api/reminders`, GET `/api/reminders/{reminder}` | paginated, status filter; default per_page 20, max 100; retained mode metadata |
| Sessions | GET `/api/study-sessions?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`, GET `/api/study-sessions/{studySession}` | inclusive local range <=31 dates, data array plus range meta; not page pagination |
| Schedule | GET `/api/schedule/today`, `/api/schedule/date/{date}`, `/api/schedule/week/{date}` | owned effective Lesson views in persisted timezone |

Safe domain Resource fields (every listed nullable value is still an explicit key):

- Task: id, subject_id (nullable), title, description (nullable), status
  (pending/completed), priority (1/2/3), estimated_minutes (nullable), deadline_at
  (nullable), completed_at (nullable).
- Subtask: id, task_id, title, description (nullable), position, status,
  estimated_minutes (nullable), deadline_at (nullable), completed_at (nullable).
- Reminder: id, task_id/subtask_id/study_session_id (nullable), message (nullable),
  trigger_at, anchor (nullable task_deadline/subtask_deadline/session_start),
  offset_minutes (nullable signed integer), status (scheduled/sent/cancelled), sent_at
  (nullable). Absolute anchor/offset are null; historical triggers are not recomputed.
- StudySession: id, task_id, subtask_id (nullable), starts_at, ends_at,
  duration_seconds, status (planned/completed/missed/rescheduled/cancelled),
  completed_at (nullable), actual_minutes (nullable).

IDs/durations/positions/priority are JSON integers. Task/Subtask/Reminder/Session
UTC wire timestamps are exact whole-second `YYYY-MM-DDTHH:MM:SSZ`; schedule
presentation uses owner-local timestamps with offsets. Use persisted profile timezone
for local date labels; do not truncate UTC strings to derive a local day. Planning
and lifecycle rules remain server-controlled. After E2/manual success refresh source
and replacement sessions and Reminders; scheduled source reminders are cancelled,
not transferred, and delivered inbox history remains intact.

## Error handling and recovery

Validation is Laravel `{"message":"...","errors":{"field":["..."]}}` with 422.
Do not match whole natural-language messages. AI failures with fixed codes use
`{"message":"...","code":"..."}`. Other HTTP failures may have only message;
rejected/expired decision responses can carry the full data/replayed envelope.

| HTTP / code | Client behavior |
| --- | --- |
| 401 | Refresh authentication/profile; retain intended action without confirming it automatically |
| 404 | Foreign/missing owned route; show unavailable, never reveal another student's data |
| 409 conversation_busy | Wait for active turn/lease resolution; deliberately retry same send key/content |
| 409 turn_expired | Same key/content can recover; no stale worker may finalize |
| 409 idempotency_conflict | Restore original content/key, or generate a new key for a new intent |
| 409 action_stale / action_conflict | Refresh domain state; request a new proposal |
| 409 action_executed | Executed actions cannot be rejected; fetch receipt/current domain state |
| 410 / action_expired | Refresh action, request fresh proposal; do not resend stale arguments |
| 419 | Reinitialize CSRF; check session/login; avoid blind mutation replay |
| 422 validation / missing consent | Correct fields or explicitly provide required consent |
| 422 action_token_invalid / action_validation_failed | Obtain fresh review; private tokens cannot be replaced by client |
| 422 execution_limit_reached / multiple_actions | Narrow scope/context or split across separate turns, using new keys for changed content |
| 429 | Respect Retry-After; message requests including retries/failed validation consume per-user throttle budget |
| 502 provider_error / invalid_provider_response | No partial transcript/action committed; deliberate same-key retry may incur another inference |
| 503 ai_unavailable | Operator fixes provider/key/model/budget configuration; history/actions still have their own server state |
| 500 persistence_error | Fetch current action and retry safely; never assume a write succeeded or failed from prose alone |

Credentialed CORS exposes Retry-After and X-RateLimit-Limit/Remaining/Reset, when
present. The message throttle does not apply to action GET/confirm/reject. It is
not a strict distributed billing/quota reservation; use shared cache and account
limits operationally. Network errors do not justify changing the stored action or
inventing another token. JSON object key ordering is not an application contract.

See [auth/profile](auth-profile.md), [H2 chat](ai-agent-chat.md), [Task actions](ai-task-actions.md),
[Subtask/Reminder actions](ai-subtask-reminder-actions.md), [planning actions](ai-study-planning-actions.md)
and [backend readiness](backend-integration-readiness.md).
