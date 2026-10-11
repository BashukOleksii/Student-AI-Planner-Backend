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

## Import A: workbook parser foundation

`App\Services\Imports\ScheduleWorkbookParser::parse(string $path)` uses
PhpSpreadsheet directly to return a weekly template without booting Laravel or
accessing the database. The real `tests/Fixtures/ScheduleImports/Приклад розкладу.xlsx`
regression fixture yields group `ПІ-231` and exactly **16 weekly rules**.

The supported dialect has a `Група` header and five contiguous Monday–Friday
blocks, each with four four-row pair slots. Detection validates the relative
header/weekday/pair merge geometry and the presence of time cells; the table may
be shifted on the worksheet. Only the detected six-column table is interpreted.
The explanatory examples on the right of the real fixture are ignored. Multiple
supported regions are rejected as ambiguous; unsupported geometry/content and
semantic formulas raise `ScheduleWorkbookParserException` with cell context
where available. Formulas are never evaluated. This is a known dialect parser,
not a general spreadsheet importer.

The returned associative array contains `group`, workbook `source` (`worksheet`,
`range`), and `rules` in weekday/pair order. Each rule contains:

- `weekday`: ISO integer 1–5;
- `pair_label`: workbook label, such as `1-2`;
- `starts_at_local`, `ends_at_local`: local `HH:MM:SS` read from numeric Excel day
  fractions or explicit `HH:MM[:SS]` cells, without a timezone;
- `parity`: `all`, `numerator`, or `denominator`;
- `subject_name`, `teacher_name`, `room`: whitespace-normalized workbook text,
  preserving Ukrainian spelling and Cyrillic room suffixes; empty room is null;
- `source`: `worksheet`, inclusive 1-based content `rows`, content `range`, and
  the containing `pair_range`.

An ordinary slot has subject/teacher rows in its middle two rows. An empty slot
emits no rule. A slot with four content rows has two subject/teacher pairs:
upper is numerator, lower is denominator (Tuesday `7-8` in the real fixture).
Only expected merge anchors are read; values are never globally filled down.
No lesson type is present or inferred.

Parser tests use plain PHPUnit and require no MySQL:
`vendor/bin/phpunit tests/Unit/ScheduleWorkbookParserTest.php`.
PhpSpreadsheet requires its Composer-declared PHP extensions, including GD and ZIP.

## Import B1: transient calendar occurrences

`ScheduleOccurrenceMaterializer::materialize(array $template, AcademicPeriod $academicPeriod, string $timezone)`
expands the Import A weekly template into concrete occurrence candidates. The
caller supplies a loaded, owned AcademicPeriod and the user's persisted IANA
timezone; the materializer itself performs no ownership lookup or database access.

Expansion uses the inclusive `starts_on..ends_on` calendar interval. The ISO week
containing `AcademicPeriod.starts_on` is **numerator**, even for a partial first
week. Subsequent weeks alternate by their offset from that Monday, independently
of numeric ISO week numbers and year boundaries. `all` rules match every week.

Calendar arithmetic uses timezone-independent date labels. Each start and end
wall-clock time resolves independently through the supplied IANA timezone's
transitions, then becomes a whole-second UTC `YYYY-MM-DDTHH:MM:SSZ` instant.
Nonexistent times (gaps/skipped dates), ambiguous times (folds), invalid inputs,
and non-positive instant intervals raise `ScheduleOccurrenceMaterializationException`;
the service never silently shifts a lesson or chooses a fold offset.

The result contains unchanged `group` and workbook `source`, plus `occurrences`.
Each occurrence preserves the parser's `weekday`, `pair_label`, rule `parity`,
subject/teacher names, nullable room, local times, and `source`, and adds
`local_date`, `starts_at_utc`, `ends_at_utc`, and `type: LessonType::Other` (serialized
as `other`). The source lesson type remains unknown; Other is solely the technical
fallback required by the Lesson domain. Ordering is local date/start/end, then
worksheet/source rows/ranges and literal metadata to break ties deterministically.

The real workbook for `2026-09-07..2026-09-20` in `Europe/Kyiv` produces **30
occurrences**: 15 in each week. Tests use in-memory AcademicPeriod attributes and
plain PHPUnit, including partial weeks, year boundaries, DST changes and gaps/folds:
`vendor/bin/phpunit tests/Unit/ScheduleOccurrenceMaterializerTest.php`.

B1 candidates remain transient and have not yet undergone entity resolution or
duplicate/conflict validation. B1 produces no batch, row, preview row number,
Subject/Teacher ID, fingerprint or Lesson.

## Import B2: exact references and fixed fingerprints

`ScheduleOccurrenceResolver::resolve(User $user, AcademicPeriod $academicPeriod, array $materialized)`
reloads the period through the user's scoped relationship and rejects foreign or
missing periods with a generic `academic_period_id` validation error. It resolves
distinct imported Subject/Teacher names in batches using only owned records.
Final matching uses strict PHP string equality against the normalized Import A
name: MySQL case/accent/space-insensitive collation cannot redefine exactness.
Persisted names are not normalized or renamed. No fuzzy matching, code/alias
matching, LLM resolution, institution preference or automatic creation is used.

An exact owned Subject supplies its ID. An exact unique owned Teacher supplies
its ID; zero matches are missing, multiple exact matches are ambiguous. A supplied
teacher name is never silently discarded. Subject/Teacher institutions need not
match the period's institution. Resolution makes no database mutations.

