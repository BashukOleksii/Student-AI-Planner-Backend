# Stage C — Study Plan Confirmation, Persistence and REST API

Stage C adds authenticated preview, explicit confirmation, atomic MySQL insertion
and owned StudySession retrieval. Stage A accounting/order, Stage B greedy
allocation and C9/PF1/PF2 source/constraint policies remain authoritative. There
is no new schema, planning-run table, optimization algorithm, cache or LLM call.

## Endpoints and input

All four endpoints require `auth:sanctum`, using the existing first-party SPA
authentication contract. Authentication precedes request validation and owned
detail lookup. Responses use Laravel's standard `data` Resource envelope.

| Method | Path | Route name | Success |
| --- | --- | --- | --- |
| POST | `/api/planning/study-plans/preview` | `planning.study-plans.preview` | 200 |
| POST | `/api/planning/study-plans/confirm` | `planning.study-plans.confirm` | 201 |
| GET | `/api/study-sessions` | `study-sessions.index` | 200 |
| GET | `/api/study-sessions/{studySession}` | `study-sessions.show` | 200 |

Preview body:

```json
{
  "start_date": "2026-10-19",
  "end_date": "2026-10-25",
  "task_id": null,
  "subtask_id": null
}
```

The required dates are exact real `YYYY-MM-DD` labels, increasing and covering at
most 31 inclusive local calendar dates in the reloaded persisted user timezone.
The horizon is not expanded. Optional identifiers must be genuine positive JSON
integers or null; numeric strings, floats, booleans, zero, negative numbers and
empty strings are rejected. A non-null Subtask requires its explicit Task ID.
Missing identifiers normalize to null. Explicit foreign, missing, deleted or
incorrectly nested preview targets produce generic 404 responses.

Confirmation body:

```json
{
  "preview_token": "opaque-Laravel-encrypted-token",
  "accept_partial": false
}
```

The token must be a non-empty string in the encrypted base64 transport form and
pass authenticated decryption, purpose/version, ownership and lifetime checks.
`accept_partial` is optional, defaults to false, and must be a genuine JSON
boolean if supplied. Integer 0/1 and string booleans are rejected.

POST validation reads the request body, never query parameters. JSON is the
primary representation; form bodies obey the same strict scalar rules. GET list
validation reads only query parameters. Unknown body/list fields, including
`user_id`, `timezone`, arbitrary `sessions`, status, completion, actual duration
and predecessor attributes, are rejected with field errors even when null.
Middleware exemptions preserve whitespace and empty values for exact validation.

List request:

```text
GET /api/study-sessions?start_date=2026-10-19&end_date=2026-10-25
```

The same required 31-date maximum applies. Listing is unpaginated within the
bounded date range, includes every existing status, and never infers a status
transition. Owned rows overlap the half-open UTC range using
`starts_at < rangeEnd AND ends_at > rangeStart`, ordered by `starts_at`, then ID.
Sources spanning midnight are included; exact adjacency is excluded. C2 full-day
membership handles Kyiv DST, skipped dates and ambiguous local midnights without
fixed 24-hour assumptions. A wholly skipped local date returns an empty list.
When historical date-line reversals yield disjoint local-date occurrences, query
strict overlap with each actual membership interval rather than filling its UTC
envelope gaps. A row spanning several occurrences is still selected only once.

## Public response representations

Preview `data` contains:

| Field | Meaning |
| --- | --- |
| `as_of_utc` | Stage A's captured UTC clock, including microseconds. |
| `start_date`, `end_date`, `timezone` | Requested local dates and persisted IANA timezone. |
| `sessions` | Ordered proposed work IDs, canonical whole-second UTC `starts_at` / `ends_at`, and exact `duration_seconds`. |
| `work_results` | Stage B Task/Subtask allocation records, requested/allocated/unscheduled seconds and observed `reason_codes`. |
| `excluded_items`, `warnings` | Original Stage A diagnostic structures and deterministic ordering, without titles or foreign metadata. |
| `summary` | Exact requested/allocated/unscheduled totals and `fully_allocated`. |
| `preview_token` | Protected confirmation token for this exact request/outcome. |
| `expires_at` | Exclusive UTC whole-second expiration instant. |

The Stage A exclusion structures retain effective UTC deadline Unix seconds,
priority/order and effort metrics as documented in [work selection](planning-work-selection.md).
Proposed session Unix endpoints remain internal; the HTTP session representation
uses UTC strings. Preview performs no business-data writes, preference
materialization, transactions or locks. Repeating preview does not store tokens;
encrypted ciphertext is randomized even for an unchanged plan.

