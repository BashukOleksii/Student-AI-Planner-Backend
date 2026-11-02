# Confirmed AI Lesson actions — Stage 11 Backend

The operator approved all four Lesson proposals, reuse of the existing explicit
confirmation protocol and an additive forward-only migration on 2026-11-02.
This document describes the Backend implementation. Frontend integration is pending;
its exact wire contract is [here](stage11/frontend-rest-contract.md).

## Tools and domain rules

`LessonActionRegistry` appends four non-strict provider schemas to the previous
23 definitions: 9 reads + 18 proposals = 27 tools. Server validation is strict;
provider non-strict mode preserves omission versus explicit clearing-null.

| Tool | Required arguments | Confirmation writer |
| --- | --- | --- |
| `propose_lesson_create` | `subject_id`, `type`, `starts_at`, `ends_at` | `LessonService::create` |
| `propose_lesson_update` | `lesson_id` and at least one editable field | `LessonService::update` |
| `propose_lesson_cancel` | `lesson_id` only | `LessonService::cancel` |
| `propose_lesson_replacement` | `lesson_id`, `subject_id`, `type`, `starts_at`, `ends_at` | `LessonService::createReplacement` |

Editable fields are `subject_id`, `teacher_id`, `academic_period_id`, `type`,
`room`, `starts_at`, `ends_at`. Only Teacher, Academic Period and room are nullable.
IDs must be positive JSON integers, never strings, floats or booleans. No owner,
status, import identity, replacement link, token or consent argument is accepted.
Types: `lecture`, `practical`, `laboratory`, `seminar`, `consultation`, `exam`, `other`.
Room has a 100-character limit; strings must be valid UTF-8.

Instants must be exact `YYYY-MM-DDTHH:MM:SSZ`, years 1000–9999. Invalid calendar
dates, offsets, fractions and whitespace fail. Both instants represent UTC and
persist as UTC DATETIME; `starts_at < ends_at`. Instructions require clarification
for missing local times, unknown IDs and DST ambiguity. No application adapter
selects the first matching record or invents an identifier. The existing schedule
read can ground Lesson/Subject/Teacher IDs. There is no AI academic catalog read;
unknown catalog IDs require explicit clarification, not implicit lookup/defaults.

`LessonInputRules` shares existing Form Request rules with the AI validator.
`LessonService::prepare`, `prepareCancel`, `prepareReplacement` share the existing
candidate, ownership, active-overlap, import and replacement rules with writers.
Preparation uses ordinary reads and never calls a writer, fires write events, or
executes a speculative save followed by rollback. Owners and targets derive from
authentication and owner-scoped relationships; concrete Lessons additionally pass
`LessonPolicy::update`. Foreign/missing references fail without disclosing names.
Malformed legacy association graphs fail closed for AI actions.

All existing direct REST rules remain: PATCH preserves omitted fields and may edit
inactive Lessons; create/replacement optional omissions become null. Replacement
requires an active ordinary original and excludes that original from overlap.
Only active non-deleted Lessons block overlap; adjacency is allowed. There is no
conflict override, StudySession conflict check or automatic replanning side effect.
Cancellation retains history and never restores a replacement's original.

Imported originals may be edited, cancelled or replaced through the same public
Service rules. Import metadata cannot be supplied or rewritten; an imported
fingerprint requires an Academic Period. A new replacement is manual. Reusing a
deleted replacement preserves its existing ID, clears omitted optional fields and
refuses imported, foreign, chained or incompatible historical rows. AI deletion,
bulk edits, import and replacement deletion/revert are outside the allowlist.

## State, transactions and failure handling

The global orchestrator counter includes all four names even for invalid calls,
across batches and rounds: at most one mutation attempt per turn in any domain.
Provider wording is replaced with server-owned confirmation-required wording.
Only successful turn finalization persists the proposal in `ai_pending_actions`;
its public preview is not executable input. No new endpoint, table, action status,
private token or confirmation framework is introduced.

A fingerprint binds complete raw Lesson, original and replacement rows (including
soft-deleted replacement history) and all reviewed/proposed Subject, Teacher and
Academic Period rows. Same-second changes and label/ownership changes invalidate
reviews. Exact record IDs and nullable references remain immutable action arguments.

Confirmation keeps H3's outermost transaction and lock order:
Conversation → Action → User → Lesson graph/owned associations. Cooperating domain
writers serialize on the User lock. No consistent read precedes that owner lock,
and no provider call occurs inside the transaction. Confirmation validates stored
arguments again, locks/reloads reviewed records, checks their fingerprint and
regenerates the complete preview. JSON object key ordering is immaterial. Shared
domain preflight and the actual writer recheck overlap and lifecycle eligibility.
New overlaps return `action_validation_failed`; changed/missing reviewed records
return `action_stale`.

The writer, executed state, immutable receipt and server-authored history commit
together. A persisted-result postcondition detects cancelled model writes or an
incomplete replacement transition. Any failure, including receipt/history failure
or MySQL expiry crossed during a slow write, rolls back the whole confirmation.
Expected Lesson confirmation failures keep the action pending with a null receipt;
no failed-attempt event is appended (existing Task/management behavior). The user
can reject it or request a fresh proposal. Rejection never invokes a Lesson writer.

MySQL supplies the 15-minute action deadline. Executed confirmation replays the
original receipt without provider or Service calls, even after later edits or
expiry. Reject-after-execution returns 409; repeat rejection is harmless. Pending
expired decisions return 410 and persist the expired terminal state.

## Migration and compatibility

`2026_11_02_000001_add_ai_lesson_actions.php` changes only the existing
`ai_actions_valid_type` CHECK from 14 to 18 allowed values, in one ALTER. No applied
migration is edited, no history is rewritten, and no business schema changes.
`down()` deliberately refuses: corrections require a new forward migration.
Production/shared databases were not migrated for this implementation.

Deploy the migration before enabling the new Backend code. MySQL ALTER requires a
metadata lock. Old schemas reject new proposals; old applications cannot execute
Lesson actions, so application downgrade after creating Lesson history requires an
explicit compatibility plan. Existing Task/Subtask/Reminder/planning contracts and
receipts are preserved. The current Frontend rejects confirmation of unknown types
and needs the documented integration before these actions are usable in its UI.

Disposable migration tests rebuild/wipe their guarded schema instead of invoking
unsupported production downgrade. `PLANNER_TEST_DATABASE` permits only the existing
default test name or an explicit `student_planner_isolated_[a-z0-9_]{1,32}` name;
actual configured and connected names must match it. Worker processes use the same
selector; lock-wait probes use their current database. See [test evidence](stage11/implementation-report.md).
