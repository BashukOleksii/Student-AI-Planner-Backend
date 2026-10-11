# Tasks and Subtasks — T1–T3, S1 and S2

Implemented: authenticated Task creation, detail retrieval, partial updates,
completion, reopening and filtered/paginated listing. Persistence uses the existing
MySQL Task/Subtask schema and enums. S1 adds nested Subtask creation, ordered
listing, detail, partial editing and completion/reopening. S2 adds transactional
Subtask reordering and read-only derived Task progress. Task/Subtask deletion,
restore, cleanup, planning, reminders and AI tools remain deferred.

## Endpoints

All routes require `auth:sanctum` and use the normal `data` Resource wrapper.

| Method | Path | Route name | Success |
|---|---|---|---|
| GET | `/api/tasks` | `tasks.index` | 200 |
| POST | `/api/tasks` | `tasks.store` | 201 |
| GET | `/api/tasks/{task}` | `tasks.show` | 200 |
| PATCH | `/api/tasks/{task}` | `tasks.update` | 200 |
| POST | `/api/tasks/{task}/complete` | `tasks.complete` | 200 |
| POST | `/api/tasks/{task}/reopen` | `tasks.reopen` | 200 |
| GET | `/api/tasks/{task}/progress` | `tasks.progress` | 200 |

Unauthenticated requests return 401. Missing, soft-deleted and foreign Tasks
return 404; PATCH authorizes before validating the body. Validation failures
return Laravel field errors with 422 and leave persisted data unchanged.

## Collection queries

`GET /api/tasks` is read-only. It accepts the following optional query parameters;
request-body fields and unknown parameters, including `user_id`, are ignored.
Supplied filters must be non-empty and valid, or the endpoint returns Laravel
422 field errors without modifying data.

| Parameter | Contract |
|---|---|
| `status` | `pending` or `completed` |
| `priority` | 1 low, 2 normal, 3 high |
| `subject_id` | Positive ID of an existing owned Subject |
| `deadline_from` | Inclusive UTC lower deadline bound |
| `deadline_to` | Inclusive UTC upper deadline bound |
| `page` | Positive page, default 1 |
| `per_page` | 1–100, default 15 |

Numeric query values use canonical positive ASCII decimal notation: no leading
zeros, signs, whitespace, fractions or exponent notation. Values must fit PHP's
integer range; page offsets that would overflow it are rejected. Deadline bounds
use UTC whole-second `YYYY-MM-DDTHH:mm:ssZ`, years 1000–9999, without whitespace
or offsets. The lower bound must not exceed the upper bound; equality is valid.
No server/User timezone interpretation is applied.

Filters combine with AND semantics. Deadline ranges match only non-null deadlines.
Null Subject and null deadline Tasks remain in unfiltered results. Foreign and
nonexistent Subject filters receive the same generic validation error. No null
Subject filter, overdue/in-progress status or configurable sorting is supported.

Ordering is fixed: non-null deadlines first, ascending deadline, then ascending
ID; null deadlines come last in ascending ID order. This is collection presentation
order, independent of future planning priority ranking.

Laravel pagination runs in MySQL and returns `data`, `links` and `meta`, including
totals and page metadata. Links preserve approved filters. Empty results and
out-of-range pages return 200 with empty `data` and pagination metadata. This is
ordinary offset pagination, without snapshot isolation across separate requests
when data changes.

TaskService queries only the authenticated owner's non-deleted Tasks, including
both pending and completed records. Subjects are eager loaded in one owned batch
query for each non-empty page requiring associations. Legacy foreign Subject links
serialize as null without loading foreign presentation data or repairing rows.
TaskResource fields remain unchanged; Subtasks/progress are not exposed.

## Creation and update input

