# Stage H1 — Read-only AI tools foundation

H3a leaves all seven H1 definitions, argument contracts, Executor dispatch and
read-only effects unchanged. Agent discovery additionally includes four separate
Task proposal definitions. Those execute through TaskActionProposalService, never
through H1 ToolExecutor, and cannot call a Task writer. Explicit authenticated
confirmation is a separate server operation. See [the H3a contract](ai-task-actions.md).

H2 integration is now implemented separately in [AI agent chat](ai-agent-chat.md).
Its orchestration layer calls this unchanged H1 Executor and wraps it in owned
conversation persistence. Statements below about H1's absence of LLM/network or
message writes describe the tool layer itself; H2 performs those activities
outside H1. No read-tool schemas, validation, Services or REST contracts changed.

H1 implements provider-independent discovery and deterministic execution of seven
read-only application capabilities. It introduces no LLM, agent, HTTP endpoint,
console entry point, schema, dependency or persistence workflow.

## Architecture and discovery

```text
Trusted authenticated User + selected name + structured arguments
  -> ToolRegistry -> ToolExecutor -> ToolArgumentValidator
  -> ReadOnlyTools -> existing application Service -> owned records
  -> safe presentation -> success/error envelope
```

All classes live in `app/Services/AI/Tools` and Laravel autowires them without
additional bindings. `ToolRegistry::definitions(): array` returns a deterministic
ordered list of `{name, description, parameters}` definitions. `find(string)`
returns the matching definition or null. There is no registration API.

`parameters` is a JSON Schema-compatible object with `type: "object"`, explicit
`properties`, an explicit `required` array (empty for `list_tasks`), and
`additionalProperties: false`. Properties use standard `type`, `format`, `pattern`,
`enum`, `minimum` and `maximum` keywords. No provider-specific wrapping is included.
H2 can adapt these definitions to a provider without changing domain Services.

`ReadOnlyTools` contains one explicit seven-branch match. Neither names nor arguments
can select arbitrary PHP classes, methods, SQL or container bindings. The adapters
only assemble parameters and presentation; Services retain all deterministic rules.
The seven handlers share one focused class rather than a plugin/registration framework.

## Exact inputs and Service mappings

The following notation specifies every accepted property:

- **D**: genuine JSON string, `format: date`, pattern
  `^[0-9]{4}-[0-9]{2}-[0-9]{2}$`, real calendar date in years 0001–9999.
- **I**: genuine JSON integer, minimum 1, maximum `PHP_INT_MAX` (9223372036854775807
  on the verified 64-bit runtime). Floats, numeric strings and booleans fail.
- **Period**: required `start_date: D`, `end_date: D`; inclusive, increasing,
  at most 31 nominal local dates. Exactly 31 dates is valid.

| Stable name | Purpose and properties | Required | Service method |
| --- | --- | --- | --- |
| `get_schedule_for_date` | Active effective lessons; `date: D` | `date` | `ScheduleService::date()` |
| `list_tasks` | Active Task list; `status: string enum [pending, completed]`, `priority: I enum [1, 2, 3]`, `subject_id: I`, `page: I`, `per_page: I` with maximum 50 | none | `TaskService::paginate()` |
| `find_free_study_slots` | Available UTC intervals; Period plus optional `min_slot_minutes: I` with maximum 65535 | `start_date`, `end_date` | `FreeTimeService::calculate()` |
| `list_study_sessions` | Existing overlapping sessions and history; Period | `start_date`, `end_date` | `StudySessionReadService::range()` |
| `get_progress_statistics` | Current populations and bounded period activity; Period | `start_date`, `end_date` | `ProgressStatisticsService::calculate()` |
| `get_daily_progress_trends` | Zero-filled daily activity and exact totals; Period | `start_date`, `end_date` | `ProgressTrendsService::calculate()` |
| `compare_progress_periods` | Factual comparison; `baseline_start_date: D`, `baseline_end_date: D`, `current_start_date: D`, `current_end_date: D` | all four properties | `ProgressComparisonService::calculate()` |

