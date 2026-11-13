# Unified academic calendar and AI semester management

Current extension: the separate `propose_semester_occurrence_create` appends one
lesson to an effective date while retaining saved snapshot rows and working
Saturday metadata. See [arguments, behavior and Frontend handoff](ai-semester-occurrence-create.md).

This contract is additive. The academic calendar contains university classes only. Study sessions remain in the planning workspace. No migration was applied and no test suite, CI, deployment or production verification was executed during implementation. PHP syntax inspection is separate from behavioral verification. Regression cases were authored for a later disposable MySQL verification stage.

## Unified REST reads

All paths below are relative to `/api`, require existing Sanctum authentication and use the existing SPA cookie/CSRF setup.

| Method/path | Meaning |
|---|---|
| `GET /academic-calendar/today` | Current local date in the fresh persisted user timezone |
| `GET /academic-calendar/date/{YYYY-MM-DD}` | Effective academic lessons overlapping that local date |
| `GET /academic-calendar/week/{YYYY-MM-DD}` | Monday–Sunday containing that local date |

Dates are exact supported calendar labels. Invalid labels produce Laravel 422 validation responses. Week bounds must also be representable. Corrupt saved calendar configuration fails closed with a generic 409 response; unexpected failures return a generic 500 response without SQL/debug details. Authentication and authorization errors retain normal HTTP behavior.

Responses use `AcademicCalendarLessonResource`:

```json
{
  "data": [{
    "id": "semester:12:2026-10-20:rule:34",
    "source_identity": "semester:12:2026-10-20:rule:34",
    "source_type": "semester_occurrence",
    "occurrence_id": "semester:12:2026-10-20:rule:34",
    "source": "base",
    "change_kind": "base",
    "occurs_on": "2026-10-20",
    "lesson_id": null,
    "academic_period_id": 5,
    "semester_schedule_id": 12,
    "weekly_lesson_rule_id": 34,
    "schedule_date_exception_id": null,
    "schedule_date_exception_lesson_id": null,
    "replaces_lesson_id": null,
    "subject": {"id": 9, "name": "Mathematics", "code": null, "color": null},
    "teacher": {"id": 7, "name": "Teacher"},
    "type": null,
    "pair_number": 1,
    "pair_label": "1-2",
    "room": "101",
    "starts_at": "2026-10-20T08:00:00+03:00",
    "ends_at": "2026-10-20T09:20:00+03:00"
  }],
  "meta": {
    "timezone": "Europe/Kyiv",
    "start_date": "2026-10-20", "end_date": "2026-10-20",
    "limit": 100, "returned": 1, "total": 1, "truncated": false
  }
}
```

Subject metadata follows its source presentation: semester subjects include their existing code/color fields; legacy subjects include id/name. Subject/teacher may be null when a legacy presentation association is not owned. Do not assume a non-null classification or teacher for historical data.

`source_type` is `semester_occurrence` or `legacy_lesson`. Semester `source` is the calculator's `base`, `replacement` or `working_saturday`. Empty cancellation snapshots return no lesson. For legacy rows `source` is `legacy_lesson`, `id` and `lesson_id` are persisted integers; semester fields and pair metadata are null. Semester `id` and `occurrence_id` are the same virtual string; legacy `occurrence_id` is null; snapshot identities end in `snapshot:{snapshotLessonId}`. The separately returned snapshot lesson ID identifies a saved row inside the specified date exception, not a legacy Lesson.

Both academic and semester occurrence reads expose `change_kind` independently of the day `source`:

- `base`: ordinary rule occurrence, or a linked snapshot lesson equal to its applicable rule after normalization;
- `modified`: a linked snapshot differs in subject, teachers, pair number/label, type, room, local endpoints or rule applicability;
- `added`: a new date-only lesson explicitly recorded by the server;
- `null`: uncertain historical provenance, a deleted source rule, or an independent dated Lesson.

