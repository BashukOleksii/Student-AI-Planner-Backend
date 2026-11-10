# Stage 4: Semester schedule management API

Implemented against `main` at `9a00da6abcaa43d7acd67f534c3b4ececee0b86b`.
[ADR-008](decisions/ADR-008-semester-schedule-management.md) records the decisions
made before schema/import reconciliation changes. See [verification](semester-schedule-stage4-verification.md)
for changed files, commands and measured results.

All routes use existing Sanctum authentication. Legacy concrete Lesson/schedule/
Excel contracts and [Stage 2 reads](semester-schedule-read-api.md) are unchanged.
No frontend, AI, free-time/planner, reminder or statistics integration is added.

## Endpoints and response contracts

Let `S = /api/semester-schedules/{semesterSchedule}`. IDs are positive integers.

| Method | Path | Success |
| --- | --- | --- |
| POST | `/api/semester-schedules` | 201 management state |
| PATCH | `S` | 200 management state |
| DELETE | `S` | 200 deletion message, only an empty schedule without import history |
| GET | `S/management` | 200 complete management state + token |
| GET | `S/weekly-rules` | 200 definition list + token |
| GET | `S/weekly-rules/{weeklyRule}` | 200 single definition + token |
| POST | `S/weekly-rules` | 201 management state |
| PATCH | `S/weekly-rules/{weeklyRule}` | 200 management state |
| DELETE | `S/weekly-rules/{weeklyRule}` | 200 management state |
| GET | `S/date-exceptions` | 200 snapshot list + token |
| GET | `S/date-exceptions/{date}` | 200 one snapshot + token, 404 if none |
| PUT | `S/date-exceptions/{date}` | 200 management state, explicit create/replace |
| DELETE | `S/date-exceptions/{date}` | 200 management state; restore current base day |
| POST | `S/working-saturdays` | 201 management state |
| GET | `S/suppressed-slots` | 200 suppression list + token |
| DELETE | `S/suppressed-slots/{suppressedSlot}` | 200 management state; explicit release |

`date` is a real `YYYY-MM-DD` route label. Query/body `date` cannot override it.
Exception PUT and working-Saturday creation require an in-period date, inclusive.
Existing Stage 2 reads outside the period still return an empty occurrence list.
PATCH does not support moving a schedule to another academic period.

Every successful mutation except deleting the schedule returns its complete,
consistent *resulting* management state. Lists/single-child GETs use the same
resources below, wrapped in `data`, with the same top-level `meta`. Definition
lists are ordered by persisted ID, not calculated occurrence start time.

Example management response after manual creation (subsequent mutations populate
its three child lists):

```json
{
  "data": {
    "schedule": {
      "id": 1,
      "academic_period": {"id": 3, "name": "I семестр", "starts_on": "2026-09-07", "ends_on": "2026-09-20"},
      "group_name": "ПІ-231",
      "education_institution": {"id": 2, "name": "Університет"},
      "parity_anchor_on": "2026-09-07",
      "first_week_parity": "numerator"
    },
    "weekly_rules": [],
    "date_exceptions": [],
    "suppressed_slots": []
  },
  "meta": {
    "schedule_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "semester_schedule_id": 1,
    "timezone": "Europe/Kyiv"
  }
}
```

Tokens in examples are placeholders. Use the actual returned token.

## Optimistic confirmation and errors

All mutations of an existing schedule require JSON `schedule_token`, exactly the
64-character lowercase hex token from the latest management/list/detail-child
response. POST creating a new schedule has no existing token. Every DELETE also
requires strict JSON boolean `confirmed: true` (strings/numbers are rejected).
PUT is an explicit full snapshot replacement, guarded by the token.

The token is computed from current owned period/institution, persisted timezone,
all owned catalogs, schedule, complete rules, provenance/manual flags, all dated
snapshots/children and suppressed slots. Unrelated catalog changes deliberately
invalidate it too. There is no client-selected timezone/owner, cache expiry or
clock-based optimistic comparison. A monotonic persisted schedule revision prevents
edit-then-revert from reviving a previous token, even within the same timestamp
second. Actual management/import writes increment it; no-op patches and identical
import commits preserve it.

Each write locks/reloads owner first, then the same authoritative graph used by
imports, and compares the token *before* any domain write. Two edits using the same
old token cannot silently overwrite each other. A stale response is HTTP 409:

```json
{"message": "The semester schedule changed. Reload its management state before retrying."}
```

Reload management state and reconcile the user's desired edit; do not blindly
retry with a new token. Failed/stale writes do not partially mutate the graph.
Rules/metadata no-op patches preserve IDs, timestamps and import ownership.
Snapshot PUT is a bounded full-day rewrite, so retain supplied child IDs when
occurrence identity matters. It need not be a timestamp-level no-op.

- 401: unauthenticated.
- 404: missing/foreign schedule, or a rule/suppression not in that owned schedule;
  nested ownership is checked before payload validation. Missing dated snapshot
  GET/DELETE also returns 404. Response: `{"message":"Not found."}`.