Comparison periods must each satisfy Period validation, have equal nominal lengths,
and satisfy `baseline_end_date < current_start_date`. Adjacent periods are allowed;
there is no maximum gap. Names, properties and string enums are case-sensitive.
Priority values come from `TaskPriority` (low=1, normal=2, high=3); status values
come from `TaskStatus`. Schema enum values are generated from these existing enums.

For example, the full schedule parameter schema is:

```json
{
  "type": "object",
  "properties": {
    "date": {
      "type": "string",
      "format": "date",
      "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"
    }
  },
  "required": ["date"],
  "additionalProperties": false
}
```

All other schemas are available directly through `definitions()` and fully specified
by the property table and shared notation above. The descriptions also state period
and comparison constraints that cannot be represented by basic scalar schema keywords.

## Server validation

`ToolArgumentValidator` is intentionally limited to the registry's scalar vocabulary,
not a general JSON Schema engine. It checks unknown properties, required presence,
actual PHP scalar types, strict enums and integer bounds without coercion. Explicit
nulls, unsupported nested arrays/objects, unknown filters, identity fields (`user_id`,
`owner_id`, `timezone`) and dispatch fields (`class`, `service`) fail. Inputs remain
unchanged. A decoded empty argument object is represented by an empty PHP array.

Dates and ranges delegate to `PlanningContextService::validateDates()`; comparisons
delegate to `ProgressComparisonService::validatePeriods()`. These are the same
business validation used by G1/G2a/G2b and session reads. PF1 uses the equivalent
exact-calendar/31-date policy. No Form Request or synthetic HTTP invocation is used.
Whitespace is not trimmed, bad dates are not normalized, and missing values are
not invented. TaskService retains owned Subject validation, filtering, ordering and
pagination-offset overflow validation. Foreign and nonexistent Subject filters both
return the same generic `invalid_arguments` error.

Missing dates always fail, including schedule date. H2 owns clarification and trusted
context derivation before execution; H1 never selects today or infers parameters.
Optional task pagination retains Service defaults (page 1, 15 per page). Optional
filters are absent rather than inferred. Omitted minimum slot duration is null in
the Service call; supplying null explicitly is invalid. Valid integer minutes are
multiplied by 60 only after the 65535 bound, so the conversion cannot overflow.

## Execution and result contract

```php
ToolExecutor::execute(User $authenticatedUser, string $toolName, array $arguments): array
```

The Executor resolves the allowlist, validates before adapter execution, checks the
supplied User represents a persisted identity, and invokes the adapter. It creates
no enclosing transaction or locking reads. G1/G2a/G2b retain their own nonlocking
consistent read transactions, fresh persisted owner/timezone and captured UTC clock.

Success always has exactly `ok`, `tool`, `data`:

```json
{"ok": true, "tool": "list_tasks", "data": {"tasks": [], "meta": {"current_page": 1, "per_page": 15, "total": 0, "last_page": 1}}}
```

Failure always has exactly `ok`, `tool`, `error`, with no partial data:

```json
{"ok": false, "tool": "get_schedule_for_date", "error": {"code": "invalid_arguments", "message": "Invalid tool arguments."}}
```

| Code | Fixed message | Meaning |
| --- | --- | --- |
| `unknown_tool` | `Unknown tool.` | Exact name absent from registry; no adapter execution. |
| `invalid_arguments` | `Invalid tool arguments.` | Schema/date/period validation or Service ValidationException, including invalid Subject filters and pagination overflow. |
| `not_found` | `Resource not found.` | ModelNotFoundException, including an owner deleted since authentication, or an authorization denial with status 404. |
| `access_denied` | `Access denied.` | Unsaved/missing User identity or other AuthorizationException. |
| `execution_failed` | `Tool execution failed.` | Any other throwable, including unsafe historical attribution, malformed persisted data, SQL, type or internal failures. |

An InvalidArgumentException during adapter execution is an execution failure rather
than a claim that persisted source corruption was caused by input. Raw exception
messages, validation details, SQL, stack traces and foreign identifiers are withheld.
The envelope echoes the caller's tool name; it does not attach exception metadata.

### Payloads and limits