| Field | Create | PATCH | Constraint |
|---|---|---|---|
| `title` | Required | Optional | Non-empty string, up to 255 characters |
| `description` | Optional/null | Optional/null | String, up to 65535 UTF-8 bytes (MySQL TEXT) |
| `subject_id` | Optional/null | Optional/null | Strict positive integer; existing owned Subject |
| `priority` | Optional, default 2 | Optional | Strict integer: 1 low, 2 normal, 3 high |
| `estimated_minutes` | Optional/null | Optional/null | Strict integer 1–65535 |
| `deadline_at` | Optional/null | Optional/null | Canonical UTC whole-second `YYYY-MM-DDTHH:mm:ssZ`, years 1000–9999 |

POST with only `title` succeeds. Subject, estimate and deadline are optional;
past deadlines are permitted. Omitted PATCH fields retain their persisted
values. Explicit null clears nullable fields. Empty or whitespace-only deadlines
are rejected rather than interpreted as null; offset/fractional timestamps are
also rejected. Ordinary text input follows Laravel trimming.

Ownership comes exclusively from authentication. Internal attributes including
`id`, `user_id`, `status`, `completed_at` and audit/deletion timestamps are ignored
on the create/PATCH endpoints. Creation uses pending status, normal priority and null
completion metadata. PATCH preserves status and completion metadata, including
already completed Tasks. Lifecycle changes use only the dedicated endpoints below.

## Completion and reopening

Both lifecycle endpoints accept no editable input; unexpected body fields are
ignored. They authorize with TaskPolicy's existing `update` ability and return
the unchanged TaskResource representation with HTTP 200.

`complete` transitions pending to completed and records the current application
UTC instant, truncated to whole-second precision, as `completed_at`. Deadlines
in the past or absent do not block completion. Late completion retains its actual
instant; no overdue status or flag is introduced. Completing an already completed
Task preserves its original completion instant and performs no UPDATE.

`reopen` transitions completed to pending and clears `completed_at`. Reopening
an already pending Task performs no UPDATE. Idempotent calls leave `updated_at`
unchanged. Completion after reopening records a new instant; no completion
history is stored in the current schema.

TaskService runs each lifecycle operation in a transaction, locks the owner
first, then reloads/locks the current owned Task before deciding its transition.
Status and completion timestamp change together in one database operation,
preserving the existing `tasks_completion_consistency` CHECK. This serializes
competing mutations rather than trusting stale route-bound models. The clock
and stored completion instant are independent of server/User/client timezones.

Task and Subtask completion are independent. Pending Subtasks do not block
manual parent completion; completing/reopening a parent does not alter any
Subtask, and completed Subtasks do not automatically complete their parent.
These endpoints change only Task lifecycle fields (plus normal `updated_at` on
an actual transition). Subject, title, description, priority, estimates and
deadlines remain unchanged; no StudySession or Reminder workflow runs and no
progress is calculated. A malformed legacy foreign Subject remains masked in
the response without silent repair; ordinary PATCH still requires explicit repair.

## Service and persistence guarantees

`TaskService` whitelists editable fields and mutates within transactions, locking
the authenticated owner first and reloading/locking the latest owned Task for
PATCH. It validates the complete candidate Subject association even when omitted
from input. A malformed legacy foreign Subject is masked as null on GET without
repairing the database; unrelated PATCH fails until explicitly repaired with
null or an owned Subject. Foreign/missing Subjects receive the same generic error.

When the candidate parent deadline is non-null, no non-deleted Subtask with a
deadline may exceed it. The Service checks relevant Subtasks under locks;
completed but non-deleted records participate, while deleted/null-deadline
records do not. Equal deadlines and clearing the parent deadline are allowed.
No Subtasks are mutated by Task updates. S1 SubtaskService enforces the reciprocal
deadline bound under the same owner and parent serialization locks.

Existing MySQL checks preserve pending/completed completion consistency,
priority 1–3 and positive estimates. Same-owner association and cross-row
deadline validation are Service responsibilities, not new database constraints.
Subject deletion retains the existing Academic Context behavior: protected
Lesson/foreign Task history blocks deletion; allowed deletion clears same-owner
Task associations, including soft-deleted Tasks.