Never infer a per-lesson change from `source = replacement`. Working Saturdays retain `source = working_saturday` and frozen copy semantics; rule comparison uses the copied weekday/source parity. Cancelled snapshot lessons remain absent. Missing source links are never guessed from pair numbers.

Migration `2026_11_12_000004_add_snapshot_lesson_added_provenance` adds nullable `is_added` to snapshot lessons. It is internal, server-assigned provenance, not writable API input. New additions store true; copies of rules store false; edits/copies retain the marker and rule deletion does not turn a copy into an addition. Historical rows remain null without backfill. Apply the migration before deploying the writer changes; it has not been applied by this task.

Independent dated Lessons retain all `LessonType` values, including consultation, and ordinary CRUD/cancellation. Active creates/edits validate half-open UTC overlaps against active dated Lessons and the effective semester calendar, under the existing owner lock/transaction; adjacency is allowed, conflicts return Laravel 422 `errors.schedule`. Semester reads resolve parity, snapshots and persisted timezone through the existing calendar services. Inactive lessons do not occupy time.

New dated Lesson replacements are disabled: the compatibility POST replacement endpoint returns Laravel 422 `errors.replacement`; both service preflight and writer reject the operation. The retired AI proposal is absent from tool discovery and cannot prepare/execute a new replacement, including pending proposals; existing executed receipts/history remain readable. This supersedes ADR-005's availability of new replacement proposals, without changing its confirmation transaction or historical links.

Legacy dated import POST `/schedule-imports`, POST `/{id}/revalidate` and POST `/{id}/commit` return 410 after authentication/ownership checks. GET `/schedule-imports/{id}` retains safe owned history reads. `/semester-schedule-imports` is unchanged. No institution/period uniqueness restriction or automatic history deletion is introduced.

Use **source_identity as the rendering key**. Legacy identities are namespaced (`legacy_lesson:{id}`), and an imported lesson partly outside authoritative semester coverage may have a segment suffix. Segment rows can share the same persisted legacy ID. Never parse a semester string into a legacy ID. For editing, use explicit schedule/rule/date/snapshot references or the complete occurrence identity as described below.

Ordering is actual start instant, end instant, then stable string identity. Intervals are half-open. Reads preserve full source endpoints (including cross-midnight legacy lessons), except the authoritative calendar's existing suppression segments. The query selects overlapping local-date membership, so a returned legacy start can precede the requested date. Timestamps carry local UTC offsets; do not append `Z` or reinterpret them as timezone-free clock values.

Results are capped at 100 **for the entire response**, after authoritative selection and ordering. `total` counts all selected rows; `truncated` explicitly signals omission. There is no pagination parameter on these endpoints. Split a truncated week into dates; exceptionally dense dates can still truncate and must not be presented as complete. Existing source-specific APIs remain available for detailed source management. Large reads do not silently materialize Lessons.

## Shared source and compatibility policy

`EffectiveScheduleReadService::range` extends the existing AI date reader. Both use `CalendarBusyIntervalService`; no new recurrence, deduplication, interval or planning engine was added. Semester boundaries, parity, complete-day snapshots, cancellations and working Saturdays use `EffectiveScheduleService` and its calculator.

Independent active legacy Lessons remain visible. Imported legacy history (including its owned replacement provenance) is suppressed only inside the corresponding authoritative semester's period; manual independent Lessons remain independent even when times overlap. Replaced/cancelled/soft-deleted legacy rows are not active classes. Overlapping semester schedules retain separate identities and their occupancy is unioned by free-time interval operations. This change does not impose a new global uniqueness/conflict rule across independent academic sources; existing within-semester conflict validation is authoritative for semester mutations.

Existing `/schedule/today`, `/schedule/date/{date}`, `/schedule/week/{date}` remain legacy-only with unchanged response contracts. Existing `/semester-schedules/*` effective reads, management APIs, XLSX imports, legacy Lesson actions and history retain their contracts. Existing AI `get_schedule_for_date` retains its date output schema and shared 100-row bound; the new REST reader opts into additional editing metadata.