Confirmation returns the same allocation/diagnostic metadata with a fresh planning
clock, replaces proposals with saved StudySession Resources, and omits
`preview_token` / `expires_at`. Saving a partial allocation never counts its
unscheduled seconds as reserved or completed.

Saved session representation (also used by GET list/detail):

```json
{
  "id": 42,
  "task_id": 12,
  "subtask_id": null,
  "starts_at": "2026-10-19T09:00:00Z",
  "ends_at": "2026-10-19T10:00:00Z",
  "duration_seconds": 3600,
  "status": "planned",
  "completed_at": null,
  "actual_minutes": null
}
```

No owner ID, audit fields, relationship objects or rescheduling graph identifiers
are exposed. Historical statuses remain `planned`, `completed`, `missed`,
`rescheduled`, `cancelled`. Raw UTC DATETIME instants are serialized independently
of server timezone. List returns `data: [...]` and `meta` containing exactly
`start_date`, `end_date`, `timezone`; detail returns `data: {...}`.

## Services and reuse

```php
StudyPlanPersistenceService::preview(User $user, string $startDate, string $endDate,
    mixed $taskId = null, mixed $subtaskId = null): array;
// plan, preview_token, expires_at

StudyPlanPersistenceService::confirm(User $user, string $previewToken,
    mixed $acceptPartial = false): array;
// plan, sessions (Eloquent Collection of newly created StudySessions)
```

Controllers orchestrate Requests, Services and Resources only. The persistence
Service is reusable by trusted application callers/future tools, without relying
on authentication globals. `StudyPlanningService::plan()` remains unchanged and
read-only; Stage C calls it for both preview and regeneration.

`StudyPlanConfirmationService` holds protected review issuance/decoding, canonical
comparison, acceptance requirements, future-start checks and exact field
construction. Its clock is supplied explicitly; it has no database or current-clock
reads. It uses the Laravel Encrypter contract and C1 interval arithmetic, with
plain arrays rather than DTOs or repository interfaces.

`StudySessionReadService` supplies owned range/detail reads. A small extraction
exposes `PlanningContextService::dateRange()` / `dateIntervals()` and existing batch
`validateSessionReferences()` for reuse; C10/Stage B public behavior and source
policies remain unchanged. Reads accept valid owned deleted-work history but
fail closed on incompatible Task/Subtask references. Detail queries through the
authenticated owner's relationship before applying `StudySessionPolicy::view()`.

## Protected confirmation protocol

Use the application's Laravel authenticated Encrypter and existing `APP_KEY` /
cipher configuration. The encrypted plaintext is JSON, not PHP serialized objects.
It contains purpose `study-plan-confirmation`, version 1, owner ID, exactly four
normalized request parameters, the complete Stage B result, and integer UTC
`issued_at` / `expires_at`. The expiry is issuance + **900 elapsed seconds**.
The owner and planning data are never sent in plaintext inside the token.

Issuance floors the captured issuance clock to seconds. Expiration is exclusive:
`now >= expires_at` rejects, so fractional issuance can make the usable lifetime
less than 15 minutes by under one second. Tokens issued in the future are rejected.
Purpose/version protect against using another application's encrypted payload as
a planning confirmation. Laravel authenticates ciphertext integrity; no custom
cryptographic algorithm, signing key or durable token table is introduced.

Confirmation does not accept supplied session rows. It decodes the token, verifies
its owner/lifetime, then regenerates from the protected original request under the
transaction described below. Compare all original Stage B outcome fields except
root `as_of_utc`: dates, timezone, session work attribution/intervals/durations and
list order, work allocations/reasons, exclusions, warnings and totals. Recursive
canonicalization sorts associative keys only; scalar types and list order remain
material. JSON object key order/presentation formatting cannot cause a mismatch.

Clock advancement alone is not a change when those reviewed fields remain identical
and valid. A changed estimate, deadline, availability, preference, Lesson, reservation,
completion state or timezone that changes the result requires a fresh preview.
A benign source change producing exactly the same valid proposal/outcome does not
require another review. For example, renaming a Task or configuring a compatible
minimum that does not affect its proposal can still confirm.

Differences cause **409**, never automatic insertion of the regenerated alternative.
A previously owned explicit target disappearing/deleting/reparenting also causes
409 on confirm, while invalid preview targets remain 404. Corrupt selected owned
sources are generic server failures, not ordinary no-capacity results.

