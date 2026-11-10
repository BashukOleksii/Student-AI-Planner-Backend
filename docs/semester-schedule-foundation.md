# Semester schedule foundation

Stage 4 now writes this foundation through the [semester management API](semester-schedule-management.md). Original foundation-only scope statements below are historical. ADR-006 parity/snapshot rules remain authoritative; ADR-008 adds optimistic writes and explicit import protections.

Stage 3 now provides the separate [confirmed recurring import workflow](semester-schedule-import.md). Original foundation scope statements below are historical; parity/snapshot decisions remain unchanged, with additive import provenance defined in ADR-007.

Stage 2 now exposes the foundation through a separate [read-only semester API](semester-schedule-read-api.md), with batched consistent week reads. The scope statements below describe the original foundation stage; its domain decisions remain unchanged.

Implemented on 2026-11-10 against backend main
`5b7d138480c77a87219939930c2d3dcccf40d4c4`, verified with `git ls-remote`.
The initial local HEAD `f1fc757…` was fast-forwarded to this commit; the only
upstream difference was four README lines, with no backend code changes.
See [ADR-006](decisions/ADR-006-semester-schedule-foundation.md) and the
[verification report](semester-schedule-verification.md) for actual test commands
and measured results.

## Scope and compatibility

This is an additive domain foundation, with no new route, Form Request, CRUD
writer, import behavior, Resource, AI tool or frontend change. Existing
`ScheduleService`, `FreeTimeService`, Lesson APIs and import preview/commit still
operate on concrete `lessons`. They do not read these new tables yet.
`EffectiveScheduleService::date(User, SemesterSchedule, string): array` reads one
owned schedule for one local calendar date. It does not combine legacy Lessons,
study sessions, other semesters or availability windows. Integrating these sources
and validating their combined conflicts belongs to a later vertical stage.

Existing periods allow a null institution; existing Lessons require a type and
UTC instants; the old importer expands rules and anchors its first week as
numerator. Those contracts are preserved. New rules use local TIME columns,
explicit parity and no required type. The institution is derived exclusively
through `academic_period.education_institution_id`, with no second editable link.
A new-domain read fails if that institution is absent or belongs to another user.
Legacy nullable periods and their current APIs remain valid. There is no automatic
institution assignment, legacy conversion or read-side repair.

The available repository fixture is `tests/Fixtures/ScheduleImports/Приклад розкладу.xlsx`
(SHA-256 `278fcd21f10da37f9eededdccf638e041236fb1c61e0c7a37a329aee59a4ddc9`).
It was parsed directly: group ПІ-231, 16 weekly rules, Tuesday 7–8 alternates,
15 actual occurrences in each week. The separately named attachment
`Приклад розкладу (2).xlsx` was not present in the workspace, so byte identity
with it is not claimed. The existing parser and import flow are unchanged.

## Calendar and effective day decisions

- The academic period supplies inclusive `starts_on` / `ends_on`. Valid dates
  outside it return an empty list; malformed date labels fail explicitly.
- `parity_anchor_on` is a required, persisted Monday calendar label.
  `first_week_parity` specifies that anchor week's `numerator` or `denominator`.
  Every seven nominal calendar dates switches parity. The anchor can precede
  or follow a requested date; signed floor division works across year boundaries.
  ISO week number parity and elapsed UTC hours are never used. Future creation
  and import previews must show and confirm this anchor and parity explicitly.
- Weekly rules apply only Monday–Friday (`weekday` 1–5), with `week_pattern`
  `all`, `numerator`, or `denominator`. Pair numbers are positive 1–255; the
  supplied dialect uses 1–4 and optional labels `1-2`, `3-4`, `5-6`, `7-8`.
  Labels do not determine times, types or parity.
- A date exception replaces the **entire** day. Its independent saved lesson rows
  supply all effective pairs; an empty snapshot cancels the whole day. Omission
  of a pair cancels that pair. Removing the exception restores the base day.
  Subject/Teacher remain catalog references; their names are not copied text.
- `replacement` can describe any one calendar date, including a day without base
  lessons. `working_saturday` must be an actual Saturday and have a copied
  Monday–Friday weekday. On future preview, that weekday denotes the source date
  in the Saturday's Monday-first week, using that source date's parity. This
  foundation only **reads already saved** snapshots; it does not implement copying,
  preview or confirmation. On read it never recopies source rules, even if those
  rules changed or no longer exist. Other Saturdays remain empty.
- Snapshots are frozen local calendar values. Rule edits do not propagate into
  them. A User timezone change reinterprets all saved local times, including
  snapshots; the snapshot does not freeze an offset or UTC instant.

## Duplicate, conflict and time rules

`EffectiveScheduleCalculator` is a database-independent deterministic service used
by the owned reader and available for future writer preflight. It checks the
**complete weekly template**, for both parities and all five weekdays, even if a
snapshot overrides the requested day. It rejects malformed rules rather than
returning a partial schedule. Each effective day must have unique pair numbers
and non-overlapping intervals. Numerator/denominator alternatives may share a
pair/time, since they never occur together. `all` conflicts with either parity.
Neither exact duplicate rules nor competing effective pairs are silently merged.

Intervals are half-open `[start, end)`: ending exactly when another begins is
valid, overlapping by one second is invalid. Local times are strict whole-second
`HH:MM:SS`, `00:00:00 <= start < end < 24:00:00`. Overnight lessons and `24:00:00`
endpoints are deliberately unsupported in this foundation. Future overnight support
needs an explicit date-boundary design, not PHP normalization.

