# Stage 13 — Statistics API contract (R35 and R37)

`GET /api/statistics/progress?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`

Authentication remains `auth:sanctum` with the existing student session. Send
`Accept: application/json`. Success is HTTP 200 with Laravel's `data` envelope.
All existing response fields retain their names, types and calculations. The only
additions are `data.tasks.current.completion_percent` and
`data.subject_workload_in_period`. No Frontend change is included in this milestone.
Daily trends and comparisons keep their existing contracts.

## New fields and types

| JSON path (relative to `data`) | Type | Meaning |
| --- | --- | --- |
| `tasks.current.completion_percent` | number or null | Overall current completed Tasks / current total Tasks × 100, rounded to one decimal place; null for no Tasks. |
| `subject_workload_in_period` | array of objects | Groups with at least one qualifying session; empty array when none qualify. |
| `subject_workload_in_period[].subject_id` | integer or null | Current owned Task Subject ID; null for unassigned. |
| `subject_workload_in_period[].subject_name` | string | Current Subject name; `Без предмета` for unassigned. |
| `subject_workload_in_period[].scheduled_seconds` | integer ≥ 0 | Full elapsed scheduled seconds of qualifying planned/completed sessions. |
| `subject_workload_in_period[].completed_actual_minutes` | integer ≥ 0 | Sum of known persisted actual minutes of completed sessions only. |
| `subject_workload_in_period[].completed_with_actual_minutes` | integer ≥ 0 | Completed sessions with non-null actual minutes. |
| `subject_workload_in_period[].completed_without_actual_minutes` | integer ≥ 0 | Completed sessions with null actual minutes. |
| `subject_workload_in_period[].session_count` | integer ≥ 1 | Number of qualifying planned/completed sessions in this group. |

The percentage is a JSON number, not a formatted string. Whole values serialize
as `0`, `70` or `100`; these are numerically equivalent to `0.0`, `70.0`, `100.0`.
Frontend may format one fractional digit for display. `33.333…` becomes `33.3`;
`66.666…` becomes `66.7`. Never replace a null percentage with 0%.

## Completion percentage semantics

The denominator includes all currently active, non-soft-deleted Tasks owned by the
authenticated student, regardless of creation date, deadline, completion date or
requested period. The numerator is the subset currently marked `completed`.
Subtasks form their own existing population and are never added to this numerator
or denominator. Reopening/deleting a Task changes the next report's current rate.
No historical percentage is reconstructed from the date range. An empty historical
period can still return a non-null current completion percentage.

## Subject workload calculation

1. Use the existing owner-scoped period sessions and historical-reference checks.
   A session qualifies when its persisted status is `planned` or `completed` and
   its own `starts_at` belongs to the requested period in the persisted timezone.
   `missed`, `cancelled` and `rescheduled` records contribute neither group count
   nor workload. Existing status-specific summary buckets still include those
   records. This matches C9's active scheduled workload definition.
2. A rescheduled predecessor is excluded. Its replacement is counted once only
   when the replacement's own status and start qualify, even if the predecessor
   lies inside/outside a different period. The report does not reconstruct chains
   or infer statuses from the current clock.
3. Group by the Task's current Subject ID. Sessions linked to Subtasks use their
   parent Task's Subject and count once. Compatible owned soft-deleted Task/Subtask
   history is retained, as in the existing session summary. Unassigned Tasks share
   `{ "subject_id": null, "subject_name": "Без предмета" }`.
4. Sum `ends_at - starts_at` in exact elapsed UTC seconds, without clipping at
   midnight or the end of the period. Equal/overlapping records remain distinct.
   Scheduled duration is separate from actual minutes.
5. For completed sessions, sum only non-null `actual_minutes`; count known and
   unknown records separately. Never substitute scheduled duration or zero for
   missing actual time. A group with only unknown completions returns a known-minute
   sum of 0 and a positive `completed_without_actual_minutes`; its total actual
   effort is unknown. A planned-only group has all three completed metrics equal
   to 0. Consumers must examine the unknown count before describing total actual
   study time. Known plus unknown completed counts may be below `session_count`
   because planned sessions are also included.
6. Return only populated groups, ordered by `scheduled_seconds` descending, then
   numeric Subject ID ascending; unassigned sorts last among equal workloads.
   Subject names do not define group identity or sort order.

Task-to-Subject associations and Subject names are current snapshot data. Later
reassignment, renaming or deletion can alter a past period's breakdown. No stored
historical labels, estimated actual minutes, forecasts or historical snapshots
are invented. Deleting a Subject clears its owned Task references under existing
rules, so those sessions subsequently appear as unassigned.

The same nonlocking MySQL REPEATABLE READ transaction covers all fields. Subject
presentation data is loaded in owner-scoped batches, with no queries per session,
Task or Subject. Invalid selected Task/Subtask references retain existing fail-closed
behavior; an active workload Task referencing a foreign Subject also fails the
whole report without exposing its name or ID. The endpoint accepts no owner ID.