The result preserves `group`, workbook `source`, and occurrence order/provenance,
and adds trusted `academic_period_id` at workbook and occurrence levels. Each
candidate also receives nullable `subject_id`, `teacher_id`, `fingerprint`, and
`resolution_errors` (field names mapped to lists of stable messages). Incoming
ownership/association IDs, fingerprints, status and row numbers are ignored.
Missing Subjects produce null fingerprints; missing/ambiguous Teachers retain
fingerprints when their Subject resolves. No `ScheduleImportRowStatus` is assigned.

`ScheduleImportFingerprint::generate(int $subjectId, string $startsAtUtc, string $endsAtUtc): string`
implements the unchanged [fixed V1 contract](database-schema.md#fixed-v1-import-fingerprint-contract):
positive Subject ID as an ASCII decimal string plus strict whole-second UTC start
and end, in that ordered JSON array, hashed with lowercase SHA-256. Non-positive
IDs, malformed instants or non-increasing intervals raise `InvalidArgumentException`.
Teacher, room, type, Subject name/code and all workbook metadata are excluded;
renaming a persisted Subject preserves identity. Bad core timestamp input is
rejected rather than hashed; B1 normally guarantees valid timestamps already.

The real workbook pipeline produces **30** fully resolved candidates for the B1
two-week Kyiv fixture period when exact owned academic references exist. Resolver
Feature tests cover read-only batching, ownership, strict matching and ambiguity;
fingerprint Unit tests require no database. B2 does not classify duplicates or
conflicts; that is the B3 responsibility below. Persisted preview and commit
remain deferred. Excel import is not complete.

## Import B3: transient identity and projected schedule classification

`ScheduleImportCandidateClassifier::classify(User $user, AcademicPeriod $academicPeriod, array $resolved)`
reloads the owned period, preserves B2 fields/order, and adds `status`
(`ScheduleImportRowStatus`), nullable `operation`, `validation_errors` and nullable
`matched_lesson_id`. It performs read-only queries and creates no staging records.
Resolution errors immediately become Invalid and are copied into validation
errors; existing Lessons cannot rescue an unresolved reference.

Identity remains `(user_id, academic_period_id, fingerprint)`, loaded in a batch
with `withTrashed()`. A missing import-batch FK does not remove imported identity.
Source-authoritative comparison covers Subject/period/Teacher IDs, room and UTC
start/end only. Excel provides no type: type-only differences cause no update.
The B2 candidate `type` remains unchanged, but future updates/restores **must
retain the existing Lesson type**, including manual corrections. New creations
use the B1 technical Other fallback.

| Matching history | Transient status | Operation |
|---|---|---|
| No imported identity | valid | create |
| Active, source-authoritative values equal | duplicate | noop |
| Active, source-authoritative values changed | valid | update |
| Non-deleted cancelled/replaced | valid | preserve |
| Soft-deleted identity, regardless of former status | valid | restore |
| Resolution, contradictory identity or schedule errors | invalid | null |

Resolution-clean rows sharing a fingerprint are grouped before conflict checks.
The first row in B2's deterministic order is canonical. Identical authoritative
data produces later duplicate/noop rows; type/provenance differences are ignored
for this comparison. Contradictory Teacher/room/other authoritative values make
the entire identity Invalid under `import_identity`, without choosing a winner.

Each create/update/restore and active noop identity supplies exactly one projected
interval. Preserve supplies none and leaves current replacements untouched.
All represented active matched rows are excluded from external blockers together:
manually moved/swapped histories are checked at their proposed source positions.
Current owned, active, non-deleted Lessons across all periods block normally.
Manual Lessons (null fingerprint), including exact Subject/interval matches,
are conflicts and are never silently adopted as imported duplicates.

Overlap is `a.start < b.end AND a.end > b.start`; adjacency is allowed. A bounded
overall UTC range loads external blockers. Different overlapping projected
identities both become Invalid under `schedule`; all repeated clean rows inherit
their identity's conflict. Projected checks retain the coherent proposed set
when errors are found, rather than simulating partial commits. Initial resolution
or contradictory-identity failures have no projection, so their current active
histories remain blockers. Any invalid candidate prevents the eventual atomic
batch from committing until correction/revalidation.

The real two-week workbook pipeline yields **30 valid/create candidates** in an
empty schedule. Classifier Feature tests cover lifecycle/type preservation,
intra-file groups, manual/effective blockers, swaps, adjacency, batched reads and
unchanged database snapshots. `matched_lesson_id` is diagnostic only: future
commit must reload/revalidate identity, ownership, lifecycle and conflicts.
Persisted preview, confirmation and all Lesson mutations remain future work.

## Remaining scope

Excel upload/storage, persisted preview/batches/rows, AcademicPeriod selection,
upload/materialization orchestration, explicit mapping/create workflows,
persisted-preview revalidation, commit/re-import reconciliation and
Lesson mutations remain deferred to later import stages. The fixed V1
fingerprint contract is unchanged. The Excel import feature is not complete.
Task/Subtask workflows, free-time calculation, study planning/rescheduling,
reminders, statistics, AI tools, and frontend work are also unimplemented.
Schedule retrieval is not free-time calculation.
