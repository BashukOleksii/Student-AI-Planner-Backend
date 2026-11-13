# Узгодження AI-можливостей і тестів датованих Lesson

Перевірка від 2026-11-13, початковий стан `ae15ddb`, гілка `main`.
Перед змінами `git status --short` був порожній.

## Чинний контракт

Провайдер отримує лише три інструменти окремої датованої Lesson:
`propose_lesson_create`, `propose_lesson_update`, `propose_lesson_cancel`.
Видалення та нова заміна не рекламуються. Семестрові зміни виконуються через
відповідні зареєстровані інструменти, включно з незміненим
`propose_semester_occurrence_create`; віртуальна ідентичність не підставляється
в числовий `lesson_id`, а окреме заняття не створюється в обхід семестру.

`LessonActionRegistry::NAMES` і MySQL CHECK містять історичний тип заміни.
Це не список поточних можливостей: `definitions()` не включає replacement,
а `find()` зберігає його схему для історичного dispatch. Чинний
`LessonActionService::prepare()` доходить до `LessonService::prepareReplacement()`,
який відмовляє: результат `invalid_arguments`, пропозиція відсутня.
Підтвердження старої pending-заміни також не відновлює операцію: на актуальному
графі повертається 422 `action_validation_failed`, дія залишається pending без
змін розкладу. Читання історичної дії та повтор executed-квитанції залишаються
чинними; повтор не викликає writer. Наявне історичне заняття-заміну можна
редагувати/скасувати допустимими діями, не відновлюючи оригінал при скасуванні.

Завершення Task/Subtask описує чинний огляд наслідків: після явного
підтвердження скасовуються переглянуті пов'язані `planned` сесії зі стартом
строго після operation clock і відповідні scheduled-нагадування. Для Task це
його сесії та сесії дітей; статуси дітей не змінюються. Для Subtask — тільки
власні сесії, без зміни батька/сусідів. Початі, минулі та термінальні сесії
зберігають статуси й фактичну історію; скасування не зараховує фактичних хвилин.

Змінено тільки текст системних інструкцій/описів та тести. Параметри інструментів,
список реєстрації, історичний dispatch, writer-и, схема БД, REST, семестрові
правила, ліміт однієї пропозиції, передавання `completion_token` та формат
помилок залишилися без змін. Frontend не редагувався.

## Як перевірено поведінку

- `AgentOrchestratorTest` перевіряє фактичні definitions, передані підробному
  `LlmProvider`, а не тільки константи: три Lesson-дії, без delete/replacement,
  зі збереженим семестровим додаванням. `AiCapabilityContractTest` окремо
  розмежовує поточну discovery-схему та історичний `find()`.
- `AiLessonActionsTest` використовує чинну залежність `LessonService` —
  `CalendarBusyIntervalService`, включно з partial mock для перевірки делегування.
  Для чинних операцій збережені власність, огляд без записів, явне підтвердження,
  stale-state, конфлікти, атомарність і повтор квитанції.
- Колишні позитивні сценарії створення replacement замінено перевірками
  відмови без пропозиції чи зміни розкладу. Історичні рядки явно створюються
  factory як fixtures: вимкнений writer для цього не викликається. Збережено
  перевірки редагування/скасування такого заняття, stale deleted-history,
  незмінності імпортної ідентичності, читання старої дії та replay executed-дії.
- Дії Lesson відхиляють віртуальні rule/snapshot identities у `lesson_id`,
  включно зі спробою передати зайве поле до create; proposal і записи відсутні.
- `AiTaskActionsTest` перевіряє огляд із двома майбутніми сесіями батька/дитини,
  їх скасування та null actual/completed fields; минула сесія й старт рівно
  зараз не змінюються. Reopen нічого не відновлює.
- `AiSubtaskReminderActionsTest` перевіряє власну майбутню сесію, незмінність
  початої сесії, батька, сусіда та їхніх майбутніх сесій. Додатково виконано
  повний `WorkCompletionTest` із перевірками історії та відкату.

Це структурні й детерміновані перевірки з підробним провайдером, а не перевірка
того, як реальна модель розуміє довільний текст чи завжди дотримується інструкцій.

## Команди та фактичні результати

