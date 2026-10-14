# Stages E1/E2 — Adaptive Replanning Preview and Atomic Confirmation

Stage E1 detects constraint-invalid future reservations and proposes deterministic
one-to-one replacements. All operations are read-only and advisory. It does not
perform Stage E2 confirmation/application, change statuses, create successors,
cancel reminders, infer missed sessions, start jobs or invoke an LLM. Stage E2 now
implements reviewed application in separate confirmation/persistence Services.

F1/F2 manage absolute and relative reminders through the authenticated
[Reminder API](reminders.md). Relative offsets resolve from exact persisted UTC
anchors. F2 atomically reconciles Task/Subtask deadline and completion changes;
completion cancels invalidated reminders without changing StudySessions or other
work. The strict single-target dependency helper and existing S3/Stage D/E2 cleanup
policies remain intact. Session retirement never transfers/clones reminders to a
successor. Notification delivery remains F3 work; hard purge is not exposed.

## REST and application contracts

`POST /api/planning/replanning/preview` (`planning.replanning.preview`) requires
Sanctum authentication and returns 200 with Laravel's `data` Resource envelope.
It reuses the existing `PreviewStudyPlanRequest` for the same exact date/work scope:

```json
{
  "start_date": "2026-10-19",
  "end_date": "2026-10-25",
  "task_id": null,
  "subtask_id": null
}
```

Dates are real, exact inclusive local `YYYY-MM-DD` labels, increasing and at most
31 dates in the freshly persisted user IANA timezone. Optional IDs are genuine
positive JSON integers or null; a Subtask requires its explicit parent Task.
POST reads only its body. Unknown fields, including ownership, status, session IDs,
replacement arrays and persisted attributes, are rejected even when null. Exact
middleware exemptions preserve whitespace/empty input for validation. Foreign,
missing, deleted or incorrectly nested explicit work scope returns generic 404.
Completed owned scope remains inspectable and receives eligibility diagnostics.

```php
StudyReplanningPreviewService::preview(User $user, string $startDate,
    string $endDate, mixed $taskId = null, mixed $subtaskId = null): array;
```

The application result uses integer UTC endpoints; `StudyReplanningResource`
exposes whole-second UTC strings and only selected public session fields.
`StudyReplanningController` contains request/response orchestration only. No
preview record or new schema is introduced. The HTTP adapter now adds an encrypted
`preview_token` and UTC `expires_at`; the application preview operation remains
read-only and encryption-free.

## Detection and classification

Inspect owned **planned** sessions overlapping any actual requested local-date
membership interval, using strict half-open overlap. Optional work scope filters
the inspected rows; all other planning sources still constrain placement.
Past/running planned overlaps are diagnosed, never automatically marked missed.
Completed/missed/rescheduled/cancelled rows are not relocation sources; C9 still
includes completed scheduled workload and Stage A still includes completed credit.

| Classification | Policy |
| --- | --- |
| `valid` | Future, eligible exact work unit and currently feasible reservation; retain it. |
| `requires_replanning` | Future eligible unit, but its interval violates C8 or exceeds the current exact remaining-work reservation capacity. |
| `cannot_replan` | Started/past source or active-work policy excludes safe relocation; retain the persisted reservation. |
| `invalid_source` | Inconsistent owned work/history: reject the entire preview with generic HTTP 500 and this classification, without session data. |

Corrupt sources are not returned as ordinary no-capacity or partially successful
results. Missing/foreign/mismatched Tasks/Subtasks, self/cyclic history, foreign or
incompatible ancestors, non-rescheduled predecessors and planned sources having
any successor fail closed. Ancestors are batch-read by history depth through the
owner's relationship; a boolean successor-existence check also detects unsafe
foreign edges without loading foreign identifiers or metadata. Stage D's pure
`StudySessionLifecycleRules::validateChain()` validates compatible chains.

Stage A supplies authoritative work eligibility, estimates, actual-first completed
credit, effective parent/child deadlines and priorities. Its `fully_reserved`
exclusion alone does not make an existing reservation ineligible: evaluate its
capacity with that reservation's own valid A3 credit removed. For otherwise
eligible sources, the available effort is:

```text
max(0, remaining_work_seconds - (future_reserved_seconds - own_valid_reservation_seconds))
```

Own credit is scheduled duration only when the future reservation ends by the
effective deadline; deadline-invalid reservations already have zero A3 credit.
An original longer than available effort gets
`future_reservation_exceeds_remaining_work`, reusing Stage A's diagnostic vocabulary.
No A1–A6 formula, estimate authority or actual-effort policy is changed.

Existing C8 detection codes retain their order and meaning: duration bounds,
availability containment, overlap, breaks before/after, local daily/ISO-week
workload and deadline. A configured break uses elapsed UTC seconds; exact equality
passes. Completed workload uses original scheduled seconds, regardless of actual
minutes. Distinct records with equal intervals remain additive.

