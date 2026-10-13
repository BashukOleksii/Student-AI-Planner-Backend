# Planning Foundation — PF1 checkpoints C1–C4

Status: **PF1 complete (C1–C4 verified).** [PF2 C5–C8 checks](planning-constraints.md)
and [C9/C10 read-only integration](planning-integration.md) are implemented.

[Stage B automatic study planning](automatic-study-planning.md) now consumes the
unchanged PF1 free-time result and uses PF2 to validate in-memory proposals,
including projected occupied time, breaks and workload. PF1 endpoints and minimum
slot semantics remain unchanged; no session persistence is introduced.

C1/C2 provide pure computation. C3 adds read-only FreeTimeService integration
with owned availability, Lessons and StudySessions. C4 exposes this calculation
through an authenticated free-slots REST API. PF2 duration, break and workload checks are implemented separately with C10
candidate integration. Stage B adds advisory allocation separately. No preference defaults or
persistence are introduced.

## C1 interval engine

TimeIntervalService validates, merges, subtracts, clips and filters half-open
intervals. Each interval is `['start' => int, 'end' => int]`, using Unix UTC seconds
with `start < end`. Integer endpoints preserve exact seconds and avoid dependence
on the server timezone; results are transient, normalized chronological unions.

## C2 recurring availability expansion

`AvailabilityOccurrenceService::expand($windows, $startDate, $endDate, $timezone)`
accepts a list of definitions containing exactly `day_of_week`, `starts_at` and
`ends_at`, inclusive canonical `YYYY-MM-DD` dates, and a named IANA timezone.
Region names, UTC and fixed-offset `Etc/*` zones are supported; timezone
abbreviations and numeric offset strings are rejected. Malformed input throws
InvalidArgumentException, and inputs remain unchanged.