The reader reloads the trusted persisted User timezone and schedule through its
owned query; changed attributes or cached relationships on passed models cannot
select another timezone or graph. The calculator receives that IANA timezone.
Each local endpoint is matched against timezone offsets near that calendar date:
zero matching instants (DST gap/skipped date) or two matching instants (DST fold)
raise `InvalidArgumentException`. There is no silent time shift or arbitrary fold
offset selection. Unique endpoints spanning a transition are valid: for Kyiv,
02:30–04:30 lasts one real hour on 2026-03-29 and three on 2026-10-25. A gap/fold
error fails the date calculation; no partial output or persistence is produced.

Output is a sorted list of plain arrays, not synthetic Eloquent Lesson models:

- `occurrence_id`: `semester:{schedule_id}:{YYYY-MM-DD}:rule:{rule_id}` or
  `semester:{schedule_id}:{YYYY-MM-DD}:snapshot:{snapshot_lesson_id}`;
- `occurs_on`, `source` (`base`, `replacement`, `working_saturday`);
- nullable `weekly_lesson_rule_id`, nullable `schedule_date_exception_id`;
- `pair_number`, nullable `pair_label`, owned `subject_id`, nullable `teacher_id`, `room`;
- offset-bearing local `starts_at` / `ends_at` and integer UTC `start` / `end`.

Identity survives metadata/time edits to the same row on the same date. It changes
when switching base/snapshot or replacing a row's ID. Sorting uses UTC start, end,
then occurrence identity. This internal representation is not a new public REST
contract; catalog presentation belongs to future Resources.

## Persistence and enforcement boundaries

Migration `2026_11_10_000001_create_semester_schedule_foundation.php` creates:

| Table | Data and keys |
| --- | --- |
| `semester_schedules` | Owner, academic period, group, Monday anchor, anchor parity; unique `(user_id, academic_period_id)` |
| `weekly_lesson_rules` | Owner/schedule, weekday/pattern, pair/label, local TIME endpoints, required subject, nullable teacher/room/import source; unique `(semester_schedule_id, weekday, week_pattern, pair_number)` and lookup index |
| `schedule_date_exceptions` | Owner/schedule, DATE, kind, nullable copied weekday; unique `(semester_schedule_id, occurs_on)` |
| `schedule_date_exception_lessons` | Owner/schedule/exception, independent pair/label/times/subject/teacher/room, nullable source rule; unique `(schedule_date_exception_id, pair_number)` |

All tables have BIGINT UNSIGNED auto-increment IDs and Laravel timestamps, without
soft deletion. No denormalized occurrence rows are stored. No lesson type is
needed at this stage. Models have their parent/catalog/inverse relationships and
integer/date casts; user-scoped schedule lookup is the authorization boundary.

New composite FKs enforce owner equality for period, schedule, subject, teacher,
import provenance and exception children. Nullable teacher/import provenance
still enforce ownership when non-null. Snapshot source rule must belong to the
same schedule, including when two schedules have the same owner. Redundant
`user_id` and snapshot `semester_schedule_id` exist specifically to express these
constraints. Added `(id, user_id)` candidate keys on `academic_periods`, `subjects`,
`teachers`, `schedule_import_batches` support them; existing rows, columns and
legacy FKs are unchanged. Candidate keys cannot create a duplicate-data failure
because `id` is already unique.

MySQL CHECKs enforce weekday, patterns, Monday anchor, Saturday kind/copy shape,
positive pair and single-date local time ranges. SQL UNIQUE cannot detect all
cross-pattern or time overlaps: the calculator enforces these. Cross-row semester
date bounds, required owned period institution and import **period** compatibility
are application invariants; the reader validates them or bounds its output, and
future writers must validate them transactionally before saving. Direct SQL can
still create a semantically invalid graph; it will not be exposed as a valid day.
The reader does not mutate or heal it. Same-owner catalog entries need not share
the period's institution, consistent with the existing academic rules.

Parent schedule/exception children cascade; period, catalog and provenance FKs
restrict deletion. Nullable provenance must be explicitly detached before deleting
its referenced rule/import; frozen lesson fields survive detachment. This avoids
a composite `SET NULL` nulling required owner/schedule columns. New-domain writers
and later academic API integration must add friendly dependency handling and
owner-first locking; no new writer is exposed here. Existing account hard-purge
ordering remains a separate task. There are no model events hiding validation or writes.

Reads run in a transaction using the configured MySQL isolation (default
REPEATABLE READ) and execute only SELECTs; no write, locks, touch, materialization,
repair, AI call or implicit creation occurs. Future writers must serialize and
revalidate the complete candidate graph; calculator validation alone is not a
concurrency control mechanism.

## Deployment and next stage

Apply the additive migration with `php artisan migrate` using the deployment's
normal backup/review process. It does not backfill or modify legacy Lesson/import
rows. No migration was applied to the application development database during this
task. `down()` refuses before DDL if any semester exists; an empty-domain rollback
removes only these new tables/keys. Production changes after adoption require a
forward migration. MySQL DDL is not transactional, as with the existing migrations.

No forward **data** migration is necessary to retain current behavior. Adopting
legacy semesters later needs an explicit, tested forward data migration or
confirmed re-import and a policy separating legacy one-off Lessons from recurring
rules. Do not infer recurring identity from every old Lesson automatically.
Do not delete applied migrations, run `migrate:fresh` against retained data, or
silently remove old Lessons. Import, REST, frontend, planning/AI integration and
legacy transition are separate authorized tasks.