Other policy reasons retain Stage A codes (`completed_task`, `parent_task_completed`,
`completed_subtask`, `subtasks_authoritative`, `missing_estimate`, `deadline_expired`,
`completed_effort_satisfies_estimate`); E1 adds `deleted_task`, `deleted_subtask` and
`session_start_not_future`. Ineligible sources receive policy diagnostics without
attempting to make their historical/unsafe interval a new candidate.

## Shared read-only scenario

`FreeTimeService::sources()` extracts the existing PF1 source assembly: expanded
allowed intervals, effective active Lesson blockers and planned-session blockers
with identity. `calculate()` delegates to the same extraction and retains its
original output, filters, horizon, status and ownership behavior. Blocker identity
is now fetched in addition to original raw UTC start/end fields.

`PlanningContextService::load(..., $anchorEnvelope = null, $includeSources = false)`
preserves the original six-field context by default. Opting in adds PF1 source
records, effective Lesson/planned-session anchors and planned/completed workload
records, retaining identities and workload status. Source selection, full ISO-week
queries, configured break padding and owned historical-work validation are shared
with C10 rather than reimplemented.

The pure `PlanningScenarioService::without($snapshot, $ids = [])` validates unique
positive integer IDs against loaded planned sources and removes them by **identity**
from PF1 blockers, break anchors and workload. It rebuilds free time from allowed
membership and retained blockers; adding the old occupied interval to existing
free time would incorrectly reopen Lesson overlaps or unavailable time. Equal
intervals from other records and Lesson IDs with the same numeric value remain.
Completed workload cannot be excluded. Inputs remain unchanged.

Stage A's backward-compatible signature adds trusted scenario arguments:

```php
PlanningWorkSelectionService::select(User $user, mixed $taskId = null,
    mixed $subtaskId = null, array $excludedPlannedSessionIds = [],
    ?DateTimeImmutable $asOfUtc = null): array;
```

Exclusions must identify owned planned sessions in the selected work scope.
Relationships and original intervals are validated before exclusion. Excluded rows
supply no hypothetical reservation or reservation warning; completed credit is
unchanged. Without arguments, original Stage A clock, output and rules remain.
E1 captures one microsecond UTC instant after owner reload, supplying it to both
Stage A reads, classification and allocation. Strict future integer starts use
the next second after the captured clock's integer part, including exact equality.
Source reads advancing the application clock do not change this run's classifications.

## Horizons, DST and source loading

The request's exact local-date membership controls source selection and every new
placement. Detection checks each original's **full** interval, including ends or
starts crossing a request boundary; the selected range alone cannot label a valid
cross-midnight session unavailable. Detection-only dates therefore cover the
requested labels plus full future originals and labels on both sides of IANA
offset transitions. Context is loaded in at-most-31-date chunks. Identity-based
combination deduplicates the same DB workload row across chunks, preserving distinct
equal rows. Original intervals are never clipped for workload.

Generation clips projected free capacity back to the exact requested membership,
including disjoint historical date occurrences. It does not expand the placement
horizon. C2/PF1 and C7/C9 supply DST gaps/folds, local day and ISO-week/year behavior;
there are no fixed 24-hour day or 168-hour week assumptions.

## One-to-one allocation and projection semantics

Order session results and relocation attempts by effective deadline ascending
(null last), priority descending, original start ascending, original session ID
ascending. Database retrieval order is immaterial.

Only `requires_replanning` sources are hypothetically released. Valid sources,
`cannot_replan` sources, outside-scope planned rows and completed scheduled workload
remain protected. Recompute Stage A once with that entire release set. Work units
share a single remaining unallocated budget across their affected sessions.

The narrow optional `StudySessionAllocationService::allocate(..., $singlePlacement = false)`
mode reuses Stage B's event/calendar-boundary search and C8 acceptance. With `true`,
allocate at most one largest feasible interval for that request, disable tail
balancing intended for two sessions, then stop. Existing default allocation and
Stage B splitting remain unchanged. Each E1 request is capped by original scheduled
duration and the exact unit's residual unallocated effort. Attribution is unchanged.
No predecessor is split into several direct successors; the existing unique direct
successor schema needs no change.

Accepted proposals consume projected free time, become break anchors and contribute
scheduled workload exactly once. After allocation, every proposal is independently
checked with C8 against all other proposals once, excluding itself. Greedy earliest
placement may choose a shorter early slot even when a later longer slot exists;
there is no optimization or mathematical impossibility claim.

**The release hypothesis includes affected sources with no replacement.** Their
IDs remain in `released_session_ids`, while their actual database rows remain
planned. New intervals are feasible under the whole reported hypothesis, not an
instruction to insert only the successful proposals while retaining unplaced
reservations. E2 requires explicit partial acceptance and separate unplaced-cancellation
acceptance, then cancels unplaced sources in the same atomic batch. E1 performs
neither retirement nor cancellation. This assumption is exposed, not silently dropped.

## Exact public result

`data` contains these keys:

| Field | Meaning |
| --- | --- |
| `preview_token`, `expires_at` | HTTP-only protected E2 review and exclusive UTC expiration. |
| `as_of_utc` | Captured UTC `YYYY-MM-DDTHH:MM:SS.uuuuuuZ`. |
| `start_date`, `end_date`, `timezone` | Original request labels and reloaded IANA timezone. |
| `session_results` | Deterministically ordered inspected planned-session results. |
| `released_session_ids` | Ordered owned IDs removed only in the hypothetical state. |
| `work_results` | Projected exact-unit effort, including available unfinished work without a relocation source. |
| `summary` | Counts, relocation durations and whether no adaptive change is needed. |
| `excluded_items`, `warnings` | Original persisted-state Stage A diagnostics, unchanged; projected effort is separately labelled. |

Each `session_results` row contains exactly `study_session_id`, `task_id`,
`subtask_id`, `classification`, `original`, nullable `replacement`,
`untransferred_seconds`, `reason_codes`, `replacement_reason_codes`.
Intervals contain exactly UTC `starts_at`, `ends_at`, `duration_seconds`.
Public rows expose no ownership, model relationships, audit fields or graph IDs.
The pure analysis and internal Service retain integer endpoints and authoritative
classification facts; the Resource selects the public representation.

`reason_codes` diagnose the original; `replacement_reason_codes` diagnose allocation.
Unplaced attempts preserve Stage B observed reasons plus `no_replacement_capacity`.
Partial attempts add `incomplete_relocation`. `single_replacement_duration_limited`
means the allocator stopped after its one permitted placement while effort remained;
it is not a claim of exhausted calendar capacity. A shortened reservation caused
by a smaller work budget also receives `incomplete_relocation`.

For `requires_replanning`, `untransferred_seconds = original duration - replacement
duration` (zero replacement duration when unplaced). For valid/cannot-replan rows,
this value is zero because no transfer is attempted; their original interval is
retained and its duration remains explicitly available.

Each `work_results` row has exactly `task_id`, `subtask_id`, `remaining_work_seconds`,
`unallocated_before_replacements`, `proposed_replacement_seconds`,
`unallocated_after_replacements`. Null estimate-derived fields retain their Stage A
meaning. Remaining unfinished effort, original transfer duration and untransferred
duration are different quantities. Work estimates, status and credit are not changed.

Summary fields are `inspected_sessions`, `valid_sessions`, `cannot_replan_sessions`,
`affected_sessions`, `proposed_replacements`, `unplaced_sessions`,
`partial_replacements`, `original_affected_seconds`, `replacement_seconds`,
`untransferred_seconds`, `no_changes_needed`. Affected means eligible
`requires_replanning` only. Original affected seconds equal replacement plus
untransferred seconds. Counts include past planned overlaps as cannot-replan;
terminal rows are excluded. `no_changes_needed` is true only with no affected or
cannot-replan results, including an empty inspected set.

## Security, read-only guarantees and limitations

401 precedes validation. 422 uses Laravel field errors. Explicit foreign/inaccessible
work returns generic 404. Integrity failures return 500 with generic message and
`classification: invalid_source`, withholding the entire result; other internal/SQL
failures use generic 500 without private details, even in debug mode.

All planning reads use the freshly persisted owner and owned relationships.
No cached timezone, relationships or public owner override is trusted. No planning writes,
destructive locks, transaction designed for mutation, rollback-based temporary
status changes, cache writes, reminder cleanup, events dispatching jobs or token
persistence occur. Tests inspect SELECT-only SQL and full-table snapshots after
repeated authenticated previews under the repository's test authentication/session
configuration (`SESSION_DRIVER=array`). Projection inputs are immutable plain arrays.

The existing first-party SPA authentication flow is preserved. Laravel's
`StartSession::saveSession()` calls the configured session handler after a stateful
request; `DatabaseSessionHandler::write()` inserts/updates authentication-session
records when `SESSION_DRIVER=database` (the default in `config/session.php`). This
is framework authentication maintenance, not replanning persistence, and is not
executed by the E1 application Services. The current tests do not establish an
absolute all-SQL SELECT-only guarantee for database-backed stateful HTTP sessions.
Including authentication housekeeping in that guarantee requires an explicit
session-storage/authentication policy decision; no authentication behavior or
configuration is silently changed by E1.

Queries grow with detection calendar chunks and ancestor depth, not independent
session count. One and 41 affected-source fixtures use identical query counts
below 30. There is no C10 database evaluation inside per-session loops. Pure
self-exclusion/classification and full-batch C8 checks can grow quadratically with
source/proposal counts; long legacy intervals may require many chunks, and deep
history requires additional batch ancestor reads. Stage A still loads relevant
completed/reservation history. No hidden source-count/iteration cap, cache or
throughput guarantee is introduced. Search visits event/calendar boundaries,
not every second of the horizon.

Separate reads are advisory, not an atomic snapshot or authorization to write.
The clock is consistent, but concurrent source changes or elapsed time can stale
the result. Stage D manually validates one explicitly requested interval and
persists immediately under an owner lock; E1 detects and greedily suggests a batch
without locks or writes. E2 owns a fresh owner-first transaction, regenerates and compares the reviewed
material outcome, independently revalidates constraints/effort and applies the
explicit acceptance, history and reminder policy documented below.

