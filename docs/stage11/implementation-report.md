# Stage 11 — Backend implementation report

Date: 2026-11-02. Baseline Backend HEAD: `f618bba290f919dc3561b0462c59b3b86ade82d8`;
Frontend inspected read-only at `d6e03fe`. Operator approval covers four proposals,
the existing confirmation mechanism and a new forward-only migration. Backend
work is implemented; Frontend integration and full system acceptance remain pending.

## Delivered

- `propose_lesson_create`, `propose_lesson_update`, `propose_lesson_cancel`,
  `propose_lesson_replacement`: strict server argument/ownership validation and
  safe before/after previews, with no business writes during proposal creation.
- Confirmation delegates to the corresponding existing `LessonService` writer.
  Shared preflight enforces exact UTC, active overlaps, lifecycle and import rules.
- Existing explicit confirm/reject, lease exclusion, MySQL expiry, atomic history/
  receipt and idempotent replay; Lesson/graph/reference fingerprints detect stale
  reviews, including same-second edits and changed labels. Failed writes roll back.
- One global mutation proposal attempt per turn, including invalid attempts.
  The previous 23 tool definitions remain; four appended definitions make 27.
- New `2026_11_02_000001_add_ai_lesson_actions.php`: expands only the existing
  CHECK to 18 mutation types. Down refuses; no applied migration edited.
- [Exact Frontend REST contract](frontend-rest-contract.md), [rules](../ai-lesson-actions.md),
  [ADR-005](../decisions/ADR-005-ai-lesson-confirmation.md), Backend-local Stage 10
  coverage/capability addenda and updates to canonical Backend AI/architecture docs.

## Changed files

The complete path inventory is [changed-files.txt](changed-files.txt).
Application changes are four `AI/Actions/LessonAction*` classes,
`Schedule/LessonInputRules.php`, `LessonService.php`, `SaveLessonRequest.php`,
`AgentOrchestrator.php` and `TaskActionConfirmationService.php`, plus the migration.
No change was needed to the generic pending-action model/resource, proposal
finalization, existing routes or confirmation controller/request.

Tests add `LessonActionTest`, `AiLessonActionsTest`, `AiLessonActionConcurrencyTest`
and `AiLessonActionMigrationTest`; cookie/CSRF smoke coverage now exercises all
four Lesson actions. Existing provider discovery expectations and constructor
fixtures reflect 27 tools. A pre-existing autumn DST fixture is now generated in
the next calendar year so protected reviews are future-dated at execution time.

`Tests/Support/TestDatabase.php` and selector use in existing independent workers,
lock probes and database assertions allow a named disposable schema without
weakening the environment/driver/configured-name/connected-name checks. The original
test database remains the default for existing CI; Stage 11 runs explicitly select
an isolated name. `UsesForwardOnlyMigrations` rebuilds/wipes disposable schemas;
legacy down/up tests exclude the forward-only overlay's test-ledger entry and
reapply its up afterward. They retain assertions that prior migrations preserve
old data; the new migration has a separate refusal/upgrade/history-preservation test.

## Validation and evidence

Final results are recorded in [evidence/results.json](evidence/results.json).

| Check | Result |
| --- | --- |
| Targeted Lesson Unit/Feature/API/lifecycle/security/concurrency/migration tests | 244 passed, 2,337 assertions |
| Compatibility: cookie/CSRF smoke, legacy persistence/migration, history trimming, DST | 30 passed, 7,694 assertions |
| Full Backend Unit + Feature regression | 5,531 passed, 53,706 assertions (272.853s) |
| Laravel Pint on changed PHP files | Passed |
| `git diff --check` | Passed |
| Frontend tests | Not run; Backend-first scope |
| Real paid OpenAI behavior | Not authorized or executed; provider HTTP was faked/stray requests blocked |

Isolation: new MySQL 8.4.10 instance under `/tmp/stage11-mysql-isolated`,
`--skip-networking`, private socket `/tmp/stage11-mysql.sock`, only schema
`student_planner_isolated_stage11`. No `student_planner`, retained
`student_planner_testing` or Stage 8 acceptance database was migrated, truncated or
used by tests. The new migration was applied only to this disposable instance, which was stopped
after final verification.

Reproduction (set connection credentials/socket for your own disposable instance;
create the explicitly named empty schema first):

```sh
export PLANNER_TEST_DATABASE=student_planner_isolated_stage11
export DB_DATABASE="$PLANNER_TEST_DATABASE"
export DB_HOST=localhost DB_SOCKET=/tmp/stage11-mysql.sock DB_USERNAME=root DB_PASSWORD=''
vendor/bin/phpunit --filter='LessonAction|LessonApi|LessonLifecycle|LessonSecurity' --log-junit /tmp/stage11-targeted.xml
vendor/bin/phpunit --log-junit /tmp/stage11-backend-regression.xml
vendor/bin/pint --dirty --test
git diff --check
```

Raw successful PHPUnit summaries are retained under `evidence/`. JUnit reports
are `/tmp/stage11-targeted.xml`, `/tmp/stage11-compatibility.xml` and
`/tmp/stage11-backend-regression.xml`; evidence includes their hashes. The initial
full run exposed outdated discovery counts, legacy rollback assumptions and the
past DST date; these were corrected before the final regression.

## Remaining limits and integration

The current Frontend's unknown-type guard blocks confirmation until Lesson
reviews/types/labels and schedule refresh are added. Frontend changes and tests,
and edits to its Stage 9 `docs/defense/` and original Stage 10 audit, are deferred.
Those source documents were read; no Backend Stage 9 directory existed to update.
Backend-local addenda explicitly retain R21 as partial system-wide. Acceptance
criteria 6 and the Frontend portion of 8 are not satisfied by this Backend delivery.

No AI academic catalog discovery, Lesson delete/bulk/import, background relocation,
password recovery or new statistics was added. IDs missing from successful reads
or explicit user input require clarification. Existing Lesson conflicts concern
active Lessons; changing one does not reschedule StudySessions. The client must
show real server feedback and request a fresh proposal for stale/invalid reviews.

Deploy schema before enabling new proposals; ALTER can require a metadata lock.
Old Backend versions cannot execute Lesson action history, and down is deliberately
unsupported. Shared/valuable database deployment remains an operator operation.
