# Explicit work completion and session accounting

Work status is a student decision. Session status and actual minutes are independent.

## Правила взаємодії роботи, сесій і планувальника

Перевірено за локальним Backend; назви звірено з `src/utils/tasks.ts` і
`src/utils/planning.ts` сусіднього Frontend (без його редагування).

| Статус завдання (`TaskStatus`) | Значення та вплив на новий план |
| --- | --- |
| Очікує виконання (`pending`) | Робота відкрита; планується лише придатний непокритий залишок. |
| Не виконано (`missed`) | Явно позначена незавершена робота; ті самі умови планування і той самий дедлайн, що для `pending`. |
| Завершено (`completed`) | Результат готовий; новий час для цієї роботи не виділяється. |

**Статус роботи, прострочення і пропуск сесії — різні поняття.** Прострочення
визначається дедлайном незавершеної роботи й поточним часом, а не окремим
збереженим статусом. `StudySessionStatus::Missed` означає «Пропущено» для однієї
сесії, не «Не виконано» для завдання. Минулі сесії без явного рішення залишаються
`planned`. Автоматичного переходу завдання в `missed` після дедлайну немає:
`TaskService::miss()` викликається явною дією; запланований
`learning:detect-recovery` створює лише огляди. Підзавдання мають лише
`pending`/`completed`, хоча модель використовує спільний PHP enum.

`PlanningWorkSelectionService::select()` і `RemainingEffortService::calculate()`
визначають залишок у секундах:
`max(0, max(0, оцінка − зарахована виконана робота) − чинні резервування)`.
Завершені сесії зараховуються за `actual_minutes × 60`; для історичних записів
із null — за запланованою тривалістю. Нове завершення вимагає фактичних хвилин.
Чинне резервування — правильно пов'язана `planned` сесія зі стартом `>= now`
та кінцем не пізніше ефективного дедлайну. Інші статуси не дають резервування;
скасування не додає виконаних хвилин. Це облік залишку, окремий від лімітів
навантаження, де враховуються заплановані інтервали `planned`/`completed`.

За наявності активних підзавдань плануються саме вони, без повторного планування
оцінки батька. Їхній ефективний дедлайн — найраніший непорожній дедлайн батька
або підзавдання. Завершена робота (також діти завершеного батька), видалена
робота і робота з ефективним дедлайном `<= now` виключаються з нового плану.
Видалення останнього активного підзавдання повертає батькові роль одиниці
планування; історія видаленого підзавдання йому не перепризначається.
Перепланування змінює розміщення сесій через наступників, **не дедлайни роботи**.

| Дія | Підтверджена поведінка Backend |
| --- | --- |
| Завершити тільки завдання | Після актуального підтвердження `WorkCompletionService::cancel()` скасовує майбутні `planned` сесії батька та його підзавдань; статуси підзавдань не змінюються. |
| Завершити тільки підзавдання | Той самий механізм лише для цього підзавдання; батько, сусідні підзавдання та їхні сесії зберігаються. |
| Завершити роботу разом із сесією | Явно вибрана пов'язана `planned` сесія зі стартом `<= now`, обов'язкові фактичні хвилини і `complete_work=true`. `StudySessionLifecycleService::complete()` атомарно завершує сесію та роботу через Task/Subtask Service і скасовує інші майбутні плани цієї роботи. Зв'язок із підзавданням завершує саме його. |
| Завершити тільки сесію | Типова дія (`complete_work=false`): зберігає фактичні хвилини, залишає статус роботи без змін; зарахований час зменшує її залишок. |
| Видалити завдання | `TaskService::delete()` атомарно виконує soft delete батька й активних дітей та очищення їхніх залежностей. |
| Видалити підзавдання | `SubtaskService::delete()` видаляє лише цей крок і очищує його залежності; сусідні сесії не змінюються. |

Завершення роботи без сесії скасовує тільки `planned` зі стартом **`> now`**:
поточні, минулі та всі термінальні записи зберігаються. За наявності майбутніх
сесій потрібен актуальний `completion_token`; без нього або при зміні набору —
409 без часткових змін. Якщо таких сесій немає, завершення без токена дозволене.