Спочатку збої відтворено на коді до змін:

```bash
scripts/testing/phpunit-disposable-mysql.sh tests/Feature/AiLessonActionsTest.php
# EXIT 2: 39 tests, 25 passed, 1 failure, 13 errors, 418 assertions, 7651 ms
```

Причини відповідали попередньому звіту: `new LessonService` без обов'язкової
залежності та спроби створити вже вимкнену заміну/отримати її `action`.
Після основного оновлення був один збій нового історичного replay-тесту
(36 tests, 35 passed, 643 assertions): MySQL нормалізував порядок JSON-ключів.
Очікувана квитанція тепер читається з persisted-ресурсу; перевірка її точного
повтору та незмінності збереженого запису лишилася. Формат API не змінювався.

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiLessonActionsTest.php tests/Feature/AiTaskActionsTest.php \
  tests/Feature/AiSubtaskReminderActionsTest.php
# PASS: 147 tests, 2335 assertions, 22619 ms

scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiLessonActionsTest.php tests/Feature/AiSemesterActionsTest.php \
  tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php \
  tests/Feature/WorkCompletionTest.php
# PASS: 189 tests, 2991 assertions, 31676 ms

vendor/bin/phpunit tests/Unit/AiCapabilityContractTest.php \
  tests/Unit/AgentOrchestratorTest.php tests/Unit/LessonActionTest.php
# PASS: 117 tests, 731 assertions, 1250 ms

vendor/bin/pint --test app/Services/AI/AgentOrchestrator.php \
  app/Services/AI/Actions/LessonActionRegistry.php \
  app/Services/AI/Actions/TaskActionRegistry.php \
  app/Services/AI/Actions/SubtaskReminderActionRegistry.php \
  tests/Feature/AiLessonActionsTest.php tests/Feature/AiTaskActionsTest.php \
  tests/Feature/AiSubtaskReminderActionsTest.php \
  tests/Unit/AiCapabilityContractTest.php tests/Unit/AgentOrchestratorTest.php \
  tests/Unit/LessonActionTest.php
# PASS
git diff --check
# PASS (no output)
```

Усі перераховані набори виконані повністю, без фільтрів. Runner для кожного
MySQL-прогону створював новий приватний datadir, перевіряв його ідентичність та
випадкову `student_planner_isolated_*` БД. Робоча БД і живий LLM не використовувалися.

## Залишкові збої поза межами завдання

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/SemesterScheduleReadApiTest.php tests/Feature/AiAvailabilityActionsTest.php
# EXIT 1: 92 tests, 88 passed, 4 failures, 1139 assertions, 9651 ms
```

- `SemesterScheduleReadApiTest::test_day_occurrence_is_a_stable_virtual_resource_with_optional_metadata`,
  рядок 151: exact JSON не очікує чинне поле `change_kind=base` — 1 failure.
- `AiAvailabilityActionsTest::test_explicit_confirmation_delegates_once_to_existing_service_and_replays_immutable_receipt`,
  рядок 224, datasets 0–2: snapshot не враховує збільшення
  `recovery_scan_states.requested_version` 5 → 6 чинним observer — 3 failures.

Ці файли не змінювалися. Зелений профільний прогін не означає зелену повну
регресію: перелічені чотири збої залишаються; увесь репозиторний набір,
Frontend/browser та живі моделі в цьому завданні не перевірялися.

## Змінені файли

- `app/Services/AI/AgentOrchestrator.php`;
- `app/Services/AI/Actions/LessonActionRegistry.php`;
- `app/Services/AI/Actions/TaskActionRegistry.php`;
- `app/Services/AI/Actions/SubtaskReminderActionRegistry.php`;
- `tests/Feature/AiLessonActionsTest.php`;
- `tests/Feature/AiTaskActionsTest.php`;
- `tests/Feature/AiSubtaskReminderActionsTest.php`;
- `tests/Unit/AiCapabilityContractTest.php`;
- `tests/Unit/AgentOrchestratorTest.php`;
- `tests/Unit/LessonActionTest.php`;
- `docs/ai-capability-alignment.md`;
- `docs/ai-semester-occurrence-create.md` (посилання на цей follow-up).
