# Lesson and Schedule API

Stage 05 implements manual Lessons, cancellation/replacement lifecycle, and bounded schedule views. Every endpoint requires first-party Sanctum session/cookie authentication; see [auth/profile](auth-profile.md). Ownership and the presentation timezone come exclusively from the authenticated user.

## Routes and status codes

| Method | Route | Success |
|---|---|---|
| POST | /api/lessons | 201 |
| GET | /api/lessons/{lesson} | 200 |
| PATCH | /api/lessons/{lesson} | 200 |
| DELETE | /api/lessons/{lesson} | 204 |
| POST | /api/lessons/{lesson}/cancel | 200 |
| POST | /api/lessons/{lesson}/replacement | 201 |
| GET | /api/schedule/today | 200 |
| GET | /api/schedule/date/{date} | 200 |
| GET | /api/schedule/week/{date} | 200 |

There is no generic Lesson index or unbounded schedule endpoint. Unauthenticated calls return 401. Foreign concrete Lesson targets and normally bound soft-deleted Lessons return 404. Authorization precedes mutation payload validation. Invalid inputs, conflicts, and invalid lifecycle operations return Laravel's standard 422 validation response.

## Manual Lesson input and UTC detail representation

POST and replacement POST require positive strict integer `subject_id`, a `type`, `starts_at`, and `ends_at`. Optional nullable fields are positive strict integer `teacher_id` and `academic_period_id`, and string `room` (maximum 100 characters). PATCH is partial: omitted fields retain persisted values; explicit null clears optional fields. Subject cannot be null.

Types are `lecture`, `practical`, `laboratory`, `seminar`, `consultation`, `exam`, and `other`. Associations must belong to the same user, but need not share an institution. Missing and foreign association IDs yield the same generic validation error.

Input instants must be exactly `YYYY-MM-DDTHH:MM:SSZ`, with whole-second precision. Offsets, fractional seconds, timezone-less values, and surrounding whitespace are rejected. Persisted Lesson DATETIME values represent UTC. Complete candidates, including persisted PATCH counterparts, must satisfy `starts_at < ends_at`.

Lesson detail, mutation, and lifecycle responses use the standard `data` envelope:

```json
{
  "data": {
    "id": 123,
    "academic_period_id": 4,
    "subject_id": 17,
    "teacher_id": 8,
    "type": "lecture",
    "room": "301",
    "starts_at": "2026-10-06T07:30:00Z",
    "ends_at": "2026-10-06T09:00:00Z",
    "status": "active",
    "replaces_lesson_id": null,
    "replacement_id": null
  }
}
```

Internal ownership, import metadata, audit timestamps, and deletion metadata are not exposed or accepted as mutable manual fields. Generic PATCH cannot change status or replacement links. Legacy foreign association/link IDs are masked as null without repairing data during GET. An unrelated PATCH cannot perpetuate malformed Subject/Teacher/AcademicPeriod associations; the owner must explicitly repair them.

## Lifecycle and deterministic conflicts

New manual Lessons are active. Cancellation permits only `active -> cancelled`; repeated cancellation and cancellation of a replaced original return 422. Cancellation of a replacement leaves its original replaced.

Replacement permits only an active, non-deleted, ordinary original with no current replacement. In one transaction, the original becomes replaced and its replacement becomes active. Chains and self-replacement are rejected. Owner locks serialize mutations; Services re-read current persisted rows.

Overlap is `existing.starts_at < candidate.ends_at AND existing.ends_at > candidate.starts_at` for owned, active, non-deleted Lessons. Adjacency is allowed. Replacement creation excludes its original, which is about to become replaced. Conflicts return 422 under `schedule`; lifecycle failures use `status` or `replacement`. There is no override.

Normal DELETE soft-deletes. An original with a current replacement cannot be directly deleted. Deleting an active or cancelled replacement atomically soft-deletes it and restores the original to active. If restoring the original would conflict with another active Lesson, both rows remain unchanged. Thus cancellation removes the effective occurrence; replacement deletion undoes substitution.

A repeated replacement restores the same historical soft-deleted manual replacement ID rather than inserting a row that violates the unique original link. The new POST replaces its manual fields, including clearing omitted optional fields. Foreign, chained, or invalid historical state fails without mutation. History carrying import identity is not converted into a manual replacement.

## Bounded schedule views

Date/week route parameters must be strict, valid `YYYY-MM-DD` calendar dates. Route values override any query `date`; `user_id`, `owner_id`, and `timezone` query parameters cannot select an owner or timezone.

Today is the current calendar date in `users.timezone`. A date range runs from local midnight through the next local midnight, exclusive. A week uses the ISO Monday containing the anchor date through the next Monday, exclusive, regardless of locale. Local calendar boundaries are constructed first, then converted separately to UTC. Each boundary is resolved independently, including zones whose DST transition skips midnight. No fixed 24-hour day or 168-hour week is assumed: Kyiv transition days are 23 or 25 UTC hours, and transition weeks are 167 or 169 hours.

If an IANA date-line change skipped an entire local calendar date, its empty instant range returns an empty schedule with the requested date labels.

A Lesson appears when `starts_at < range_end_utc AND ends_at > range_start_utc`. Midnight-crossing occurrences are included; ending exactly at the start or starting exactly at the end is excluded. Only owned, active, non-soft-deleted Lessons appear. Active replacements appear, replaced originals do not. Cancelling a replacement leaves neither occurrence effective; reverting it returns the active original.

Ordering is exactly `starts_at ASC, ends_at ASC, id ASC`. Relevant Subject, Teacher, and original relations are eager loaded. GETs do not repair associations, change lifecycle, restore rows, or persist new data.

Schedule presentation uses a separate Resource. The same persisted UTC instants are rendered in the authenticated user's IANA timezone with the offset valid at each instant: `YYYY-MM-DDTHH:MM:SS±HH:MM`. Even UTC presentation uses `+00:00`; canonical Lesson detail still uses `Z`.

```json
{
  "data": [{
    "id": 123,
    "subject": {"id": 17, "name": "Software Design", "code": "SD", "color": "#3366FF"},
    "teacher": {"id": 8, "name": "John Doe"},
    "type": "lecture",
    "room": "301",
    "starts_at": "2026-10-06T10:30:00+03:00",
    "ends_at": "2026-10-06T12:00:00+03:00",
    "replaces_lesson_id": null
  }],
  "meta": {
    "timezone": "Europe/Kyiv",
    "start_date": "2026-10-06",
    "end_date": "2026-10-06"
  }
}
```

Teacher and room may be null. Subject code/color retain null keys when unset. A malformed foreign Subject or Teacher is represented as null; an unsafe or soft-deleted original link is null. No foreign names, codes, colors, or IDs are exposed. Status, replacement child ID, import identity, owner, and audit/deletion fields are omitted from presentation.

Metadata contains inclusive local calendar labels, not internal UTC query boundaries. For the week anchored at 2026-10-07, labels are 2026-10-05 through 2026-10-11. Empty day/week schedules return 200 with `data: []` and the same metadata.

## Remaining scope

Excel upload/parsing, preview/commit, fingerprint generation, duplicate resolution, import APIs, and imported Lesson reconciliation remain deferred. Task/Subtask workflows, free-time calculation, study planning/rescheduling, reminders, statistics, AI tools, and frontend work are also unimplemented. Schedule retrieval is not free-time calculation.
