# Single semester teacher and custom working Saturdays

Implemented on `main` from `6235635c9ddfaa79447feaa6789efc2706cabc5c`.

## Single-teacher contract

Every new, edited or copied nonempty semester lesson requires one positive owned
integer `teacher_id`. This scalar is canonical. A subject has one assigned teacher
in the user's semester catalog; a teacher may teach many subjects. An assignment
cannot be silently replaced, even by a different period or a date replacement.

Existing `subject_teacher`, `weekly_lesson_rule_teacher` and
`schedule_date_exception_lesson_teacher` tables are retained. The subject's unique
assignment is checked against catalog pivots and scalar rules/snapshots across all
of the owner's semesters, including scalar historical records without pivots.
Writes mirror a single teacher into compatibility pivots. No Subject column or
teacher schema migration is necessary. Existing Subject/Teacher Eloquent relations
remain available for inspecting historical links.

REST creation/full lesson replacement requires `teacher_id`; PATCH may omit it
when the saved lesson already has a valid teacher. `null` is rejected. Optional
`teacher_ids` must be a singleton equal to the canonical scalar (or the saved
scalar on a partial edit). It cannot change the teacher by itself. Nonempty input
`teachers` is prohibited. More than one supplied ID is rejected, even if repeated.
Resources retain singleton `teacher_ids`/`teachers` response aliases. Legacy
teacherless records remain readable, but editing/copying them requires a teacher.

Standard Laravel 422 errors identify `teacher_id`, `teacher_ids`, or the nested
lesson field at request validation. Business conflicts use `errors.teacher_id`:

```json
{
  "message": "The subject is already assigned to another teacher.",
  "errors": {"teacher_id": ["The subject is already assigned to another teacher."]}
}
```

## Import validation and history

`POST /api/semester-schedule-imports` still requires only `academic_period_id` and
`schedule_file`. Missing valid Subjects/Teachers are prepared in previews and
created only in the existing atomic confirmed commit. Catalog reuse, room strings,
source coordinates, token checking, 409 stale refresh, manual-rule protection,
suppressed slots and immutable receipt replay are retained. The anchor remains
Monday containing the owned period start, always numerator first.

Each nonempty source lesson must contain exactly one valid teacher name. Explicit
separators remain recognized so multiple teachers are rejected instead of combined
into a fabricated Teacher. The legacy dated XLSX parser/resolver is unchanged.
Preview status is `invalid`, `can_commit` is false, and each affected row retains
its `source.worksheet`, `source.rows` and `source.range`, with:

- `errors.teacher_name`: missing/multiple source names;
- `errors.teacher_names`: malformed names or ambiguous owned name matches;
- `errors.teacher_id`: conflicting teachers for one subject, including both sides
  of an incoming disagreement and conflicts with an existing catalog assignment.

Nine-field and ten-field historical baselines remain readable and unchanged.
Comparisons keep complete historical teacher lists while taking `teacher_id`
from the scalar; no first-teacher selection is performed. Committed imports replay
their original receipts without loading or changing the active schedule graph.

Multiple historical teachers on a subject/lesson, inconsistent scalar/pivot teacher
identities, or a pivot-only lesson without a canonical scalar require explicit
manual repair. Management/import/effective reads fail with a controlled 422, e.g.:

```json
{
  "message": "Manual repair required: Subject 11 has multiple historical teacher identities. No teacher relationships were changed.",
  "errors": {
    "semester_teacher_repair": ["Manual repair required: Subject 11 has multiple historical teacher identities. No teacher relationships were changed."]
  }
}
```

No relationships, receipts or baselines are automatically cleaned up or rewritten.
Catalog conflicts cover the owner's semesters, so they may block a different
period until explicitly repaired. This task did not audit a database: whether any
installed data requires repair is unknown. Historical multi-teacher data, if
present, requires a deliberate repair plan before these workflows can continue.

## Select source lessons using existing reads

`GET /api/semester-schedules/{id}/management` returns `data.weekly_rules` and
`meta.schedule_token`. `GET /api/semester-schedules/{id}/weekly-rules` returns the
same owned definitions with the token. Both include the rule ID, weekday, week
pattern, pair number/label, subject, teacher, room and local start/end times.

For weekday W and parity P, display rules with `weekday == W` and
`week_pattern == "all" || week_pattern == P`. This is a template selection,
independent of the target week's calendar parity and any weekday date replacement.
No new read endpoint is added. The target date, selection and token are revalidated
under the owner lock during the final write.

## Create a custom working Saturday

`POST /api/semester-schedules/{id}/working-saturdays`:

```json
{
  "schedule_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "occurs_on": "2026-11-14",
  "copied_weekday": 1,
  "source_week_parity": "numerator",
  "selected_weekly_rule_ids": [12, 14]
}
```

