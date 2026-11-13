# Planning Preferences + Study Availability Windows

Stage 18B shares exact weekday/clock rules through StudyAvailabilityInputRules
and complete-candidate, overlap and read-only preflight through the existing
StudyAvailabilityService. REST contracts remain unchanged; confirmed AI actions
use the same three transactional writers and owner serialization. Cancelled
model writes fail rather than reporting successful persistence. See
[the AI action contract](stage18/availability-action-contract.md).

Implemented on the existing MySQL schema, under `/api`, using Sanctum's existing
first-party SPA session/cookie contract. Every route requires `auth:sanctum`;
see [authentication integration](auth-profile.md) for credentials and CSRF setup.

| Method | Route | Success |
| --- | --- | --- |
| GET | `/api/planning-preferences` | 200 |
| PUT | `/api/planning-preferences` | 200 |
| GET | `/api/study-availability-windows` | 200 |
| POST | `/api/study-availability-windows` | 201 |
| PATCH | `/api/study-availability-windows/{studyAvailabilityWindow}` | 200 |
| DELETE | `/api/study-availability-windows/{studyAvailabilityWindow}` | 204 |

There is no preference ID route, single-window GET, or `/api/v1` prefix.
Validation/domain errors use Laravel's standard 422 `message`/`errors` response.
Unauthenticated requests return 401. Other users' window IDs and missing IDs
return 404 on mutations, including PATCH requests with invalid input.

## Preferences singleton

GET returns only the authenticated user's settings. A missing physical row is
represented as the following all-null configuration without creating a row:

```json
{
  "data": {
    "max_daily_study_minutes": null,
    "max_weekly_study_minutes": null,
    "preferred_break_minutes": null,
    "min_session_minutes": null,
    "max_session_minutes": null
  }
}
```

PUT requires all five keys, replacing/upserting the complete representation.
It returns 200 even on first materialization. Each value may be null or a
JSON integer from 1 through 65535 (the unsigned SMALLINT storage limit). Booleans and
numeric strings are rejected. Configured values serialize as JSON numbers.
Null clears existing configuration and means no user-configured value; no future product defaults are invented here.

When both bounds are configured, maximum session minutes must be at least
minimum session minutes, and weekly study minutes must be at least daily study
minutes. `PlanningPreferenceService` enforces these reusable rules before any
write. Authenticated relationships set ownership; a payload `user_id` is ignored.
Singleton replacement is transactional and locks the user to serialize concurrent
first materialization/replacement. MySQL CHECK/FK/primary-key rules remain intact.

## Recurring availability

POST requires `day_of_week`, `starts_at`, and `ends_at`. PATCH accepts any subset
of those fields and evaluates the complete candidate from persisted state plus
submitted values. Empty PATCH is valid for a valid existing interval. DELETE
hard-deletes the owned window and returns an empty 204.

Weekdays are ISO: 1 = Monday through 7 = Sunday. Times are recurring **local
wall-clock** values interpreted in the profile's IANA timezone by the implemented
[PF1 Services](planning-foundation.md). They are not UTC instants and are never
converted to UTC by this settings API.

Use canonical `HH:MM:SS`. Starts range from `00:00:00` through `23:59:59`.
Ends additionally permit exactly `24:00:00`; `24:01:00`, `25:00:00`, invalid
minutes/seconds, abbreviated formats, and start `24:00:00` are invalid. The end
must be strictly after the start.

An overnight request such as Sunday `23:00:00` to `02:00:00` returns 422. Submit
two explicit POSTs instead:

```json
{"day_of_week":7,"starts_at":"23:00:00","ends_at":"24:00:00"}
```

```json
{"day_of_week":1,"starts_at":"00:00:00","ends_at":"02:00:00"}
```

The server never automatically splits one request or creates multiple rows.
These are independent requests, not an atomic bulk operation.

A single-window mutation response is:

```json
{"data":{"id":1,"day_of_week":1,"starts_at":"09:00:00","ends_at":"10:00:00"}}
```

List returns `{"data":[...]}`, restricted to the current user and ordered by
weekday, start time, then ID. Neither Resource exposes `user_id` or timestamps.
Unknown request fields are ignored; payload IDs cannot choose/reassign ownership.

## Overlap and authorization

`StudyAvailabilityService` is the authoritative create/update path. For the same
user and weekday, overlap means:

```text
existing.starts_at < candidate.ends_at
AND existing.ends_at > candidate.starts_at
```

This rejects partial overlap, containment, and exact duplicates with a controlled
422 before relying on the unique constraint. `09:00:00–10:00:00` and
`10:00:00–11:00:00` are adjacent and valid. Identical times on different weekdays
or for different users are valid. PATCH excludes its own row, validates complete
state, and leaves persistence unchanged when rejected.

Mutations lock the trusted owning user within a transaction; interval reads use
locking reads so concurrent same-user writes cannot both pass stale overlap
checks. PATCH re-reads the owned window after acquiring the lock. Database
constraints remain defense in depth; direct persistence writes can still bypass
application overlap rules and must not be used by future tools.

The auto-discovered `StudyAvailabilityWindowPolicy` permits owner update/delete
and denies others as not found. PATCH authorization runs in its Form Request
before validation; DELETE authorizes in its Controller. Service lookups also
scope route-bound IDs through the trusted user's relationship. No public input
establishes ownership.

This settings slice does not implement scheduling, defaults, academic CRUD or
AI tools. PF1 free-time calculation and its read-only API are implemented
separately; see [planning-foundation.md](planning-foundation.md). Future AI
adapters must validate structured inputs and call these Services under trusted
user context.

## Planning completion, stage 1: read-only suitability diagnostics

`GET /api/planning/diagnostics` accepts the same inclusive dates and optional
`min_slot_minutes` as free-slots, using the authenticated owner only. Its Resource
returns `as_of_utc`, dates, timezone, saved preferences, warning records
(`configuration` or `calendar`), and raw slots with `can_schedule`, nullable
`candidate` interval and observed `reason_codes`. Candidate tests reuse the pure
existing allocator against the shared context, in one consistent read transaction.
No Task, session, preference or availability is written. The private synthetic
work identity is never returned. Each slot is tested independently, not as a batch
plan, with the current future cutoff. `task_deadline_checked=false` is explicit;
this endpoint cannot establish suitability for a specific Task.

The existing two contradictory bound combinations still fail validation. A minimum
above a daily/weekly limit or a break above max-session produces an advisory,
not a new rejection: a session may span midnight or an ISO-week boundary. Calendar
warnings concern only the requested horizon and do not prove global infeasibility.
Null values keep their existing meaning; no defaults are introduced. Free-slots
continues to expose unpadded raw free time independently.
