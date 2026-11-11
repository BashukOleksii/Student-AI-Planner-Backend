# Automatic learning recovery: Backend and frontend contract

> Subsequent testing stage: the complete offline suite passed locally (6,782 tests, 74,032 assertions), including the recovery/calendar regressions. See [executed evidence and runtime limits](testing/backend-test-rebuild-report.md). Deferred-verification statements below describe the original implementation stage.

Implementation follows reference `4eb708e6a97964007460d4f84585637f8e260b9b` on `main`. This document describes code, not verified runtime behavior. No database migration, scheduler, PHPUnit, browser, delivery or live LLM verification was executed for this implementation stage.

## Authority and lifecycle

The product decision is **automatic suggestions, explicit student confirmation**. [LearningRecoveryAnalysisService](../app/Services/Planning/LearningRecoveryAnalysisService.php) composes existing recovery, adaptive replanning, effort selection and workload advice. It does not allocate intervals itself. [RecoverySuggestionService](../app/Services/Planning/RecoverySuggestionService.php) owns durable review metadata and delegates confirmed changes to existing planning writers. Conversation-independent suggestions never use `AiPendingAction`.

Kinds:

| Kind | Meaning | Can confirm? |
|---|---|---|
| `decision_required` | A planned session has started without an explicit outcome; includes elapsed sessions. | No. Use existing session lifecycle endpoints, then obtain a fresh review. |
| `replan` | Existing future sessions require deterministic adaptive replanning. | Yes, with required consents. |
| `create_plan` | Known remaining work can receive additional planned allocation. | Yes, with partial-allocation consent when required. |
| `warning` | Missing estimates, insufficient capacity, manual-review sessions or persisted overload require attention without a safe automatic write. | No. Resolve inputs or use existing scoped workflows. |

Unknown outcomes take precedence over writes. This conservatively pauses automatic allocation for the owner while any captured planned session has started. It does not mark the session missed, fabricate actual minutes, complete a Task/Subtask, or cancel an existing session. A session that is in progress may not yet allow the `miss` transition. `review.recovery` reports allowed lifecycle actions for selected work; existing lifecycle endpoints remain authoritative.

`pending` becomes `applied`, `rejected`, `expired` or `invalidated`. Applied rows contain an immutable receipt. Terminal rows cannot execute again; duplicate confirmation of an applied row returns that same receipt with `replayed: true`. GETs annotate current expiry/staleness without writing metadata. The scheduler or explicit decisions persist lifecycle resolution. Never assume that opening a review, creating a diagnostic, refreshing a review, or saying “yes” in chat applies it.

One pending review is allowed per owner. Stable source and qualitative need fingerprints suppress repeated reviews for an unchanged need, including a rejected/expired one. Worsening allocation categories can produce another review. Exact shifting proposed start times do not themselves trigger repeated notifications. Explicit refresh creates a new UUID with `refreshed_from_id`; repeating refresh on the same parent returns the same child, even if that child later expires. Refresh that child to obtain another review. Refreshing never applies work. There is no uncertain-write retry.

## Detection and bounds

[DetectLearningRecovery](../app/Console/Commands/DetectLearningRecovery.php) registers:

```text
php artisan learning:detect-recovery --limit=10 --days=7
# Optional --user=<positive owner id>; limit 1–100, days 1–31.
```

[Scheduler](../routes/console.php): every five minutes, `withoutOverlapping(10)`, independent of the existing every-minute reminder command. No queue job, reminder dispatch, deployment or cron configuration is added. Scheduling requires the application's existing Laravel scheduler and functioning shared cache locks; these operational prerequisites remain unverified.

Selection considers unfinished Tasks, planned sessions or pending reviews, with least recently scanned owners first. Each owner has a separate owner-locked transaction; failures advance scan metadata so one invalid owner does not starve the batch. Output contains counts only: `selected`, `created`, `unchanged`, `no_need`, `limited`, `failed`. Nonzero failures return failure; safety-limit exclusions are reported separately. Analysis detects current state, not a historical event log: new/missed/reopened work, changed deadlines/preferences/availability and effective academic occupancy feed the next bounded scan. No handlers silently move sessions after an academic edit.

The default horizon is seven inclusive local dates beginning today. UTC intervals, local dates, DST, deadlines, breaks, limits, min/max sessions and calendar suppression continue to be calculated by existing domain services. Future sessions crossing the automatic horizon boundary fail the bounded analysis rather than silently expanding it. Existing task-scoped recovery remains available.