**Видалення має іншу межу:** `TaskDependencyCleanupService::cancel()` скасовує
`planned` зі стартом **`>= now`**, включно з рівністю. Сесії зі стартом `< now`
(поточні та повністю минулі) і термінальні записи зберігаються. Не переносити
на видалення строгий поріг завершення. В обох випадках скасовані записи мають
`actual_minutes=null`, `completed_at=null`, звільняють час і зберігають історичні
зв'язки; відповідні заплановані нагадування скасовуються. Повторне відкриття
роботи не відновлює скасовані сесії й саме по собі не створює план.

### Регресійне покриття правил

Шляхи нижче відносні до `tests/`; наведені методи є перевірками поведінки,
а не порівняннями текстів відповідей AI. Наявне покриття повторно не дублюється.

| Правило | Набір і метод |
| --- | --- |
| Однаковий залишок pending/missed; незмінний дедлайн | `Feature/TaskMissedStatusTest::test_explicit_miss_preserves_deadline_and_remaining_plan_after_credit_and_reservations` |
| Дедлайн не змінює статус автоматично; прострочений крок не планується | `Feature/PlanningCompletionRulesTest::test_deadline_passage_and_scheduled_detection_do_not_infer_missed_status` |
| Завершені/видалені/прострочені не отримують нових сесій | `Feature/StudyPlanningServiceTest::test_empty_or_excluded_stage_a_work_remains_excluded`; `Feature/PlanningWorkSelectionServiceTest::test_parent_and_child_statuses_are_independent_and_excluded_without_mutation`, `test_only_deleted_children_restore_task_authority_without_reassigning_child_history`, `test_expired_deadlines_exclude_new_planning_without_historical_slots` |
| Завершення батька/кроку, підтвердження, межа старту, null фактичних хвилин | `Feature/WorkCompletionTest::test_review_consent_boundary_history_and_reopening`, `test_child_scope_reminders_and_atomic_failure`, `test_exact_set_and_zero_reviews_are_rechecked`, `test_empty_completion_and_foreign_review` |
| Спільне завершення: вибраний крок/батько, старт уже настав, атомарність | `Feature/WorkCompletionTest::test_session_completion_is_independent_by_default_and_combined_action_uses_linked_child`, `test_combined_task_completion_and_completed_work_capability`, `test_combined_task_completion_rolls_back_on_stale_review_and_session_save_failure` |
| Лише сесія: статус роботи незмінний, фактичні хвилини зараховані | `Feature/StudySessionLifecycleTest::test_completion_preserves_history_and_uses_actual_first_accounting`; `Unit/RemainingEffortServiceTest` |
| Завершення зберігає минуле; час звільняється | `Feature/WorkCompletionTest::test_completion_preserves_all_terminal_and_elapsed_session_records`, `test_cancelled_future_time_is_available_without_creating_a_new_plan_and_checks_dispatch_after_commit` |
| Видалення: батько/крок, рівність старту, поточні/минулі записи, null хвилин | `Feature/TaskDeletionTest::test_task_session_cleanup_preserves_lifecycle_metadata_and_relations`; `Feature/SubtaskDeletionTest::test_only_future_planned_sessions_and_their_scheduled_reminders_are_cancelled`, `test_fractional_utc_cutoff_does_not_round_a_past_start_up_to_now` |
| Ізоляція сусідів при видаленні | `Feature/SubtaskDeletionTest::test_direct_reminders_cancel_without_changing_sent_cancelled_general_or_sibling_history`; `Feature/TaskDeletionTest::test_reminders_for_task_all_descendants_and_newly_cancelled_sessions_cancel_only_scheduled_records` |
| Перепланування зберігає дедлайни, статуси та історію роботи | `Feature/StudySessionLifecycleTest::test_reschedule_history_exact_fields_partial_summary_and_valid_chain`; `Feature/StudyReplanningConfirmationTest::test_full_batch_preserves_intervals_history_effort_and_reminders` (повні snapshots інших таблиць) |

Розбіжностей реалізації з очікуваними таблицями не виявлено. Різницю `>`/`>=`
для завершення та видалення зафіксовано явно; зміни бізнес-логіки не внесено.

## Existing completion contract