## Public representation

Only `id`, `subject_id`, `title`, `description`, `status`, `priority`,
`estimated_minutes`, `deadline_at` and `completed_at` are exposed. Status is a
string enum value; priority is an integer enum value. Nullable dates are null or
canonical UTC whole-second strings. Storage and serialization explicitly use
UTC, independent of server or User timezone. No ownership IDs, audit metadata,
relationship objects, Subtask collections or calculated progress are exposed.

## S1 Subtask API

All nested routes require `auth:sanctum` and use SubtaskResource under `data`.

| Method | Path | Route name | Success |
|---|---|---|---|
| GET | `/api/tasks/{task}/subtasks` | `tasks.subtasks.index` | 200 |
| POST | `/api/tasks/{task}/subtasks` | `tasks.subtasks.store` | 201 |
| POST | `/api/tasks/{task}/subtasks/reorder` | `tasks.subtasks.reorder` | 200 |
| GET | `/api/tasks/{task}/subtasks/{subtask}` | `tasks.subtasks.show` | 200 |
| PATCH | `/api/tasks/{task}/subtasks/{subtask}` | `tasks.subtasks.update` | 200 |
| POST | `/api/tasks/{task}/subtasks/{subtask}/complete` | `tasks.subtasks.complete` | 200 |
| POST | `/api/tasks/{task}/subtasks/{subtask}/reopen` | `tasks.subtasks.reopen` | 200 |

Ownership is inherited from the exact route parent. The parent must be owned and
non-deleted, and the child must be non-deleted and belong to that parent. Scoped
binding, TaskPolicy and SubtaskPolicy reject missing/deleted/foreign/mismatched
targets with 404 before body validation. Authentication failure returns 401;
input and deadline/capacity errors return Laravel 422 field errors. Service calls
independently reload the current owned parent and scoped child, protecting direct
calls from forged or stale model attributes.

### Basic creation, listing and editing (S1a)

Input fields are `title` (required on create, optional on PATCH), `description`,
`estimated_minutes` and `deadline_at`. They follow the Task contract: non-empty
title up to 255 characters, nullable description up to 65535 UTF-8 bytes, nullable
strict integer estimate 1–65535 and nullable canonical UTC whole-second deadline
in years 1000–9999. Past deadlines are valid. Omitted PATCH fields retain current
values; explicit null clears nullable fields. Internal fields (`id`, `task_id`,
`position`, `status`, `completed_at`, audit/deletion timestamps and ownership)
are ignored. Subtasks cannot move between Tasks.

Creation is allowed under pending or completed parents and never changes parent
state. New Subtasks are pending with null completion metadata. After locking the
owner and current parent, SubtaskService allocates position 1 when no non-deleted
children exist, otherwise maximum non-deleted position + 1. Deleted history is
excluded; creation never renumbers or restores existing rows. Position 65535 is the
last available value; exhaustion returns a `position` validation error. Allocation
uses the owner/parent serialization locks, without adding a uniqueness constraint.

The collection is read-only and unpaginated, ordered by position then ID ascending,
including an ID tie-breaker for historical duplicate positions. Deleted children
are excluded and an empty collection returns `data: []`.

Create/PATCH validate the complete candidate against the latest parent deadline:
if both deadlines exist, the Subtask deadline must be no later than its parent's.
Equal/earlier values or null on either side are allowed. An unrelated PATCH on a
legacy invalid relation fails until explicitly repaired with a valid deadline or
null. Validation failure rolls back without changing parent/child state.

Subtask mutations use one transaction and owner → parent → child lock order (the
allocation query locks the current maximum child when present). This is compatible
with TaskService's parent deadline changes. PATCH preserves parent, position and
lifecycle metadata; StudySessions and Reminders are untouched.

### Completion and reopening (S1b)

