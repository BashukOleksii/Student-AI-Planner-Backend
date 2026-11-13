# Workload dashboard aggregates

`GET /api/planning/workload-analytics?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`

Authenticated through the existing Sanctum API group. Reuses `WorkloadAdviceRequest`: query dates only, inclusive local period of 1–31 days, existing validation and authorization behavior. Response: `{ "data": { ... } }`.

Fields:

- `as_of_utc`, `timezone`, `period`: statistics snapshot and requested dates.
- `days[]`: `date`, `planned_seconds`, `completed_seconds`. Reuses progress trends; full scheduled duration belongs to the session's local start date, including sessions crossing midnight. Only the respective statuses contribute.
- `planned_seconds`, `planned_sessions`: saved sessions whose status is `planned` and start belongs to the period. Excludes proposed, completed, missed, cancelled and rescheduled sessions.
- `subjects[]`: unchanged `subject_workload_in_period` aggregation from progress statistics. Includes planned and completed scheduled durations, grouped by current subject association; excludes inactive statuses.
- `all_time.completed_actual_minutes`: sum of recorded actual minutes of the authenticated user's completed sessions, independent of selected dates. No scheduled-time substitution for legacy null actual duration.
- `all_time.completed_sessions`, `all_time.sessions_without_actual_minutes`: completed session count and subset with unknown actual duration.
- `all_time.completed_tasks`, `all_time.completed_subtasks`: current completed, non-deleted work counts from progress statistics, independent of selected dates.
- `all_time.task_completion_percent`: current completed task count divided by current total task count, rounded by the existing statistics service; null when there are no tasks. This is not an effort-completion percentage.

Aggregates use a read transaction and reuse existing progress services. No persistence, migration, planning preference update or planning algorithm change. The original workload-advice contract and its consumers remain unchanged. The frontend loads aggregates separately so their failure does not hide the workload advice.

Verified with an isolated disposable MySQL request: one owned planned hour starting at 22:30 UTC on November 9 belongs to November 10 in Europe/Kyiv; completed actual minutes outside the selected dates appear only in lifetime activity; another user's sessions do not contribute.