- 422: Laravel `message` + `errors` for missing token/confirmation, invalid scalar
  fields, foreign catalogs/period/institution, dates, dependencies or projected
  conflicts/DST endpoints. No partial writes occur.
- 409: stale graph token, or POST attempting to overwrite an existing Saturday
  exception (edit its existing snapshot explicitly).
- 500: unexpected persistence failure, with only
  `{"message":"The semester schedule could not be processed."}` even in debug.

Management GETs are SELECT-only consistent snapshots. They validate ownership and
provenance but expose raw owned definitions even when a profile/date change made
their calendar projection invalid. This allows a valid repair mutation. Every
write validates the *complete candidate* through EffectiveScheduleCalculator,
including all frozen snapshots and timezone transitions anywhere in the semester.
Broken ownership/institution/provenance dependencies must be repaired first.
Stage 2 still fails inconsistent effective calendar reads with its existing 409.

## Manual semester and weekly rules

POST `/api/semester-schedules`:

```json
{
  "academic_period_id": 3,
  "group_name": "ПІ-231",
  "parity_anchor_on": "2026-09-07",
  "first_week_parity": "numerator"
}
```

All four fields are required. Period must be owned, have an owned institution and
not already have a schedule. Group is a nonempty string up to 255 characters.
Anchor is a Monday; it need not equal the period start. PATCH accepts any subset
of group/anchor/first parity plus `schedule_token`; period reassignment is rejected.
The original signed calendar-week anchor calculation remains authoritative.

POST `S/weekly-rules`:

```json
{
  "schedule_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "weekday": 1,
  "pair_number": 1,
  "pair_label": "1-2",
  "week_pattern": "all",
  "starts_at_local": "08:30:00",
  "ends_at_local": "09:50:00",
  "subject_id": 12,
  "teacher_id": 8,
  "room": "245"
}
```

Required: weekday integer 1–5, pair integer 1–255, pattern `all|numerator|denominator`,
strict local `HH:MM:SS` start/end, owned integer subject ID. Optional nullable:
label (32 characters), owned teacher ID, room (100). PATCH uses the same fields,
all optional, plus required token. Omitted optional fields survive a PATCH.
No lesson type is required or inferred. Owner/import/baseline fields are not writable.

Definitions expose:

```json
{
  "id": 4,
  "weekday": 1,
  "week_pattern": "all",
  "pair_number": 1,
  "pair_label": "1-2",
  "starts_at_local": "08:30:00",
  "ends_at_local": "09:50:00",
  "subject": {"id": 12, "name": "Архітектура ПЗ", "code": null, "color": null},
  "teacher": {"id": 8, "name": "Викладач"},
  "room": "245",
  "is_manually_managed": true,
  "semester_schedule_import_id": null
}
```

Intervals are half-open; adjacency is valid. Duplicate effective pairs and
intersecting intervals are rejected for both parity weeks, including `all` versus
either alternating rule. Overnight, `24:00:00`, DST gaps and folds are rejected;
unique endpoints spanning transitions retain the calculator's correct offsets.
Same-owner catalogs need not share the period's institution, preserving the domain.

Weekly edits change ordinary matching dates throughout the semester. Frozen date
snapshots do not inherit them. Metadata parity changes also affect ordinary dates'
membership, including protected manual rules; snapshots remain independent.

## One-date replacement, addition and cancellation

PUT `S/date-exceptions/2026-09-07` can copy the recurring **base** day and apply
partial changes in the same transaction:

```json
{
  "schedule_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "mode": "copy_base",
  "changes": [
    {"weekly_lesson_rule_id": 4, "subject_id": 17, "teacher_id": null, "room": "122"},
    {"weekly_lesson_rule_id": 5, "cancelled": true}
  ],
  "lessons": [
    {"pair_number": 5, "starts_at_local": "15:00:00", "ends_at_local": "16:00:00", "subject_id": 12}
  ]
}
```

`changes` is optional. IDs must be unique rules effective on that exact *base*
date/parity. Each entry accepts optional lesson fields or strict `cancelled`;
unmentioned pairs are copied unchanged. `lessons` optionally adds complete extra
pairs. The result becomes a full snapshot; later base edits do not propagate.
Re-copying an existing replacement explicitly resets it from current base.
`copy_base` is rejected for an existing working Saturday; edit its saved list.

For a fully specified snapshot, PUT with `mode: replace` and a **present** `lessons`
array (empty is valid). Each lesson has the weekly lesson fields except weekday/
pattern. Optional positive `id` must belong to that exact saved snapshot; it retains
that child's ID, creation time and source reference. Omit ID for a new child.
Omitted old rows are removed. Changes in subject/teacher/time/pair are supported,
including pair swaps; every projected day must still be conflict-free.

Cancel the entire date:

```json
{
  "schedule_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "mode": "replace",
  "lessons": []
}
```

GET `S/date-exceptions/2026-09-07` after cancellation:

