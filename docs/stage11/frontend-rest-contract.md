# Stage 11 — REST contract for subsequent Frontend integration

Backend only; no Frontend files were modified. This extends the existing
[authenticated API](../frontend-api-contract.md), not the independent Vue/Laravel
architecture. Use Sanctum cookies, credentials and the existing CSRF setup.

## Routes and requests

| Method / URL | Body / headers | Result |
| --- | --- | --- |
| `POST /api/ai/conversations` | `{}` | 201, `data` Conversation |
| `POST /api/ai/conversations/{conversation}/messages` | `{"content":"…"}`, UUID `Idempotency-Key` | 200, existing turn result; optional action summary |
| `GET /api/ai/conversations/{conversation}/actions` | query `page` ≥1, `per_page` 1–50 (default 20) | 200, Laravel `data`, `links`, `meta`; newest first |
| `GET /api/ai/conversations/{conversation}/actions/{action}` | no body | 200, `{"data": Action}` |
| `POST /api/ai/conversations/{conversation}/actions/{action}/confirm` | exactly `{}` | 200, `{"data": Action, "replayed": false}` on execution |
| `POST /api/ai/conversations/{conversation}/actions/{action}/reject` | exactly `{}` | 200, same decision envelope; no business writes |

All four Lesson types use empty decision objects: no arguments, Lesson IDs,
acceptance flags or timezone overrides. Sending those returns Laravel 422 errors.
There is no REST endpoint for directly invoking a model tool. Tools prepare the
proposal during an ordinary chat turn. A conversational “yes” is not confirmation.
No mutation occurs on send, preview GET or before explicit confirmation.

The turn result keeps its existing shape:

```ts
type TurnResult = {
  conversation_id: number; user_message_id: number; assistant_message_id: number;
  content: string;
  tools: {call_id: string; name: string; ok: boolean; error_code: string | null}[];
  action?: {
    id: string; state: 'confirmation_required'; call_id: string;
    preview: LessonPreview; expires_at: string;
  };
};
```

Fetch the full action by its returned UUID. The summary's type is available as
`preview.type`; the full resource has `type`. A failed preparation has no action
and a failed tool result (`invalid_arguments`, `not_found`, `access_denied` or
`execution_failed`). The turn may still return HTTP 200; never infer a proposal or
mutation from prose or HTTP status alone. Retry a lost send response with the same
UUID/content, as in the existing client.

## Exact Lesson preview and receipt shape

```ts
type LessonActionType =
  | 'propose_lesson_create'
  | 'propose_lesson_update'
  | 'propose_lesson_cancel'
  | 'propose_lesson_replacement';
type NamedReference = { id: number; name: string } | null;
type LessonSnapshot = {
  id: number | null; // null only for a not-yet-inserted create/replacement
  subject_id: number | null; teacher_id: number | null; academic_period_id: number | null;
  subject: NamedReference; teacher: NamedReference; academic_period: NamedReference;
  type: 'lecture' | 'practical' | 'laboratory' | 'seminar' | 'consultation' | 'exam' | 'other';
  room: string | null; starts_at: string; ends_at: string; // YYYY-MM-DDTHH:MM:SSZ
  status: 'active' | 'cancelled' | 'replaced';
  replaces_lesson_id: number | null; replacement_id: number | null;
};
type LessonPreview = {
  type: LessonActionType;
  confirmation_required: true; // immutable review flag, not current action status
  before: LessonSnapshot | null;
  after: LessonSnapshot;
  effects: {
    original_becomes_replaced: boolean;
    restores_original: false;
    study_sessions_rescheduled: false;
    import_identity_preserved: true;
  };
};
type LessonReceipt = {
  action_id: string; type: LessonActionType;
  lesson: LessonSnapshot; // persisted non-null id
  original: LessonSnapshot | null; // only replacement: original AFTER it becomes replaced
};
type Action = {
  id: string; conversation_id: number; call_id: string; type: LessonActionType;
  status: 'pending' | 'executed' | 'rejected' | 'expired';
  state: 'confirmation_required' | 'executed' | 'rejected' | 'expired';
  confirmation_required: boolean; preview: LessonPreview;
  expires_at: string; // UTC ISO timestamp, potentially fractional seconds
  receipt: LessonReceipt | null; resolved_at: string | null; created_at: string | null;
};
```

No `arguments`, owner/import metadata, target hash or private planning token is
public. JSON object key ordering is unspecified. Names and room are untrusted data;
render as text using existing Vue escaping, never HTML. Snapshot IDs/labels are
server-resolved and must not be filled or changed by the client.

| Review title | `before` | `after` / visible effect |
| --- | --- | --- |
| New Lesson | null | Complete active candidate; ID null until insertion |
| Lesson update | Complete existing Lesson | Complete merged candidate; omitted fields retained, explicit optional null clears |
| Lesson cancellation | Existing active Lesson | Same occurrence, status cancelled; no deletion or original restoration |
| Lesson replacement | Active original | Complete manual replacement; original becomes replaced; after ID null for insert or existing ID for reuse of deleted history |

