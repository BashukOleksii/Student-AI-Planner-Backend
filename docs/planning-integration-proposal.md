# C9 — Approved V1 Planning Integration Policy

**Status: C9 approved by the developer; C10 read-only integration implemented.**
The original local proposal was found untracked and is preserved verbatim below
as a historical proposal. Its approval requests and illustrative signature are
superseded by this contract, not current unresolved decisions.

## Developer-approved V1 decisions

- **A — Workload:** select owned `planned` and `completed` StudySessions;
  exclude `missed`, `rescheduled` and `cancelled`. Count each database row once
  using scheduled `ends_at - starts_at`, never `actual_minutes`. Distinct equal
  or overlapping records remain additive. Historical planned/completed records
  count without clock-based transitions or read-side status changes.
- **B — Breaks:** all owned active, non-soft-deleted Lessons (including active
  replacements, with no type/subject/teacher/room/period exemptions) and only
  planned StudySessions are anchors. Null `preferred_break_minutes` means no
  default separation; overlap checks still apply. Configured separation uses
  exact elapsed UTC seconds; 15 minutes means 900 seconds and equality passes.
  PF1 continues subtracting effective Lessons/planned sessions without reserving
  break time or changing its API.
- **C — Deadline:** Task-only evaluation uses its nullable deadline. Subtask
  evaluation requires its owned active parent and uses the earliest non-null
  parent/child deadline, falling back to either present deadline or null.
  Persisted UTC DATETIME values become integer UTC seconds. End equality passes;
  one second later fails. This diagnostic fallback does not repair legacy data
  or weaken the existing write-side parent/child deadline invariant.
- **D — Horizon:** at most 31 inclusive calendar date labels in the persisted
  user IANA timezone. For `[start, end)`, the final included second is `end - 1`,
  so ending at next local midnight adds no date. Count nominal calendar dates,
  not elapsed seconds divided by 86400; retain DST/skipped-date membership.
  No additional future lookahead limit is introduced.
- **E — History:** read-only historical diagnostic evaluation is allowed.
  No current-clock restriction, missed-status inference or hidden repair occurs.
  This does not authorize future creation in the past.

## Final application integration contract

```php
public function evaluate(
    User $user,
    array $candidate,
    mixed $taskId = null,
    mixed $subtaskId = null,
): array;
```

One focused Eloquent Service reloads trusted persisted ownership/timezone,
validates genuine integer candidate/IDs and safe UTC DATETIME boundaries, loads
exactly five nullable preferences without creation/defaults, and delegates to
PF1 with a null minimum filter and then C8. Optional active work targets resolve
only through owned relationships; a child requires the specified parent.

Workload queries cover complete local ISO weeks touched by the candidate,
expanded with C2 full-day membership, then converted to a safe UTC envelope.
All event queries use strict overlap (`starts_at < upper AND ends_at > lower`),
including events beginning before the envelope. Break queries expand candidate
UTC bounds by the configured break in integer seconds, with overflow/range
checks and no smaller hidden limit. Preserve original scheduled intervals and
include each row once per role; never merge workload records.

Batch-load owned Tasks and their Subtasks with deleted history to validate
selected session ownership and exact parent compatibility. Invalid selected
source references fail closed with a generic integrity error, never skip a row
and undercount workload, load foreign details, repair data or issue N+1 reads.
Valid historical soft-deleted references retain their contributions.

Return C8's ordered violation list unchanged. C1–C8 remain authoritative for
all calculations; the assembler adds source selection and boundary validation.
No endpoint, AI adapter, transaction, locks, persistence or automatic planning
is part of C10. Current multi-query reads are diagnostic, not a commit snapshot.
See [constraints](planning-constraints.md) and [business rules](business-rules.md).

## Implemented behavior and remaining work

At the C9 checkpoint PF1/PF2 (C1–C8) were already implemented. C10 now adds
the read-only assembler and MySQL Feature tests; see [the C10 contract](planning-integration.md).
The historical record below describes the earlier proposal, not current implementation status.

At C9/C10 completion, work eligibility and remaining effort were deferred;
Stage A now implements their read-only selection/accounting contract. Remaining
write-side work includes past-start restrictions for creation, any additional
lookahead/defaults, confirmed session creation, bulk planning/rescheduling and
graph reconciliation,
reminders and AI orchestration. Future mutations must reload/revalidate current
owned work, timezone, preferences and sources under appropriate owner-first
transactions/locks, include accepted batch candidates, and persist atomically.
An earlier diagnostic success is not permission to write.

The proposal's optional workload omission/clipping optimizations and illustrative
model-argument signature are not adopted by this final contract. Full scheduled
source intervals are passed to C7; bounded selection replaces entire-history reads.
No critical policy outside approvals A–E is introduced.

