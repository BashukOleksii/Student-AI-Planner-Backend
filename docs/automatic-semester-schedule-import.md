# Automatic semester schedule import implementation

Implemented on main from baseline `295bf5c`.

Upload `POST /api/semester-schedule-imports` requires only `academic_period_id`
and `schedule_file`. Server-derived `parity_anchor_on` is the Monday containing
the owned period start; `first_week_parity` is always numerator. Client parity
settings are ignored, including on service entry. A period starting 2026-09-01
uses 2026-08-31, with occurrences restricted to the period.

`preview.catalog.subjects` / `teachers` expose unique records with
`resolution: reuse|create|invalid`, names, nullable IDs and errors. Lesson rows
include all teacher names/objects/IDs, room, source coordinates and existing
operations/errors. Pending IDs are null in public previews. Missing valid names
permit a ready preview. Commit uses the same confirmed token flow and transaction;
`receipt.catalog` returns positive final IDs with `created|reused` dispositions.
The preview remains the historical proposal. Existing durable receipts replay
without modification, and stale confirmation still returns 409.

Migration: `2026_11_11_000001_add_semester_lesson_teachers.php`.
It creates `subject_teacher`, `weekly_lesson_rule_teacher` and
`schedule_date_exception_lesson_teacher`, with unique pairs and composite owner
foreign keys. Existing scalar teacher links are backfilled without rewriting
historical baselines. Complete teacher lists enter new baselines and concurrency
fingerprints. Weekly rule identity stays independent of teacher count.

Manual rule and snapshot writes accept `teacher_ids`, including `[]`; it takes
precedence over `teacher_id`. Legacy scalar patches remain supported. Schedule
Resources retain the scalar view and add canonical `teacher_ids` / `teachers`.
Date replacements and working Saturdays copy all teachers into independent
snapshot pivots. Subject teaching relationships are additive.

No tests were written or executed. PHP syntax checks (`php -l`), formatting
(`vendor/bin/pint` on changed PHP files) and `git diff --check` passed. The migration
was not applied to a database during this task, and runtime/database behavior was
not exercised. No deployment configuration or frontend files changed.

## Changed files

- `app/Http/Requests/SaveScheduleDateExceptionRequest.php`
- `app/Http/Requests/SaveWeeklyLessonRuleRequest.php`
- `app/Http/Requests/StoreSemesterScheduleImportRequest.php`
- `app/Http/Resources/SemesterScheduleImportResource.php`
- `app/Http/Resources/SemesterScheduleLessonDefinitionResource.php`
- `app/Http/Resources/SemesterScheduleOccurrenceResource.php`
- `app/Models/Concerns/HasSemesterTeachers.php`
- `app/Models/ScheduleDateExceptionLesson.php`
- `app/Models/Subject.php`
- `app/Models/Teacher.php`
- `app/Models/WeeklyLessonRule.php`
- `app/Services/Imports/ScheduleOccurrenceResolver.php`
- `app/Services/Imports/ScheduleWorkbookParser.php`
- `app/Services/Imports/SemesterScheduleImportPlanner.php`
- `app/Services/Imports/SemesterScheduleImportService.php`
- `app/Services/Schedule/EffectiveScheduleCalculator.php`
- `app/Services/Schedule/EffectiveScheduleService.php`
- `app/Services/Schedule/SemesterLessonTeacherService.php`
- `app/Services/Schedule/SemesterScheduleManagementService.php`
- `app/Services/Schedule/SemesterScheduleStateService.php`
- `database/migrations/2026_11_11_000001_add_semester_lesson_teachers.php`
- `docs/automatic-semester-schedule-import.md`
- `docs/decisions/ADR-009-automatic-semester-import-and-teachers.md`
- `docs/semester-schedule-import.md`
- `docs/semester-schedule-management.md`
- `docs/semester-schedule-read-api.md`