For replacement, `before` is the original, not an old replacement. On success the
receipt's `lesson` is the active replacement and `original` is the retired original
with its `replacement_id`. Other receipt `original` values are null. A cancelled
replacement keeps its `replaces_lesson_id`; its original remains replaced.

All preview/receipt Lesson instants are UTC, independent of user timezone. Display
both snapshots in the persisted profile timezone and include date, time and offset
where needed. Never infer new IDs, conflicts, inherited optional associations or
schedule feasibility in the browser. Catalog IDs missing from the AI request
require clarification; Stage 11 adds no AI catalog discovery capability.

## Decisions, errors and recovery

Use full action `status`/`confirmation_required` for controls; the immutable preview
flag remains true even after resolution. GET projects an elapsed pending action as
expired. Disable both decisions while one is in flight; require a deliberate click.
Keep existing safe blocking of unsupported types until all four reviews are added.

| Response | Meaning and client action |
| --- | --- |
| 200 `status=executed`, `replayed=false` | Mutation + receipt + server history committed; refresh schedule |
| 200 `status=executed`, `replayed=true` | Same historical receipt; no writer/provider invoked; refresh current schedule |
| 200 `status=rejected` | No Lesson mutation; repeated rejection remains safe |
| 409 with `data.status=rejected` | Confirming a rejected action; refresh action, request a new proposal |
| 410 with `data.status=expired` | Expired decision; refresh action and request a new proposal |
| 409 `code=action_stale` | Reviewed Lesson/link/association changed or disappeared; pending action remains unchanged; obtain fresh proposal |
| 422 `code=action_validation_failed` | New overlap or invalid current lifecycle/candidate; pending action remains unchanged; show message, refresh schedule, request fresh proposal |
| 409 `code=action_executed` | Rejecting an executed action; GET receipt/current schedule |
| 409 `code=conversation_busy` | Active turn excludes decisions; retry deliberately after it completes |
| 410 `code=action_expired` | Deadline crossed during write; all business/receipt writes rolled back; GET action |
| 422 `message` + `errors` | Invalid request JSON/body/fields; show server validation feedback |
| 401 / 419 | Recover login / CSRF with existing client flow; never auto-confirm |
| 404 | Missing/foreign conversation or action; show unavailable |
| 500 `code=persistence_error`, or lost response | GET action before assuming outcome; executed receipt is authoritative; explicit confirm retry replays safely |

Code errors use the existing `{"message":"…","code":"…"}` envelope. A 409/410
terminal decision can instead carry `data`/`replayed`; parse both paths. Do not
blindly resend all 409/422 errors. A stale or invalid pending action can be rejected,
but it cannot be refreshed by editing its arguments. Lesson failures do not append
planning-style failed-attempt events. Success/reject/expiry append the existing
`action_event` in message history with `action_id`, `status`, `receipt`.

After execution, refetch `GET /api/lessons/{receipt.lesson.id}` and, for replacement,
`GET /api/lessons/{receipt.original.id}` as needed. Invalidate/refetch the visible
`GET /api/schedule/today`, `/api/schedule/date/{date}` or `/api/schedule/week/{date}`
data, including both original and proposed local dates if the occurrence moved.
A midnight-crossing Lesson may affect multiple date views; use the visible view
range and server results. Schedule responses use offset presentation and `meta.timezone`;
Lesson detail and AI snapshots use UTC `Z`. Refresh the action/history too. A failed
refresh does not undo an executed receipt; expose retry-refresh independently.
No optimistic Lesson update is permitted before the decision response confirms
execution. No conflict detection, allocation or planning algorithm belongs here.

## Frontend follow-up at inspected HEAD

Read-only inspection used Frontend HEAD `d6e03fe` and Backend baseline `f618bba`.
Extend `src/types/ai.ts`, labels and `reviewSupported` in `src/utils/ai.ts`; add a
Lesson review alongside `AiWorkReview`/`AiReminderReview`/`AiPlanningReview` in
`AiActionReview.vue`. Extend `src/api/aiRefresh.ts` for schedule/detail refresh and
receipt Lesson links. Keep `src/api/ai.ts` safe object parsing and existing
`useAiConversation.ts` decision/error/uncertain-outcome recovery.

Required later tests: all four previews and both replacement IDs (insert/reuse),
explicit confirm/reject/loading, expiry/stale recovery, Laravel and code errors,
lost-response receipt recovery, schedule refresh (both dates), refresh failure,
and no optimistic writes. Run Frontend Unit/E2E/regression only in that subsequent
scope. The current Backend completion does not satisfy full Stage 11 UI acceptance.
