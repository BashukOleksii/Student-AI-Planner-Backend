# Stage 14A — Backend result

Base: `1a6e5b4ecb3a73a0ed8cff60b346d9b1b4aeb95d` on `main`, initially clean.

## Implemented capability

`POST /api/planning/recovery/preview` automatically proposes a deterministic
future study plan for eligible unfinished work and explains missed / past planned
session history. The exact request, response, types, errors, deadline behavior and
confirmation protocol are in [recovery-api-contract.md](recovery-api-contract.md).

This stage provides **automatic recovery proposals with explicit user
confirmation**. It does not perform unattended background relocation. Preview is
read-only; terminal missed history is never retired, modified or used as a
rescheduling predecessor. Past planned sessions retain their status and expose
current lifecycle hints instead of being silently marked missed.

## Architecture and compatibility

`StudyRecoveryPreviewService` composes `StudyPlanPersistenceService::preview()`
inside a nonlocking read transaction. That existing path reuses
`StudyPlanningService`, `PlanningWorkSelectionService` and `RemainingEffortService`
for authoritative units, actual-first completed credit, remaining effort, future
reservations, deadlines, priority and allocation constraints. There is no second
remaining-effort or placement algorithm. Recovery only adds owner-scoped batch
history diagnostics and existing `StudySessionLifecycleRules` time/status hints.

The nested public plan uses `StudyPlanResource` unchanged. Nonempty proposals use
the existing encrypted token; empty recovery proposals omit token/expiration.
Saving goes through the existing `POST /api/planning/study-plans/confirm`, with
unchanged partial acceptance, owner-first fresh transaction, material comparison,
independent batch checks, expiration checks and atomic rollback. Replays conflict
after the new reservations become visible. The adaptive replanning flow and its
future-source retirement rules remain unchanged.

Past-plan lifecycle decisions are advisory diagnostics, not an extra confirmation
gate. Explicit confirmation saves new sessions without changing that history.
Resolving the old session can change effort/warnings and require a fresh preview.
Recovery history is selected-work history across all dates, while the 31-date
limit applies to the proposed allocation horizon. No history-volume cap is claimed.

## Files changed

| File | Responsibility |
| --- | --- |
| `app/Services/Planning/StudyRecoveryPreviewService.php` | Read-only composition, scoped history and lifecycle diagnostics. |
| `app/Http/Controllers/Api/StudyRecoveryController.php` | Thin preview orchestration. |
| `app/Http/Requests/PreviewStudyRecoveryRequest.php` | Inherit exact study-plan validation and ownership scope input shape. |
| `app/Http/Resources/StudyRecoveryResource.php` | Recovery envelope plus existing plan representation. |
| `routes/api.php` | One additional Sanctum-authenticated preview route. |
| `bootstrap/app.php` | Include recovery in existing exact-input exemptions and private planning-error responses. |
| `tests/Feature/StudyRecoveryApiTest.php` | Focused integration, authorization, read-only and confirmation coverage. |
| `docs/stage14/recovery-api-contract.md` | Exact REST contract, diagnostics, flow and limitations. |
| `docs/stage14/backend-result.md` | Implementation and verification record. |
| `docs/architecture.md`, `docs/business-rules.md` | Document recovery under existing architecture and rule identifiers. |

No migration, model change, dependency, cache, statistics persistence, AI Tool,
provider change or new confirmation implementation is introduced. Existing
StudyPlan, StudyReplanning, Gemini/OpenAI and all 27 AI Tool contracts remain
unchanged. No Frontend files were inspected or modified.

## Verification

- Final recovery suite: **9 tests passed, 227 assertions**.
- Affected planning regression set: **662 tests passed, 4,672 assertions** before
  the final test-only additions for ordinary-preview parity and wrong-owner token
  rejection. It includes recovery, StudyPlan API, StudyReplanning preview and
  confirmation, work selection, StudyPlanning, lifecycle rules and pure study-plan
  confirmation tests. The final recovery run above includes those added checks.
- `vendor/bin/pint --dirty`: passed.
- `git diff --check`: passed.
- Contract JSON examples parse and the representative counts/durations reconcile.

Focused tests use a newly created disposable MySQL 8.4 container and existing
database-name safety guards. All-table snapshots and SELECT-only query inspection
verify that repeated previews do not mutate business data or acquire row locks.
Actual standard confirmations verify history preservation, completed actual
credit, future reservation accounting, partial acceptance, replay, wrong-owner
tokens, deadline/conflict rejection and expiration without partial writes.

The single complete PHPUnit run and repository-wide Pint check execute on this
commit through the existing [Backend verification workflow on main](https://github.com/BashukOleksii/Student-AI-Planner-Backend/actions/workflows/backend-verification.yml?query=branch%3Amain).
It provisions its own empty isolated MySQL 8.4 service, verifies migration and lock
instrumentation guards, runs the complete offline suite once, runs its existing
offline Gemini action matrix and retains JUnit artifacts. Final delivery reports
the exact commit SHA, run link and outcome. No retained/application database is
used for destructive tests.

All work stays on `main`, with no branch, worktree, Pull Request or history rewrite.
