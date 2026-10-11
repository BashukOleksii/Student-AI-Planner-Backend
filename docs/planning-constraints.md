# Planning Constraints — PF2

The pure constraint foundation includes C5 duration, verified C6 separation,
C7 workload and C8 combined candidate checks. This does not implement automatic
planning, database source selection or StudySession persistence.
[PF1](planning-foundation.md) remains the read-only allowed-free-time calculation.

## C5 configured session duration

`SessionDurationConstraintService::evaluate($interval, $minimumMinutes = null,
$maximumMinutes = null)` accepts one half-open UTC interval
`['start' => int, 'end' => int]`. It delegates interval validation and exact
duration calculation to `TimeIntervalService::durationSeconds()`.

Each configured bound is null or a genuine PHP integer from 1 through 65535.
Strings, floats, booleans, out-of-range values and reversed configurations are
invalid. If both bounds are configured, maximum must be at least minimum. Null
means unconfigured; there are no implicit defaults, preference reads or user/clock
dependencies. With both bounds absent, every valid representable positive
duration passes. Invalid input, including C1 integer-duration overflow, throws
InvalidArgumentException; it is not a business violation.

Configured minutes become seconds through exact integer multiplication by 60.
Equality passes. One second below minimum or above maximum fails without rounding
to minutes. Output is a deterministic list containing these machine-readable codes:

| Code | Condition |
| --- | --- |
| `below_minimum_session_duration` | Duration is less than the configured minimum × 60. |
| `above_maximum_session_duration` | Duration is greater than the configured maximum × 60. |

The minimum check precedes the maximum check. Valid ordered configurations permit
at most one violation; satisfying all configured duration bounds returns `[]`.
The interval is never modified, truncated or split. The component uses plain PHP
and C1, with no Laravel bootstrap, database, authentication or HTTP dependency.

## C6 configured break separation

`BreakConstraintService::evaluate($candidate, $anchors, $breakMinutes = null)`
accepts one candidate interval and a list of caller-selected anchor intervals.
Each interval contains exactly integer `start` and `end` UTC Unix seconds with
`start < end`, using half-open `[start, end)` semantics. C1 `merge()` validates
the candidate and every anchor and normalizes overlapping, duplicate and adjacent
anchors into their chronological union. Inputs remain unchanged.

The break is null or a genuine PHP integer from 1 through 65535. Invalid intervals
or configuration throw InvalidArgumentException. Numeric strings, floats, booleans,
zero and out-of-range values are rejected without coercion. Null means no
configured separation requirement; there are no defaults or preference reads.
Configured minutes are multiplied by 60 using integer arithmetic.

For a normalized anchor ending at or before candidate start, the before-gap is
`candidate.start - anchor.end`. For an anchor starting at or after candidate end,
the after-gap is `anchor.start - candidate.end`. These are real elapsed seconds,
including across UTC midnight, not local-day lengths or rounded minutes. Equality
with the configured break passes; one second less fails. Adjacency is a zero-second
gap, not overlap, and fails only when a break is configured.

| Code | Condition |
| --- | --- |
| `overlapping_break_anchor` | A normalized anchor overlaps the candidate, even with a null break. |
| `insufficient_break_before` | A preceding anchor's gap is shorter than the configured break. |
| `insufficient_break_after` | A following anchor's gap is shorter than the configured break. |

Overlap is a separate business violation, not malformed interval input or a
negative gap. An overlapping normalized anchor does not itself emit before/after
codes; other separated anchors can still do so. Results contain unique codes in
the table order, independent of input order; no violations returns `[]`. All
anchors are validated even when no break is configured or an overlap is found.
Gap comparisons avoid subtracting distant endpoints and guard threshold
subtraction near PHP_INT_MIN. Valid integer extremes, including intervals wider
than a representable integer duration, remain usable without float arithmetic.

C6 is pure computation without Eloquent, HTTP, authentication, database or clock
dependencies. It does not select anchors or know their event types or lifecycle
statuses. In particular, whether Lessons or particular StudySession statuses
require breaks remains an explicit product decision for application integration. It does
not change PF1 free slots, reserve breaks or claim complete enforcement of
BR-FREE-004. C6 was re-audited and passed its focused and full regression gate
before C7 implementation, without code changes. Future application integration
must select break-relevant events and reconcile the overlap code with its wider
scheduling conflict policy; C8 accepts explicit caller inputs.

## C7 daily and ISO-week workload

`WorkloadConstraintService::evaluate($candidate, $existingSessions, $timezone,
$maxDailyMinutes = null, $maxWeeklyMinutes = null)` accepts one candidate and a
list of explicitly selected scheduled workload intervals, excluding the candidate.
Inputs use C1's exact half-open UTC integer-second representation. The timezone
must be a named IANA region, UTC or supported fixed-offset `Etc/*` zone, matching
C2. The component performs no authentication, database reads/writes or clock reads.

Each limit is null or an integer from 1 through 65535; configured weekly maximum
must be at least configured daily maximum, matching PlanningPreferenceService.
Null introduces no default. Thresholds are exact minutes × 60; equality passes
and exceeding a threshold by one second fails. Invalid input throws
InvalidArgumentException. Individual durations, the sum of all supplied durations
and local-label arithmetic must be representable as PHP integers; overflow is
explicitly rejected, never rounded or converted to floats.