## Stage E2 confirmation contract

`POST /api/planning/replanning/confirm` (`planning.replanning.confirm`) requires
Sanctum authentication and returns **200**, because it updates an existing batch
and can create successors. The request accepts exactly:

```json
{
  "preview_token": "opaque-encrypted-token",
  "accept_partial": false,
  "accept_unplaced_cancellation": false
}
```

The token is required, non-empty, protected base64 transport. Both optional flags
default to false and require genuine JSON booleans. Null, 0/1, string booleans,
floats, arrays, whitespace tokens and unknown fields are rejected. Validation
reads only the POST body; query values cannot supply or override it. Work IDs,
source IDs, replacement arrays, statuses and owner IDs cannot be supplied to
confirmation. `ConfirmStudyReplanningRequest` extends the unchanged Stage C
strict body conventions. The Controller delegates to a Service and returns a
`StudyReplanningConfirmationResource` in Laravel's `data` envelope.

The HTTP preview uses `StudyReplanningPersistenceService::preview()` to add a
review token to the unchanged E1 application result. The underlying
`StudyReplanningPreviewService::preview()` remains a pure read-only application
operation, available independently for regeneration. Token issuance stores
nothing. This application boundary is distinct from Laravel's authentication
session housekeeping; Sanctum and session storage are unchanged.

### Protected review

`StudyReplanningConfirmationService` uses Laravel's authenticated Encrypter with
JSON plaintext (`encrypt(..., false)` / `decrypt(..., false)`), without PHP object
unserialization, custom cryptography or token tables. Its payload contains exactly:

```text
purpose: adaptive-replanning-confirmation
version: 1
owner_id: persisted trusted User ID
parameters: {start_date, end_date, task_id, subtask_id}
review: complete internal Stage E1 result
issued_at: UTC Unix integer seconds
expires_at: issued_at + 900 seconds
```

The full internal review is encrypted; only the existing public E1 Resource plus
token/expiry is returned over HTTP. Owner and internal source graph/effort details
are not exposed in plaintext token metadata. Verify purpose, integer version,
owner, exact parameter keys/scalar types, real increasing bounded dates, child
scope, issuance and expiration. Future issuance and malformed/tampered tokens
fail uniformly. Expiration is exclusive (`now >= expires_at` rejects). Issuance
floors to whole seconds, so usable lifetime can be under 15 minutes by less than
one second. Lifetime is rechecked after owner contention, before writes, and
immediately before commit. Stage C's original purpose/version/format and endpoint
remain unchanged; tokens from the two protocols are rejected in both directions.

Regenerate E1 after acquiring the owner lock and compare the **complete internal
result**, excluding only root `as_of_utc`. Token issuance/expiry/ciphertext do not
enter that result. Dates/work parameters come from the token, timezone from the
locked owner. Recursive associative-key normalization reuses Stage C's pure
comparison; scalar types and every list's order remain material. Comparison
includes every inspected source ID, original interval, classification, source
reason, replacement presence/interval/duration, work attribution, priority,
eligibility and effort facts, partial/unplaced diagnostics, released IDs/order,
work results, exclusions, warnings and complete summary. A change returns 409;
the current alternative is never silently applied. Compatible changes producing
identical material outcomes may confirm, subject to independent current validation.

### Acceptance and transitions

| Reviewed source | Required flags | Persisted original | Successor |
| --- | --- | --- | --- |
| requires_replanning, full replacement | Neither | rescheduled | Exactly one planned row |
| requires_replanning, shorter replacement | accept_partial | rescheduled | Exactly one planned row |
| requires_replanning, no replacement | Both flags | cancelled | None |
| valid | Neither; always preserved | unchanged | None |
| cannot_replan | Neither; always preserved | unchanged | None |

Missing acceptance returns 422 on `accept_partial` first, then
`accept_unplaced_cancellation`. Zero actionable sources always return 422 on
`preview_token` with `This preview contains no actionable replanning changes.`,
including valid-only, cannot-replan-only and empty results. A full batch does not
require partial acceptance merely because unrelated unfinished work exists.

Unplaced cancellation is deliberate: it realizes the exact reviewed release
hypothesis, including all unplaced reservations. Keeping an unplaced source planned
while inserting other proposals would apply a different capacity scenario. The
cancelled original retains its interval/work/history and null completion/actual
fields. Its work remains unfinished and can be allocated through a fresh Stage C
preview/confirmation. No Task/Subtask is completed or reopened automatically.

### Fresh transaction and full-batch algorithm

`StudyReplanningPersistenceService::confirm()` rejects ambient transactions and
owns one fresh outermost MySQL transaction with one attempt. Pure token/input
checks can precede it; its **first database read** is the current locking read
of the trusted User row (`SELECT id, timezone ... FOR UPDATE`). No ordinary
consistent snapshot is established before that lock. After a wait, all source
reads see the previous holder's committed state under REPEATABLE READ. Cached
authentication/route models and relationships supply no mutable planning facts.