[RecoverySourceStateService](../app/Services/Planning/RecoverySourceStateService.php) validates owned references and captures complete bounded inputs. It rejects, rather than truncates: over 200 Tasks (including trashed), 500 Subtasks (including trashed), 1,000 study sessions (all statuses/history), 2,000 legacy Lessons (including trashed), 500 subjects, 500 teachers, 20 semester schedules, 100 academic periods, 500 weekly rules, 500 date exceptions, 2,000 exception snapshot lessons, 500 scheduled reminders, or 100 availability windows. Used academic periods longer than 400 days and session intervals longer than 31 days also fail automatic analysis. Public review JSON is capped at 262,144 bytes. Bounds intentionally limit automatic coverage for large/historical accounts; no incomplete input set is passed to a planner. Operators/frontend must surface `analysis_limit_exceeded` and the manual scoped path.

Source fingerprints cover period/timezone, planning preferences, authoritative calendar/context, Tasks/Subtasks, sessions and scheduled reminders, plus session-start/end and unfinished-deadline thresholds. Existing preview writers independently revalidate the exact proposed write batch. Cosmetic source changes can conservatively invalidate reviews. The server supplies a 900-second earliest-allocation lead to the existing allocator so a review is not stale immediately when today's availability is already open. This optional internal parameter is encrypted into the existing token and reused at confirmation; ordinary REST/AI planning calls retain their previous default. Tokens expire after at most 15 minutes, or earlier when a source/replacement starts. Advisory reviews expire after one day. No client can set the allocation cutoff.

## Authenticated REST contract

All endpoints use the existing Sanctum/session authentication and owner-scoped lookup. Foreign UUIDs return 404 before mutation-body validation. No client owner ID or private token is accepted. JSON mutation flags must be genuine booleans; unknown body/query fields are rejected. Existing APIs remain unchanged.

| Method | Route | Input | Output |
|---|---|---|---|
| GET | `/api/planning/recovery-suggestions` | Optional `status`, decimal `page`, `per_page` 1–10 | Laravel paginated `data`, `links`, `meta`, plus `automation`. Default status `pending`. |
| GET | `/api/planning/recovery-suggestions/{uuid}` | None | `{data: suggestion}` |
| POST | `/api/planning/recovery-suggestions/{uuid}/confirm` | Optional `accept_partial`, `accept_unplaced_cancellation`, both default false | `{data: suggestion, replayed: boolean}`; receipt inside suggestion. |
| POST | `/api/planning/recovery-suggestions/{uuid}/reject` | Empty object | `{data: suggestion}`; repeated rejection is safe, applied reviews conflict. |
| POST | `/api/planning/recovery-suggestions/{uuid}/refresh` | Empty object | `{data: new_or_existing_child}`; fresh proposal only. |
| GET | `/api/planning/recovery/diagnostics` | Required real `start_date`, `end_date`, `YYYY-MM-DD`, inclusive 1–31 dates | `{data: current_review, automation}`; no durable suggestion created. |

Status filters: `all`, `pending`, `applied`, `rejected`, `expired`, `invalidated`. Filtering uses persisted status; each returned item reports its effective current status. Thus a default pending page may contain an effectively expired/invalidated item until the next scan/decision. Use returned `actionable` and `status`, not the query filter, to enable controls.

[RecoverySuggestionResource](../app/Http/Resources/RecoverySuggestionResource.php) returns:

```json
{
  "id": "uuid",
  "kind": "create_plan",
  "status": "pending",
  "period": {"start_date": "2026-10-19", "end_date": "2026-10-25", "timezone": "Europe/Kyiv"},
  "actionable": true,
  "confirmation_required": true,
  "requires_lifecycle_decision": false,
  "required_confirmation_fields": [],
  "reason_codes": ["eligible_remaining_work"],
  "review": {},
  "receipt": null,
  "refreshed_from_id": null,
  "expires_at": "UTC ISO timestamp",
  "resolved_at": null,
  "created_at": "UTC ISO timestamp"
}
```

`review` is immutable presentation evidence: `version`, `as_of_utc`, `period`, `kind`, `review_lead_seconds`, `earliest_proposed_start_at`, `actionable`, `requires_lifecycle_decision`, `required_confirmation_fields`, `unknown_session_decisions`, `recovery`, `new_work_plan`, `adaptive_replanning`, `work_effort`, `unknown_duration_work`, `completed_credit_is_not_reported_actual_time`, `workload_advice`, `reason_codes`, `limitations`. Nested planning/replanning shapes use existing API Resources. Completed effort credit may use the existing scheduled-duration fallback; it is not a claim of measured actual study time. Missing estimates remain unknown, never zero. Workload advice includes existing per-work reasons, daily/weekly persisted violations and deadline/capacity alerts. The proposal is deterministic, not globally optimal; no ranked alternative engine is introduced.