Partial plans require `accept_partial: true`. Otherwise return 422 with an
`accept_partial` error. An empty proposal always returns 422 with a `preview_token`
error, including an empty eligible workload whose Stage B summary is vacuously
fully allocated. No meaningless successful persistence operation is created.

## Transaction, lock order and MySQL visibility

Confirmation owns a fresh **outermost** `DB::transaction()` with one attempt. It
rejects invocation inside an existing transaction with `LogicException`; the REST
adapter exposes a generic 500 for that programmer/integration error. This boundary
prevents silently inheriting an older MySQL repeatable-read snapshot. Future tools
must call this Service outside their own transaction; it owns atomicity itself.

The first database read after beginning the transaction locks the current trusted
User row with `lockForUpdate()`, selecting persisted ID/timezone. No planning
SELECT precedes that lock. Only after the lock is acquired do Stage A, Stage B and
shared context read current owned source data. Under MySQL REPEATABLE READ, the
locking read is a current read and does not establish the ordinary consistent-read
snapshot; that snapshot first forms after the prior lock holder has committed.
This also works with READ COMMITTED. No `WITH CONSISTENT SNAPSHOT`, isolation change
or connection replacement is introduced in production.

Cooperating Task/Subtask, Lesson/import, preference and availability mutations
already lock this same owner first. Profile timezone UPDATE necessarily acquires
the same User row's exclusive lock. A confirmation holding it prevents these
writes from changing planning sources between validation and insertion, including
new rows in otherwise empty collections. There is no need to introduce a second
set of cross-table/gap locks or change those mutation Services' lock order.
Session inserts follow owner acquisition and occur in the reviewed proposal order;
normal MySQL FK/write locks still apply.

The in-transaction sequence is:

1. Lock fresh owner; recheck token expiration after any wait.
2. Regenerate Stage A → Stage B and compare the reviewed outcome.
3. Check non-empty and partial-acceptance requirements.
4. Reload Stage A eligibility/unallocated effort and shared C9 context for an
   independent complete-batch gate.
5. Require each proposal's current owned eligible Task/Subtask, positive valid
   interval/whole-second fields, and total allocation no greater than that unit's
   current unallocated effort.
6. For each candidate, call C8 with all **other** proposals exactly once in break
   anchors and workload, excluding itself, alongside original persisted sources.
   Recheck availability, Lessons, reservations, duration, breaks, local daily/ISO
   workload and effective deadline. There are no database reads in that inner loop.
7. Require every start strictly after the full-precision actual UTC check instant.
8. Insert every server-constructed record. An exception or cancelled model save
   aborts and rolls back the entire batch.
9. Recheck lifetime and strictly future starts after insertion, before committing;
   a clock change or expiration during a slow batch rolls everything back.

Complete-batch validation does not rely solely on Stage B's incremental acceptance.
Tests inject matching but invalid proposals to prove the independent gate rejects
overlap, one-second-short breaks, undersized durations, excess work, completion,
deadlines, foreign attribution and collective scheduled-workload violations before
any insert. Other tests retain real Stage B execution and exact accepted sessions.

The future-start guarantee applies at the final pre-commit validation instant;
network delivery or the wall clock can naturally advance after that instant.
No transaction can make a future-start promise permanent. Stage A's captured clock
still governs regeneration, and actual confirmation-time checks add protection.
Previews whose earliest session starts immediately can become stale within a
second. No unapproved confirmation-delay buffer is introduced into Stage B; a
future date or future availability window leaves more time for human review.

## Persistence, replay and concurrency limits

Each record has trusted `user_id`, owned `task_id`, optional matching `subtask_id`,
whole-second UTC `starts_at` / `ends_at`, `status = planned`, and null
`completed_at`, `actual_minutes`, `rescheduled_from_session_id`. The existing Model
and schema are used. Tasks/Subtasks, existing sessions, Lessons, preferences,
availability, progress and Reminders are not changed by ordinary confirmation.

Successful replay is **409**, not an idempotent prior response. Newly persisted
reservations change Stage A/C9 state and the regenerated proposal no longer matches
the token. Concurrent confirmations serialize on the owner lock; the second sees
the first committed reservations and rejects rather than inserting duplicates.
No durable operation identity/receipt is stored. This is the conservative V1 replay
policy while confirmed reservations remain in current state; it is not a promise
of operation identity after arbitrary direct deletion/restoration of database state.

There are no automatic deadlock retries or writes outside the transaction. A
failure rolls back all created rows; MySQL AUTO_INCREMENT gaps are normal and do
not imply partial persistence. A transport failure after commit can leave a client
unsure of success: it can retrieve saved sessions, while replay conflicts rather
than returning a durable original receipt.

