# Stage F1 — Absolute Reminder Management

Stage F1 implements authenticated storage and explicit management of absolute
reminders. A scheduled record is an instruction stored in MySQL; **no notification
is delivered**. There is no worker, queue, scheduler, email, push, retry system,
relative-trigger writer, bulk generation, AI adapter or migration in this stage.

The existing Reminder model, enums, table, foreign keys and CHECK constraints are
unchanged. See [the schema](database-schema.md#314-reminders),
[stable reminder rules](business-rules.md#10-reminder-rules) and
[session cleanup](study-session-lifecycle.md#chain-and-reminder-history).

## API contract

All endpoints require `auth:sanctum` and use `ReminderResource`.

| Method | Endpoint | Success |
| --- | --- | --- |
| GET | `/api/reminders` | 200, paginated resource collection |
| POST | `/api/reminders` | 201, created resource |
| GET | `/api/reminders/{reminder}` | 200, owned resource |
| PATCH | `/api/reminders/{reminder}` | 200, updated resource |
| POST | `/api/reminders/{reminder}/cancel` | 200, cancelled resource |

Creation accepts exactly `trigger_at`, optional nullable `message`, and optional
nullable `task_id`, `subtask_id`, `study_session_id`. `trigger_at` is required.
At most one target is non-null. Target IDs must be genuine positive JSON integers;
numeric strings, floating-point tokens (including `12.0`), booleans, zero and
negative values are invalid. Omitted/null targets classify a general reminder.

```json
{
  "message": "Review the coursework before class",
  "trigger_at": "2026-10-20T07:45:00Z",
  "task_id": 12
}
```

General reminders require a meaningful message: it must contain a character
other than Unicode separators, controls/format characters or whitespace. Targeted
reminders allow a null/omitted message. Non-null messages must be UTF-8 strings of
at most 500 characters, matching `VARCHAR(500)`. Message whitespace is preserved.
PATCH validates the resulting general message, so it cannot be cleared.

Creation always supplies trusted ownership, `status = scheduled`, `sent_at = null`,
`anchor = null`, `offset_minutes = null`. Every unrecognized body field is rejected,
including `id`, `user_id`, audit fields, `anchor`, `offset_minutes`, `status` and
`sent_at`, even when explicitly null. Query parameters cannot supply or override
writable body fields. Malformed JSON, scalar roots and nonempty array roots fail
validation. Empty action payloads may be an absent body, `{}` or the conventional
`[]`; cancellation otherwise accepts no body fields.

Example response, under Laravel's standard `data` key:

```json
{
  "data": {
    "id": 35,
    "task_id": 12,
    "subtask_id": null,
    "study_session_id": null,
    "message": "Review the coursework before class",
    "trigger_at": "2026-10-20T07:45:00Z",
    "anchor": null,
    "offset_minutes": null,
    "status": "scheduled",
    "sent_at": null
  }
}
```

The Resource includes original target IDs and historical anchor/offset metadata;
it excludes owner, audit and internal relationship fields. Existing relative
records can be read and explicitly cancelled; they cannot be edited in F1.

## Time and list filters

Wire timestamps must be exactly `YYYY-MM-DDTHH:MM:SSZ`: real calendar values,
whole seconds, years 1000–9999 for MySQL DATETIME. Offsets, fractional seconds,
lowercase `z`, rollover dates and leading/trailing whitespace are rejected.
Request middleware does not normalize reminder input. Persisted DATETIME values
are interpreted explicitly as UTC, independent of the user's timezone.
`ReminderRules` reuses the established `StudySessionLifecycleRules` parser.

Creation and every edit require the resulting trigger to be strictly after the
full-precision UTC application clock. A timestamp in the current second is already
past when that clock includes microseconds. This applies to message-only edits.
Clock checks occur after owner contention and again after persistence before
transaction completion. Session-target creation also rechecks the future start
at that final gate. No arbitrary future buffer is added.

GET collection defaults to all statuses, ordered by `trigger_at ASC, id ASC`,
with standard Laravel `data`, `links`, `meta` pagination. Filters are ANDed:

| Query key | Accepted value |
| --- | --- |
| `status` | `scheduled`, `sent`, `cancelled` |
| `trigger_from`, `trigger_to` | Both supplied or both omitted; strict UTC timestamps, inclusive bounds; ordered span at most 366 days |
| `per_page` | Positive decimal integer string, 1–100; default 20 |
| `page` | Positive decimal integer string, 1–2147483647; default 1 |

Unknown query filters fail validation. Pagination links retain validated filters.
Historical and overdue scheduled records are included unless filtered. Reads never
infer delivery, cancel, repair, recalculate or update a record.

## Target integrity and authorization

Creation resolves persisted targets under the authenticated owner's lock:

- Task: owned, not soft deleted, pending.
- Subtask: not soft deleted, pending, attached to an active pending owned Task.
- StudySession: owned, planned, starts strictly in the future, with an active
  pending owned Task and, when present, an active pending child of that exact Task.
- General: no target and a meaningful message.

Foreign, missing and ineligible targets return the same generic Laravel 422
`target` validation error without identifiers. Reminder lookups are owner-scoped
and protected by an auto-discovered Policy. Foreign/missing reminder IDs return
identical 404 `{"message":"Not found."}` responses. Mutation ownership is resolved
before request field validation and independently reloaded inside the transaction.

Every selected scheduled reminder fails closed on multiple targets, incompatible
anchor/offset, unsafe target ownership or corrupt session Task/Subtask attribution.
A malformed scheduled general reminder fails closed too. Such persisted source
corruption returns generic 500 `{"message":"The reminder could not be processed."}`
even with debug enabled; failed reads/writes expose no target details and make no
repairs. Historical sent/cancelled rows remain readable with their original
references, including soft-deleted work, without eligibility reconciliation.

Existing scheduled targets receive structural ownership/relationship validation,
not automatic F2 eligibility reconciliation. Consequently an existing structurally
valid reminder may still be scheduled after Task/Subtask completion or a deadline
change; it remains readable, editable while its trigger is future, and cancellable.
Structurally owned soft-deleted history is also accepted for these checks. Existing
S3/Stage D/E2 cancellation rules are authoritative where they apply. F1 does not
introduce cancellation on every work completion, deadline change or elapsed start.

## Lifecycle and mutation behavior

PATCH accepts only `message` and `trigger_at`, and requires at least one of them.
Only scheduled absolute reminders are editable. Target replacement and delivery/
ownership/anchor fields are rejected, even when null. Editing uses the same row;
there is no clone, reassignment or replacement.

Explicit cancellation permits `scheduled -> cancelled`, including overdue
scheduled records and compatible preexisting relative records. It preserves ID,
targets, message, original trigger and anchor/offset; `sent_at` stays null.
`updated_at` may change. No record is deleted.

Sent and cancelled records are immutable. Editing a relative scheduled reminder,
editing/cancelling sent or cancelled history, and repeated cancellation return
409 `{"message":"The reminder cannot be changed in its current state."}` with no
additional changes. F1 has no public transition to sent and no automatic expiry.

## Transactions and cleanup integration

`ReminderService` owns a fresh outermost transaction for each write and rejects
ambient transactions. Its first SELECT is a locking current read of the persisted
User, before any REPEATABLE READ snapshot is established. After waiting, it loads
current source state and locks in the existing order:

`User -> Task -> optional Subtask -> optional StudySession -> Reminder`.

General reminders need only User then Reminder. Discovery queries happen after
the owner lock and do not lock foreign work. Target creation inserts the Reminder
after work/session locks. Existing mutation reloads the Reminder by owned ID after
work locks; it never trusts a route-bound model. Cooperating writers for one owner
serialize, so partial edits preserve other committed fields, cancellation cannot
be overwritten by a stale edit, and target retirement is observed before creation.
All validation, save-veto, SQL, event and final-clock failures roll back completely.
There are no network calls inside transactions.

`ReminderDependencyService` is unchanged. Its single-target helper remains strict
for deletion/session preflight; general reminders use F1's separate path. S3 still
cancels eligible Task/Subtask/session dependents, Stage D still cancels reminders
for completion, miss, cancellation and manual transfer, and Stage E2 still cancels
obsolete source reminders atomically with the replanning batch. No reminder is
cloned or redirected to a successor. Sent/cancelled/unrelated/general history is
preserved. Reminder creation does not modify progress, sessions, planning
reservations, availability, preferences or constraints.

## F2 and F3 boundaries

F2 must specify relative-anchor creation, signed offset validation, deterministic
resolution and recalculation after deadline/session changes, target eligibility
reconciliation after Task/Subtask completion/deletion, and hard-purge behavior.
It must distinguish historical identity from successor identity and reuse existing
cleanup Services. These rules must be implemented before a delivery worker exists.

F3 must separately specify delivery claiming, sent-state transitions, idempotence,
notification channels and retries under concurrency. `BR-REM-004` is an intended
delivery requirement, not an implemented F1 capability. No separate delivery
history table is introduced here.

Known limits: serialization protects cooperating owner-first application writers,
not arbitrary direct SQL writers. Read validation is advisory and takes no locks.
List integrity checks perform bounded per-record relationship queries (page size
at most 100). The wall clock can advance after the final check and before commit;
F1 provides no delivery guarantee or minimum lead-time promise.

## Verification and changed-file review

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