1. Lock current User and recheck token lifetime/owner.
2. Derive affected work/source IDs from the protected review; lock owned Tasks
   (including deleted history) by ascending ID, corresponding Subtasks by ascending
   ID, then affected connected StudySession chains by ascending ID.
3. Authorize each source with `StudySessionPolicy`; require planned state,
   identical original interval and trusted exact Task/Subtask attribution. Validate
   each connected component with Stage D's pure chain rules: ownership, acyclicity,
   predecessor state and no existing direct successor. Validate positive UTC bounds
   and strictly future sources after contention.
4. Lock/preflight scheduled source-target Reminders through
   `ReminderDependencyService` after all session locks. Source iteration and each
   reminder query are deterministic by ID. Validate owner, exactly one target and
   compatible anchor/offset before any cancellation.
5. Regenerate E1 using protected dates/filters, compare all material results,
   enforce explicit acceptance and exact ordered release-set integrity.
6. Independently reload Stage A excluding **all** released IDs and identified
   PF1/C9 context. `PlanningScenarioService::without()` removes those identities
   from blocking, break and scheduled workload inputs. Validate the entire exact
   replacement batch before writes.
7. Recheck future starts/lifetime; retire all affected sources in reviewed order
   to rescheduled or explicitly accepted cancelled. Preserve intervals, work
   relationships and ancestors, with null completion/actual fields.
8. Independently reload Stage A/C9 after retirement and repeat the full-batch gate
   against persisted rules. No inserts have occurred, so old reservations cannot
   block replacements and cannot count twice toward workload.
9. Insert exactly one trusted planned successor for every non-null proposal,
   setting its immediate `rescheduled_from_session_id`, exact original work IDs,
   reviewed whole-second UTC interval and null completion/actual fields.
10. Cancel only the preflighted scheduled reminders, preserving target IDs,
    message, trigger, anchor and offset. Sent/cancelled and unrelated Task/Subtask/
    general reminders remain unchanged; no reminder is cloned or delivered.
11. Recheck every source and successor start against a new full-precision UTC
    clock, and token lifetime immediately before commit. Any failure rolls back
    predecessors, successors and reminder cancellation together.

The shared Stage D context exposes only its chain-locking helper; E2 never calls
`StudySessionMutationContextService::run()` or `StudyPlanPersistenceService::confirm()`
inside its transaction. Stage D retains its own outermost boundary and rules.
The existing unique direct-successor MySQL key remains a final defense. Terminal
sessions, ancestors and unaffected planned reservations are never rewritten.

The independent pure gate requires unique source IDs, exact ordered release IDs
matching all requires-replanning rows, at most one correctly attributed replacement,
positive representable DATETIME bounds, duration <= original, and strict future
starts. Sum replacement durations per exact work unit against current Stage A
unallocated effort after the full release. Require current eligible owned work and
effective earliest Task/Subtask deadlines. C8 independently checks availability,
effective Lessons, retained sessions, configured duration/breaks and local daily/
ISO-week limits. Each replacement sees **all other proposals exactly once** in
break and workload inputs, excluding itself. DST and local ISO week/year behavior
are inherited from C2/C7; no fixed-duration day/week or new search algorithm is
introduced. Completed scheduled workload remains distinct from actual-first effort.

### Confirmation response

```json
{
  "data": {
    "applied": true,
    "original_sessions": [{"id": 42, "status": "rescheduled"},
                          {"id": 43, "status": "cancelled"}],
    "replacement_sessions": [{"id": 58, "task_id": 12, "subtask_id": null,
      "starts_at": "2026-10-20T13:00:00Z", "ends_at": "2026-10-20T14:00:00Z",
      "duration_seconds": 3600, "status": "planned", "completed_at": null,
      "actual_minutes": null}],
    "summary": {"rescheduled_sessions": 1, "cancelled_unplaced_sessions": 1,
      "created_replacements": 1, "untransferred_seconds": 3600}
  }
}
```

Original/replacement lists preserve reviewed affected/proposal order. Replacements
reuse `StudySessionResource`. No owner, audit, internal token or graph fields are
returned. Untransferred seconds measure original transfer shortfall, **not** total
unfinished effort; no response claims unfinished work is completed.

### Errors, replay and boundaries

| Status | Meaning |
| --- | --- |
| 401 | Unauthenticated, before validation |
| 404 | Inaccessible explicit preview work or missing owned authorization target |
| 422 | Malformed body/token, expiry/wrong owner/purpose, missing acceptance, no action |
| 409 | Changed material result, conflicting source status/time, replay or independent current constraint failure |
| 500 | Generic SQL, unsafe source/reminder integrity or internal/integration failure |

Explicit scope disappearing after issuance conflicts at confirmation. Generic
error rendering withholds SQL, exception traces, foreign metadata and private IDs,
including in debug mode. Unsafe graphs never become ordinary no-capacity output.

