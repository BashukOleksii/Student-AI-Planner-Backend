# Calendar occupancy in study planning

This stage integrates semester classes into planning and supersedes the earlier
semester foundation/management statements that planning consumes only Lessons.
Legacy schedule presentation and the AI `get_schedule_for_date` tool remain on
their existing sources.

`CalendarBusyIntervalService::between` is a read-only, owner-scoped source of
half-open UTC class intervals. It reloads the persisted user timezone and delegates
semester dates to `EffectiveScheduleService::forRange` and the unchanged
`EffectiveScheduleCalculator`. No lessons, occurrences or sessions are synchronized,
materialized, deleted or rewritten by reads. All semester lessons fully block time.

## Source-selection policy

* A persisted SemesterSchedule is active within its owned AcademicPeriod's inclusive
  local dates; there is no active-schedule flag or frontend selection dependency.
* Every owned overlapping semester contributes its effective occurrences. An empty
  day snapshot cancels the day. Replacements/extras and frozen working Saturdays
  contribute only their actual saved intervals, including selected source parity.
  Current manually managed weekly rules are read normally.
* Outside that period, legacy active, non-deleted Lesson behavior is preserved.
  Within that period, suppress imported legacy occupancy **only for that same
  period**. Import provenance is `schedule_import_batch_id` or a retained
  `import_fingerprint`; period association alone never implies an import.
* Follow `replaces_lesson_id` to the owned historical original, including soft-deleted
  history: replacements derived from imported originals inherit that original's
  import/period provenance. Independently created manual Lessons and replacements
  of manual originals remain additional blockers, even with a semester period ID.
* Imported Lessons in unrelated periods remain occupied. Never choose one global
  semester or discard another period because its dates overlap. An imported
  interval crossing its authoritative period boundary retains its outside pieces.
* Broken ownership, missing/cyclic/chained replacement origins, incompatible import
  periods and invalid effective schedule relationships fail closed. Nothing is
  inferred from subject names, matching times or frontend state. Historical batch
  detachment still permits fingerprint provenance; missing provenance is treated
  conservatively as independent manual occupancy.

## Planning and identities

FreeTimeService and PlanningContextService share this source, including class
blockers, break anchors and scenario snapshots. Existing TimeIntervalService
subtraction merges overlapping occupancy and clips to allowed study windows;
minimum filtering and the 31-inclusive-local-date public limit remain unchanged.
Availability and period membership use timezone-aware expansion, never a fixed
24-hour local day. The calendar read can return clipped intervals, while planning
snapshots deliberately retain full source endpoints for stable cross-range
comparison and break distances.

Class source IDs are `legacy_lesson:<id>` (with deterministic endpoint segment
suffixes for period-boundary fragments), or the calculator's existing
`semester:<schedule>:<date>:rule|snapshot:<id>`. Records also retain source type and
relevant database identities. Numeric study-session IDs are unchanged. Scenario
validation permits the namespaced strings only in class collections; exclusions
continue requiring actual loaded owned planned-session integer IDs. Identical
class records can be combined across calendar chunks without query-clipping
collisions; changed source records still reject inconsistent snapshots.

Effective range reads load each selected schedule graph once for the whole range,
with bounded exception queries and eager-loaded catalogs/provenance. A 31-day read
does not execute 31 full graph reads. Lightweight owned schedule/period metadata is
selected by overlapping dates or the exact periods of selected legacy history; only
intersecting periods expand occurrences.
The internal limit of 128 local dates accommodates planning's 31 dates plus the
maximum persisted break on both sides. Public REST formats and limits are unchanged.

Allocation, work selection, effort, workload, deadlines and break calculations are
unchanged. Preview, confirmation, candidate evaluation, manual rescheduling,
adaptive replanning, unfinished-work recovery and workload advice inherit the same
calendar through the shared context. Existing confirmation reruns planning under
the owner lock and validates the batch before persisting. A stale overlapping
semester edit rejects the plan atomically; a cancellation can permit a fresh plan.
Existing sessions are never automatically moved after a timetable edit.

No schema, deployment, frontend, lesson-type or AI schedule-tool changes belong to
this stage. Existing cooperating owner-lock transactions protect confirmation
against concurrent semester management/import writes; direct database edits do
not participate in application locking.

## Focused verification

`SemesterPlanningCalendarTest` covers semester-only free slots and REST shape,
parity, current rule edits, cancellation, replacements/extras, selected-parity
Saturday snapshots, imported/replacement/manual coexistence, overlapping periods,
clipping, persisted timezone, DST, select-only reads, constant per-range query
counts, stale confirmation rollback, successful confirmation, replanning and manual
rescheduling conflicts, multi-range identity and inconsistent legacy ownership.
Existing focused PF1, C10, effective-calculator and scenario checks supplement it.
The complete PHPUnit suite, migration rollback tests, E2E and CI stabilization are
intentionally outside verification for this stage.