Stage A subsequently implements read-only work eligibility and remaining effort;
see [its contract](planning-work-selection.md). This follow-up does not change the
approved C9/C10 source policies or historical proposal preserved below.

## Previously proposed decisions — preserved historical record

<details>
<summary>Original local C9 proposal before developer approval (superseded)</summary>

# C9 — Planning Source Policy and Integration Proposal

**Status: proposal awaiting developer approval.** Only verification and design
are complete. Recommendations below are not approved business rules or implemented
source filters. No integration Service, endpoint or session writer is added.

## 1. Verified baseline and existing rules

Audited and verified HEAD: `27ff86e7df1bb0de1382aa52bb8dc00571299e4b`; initial
working tree clean. The effective testing connection and `SELECT DATABASE()` both
identified MySQL `student_planner_testing` before database tests.

| Verification | Passing tests | Assertions |
| --- | ---: | ---: |
| `php artisan test --filter=BreakConstraintServiceTest` | 95 | 291 |
| `php artisan test --filter=WorkloadConstraintServiceTest` | 101 | 276 |
| `php artisan test --filter=PlanningConstraintServiceTest` | 137 | 260 |
| `php artisan test` | 2178 | 20301 |

`vendor/bin/pint --test` and `git diff --check` passed. No calculation defect was
demonstrated; C1–C8 remain unchanged.

Existing approved behavior, distinct from the proposed policies:

- [PF1](planning-foundation.md) subtracts owned, non-deleted active Lessons and
  planned StudySessions, including on historical dates. Active replacements block;
  cancelled/replaced originals and soft-deleted Lessons do not.
- [C5–C8](planning-constraints.md) evaluate explicit inputs, exact half-open UTC
  seconds and nullable preferences without defaults. C7 adds each supplied record
  independently; C8 checks containment, breaks, workload and deadline but selects
  no sources and proves no ownership or database freshness.
- BR-LIM-001/002 constrain planned workload. BR-SES-003 and BR-STAT-003 distinguish
  scheduled duration from `actual_minutes`; `completed_at` is lifecycle metadata,
  not a stored actual session interval. BR-FREE-004 does not enumerate break anchors.