A successful token replay returns **409**, with no duplicate successors or second
reminder cleanup. There are no durable replay receipts; they are deferred. A
transport failure after commit requires inspecting saved sessions; retry conflicts
rather than returning an unverified success. MySQL AUTO_INCREMENT gaps on rollback
are normal. Application mutation guarantees cover cooperating owner-first writers,
including Stage C/D, Task/Subtask, Lesson/import and settings; timezone UPDATE locks
the same User row. Arbitrary direct SQL and administrative hard purges are outside
that serialization boundary. Later authorized changes may invalidate a committed
plan. Final future checks apply at the check instant; time can advance after commit.
No arbitrary future-start buffer or automatic missed inference is added.

V1 retains E1's greedy, local one-to-one suggestions. Large reviews/tokens, history
reads and quadratic full-batch checks can consume memory/time and hold the owner
lock longer; no hidden cap or throughput promise is introduced. Frontend, LLM,
cron/jobs, global optimization, multiple direct successors, reminder creation/
cloning/delivery, statistics and general session reopening remain outside E2.
No schema, migration, dependencies or authentication architecture changed.

### Stage E2 verification

Starting and ending HEAD: `4ffcd740883ac3a953a596d9eb903f71d5c65b45`.
Initial tree already contained four tracked modifications (Controller, shared
mutation context, routes and E1 Feature tests) plus five untracked E2 files
(Request, Resource, confirmation/persistence Services and Unit tests). Those
changes were inspected, preserved and completed. Final tree: 17 modified tracked
files and eight untracked files, 25 total, nothing staged, committed or pushed.
No unrelated changes, schema migrations, model/enums, dependencies or authentication
configuration were changed.

All database tests ran serially on real MySQL `student_planner_testing` with the
existing configured/live database guards. Unit tests use pure arrays and no
Laravel/database bootstrap. E2 Feature tests use DatabaseTruncation and committed
fixture cleanup, allowing actual outermost transactions. No SQLite substitution,
skipped concurrency proof or weakened baseline test was used.