```json
{
  "data": {"id": 9, "occurs_on": "2026-09-07", "kind": "replacement", "copied_weekday": null, "lessons": []},
  "meta": {
    "schedule_token": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
    "semester_schedule_id": 1,
    "timezone": "Europe/Kyiv"
  }
}
```

Nonempty snapshot lessons expose id/pair/label/local times/subject/teacher/room and
nullable `source_weekly_rule_id`, without weekday/pattern/import flags. Client
source references and foreign snapshot IDs are rejected. Child fields stay frozen,
while catalog names remain references. Rewriting a full list preserves supplied
owned IDs; omit IDs to deliberately create new occurrence identities.

DELETE the exception with `{ "schedule_token": "...", "confirmed": true }` to
restore the **current** recurring day. Restoring is rejected if that projected day
would now be invalid (for example a previously suppressed DST gap).

## Working Saturdays

POST `S/working-saturdays`:

```json
{
  "schedule_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "occurs_on": "2026-09-12",
  "copied_weekday": 5
}
```

Target must be an in-period Saturday; source weekday must be integer 1–5. Resolve
the source date in that Saturday's Monday-first week (here Friday 2026-09-11).
Source must also lie inside the period. Resolve its *effective* schedule, including
its parity and any replacement/empty cancellation, using the shared calculator.
Copy local lesson values and valid nullable rule references into an independent
Saturday snapshot with `kind: working_saturday` and the selected copy marker.
The source snapshot's own child IDs are never reused on Saturday.

Edit the Saturday using `PUT S/date-exceptions/2026-09-12`, `mode: replace` and its
saved child list/IDs. Kind/copy marker remain unchanged. Delete it through the same
exception DELETE. Other Saturdays and source weekdays do not change. Later source
edits do not recopy anything. An empty source produces a valid empty Saturday.

## Import reconciliation and intentional deletion

Manual rule edits keep their IDs and original import key/baseline/provenance.
An actual edit sets permanent `is_manually_managed: true`; restoring old values
later does not silently return import control. No-op patches do not set the flag.
Identical protected rows can satisfy a new import without adoption; differing
values remain a blocking `protected_rule` preview error.

Manual deletion creates a persisted suppression for the current slot, and also
its original imported slot if different. Moving an imported identity suppresses
its original slot immediately. A suppression blocks the same weekday/pair for any
intersecting pattern (`all` intersects both variants). Opposite alternating
patterns remain independent. Manual creation/movement into a blocked slot is also
rejected. Import-driven ordinary reconciliation deletions do not create tombstones.

A blocked workbook row has `operation: suppressed`,
`errors.suppressed_slot: [...]` and `suppressed_slot_ids: [...]`. It contributes no
successful create/update operation. `preview.impact.suppressed_slots` lists current
IDs and slot fields. A preview with such errors cannot commit.

To intentionally permit recreation, GET `S/suppressed-slots` and explicitly DELETE
its selected ID with token + strict confirmation. This releases only that record;
it creates no lesson, erases no receipt and does not overwrite a protected row.
Then revalidate the import and review/confirm its new token. Any remaining
intersecting suppressions/protected-rule differences must be resolved too.

All affected management mutations stale pending Stage 3 import previews. Stage 3
rechecks the same locked graph and refreshes the preview with HTTP 409 before any
schedule write. Deployment also conservatively stales old uncommitted previews
because the graph fingerprint includes the new protection fields/table. Committed
same-token retries still replay the historical receipt without domain writes;
receipt rule IDs describe the historical commit and can refer to later-deleted rows.

Deleting a weekly rule explicitly detaches only owned frozen lessons' nullable
source references, preserving their IDs and independent values. If a later write
fails, suppression, detachment and deletion roll back together. Pair-swap snapshot
rewrites delete/reinsert their bounded child rows inside one transaction while
preserving supplied existing IDs; no intermediate state is visible to readers.

## Schedule deletion, deployment and limits

Schedule DELETE requires confirmation + token and rejects dependencies with 422.
Rules, snapshots and suppressed slots must first be removed through their explicit
APIs. **Any semester import history for that academic period blocks schedule
removal**, including uncommitted previews, so receipts cannot silently lose their
schedule identity. There is no history-purge/cascade API in this stage.

Apply additive migration
`2026_11_10_000003_add_semester_schedule_management_protections.php` through the
normal `php artisan migrate` deployment process. It adds a monotonic schedule revision, a default-false weekly
manual flag/CHECK and an owned suppression table with unique slot, composite FK
and weekday/pair/pattern CHECKs. It infers no historical deletions or manual edits.
Existing baseline-drift protection still applies. Down refuses if revision history, flags or
suppressions would be lost; retained corrections require forward migrations.
No migration was applied to the retained application database during this task.

Limits: no bulk weekly edit, historical purge, automatic legacy conversion,
implicit suppression release or force-import override. Manual rules can use pairs
1–255; the existing workbook dialect still maps its four pair labels to 1–4.
The new semester domain remains independent of concrete Lessons and legacy
planning/AI consumers. Account/catalog lifecycle improvements outside these APIs
remain separate work. Stage 4 ends at Backend management.