Concurrency protection assumes cooperating application writers follow the owner
lock protocol. It does not protect against arbitrary direct SQL/non-cooperating
writers, or prevent a later authorized Lesson/preference/Task change from affecting
an already saved plan after confirmation commits. Later plan review/rescheduling
is outside Stage C. Large plans hold the owner lock while regenerated, revalidated
and inserted; complete-batch checks can grow quadratically, and encrypted previews
can be large. There are no hidden session/query caps or latency guarantees.

## Security and error responses

| Status | Public behavior |
| --- | --- |
| 401 | Unauthenticated requests. |
| 404 | Generic `Not found.` for foreign/missing/inaccessible explicit preview work or session details. |
| 422 | Standard Laravel `message` / `errors` for invalid fields, invalid/tampered/wrong-owner/expired token, required partial acceptance or empty confirmation. |
| 409 | Generic instruction to obtain and review a fresh preview when reviewed outcome/state conflicts. |
| 500 | Generic `The study plan could not be processed.` for source corruption, unsafe stored/query bounds, SQL failures or internal integration errors. |
| 201 | The entire batch of newly planned sessions was committed. |

New-route exception rendering suppresses foreign IDs, model details, SQL errors
and internal traces even with `APP_DEBUG=true`; existing unrelated routes retain
their behavior. Errors are not mislabeled as no-availability. Owned read sources
with incompatible foreign work references fail closed before serialization.
Selected history is validated through owned relationships with deleted work
included. There is no global foreign lookup, hidden repair or GET status mutation.

Stage C itself does not implement general StudySession creation/editing, lifecycle,
rescheduling, reminder workflow, reports, frontend, AI adapters, jobs or calendar
export. [Stage D](study-session-lifecycle.md) now adds explicit completion/miss/cancel
and single-session transfers under the same owner-first protocol, preserving these
preview/confirmation/read contracts. Automatic missed detection and reopening
remain deferred. The Stage C verification record below remains historical.

## Verification

Starting/local/remote `main` HEAD: `94bd30703b050759c3c330cac535a1a6f68865e9`.
The initial tree was clean. Remote verification required the already authorized
`git ls-remote` outside the network-restricted sandbox after DNS resolution failed.
No commit, push, migration, dependency or database configuration change is made.

Tests use real MySQL `student_planner_testing`, guarded by the existing TestCase
configured/live database checks. Pure confirmation Unit tests run without Laravel
or MySQL bootstrap. API/persistence and concurrency tests use DatabaseTruncation
with committed-fixture cleanup, so confirmations execute actual outermost
transactions rather than nested RefreshDatabase savepoints. Other existing suites
retain their testing conventions.

Concurrency tests launch separate PHP processes with independent PDO connections.
Each worker independently checks testing/MySQL/live database safety and receives
the current test connection/key configuration in its process environment, never
in printed output. Both connections explicitly select REPEATABLE READ for these
tests and verify MySQL owner-row waits via `performance_schema.data_lock_waits`,
`data_locks` and `threads`. The test account needs SELECT access to these standard
MySQL instrumentation tables and performance schema must be enabled. These tests
do not substitute sequential calls or skip concurrency proof: two blocked
confirmations yield one committed batch/one conflict; concurrent Task/Lesson,
session and timezone mutations committed before releasing the owner lock make
the waiting confirmation see changed state and reject it.

Ending HEAD is unchanged: `94bd30703b050759c3c330cac535a1a6f68865e9`, on `main`.
Final tree has ten modified tracked files and eighteen new untracked files;
nothing is staged, committed or pushed. All changes belong to Stage C, with no
unrelated changes found or discarded.