- BR-SES-001 requires compatible owner/Task/Subtask references. StudySessions have
  lifecycle history without soft deletion. Their FKs enforce existence, not that
  compatibility; [persistence tests](../tests/Feature/StudySessionPersistenceTest.php)
  demonstrate malformed references and that linking a successor does not transition
  its predecessor. S3 retains past planned and completed session history even when
  its work is soft-deleted; see [Task deletion](tasks.md#s3-safe-deletion-and-dependency-cleanup).
- BR-PLAN-002/003 require deadline/conflict safety; BR-PLAN-005 requires remaining
  effort. BR-TSK-005 excludes completed Tasks from future automatic planning until
  reopened. Parent/child lifecycle independence does not waive those planning rules.

Some older schema prose still labels free-time calculation unimplemented and null
preferences as future system defaults. Current PF1/PF2 code and their contracts
are authoritative for implemented behavior: no defaults exist. This proposal does
not amend that prose, approved rule IDs or future default-policy permissions.

## 2. Recommended workload policy — approval required

Recommend **planned + completed StudySessions**, measured by scheduled intervals,
as the candidate's existing workload. This counting interpretation extends beyond
the literal planned-only alternative and needs explicit approval for BR-LIM-001/002.

| Status | Planned-only alternative | Recommended contribution | Consequence |
| --- | --- | --- | --- |
| `planned` | Include | Include scheduled duration | Existing reservations consume their local day/week budget. |
| `completed` | Exclude | Include scheduled duration | Completion does not release budget already allocated in that period. |
| `missed` | Exclude | Exclude | Unperformed work may use another future slot without counting its missed predecessor. |
| `rescheduled` | Exclude | Exclude | The qualifying successor contributes instead of the superseded row. |
| `cancelled` | Exclude | Exclude | Cancelled work no longer consumes a planning reservation. |

Planned-only is simpler and tracks current reservations, but marking work completed
can immediately permit more scheduling in the same day/week. Counting all statuses
would retain missed/cancelled reservations and double-count rescheduling history.
Neither alternative is mandated by the current pure Services.

Under the recommendation, earlier completed sessions and later planned sessions in
a candidate's ISO week both contribute. Selection uses status, not `starts_at >= now`:
past planned rows still count until explicitly changed; reads perform no cleanup.
Even an unusual future-dated completed row is not silently reclassified. Actual
effort may differ from the scheduled contribution and remains a separate statistic.

Use source IDs to supply each record once, never deduplicate equal intervals or
merge overlapping records. The new candidate is not an existing record. A successor
link alone must not make a still-planned predecessor disappear from the budget.
Future rescheduling must atomically transition the owned predecessor and revalidate
the replacement; no public arbitrary `exclude_session_id` bypass is proposed.
Retained sessions are not discarded merely because their owned work is soft-deleted.

## 3. Recommended break policy — approval required

| Possible anchors | Benefit | Limitation |
| --- | --- | --- |
| Planned StudySessions only | Minimum change; separates study reservations. | Allows study immediately adjacent to a Lesson. |
| Effective Lessons + planned StudySessions **(recommended V1)** | Provides transitions around classes and study; matches PF1's effective event set. | Completion removes a session anchor; no guaranteed post-completion rest. |
| Effective Lessons + planned + completed scheduled StudySessions | Retains separation after marking work completed. | Scheduled boundaries may differ from actual work; historical overlaps become additional rejections. |

Recommend all owned, non-deleted active Lessons regardless of type, plus owned
planned StudySessions. Cancelled/replaced/deleted Lessons and non-planned sessions
are excluded. This assumes Lessons warrant breaks; BR-FREE-004 does not currently
approve that assumption. The completed-session alternative is feasible with stored
scheduled intervals, but `actual_minutes` and `completed_at` cannot reconstruct
actual start/end boundaries. V1 would not claim a physiological rest guarantee.

Overlap prevention and separation remain distinct. PF1 removes occupied time;
C6 requires configured elapsed gaps before/after its selected anchors. With null
break configuration, there is no gap requirement but anchor overlap still yields
`overlapping_break_anchor`. A candidate overlapping an effective event can therefore
receive both that code and `outside_available_study_time`; retain C8's ordered
codes rather than silently collapse or reinterpret them. PF1's API is unchanged.

## 4. Proposed read-only application contract

Suggested location: `App\Services\Planning\PlanningCandidateEvaluationService`.
One focused Service, ordinary arrays and dependency injection; no repository/DTO
layer or new persistence. Illustrative signature, not implementation:

```php
public function evaluate(
    User $user,
    Task $task,
    array $candidate, // exactly ['start' => int, 'end' => int]
    ?Subtask $subtask = null,
): array; // the unchanged C8 list of violation codes
```

The future authenticated adapter supplies the trusted User. The Service reloads
that User by key for persisted timezone, then the active Task through `tasks()`
and the optional active child through that exact Task. Stale/forged models or
foreign/deleted targets fail as not found before candidate processing. Request
owner IDs, timezone, preferences, source arrays and deadlines are never trusted.

Proposed assembly:

1. Validate the candidate through C1, UTC DATETIME representability and the horizon
   below. Load `planningPreference()` read-only and explicitly construct exactly
   `min_session_minutes`, `max_session_minutes`, `preferred_break_minutes`,
   `max_daily_study_minutes` and `max_weekly_study_minutes`; a missing row supplies
   five nulls. Reject malformed
   stored timezone/configuration instead of repairing it or adding defaults.
2. Call `FreeTimeService::calculate()` for the candidate's touched local-date
   envelope with `minimumSeconds = null`; C5 handles candidate duration. Missing
   availability remains empty and C8 reports outside available study time.
3. Select workload from `studySessions()` using the approved status policy and
   affected period envelope below. Break sources use that relationship separately
   plus `lessons()` with active status and normal soft-delete exclusion. No joins
   to active work may discard retained session history.
4. Retrieve only IDs, status, UTC start/end and session reference keys needed for
   validation. Bulk-load owned source Tasks/Subtasks with soft-deleted history,
   verify owner and exact parent-child compatibility, and reject selected malformed
   references with a generic validation error, without foreign details. Do not
   lazily read foreign relationships, skip unsafe selected rows or perform repair.
   Full rescheduling-graph reconciliation belongs to its later lifecycle module.
5. Parse raw DATETIME originals explicitly in UTC with exact format round-trip
   validation, following FreeTimeService. Do not use server-local parsing, cast
   presentation timezone, `actual_minutes` or `completed_at` as interval endpoints.
6. Recommended deadline: earliest non-null Task/selected Subtask deadline, with
   parent fallback for a child lacking its own deadline; both absent means null.
   This derived fallback needs approval, changes no stored deadline, and does not
   excuse a malformed parent-child deadline relation. Delegate all checks to C8.

Standalone evaluation performs SELECTs only, without writes, cleanup, locks or
preference creation. Its several reads are advisory, not an atomic commit snapshot.
An empty result is not work eligibility: future creation must separately enforce
completion/remaining-effort rules. Diagnostics can inspect completed work without
authorizing new planning for it.

### Calendar envelopes and bounded queries

Recommend at most **31 inclusive local calendar dates per candidate envelope**,
matching PF1's limit, including historical diagnostics. This bounds span, not a
31 × 86400-second duration or an implicit lookahead measured from the current clock.
Determine all touched labels via UTC timezone segments, with the exclusive end
excluded; inspecting only endpoints is insufficient for unusual date-line changes.

For workload, expand touched dates to their complete ISO weeks: nominal Monday
through the final Sunday, at most 42 local dates for a 31-date envelope. Use C2's
full-day membership expansion (`00:00:00–24:00:00`, all weekdays) to obtain real
UTC period coverage. Query its earliest start/latest end envelope; do not add fixed
UTC days or automatically select one ambiguous midnight. This also handles missing
dates, midnight transitions and ISO week-years. With only daily limits, complete
local-day coverage suffices; with both limits null, workload queries can be omitted.

Every event query uses owned scope and strict overlap:
`starts_at < upperUtc AND ends_at > lowerUtc`. Include events beginning before
the envelope. Fetch workload across full periods, not just candidate overlap;
other days in its week can exhaust its budget. Validate source intervals, then
clip each record separately to the workload envelope before C7. Portions outside
affected periods are irrelevant; this avoids iterating years of a spanning record
without merging different records or changing candidate-period totals.

For anchors, let `b = preferred_break_minutes × 60`, or zero when null. Query the
UTC envelope `[candidate.start - b, candidate.end + b)` with checked arithmetic.
This catches preceding/following events outside the candidate's availability/day;
anchors at exact required-gap equality may be omitted because they cannot violate.
The buffer is elapsed seconds, not calendar arithmetic, and never exceeds 3932100
seconds per side. Reject unrepresentable query bounds explicitly. Keep original
anchor endpoints for C6 normalization.

Use a small number of bulk queries; at most separate workload/anchor session
queries plus Lesson and reference-map queries, independent of per-record lazy
loading. Deduplicate repeated query hits by session ID within each role; a row may
serve both roles legitimately. Existing `(user_id, status, starts_at)` indexes
support selection, but an `ends_at` predicate still requires filtering. A bounded
time envelope does not guarantee few scanned/returned rows. Profile large fixtures
and query plans; never silently truncate source inputs to improve performance.

### Future transactional revalidation

A future confirmed writer must acquire the owner lock first, reload target work,
timezone, preferences and all sources, rerun assembly/C8, and persist only within
that same transaction. Lock target Task/child and relevant session rows in stable
order compatible with existing owner-first mutation Services. An earlier read
result cannot authorize a write. Batch creation must also account for accepted
in-transaction candidates; independent evaluations are not whole-plan validation.
This convention coordinates participating Services, not arbitrary database writers.

## 5. Decisions still requiring developer approval

1. **Workload:** approve planned + completed scheduled duration, excluding missed,
   rescheduled and cancelled, rather than planned-only or actual-effort accounting.
2. **Breaks:** approve effective Lessons + planned sessions, including the consequence
   that completion removes an anchor; alternatively choose completed scheduled
   anchors explicitly. Confirm that Lesson types need no separate exemption.
3. **Deadline:** approve the parent/child minimum and parent fallback described above.
4. **Horizon:** approve the 31-local-date candidate span and historical diagnostic
   scope. Whether future creation accepts past starts or needs a separate lookahead
   cap remains outside this read-only contract and must be settled before a writer.

These decisions are not granted by this proposal or by passing C6–C8 tests.

## 6. Future implementation and test checkpoints

After explicit approval, implement only the read-only assembler and MySQL Feature
tests first; reuse C8 unchanged. Add separate, later work-target eligibility,
confirmed single-session persistence/rescheduling and multi-session planning tasks.
No endpoint, generator, writer, reminder or AI capability is authorized here.

The assembler's acceptance suite should cover all five statuses in historical and
future periods; scheduled-vs-actual differences; rescheduled predecessors and
successors; retained deleted-work history; foreign owners and malformed own FKs;
forged/cached model attributes; missing/all-null preferences without inserts;
read-only SQL traces and unchanged table snapshots; and exact C8 code ordering.

Boundary tests must cover complete-week workload outside candidate overlap,
spanning events, exact adjacency/gap equality, anchors outside the PF1 date range,
31/32-date spans, ISO week-years, Kyiv gaps/folds, midnight transitions, Apia and
non-hour offsets, clipping equivalence and server-timezone independence. Larger
collections should preserve distinct overlapping record contributions and results
under input reordering, show no N+1 growth, and exercise bounded predicates and
existing indexes. Performance claims require measurement; cardinality rejection
limits, if later needed, require an explicit contract rather than false valid
results from truncated inputs. Creation tests must separately prove revalidation
under owner locks and atomic rollback; no such writer exists in C9.

</details>
