# Stage 18A — AI study availability read Tool

`list_study_availability_windows` reads the authenticated student's configured
recurring weekly availability. It does not calculate free time or change anything.
R22 is incrementally supported for discovery only; availability proposals remain
Stage 18B work. Discovery now contains 28 Tools: 10 reads and 18 existing proposals.

## Exact provider-independent definition

```json
{
  "name": "list_study_availability_windows",
  "description": "Read configured recurring weekly study availability windows, not calculated free slots; ordered by weekday, start time and ID with bounded pagination.",
  "parameters": {
    "type": "object",
    "properties": {
      "day_of_week": {"type": "integer", "minimum": 1, "maximum": 7},
      "page": {"type": "integer", "minimum": 1, "maximum": 9223372036854775807},
      "per_page": {"type": "integer", "minimum": 1, "maximum": 50}
    },
    "required": [],
    "additionalProperties": false
  }
}
```

`page.maximum` is `PHP_INT_MAX` (the value above is the verified 64-bit runtime).
All arguments are optional. Omission means no weekday filter, page 1 and 20 rows
per page. `day_of_week` follows ISO weekdays: Monday=1 through Sunday=7. Supplied
values must be genuine integers: strings, floats, booleans, nulls, nested values,
nonpositive values, weekday 8 and page size 51 fail. Unknown properties, including
`user_id`, `owner_id`, `timezone`, `class` and `method`, fail before database reads.
The offset must fit a PHP integer: `page - 1 <= intdiv(PHP_INT_MAX, per_page)`.
Overflow returns `invalid_arguments`; no overflowing SQL offset is attempted.

Gemini preserves this schema, including optional omission. OpenAI's existing
strict adapter requires all three wire properties with nullable integer types,
then removes recognized optional nulls before executor validation. Explicit nulls
passed directly to the executor remain invalid; unknown null properties remain
unknown and invalid. Bounds and `additionalProperties: false` are preserved.

## Result and example

Illustrative request (the ID below represents an existing owned row, not a
server-created or invented row):

```json
{"day_of_week": 1, "page": 1, "per_page": 20}
```

ToolExecutor returns its existing success envelope:

```json
{
  "ok": true,
  "tool": "list_study_availability_windows",
  "data": {
    "windows": [
      {"id": 12, "day_of_week": 1, "starts_at": "16:00:00", "ends_at": "24:00:00"}
    ],
    "meta": {
      "timezone": "Europe/Kyiv",
      "current_page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1
    }
  }
}
```

Each window has exactly `id`, `day_of_week`, `starts_at`, `ends_at`, matching
StudyAvailabilityWindowResource. Times remain local recurring `HH:MM:SS` strings;
seconds and the end-of-day `24:00:00` are preserved without date expansion, UTC
conversion, offset annotation or normalization to midnight. The timezone is the
fresh persisted User profile value, never a model argument or unsaved override.

Ordering is ascending `day_of_week`, `starts_at`, `id`. Filtering precedes counting
and pagination. At most 50 rows are returned; `total` counts all owned matches,
`last_page` is at least 1, and no data is silently truncated. Empty or out-of-range
pages succeed with `windows: []` and accurate metadata; `current_page` retains the
requested valid page. Call again with later pages when needed. Separate calls are
live reads and do not share a historical snapshot; concurrent edits can change
page membership and totals. There is no cross-call snapshot token.

## Authorization and read-only guarantees

Trusted Laravel application code supplies the authenticated User. Tool arguments
cannot choose an owner. The adapter reloads that user's persisted ID/timezone and
queries only `User::studyAvailabilityWindows()`. Ambient authentication does not
replace the supplied trusted owner. No unrelated user, profile, timestamps or raw
model attributes are returned. This internal Tool is not an authentication API;
existing chat routes retain Sanctum authentication and conversation ownership.

Discovery and dispatch remain fixed server allowlists. ToolExecutor and
ToolArgumentValidator stay unchanged. Errors use the existing sanitized envelope:
`invalid_arguments`, `unknown_tool`, `access_denied`, `not_found` or
`execution_failed`; no SQL, credentials, exception messages or foreign IDs escape.
Unsaved/deleted model instances are denied; an old still-persisted-looking instance
whose row no longer exists fails the persisted owner reload safely.

The Tool performs only nonlocking SELECTs. It creates no availability, preferences,
StudySessions, reminders, pending actions, messages, notifications or jobs. The
surrounding successful chat turn still persists its ordinary conversation history
and replay result through existing orchestration. No availability writer,
overlap validator, persistence abstraction, route, proposal action or migration
is introduced. Existing mutation/confirmation guards are unchanged.

Configured weekly availability specifies allowed recurring wall-clock periods.
`find_free_study_slots` calculates concrete free UTC intervals after considering
existing schedule and reservations; it is still not a full planning feasibility
guarantee. Reading availability neither authorizes modifying it nor supports a
claim that a window changed. The server-owned instructions state these boundaries.

See [implementation results and Stage 18B design](backend-stage18a-result.md),
[planning settings](../planning-settings.md) and [AI Tool foundation](../ai-tools-foundation.md).
