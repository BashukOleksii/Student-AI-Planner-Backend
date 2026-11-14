# Stage 14A — Unfinished work recovery API

Recovery automatically proposes new future sessions for eligible remaining work.
It requires explicit confirmation to save them. It does not relocate sessions in
the background, mark sessions missed, retire sources, or overwrite completed work.

## Request

`POST /api/planning/recovery/preview` requires the existing Sanctum student session
and normal SPA CSRF protection. Send `Accept: application/json` and
`Content-Type: application/json`.

```json
{
  "start_date": "2026-11-09",
  "end_date": "2026-11-15",
  "task_id": null,
  "subtask_id": null
}
```

Dates are required, exact real `YYYY-MM-DD` labels, inclusive and ordered, with
at most 31 nominal dates. The stored User timezone is authoritative; no request
timezone is accepted. Optional IDs may be omitted or null, otherwise they must
be positive JSON integers. A Subtask requires its explicit parent Task. Only the
body supplies these values. Unknown body fields, client session rows, owner IDs,
whitespace-padded dates and string/float IDs fail validation.

Null scope selects all current owned work, **including unfinished work without a
missed session**. A Task selects that Task and its authoritative active Subtasks;
an explicit Subtask selects only that unit. Missing, deleted, foreign or incorrectly
nested explicit work returns generic 404. Completed owned work is accessible for
diagnosis but excluded from allocation. Unscoped deleted Tasks are not enumerated.

## Response

HTTP 200 uses the standard `data` envelope with exactly `recovery` and
`study_plan`. `study_plan` is formatted by the existing `StudyPlanResource`, with
the same plan fields and meaning as ordinary study-plan preview. In this endpoint
only, `preview_token` and `expires_at` are **omitted** if there are no proposed
sessions. All other plan fields remain present, including empty lists and zero
summary totals. Nonempty partial proposals still receive a token.

This is a complete representative body for Task 42 with a 60-minute estimate and
one missed session. The new plan uses Monday's stored local availability in UTC.
The token value is an opaque placeholder; use the real returned value verbatim.

```json
{
  "data": {
    "recovery": {
      "scope": {"task_id": 42, "subtask_id": null},
      "history_scope": "selected_work_all_dates",
      "summary": {
        "eligible_work_count": 1,
        "missed_session_count": 1,
        "past_planned_session_count": 0,
        "requires_lifecycle_decision": false,
        "actionable": true
      },
      "missed_sessions": [
        {
          "study_session_id": 91,
          "task_id": 42,
          "subtask_id": null,
          "starts_at": "2026-11-07T09:00:00Z",
          "ends_at": "2026-11-07T10:00:00Z",
          "duration_seconds": 3600,
          "work_reason_codes": [],
          "allowed_lifecycle_actions": []
        }
      ],
      "past_planned_sessions": []
    },
    "study_plan": {
      "as_of_utc": "2026-11-08T08:00:00.500000Z",
      "start_date": "2026-11-09",
      "end_date": "2026-11-15",
      "timezone": "UTC",
      "sessions": [
        {
          "task_id": 42,
          "subtask_id": null,
          "starts_at": "2026-11-09T09:00:00Z",
          "ends_at": "2026-11-09T10:00:00Z",
          "duration_seconds": 3600
        }
      ],
      "work_results": [
        {
          "task_id": 42,
          "subtask_id": null,
          "requested_seconds": 3600,
          "allocated_seconds": 3600,
          "unscheduled_seconds": 0,
          "reason_codes": []
        }
      ],
      "excluded_items": [],
      "warnings": [],
      "summary": {
        "requested_seconds": 3600,
        "allocated_seconds": 3600,
        "unscheduled_seconds": 0,
        "fully_allocated": true
      },
      "preview_token": "<opaque encrypted study-plan preview token>",
      "expires_at": "2026-11-08T08:15:00Z"
    }
  }
}
```

### Recovery fields and types

