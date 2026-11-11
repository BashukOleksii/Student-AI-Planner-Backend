# Semester schedule Excel import

Current teacher cardinality and catalog assignment rules are defined by
[the single-teacher contract](single-teacher-and-working-saturdays.md) and
[ADR-010](decisions/ADR-010-single-teacher-and-custom-saturdays.md).

Stage 4 extends reconciliation with permanent manual flags and [suppressed-slot protection](semester-schedule-management.md#import-reconciliation-and-intentional-deletion). Blocked rows report `operation: suppressed`, `errors.suppressed_slot` and `suppressed_slot_ids`; `preview.impact.suppressed_slots` lists protections. Explicit release, revalidate and a new confirmation are required to recreate a manually deleted slot. Existing receipts and legacy import contracts remain unchanged; old uncommitted previews conservatively stale after deployment.

Automatic catalog creation and multi-teacher support extend baseline `295bf5c` on main.
[ADR-009](decisions/ADR-009-automatic-semester-import-and-teachers.md) supersedes the original manual-catalog/parity-input decisions.
[ADR-007](decisions/ADR-007-semester-schedule-import.md) records the decision made
before implementation. [Stage 2](semester-schedule-read-api.md) remains the read API.

## REST workflow

All routes require existing Sanctum session/cookie authentication; browser POSTs
use the existing SPA CSRF flow. No AI/provider or frontend is required.

| Method | Route | Result |
| --- | --- | --- |
| POST | `/api/semester-schedule-imports` | 201 persisted weekly preview, including invalid/failed reviews |
| GET | `/api/semester-schedule-imports/{semesterScheduleImport}` | 200 stored state/review/receipt; never implicit revalidation |
| POST | `/api/semester-schedule-imports/{semesterScheduleImport}/revalidate` | 200 refreshed parsed preview and new revision token |
| POST | `/api/semester-schedule-imports/{semesterScheduleImport}/commit` | 200 atomic application or receipt replay; 409 stale confirmation |

### Upload

Multipart form fields:

| Field | Contract |
| --- | --- |
| `academic_period_id` | Positive owned ID; canonical integer text accepted for multipart |
| `schedule_file` | Server-validated XLSX, `.xlsx` extension, at most 5120 KiB |

The server calculates `parity_anchor_on` as the Monday containing the owned period's
`starts_on`, and sets `first_week_parity` to `numerator`. Client parity fields are
ignored. A Tuesday start of `2026-09-01` produces `2026-08-31`; occurrences still
remain strictly inside the academic period.

The period must already have an owned education institution. Semester dates come
only from the period. Owner/timezone/group/rules cannot be selected through extra
request fields; group comes from XLSX, timezone from the persisted User. The file
uses the repository `Приклад розкладу.xlsx` dialect. Semester parsing additionally
preserves explicit teacher separators: newlines, semicolons, and commas/slashes
whose separated parts each clearly identify a person. Initials are never split.
Exactly one valid teacher name is required for each nonempty lesson. Empty cells,
multiple separated names, malformed names and ambiguous matches are row errors.
Separators are recognized to reject multi-teacher sources without inventing a
combined Teacher. Legacy dated parsing retains its original behavior.
The explicit pair mapping is `1-2 → 1`, `3-4 → 2`, `5-6 → 3`, `7-8 → 4`.
Source lesson type is never inferred.

Example multipart fields (send the fixture as a file part, not JSON):

```text
academic_period_id=3
schedule_file=@tests/Fixtures/ScheduleImports/Приклад розкладу.xlsx
```

Missing valid Subjects and Teachers are prepared automatically. Matching is owned,
case-insensitive, whitespace-normalized and apostrophe-normalized; whitespace
around periods in initials is ignored. Multiple matching owned records are an
error. Existing records keep their names, institution and other relationships;
new records inherit the period's owned institution. No catalog or active schedule
writes occur during preview/revalidation. Confirming the existing commit action
creates missing records, reconciles rules and saves the receipt in one transaction.
Each subject must use a single teacher identity across rows and the user's existing
semester catalog. Disagreements attach `errors.teacher_id` to every affected row;
missing/multiple names use `errors.teacher_name`, malformed/ambiguous names use
`errors.teacher_names`. Source worksheet/range/rows remain on each preview row.
Invalid cardinality/assignments cannot be committed. Historical multi-teacher
catalogs/lessons produce a clear `errors.semester_teacher_repair` 422 response.

### Review contract

The original control fixture contains 16 rows and 16 create operations for an empty
semester. The following is a **one-row excerpt** of that 16-row response; arrays
in the actual response include every parsed rule and operation:

```json
{
  "data": {
    "id": 1,
    "academic_period_id": 3,
    "original_filename": "Приклад розкладу.xlsx",
    "status": "ready",
    "parity_anchor_on": "2026-09-07",
    "first_week_parity": "numerator",
    "preview_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "can_commit": true,
    "preview": {
      "catalog": {
        "subjects": [{"id": 11, "name": "Програмування в Інтернет", "code": null, "color": null, "resolution": "reuse", "errors": []}],
        "teachers": [{"id": 5, "name": "Когут В.С.", "resolution": "reuse", "errors": []}]
      },
      "rows": [{
        "row_number": 1,
        "weekday": 1,
        "pair_number": 1,
        "pair_label": "1-2",
        "week_pattern": "all",
        "starts_at_local": "08:30:00",
        "ends_at_local": "09:50:00",
        "subject_name": "Програмування в Інтернет",
        "teacher_name": "Когут В.С.",
        "teacher_names": ["Когут В.С."],
        "teacher_ids": [5],
        "teachers": [{"id": 5, "name": "Когут В.С.", "resolution": "reuse", "errors": []}],
        "subject": {"id": 11, "name": "Програмування в Інтернет", "code": null, "color": null},
        "teacher": {"id": 5, "name": "Когут В.С."},
        "room": "231а",
        "source": {"worksheet": "Sheet1", "rows": [3, 4], "range": "D3:F4", "pair_range": "B2:F5"},
        "import_key": "1:1:all",
        "operation": "create",
        "matched_rule_id": null,
        "errors": []
      }],
      "operations": [{
        "operation": "create",
        "rule_id": null,
        "import_key": "1:1:all",
        "before": null,
        "after": {"weekday": 1, "pair_number": 1, "pair_label": "1-2", "week_pattern": "all", "starts_at_local": "08:30:00", "ends_at_local": "09:50:00", "subject_id": 11, "teacher_id": 5, "teacher_ids": [5], "room": "231а"}
      }],
      "counts": {"create": 16, "update": 0, "delete": 0, "noop": 0, "preserve": 0, "duplicate": 0},
      "errors": [],
      "impact": {
        "schedule": {"id": null, "before": null, "after": {"group_name": "ПІ-231", "parity_anchor_on": "2026-09-07", "first_week_parity": "numerator"}},
        "preserved_rule_ids": [],
        "preserved_exception_dates": [],
        "detached_snapshot_references": []
      }
    },
    "receipt": null,
    "committed_at": null
  }
}
```

Rows expose original coordinates, names, owned resolutions, local times, room,
complete `teachers`/`teacher_ids`, operation and field errors. `preview.catalog`
contains unique `subjects` and `teachers` with `resolution: reuse|create|invalid`.
Prepared records have `id: null`; corresponding preview IDs are nullable until
commit. Missing valid records do not block readiness. Malformed names and ambiguous
matches produce `errors.subject_name` / `errors.teacher_names`. Subject/teacher
need not share the period's institution, but must share its owner.

The committed `receipt.catalog` contains final positive IDs and
`resolution: created|reused`, plus the calculated parity fields alongside existing
counts/rule IDs/time. The stored preview remains the reviewed historical proposal;
use the receipt for committed catalog identities. Old receipts replay unchanged.

`preview.errors` contains cross-row/conflict/calendar errors. Empty error bags are
arrays, consistent with existing import responses. Room >100 characters and group
>255 characters are preview errors, not late database failures. An unsupported
valid XLSX returns status `failed`, empty rows/operations and a useful `errors.file`.
Request-level invalid file/fields return 422 and create no import record.

Statuses are `ready`, `invalid`, `failed`, `committed`. Only ready is committable.
File hashes, raw template, state fingerprint, user IDs and private persistence
metadata are omitted from the public Resource.

### Revalidate

After resolving ambiguous/invalid catalog entries, POST the `/revalidate` route with no body. It reads the
saved parsed weekly template, not the original file, rebuilds resolution/impact and
rotates the token even if the result is identical. It recalculates parity from the
current period and never changes catalogs, active rules, schedule metadata or snapshots. GET returns the stored review without repair.
Committed and failed imports cannot revalidate (422); upload a new file for changed
workbook content/options or parser failure.

### Commit

JSON body, with the **exact token returned by the reviewed revision**:

```json
{
  "confirmed": true,
  "preview_token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
}
```

`confirmed` must be the JSON boolean true. Missing/false/string/numeric confirmations
and malformed tokens return standard Laravel 422 validation errors. Foreign or
missing targets return 404 before body validation; unauthenticated requests return
401. This explicit confirmation also covers deletions and schedule metadata changes
shown in the review; there is no hidden semester purge.

Commit locks/rechecks the complete authoritative state. A different token returns
409 with the current Resource. If the token matches but important state changed,
commit persists only a refreshed preview/new token and returns 409:

```json
{"message":"The import preview changed. Review it and confirm again."}
```

The actual 409 response also includes the complete import Resource under `data`.
Review it again; then confirm its token, or explicitly call revalidate. No rule,
schedule or snapshot change occurs on this stale path. A still-invalid preview
returns 422 with `errors.semester_import` and cannot be forced through.

On success the same Resource has `status: committed`, `can_commit: false`, a UTC
microsecond `committed_at`, and a durable receipt. Example receipt excerpt:

```json
{
  "semester_schedule_id": 1,
  "counts": {"create": 16, "update": 0, "delete": 0, "noop": 0, "preserve": 0, "duplicate": 0},
  "rule_ids": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16],
  "committed_at": "2026-11-10T12:00:00.123456Z"
}
```

Follow `GET /api/semester-schedules/1/week/2026-09-07` (or `/date/2026-09-08`).
For the control fixture, each of the two weeks has 15 occurrences and Tuesday pair
4 selects the correct numerator/denominator subject. Zero dated Lessons are created.
Retrying a committed import with the same token replays its receipt without graph
reads/writes or timestamp changes, even if current data later changed. A different
token still returns 409. A new identical upload has 16 `noop` rows and preserves IDs,
metadata, rule timestamps and provenance when no other source changed.

## Identity, reconciliation and frozen snapshots

| Operation | Meaning |
| --- | --- |
| create | New recurring slot |
| update | Same unchanged managed identity, different imported values |
| delete | Unchanged managed slot is absent from the new file |
| noop | Duplicate of the existing managed rule, with identical values |
| preserve | Identical protected slot satisfies a row without adoption |
| duplicate | Identical repeated source row; no second write |

Identity is `(semester_schedule_id, weekday:pair_number:week_pattern)`, independent
of subject, teacher, room and local times. Updates retain the existing row ID.
A weekday/pair/pattern move is a reviewed delete/create. Contradictory same-key
source rows are errors. Numerator/denominator alternatives can share pair/time;
`all` overlaps either parity. Half-open adjacency is valid. Calendar rules are
implemented only by EffectiveScheduleCalculator.

Only unchanged rules with this workflow's provenance/baseline are reconciled.
Manual rules, legacy-provenance rules and manually edited imports are protected.
An identical protected slot can satisfy the file without gaining import ownership.
A different protected slot or projected time conflict blocks the whole commit.
Protected rows absent from the file remain. There is no force-overwrite option;
adjust the workbook/catalogs to agree with protected data. Existing data is never
automatically adopted as a new import.

Before/after group/anchor/parity are shown in `impact.schedule`. Confirming new
metadata changes ordinary date/parity interpretation, including protected recurring
rules' membership; their fields remain intact. Frozen snapshots do not inherit this
change. Snapshots are always preserved, including working Saturdays and empty days.

`impact.detached_snapshot_references` lists snapshot lesson ID, date and deleted
source rule ID. Commit clears only those owned nullable source references before
rule deletion, preserving snapshot IDs, subject/teacher, pair/time/room and exception
kind. No FK checks are bypassed. A later failure rolls detachment, deletion,
metadata, all rule writes and receipt back together.

## Persistence and consistency

Additive migration `2026_11_10_000002_create_semester_schedule_imports.php` adds:

- `semester_schedule_imports`: owned period, file metadata, calculated anchor/parity,
  VARCHAR status, JSON template/review/receipt, private context hash, public revision
  token, DATETIME(6) committed time and Laravel timestamps. Owner/period composite
  FK, lookup/candidate indexes and status/parity/receipt CHECKs apply.
- Nullable `semester_schedule_import_id`, `import_key`, `import_baseline` on weekly
  rules. Composite provenance FK enforces owner equality; unique schedule/import
  key prevents duplicate managed identity. A CHECK requires all provenance fields
  together with an object baseline, and forbids mixing new/legacy provenance.
  The supporting FK index is explicitly named for safe empty-data rollback/up.

Existing nullable values are not backfilled. `source_import_batch_id` continues to
mean legacy import provenance; new imports leave it null. New provenance means
last **applying** import, not last identical upload; noop/preserve do not repoint it.
Period deletion now gives a controlled dependency validation error when semester
import history exists. Populated import-history migration downgrade refuses before
DDL; future retained-data corrections require forward migrations.

A random revision token plus a SHA-256 canonical state fingerprint binds approval.
Fingerprint covers timezone, period/institution, all owned catalogs, schedule,
rules/baselines/provenance, subject/teacher links, complete rule/snapshot teacher
lists and the complete exception/lesson graph. Catalog changes
unrelated to this workbook conservatively require re-review too. Preview options
and template are covered; JSON object key order is not material. There is no cache,
preview expiry timer, external service or new approval framework.

Parsing precedes locks. Owner-first transactions use current locking reads for
catalog resolution, even if a confirmation waited for another writer. All relevant
parents/children and provenance are locked deterministically before application.
New cooperating schedule writers must keep the same owner-lock discipline. Two
independent-process tests verify one application with identical receipt replay and
stale detection after a catalog change committed while waiting for the owner lock.

Whole-semester timezone validation checks two weekly cycles, every timezone offset
transition's local dates and all snapshots through the existing calculator. It
therefore detects gaps/folds/skipped dates without expanding millions of ordinary
dates or persisting occurrence copies. Invalid saved dependencies fail safely;
repair/revalidate them before committing. Unexpected errors return only:

```json
{"message":"The semester schedule import could not be processed."}
```

This is a 500 response even with APP_DEBUG enabled; partial application is rolled
back. Bad period/institution selection or irreparable saved integrity returns 422.
The new prefix's error boundary does not change legacy route behavior.

## Scope and verification

Legacy import endpoints, Lesson commit/CRUD, frontend, AI schemas/tools,
FreeTimeService and planning/reminder/statistics logic remain separate. Semester
Resources use canonical scalar `teacher_id`/`teacher`, retaining singleton
`teacher_ids`/`teachers` compatibility fields. Occurrences remain virtual; no legacy Lessons are generated.

Additive migration `2026_11_11_000001_add_semester_lesson_teachers.php` creates
`subject_teacher`, `weekly_lesson_rule_teacher` and
`schedule_date_exception_lesson_teacher`. Unique pairs and composite owner FKs
prevent duplicate and foreign links. Existing single-teacher links are backfilled;
historical nine-field baselines stay unchanged and compare through normalized
single-teacher lists. New baselines retain `teacher_ids` for format compatibility.
No historical baseline/receipt or pivot is rewritten. New snapshots require one
teacher and stay independent after later rule edits/deletions.

Only the supported XLSX dialect is accepted. No mapping UI, cancellation/deletion,
force replacement, automatic legacy conversion or frontend integration is added.
Workbook changes require a new upload.

For this automatic-import update, no tests were written or run, as requested.
PHP syntax and formatting checks are the only executed code checks. Previous
Stage 3 test results in [the historical verification report](semester-schedule-stage3-verification.md)
do not verify this update. See [the changed-file report](automatic-semester-schedule-import.md).