Weekdays are ISO 1–7 (Monday–Sunday). Canonical local `HH:MM:SS` starts range from
00:00:00 to 23:59:59. Only ends may be 24:00:00, meaning the following **local**
calendar midnight, exclusive. End must follow start; overnight availability must
already be represented by two explicit windows, as in
[the availability CRUD contract](planning-settings.md#recurring-availability).

Calendar iteration uses nominal date labels in UTC, so a server timezone or a
skipped local date cannot alter weekday matching. These labels are not resolved
occurrence endpoints. The real UTC timeline is partitioned at IANA offset
transitions. For each constant-offset segment, the service intersects that
segment with the UTC preimage of the local wall-clock window. Two days of search
padding cover IANA offsets/date-line shifts; padding does not define the duration
of a local day. Fixed zones without transition arrays use their constant offset.

Nonexistent labels in spring gaps produce no instants. Repeated labels in autumn
folds include both real occurrences. Windows spanning transitions retain every
real instant with a matching local label. Local days may therefore contain 23,
25 or zero real hours; non-hour and historical second offsets are preserved.

For Kyiv Sunday 03:15:00–03:45:00:

- 2026-03-29 produces no interval.
- 2026-10-25 produces `[00:15:00Z, 00:45:00Z)` and
  `[01:15:00Z, 01:45:00Z)` on that UTC date.

This membership policy differs from
[lesson occurrence materialization](schedule.md#import-b1-transient-calendar-occurrences),
which requires one unambiguous real instant per discrete endpoint and rejects
gaps/folds. C2 never uses PHP's automatic local-endpoint normalization.

Generated positive-length intervals are normalized through the unchanged C1
`merge()` operation. Duplicates, overlap and true UTC adjacency are merged,
including adjacency across local dates. Output is sorted and non-overlapping;
it describes allowed availability, not free time after blockers or guaranteed
session capacity. There are no database reads/writes, application bootstrap,
authentication, HTTP, or current-clock dependencies. Calculations use the IANA
timezone data installed with PHP. C3 supplies trusted user data and bounded ranges;
C4 validates HTTP input. No range limit or planning policy is imposed by this
pure C2 component.

## C3 read-only allowed free time

`FreeTimeService::calculate($user, $startDate, $endDate, $minimumSeconds = null)`
returns `list<array{start: int, end: int}>` with the same half-open UTC-second
representation. It validates canonical calendar dates, ordered inclusive ranges
of at most 31 local dates, and a positive integer minimum when supplied. Calendar
date counting is independent of elapsed UTC seconds, so a range spanning a DST
change still has its correct local-date length. Invalid direct arguments raise
InvalidArgumentException; scalar minimum values are checked without coercion.

The caller supplies a trusted User; this Service does not select an owner from
authentication globals or request IDs. All reads use that User's
`studyAvailabilityWindows()`, `lessons()` and `studySessions()` relationships.
Timezone comes exclusively from the supplied User's persisted `timezone` value.
Only the three recurring definition fields are loaded and passed to C2. Missing
or nonmatching availability, gap-only windows and skipped dates yield no allowed
intervals; empty expansion skips both blocker queries.

The earliest expanded availability start and latest end define a safe UTC query
envelope. Blockers are included with `starts_at < rangeEnd AND ends_at > rangeStart`,
including events beginning before the requested range. Lessons block only when
active and non-soft-deleted: cancelled/replaced originals do not block, while an
active replacement does. StudySessions block only when planned, even for a
historical requested date. Completed, missed, rescheduled and cancelled sessions
are excluded. No current-clock or parent-work lifecycle filter is inferred.

Blocker queries retrieve only start/end fields. Raw database DATETIME values are
parsed explicitly in UTC; model presentation casts and the server's default
timezone never determine their instants. Query bounds are also formatted in UTC.
C1 subtraction normalizes overlapping, adjacent and duplicate-equivalent blockers
across both tables and preserves exact seconds. Since subtraction only removes
instants from C2's expanded availability, every result retains its requested
local-date and allowed-window membership. Additional midnight conversion or
clipping to an approximate UTC day is unnecessary and could distort DST semantics.

When provided, the minimum filters the remaining intervals after subtraction,
including equality at the exact second boundary. Absence means no minimum filter.
PlanningPreference is neither read nor created: configured session bounds,
breaks and daily/weekly limits are not applied or used as defaults here.

Calculation performs only scoped SELECTs, with no locks, transactions, writes,
cleanup, lifecycle changes or cached/persisted slots. Tests verify query behavior
and unchanged database tables after repeated calculations. Independent reads are
not an atomic planning/commit snapshot; future plan creation must revalidate
current state transactionally. Output is allowed study free time, not unrestricted
calendar free time or guaranteed schedulable capacity. C4 exposes this read-only
workflow; PF2 checks and C10 candidate integration are separate implemented capabilities.

## C4 free-slots REST API

`GET /api/planning/free-slots` (`planning.free-slots.index`) requires
`auth:sanctum`, following the existing first-party SPA authentication contract.
Only query parameters are considered; a GET body cannot supply or override them.

| Parameter | Contract |
| --- | --- |
| `start_date` | Required real calendar date, exactly `YYYY-MM-DD`. |
| `end_date` | Required real calendar date, exactly `YYYY-MM-DD`; at or after start. |
| `min_slot_minutes` | Optional canonical positive decimal query string, 1–65535 inclusive. |

The inclusive range contains at most 31 local calendar dates, including when it
crosses DST. Individual dates are validated before cross-field range checks;
neither check depends on the server's local timezone. Dates, minimums and arrays
with invalid formats return Laravel's standard 422 `message`/`errors` response.
Unauthenticated requests return 401 before query validation.

Minimums reject leading zeroes, signs, decimals, scientific notation, whitespace,
arrays and explicit empty values. A GET-only TrimStrings exemption preserves
noncanonical input for rejection without changing other routes. Valid minutes
are explicitly cast to integer and multiplied by 60 (30 → 1800; 65535 → 3932100).
Absence passes null to C3: there is no implicit PlanningPreference minimum.

The Controller calls FreeTimeService with the authenticated User and validated
allowlisted fields only. `user_id`, `owner_id`, `timezone` and other unknown
parameters are ignored. Availability and blockers remain owned queries in C3;
no foreign record IDs, source details or blocking-event data are returned.

Successful responses use HTTP 200 and the standard Resource envelope:

```json
{
  "data": [
    {
      "starts_at": "2026-10-12T06:00:00Z",
      "ends_at": "2026-10-12T08:00:00Z",
      "duration_seconds": 7200
    }
  ],
  "meta": {
    "timezone": "Europe/Kyiv",
    "start_date": "2026-10-12",
    "end_date": "2026-10-18"
  }
}
```

The values above are illustrative. Each slot contains exactly those three keys;
UTC endpoints use whole-second `Z` formatting, independent of server timezone,
and duration is the exact integer `end - start`. Metadata contains exactly the
authenticated User's timezone and the validated local date labels. Empty results
return `data: []` with the same metadata shape. No internal Unix endpoints, owner
IDs, Lesson IDs or StudySession IDs are exposed.

DST behavior is inherited unchanged from C2/C3: gap-only windows yield nothing,
both real fold occurrences are included, and blockers may remove one occurrence
independently. The result is sorted allowed study free time. It does not imply
that a workload fits, apply breaks/session bounds, or guarantee PF2 capacity.

GET performs no application-table writes, locks, transactions, cleanup or cache
materialization. Missing preferences remain missing; availability, Lessons,
StudySessions, Tasks/Subtasks and Reminders remain unchanged. Feature tests cover
the real MySQL pipeline, exact response shapes, isolation, canonical input,
second-level filtering, DST and repeated read-only requests. Automatic scheduling,
StudySession CRUD/rescheduling, Reminder delivery, AI tools and frontend work
remain outside PF1.