| Path under `data.recovery` | Type and meaning |
| --- | --- |
| `scope.task_id`, `scope.subtask_id` | integer or null; validated selection scope. |
| `history_scope` | literal string `selected_work_all_dates`. |
| `summary.eligible_work_count` | integer ≥ 0; number of authoritative eligible planning units, including units that cannot fit. |
| `summary.missed_session_count` | integer ≥ 0; count of returned explicitly missed records, not a count of remaining units. |
| `summary.past_planned_session_count` | integer ≥ 0; count of returned planned records whose end is at or before the captured full-precision UTC clock. |
| `summary.requires_lifecycle_decision` | boolean; at least one such past planned record exists. |
| `summary.actionable` | boolean; at least one new session is proposed. It does not mean a partial plan is fully allocated. |
| `missed_sessions`, `past_planned_sessions` | arrays of diagnostic records, ordered by original `starts_at`, then numeric ID ascending. |
| `[].study_session_id`, `[].task_id` | integer; owned persisted history IDs. |
| `[].subtask_id` | integer or null; exact associated child. |
| `[].starts_at`, `[].ends_at` | strings; whole-second UTC ISO timestamps. |
| `[].duration_seconds` | positive integer; original elapsed scheduled seconds, not remaining effort. |
| `[].work_reason_codes` | array of existing planning reason strings for the associated current work. |
| `[].allowed_lifecycle_actions` | array of strings; status/time hints from existing lifecycle rules. Missed terminal records return `[]`. |

Plan types remain as documented in [study-plan-persistence.md](../study-plan-persistence.md)
and [automatic-study-planning.md](../automatic-study-planning.md). `work_results`
contains each eligible unit once, with exact requested/allocated/unscheduled
seconds. `excluded_items` preserves the existing selection shape, including its
six effort fields, effective deadline as nullable UTC Unix seconds, priority,
position and exclusion codes. Task and Subtask estimates are not combined when
Subtasks are authoritative.

## Historical diagnostics and warnings

History is for the selected active Tasks across **all dates**, independently of
the new allocation horizon. Thus a missed session last week remains visible when
requesting next week's recovery. Only `missed` or ended `planned` records are
returned; completed/cancelled/rescheduled records are not recovery sources. Parent
history under a Task now using Subtasks is diagnostic, not redistributed effort.
Owned deleted-Subtask history under a selected active Task can be reported with
`deleted_subtask`; deleted Tasks are not current selected work. Explicit Subtask
scope filters sibling/parent session history.

`past_planned_sessions` is separate from explicitly missed history. It requires
`status=planned` and `ends_at <=` the captured UTC clock. Started but not yet
ended sessions are excluded from this list and its count; their time stays
occupied and their work remains reserved against duplicate planning. Neither
`starts_at` nor a task deadline requests an outcome. An ended planned session
reports `allowed_lifecycle_actions: ["complete", "miss"]`. These are time/status hints, not an
authorization token or guarantee of later success. Existing lifecycle endpoints
still validate ownership, history, reminders and the current clock.

The existing planner's `stale_planned_session` warnings remain in
`study_plan.warnings`, with their existing `code`, `task_id`, `subtask_id`,
`study_session_id` and `seconds` keys. Other selection warnings pass through
unchanged. Recovery itself never changes status or assumes that a past plan
represents completed work. To resolve the ambiguity, the student explicitly uses
the existing `POST /api/study-sessions/{id}/complete` (optional `actual_minutes`)
or `POST /api/study-sessions/{id}/miss`, then requests a fresh recovery preview.

`requires_lifecycle_decision` is advisory: it does not silently retire the record
and does not block reviewing/confirming new remaining-work sessions. Confirming a
proposal leaves those past records planned. A subsequent lifecycle decision can
change effort/warnings and make an earlier proposal stale; confirmation then
rejects changed material outcomes instead of silently saving a different plan.

| Situation | Result using existing authoritative rules |
| --- | --- |
| Missed work still has unallocated effort | New future sessions are proposed; missed durations are not added to the estimate. |
| Multiple missed sessions for one unit | Multiple historical diagnostics, but one allocation budget for that unit. |
| Other completed sessions satisfy the estimate | No new allocation; `completed_effort_satisfies_estimate`. |
| Valid future reservations cover the remainder | No new allocation; `fully_reserved`, including reservations outside the requested horizon. |
| Ongoing planned session | No outcome request yet; its full duration still reserves unfinished work, including when deadline warnings exist. |
| Missing estimate | `missing_estimate`; no invented remaining duration. Persisted estimates are positive or null; zero remaining work is an effort result. |
| Completed parent/child | Existing `completed_task`, `parent_task_completed` or `completed_subtask` exclusions. |
| Decomposed parent | `subtasks_authoritative`; no duplicate parent allocation. |
| Ended planned session | Outcome diagnostic and `stale_planned_session`; no completed credit or new-effort reservation is inferred. Existing occupied intervals/workload remain in C9 context. |
| Deadline already expired | `deadline_expired`; no automatic deadline extension or overdue placement. |
| Deadline leaves too little usable time | Partial/empty result with existing allocator reasons such as `deadline_capacity_exhausted`. |
| Availability/break/daily/weekly limits prevent fitting | Exact unscheduled seconds and existing constraint/exhaustion codes; no invented capacity. |