The immutable receipt has `suggestion_id`, `kind`, `applied_at`, `result` (existing planning or adaptive-confirmation Resource). Protected tokens, source hashes and database internals are never returned. Confirm applies adaptive changes first when needed; subsequent detection can propose additional uncovered work. Valid unaffected sessions and completed history remain intact. A partially unplaced adaptive result requires **both** `accept_partial: true` and separate `accept_unplaced_cancellation: true`. Without these consents no source session is cancelled.

Errors: normal 401/422; 404 for inaccessible resources; 409 `suggestion_stale`, `suggestion_resolved`, `no_recovery_needed`; 410 `suggestion_expired`; 422 `decision_required`, `analysis_limit_exceeded`; unexpected errors return fixed 500 `recovery_failed`. Ordinary consent validation uses Laravel field errors. After a conflict/expiry, obtain a new review; never blindly retry a potentially applied write. A duplicate confirmation is safe specifically because it addresses the same durable UUID and receipt.

`automation`: `last_scanned_at` (UTC/null), `last_outcome`, `notification_channel: recovery_review_feed`. This is a separate authenticated review feed, not a new reminder or `notifications` record. No push, email, delivery guarantee or notification count is implied. Detection does not deliver reminders. Existing scheduled reminders for retired sessions are cancelled by the existing confirmed replanning writer; historical notifications are retained. New replacement reminders are not invented. Existing progress/statistics read the confirmed session state without new formulas.

## AI and Vue integration

Three registered read tools: `list_recovery_suggestions`, `get_recovery_suggestion`, `get_recovery_diagnostics`. List returns bounded summaries; detail returns the full owned review/receipt. The orchestrator distinguishes pending proposals from applied plans and directs confirmation/refresh to the authenticated UI. Existing tools can prepare manual scoped recovery using the existing AI confirmation workflow; automatic reviews remain independent. No AI tool confirms/rejects/refreshes automatic reviews. Existing per-tool and total-context byte limits still apply: a large detail/diagnostic result can exceed the configured AI budget even when it fits the REST review cap. Use the summary tool and authenticated review UI in that case; data is never silently truncated. No paid provider calls were made.

Vue must:

1. Fetch the paginated review feed and render effective status, freshness and scan outcome; optionally fetch read-only diagnostics.
2. Display reasons, unknown effort/outcomes, affected/unchanged sessions, proposed replacements, unallocated time, warnings and expiry directly from server fields. Do not recalculate availability, effort, conflicts or statistics.
3. Route unknown decisions to the existing session complete/miss UI with explicit actual duration only when supplied by the student; collect missing estimates/constraints through existing APIs.
4. Require a deliberate confirmation action for the exact UUID; show separate partial and cancellation consents when required, then send JSON booleans. Never confirm from chat wording, page opening or polling.
5. After confirmation use the receipt, refresh session/calendar/reminder/workload/statistics queries, and preserve replay semantics. Refresh/reject controls act on review metadata only; refresh may return a different UUID.
6. Handle advisory/limited/stale/expired states, absence of a new review for an unchanged rejected need, and explicit refresh. Use existing manual task-scoped workflows for accounts outside automatic limits.

No frontend source or production infrastructure is changed. Existing academic lesson editing identities, import suppression, frozen semester exceptions, self-study/calendar separation and legacy routes remain unchanged.

## Deferred verification

[AutomaticLearningRecoveryTest](../tests/Feature/AutomaticLearningRecoveryTest.php) adds disposable-MySQL regression cases; it has **not been executed**. Run focused tests sequentially only against a positively identified disposable MySQL database. Never overlap database test processes.

Required next stage: forward migration and CHECK/index behavior; the new feature class; existing planning/recovery/replanning confirmation, remaining-effort/allocation, semester planning, reminder synchronization/delivery/concurrency, lifecycle, AI tools/orchestration and statistics tests; smoke then full suite. Add/execute two-connection concurrent confirm, concurrent manual planning/academic edit, expiry while waiting/writing, injected failure after source retirement/receipt serialization, changed availability/import/semester exception, safe SQL errors, DST and all caps/fairness regressions. Verify existing four-parameter tokens and new internal cutoff tokens. Separately observe scheduler fairness/cache lock/latency, real browser review authentication, actual inbox/mail delivery, and live model behavior with appropriate authorization. No result is claimed in advance.
