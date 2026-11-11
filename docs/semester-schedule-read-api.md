# Stage 2: Semester Schedule Read API

Multi-teacher extension: occurrences additionally expose canonical `teacher_ids`
and `teachers: [{id, name}, ...]`, including empty lists, and the legacy-compatible
`teacher_id`/`teacher` scalar view. Teacher lists are ordered by ID. Weekly and
snapshot occurrences retain all associated teachers; room remains a string.
The earlier examples below omit these additive fields.

Stage 4 adds separate [management endpoints](semester-schedule-management.md). These original Stage 2 list/detail/date/week JSON contracts remain unchanged; new management responses supply the graph token needed for writes.

Stage 3 can populate these existing read endpoints through the separate [semester Excel import](semester-schedule-import.md). This read API contract and its legacy separation remain unchanged.

Baseline: `e25754c3b22f8b50c152b91fa3de0595efdef058` on `main`, verified against
GitHub before implementation. The [foundation rules](semester-schedule-foundation.md)
and [ADR-006](decisions/ADR-006-semester-schedule-foundation.md) remain authoritative.
See [verification commands and results](semester-schedule-stage2-verification.md).

## Routes and authorization

All routes use the existing first-party Sanctum SPA session/cookie authentication.
All are read-only and return 200 on success.

| Method | Path | Response |
| --- | --- | --- |
| GET | `/api/semester-schedules` | Owned schedules, ordered by ID ascending |
| GET | `/api/semester-schedules/{semesterSchedule}` | One owned schedule and academic context |
| GET | `/api/semester-schedules/{semesterSchedule}/date/{date}` | Effective occurrences on one local date |
| GET | `/api/semester-schedules/{semesterSchedule}/week/{date}` | Effective occurrences Monday through Sunday containing the date |

The index is an unpaginated `data` array, following the existing academic listing
convention. Each item has the same fields as detail. No owner, timezone, filter or
sorting query parameter is accepted as authority. IDs must be numeric; missing and
foreign schedule targets return the same generic 404. `SemesterSchedulePolicy`
authorizes targets before date validation, and the Service reloads through an
owned query rather than trusting route-bound attributes or cached relationships.

There is no new create/update/delete route. Saved rules and snapshots must already
exist, with the Stage 1 migration applied through the normal deployment process.
This stage adds no migration and performs no legacy data conversion.

## Detail and list schema

Example detail (`GET /api/semester-schedules/1`):

```json
{
  "data": {
    "id": 1,
    "academic_period": {
      "id": 3,
      "name": "I семестр 2026–2027",
      "starts_on": "2026-09-07",
      "ends_on": "2026-12-31"
    },
    "group_name": "ПІ-231",
    "education_institution": {"id": 2, "name": "Навчальний заклад"},
    "parity_anchor_on": "2026-09-07",
    "first_week_parity": "numerator"
  }
}
```

The index wraps zero or more of these objects in `data: []`. Institution comes
only through the owned AcademicPeriod. A missing/foreign period or institution
fails the entire response with a controlled 409; it is not silently replaced or
omitted. Owner IDs, import information, timestamps and other internal model fields
are excluded. No weekly rules or date snapshots are embedded in the detail.

## Date and week schema

Example date (`GET /api/semester-schedules/1/date/2026-09-08`):

```json
{
  "data": [
    {
      "occurrence_id": "semester:1:2026-09-08:rule:8",
      "occurs_on": "2026-09-08",
      "source": "base",
      "weekly_lesson_rule_id": 8,
      "schedule_date_exception_id": null,
      "pair_number": 4,
      "pair_label": "7-8",
      "subject": {"id": 17, "name": "Філософія", "code": null, "color": null},
      "teacher": {"id": 8, "name": "Багас Л.М."},
      "room": "122",
      "starts_at": "2026-09-08T13:20:00+03:00",
      "ends_at": "2026-09-08T14:40:00+03:00"
    }
  ],
  "meta": {
    "timezone": "Europe/Kyiv",
    "semester_schedule_id": 1,
    "start_date": "2026-09-08",
    "end_date": "2026-09-08"
  }
}
```

A week uses exactly the same occurrence schema in a single flattened `data` array.
Its metadata always describes the **requested** Monday–Sunday calendar interval,
not the intersection with the semester. For example, a week entirely after the
semester (`GET /api/semester-schedules/1/week/2027-01-06`) returns:

```json
{
  "data": [],
  "meta": {
    "timezone": "Europe/Kyiv",
    "semester_schedule_id": 1,
    "start_date": "2027-01-04",
    "end_date": "2027-01-10"
  }
}
```

For `week/2026-09-12`, the requested labels are 2026-09-07 through 2026-09-13.
If a working Saturday snapshot exists, its occurrence might be:

```json
{
  "occurrence_id": "semester:1:2026-09-12:snapshot:21",
  "occurs_on": "2026-09-12",
  "source": "working_saturday",
  "weekly_lesson_rule_id": null,
  "schedule_date_exception_id": 9,
  "pair_number": 1,
  "pair_label": "1-2",
  "subject": {"id": 12, "name": "Архітектура та проект ПЗ", "code": null, "color": null},
  "teacher": null,
  "room": "245",
  "starts_at": "2026-09-12T08:30:00+03:00",
  "ends_at": "2026-09-12T09:50:00+03:00"
}
```

`replacement` uses the same snapshot identity format. Provenance is the exception
ID, even when its lesson still references a source rule internally. A base
occurrence exposes its rule ID and a null exception ID. Optional teacher, room and
pair label remain explicit nulls. Subject always includes id/name/code/color;
teacher includes only id/name. No `id`, `type` or `lesson_type` is emitted for an
occurrence. Internal subject IDs and UTC epoch endpoints are not separately exposed. `SemesterScheduleOccurrenceResource` is independent of the unchanged
legacy `ScheduleLessonResource`.

Occurrence identity is stable across reads and metadata edits to the same source
row/date; switching base to snapshot or replacing a source row changes identity.
Occurrences are ordered by calendar date, then UTC start/end and identity within
the date. Empty dates have no placeholder entries; `data: []` represents an empty
day/week. Adjacent lessons remain separate occurrences, never merged.

## Calendar, parity and snapshots

The existing calculator is reused for every nominal date in the interval.
Its `weekDates()` helper computes ISO Monday–Sunday labels, independent of locale,
server timezone and elapsed UTC hours. Date validation requires a real, strict
four-digit `YYYY-MM-DD` date and a representable full week for week requests.
A route date overrides query `date`; timezone and owner query arguments are ignored.

The persisted User timezone is reloaded **inside the same read transaction** as
all schedule data and supplies both occurrence offsets and metadata. It is not
selected by the client or by modified attributes of a passed User model.

Semester dates are inclusive; dates outside a valid semester return no occurrences.
A week crossing a semester boundary includes only in-period occurrences without
clipping its requested metadata. Alternation uses the persisted Monday anchor
and configured numerator/denominator value, not ISO week-number parity.

A saved exception replaces the complete date. Empty snapshots cancel all pairs;
deleting the exception restores that date's base rules. A working Saturday reads
only its saved independent snapshot; no source-day recopy or other-Saturday change
occurs during GET. Rule edits do not propagate to frozen snapshots.

All original conflict and DST rules remain in the calculator. It checks the full
weekly template across both parity variants. Nonexistent or ambiguous local
endpoints fail explicitly. Unique endpoints spanning a Kyiv transition preserve
real elapsed time: 02:30–04:30 on 2026-03-29 is one hour, on 2026-10-25 three hours.
The entire date/week fails rather than returning a partial schedule.

## Consistency, performance and errors

`EffectiveScheduleService::forDate()` / `forWeek()` return `lessons` plus metadata.
The foundation `date()` list contract remains available, and `week()` provides a
matching internal list. These internal projections also carry owned subject and
teacher data; only the explicit Resource whitelist becomes public JSON.

A week performs one owned schedule/period/institution load, one complete rule
load and one range exception load, with batched subject, teacher, import and source
rule relationships. Rules are not reloaded for each date. Materialized arrays
carry catalogs into serialization, preventing queries after the read transaction.
The configured MySQL REPEATABLE READ isolation supplies one consistent snapshot;
a two-connection test commits profile, subject, rule and snapshot updates mid-read
and verifies that the first response is entirely old and the next entirely new.
The sparse/dense query test verifies bounded equal query counts for one rule versus
20 rules / seven exceptions, including lazy-loading prevention and unique identities.

The Service performs SELECT-only queries. GET does not create Lessons, copy
snapshots, repair broken associations, touch records or invoke AI. API tests compare
persisted rows before/after all four endpoints and inspect their SQL statements.

| Status | Meaning |
| --- | --- |
| 401 | Missing authentication |
| 404 | Missing/foreign target or unmatched route; `{"message":"Not found."}` |
| 422 | Invalid route date; Laravel `message` and `errors.date` |
| 409 | Inconsistent saved configuration, missing/foreign required associations, conflicts, DST gap/fold |
| 500 | Unexpected internal/persistence failure |

409 body:

```json
{"message":"The saved semester schedule configuration is inconsistent."}
```

500 body:

```json
{"message":"The semester schedule could not be processed."}
```

The new route-specific exception boundary does not expose SQL, bindings, paths,
traces or foreign names, including with `APP_DEBUG=true`. Invalid graphs are not
silently repaired or partly returned. This boundary does not change error handling
for any legacy route.

## Compatibility boundary

No changes to legacy schedule endpoints, `ScheduleService`, `ScheduleLessonResource`,
`LessonService`/CRUD, Excel parser/import commit, `FreeTimeService`, planning,
reminders, statistics, frontend or AI schemas/tools. Legacy `lessons` and semester
rules are not automatically combined. Write/import/UI integration and migration of
legacy semesters remain separate tasks. No migration or data reset is needed for
this stage beyond having the foundation already deployed.