The dedicated lifecycle endpoints ignore all body fields. They reload/lock the
owner, parent and child, then change status/completion together in one UPDATE.
Completion transitions pending → completed using current application UTC time
truncated to seconds. Late completion is allowed. Reopening transitions completed
→ pending and clears completion metadata. Already completed/pending operations
are idempotent: no UPDATE, no timestamp replacement and no `updated_at` change.
Completion after reopening records a new instant; no completion history is stored.

Task/Subtask status remains independent in both directions, including when all
Subtasks are completed. Lifecycle operations change no unrelated fields, do not
repair legacy deadline relations, and do not alter parents, siblings, StudySessions
or Reminders. No progress calculation or planning/reminder workflow runs.

### Public Subtask representation

Only `id`, `task_id`, `title`, `description`, `position`, `status`,
`estimated_minutes`, `deadline_at` and `completed_at` are exposed. Status uses
the existing pending/completed string values. Dates are null or canonical UTC
whole-second strings, independent of server/User timezone. No audit fields,
ownership IDs, relationship objects or derived progress are exposed. Existing
TaskResource remains unchanged and contains no Subtask collection.

## S2a transactional Subtask reordering

`POST /api/tasks/{task}/subtasks/reorder` accepts JSON containing `subtask_ids`,
the complete desired order of the parent's active (non-deleted) Subtasks:

```json
{"subtask_ids": [12, 9, 15]}
```

The field must be a JSON array (including an empty array only for an empty active
set), with strict positive integer IDs and no duplicates. Objects, string/floating
IDs, partial/extra/deleted/unrelated IDs are rejected with 422. Foreign and unknown
IDs receive the same generic permutation error. Forged `user_id`, `task_id` and
position fields are ignored. Parent authorization precedes body validation;
missing/deleted/foreign parents return 404 and unauthenticated requests return 401.

SubtaskService opens a transaction, locks owner → current owned parent → all active
children, then validates against that freshly loaded set. Cached relationship
collections and stale client counts are not trusted. The submitted order assigns
positions 1..N, normalizing legacy gaps/duplicate positions without a new uniqueness
constraint. More than 65535 active rows cannot be represented and causes a controlled
error. All changes roll back together on failure. Rows already at their desired
position receive no UPDATE; an identical dense reorder preserves `updated_at`.

The response is the unchanged complete SubtaskResource collection under `data`,
ordered by position then ID. Subsequent GET shows the same order, and S1 creation
still appends after the current maximum. Deleted history, parent fields, child
lifecycle/title/description/deadline/estimate data, sessions and reminders remain
untouched. GET never repairs positions.

Creation and reordering share the owner/parent locks. A new active child added since
list retrieval makes the old permutation incomplete and yields 422 without partial
updates. No snapshot consistency across separate requests is promised.

## S2b derived Task progress

`GET /api/tasks/{task}/progress` returns a separate Resource without changing
TaskResource or SubtaskResource. It requires the authenticated owner (401 without
authentication; 404 for foreign, missing or deleted Tasks).

```json
{"data":{"task_id":42,"total_subtasks":3,"completed_subtasks":2,"progress_percent":67}}
```

Progress is completed active Subtasks / total active Subtasks × 100, rounded to an
integer with nearest, half-up behavior using integer arithmetic. For example 1/4
is 25, 2/3 is 67, and 1/8 is 13. With zero active Subtasks, both counts are 0 and
`progress_percent` is null, including for a completed parent or deleted-only history.

TaskService reloads the owned active Task and runs one scoped aggregate query;
both pending and completed non-deleted children count. No Subtask collection is
loaded, no cache/percentage column is persisted and no records are mutated.
The result reflects the current aggregate query, without cross-request snapshot
guarantees. Input cannot supply the counts or progress result.

Parent and child statuses remain independent: a pending Task can report 100%, and
a completed Task can report 0%. Child completion/reopening changes the next result;
parent completion/reopening and child reordering do not. Positions, estimates,
completion dates and study sessions do not define this count-based metric. Remaining
schedulable duration, effort accounting, feasibility and planning remain deferred;
BR-SUB-006's future planner must not double-count parent and child estimates.
