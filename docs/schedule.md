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
conflicts; that is the B3 responsibility below. B2 remains transient; C1 persists its classified output and C2b commits the confirmed snapshot atomically. Excel import is not complete.

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
The B2 candidate `type` remains unchanged, but C2b updates/restores **must
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
unchanged database snapshots. `matched_lesson_id` is diagnostic only: C2b
commit must reload/revalidate identity, ownership, lifecycle and conflicts.
The classifier remains read-only; C1 persists its output as described below.

## Import C1: XLSX upload and persisted preview API

Authenticated `POST /api/schedule-imports` (`schedule-imports.store`) accepts
multipart `academic_period_id` and `schedule_file`. The ID is a strict positive
integer; canonical decimal multipart strings are converted to integers before
validation. The file must pass Laravel server-detected `mimes:xlsx`, explicit
`extensions:xlsx`, uploaded-file validation and `max:5120` (5 MiB). Client MIME,
owner and timezone fields are not trusted. Foreign/nonexistent periods receive
the same generic 422 validation error and create no batch. Other request-level
file validation errors also create no batch; unauthenticated requests return 401.

`ScheduleImportPreviewService::create(User $user, int $academicPeriodId, UploadedFile $file)`
reads the private temporary upload synchronously, computes SHA-256 over its
actual bytes, and parses before opening its transaction. No workbook is
permanently stored; only a safe basename and lowercase hash persist. Repeated
identical bytes may create separate batches and do not imply duplicate rows.
The transaction locks/reloads User, then the selected owned AcademicPeriod,
uses the fresh persisted timezone for materialization, invokes B2/B3 unchanged,
and atomically saves the batch and ordered preview rows. No Lesson is mutated.

Each persisted row is one **concrete occurrence**, not a weekly rule or physical
Excel row. `row_number` is the deterministic 1-based chronological preview
ordinal. C1 uses explicit scalar JSON snapshots:

- `raw_data`: `group`, `workbook_source` (worksheet/range), `local_date`, `weekday`,
  `pair_label`, `parity`, `subject_name`, `teacher_name`, `room`,
  `starts_at_local`, `ends_at_local`, `starts_at_utc`, `ends_at_utc`, and `source`
  (worksheet, inclusive rows, range, pair_range). It contains no Lesson type.
- `normalized_data`: `academic_period_id`, `subject_id`, `teacher_id`, `room`,
  scalar technical `type = other`, `starts_at_utc`, `ends_at_utc`, `operation`,
  `matched_lesson_id`. Existing corrected Lesson types remain untouched;
  C2b update/restore preserves them. The matched ID is diagnostic only.
- Dedicated columns hold B3 `status`, B2 `fingerprint` (nullable) and final
  field-oriented `validation_errors`; no-error values are consistently `[]`.
  No fingerprint or separate resolution-error field is duplicated in JSON.

Counters count persisted occurrences and satisfy
`total_rows = valid_rows + invalid_rows + duplicate_rows`:

| Processing result | Batch status | HTTP response |
|---|---|---|
| Preview with no invalid rows, duplicates allowed | validated | 201 |
| Preview with one or more invalid rows | uploaded | 201 |
| Controlled parser/materialization failure, no useful preview | failed, zero counters/rows | 201 |
| Request/file/owned-period validation fails | no batch | 422 |

Failed attempts retain a safe understandable processing message, without paths
or stack traces. Unexpected infrastructure/programming errors propagate and
roll back persistence rather than becoming fake failed/validation outcomes.
`committed_at` remains null. The real workbook and two-week Kyiv period produce
exactly **30 persisted valid/create rows** when all exact references exist and
the schedule is empty.

Authenticated `GET /api/schedule-imports/{scheduleImportBatch}`
(`schedule-imports.show`) authorizes Batch ownership with `denyAsNotFound()`
for foreign batches, eagerly loads rows by ordinal, and returns the stored
snapshot without rerunning processing or modifying data. Batch/Row Resources
expose counts/status, filename, period, preview names/IDs, dates/times, type,
operations, provenance and errors. User IDs, internal row batch IDs, hashes and
fingerprints are not public. Both responses use the usual `data` resource wrapper.

