# Planning Foundation — PF1 checkpoints C1 and C2

These checkpoints provide pure computation only. FreeTimeService, database
retrieval, Lesson/StudySession subtraction and the free-slots REST API remain
unimplemented (C3/C4). PF2 capacity, breaks, workload limits and scheduling remain
separate future work. No preference defaults or persistence are introduced.

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
timezone data installed with PHP. C3/C4 must supply trusted user data and request
bounds; no range limit or planning policy is imposed by this pure C2 component.