## Date and timezone semantics

Both dates are required exact real `YYYY-MM-DD` local calendar labels, ordered
and inclusive, with at most 31 nominal dates. Missing/invalid dates, a 32-day range
or unknown query parameters return Laravel 422 validation errors. Query `user_id`
and `timezone` are rejected; GET bodies cannot override dates or owner settings.
Unauthenticated requests return 401 before validation.

The backend reloads the authenticated User's persisted IANA timezone. Existing
half-open UTC membership `[start, end)` handles DST, skipped dates and disjoint
historical occurrences; it does not assume 24-hour days. A session at the first
included instant counts; one at the exclusive upper boundary does not. A session
starting before the period is excluded even if it overlaps it. A session starting
inside it contributes its full duration even if it ends/completes afterward.
Completion activity retains its separate `completed_at` attribution.
`as_of_utc` is the captured calculation clock, not a historical snapshot date.

Selected-source/internal failures retain the generic HTTP 500 response:

```json
{"message":"The progress statistics could not be processed."}
```

## Empty state

With no active Tasks, `tasks.current.completion_percent` is null and its existing
four counts are 0. With no qualifying sessions, `subject_workload_in_period` is
`[]`, including periods containing only missed/cancelled/rescheduled sessions.
No artificial zero-workload Subject or unassigned row is emitted. Existing
completion and status buckets remain present with zero counts/durations.
The full empty response is in [progress-statistics.md](../progress-statistics.md#stable-response-contract).

## Exact representative response

For `start_date=2026-10-01&end_date=2026-10-31`, this example contains two Subjects
and unassigned work. It is the complete HTTP response body, including all existing
fields. Task completion is 2 / 4 = 50%; one of those completions is outside the
period. Session workload totals 28,800 scheduled seconds; the 25 recorded actual
minutes are incomplete because two completions have unknown actual duration.

```json
{
  "data": {
    "as_of_utc": "2026-10-15T12:00:00.123456Z",
    "timezone": "Europe/Kyiv",
    "period": {
      "start_date": "2026-10-01",
      "end_date": "2026-10-31",
      "session_attribution": "starts_at"
    },
    "tasks": {
      "current": {"total": 4, "pending": 2, "completed": 2, "overdue_pending": 0, "completion_percent": 50},
      "completed_in_period": {"total": 1, "on_time": 0, "late": 0, "without_deadline": 1}
    },
    "subtasks": {
      "current": {"total": 1, "pending": 1, "completed": 0, "overdue_pending": 0},
      "completed_in_period": {"total": 0, "on_time": 0, "late": 0, "without_deadline": 0}
    },
    "study_sessions": {
      "started_in_period": {
        "planned": {"count": 3, "scheduled_seconds": 18000},
        "completed": {"count": 3, "scheduled_seconds": 10800},
        "missed": {"count": 0, "scheduled_seconds": 0},
        "rescheduled": {"count": 0, "scheduled_seconds": 0},
        "cancelled": {"count": 0, "scheduled_seconds": 0},
        "completed_actual_minutes": 25,
        "completed_with_actual_minutes": 1,
        "completed_without_actual_minutes": 2
      }
    },
    "subject_workload_in_period": [
      {
        "subject_id": 11,
        "subject_name": "Математика",
        "scheduled_seconds": 10800,
        "completed_actual_minutes": 25,
        "completed_with_actual_minutes": 1,
        "completed_without_actual_minutes": 1,
        "session_count": 4
      },
      {
        "subject_id": 12,
        "subject_name": "Алгоритми",
        "scheduled_seconds": 10800,
        "completed_actual_minutes": 0,
        "completed_with_actual_minutes": 0,
        "completed_without_actual_minutes": 0,
        "session_count": 1
      },
      {
        "subject_id": null,
        "subject_name": "Без предмета",
        "scheduled_seconds": 7200,
        "completed_actual_minutes": 0,
        "completed_with_actual_minutes": 0,
        "completed_without_actual_minutes": 1,
        "session_count": 1
      }
    ]
  }
}
```

## Suggested Ukrainian Frontend labels

| Indicator | Label |
| --- | --- |
| Current completion percentage | Загальний відсоток виконаних завдань |
| Null completion percentage | Ще немає завдань |
| Subject workload section | Навчальне навантаження за предметами |
| Subject | Предмет |
| Scheduled workload (seconds converted for display) | Запланований час |
| Known completed actual minutes | Зафіксований фактичний час (хв) |
| Completed sessions with actual minutes | Завершені сесії з указаним часом |
| Completed sessions without actual minutes | Завершені сесії без указаного часу |
| Qualifying session count | Кількість сесій |
| Unassigned group | Без предмета |
| No qualifying sessions | За цей період немає навчальних сесій для розподілу навантаження |
| Unknown actual duration hint | Фактичний час указано не для всіх завершених сесій |