Preview is a snapshot. Confirmed C2b commit must revalidate identity,
ownership, current lifecycle and conflicts under locks, and must never blindly
trust `matched_lesson_id`. C1 provides no commit/cancel endpoints; C2a adds
revalidation below.

## Import C2a: persisted preview revalidation

Authenticated `POST /api/schedule-imports/{scheduleImportBatch}/revalidate`
(`schedule-imports.revalidate`) requires no body and returns the refreshed Batch
Resource with ordered rows and HTTP 200. The ownership Policy hides foreign
batches with 404; unauthenticated requests return 401. Only uploaded/validated
batches may revalidate. Failed/committed/cancelled batches return standard 422
`schedule_import` errors without changing the preview.

`ScheduleImportPreviewRevalidator::revalidate(User $user, ScheduleImportBatch $batch)`
transactionally reloads/locks User → owned Batch → owned AcademicPeriod → ordered
Rows, then rebuilds candidates from the immutable C1 raw snapshot. It never
reopens XLSX or calls the materializer: even changes to the period range or user
timezone leave the exact concrete dates, UTC intervals, parity and row order
unchanged. Upload again to rematerialize a workbook for a different period.

Existing positive integer Subject/Teacher mappings are checked in owned ID
batches and retained while the entities remain owned, even after harmless
catalog renames or new same-name Teachers. Null, deleted, foreign or otherwise
invalid mappings retry B2's batched exact-name matcher using the imported raw
names and strict PHP equality. Missing references remain field-oriented errors;
multiple exact Teachers remain ambiguous. There is no fuzzy/LLM matching, code
alias, institution preference or automatic entity creation.

Fingerprint validation/generation uses the unchanged V1 contract on raw UTC
start/end and the refreshed Subject ID. Stored fingerprints, statuses,
operations, matched IDs and errors are discarded and recomputed. Subject failure
leaves fingerprint null; Teacher failure may retain it. Reconstructed candidates
carry the technical Other fallback; existing corrected Lesson types are still
preserved by B3/C2b's type-preservation contract.

Before any preview updates, integrity checks require the stored total to match
the actual row count, contiguous ordinals `1..N`, all typed C1 raw fields,
coherent workbook provenance and an owned period. Core corruption returns a
generic 422 asking for a new preview, without partial updates. An otherwise
intact occurrence with malformed whole-second UTC timestamps or an unordered
UTC interval becomes invalid under `preview_integrity`, with null fingerprint
and operation; malformed values are never normalized into valid instants.
UTC validation also runs when Subject resolution fails.

B3 reruns its existing identity/lifecycle/projected-conflict rules against the
current Lesson state. A new active manual blocker can change create → invalid;
a matching imported identity can change create → duplicate/noop; a matching
soft-deleted Lesson can change noop → valid/restore. Revalidation performs no
Lesson creation, update, restoration, deletion, status/FK or replacement change.

Rows update in place, retaining IDs, `row_number`, `raw_data` and creation time.
Only status, the explicit scalar C1 normalized snapshot, final errors and
fingerprint refresh. Actual refreshed statuses determine all four counters;
zero invalids means validated (duplicates allowed), otherwise uploaded.
`error_message` and `committed_at` are null. Revalidation can move uploaded ↔
validated; an empty legitimate preview stays validated with all counters zero.
GET subsequently returns this stored snapshot. All refresh writes are atomic;
unexpected exceptions propagate and roll back.

Confirmed Lesson commit is a separate C2b operation that revalidates again under
its transaction; an earlier successful revalidation is not permission to trust
a stale preview or diagnostic matched Lesson ID.

## Import C2b: confirmed atomic Lesson commit

The supported V1 workflow is upload → persisted preview → optional reference
fixes/revalidation → explicit commit → atomic Lesson reconciliation.
Authenticated `POST /api/schedule-imports/{scheduleImportBatch}/commit`
(`schedule-imports.commit`) requires no body: this endpoint is the confirmation
action. Owners receive the Batch Resource with ordered rows; foreign batches
return 404 and unauthenticated requests return 401. A new commit requires a
validated batch; uploaded/failed/cancelled return 422 with
`schedule_import: ["This import preview is not ready to commit."]`.

