# Planning Constraints — PF2

PF2 includes C5 duration checks and C6 pure break-separation checks. This does not
implement automatic planning, combined candidate validation or persistence.
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
require breaks remains an explicit product decision for C8 integration. It does
not change PF1 free slots, reserve breaks or claim complete enforcement of
BR-FREE-004. C7 workload evaluation and C8 combined candidate validation remain
future work; C8 must select break-relevant events and reconcile the overlap code
with its wider scheduling conflict policy.

## Free slots and candidate sessions

PF1 reports allowed study free intervals, optionally filtered by requested slot
length. Its `min_slot_minutes` query filter is separate from configured candidate
session bounds. A three-hour free slot remains a three-hour free slot even when
maximum session duration is one hour. Evaluating that entire slot as one session
then returns `above_maximum_session_duration`; a future planner may choose a
shorter candidate, but C5 does not perform that choice or splitting.

An empty C5 violation list establishes duration suitability only. It does not
establish availability containment, absence of Lesson/StudySession conflicts,
deadline compliance, workload feasibility or permission to persist a session.
Those combined checks, remaining effort, priorities, splitting and automatic
planning belong to later checkpoints. Integrated break enforcement (BR-FREE-004), daily and
weekly limits (BR-LIM-001/BR-LIM-002), scheduling, rescheduling, session persistence
and reminder workflows remain deferred; their approved rules are unchanged.