The existing call paths already use authoritative academic occupancy: `FreeTimeService::calendarSources` → `CalendarBusyIntervalService`; `PlanningContextService` → free time and buffered class anchors; `StudyPlanningService`, adaptive replanning, manual session rescheduling and workload advice → shared context/candidate validation. Study sessions are independent blockers/reservations and never academic calendar rows. Existing numeric session exclusions cannot exclude namespaced class sources. Persisted study-session workload metrics continue to measure study effort, not university class hours.

Academic edits do not reschedule existing study sessions or silently repair conflicts. Subsequent diagnostics/planning/confirmation see the updated calendar; explicit adaptive replanning remains a separate confirmed action.

## AI discovery tools

Three read definitions are appended to existing discovery. They accept no owner or timezone arguments and never change academic records.

| Tool | Arguments | Result |
|---|---|---|
| `list_semester_schedules` | optional `page`, `per_page` | Owned IDs, academic periods, group and parity settings |
| `get_semester_schedule_context` | `semester_schedule_id`; optional `date`, `page`, `per_page` | Schedule metadata, paginated weekly definitions and suppressed slots; when a date is supplied, its saved exception metadata and paginated snapshot lessons, or null if no exception |
| `search_academic_catalog` | `kind`: `subject` or `teacher`; optional literal name-substring `query`, `page`, `per_page` | Owned id/name matches; subjects include their owned compatibility teacher links |

Pages start at 1, default size 20, maximum 50, maximum page 1,000,000. Metadata includes current/last page, per_page, total, returned and truncated. Weekly rules, suppressed slots and date snapshot lessons have separate totals but use the supplied page/size. Fetch remaining pages before selecting an ambiguous target; an empty page is not evidence that the entire list is empty. Context is definitions, not a replacement for an effective date read. Use `get_schedule_for_date` to ground occurrence identities and local timestamps. Catalogue search does not create subjects/teachers and does not authorize guessing among matches. Historical invalid teacher graphs fail closed; repair them through existing management rather than selecting arbitrary identities.

## AI semester proposals

Nine definitions are appended; total discovery becomes 46 (14 reads, 32 proposals). Existing tool definitions are unchanged. All new definitions preserve optional-field omission (`strict: false` in OpenAI schema); explicit null is permitted only for pair_label, room and lesson_type. Both providers use the same registry schema and recursive scalar/list/object validation.

Every proposal requires `semester_schedule_id`. No model-supplied schedule token, confirmation boolean, owner, PHP class or method is accepted.

| Proposal | Additional arguments | Scope/effect |
|---|---|---|
| `propose_semester_rule_create` | weekday, week_pattern, pair_number, starts_at_local, ends_at_local, subject_id, teacher_id; optional pair_label, room, lesson_type | New recurring weekly rule |
| `propose_semester_rule_update` | weekly_lesson_rule_id plus at least one of the above rule fields | Entire recurring rule; frozen snapshots stay independent |
| `propose_semester_rule_delete` | weekly_lesson_rule_id | Deletes one rule, suppresses its intentional/imported slot and detaches snapshot provenance without deleting frozen lessons |
| `propose_semester_date_replace` | date, mode; lessons and/or changes as below | Explicit whole-day arrangement/cancellation |
| `propose_semester_date_delete` | date | Removes saved exception, restoring base recurrence; does not cancel the restored lessons |
| `propose_semester_working_saturday` | occurs_on, copied_weekday; optionally both source_week_parity and selected_weekly_rule_ids | Existing effective-weekday copy or selected-parity subset, saved as a frozen Saturday |
| `propose_semester_occurrence_update` | date, occurrence_id, plus at least one lesson field | Only one occurrence on that date, preserving all other lessons and recurring rules |
| `propose_semester_occurrence_cancel` | date, occurrence_id | Removes exactly one effective lesson from that date |
| `propose_semester_settings_update` | at least one of group_name, parity_anchor_on, first_week_parity | Semester settings, explicitly distinct from rule/date edits |