| Tool | Complete `data` shape and boundary |
| --- | --- |
| Schedule | `lessons` plus Service `meta` (`timezone`, inclusive `start_date`, `end_date`). Each lesson exposes `id`, owned `subject` (`id`, `name`, `code`, `color`), owned `teacher` (`id`, `name`), `type`, `room`, local-offset `starts_at`, `ends_at`, owned `replaces_lesson_id`. One explicit date, unchanged starts/ends/ID ordering. |
| Tasks | `tasks` plus `meta` (`current_page`, `per_page`, accurate `total`, `last_page`). Fields: `id`, masked `subject_id`, `title`, `description`, `status`, `priority`, `estimated_minutes`, UTC/null `deadline_at`, `completed_at`. Soft-deleted tasks excluded. Deadline/null/ID ordering retained. Maximum 50 per page; an empty/out-of-range page is valid. |
| Free slots | `slots` plus `meta` (`timezone`, `start_date`, `end_date`). Fields: canonical UTC `starts_at`, `ends_at`, exact integer `duration_seconds`. All normalized PF1 intervals in the bounded period are returned. This is free-time retrieval, not full constraint feasibility or a plan. |
| Sessions | `sessions` plus Service period/timezone `meta` extended with `limit: 100`, exact `returned`, exact `total`, boolean `truncated`. Fields: original `id`, validated `task_id`, `subtask_id`, UTC `starts_at`, `ends_at`, `duration_seconds`, `status`, UTC/null `completed_at`, nullable `actual_minutes`. Start/ID order, half-open overlap membership and all statuses retained. Retired identities are not resolved to successors. |
| G1 | Entire unmodified Service array: `as_of_utc`, `timezone`, `period`, `tasks`, `subtasks`, `study_sessions`. See [G1 metrics](progress-statistics.md). |
| G2a | Entire unmodified Service array: `as_of_utc`, `timezone`, `period`, `days`, `totals`. At most 31 zero-filled daily buckets; exact G1 reconciliation. See [G2a metrics](progress-trends.md). |
| G2b | Entire unmodified Service array: `as_of_utc`, `timezone`, `comparison`, `baseline`, `current`, `delta`, `observations`. Signed integer differences, unknown actual-duration flags and `historical_snapshot: false` retained. See [G2b metrics](progress-comparisons.md). |

Empty collections and zero reports are successful data, not not-found errors.
Session truncation happens only after the Service has validated all matching
historical Task/Subtask relationships, including soft-deleted history. Unsafe
attribution beyond the first 100 fails the whole read. A total of 100 is complete;
101 yields returned=100, total=101, truncated=true. No count is estimated.

## Owner context and safe presentation

This internal API is not an authentication mechanism. Only trusted application code
may pass the User returned by Laravel authentication; H2 must not construct it from
model-selected IDs. Ambient authentication is never substituted for the supplied
owner. Before dispatch, adapters reload the owner's persisted ID/timezone. Unsaved
or stale timezone changes on the caller's model do not affect reads or mutate that
model. G1/G2a/G2b and session reads preserve their own fresh-profile reads as well.

Services use actual owner relationships for collection reads. Task Subject filters
are validated by TaskService. Session Task/Subtask attribution uses the existing
historical ownership validator, not optimistic serialization. Policies for REST
detail/mutation remain unchanged; H1 adds only owner-scoped collection capabilities.

`ToolResultPresenter` explicitly receives the trusted User and mirrors the approved
TaskResource and ScheduleLessonResource field lists and ownership masks. Foreign
Subject/Teacher/replaced-Lesson references serialize as null, including their IDs.
No raw models or unrestricted relationship `toArray()` calls escape. FreeSlotResource
and StudySessionResource are reused because their implementations do not use
authentication or other Request state. No request user resolver is installed or
forged. Regression comparisons verify equivalence with the existing REST output.
Progress results are already safe structured Service arrays and pass through intact.

## Read-only guarantees and future boundaries

The allowlist reaches only the seven read methods. H1 does not create or change
Tasks, Subtasks, Lessons, StudySessions, plans, deadlines, reminders, inbox state,
conversations, messages, tool-call history, statistics snapshots or execution logs.
It sends no HTTP/LLM requests, notifications, queued jobs or background commands.
Discovery and execution need no conversation. No schema or REST contract changes.