Each record contributes its own scheduled duration. Overlapping records and even
duplicate-equivalent intervals are additive; they are not merged. The caller is
responsible for selecting each intended source record once and excluding the
candidate. No status, Lesson workload, actual_minutes, estimates or deduplication
policy is inferred. This preserves BR-SES-003 and BR-STAT-003's separation between
scheduled workload and actual effort.

The UTC timeline is split into constant-offset timezone segments, then at local
calendar midnights represented as nominal labels. Every portion contributes its
real elapsed seconds to local `Y-m-d` and ISO `o-W` (Monday-first week and ISO
week-year) totals. Spring gaps create no extra workload; repeated fold hours both
count. Midnight transitions, skipped dates and non-hour offsets retain their real
calendar membership. No local day is assumed to last 86400 seconds.

Only days and weeks receiving a positive candidate contribution are checked;
existing sessions on other days in an affected week still count toward that week.
Already excessive workload in unrelated periods does not invalidate this candidate.
Results are unique and ordered: `daily_study_limit_exceeded`, then
`weekly_study_limit_exceeded`; satisfying configured limits returns `[]`.
Source/status selection and transactional persistence remain future application
integration responsibilities. C7 does not create plans or sessions.
C7's focused tests, complete regression suite, Pint and diff checks passed before
starting C8; plain PHPUnit also verifies it without application bootstrap.

## C8 combined candidate evaluation

```php
PlanningConstraintService::evaluate(
    array $candidate,
    array $freeIntervals,
    array $breakAnchors,
    array $existingWorkloadSessions,
    string $timezone,
    array $preferences,
    mixed $deadlineUtc = null,
): array
```

All intervals use the same half-open UTC integer-second contract. The caller
supplies PF1 free intervals, separately selected break anchors and countable
scheduled workload sessions, and a trusted IANA timezone. There are no database,
authentication, current-clock or HTTP dependencies. Candidate validation rejects
invalid structure and unrepresentable duration through C1.

Preferences must contain exactly these five keys, in any order:
`min_session_minutes`, `max_session_minutes`, `preferred_break_minutes`,
`max_daily_study_minutes`, `max_weekly_study_minutes`. Each value is null or a
configured integer in 1–65535. Missing or extra keys are invalid; C5–C7 enforce
value types and bound ordering. A future caller with no persisted preferences
must explicitly supply all five nulls; no record or product default is created.

C8 delegates duration to C5, separation to C6 and workload to C7. C1 subtracts
the free-interval union from the candidate: any remaining portion produces
`outside_available_study_time`. Entire containment is required; checking only
endpoints is insufficient. Adjacent/overlapping free intervals normalize without
inventing time across gaps. Neither the candidate nor free inputs are changed.

An optional deadline is null or a genuine integer UTC timestamp. The public
argument is `mixed` so non-strict PHP callers cannot coerce numeric strings,
floats or booleans into timestamps. Candidate end equal to deadline passes;
later end yields `deadline_exceeded`. Negative and zero timestamps are valid;
null imposes no deadline. Malformed inputs raise InvalidArgumentException even
when an earlier business rule has already found a violation.

The unique result list uses this fixed order and does not stop at the first
business violation:

1. `below_minimum_session_duration`
2. `above_maximum_session_duration`
3. `outside_available_study_time`
4. `overlapping_break_anchor`
5. `insufficient_break_before`
6. `insufficient_break_after`
7. `daily_study_limit_exceeded`
8. `weekly_study_limit_exceeded`
9. `deadline_exceeded`

No violations returns `[]`. This establishes suitability only against the
explicitly supplied inputs. It does not establish correct ownership/source
selection, a fresh database snapshot, countable statuses, enough remaining work,
whole-plan feasibility or permission to persist a StudySession. PF1 already
subtracts its effective blockers; C8 trusts the supplied free intervals and does
not query or independently select conflict events.

These pure checks support BR-FREE-004/BR-FREE-005, BR-LIM-001/BR-LIM-002 and
BR-PLAN-002 without changing their approved meanings. BR-LIM-003 introduces no
override here: violations are reported, not bypassed to fit a plan. BR-LIM-004's
possible documented defaults remain a future product decision; C5–C8 invent none.
BR-PLAN-003 conflict/source correctness remains the application's responsibility
when obtaining and revalidating PF1 inputs. Break-anchor event types and statuses,
workload status selection, source-record deduplication, ownership checks and
transactional current-state revalidation require a future application integration.

## Free slots and candidate sessions

PF1 reports allowed study free intervals, optionally filtered by requested slot
length. Its `min_slot_minutes` query filter is separate from configured candidate
session bounds. A three-hour free slot remains a three-hour free slot even when
maximum session duration is one hour. Evaluating that entire slot as one session
then returns `above_maximum_session_duration`; a future planner may choose a
shorter candidate, but C5 does not perform that choice or splitting.

An empty C5 result establishes duration suitability only; C8 additionally checks
the supplied free time, anchors, workload and deadline. Free slots remain allowed
study time, not guaranteed session capacity or a generated plan. Remaining effort,
priorities, splitting, multi-session feasibility, automatic planning, rescheduling,
session persistence and reminder workflows remain deferred. Integrated selection
and enforcement of BR-FREE-004 and BR-LIM-001/BR-LIM-002 against current owned
database state also remain future work; approved rules and the PF1 API are unchanged.