Weekly weekday is 1–5; week_pattern is all/numerator/denominator; pair_number is 1–255. Local times are exact `HH:MM:SS`, with same-day increasing endpoints. Date strings are exact `YYYY-MM-DD`; parity anchors must be Mondays. All lesson types from `LessonType` are supported; null remains unspecified. Subject and single scalar teacher IDs must be owned and consistent with existing subject assignment. Historical teacherless rows may be read, but nonempty snapshot rewrites and edited/copied rules must satisfy current single-teacher requirements. Tools use scalar teacher_id rather than compatibility teacher_ids aliases.

Whole-day `mode: replace` requires `lessons`, a complete list (maximum 255), with each row containing pair_number, starts_at_local, ends_at_local, subject_id, teacher_id and optional nullable metadata. Optional `id` preserves an existing row belonging to that same snapshot. `lessons: []` cancels the entire date. `changes` is forbidden with replace.

Whole-day `mode: copy_base` begins from recurring base rules even if a non-Saturday snapshot already exists. Optional `changes` contains unique weekly_lesson_rule_id plus partial lesson fields and/or a genuine boolean cancelled; optional `lessons` adds complete extra rows. It deliberately replaces the day; use a targeted occurrence action for a single edit. Existing working Saturday snapshots require targeted occurrence changes or a complete replacement list, not copy_base.

Targeted occurrence operations require the exact current virtual identity matching the owned schedule/date. The management service derives a copy_base change for an unsnapshotted base day, or preserves the complete existing snapshot and edits/removes the identified row. It never converts the operation into a legacy Lesson. Copying a date produces a full frozen snapshot; later recurring changes no longer affect other copied lessons on that date. The preview shows that complete effective day.

Working Saturday selected-subset mode requires both explicit numerator/denominator source parity and unique nonempty owned rule IDs matching copied_weekday and that parity. Without both optional fields, the existing same-week effective source-day behavior applies, including replacement/cancellation. An existing target exception is a conflict; it is not overwritten by Saturday creation.

## Proposal and confirmation durability

1. The orchestrator resolves owned identifiers using successful reads and asks for clarification about ambiguous names, dates, scope or missing references.
2. `SemesterActionService` validates arguments, authorizes the owned schedule and reads the existing complete graph fingerprint.
3. Management operations execute their existing projected validation path with explicit server-only preview mode. It returns before any save/delete/sync/revision write. No trial SQL mutation/rollback is used to simulate a preview.
4. A successful chat turn stores the proposal using the existing `AiPendingAction` mechanism and MySQL-clock 15-minute expiry. The graph token is stored privately as target_hash, never in provider arguments/public previews. Preview contains type, semester ID, timezone, scope/effect flags and deterministic before/after definitions or the whole effective day.
5. Only `POST /ai/conversations/{conversation}/actions/{action}/confirm` with the authenticated owner executes. Use the existing reject endpoint to decline. Neither opening the preview nor a conversational yes executes anything.
6. The existing confirmation handler locks conversation/action/owner, checks turn provenance, state and expiry, then dispatches to the semester adapter. It rechecks policy and the complete locked graph token, including timezone/catalogs/provenance/revision, and regenerates the preview.
7. The adapter calls the same `SemesterScheduleManagementService` writer, rereads persisted state, checks semantic equality with the projection and returns the structured receipt. New database-generated occurrence/snapshot/rule IDs replace temporary preview identities. The receipt is stored with action status and conversation history in the same transaction.
8. Repeated confirmation returns the immutable stored receipt without executing the writer again. Stale/conflicting/invalid attempts roll back; rejected/expired actions cannot mutate. Crossing expiry during dependency waits or writes rolls the entire confirmation back through the existing final expiry check. SQL and exception details stay private.