- `schedule_token`: existing lowercase 64-character graph token;
- `occurs_on`: valid Saturday inside the owned academic period;
- `copied_weekday`: integer Monday–Friday, 1–5;
- `source_week_parity`: numerator or denominator;
- `selected_weekly_rule_ids`: nonempty unique list of positive integer rule IDs,
  at most 255; each must belong to this semester and the eligible source set.

Both new fields must be supplied together. No lesson subject, teacher, time, room
or pair fields are taken from client input. The selected authoritative base rules
are copied into a frozen `working_saturday` snapshot with original rule provenance.
Only the target date needs to exist in the period in this template mode; no
out-of-period weekday occurrence is generated. Pair uniqueness, half-open interval
conflicts and ambiguous/nonexistent local endpoints are validated on the projected
semester before any writes. All writes and revision changes share one transaction.

Success retains the existing **201 management response** containing the updated
schedule, rules, exceptions, suppressed slots and refreshed `meta.schedule_token`.
Each created exception exposes:

```json
{
  "id": 9,
  "occurs_on": "2026-11-14",
  "kind": "working_saturday",
  "copied_weekday": 1,
  "source_week_parity": "numerator",
  "lessons": [
    {
      "id": 20,
      "pair_number": 1,
      "pair_label": "1-2",
      "starts_at_local": "08:30:00",
      "ends_at_local": "09:50:00",
      "subject": {"id": 11, "name": "Програмування в Інтернет", "code": null, "color": null},
      "teacher_id": 8,
      "teacher": {"id": 8, "name": "Когут В.С."},
      "teacher_ids": [8],
      "teachers": [{"id": 8, "name": "Когут В.С."}],
      "room": "231а",
      "source_weekly_rule_id": 12
    }
  ]
}
```

The example exception contains one lesson for brevity; the actual list contains
every selected lesson. Stale token or existing target exception returns **409**.
Invalid weekday/parity/IDs/date, missing teacher, subject conflict, duplicate pair,
overlap or invalid timezone endpoints returns **422**. Foreign schedules retain
existing 404 ownership behavior. Partial writes roll back.

## Compatibility mode and full-day editing

Omit both new Saturday fields to retain same-week effective-day copying. Both the
source weekday date and target Saturday must be in the period. Replacements and
empty cancellation are copied as before. Nonempty copies still require one valid
teacher. New compatibility snapshots save the calendar parity of the source date;
older snapshots keep `source_week_parity: null` without inferred backfill.

Continue using `PUT /api/semester-schedules/{id}/date-exceptions/{date}` with
`mode: "replace"` and a complete `lessons` list, or `mode: "copy_base"` with changes
and additional lessons. Any valid pair number 1–255 is supported, including newly
added pairs 3 or 5; every supplied full lesson requires an owned teacher_id. An
empty replacement list cancels the date. Existing snapshot IDs may be retained.
Saturday edits use replace mode and preserve kind, copied weekday and source
parity. Source metadata describes the original copy and does not change on edits.
The existing DELETE restores the current recurring day. Later template changes
never recopy frozen snapshots.

## Migration and verification

New additive migration:
`2026_11_11_000002_add_working_saturday_source_week_parity.php`.
It adds a nullable parity string with a MySQL CHECK requiring numerator/denominator
only on working Saturdays. Old snapshots stay null. Down refuses when populated
source metadata exists, preserving the deployment rollback safeguard. No old
migration, pivot, historical baseline or receipt is modified. The migration was
not applied during this task.

Only PHP syntax (`php -l`), formatting (`vendor/bin/pint` on changed PHP files) and
whitespace (`git diff --check`) checks were performed. No tests were created,
modified or run. Runtime/database behavior was not exercised. Frontend, legacy
Lessons/import, planning, AI and deployment configuration were not changed.

## Changed files

- `app/Http/Requests/SaveWeeklyLessonRuleRequest.php`
- `app/Http/Requests/StoreWorkingSaturdayRequest.php`
- `app/Http/Resources/ScheduleDateExceptionResource.php`
- `app/Http/Resources/SemesterScheduleLessonDefinitionResource.php`
- `app/Models/Concerns/HasSemesterTeachers.php`
- `app/Models/ScheduleDateException.php`
- `app/Services/Imports/SemesterScheduleImportPlanner.php`
- `app/Services/Imports/SemesterScheduleImportService.php`
- `app/Services/Schedule/EffectiveScheduleCalculator.php`
- `app/Services/Schedule/EffectiveScheduleService.php`
- `app/Services/Schedule/SemesterLessonTeacherService.php`
- `app/Services/Schedule/SemesterScheduleManagementService.php`
- `app/Services/Schedule/SemesterScheduleStateService.php`
- `database/migrations/2026_11_11_000002_add_working_saturday_source_week_parity.php`
- `docs/decisions/ADR-010-single-teacher-and-custom-saturdays.md`
- `docs/semester-schedule-import.md`
- `docs/semester-schedule-management.md`
- `docs/semester-schedule-read-api.md`
- `docs/single-teacher-and-working-saturdays.md`
