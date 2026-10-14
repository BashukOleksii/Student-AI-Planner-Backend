# Stages F1/F2/F3a — Reminders and In-App Notifications

F1/F2 implement authenticated reminder management and deterministic target
synchronization. F3a adds atomic delivery to a persistent in-app inbox, a bounded
Artisan dispatcher, minute scheduling and authenticated inbox reads. Delivery means
a committed inbox record, not a student opening it or an external device receiving
it. There is no email, push, SMS, broadcasting, frontend or AI adapter. Queues and
their existing infrastructure are unchanged. One additive notifications migration
is added; Reminder models, enums, signed SMALLINT offsets, FKs and CHECKs remain
unchanged. See [the schema](database-schema.md#317-notifications-f3a) and
[stable BR-REM rules](business-rules.md#10-reminder-rules).

## API contract

All five endpoints require `auth:sanctum`, owned lookup and `ReminderPolicy`.

| Method | Endpoint | Success |
| --- | --- | --- |
| GET | `/api/reminders` | 200, paginated resources |
| POST | `/api/reminders` | 201, created resource |
| GET | `/api/reminders/{reminder}` | 200, owned resource |
| PATCH | `/api/reminders/{reminder}` | 200, updated resource |
| POST | `/api/reminders/{reminder}/cancel` | 200, cancelled resource |

Creation has two exclusive modes. Optional `message` and target identifiers use
F1's exact rules in both modes.

**Absolute:** required `trigger_at`; omit `anchor` and `offset_minutes`. Server
metadata is null. All previously valid F1 requests remain valid.

```json
{
  "message": "Review coursework",
  "trigger_at": "2026-10-20T07:45:00Z",
  "task_id": 12
}
```

**Relative:** required compatible non-null `anchor` and signed integer
`offset_minutes`, exactly one non-null target, and no `trigger_at` key (even null).

```json
{
  "message": "Deadline in one hour",
  "task_id": 12,
  "anchor": "task_deadline",
  "offset_minutes": -60
}
```

Supplying either relative field selects relative validation; missing pairs,
explicit null pairs, invalid types, general relative reminders and mixed modes
return 422. As in F1, absolute clients omit server-null anchor/offset metadata
rather than supplying null overrides. Ownership, status, `sent_at`, ID and audit
fields remain server controlled and forbidden, including explicit null values.
Every unknown writable body field is rejected. Query values cannot supply or
replace body fields. Malformed JSON, scalar roots and nonempty array roots fail
validation. Cancellation accepts no fields; an absent body, `{}` or conventional
empty `[]` is accepted.

## Targets, offset and UTC resolution

IDs must be genuine positive JSON integers; numeric strings, float tokens such
as `12.0`, booleans, zero and negative numbers are invalid. At most one target is
non-null. Absolute general reminders have no target and require a meaningful
message, containing something other than Unicode separators, controls/format
characters and whitespace. Targeted messages may be null/omitted. Non-null
messages must be UTF-8 strings of at most 500 characters; whitespace is preserved.

| Target | Relative anchor | Exact persisted timestamp |
| --- | --- | --- |
| Owned Task | `task_deadline` | That Task's `deadline_at` |
| Owned Subtask | `subtask_deadline` | That Subtask's own `deadline_at` |
| Owned StudySession | `session_start` | That exact session's `starts_at` |

A missing Task/Subtask deadline rejects relative creation or explicit offset
editing. The parent's deadline is never substituted for a child's missing or
changed deadline. A successor session is never substituted for the linked source.

The existing field is a **signed SMALLINT**: offsets must be genuine integers in
`[-32768, 32767]`. Negative means before, zero at, positive after the anchor.
There is no clamp, truncation or local wall-clock addition:

`resolved UTC epoch seconds = persisted anchor UTC epoch seconds + offset_minutes * 60`.

`ReminderRules` shares F1's strict `StudySessionLifecycleRules` UTC parser. Wire
timestamps are canonical `YYYY-MM-DDTHH:MM:SSZ`, real calendar dates, whole seconds,
years 1000–9999 inclusive. Offsets, fractional seconds, lowercase `z`, rollover
values and whitespace normalization are rejected. DATETIME is read explicitly as
UTC, independent of server/user timezone, DST transitions or ISO weeks. Out-of-range
results reject explicit creation/offset edits with 422. Arithmetic uses bounded
integer seconds, including leap days and year boundaries.

Creation retains F1 eligibility: owned active pending Task; active pending Subtask
under an active pending owned Task; or owned planned future StudySession with an
active pending owned Task and compatible active pending child when present.
General absolute reminders require no work target. Foreign, missing and ineligible
targets give the same generic 422 `target` error without private identifiers.

Every newly created or edited scheduled trigger must remain strictly after
full-precision current UTC time. A whole-second instant in the current fractional
second is already elapsed. Creation and explicit edits check after owner contention
and again after saving. Session creation and offset editing also recheck current
future-session eligibility before commit. Message-only edits preserve the stored
trigger and still require it to be future, consistent with F1; they do not resolve
or repair the anchor. No minimum lead-time buffer is introduced.

## PATCH, cancellation and history

Scheduled absolute PATCH accepts only `message`, `trigger_at`, unchanged from F1.
Scheduled relative PATCH accepts only `message`, `offset_minutes`. Supplying an
offset, including an equal value, requests resolution against the current locked
target and persists offset/trigger atomically. Relative `trigger_at` input is
forbidden. At least one editable field is required. General absolute messages
cannot be removed or made meaningless.

No PATCH changes ownership, target type/identity, anchor type, status or delivery
fields. Mode conversion requires cancelling the old record and creating a new
one. No record is replaced, cloned or redirected by PATCH.

Explicit cancellation permits only `scheduled -> cancelled` in both modes,
including overdue scheduled reminders. It preserves ID, target, message, trigger,
anchor and offset, and keeps `sent_at = null`. Sent/cancelled records are immutable;
repeated cancellation and historical edits return 409 with no further changes.
Reopening work never resurrects cancelled reminders. Users may create new ones.
`updated_at` may change on an actual write. There is no public transition to sent.

`ReminderResource` is unchanged:

```json
{
  "data": {
    "id": 35,
    "task_id": 12,
    "subtask_id": null,
    "study_session_id": null,
    "message": "Deadline in one hour",
    "trigger_at": "2026-10-21T11:00:00Z",
    "anchor": "task_deadline",
    "offset_minutes": -60,
    "status": "scheduled",
    "sent_at": null
  }
}
```

It exposes original target IDs, stored anchor/offset and resolved UTC trigger;
owner, audit fields and internal relationship details are excluded. Historical
sent/cancelled references remain readable, including soft-deleted work.

## Read compatibility and errors

GET remains read-only, with no recalculation, cancellation, repair or inference of
delivery. List includes all statuses by default and orders `trigger_at ASC, id ASC`.
Standard Laravel `data`, `links`, `meta` pagination and filters are unchanged:

| Filter | Accepted values |
| --- | --- |
| `status` | `scheduled`, `sent`, `cancelled` |
| `trigger_from`, `trigger_to` | Both omitted or both supplied; strict UTC, inclusive ordered bounds spanning at most 366 days |
| `per_page` | Positive decimal integer string 1–100; default 20 |
| `page` | Positive decimal integer string 1–2147483647; default 1 |

Unknown filters fail validation; pagination links retain validated filters.
Every selected scheduled record receives structural owner/target/anchor checks.
Malformed general records, multiple targets, foreign references or corrupt session
attribution fail closed. History is never reassigned or silently repaired.

- 401: unauthenticated.
- 404: foreign/missing Reminder; identical `{"message":"Not found."}` responses.
- 422: invalid body/mode/offset, unavailable target/anchor or nonfuture explicit trigger.
- 409: incompatible lifecycle, `{"message":"The reminder cannot be changed in its current state."}`.
- 500: unexpected persisted Reminder corruption/write failure. Reminder routes use
  `{"message":"The reminder could not be processed."}`. Enclosing Task/Subtask
  synchronization failures use `{"message":"The reminder dependencies could not be processed."}`.

Mutation ownership precedes field validation and is independently reloaded under
locks. SQL details, foreign identifiers and internal exceptions are never included
in synchronization responses, including with application debug enabled.

## Automatic synchronization and mutation-path audit

`ReminderSynchronizationService` runs inside the existing owner/work transaction.
Shared `ReminderTargetService` extracts F1's ownership/attribution, eligibility and
exact anchor sources; `ReminderRules` owns calculation and decisions. The old
`ReminderDependencyService::compatible()` retains its strict single-target rules,
using the same pure mapping. General reminders have their separate F1 path.

| Existing mutation path | F2 behavior |
| --- | --- |
| `TaskService::update()` changes persisted `deadline_at` | Recalculate scheduled direct `task_deadline` reminders |
| `SubtaskService::update()` changes persisted `deadline_at` | Recalculate scheduled direct `subtask_deadline` reminders from their own deadline |
| Task pending -> completed | Cancel scheduled Task, descendant Subtask and related StudySession reminders in either mode |
| Subtask pending -> completed | Cancel scheduled direct Subtask and exact child-attributed StudySession reminders in either mode |
| Task/Subtask reopen | No resurrection or reminder changes |
| Task/Subtask soft deletion | Unchanged S3 cancellation of its eligible dependent records |
| Session completion/miss/cancel | Unchanged Stage D source reminder cancellation |
| Session manual replacement | Unchanged Stage D original reminder cancellation; no transfer |
| Stage E2 replacement/unplaced cancellation | Unchanged atomic source reminder cancellation; no transfer |
| Task/Subtask text/estimate edits, Task priority/Subject edits or child reorder | No anchor or eligibility transition; no synchronization |
| Stage C/E2/session transfer insertion | New session identity, no generated or cloned reminders |

The application has no operation editing an existing planned session's start in
place and no public permanent target deletion. No new session-edit endpoint or
speculative purge hook is added. Task and Subtask completion remain independent;
reconciliation does not complete/cancel sessions, update other work, or change
planning reservations, constraints or progress rules.

For deadline synchronization, compatible scheduled absolute reminders are
preflighted but left unchanged. Sent/cancelled and unrelated records are excluded
from writes. A parent deadline update does not reanchor any child reminder. Existing
Task/Subtask reciprocal deadline constraints remain authoritative and may reject a
parent/child deadline conflict independently of reminders.

A valid future resolution updates only the relative reminder's trigger. Null
anchors, elapsed resolutions (including exact equality), unrepresentable results,
or ineligible work cancel instead of blocking an otherwise legitimate deadline
edit. **Automatic cancellation keeps the last committed scheduled trigger**; it
does not store the newly resolved invalid instant. ID, targets, message, anchor,
signed offset and null sent timestamp remain unchanged. A final clock check after
the entire batch restores that prior trigger when a recalculation expires during
the operation. Cancelled history is never rewritten by later deadline changes.

Completion handles affected scheduled targets, including structurally valid
already-deleted children and session history. Sessions with live reminder references
are inspected in both attribution directions; unexpected owner/parent/child
corruption rolls back the completion. Historical anomalies without scheduled
reminder dependencies are outside this reconciliation slice.

## Transactions, locking and failure policy

Public Reminder writes keep F1's fresh outermost transaction and reject ambient
transactions. Task/Subtask mutation Services retain owner-first reloading and call
synchronization after the target write inside the same transaction. The sync Service
opens no transaction, requires an existing one and trusts only already locked fresh
owner/work context from these callers; it also rejects mismatched owner/child context.
Nested callers must acquire the owner before establishing a consistent-read snapshot.

Lock order is `User -> Task -> Subtask -> StudySession -> Reminder`, omitting unused
types. Parent completion locks children by ascending ID; relevant sessions with live
reminders are discovered and attribution-checked, then locked by ascending ID.
Scheduled reminders are selected across owners so foreign corrupt dependencies
cannot be hidden, and locked by ascending ID. All dependency compatibility and
calculations are preflighted before the first reminder change. After Reminder locks,
shared source validation performs only reads of already locked work/session records,
not earlier locking reads. Deadline synchronization needs only its already-held
parent/child locks and the direct Reminder batch.

Fresh owner locking ensures a REPEATABLE READ waiter observes the preceding writer's
committed state. Cached route models are never authoritative. A blocked offset edit
uses the latest deadline; a deadline writer uses the latest stored offset/status.
Compatible edits serialize; cancellation cannot be overwritten by a stale edit.
Failed preflight, target/reminder save veto, SQL failure, event failure or final
explicit-trigger checks roll back the complete enclosing mutation. Automatic expiry
cancels safely inside that transaction. No partially synchronized batch commits.
There are no network calls inside transactions. Only F3a local inbox persistence
runs inside its delivery transaction; target synchronization sends nothing.

Known limits are cooperating owner-first writers rather than arbitrary SQL/model
writes; advisory unlocked GET; bounded per-record list relationship queries; and
clock advancement after the last check and before commit. Direct writers bypassing
Services require separate reconciliation and must never be trusted by delivery.
Repeated completion is idempotent; F2 hooks run on the actual transition and deadline
change, not as a general background sweep of legacy data.

## Hard purge safety

FK `SET NULL` can orphan relative metadata if a target is permanently removed.
A future concrete purge workflow must acquire the owner/work/session/reminder locks,
validate all affected dependencies, cancel/reconcile every scheduled dependent
before target deletion, preserve historical metadata, and never resurrect orphaned
history. Existing SET NULL necessarily clears the numeric FK; a purge-specific
historical attribution/audit policy must be defined before exposing such a workflow.
There is no public purge now and F2 adds no hook without a concrete caller. Raw
force deletion is not a supported application workflow.

F3a enforces `BR-REM-004` for committed local inbox records. External channels and
their retry/receipt requirements remain separate future work. Existing S3/Stage D/E2
historical identity rules remain authoritative.

## F3a inbox persistence and payload

`2026_10_14_000001_create_notifications_table.php` creates Laravel's native database
notification structure: UUID primary key, notification type, indexed polymorphic
`notifiable_type`/`notifiable_id`, JSON `data`, nullable `read_at` and timestamps.
A nullable BIGINT `reminder_id` has a UNIQUE constraint and FK to Reminders with
`ON DELETE SET NULL`. An additional recipient/read/creation index supports inbox
queries. Multiple null references allow unrelated future notification types.

The existing User `Notifiable` relation and framework `DatabaseNotification` model
persist receipts directly in the same MySQL transaction as the Reminder update.
No second outbox/receipt table, custom notification model or external channel is
needed. The safe stored type is `reminder`; the immutable JSON snapshot has exactly:
`reminder_id`, `message`, `task_id`, `subtask_id`, `study_session_id`, `trigger_at`.
It contains the original exact target identity, canonical UTC trigger and meaningful
display message. Null/blank targeted messages receive deterministic text:
"You have a task reminder.", "You have a subtask reminder." or
"You have a study session reminder." General reminders still require meaningful
messages. There are no target titles, model dumps, credentials or LLM text.

Target changes never rewrite delivered content. `SET NULL` retains delivered inbox
history if an administrative operation eventually removes a Reminder; the original
numeric ID stays in JSON. This does not authorize a public purge. Polymorphic
recipients have no User FK, following Laravel conventions; a future account purge
must explicitly remove its inbox records as part of the account retention policy.
No application hard-purge workflow is introduced.

## Due selection, delivery and failure policy

`ReminderDeliveryService::dispatchDue()` captures full-precision UTC time and selects
only `scheduled` rows with persisted `trigger_at <= now`, ordered by
`trigger_at ASC, id ASC`. It loads only bounded ID/owner candidates: default 100,
maximum 1,000. Each candidate is revalidated in its own fresh outermost transaction
through `process()`; ambient transactions are rejected. Candidate discovery is
advisory and cannot authorize delivery.

Each transaction locks the current User before any consistent-read snapshot,
reloads/locks Task, Subtask and exact StudySession attribution when applicable,
then locks the Reminder. Lock order remains
`User -> Task -> Subtask -> StudySession -> Reminder`. Shared
`ReminderTargetService::forReminder()`, `ReminderDependencyService` and
`ReminderRules` enforce structural ownership, target count and anchor compatibility;
the strict single-target helper is not loosened for general reminders. General
absolute reminders use their separate meaningful-message path.

After the locks, a fresh full-precision UTC clock rechecks scheduled/due state.
Missing, foreign or already-terminal candidates are skipped. Structurally valid
targets that are deleted, completed or terminal are cancelled, preserving the last
scheduled trigger, targets, anchor, offset and message with null `sent_at`. Delivery
requires active pending work and a `planned` linked session, but does not require
its start to remain future: zero/positive offsets and overdue planned sessions may
deliver without inferring completion or missed status. Creation/edit eligibility
still requires a future planned session as in F1/F2.

For eligible relative targets, the exact current persisted anchor plus signed
offset must equal the stored trigger. Stale triggers, missing anchors, overflow,
foreign attribution, invalid structure and malformed general messages fail closed
and roll back. They are not silently repaired. F2 deadline synchronization policy
is unchanged. The dispatcher never substitutes a successor session, changes work
progress, completes sessions or changes planning reservations.

A scheduled row with any existing linked notification is an integrity failure;
the dispatcher never repairs its lifecycle. Under the owner serialization boundary,
the receipt existence query is deliberately nonlocking to avoid empty unique-index
gap locks between independent owners. The database UNIQUE constraint complements
the owner/Reminder locks. Native inbox insertion and `scheduled -> sent` with
`sent_at` occur atomically. `sent_at` and inbox `created_at` use the captured
post-lock delivery clock, stored at whole-second column precision. A save veto,
SQL error or exception after either write rolls back both; a later attempt can
deliver once. Already-sent/cancelled rows are never rewritten or backfilled.

Each failed candidate is isolated and reported as `failed`; processing continues
with the remaining selected candidates. No private IDs, messages or SQL are printed.
An unsuccessful candidate remains scheduled for a later invocation after repair;
there is no separate retry queue. Persistent corrupt candidates can occupy bounded
batch slots and need operator resolution. A command-wide selection failure also
fails the invocation. Local committed-record exactly-once protection does not
extend to future external side effects or arbitrary SQL writers bypassing Services.

## Artisan command and scheduler operation

```sh
php artisan migrate
php artisan reminders:dispatch-due --limit=100
php artisan schedule:work
```

The dispatcher emits one JSON count object, for example:

```json
{"selected":100,"sent":96,"cancelled":2,"skipped":1,"failed":1}
```

Exit codes: 0 for a completed batch without failures, 1 for failed candidates, and
2 for an invalid limit. Limits are exact positive decimal strings in 1–1,000.
`routes/console.php` registers `reminders:dispatch-due --limit=100` every minute.
No global scheduler overlap lock is required for correctness; per-owner database
serialization allows competing dispatchers and independent recipients.

Schedule registration does not start an operating-system scheduler. Locally run
`schedule:work` in a separate process. Production requires a continuously operating
Laravel scheduler, typically an OS cron entry using the deployed project path:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run
```

Configure operational logging and observe nonzero dispatcher results. More than
100 due reminders are handled by later invocations, or an explicit larger bounded
limit. F3a provides no external channel queue, provider retries or deployment setup.

## Notification inbox REST API

Both routes require `auth:sanctum`, owner-scoped native notification lookup and the
explicitly registered `DatabaseNotificationPolicy`.

| Method | Endpoint | Success |
| --- | --- | --- |
| GET | `/api/notifications` | 200, paginated Resources |
| POST | `/api/notifications/{notification}/read` | 200, owned Resource |

GET orders `created_at DESC, id DESC`, includes read and unread records by default,
and has no writes. Query filters are `unread_only=true|false`, `per_page=1..100`
(default 20), and `page=1..2147483647` (default 1); unknown or malformed filters
return 422. Pagination links retain validated filters. Example resource:

```json
{
  "data": {
    "id": "fcb245ee-bbe0-48f7-9212-8cd672c7672e",
    "type": "reminder",
    "reminder_id": 35,
    "message": "Deadline in one hour",
    "task_id": 12,
    "subtask_id": null,
    "study_session_id": null,
    "trigger_at": "2026-10-21T11:00:00Z",
    "created_at": "2026-10-21T11:00:08Z",
    "read_at": null
  }
}
```

`created_at` is the in-app delivery timestamp. Stored history is validated without
reading mutable targets. Owner/morph details, internal notification classes and
arbitrary payload data are never exposed. Unrelated future types remain compatible
with persistence and serialize as safe `type=notification`, a generic message and
null reminder/target/trigger fields until a separate public contract is defined.

Read POST accepts an empty body only; fields including explicit null `read_at`
are forbidden. An absent body, `{}` or conventional empty `[]` is accepted;
malformed/scalar/nonempty-array JSON returns 422. Query parameters cannot override
the server timestamp. A fresh User lock followed by the owned notification lock
sets current UTC `read_at` only when null. Repeated and concurrent requests return
the existing timestamp without another write. Payload and Reminder state remain
unchanged. No inbox delete endpoint exists.

Unauthenticated requests return 401; missing/foreign IDs return identical generic
404 responses. Invalid inputs return 422. Unexpected persisted corruption/write
failure returns generic 500 `{"message":"The notification could not be processed."}`,
without private metadata or SQL details, even with application debug enabled.

## Lifecycle compatibility and remaining delivery scope

F1 absolute requests, F2 relative requests, PATCH/cancel contracts and Reminder GET
behavior remain unchanged. Delivery contends at the same owner boundary as
deadline reconciliation, completion, deletion, Stage D and E2 retirement. A waiting
dispatcher sees committed edits/cancellations or rollback. If delivery commits
first, subsequent retirement preserves sent Reminder and inbox history; explicit
Reminder PATCH/cancel conflicts with 409. No successor receives a cloned reminder
or notification. Marking read has no Task/Subtask/session/progress effect.

Reminder lifecycle, committed inbox delivery and nullable inbox read state are
separate concepts. A sent but unread notification remains visible. F3a guarantees
at most one committed linked inbox record and atomic source transition; it makes
no claim that a student opened it. Email/push/SMS providers, WebSockets, external
retry/claim queues, analytics, automatic generation and AI/frontend remain deferred.

## Stage F3a verification and changed-file review

Starting/ending HEAD: `b81b8d4d717ab7714a8b04ce94e3c7c7d90eadfe`. Initial tree
clean; final tree contains 17 modified tracked files and 14 new files, all unstaged.
No commit or push. No dependency, queue, database configuration, Reminder schema,
target mutation Service, planning algorithm or existing cleanup Service changes.

| Changed/added file | Responsibility |
| --- | --- |
| `database/migrations/2026_10_14_000001_create_notifications_table.php` (new) | Native JSON inbox, UUID/morph indexes, nullable unique source FK with SET NULL. |
| `app/Services/Reminders/ReminderDeliveryRules.php` (new) | Pure full-precision due checks and immutable safe payload validation. |
| `app/Services/Reminders/ReminderDeliveryService.php` (new) | Bounded deterministic selection, source revalidation, atomic delivery and failure isolation. |
| `app/Services/Reminders/ReminderTargetService.php` | Shared scheduled structural validation; delivery permits still-planned overdue sessions while creation/edit defaults remain unchanged. |
| `app/Services/Reminders/ReminderService.php` | Delegate existing structural validation to shared target Service. |
| `app/Console/Commands/DispatchDueReminders.php` (new) | Bounded command, count-only output and failure exit codes. |
| `routes/console.php` | Register minute dispatch; preserve inspire and queue infrastructure. |
| `app/Services/Notifications/NotificationInboxService.php` (new) | Owned deterministic pagination and transactional idempotent read mutation. |
| `app/Http/Controllers/Api/NotificationController.php` (new) | Thin list/read orchestration. |
| `app/Http/Requests/NotificationIndexRequest.php` (new) | Exact bounded query validation. |
| `app/Http/Requests/ReadNotificationRequest.php` (new) | Ownership before empty-body validation. |
| `app/Http/Resources/NotificationResource.php` (new) | Safe immutable history and canonical UTC serialization. |
| `app/Policies/DatabaseNotificationPolicy.php` (new) | Exact recipient type/owner and generic not-found denial. |
| `app/Providers/AppServiceProvider.php` | Explicit native notification model Policy mapping. |
| `routes/api.php` | Two authenticated inbox routes. |
| `bootstrap/app.php` | Exact notification inputs and generic private-data-safe errors. |
| `tests/Unit/ReminderDeliveryRulesTest.php` (new) | Pure due/calendar precision, payload typing/whitelisting and immutability. |
| `tests/Feature/ReminderDeliveryTest.php` (new) | Real MySQL delivery/schema/command/source integrity, UTC boundaries and exact rollback snapshots. |
| `tests/Feature/NotificationInboxApiTest.php` (new) | Authentication, owner isolation, pagination/read state, history and safe failures. |
| `tests/Feature/ReminderDeliveryConcurrencyTest.php` (new) | Real independent-process dispatch/lifecycle/read contention and rollback visibility. |
| `tests/Support/reminder_mutation_worker.php` | Guarded native delivery/read/E2 actions in independent processes. |
| `tests/Feature/PlanningMigrationTest.php` | Strengthen additive rollback/reapply assertion with notifications table. |
| `docs/reminders.md` | F3a contract, deployment operation, limitations and actual verification; unchanged historical F1/F2 records. |
| `docs/architecture.md` | Native notification persistence, shared Services and scheduling architecture. |
| `docs/domain-model.md` | Scheduling/delivery/read lifecycle separation and delivered snapshots. |
| `docs/business-rules.md` | Implement local inbox idempotence under unchanged BR-REM identifiers. |
| `docs/database-schema.md` | Additive inbox schema, FK/ownership and retention policy. |
| `docs/database-review-notes.md` | Current F3a enforcement and unchanged Stage 02 history. |
| `docs/tasks.md` | Preserve existing cleanup, progress and sent history. |
| `docs/study-session-lifecycle.md` | Exact source identity and delivery/retirement contention. |
| `docs/adaptive-replanning.md` | Existing E2 cleanup remains authoritative; no successor receipt cloning. |

All database suites run serially against guarded real MySQL
`student_planner_testing`; pure Unit tests have no database. Fixtures are committed
and Services exercise fresh outermost transactions. No SQLite, skipped concurrency
substitution or weakened/deleted existing tests. Migration rollback/reapply includes
the new inbox and preserves scaffold tables/users. Historical verification below
remains unchanged.

The **29 independent-process F3a concurrency cases** assert REPEATABLE READ and
actual User-row contention via `performance_schema.data_lock_waits`, `data_locks`
and `threads`, using separate guarded PHP processes/connections. They verify two
dispatchers, both execution orders for cancel/absolute edit/relative edit/deadline/
Task and Subtask completion/deletion/session cancel/reschedule/E2 retirement,
post-insert and post-sent rollback/retry, failed deadline-sync rollback visibility,
clock capture after owner wait, independent-owner delivery while another receipt
is uncommitted, and competing read requests preserving the first timestamp.
Query-log coverage separately asserts actual work-before-Reminder lock order.
Sequential HTTP tests are not used as the concurrency proof.

Feature tests additionally cover each absolute/relative target and general mode,
zero/positive session offsets, overdue planned sessions, not-due and terminal
history, eligibility cancellation, corrupt/foreign/stale/overflow anchors,
command limit/order/failure/schedule behavior, nullable uniqueness and JSON schema,
physical source SET NULL, native save vetoes/SQL errors/post-write exceptions,
retry after exact all-table rollback, owner-only pagination/read API, immutable
payload, generic future notification types and Kyiv DST/ISO-year UTC boundaries.

| Final verification command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit --filter='ReminderDelivery|NotificationInbox|PlanningMigrationTest'` | 0 | 115 | 1,188 |
| Affected regression filter below | 0 | 3,071 | 24,640 |
| `vendor/bin/phpunit --filter='RemainingEffortServiceTest'` | 0 | 68 | 120 |
| `vendor/bin/phpunit` | 0 | 3,926 | 32,398 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Focused, affected regression, supplemental effort and full durations: 27.386,
172.405, 0.049 and 185.153 seconds. All tests passed; none skipped. The full suite
includes unchanged F1/F2, Task/Subtask/security/deletion, Stage D and E2 confirmation/
concurrency tests and all 114 new F3a tests. The new pure Unit run passed 28 tests /
81 assertions, exit 0. The initial focused run passed 83 / 768, and the expanded
concurrency run passed 112 / 1,164, both exit 0; three additional real MySQL UTC
calendar cases produced the final 115 / 1,188 focused result.

Exact affected regression command:

```sh
vendor/bin/phpunit --filter='TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceServiceTest|PlanningWorkSelectionServiceTest|StudyPlanningServiceTest|StudySessionAllocationServiceTest|WorkloadCapacityTest|StudyPlan|StudySession|TaskLifecycleTest|SubtaskLifecycleTest|TaskSecurityTest|SubtaskSecurityTest|TaskDeletionTest|SubtaskDeletionTest|LessonLifecycleTest|LessonSecurityTest|ScheduleImport|Reminder|NotificationInbox|PlanningMigrationTest|AuthProfileTest|PlanningPreferenceApiTest|StudyAvailabilityApiTest|StudyReplanning'
```

The affected command contained a misspelled `RemainingEffortServiceServiceTest`
selector; the separately recorded `RemainingEffortServiceTest` run closes that
omission. The full run also includes those tests. No failure was hidden or test
weakened. Final architecture/changed-file review confirms 31 scoped files, no
new whitespace errors or missing local documentation links, no lock inversion,
duplicated ownership/anchor arithmetic, network delivery or unrelated infrastructure.
Approved BR-REM identifiers and historical F1/F2 verification records are retained.

**F3a acceptance: complete.** All nineteen criteria pass for local in-app delivery,
atomic source/inbox persistence, owner authorization, actual MySQL contention,
regressions, full-suite and formatting checks. Operational deployment still requires
applying the migration and running Laravel's scheduler. Persistent corrupt
candidates need operator resolution and may consume bounded batch capacity.
Exactly-once protection concerns committed local records; external channels,
arbitrary direct SQL writers and administrative purge remain outside this stage.

## Stage F2 verification and changed-file review (historical)

Starting and ending HEAD: `1e7bb28249e5769387a928c4fb35e9ddd8bf56e5`.
Initial tree: clean. Final tree: 21 modified tracked files and six new files,
27 total, all unstaged. Nothing is committed or pushed. Existing models, enums,
factories, schema/migrations, FK actions, planning algorithms, Controller/Resource/
Policy/routes and authentication configuration remain unchanged. Bootstrap adds
only generic F2 synchronization failure rendering on Task/Subtask paths.

| Changed file | Responsibility |
| --- | --- |
| `app/Services/Reminders/ReminderRules.php` | Exclusive modes, strict signed offsets, shared anchor mapping and UTC resolution/reconciliation decisions. |
| `app/Services/Reminders/ReminderTargetService.php` (new) | Extract F1's persisted ownership/attribution/eligibility checks and exact anchor source, shared by public and automatic writers. |
| `app/Services/Reminders/ReminderService.php` | Relative creation/offset edits, mode validation under fresh locks and final trigger/eligibility gates. |
| `app/Services/Reminders/ReminderSynchronizationService.php` (new) | Existing-transaction deadline/completion batches, complete preflight, historical cancellation and final automatic expiry checks. |
| `app/Services/Reminders/ReminderDependencyService.php` | Reuse the pure anchor mapping; existing strict single-target/cancellation behavior retained. |
| `app/Http/Requests/StoreReminderRequest.php` | Relative creation fields and inherited PATCH field validation. |
| `app/Services/Tasks/TaskService.php` | Deadline/completion hooks and target-save failure checks. |
| `app/Services/Tasks/SubtaskService.php` | Own-deadline/completion hooks and target-save failure checks. |
| `app/Exceptions/ReminderIntegrityException.php` (new) | Safe propagation of corruption/write failure through enclosing transactions. |
| `bootstrap/app.php` | Generic Task/Subtask synchronization failure JSON. |
| `tests/Unit/RelativeReminderRulesTest.php` (new) | Pure mapping/type/offset/calendar/overflow/mode/history/immutability tests. |
| `tests/Feature/RelativeReminderApiTest.php` (new) | Real MySQL relative API, automatic synchronization, all-table rollback, isolation, integration and lock-order checks. |
| `tests/Feature/RelativeReminderConcurrencyTest.php` (new) | Real separate-process F2 owner contention and commit/rollback visibility. |
| `tests/Support/reminder_mutation_worker.php` | Guarded independent-process target deadline/completion actions. |
| `tests/Unit/ReminderRulesTest.php` | Scheduled relative state is editable; historical transition assertions retained. |
| `tests/Feature/ReminderApiTest.php` | Replace F1's temporary relative-edit/completion boundary assertions with the requested F2 behavior. |
| `tests/Feature/TaskLifecycleTest.php` | Completion cancellation/history assertions plus unchanged work/session/unrelated records. |
| `tests/Feature/SubtaskLifecycleTest.php` | Same child completion/no-resurrection assertions while preserving parent/sibling/session checks. |
| `docs/reminders.md` | Current F1/F2 API, sources, synchronization, history, locking, purge/delivery boundaries and actual verification. |
| `docs/business-rules.md` | F2 enforcement under unchanged BR-REM identifiers. |
| `docs/domain-model.md` | Current relative Reminder lifecycle and ownership. |
| `docs/architecture.md` | Shared rules/target Service and in-transaction reconciliation. |
| `docs/tasks.md` | Deadline/completion synchronization and unchanged Task/Subtask/session semantics. |
| `docs/study-session-lifecycle.md` | Retained Stage D exact-session cleanup and successor policy. |
| `docs/adaptive-replanning.md` | Retained E2 atomic source cancellation and no cloning. |
| `docs/database-schema.md` | Current application invariants, signed offset interpretation, unchanged physical persistence. |
| `docs/database-review-notes.md` | Current enforcement and deferred administrative purge. |

New F2 coverage comprises 44 pure Unit tests, 98 MySQL API/integration tests and
**23 independent-process MySQL concurrency tests**. Existing F1, Task/Subtask and
Stage A–E2 tests remain present. Updated old assertions correspond only to the
explicitly changed relative-edit and target-completion contracts; tests were not
deleted or weakened. Earlier stages' historical testing records are preserved.

All database tests run serially on real MySQL `student_planner_testing` with the
existing configured/live database guards. API/concurrency tests use DatabaseTruncation
and committed fixtures, so Reminder mutations exercise actual outermost transactions.
Pure tests do not bootstrap Laravel or a database. No SQLite, skipped concurrency
proof or dependency/configuration workaround is used.

F2 concurrency tests assert REPEATABLE READ, use independent guarded PHP processes
and verify actual User-row waits through `performance_schema.data_lock_waits`,
`data_locks` and `threads`. Those instrumentation permissions are already required
by the existing F1/Stage D/E2 tests. Coverage includes:

- Deadline update versus offset edit in both directions for Task and Subtask.
- Two competing independent writers retaining the latest committed deadline/offset pair.
- Deadline mutation versus explicit cancellation in both directions, retaining
  the last scheduled trigger rather than recalculating cancelled history.
- Relative creation versus actual Task/Subtask completion and deletion in both
  directions: late creation rejects; creation first is cancelled by the retiring writer.
- Failure after the second synchronization write: both target and the entire
  reminder batch roll back, and a waiter edits against the old committed anchor.
- Failure after an offset write: waiting deadline synchronization uses the rolled-back offset.
- Clock advanced after a real owner wait: an elapsed automatic resolution cancels
  without preventing the legitimate deadline update.
- A foreign owner after contention cannot bypass the target's owned reload.

These are contention proofs, not sequential requests. A separate query-log test
asserts actual locking SELECT order `users, tasks, subtasks, study_sessions, reminders`
and ascending ID ordering for the dependent batches. The enclosing Services
revalidate current persisted targets rather than trusting cached route models.

| Final verification command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit --filter='Reminder|TaskLifecycleTest|SubtaskLifecycleTest'` | 0 | 467 | 4,297 |
| Stage A–E2 regression filter below | 0 | 3,024 | 23,572 |
| `vendor/bin/phpunit` | 0 | 3,812 | 31,255 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Focused, regression and full durations: 48.428, 146.636 and 167.532 seconds.
No tests were skipped. The full suite includes all unchanged F1 and Stage A–E2
coverage, existing separate-process concurrency tests and every new F2 test.

Exact affected regression command:

```sh
vendor/bin/phpunit --filter='TimeIntervalServiceTest|AvailabilityOccurrenceServiceTest|FreeTimeServiceTest|FreeSlotsApiTest|SessionDurationConstraintServiceTest|BreakConstraintServiceTest|WorkloadConstraintServiceTest|PlanningConstraintServiceTest|PlanningCandidateEvaluationServiceTest|RemainingEffortServiceTest|PlanningWorkSelectionServiceTest|StudyPlanningServiceTest|StudySessionAllocationServiceTest|WorkloadCapacityTest|StudyPlan|StudySession|TaskLifecycleTest|SubtaskLifecycleTest|TaskSecurityTest|SubtaskSecurityTest|TaskDeletionTest|SubtaskDeletionTest|LessonLifecycleTest|LessonSecurityTest|ScheduleImport|Reminder|AuthProfileTest|PlanningPreferenceApiTest|StudyAvailabilityApiTest|StudyReplanning'
```

Development verification: the initial F1/lifecycle run reported 227 tests / 1,623
assertions, exit 1, with one remaining pre-F2 assertion expecting a Task-targeted
reminder to stay scheduled after completion. It was updated to assert cancellation,
unchanged history/other records and no resurrection, matching the requested F2
contract. The new pure run passed 44 / 120, exit 0. Expanded API/lifecycle coverage
reported 367 / 3,059, exit 1, with two fixture failures: a child deadline exceeded
its parent's bound, and a stale test model considered its original deadline clean
instead of actually restoring the database value. The fixtures now provide a valid
parent bound and refresh before the direct model write; application constraints
were preserved. The complete expanded focused run passed 465 / 4,280, exit 0.
Final owner-context/actual-lock-order coverage produced the final 467 / 4,297 above.
No failing test was removed or relaxed to pass.

Final architecture and changed/untracked-file audit confirm no new whitespace
errors, missing local documentation files, lock-order inversion, duplicated target/
UTC calculation rules, delivery/purge infrastructure or unrelated changes. Existing
Markdown hard breaks and all historical verification records are retained.

**F2 acceptance: complete.** All eighteen criteria pass for deterministic relative
reminders, atomic target synchronization, historical preservation, real MySQL
contention and Stage A–E2/full-suite/formatting regression. Actual notification
delivery remains F3; administrative hard purge remains a separately specified
future workflow.

## Stage F1 verification and changed-file review (historical)

Starting and ending HEAD: `8f94e15a3f8ab808636694066eaf43a93448a1d1`.
Initial working tree: clean. All changes belong to F1; nothing is staged, committed
or pushed. No migrations, existing models/enums/factories, database configuration,
dependencies, planning algorithms or cleanup Services are modified.

Final tree: 10 modified tracked files and 15 new files (25 total), all unstaged.
The changed-file review confirms thin HTTP orchestration, shared deterministic UTC
parsing, no duplicated dependency rules and no owner/work/Reminder lock inversion.
Approved BR-REM identifiers and earlier stages' historical verification records
are retained.

| Changed file | Responsibility |
| --- | --- |
| `app/Exceptions/ReminderConflictException.php` (new) | Generic incompatible-transition error. |
| `app/Services/Reminders/ReminderRules.php` (new) | Pure strict input, message, timestamp and status rules. |
| `app/Services/Reminders/ReminderService.php` (new) | Owned queries, fresh locking, target integrity and atomic writes. |
| `app/Http/Controllers/Api/ReminderController.php` (new) | Thin five-endpoint orchestration. |
| `app/Http/Requests/ReminderActionRequest.php` (new) | Ownership-first empty cancellation body and JSON validation. |
| `app/Http/Requests/StoreReminderRequest.php` (new) | Exact creation body validation. |
| `app/Http/Requests/UpdateReminderRequest.php` (new) | Exact editable-field body validation. |
| `app/Http/Requests/ReminderIndexRequest.php` (new) | Strict bounded query filters and pagination. |
| `app/Http/Resources/ReminderResource.php` (new) | Public UTC history-preserving representation. |
| `app/Policies/ReminderPolicy.php` (new) | Owner authorization with not-found denials. |
| `routes/api.php` | Five authenticated routes. |
| `bootstrap/app.php` | Exact input exemptions and private-data-safe errors. |
| `tests/Unit/ReminderRulesTest.php` (new) | Pure type/time/message/status and unchanged dependency compatibility tests. |
| `tests/Feature/ReminderApiTest.php` (new) | MySQL HTTP isolation, integrity, atomicity and S3/D/E2 integration. |
| `tests/Feature/ReminderConcurrencyTest.php` (new) | Real independent-process owner contention and current-state visibility. |
| `tests/Support/reminder_mutation_worker.php` (new) | Guarded independent MySQL test process; not a delivery worker. |
| `docs/reminders.md` (new) | API contract, boundaries, complete verification and changed-file record. |
| `docs/architecture.md` | Current F1 architecture and deferred F2/F3 scope. |
| `docs/domain-model.md` | Current absolute reminder lifecycle and ownership. |
| `docs/business-rules.md` | Enforcement under preserved BR-REM rule IDs. |
| `docs/database-schema.md` | Existing-schema F1 application invariants; no migration. |
| `docs/database-review-notes.md` | Current enforcement and unchanged persistence decisions. |
| `docs/tasks.md` | S3 compatibility and completion/reconciliation boundary. |
| `docs/study-session-lifecycle.md` | Stage D cleanup and compatible locking. |
| `docs/adaptive-replanning.md` | Stage E2 atomic reminder cleanup compatibility. |

All database checks run serially on real MySQL `student_planner_testing` under
existing configured/live database guards. Unit tests require no Laravel/database
bootstrap. Feature tests use DatabaseTruncation, committed fixtures and actual
outermost transactions. No SQLite, skipped tests or weakened baseline tests are
used. The full suite includes all affected Task/Subtask, StudySession, Stage D and
E2 regressions.

| Verification command | Exit | Tests | Assertions |
| --- | ---: | ---: | ---: |
| `vendor/bin/phpunit --filter='Reminder'` | 0 | 257 | 2,122 |
| Affected regression filter below | 0 | 1,330 | 9,426 |
| `vendor/bin/phpunit` | 0 | 3,647 | 29,486 |
| `vendor/bin/pint --dirty` | 0 | — | — |
| `vendor/bin/pint --test` | 0 | — | — |
| `git diff --check` | 0 | — | — |

Affected regression command:

```sh
vendor/bin/phpunit --filter='Reminder|TaskLifecycleTest|SubtaskLifecycleTest|TaskSecurityTest|SubtaskSecurityTest|TaskDeletionTest|SubtaskDeletionTest|StudySession|StudyReplanning|StudyPlan'
```

The regression command passed before the final UTF-8 internal-encoding regression
was added; the final focused and full runs include that additional test and its
explicit UTF-8 length fix. The focused filter includes 194 new F1 tests and 63
existing tests selected by their Reminder names. There are 68 new pure Unit tests,
111 new MySQL API tests and **15 new separate-process MySQL concurrency tests**.
No tests were skipped. Final focused, regression and full run durations were
22.830, 89.658 and 137.569 seconds respectively.

Concurrency tests assert REPEATABLE READ and verify actual User-row waits through
`performance_schema.data_lock_waits`, `data_locks` and `threads`, using independently
bootstrapped guarded processes/connections. The test account requires access to
these instrumentation tables, as already required by Stage D/E2 tests. Coverage:

- Competing update/cancel, two partial updates and two cancellations; committed
  fields survive serialization, and terminal transitions conflict.
- Creation behind actual Task/Subtask deletion and StudySession cancellation/
  rescheduling sees committed terminal state and rejects the target.
- The reverse ordering makes those actual cleanup writers wait for creation,
  then cancel the newly committed dependent without changing its identity/metadata.
- A waiting updater rejects committed cancellation but sees rollback of an
  already-written cancellation and successfully edits the scheduled record.
- The full-precision clock advanced specifically after the real owner-lock wait
  rejects an elapsed trigger.
- A contending foreign owner cannot mutate another owner's Reminder.

These are actual contention proofs, not sequential request simulations. Full-suite
Stage D/E2 concurrency coverage also remains intact.

API coverage includes all target kinds and general reminders, exact types/null
controlled fields/malformed JSON, calendar/DATETIME/future boundaries, UTF-8 length,
owner index/show, deterministic pagination/filtering, stale/foreign/corrupt target
sources, scheduled edits, immutable identity/history, relative edit conflicts,
overdue cancellation and reads, cancellation conflicts, and unchanged all-table
snapshots after failed writes. Save vetoes, exceptions after insertion/update and
post-write trigger/session clock expiry roll back. API-created reminders pass S3
and all four Stage D actions plus E2 confirmed-batch cleanup; all other application
tables remain unchanged on creation. The old single-target helper retains pure
owner/count/anchor/offset regression coverage.

Development results: the baseline persistence run passed 23 tests / 258 assertions.
An initial sandboxed new-test run could not reach MySQL (exit 2, 168 tests / 78
assertions, 102 setup errors; 66 pure tests passed); authorized execution then
exposed four fixture assertions and 22 snapshot errors (exit 2, 168 / 856). The
fixtures now preserve floating-point JSON tokens, compare freshly loaded attribute
ordering and support tables without an `id` column; no application rule or test
assertion was weakened. The corrected run passed 168 / 1,008 (exit 0). An expanded
focused run passed 246 / 1,993 (exit 0). A later added E2 integration fixture correctly
returned a partial-review acceptance error (exit 1, 255 / 2,102, one failure): its
08:00:00.5-to-09:00 fragment could not fit a full hour. Restricting fixture availability
to 09:00 onward produced the intended full replacement and passed 256 / 2,120
(exit 0). The final encoding-independent Unicode check produced the final focused
257 / 2,122 above. Other sandbox-only attempts were rerun with authorized MySQL
access; no database substitution was made.

Final review and changed/untracked-file audit pass: no new whitespace errors,
missing local documentation files, schema changes, cleanup/planning changes or
unrelated edits. Markdown's existing intentional two-space hard breaks remain.

**F1 acceptance: complete.** All twelve acceptance criteria pass for absolute
reminder storage/management, including real concurrency, regression/full-suite and
formatting gates. Relative/target reconciliation is F2; actual delivery is F3.