Stage 1 adds authenticated SELECT-only `GET /api/tasks/{task}/completion-review`
and `GET /api/tasks/{task}/subtasks/{subtask}/completion-review`. Response fields:
`future_sessions_count`, `message`, `completion_token` (encrypted exact owned
session identities and intervals). The existing completion POST accepts that token.
A missing token with future sessions or any changed reviewed set returns 409 with
`completion_review`; no work, reminder or session write survives. Zero-session
legacy completion remains supported. A supplied zero-session token is rechecked.

Owner/work/session locks precede the operation clock. Only planned sessions with
`starts_at > now` are cancelled using model writes, preserving history and the
existing after-commit plan-attention observer. Existing work reminder completion
rules remain authoritative. Reopening never resurrects cancelled data. AI work
proposals show the server review and use its token only on explicit authenticated
pending-action confirmation. No model tool can grant consent.

Frontend task/subtask completion shares one review-before-write API adapter.
Each explicit action reads a fresh review; stale/unknown writes are never retried
automatically. The next attempt requires a fresh review and consent.

Stage 2 adds `GET /api/study-sessions/{id}/completion-review`: `can_complete_work`,
`work_type` and nullable `completion_review`. The complete POST adds optional
strict boolean `complete_work` (default false) and `completion_token`. It accepts
no client work ID. Required `actual_minutes` and lifecycle rules are unchanged.
A combined write runs inside the session transaction, uses the Task/Subtask
services and rolls back both transitions on stale consent or any write failure.
Completed/deleted work suppresses the checkbox; session-only completion is still
available under the existing rules. AI session proposals remain session-only;
combined completion is available through authenticated REST/UI, not model input.

Stage 3 preserves existing miss/cancel/transfer/split/deadline/reopen algorithms.
Read-only workload advice adds `review_estimated_effort` for open authoritative
Task work and `review_subtask_estimated_effort` for open authoritative Subtask
work whose completed credit covers its estimate. Reservations alone never trigger
these codes. Completed work and derived parent estimates are excluded. Frontend
advice links to the corresponding step editor via `?subtask_id=...`; all-steps
completion remains a positive exact count equality, independent of rounded percent.

## Verification record

### Documentation/regression audit — 2026-11-13

Initial `git status --short` was empty; branch `main`. Only documentation and
tests changed. No application/API/schema/UI/business-logic changes were made.
Frontend was read only to check labels. No full repository suite or browser run
is claimed. Each database run below used a freshly initialized private MySQL
datadir and random `student_planner_isolated_*` database verified by the existing
runner; no working database was used.

Changed files:

- `docs/work-completion.md`, `docs/business-rules.md`, `docs/domain-model.md`;
- `tests/Feature/WorkCompletionTest.php`, `tests/Feature/TaskMissedStatusTest.php`,
  `tests/Feature/PlanningCompletionRulesTest.php`, `tests/Feature/TaskDeletionTest.php`,
  `tests/Feature/SubtaskDeletionTest.php`.

Final commands and actual outcomes (line continuations only improve readability):

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/WorkCompletionTest.php tests/Feature/TaskMissedStatusTest.php \
  tests/Feature/PlanningCompletionRulesTest.php tests/Feature/TaskDeletionTest.php \
  tests/Feature/SubtaskDeletionTest.php
# PASS: 62 tests, 796 assertions, 7212 ms

scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/WorkCompletionTest.php tests/Feature/TaskMissedStatusTest.php \
  tests/Feature/PlanningCompletionRulesTest.php tests/Feature/TaskDeletionTest.php \
  tests/Feature/SubtaskDeletionTest.php tests/Feature/PlanningWorkSelectionServiceTest.php \
  tests/Feature/StudyPlanningServiceTest.php tests/Feature/StudySessionLifecycleTest.php \
  tests/Feature/StudyReplanningConfirmationTest.php tests/Feature/TaskLifecycleTest.php \
  tests/Feature/SubtaskLifecycleTest.php tests/Feature/TaskProgressTest.php \
  tests/Feature/TaskSecurityTest.php tests/Feature/SubtaskSecurityTest.php \
  tests/Unit/RemainingEffortServiceTest.php tests/Unit/StudySessionLifecycleRulesTest.php
# PASS: 814 tests, 6086 assertions, 55603 ms