| Final verification command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit --filter='StudyReplanning'` | 0 | 303 | 2,006 |
| Required regression filter below | 0 | 2,665 | 20,464 |
| `vendor/bin/phpunit` | 0 | 3,453 | 28,147 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

The complete suite includes unchanged Stage A–E1 coverage and all E2 tests.
Regression was repeated after restoring the shared context's pre-lock ownership/
attribution checks. The focused command includes **14 separate-process MySQL
concurrency tests**, all passing under REPEATABLE READ with actual owner-row waits
verified through `performance_schema.data_lock_waits`, `data_locks` and `threads`.
Workers independently check testing environment, driver and live database, receive
connection/key configuration through their process environment, and never print
secrets. The test account requires access to those instrumentation tables.

Contention coverage includes two E2 confirmations of one multi-source review,
Stage C confirmation in both directions, Stage D cancellation/completion/manual
transfer, Task/Subtask deletion, Lesson mutation and preference/availability
updates. Waiting E2 workers observe actual cooperating Services' committed changes
before current analysis and conflict. A waiting worker also observes rollback of
an already-written predecessor and successfully applies the original review.
A real owner-lock wait with a deterministic clock advanced after acquisition
verifies source-start and exclusive-expiration rechecks. Sequential API calls are
not the concurrency evidence.

Required regression command:

```sh
vendor/bin/phpunit --filter='TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceTest|PlanningWorkSelectionServiceTest|StudyPlanningServiceTest|StudySessionAllocationServiceTest|WorkloadCapacityTest|StudyPlan|StudySession|TaskLifecycleTest|SubtaskLifecycleTest|TaskSecurityTest|SubtaskSecurityTest|TaskDeletionTest|SubtaskDeletionTest|LessonLifecycleTest|LessonSecurityTest|ScheduleImport|ReminderPersistenceTest|AuthProfileTest|PlanningPreferenceApiTest|StudyAvailabilityApiTest|StudyReplanning'
```

Unit coverage includes protected issuance/expiry/owner/purpose/version/tampering,
scalar types and parameter structures, complete material comparisons, associative
normalization/list order, explicit acceptance/no action, release-set/one-successor
integrity, exact multi-unit and shared-unit budgets, full-precision future boundaries,
invalid/overflowing intervals/DATETIME bounds and input immutability.

Feature coverage includes strict body/query/auth isolation, full/multi-source/
multi-unit batches, preservation of valid/cannot-replan/history, partial/unplaced/
mixed acceptance, status/work/estimate/deadline/calendar/settings/reservation/
completed-effort staleness, Stage C/D integration, DST/local ISO weeks, protected
tokens/replay and corrupt source/chain/reminder rollback. Matching intentionally
invalid reviews prove the independent gate rejects availability, deadlines,
breaks, daily/weekly workload, excess unit/aggregate effort and proposal overlap.
All-table snapshots verify rollback after predecessor writes, first/second
successor insertion, cancelled model saves, reminder updates, elapsed starts and
mid-operation expiry. Reminder target/anchor/message/time metadata and sent,
cancelled, unrelated/general history are preserved.

Development results: the initial token/E1 run passed 116 tests / 774 assertions
(exit 0). An unapproved sandboxed new-Feature invocation could not connect to
MySQL (exit 2, 95 setup errors); the authorized run accessed only the guarded test
database. The first authorized Feature run failed (exit 1, 95 tests / 746 assertions,
37 failures), exposing the draft component-filter operation discarding model IDs.
After restoring ID keys, the next run had two fixture failures (exit 1, 95 / 846):
a completed interval in the prior ISO week and a Lesson unintentionally blocking
mixed partial capacity. Corrected fixtures then passed the expanded E2 run (exit 0,
159 / 1,109); added independent-gate/precision/budget coverage passed the final
focused command above. No failed test was deleted or weakened. Final full regression
and formatting pass with the final shared-lock implementation.

| Changed or added file | Responsibility |
| --- | --- |
| `app/Services/Planning/StudyReplanningConfirmationService.php` (new) | Protected review, canonical/acceptance/release integrity and independent pure batch gate. |
| `app/Services/Planning/StudyReplanningPersistenceService.php` (new) | HTTP preview adapter and fresh owner-first atomic confirmation. |
| `app/Services/Planning/StudySessionMutationContextService.php` | Shared chain locking while preserving Stage D pre-lock integrity. |
| `app/Http/Requests/ConfirmStudyReplanningRequest.php` (new) | Strict body/token/two-flag validation. |
| `app/Http/Resources/StudyReplanningConfirmationResource.php` (new) | Public applied-source/replacement/summary result. |
| `app/Http/Controllers/Api/StudyReplanningController.php` | Thin preview/confirm orchestration. |
| `routes/api.php` | Authenticated confirm POST, HTTP 200. |
| `tests/Unit/StudyReplanningConfirmationServiceTest.php` (new) | Pure token/material/release/acceptance/budget/precision coverage. |
| `tests/Feature/StudyReplanningConfirmationTest.php` (new) | MySQL API, atomicity, constraints, history, isolation and integration. |
| `tests/Feature/StudyReplanningConcurrencyTest.php` (new) | Independent-process contention, commit/rollback visibility and multi-source batch. |
| `tests/Support/study_replanning_confirmation_worker.php` (new) | Guarded independent MySQL confirmation worker. |
| `tests/Feature/StudyReplanningPreviewTest.php` | Token/expiry response assertions and unchanged application SELECT-only snapshots. |
| `docs/adaptive-replanning.md` | Complete E2 contract, implementation, verification and limits; historical E1 record preserved. |
| `docs/architecture.md`, `docs/business-rules.md`, `docs/domain-model.md` | Current E2 architecture, approved rule enforcement and history concepts. |
| `docs/database-schema.md`, `docs/database-review-notes.md` | Existing-schema application enforcement; no migration. |
| `docs/planning-foundation.md`, `docs/planning-constraints.md`, `docs/planning-integration.md` | Exact source projection and unchanged PF1/C8/C9 rules. |
| `docs/planning-work-selection.md`, `docs/automatic-study-planning.md` | Unchanged Stage A budgets/B allocator and released unfinished work. |
| `docs/study-plan-persistence.md`, `docs/study-session-lifecycle.md` | Protocol isolation, owner serialization and Stage C/D compatibility. |

Final architecture review confirms thin Requests/Controller/Resource boundaries,
no duplicated planner/constraint rules, no nested mutation transactions, exact
reviewed release semantics, preserved one-direct-successor history and atomic
Reminder cleanup. A changed/untracked-file whitespace/local-document-link audit
passes with zero errors. Approved rule identifiers and historical verification
records remain intact.

**Stage E2 acceptance: complete.** All requested application/security/persistence,
independent-process concurrency, Stage A–E1 regression, full-suite and formatting
gates pass. Known V1 limits are cooperating-writer serialization, greedy proposals,
large-token/batch cost, time advancement after the final check, and deferred durable
replay receipts; none silently changes the approved contracts. The Stage E1
checkpoint below remains a historical record, including its then-open whole-request
authentication-housekeeping scope discussion. E2 explicitly separates that
housekeeping from the application read-only preview guarantee.

## Stage E1 verification (historical checkpoint)

Starting local/remote main HEAD: `ad0232c609cb65c340846f3bbc678178fa721091`.
Ending HEAD: unchanged. Initial working tree: clean. Final tree: 14 modified
tracked files and eight new files, all from this task, with nothing staged,
committed or pushed. Remote main was verified with `git ls-remote`. No migrations,
models, enums, dependencies, DB configuration or authentication changes were made.

All tests ran serially on real MySQL `student_planner_testing`, using the existing
configured/live DB safety checks. The pure tests need no Laravel/database bootstrap.
Feature tests use DatabaseTruncation and remove committed fixtures before the next
application; existing Stage C/D tests still exercise actual transactional persistence
and contention. No SQLite or weakened baseline tests were used.

| Final verification | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| Focused E1 filter below | 0 | 131 | 827 |
| Required Stage A–D/PF1/PF2/security regression filter below | 0 | 2,493 | 19,285 |
| `vendor/bin/phpunit` | 0 | 3,281 | 26,968 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Focused final command:

```sh
vendor/bin/phpunit --filter='StudyReplanningAnalysisServiceTest|StudyReplanningPreviewTest'
```

Required regression command:

```sh
vendor/bin/phpunit --filter='TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceTest|PlanningWorkSelectionServiceTest|StudyPlanningServiceTest|StudySessionAllocationServiceTest|WorkloadCapacityTest|StudyPlan|StudySession|TaskLifecycleTest|SubtaskLifecycleTest|TaskSecurityTest|SubtaskSecurityTest|TaskDeletionTest|SubtaskDeletionTest|LessonLifecycleTest|LessonSecurityTest|ScheduleImport|ReminderPersistenceTest|AuthProfileTest|PlanningPreferenceApiTest|StudyAvailabilityApiTest|StudyReplanning'
```

Earlier development gates also passed: the affected-service regression filter
(`FreeTimeServiceTest|StudySessionAllocationServiceTest|PlanningWorkSelectionServiceTest|PlanningCandidateEvaluationServiceTest`)
ran 406 tests / 2,626 assertions; initial pure E1 tests ran 44 / 109; initial MySQL
Feature tests ran 58 / 615; the expanded focused run passed 110 / 806 before the
final malformed-input and integrity-error coverage. Every PHPUnit run exited 0.

Focused coverage includes exact C8 diagnoses/intervals, self-exclusion across all
sources, genuine integer input, partial/unplaced effort, protected valid reservations,
projected replacements, one-successor limits, repeatability, original intervals
crossing requested boundaries, clock advancement during reads, parent/child authority,
Kyiv gap/fold and ISO-year boundaries, reversed DB retrieval, chain corruption,
source ownership, unchanged table snapshots and SELECT-only planning SQL.
One-/41-source query counts are identical and below 30, not an arbitrary source cap.
Full regression retains Stage A–D accounting, planning, confirmation, lifecycle,
schema, authorization and separate-process contention tests.

## Changed files and final audit

| File | Change |
| --- | --- |
| `app/Services/Planning/StudyReplanningPreviewService.php` | Trusted read-only orchestration, source/history batches and shared clock. |
| `app/Services/Planning/StudyReplanningAnalysisService.php` | Pure detection, deterministic budgets/order and complete projected-batch validation. |
| `app/Services/Planning/PlanningScenarioService.php` | Pure identified source removal/combination with immutable inputs. |
| `app/Services/Planning/FreeTimeService.php` | Extract identical PF1 source assembly with blocker identities. |
| `app/Services/Planning/PlanningContextService.php` | Opt-in identity-preserving C9 context, unchanged default representation. |
| `app/Services/Planning/PlanningWorkSelectionService.php` | Optional trusted planned-source exclusions and immutable UTC clock. |
| `app/Services/Planning/StudySessionAllocationService.php` | Optional single-placement mode, preserving default Stage B behavior. |
| `app/Http/Controllers/Api/StudyReplanningController.php` | Thin preview orchestration using the existing strict Form Request. |
| `app/Http/Resources/StudyReplanningResource.php` | Public UTC result serialization without internal model fields. |
| `routes/api.php` | One authenticated read-only POST route. |
| `bootstrap/app.php` | Exact-input exemptions and private-data-safe errors for the new route. |
| `tests/Unit/StudyReplanningAnalysisServiceTest.php` | Pure classification, projection, placement, precision, malformed inputs and immutability. |
| `tests/Feature/StudyReplanningPreviewTest.php` | Real MySQL API/security, compatibility, calendar, query/snapshot coverage. |
| `docs/adaptive-replanning.md` | E1 contract, hypotheses, guarantees, limits and actual verification. |
| `docs/architecture.md` | Shared-scenario architecture and E2 boundary. |
| `docs/domain-model.md` | Transient adaptive result and one-to-one history semantics. |
| `docs/business-rules.md` | E1 enforcement under preserved rule identifiers. |
| `docs/planning-foundation.md` | Shared PF1 source extraction; unchanged calculation behavior. |
| `docs/planning-integration.md` | Opt-in identified C9 context. |
| `docs/planning-work-selection.md` | Backward-compatible trusted scenario arguments. |
| `docs/automatic-study-planning.md` | Narrow single-placement reuse with unchanged default splitting. |
| `docs/study-session-lifecycle.md` | Separate read-only E1 consumer; Stage D contracts unchanged. |

All 22 modified/added files, including untracked files, passed the final whitespace
and local documentation-link audit with zero errors.

The final architecture audit confirms no second slot-search implementation, no
per-session persisted C10 evaluation, no status/effort formula or C9 policy change,
no writes/locks inside the E1 application slice and no Stage E2 implementation.
Historical verification records and approved rule identifiers are retained.

**Stage E1 planning/application slice: implemented and verified.** The complete projected
result is conditional on its explicit release set, including unplaced affected
sources. **Literal whole-request SELECT-only acceptance: unresolved pending the
authentication-housekeeping scope clarification.** Existing database-backed SPA
session maintenance is preserved; no exception to the requested guarantee is
silently assumed and no authentication change is made without that policy decision.
The scope question does not change the implemented planner or successful regression.
