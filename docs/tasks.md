# Tasks — T1 basic API

Implemented: authenticated Task creation, detail retrieval and partial updates.
Persistence uses the existing MySQL Task/Subtask schema and enums. T1 does not
implement listing/filtering, lifecycle transitions, deletion/cleanup, Subtask
CRUD/reordering, progress, planning, reminders or AI tools.

## Endpoints

All routes require `auth:sanctum` and use the normal `data` Resource wrapper.

| Method | Path | Route name | Success |
|---|---|---|---|
| POST | `/api/tasks` | `tasks.store` | 201 |
| GET | `/api/tasks/{task}` | `tasks.show` | 200 |
| PATCH | `/api/tasks/{task}` | `tasks.update` | 200 |

Unauthenticated requests return 401. Missing, soft-deleted and foreign Tasks
return 404; PATCH authorizes before validating the body. Validation failures
return Laravel field errors with 422 and leave persisted data unchanged.

## Input

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
on both write endpoints. Creation uses pending status, normal priority and null
completion metadata. PATCH preserves status and completion metadata, including
already completed Tasks. No lifecycle transition is exposed.

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
No Subtasks are mutated. Future Subtask mutations must enforce the same bound
and owner serialization convention.

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