`ScheduleImportCommitService::commit(User $user, ScheduleImportBatch $batch)`
starts one transaction and reloads/locks User → owned Batch → owned Period →
ordered Rows. It captures confirmation semantics, then calls C2a's shared
`refreshLocked` core within that same transaction. There is no separate
revalidation commit or owner-lock gap. B2 reference/fingerprint refresh, source
integrity validation, B3 projected conflict logic and counter refresh are reused.

Confirmation compares status/counters/error_message plus every row's ordinal,
status, fingerprint, explicit C1 normalized fields (including operation/matched
ID) and errors. JSON object key order and audit timestamps are ignored. Raw
source remains immutable. Any semantic change returns HTTP 409 with the normal
refreshed Resource under `data` and top-level message
`The import preview changed. Review it and confirm again.` The refresh persists,
including an uploaded state if conflicts/errors appeared; no Lessons change.
The caller must review and confirm again. No newly discovered operation is
silently applied, including create → noop or noop/update → restore.

Only unchanged, still-validated confirmation reaches reconciliation:

| Confirmed row | Lesson operation |
|---|---|
| valid / create | New owned active imported Lesson, Other type, null replacement link |
| valid / update | Same active identity; refresh source-authoritative fields, retain current type and replacement links |
| valid / restore | Same physical deleted identity; refresh source fields, restore as active, retain current type and links |
| valid / preserve | No Lesson mutation; cancelled/replaced lifecycle remains unchanged |
| duplicate / noop | No Lesson mutation, including repeated intra-file identity rows |

Mutation targets are reloaded with `withTrashed()` and `lockForUpdate()` by
`(user_id, academic_period_id, fingerprint)`. Cached matched IDs never select a
target. Unexpected identity/lifecycle or operation/status mismatches fail and
roll back; commit does not substitute operations. B3 validates the coherent
projected target schedule, so the mutation loop does not rerun a competing
per-row overlap engine (including manually swapped current positions).

Source-authoritative updates cover period, Subject, Teacher, room and UTC
interval. Instants are explicitly interpreted in UTC, without current timezone
or period rematerialization. Only new Lessons receive the technical Other type;
updates/restores preserve the latest existing type, including corrections after
preview. No Subject/Teacher/Period is automatically created.

`Lesson.schedule_import_batch_id` means **last applying import batch**: it changes
only for create/update/restore. Noop/preserve retain the previous FK/provenance.
Ordinary re-import never deletes, cancels or synchronizes historical Lessons
absent from the new workbook. Replacements and manual Lessons are not adopted or
undone; manual active overlaps remain conflicts.

All refresh writes, Lesson mutations and final transition are atomic. Any later
mutation/final-transition failure rolls back earlier creates, updates and
restores. Success returns HTTP 200, status committed and `committed_at` set once
to the current whole-second UTC instant. Counters and rows retain the confirmed
applied preview operations; create rows are not rewritten as duplicates after
creation. A repeated commit on an owned committed Batch returns its stored
history with 200, without refresh, Lesson changes or timestamp changes. C2a
still rejects committed batches. An empty validated preview can commit normally.

The real two-week Kyiv workbook commits exactly **30 active imported Lessons**.
Uploading the same file again produces 30 duplicate/noop rows; its commit keeps
all Lesson IDs, fields and original applying Batch FKs unchanged. Integration
tests also cover type/lifecycle preservation, stale reconfirmation, invariant
checks, ordered locks, and full rollback across mixed mutation operations.

## Remaining scope

Explicit import mapping/create workflows, Batch cancellation and destructive
semester replacement/deletion remain deferred. Permanent workbook storage and asynchronous processing
are not part of V1 C1. The fixed V1
fingerprint contract is unchanged. The Excel import feature is not complete.
Task/Subtask workflows, free-time calculation, study planning/rescheduling,
reminders, statistics, AI tools, and frontend work are also unimplemented.
Schedule retrieval is not free-time calculation.