| Executed command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit` early existing-suite run | 0 | 2676 | 23398 |
| `vendor/bin/phpunit tests/Unit/StudyPlanConfirmationServiceTest.php` initial focused Unit run | 0 | 32 | 53 |
| `vendor/bin/phpunit tests/Feature/StudyPlanConcurrencyTest.php` | 0 | 5 | 61 |
| Final focused command below (before the additional PF1 assertion) | 0 | 171 | 1199 |
| Existing regression command below | 0 | 1865 | 15457 |
| `vendor/bin/phpunit --filter test_full_plan_is_created_with_exact_fields_and_no_other_application_mutations` (additional saved-session PF1 assertion) | 0 | 1 | 37 |
| `vendor/bin/phpunit` final complete suite | 0 | 2847 | 24598 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Focused Stage C command:

```bash
vendor/bin/phpunit tests/Unit/StudyPlanConfirmationServiceTest.php tests/Feature/StudyPlanApiTest.php tests/Feature/StudySessionApiTest.php tests/Feature/StudyPlanConcurrencyTest.php
```

Existing regression command:

```bash
vendor/bin/phpunit --filter 'TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceTest|PlanningWorkSelectionServiceTest|StudyPlanningServiceTest|StudySessionAllocationServiceTest|WorkloadCapacityTest|TaskLifecycleTest|SubtaskLifecycleTest|TaskSecurityTest|SubtaskSecurityTest|TaskDeletionTest|SubtaskDeletionTest|LessonLifecycleTest|LessonSecurityTest|ScheduleImport|StudySessionPersistenceTest|AuthProfileTest|PlanningPreferenceApiTest|StudyAvailabilityApiTest'
```

Early new-test runs caught empty-string identifier validation and test setup
issues involving literal JSON floats, cached authentication guards, clock/expiry
fixtures and a test-helper naming collision; these were corrected. No existing
test was weakened. One sandboxed chained run could not connect to MySQL (exit 2).
The subsequent focused/full runs accidentally overlapped the shared testing
database, producing invalid fixture-table failures; the full run was interrupted
(exit 130). Both were rerun sequentially against the guarded testing database and
passed. The final complete run also passed after the added explicit PF1 assertion.
These interrupted/environment runs are not counted as successful verification.

Changed files:

| File | Responsibility |
| --- | --- |
| `app/Exceptions/StudyPlanConflictException.php` | Generic stale-preview domain failure. |
| `app/Services/Planning/StudyPlanPersistenceService.php` | Preview issuance and confirmed atomic persistence. |
| `app/Services/Planning/StudyPlanConfirmationService.php` | Protected token, canonical review comparison and server field construction. |
| `app/Services/Planning/StudySessionReadService.php` | Owned bounded range/detail retrieval. |
| `app/Services/Planning/PlanningContextService.php` | Reusable local-date membership and source-reference validation extraction. |
| `app/Http/Controllers/Api/StudyPlanController.php` | Thin preview/confirm orchestration. |
| `app/Http/Controllers/Api/StudySessionController.php` | Thin session read orchestration and Policy check. |
| `app/Http/Requests/PreviewStudyPlanRequest.php` | Exact body dates, strict optional IDs and parent requirement. |
| `app/Http/Requests/ConfirmStudyPlanRequest.php` | Token/boolean validation and rejection of unknown fields. |
| `app/Http/Requests/StudySessionRangeRequest.php` | Shared bounded date validation from PF1. |
| `app/Http/Resources/StudyPlanResource.php` | Public preview/confirmation outcomes. |
| `app/Http/Resources/StudySessionResource.php` | Public saved-session UTC representation. |
| `app/Policies/StudySessionPolicy.php` | Ownership-based view authorization. |
| `bootstrap/app.php` | Exact-input middleware exemptions and generic new-route failures. |
| `routes/api.php` | Four Sanctum-authenticated routes. |
| `tests/Unit/StudyPlanConfirmationServiceTest.php` | Token, review comparison, acceptance and exact attribute checks. |
| `tests/Feature/StudyPlanApiTest.php` | Real MySQL preview/confirm, stale/security/batch/rollback/PF1 integration. |
| `tests/Feature/StudySessionApiTest.php` | Ownership, resource, overlap, DST and historical date membership checks. |
| `tests/Feature/StudyPlanConcurrencyTest.php` | Independent-connection contention and committed-state visibility. |
| `tests/Support/study_plan_confirmation_worker.php` | Guarded portable separate-process confirmation worker. |
| `docs/study-plan-persistence.md` | Stage C contract, guarantees, limits and verification. |
| `docs/architecture.md` | Confirmation flow and fresh transaction boundary. |
| `docs/domain-model.md` | Stateless preview versus persisted sessions. |
| `docs/business-rules.md` | Implemented confirmation/atomicity/creation rules, retaining rule IDs. |
| `docs/database-schema.md` | Current session application behavior; unchanged physical schema. |
| `docs/automatic-study-planning.md` | Separate Stage C consumer of unchanged read-only Stage B. |
| `docs/planning-integration.md` | Stage C use of unchanged C9/C10 source policies. |
| `docs/tasks.md` | Separate explicit planning workflow; unchanged Task lifecycle. |

**Stage C acceptance: complete.** The deliberate outermost-transaction contract,
strictly future final check, conservative conflict replay and documented cooperating
writer guarantee are the V1 boundaries. No essential product-policy conflict,
unrelated architecture layer, migration or deferred feature was introduced.
