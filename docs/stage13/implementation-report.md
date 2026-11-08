# Stage 13 — Backend implementation

Base: `00847fc910eae194c8c56f25cbde9eb74def5f64` on `main`, initially clean.

R35 and R37 extend the existing authenticated progress endpoint. The exact JSON,
types, inclusion rules, empty states and Ukrainian labels are in
[statistics-api-contract.md](statistics-api-contract.md).

`ProgressStatisticsService` derives the current Task percentage from its existing
owned active aggregate. It reuses bounded session loading, historical ownership
validation and the session reducer for planned/completed workload grouped by the
Task's current Subject. Subject presentation data is batch-loaded with explicit
owner scope. Existing period status totals, daily trends and comparisons retain
their definitions. Only the API Resource's two additive outputs change the public
progress contract; the Controller and Form Request need no changes.

No schema, migration, persisted statistic, cache, dependency, endpoint, AI Tool,
provider, planning, reminder, password-management or Frontend implementation is
added or changed. The existing AI statistics read adapter naturally receives the
shared Service's additive data; its implementation and registration are unchanged.
Whole numeric percentages follow the existing JSON round-trip convention.

Focused coverage extends the existing fixtures/tests rather than introducing a
second statistics harness. Seven new cases cover rounded/current completion rates,
multiple Subjects/unassigned grouping, retired-session/replacement attribution and
foreign Subject references. Existing assertions additionally cover empty contract,
soft-deleted work history, timezone boundaries, user isolation, all five statuses,
cross-midnight duration and read-only repeatability. Query-count checks remain
constant at ten SELECTs for 1 Task / 1 session and 21 Tasks / 41 sessions across
21 Subjects.

## Validation

- `vendor/bin/phpunit --filter=Progress`: **508 passed, 5,479 assertions** using a
  newly created disposable MySQL 8.4 container, including statistics integration,
  daily trends, comparisons and existing AI read-tool compatibility tests.
- `vendor/bin/pint --dirty`: passed.
- `git diff --check`: passed.
- Both documentation JSON contracts parse; representative group totals reconcile
  with the existing session summary's scheduled seconds, counts and actual metrics.

The single complete PHPUnit run and repository-wide Pint check run on this commit
through the existing [Backend verification workflow on main](https://github.com/BashukOleksii/Student-AI-Planner-Backend/actions/workflows/backend-verification.yml?query=branch%3Amain).
That workflow provisions its own empty MySQL 8.4 service, verifies migration and
lock instrumentation guards, and retains JUnit results. Its existing offline
Gemini action regression step also runs unchanged. The final delivery reports the
exact commit, run link and outcome. No retained/application database is used for
destructive test execution.

All work stays on `main`, with no branch, worktree or Pull Request. `AGENTS.md`
now records this workflow rule for future Backend work.