Completed credit is unchanged: positive actual minutes take precedence, with the
existing scheduled-duration fallback when completed actual minutes are null.
Missed records supply neither completed credit nor valid future reservations.
Valid future planned reservations reduce unallocated effort separately. No second
remaining-effort algorithm is introduced. Future reservations and conflicts are
preserved; recovery does not fix invalid future reservations by relocating them.
That remains the separate [adaptive replanning](../adaptive-replanning.md) flow.

## Confirmation

Use the **existing** endpoint with `data.study_plan.preview_token`:

```http
POST /api/planning/study-plans/confirm
Content-Type: application/json
Accept: application/json
```

```json
{
  "preview_token": "<the exact returned token>",
  "accept_partial": true
}
```

`accept_partial` may be omitted for a full plan. Partial proposals require the
explicit boolean `true`; otherwise Laravel returns 422 for `accept_partial` and
creates nothing. Empty recovery proposals supply no token and cannot be saved.

The token is the existing authenticated encrypted owner-bound Stage C token,
with its unchanged 900-second lifetime. Confirmation locks the owner in a fresh
outermost transaction, regenerates the authoritative plan, compares the reviewed
material outcome, and independently revalidates work budgets, conflicts,
availability, breaks, limits and deadlines. Expiration is checked again around
the write batch; failure rolls back all inserts. Starts must remain strictly in
the future at the final pre-commit check. No alternate recovery writer or token
purpose is introduced.

Success is HTTP 201 with the unchanged ordinary `StudyPlanResource` response:
the same plan fields under `data`, saved `sessions` containing their IDs, status,
completion/actual fields, and no preview token/expiration. Every inserted record
is `planned` with null `completed_at`, `actual_minutes` and predecessor reference.
Missed history is **not** a `rescheduled_from_session_id` source. See the full
existing [confirmation contract](../study-plan-persistence.md).

Replaying a successful token, confirming another preview of the same now-reserved
work, or changing material capacity/work/deadline state returns 409 without new
rows. Repeated previews do not write data; their encrypted tokens differ while
the same snapshot and clock produce identical proposals/diagnostics. This retains
existing conservative conflict semantics, not a new durable idempotency receipt.

Recovery diagnostics are supplementary context, not new protected confirmation
parameters. The token protects the existing complete planner outcome. A history
change that has no material effect on that outcome does not invalidate the token.

## Errors, time and limits

| HTTP status | Behavior |
| --- | --- |
| 200 | Preview, including nonactionable empty results. |
| 401 | Authentication required before validation. |
| 404 | `{"message":"Not found."}` for inaccessible explicit work. |
| 422 | Standard Laravel validation; confirmation also uses this for invalid, expired or wrong-owner tokens and missing partial acceptance. |
| 409 | Existing stale-preview message on conflicting confirmation. |
| 500 | `{"message":"The study plan could not be processed."}` for unsafe references, invalid stored data or internal failures, including debug mode. |

New allocation uses the existing persisted timezone, exact local-date membership,
UTC elapsed intervals and captured planner clock. Past parts of a requested range
do not create past sessions. Deadline equality at the session end is allowed;
expiration is evaluated against the captured clock. The existing deterministic
earliest-deadline/priority ordering and greedy allocator remain authoritative;
insufficient allocation is not a global impossibility proof.

One nonlocking MySQL REPEATABLE READ transaction covers preview and diagnostic
reads. Queries are owner-scoped and references validated in batches. No foreign
Task, Subtask, Subject name or session identifiers are serialized. Historical
diagnostic volume is not capped by the 31-date allocation limit; all relevant
selected-work history is read, with no pagination or latency guarantee. There is
no background job, autonomous mutation, new AI Tool, schema change, missed-status
inference or immutable historical snapshot in this stage.