H2 can discover schemas, select a tool through an LLM, validate decoded structured
arguments through this Executor, and return the envelope to that LLM. It must handle
clarification, provider transport and multi-step sequencing. H3/future mutations
need their own explicitly authorized allowlist, policies, confirmation and writer
Services; existing read adapters must stay read-only. User-authored text in results
remains data and is not authority to change owner, policy or available tools.

Minimal invocation inside an already authenticated Laravel application handler:

```php
// The surrounding route/handler has already enforced auth:sanctum.
$definitions = app(\App\Services\AI\Tools\ToolRegistry::class)->definitions();
$result = app(\App\Services\AI\Tools\ToolExecutor::class)->execute(
    $request->user(),
    'get_schedule_for_date',
    ['date' => '2026-10-20'],
);
```

This is an internal usage example, not a new production/debug endpoint.

Known limits: session range reads still load/validate the full matching collection
inside the existing Service before presentation is capped; H1 bounds output, not
source-memory usage. Schedule and free-slot outputs retain existing date bounds
and have no extra record cap. Database timestamp edge restrictions and unusual
calendar semantics remain those of each existing Service. G1/G2a/G2b reports retain
mutable historical-data limitations and distinguish scheduled time from known
actual effort. Separate calls do not share one global snapshot. There is no token
budgeting, execution persistence, automatic retry or provider integration.

## Verification

Tests run on the guarded real MySQL `student_planner_testing` database. Integration
tests use DatabaseTruncation so report read transactions are exercised without a
test-owned outer transaction. The local CLI is PHP 8.5.11; implementation retains
the repository's PHP ^8.3/Laravel ^13.17 compatibility and adds no dependencies.

`AiToolFoundationTest` covers deterministic JSON-serializable schemas, unique names,
all argument contracts, enum/date/type/null/unknown-field handling, comparison
constraints, safe exceptions, immutable inputs and no dispatch on invalid inputs.
`AiToolsIntegrationTest` covers every tool with persisted fixtures, DST/skipped-date
behavior, ownership, malformed foreign references, pagination, soft-deleted history,
truncation, REST parity and unknown actual duration. It snapshots every database
table before and after each registered tool for two owners, asserts only nonlocking
SELECTs, unchanged transaction depth, and no HTTP, Bus, Queue or Notification sends.
Existing AI messages, reminders and unread inbox records are included in snapshots.

Final verification commands and results:

| Command | Exit | Tests | Assertions |
| --- | --- | --- | --- |
| `vendor/bin/phpunit tests/Unit/AiToolFoundationTest.php` | 0 | 328 | 1129 |
| `vendor/bin/phpunit tests/Feature/AiToolsIntegrationTest.php` | 0 | 33 | 885 |
| `vendor/bin/phpunit --filter='AiTool' --log-junit /tmp/h1-focused.xml` | 0 | 361 | 2014 |
| Affected regression command below | 0 | 1581 | 13876 |
| `vendor/bin/phpunit --log-junit /tmp/h1-full.xml` | 0 | 4618 | 38938 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

```sh
vendor/bin/phpunit --filter='ScheduleView|LessonSecurity|LessonLifecycle|TaskIndex|TaskLifecycle|TaskSecurity|StudySession|FreeTimeService|FreeSlotsApi|TimeIntervalService|AvailabilityOccurrenceService|Progress|AiPersistence|AuthProfile' --log-junit /tmp/h1-regression.xml
```

The affected regression run includes existing schedule/lesson security, Task
pagination/lifecycle, session reads/lifecycle/concurrency, PF1 interval/availability,
G1/G2a/G2b, AI persistence and authentication/ownership tests. No failures or skips
were reported. Pint's first dirty pass formatted two newly added files; final
dirty and full check passes required no changes.

Stage H1 acceptance: complete. All seven capabilities, safe execution/presentation,
strict validation, output boundaries and read-only verification pass. The full suite
passes without failures or skips. Starting and ending HEAD are
`f26fdb876a145e9ee30766920e44fabb6072a84d`; the starting working tree was clean.
Changes remain uncommitted for review; no commit or push was performed.
