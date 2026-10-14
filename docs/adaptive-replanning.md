# Stage E1 — Adaptive Replanning Analysis and Preview

Stage E1 detects constraint-invalid future reservations and proposes deterministic
one-to-one replacements. All operations are read-only and advisory. It does not
perform Stage E2 confirmation/application, change statuses, create successors,
cancel reminders, infer missed sessions, start jobs or invoke an LLM.

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
confirmation token, preview record, session mutation or new schema is introduced.

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
reservations. E2 must explicitly define/review the treatment of unplaced sources
or regenerate with those sources retained before application. E1 performs neither
retirement nor cancellation. This assumption is exposed, not silently dropped.

## Exact public result

`data` contains these keys:

| Field | Meaning |
| --- | --- |
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
without locks or writes. E2 must own a fresh owner-first transaction, regenerate,
compare the reviewed material outcome, revalidate all constraints and efforts,
resolve explicit partial/unplaced confirmation, and apply history/reminder policy.
No E2 endpoint, confirmation token or persistence behavior is implemented here.

## Verification

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