A receipt includes action_id, type, semester_schedule_id, operation, timezone, after and effects. Its after value is a rule definition (null for deletion), semester settings, or a date/day lesson array. Before/after associations include owned id/name. `may_suppress_imported_slot` warns about existing rule update/delete import protections; manual-management and suppression semantics remain in the authoritative writer. Replays retain original receipt data even after subsequent calendar edits.

Errors retain the existing safe envelopes. Proposal failures report invalid_arguments/not_found/action_stale/execution_failed with no pending action. Confirmation uses action_stale (409), action_validation_failed (422), action_expired (410), and safe persistence_error (500), with existing rejected/executed state handling. A stale action remains unusable until a fresh proposal is requested; do not overwrite its graph token or silently retry against new state.

## Frontend integration requirements

- Switch the combined academic view to the three new academic-calendar paths. Keep planning sessions in their separate UI/API. Existing source-specific pages can retain their APIs.
- Treat source_identity as opaque and id as a string-or-number union. Route legacy edits by lesson_id; recurring edits by semester_schedule_id/weekly_lesson_rule_id; snapshot edits by semester/date/snapshot row; targeted AI edits by the complete occurrence_id plus schedule/date. Never coerce virtual IDs to numbers.
- Render local timestamp offsets, nullable type/teacher and truncation notices honestly. Split truncated week reads into days without claiming exceptionally dense dates are complete. Do not deduplicate independent classes merely because times or names match.
- Support all nine new action types in the existing action card. Clearly show recurring/date/settings scope, before/after, whole-day cancellation/restoration and frozen-day effects. For a rule-create preview the ID is null; date previews may contain temporary virtual negative row suffixes. They are preview-only, not future editing targets. Refresh calendar/context after success and use receipt/current-read identities.
- Continue existing message Idempotency-Key handling. Confirm/reject through the existing authenticated CSRF-protected action endpoints with an empty body; no new confirmation token or client-only consent field is needed. Render server state and immutable receipts, and refresh the plan workspace diagnostics when appropriate after an academic edit.
- On 409 stale/conflict reload the owned context and request a fresh proposal. On 410 show expiration. Never turn retries or chat yes into approval. Unknown action types should not be automatically executed by the UI.
- Existing manual semester REST writes still use management schedule_token and their existing explicit delete-confirmation fields; their contract is unchanged. Semester creation, suppression release, subject/teacher creation and imports remain in existing manual REST flows; no AI import, suppression-release or semester-create/delete tool was added.

## Migration and deferred verification

`2026_11_12_000001_add_ai_semester_schedule_actions.php` only extends the existing ai_actions_valid_type CHECK with the nine new names, preserving all 23 previous proposal types. It is forward-only to preserve pending actions and receipts. No old migration was edited, no table/column was added, and no migration was run in this pass. The new action types require that migration before use in a later approved environment.

Added unexecuted cases: `AcademicCalendarApiTest`, `AiSemesterActionsTest`, `SemesterActionSchemaTest`. Existing orchestrator construction/discovery expectations were extended to include the new dependencies and 46 tools; assertions for existing behavior remain. Later verification must cover mixed-source suppression, DST and period boundaries, snapshot and base occurrence edits, valid/invalid teachers, SQL privacy, stale/ABA tokens, concurrent confirmation, rollback on receipt/history failure, expiry during a write, imported-rule suppression and provider structured calls. Existing `SemesterPlanningCalendarTest` remains relevant.

Behavior remains unverified until that stage. No paid provider call, live browser session or production operation was performed. Large complete-day previews can exceed existing AI result/context byte limits; they fail explicitly without a stored proposal rather than truncating a mutation review. Existing conflicting/teacherless historical graphs require explicit valid references or manual repair; there is no silent data cleanup. Existing MySQL consistent-snapshot/isolation assumptions, live-model selection quality and concurrent MySQL behavior are unverified assumptions, not claimed outcomes.