vendor/bin/pint --test tests/Feature/WorkCompletionTest.php \
  tests/Feature/TaskMissedStatusTest.php tests/Feature/PlanningCompletionRulesTest.php \
  tests/Feature/TaskDeletionTest.php tests/Feature/SubtaskDeletionTest.php
# PASS
git diff --check
# PASS (no output)
```

Intermediate runs exposed test setup issues, not a mismatch with the expected
domain tables: 58 tests / 21 failures initially; 62 / 1 after isolation fixes;
one focused lock-order diagnostic / 1 failure before its final correction.
`WorkCompletionTest` now truncates on teardown as other lifecycle suites do,
preventing fixture leakage into subsequent RefreshDatabase tests. The new detector
regression lives in `PlanningCompletionRulesTest`, where no outer test transaction
violates the detector's public contract. The deletion lock test now checks all
five domain locks at the deletion transaction level, ignoring estimate reads and
the observer's nested reentrant owner lock; it also verifies parent rollback.
The first Pint check reported import ordering, corrected with
`vendor/bin/pint tests/Feature/PlanningCompletionRulesTest.php` before passing.

### Historical implementation verification

All backend runs used `scripts/testing/phpunit-disposable-mysql.sh` (separate
fresh datadirs, database identities verified by the script). No working database,
schema, demo data or credentials were changed. Both repositories started clean on
`main`; all implementation commits remain on `main`.

- Stage 1: 163/163 Task/Subtask REST and AI lifecycle tests, plus 5/5 dedicated
  completion tests. Frontend new review adapter tests 3/3; type-check and build
  passed. Legacy frontend Subtask tests had stale button/date-bound expectations;
  those tests were subsequently updated to the existing checkbox and date bounds,
  preserving their lifecycle assertions.
- Stage 2: 308/308 session lifecycle/rule/AI and completion tests; final shared-clock
  recheck 8/8. Existing frontend targeted tests 98/98 and new checkbox/combined
  tests 3/3; type-check and build passed.
- Stage 3: 237/237 completion, workload advice, planning completion, attention,
  split replanning, exact progress, remaining effort and work selection tests.
  Additional AI zero-review/new-session conflict test: 1/1 (11 assertions).
  Final frontend targeted set: 169/169 across 8 files, including stale-set consent,
  no automatic POST retry, both work labels, default unchecked behavior, exact
  all-step equality, and direct step advice. Type-check/build passed.

Stage 1 changed backend Task/Subtask controllers and services, WorkCompletionService,
AI Task/Subtask proposal/confirmation presenters and adapters, API routes and their
lifecycle/consent tests. Frontend changed endpoints.ts, subtasks.ts, workCompletion.ts,
aiEffects.ts, and adapter tests/fixtures.

Stage 2 changed StudySessionController, CompleteStudySessionRequest,
StudySessionLifecycleService, TaskService/SubtaskService (shared operation clock),
routes and WorkCompletionTest. Frontend changed studySessions.ts,
StudySessionView.vue and session-work-completion.test.ts.

Stage 3 changed WorkloadAdviceService, completion/workload/AI regression tests and
current architecture/domain/business-rule notes. Frontend changed workload
recommendations, WorkloadAdviceView, useSubtasks, TasksView, workCompletion.ts
(neutral declined-consent handling), and relevant tests.

Limits: this is targeted verification, not a full repository suite or live-browser
acceptance run. AI session proposals intentionally expose no combined-completion
flag. A stale AI work proposal must be proposed/reviewed again; re-confirming its
old persisted review cannot authorize a different session set. Queue workers and
the existing Scheduler remain necessary for eventual plan attention analysis.

## Manual acceptance

1. Open an elapsed planned session. Enter actual minutes and leave the checkbox
   unchecked. Complete it: only the session changes; work remains open.
2. Open an elapsed Subtask session that has another future planned session. Check
   “Також позначити підзавдання виконаним”, enter actual minutes and accept the
   server count. Only that step completes; its future session/reminder cancel;
   the parent and other steps retain their statuses.
3. Complete a Task with its own and child future sessions. Review the server count
   before confirmation. Future plans cancel; elapsed unresolved sessions remain
   available for an explicit outcome; completed history/actual minutes remain.
4. Reopen the Task/step. Cancelled sessions and reminders remain cancelled and
   visible in history. Review a new planning preview to allocate more study time;
   reopening itself creates no sessions.
