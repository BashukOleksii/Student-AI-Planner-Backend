# Planning Constraints — PF2

PF2 has begun with C5, a pure candidate-session duration check. This does not
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
planning belong to later checkpoints. Break enforcement (BR-FREE-004), daily and
weekly limits (BR-LIM-001/BR-LIM-002), scheduling, rescheduling, session persistence
and reminder workflows remain deferred; their approved rules are unchanged.
