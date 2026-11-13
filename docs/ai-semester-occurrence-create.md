# AI: додати одну пару на конкретну дату

Подальше узгодження описів AI та виправлення історично застарілих
`AiLessonActionsTest` після `ae15ddb`: [поточний звіт](ai-capability-alignment.md).
Результати нижче фіксують саме етап завершення семестрового додавання;
14 збоїв Lesson із того етапу виправлено в follow-up, інші 4 залишаються.

Блокер збереження усунено новою forward-only міграцією
`2026_11_13_000003_add_ai_semester_occurrence_create_action.php`. Вона одним
атомарним ALTER змінює лише `ai_actions_valid_type`: зберігає всі 32 попередні
типи (включно з історичним `propose_lesson_replacement`) і додає тільки
`propose_semester_occurrence_create`. Невідомі типи й надалі відхиляються.
`down()` відмовляє без змін даних; старі міграції не редагуються.

Міграцію застосовано лише в ізольованих disposable MySQL-прогонах. На робочій БД
вона не запускалася; середовище використання нового типу потребує застосування
цієї міграції звичайним процесом розгортання. Повний Backend-цикл перевірено
підробним провайдером, без викликів живого LLM; результати наведено нижче.

Інструмент і тип пропозиції: `propose_semester_occurrence_create`.
Це окрема операція у чинному циклі AiPendingAction, з тим самим лімітом однієї
пропозиції за хід. Ручні REST-операції, `replaceDate`, щотижневі правила,
планувальник і Frontend не змінюються.

## Аргументи інструмента

```json
{
  "semester_schedule_id": 12,
  "date": "2026-10-24",
  "pair_number": 3,
  "starts_at_local": "12:00:00",
  "ends_at_local": "13:00:00",
  "subject_id": 9,
  "teacher_id": 7,
  "room": "301"
}
```

Обов'язкові поля — всі наведені, крім `room`. Необов'язкові поля:
`room` (string/null, до 100 символів), `pair_label` (string/null, до 32),
`lesson_type` (чинний LessonType/null). Дата — локальне `YYYY-MM-DD` у межах
семестру, час — точне `HH:MM:SS` у збереженій зоні користувача; ID — додатні
цілі, номер пари — 1–255. Масив `lessons`, `mode`, `changes`, `occurrence_id`,
джерело/парність копіювання і тривалість не приймаються.

Модель шукає назви через чинний `search_academic_catalog` (`kind`, `query`,
необов'язкові `page`/`per_page`). Студенту не потрібно вводити ID. За нульового
або неоднозначного збігу потрібне уточнення до пропозиції; сторінка з одним
результатом не доводить унікальність без metadata. Враховується викладач,
пов'язаний із предметом. Не можна вгадувати ID або перепризначати цей зв'язок.
Якщо початок і кінець суперечать названій тривалості, агент має уточнити намір,
а не мовчки відкинути тривалість чи змінити час. Доменний сервіс не парсить текст.

Вибір операції:

- **Додати одну нову пару на дату:** `propose_semester_occurrence_create`.
- **Змінити наявну пару:** `propose_semester_occurrence_update` з чинною
  віртуальною ідентичністю, отриманою з ефективного розкладу.
- **Замінити весь день:** `propose_semester_date_replace`; чинна семантика
  повного списку / `copy_base` залишається незмінною.
- **Скопіювати день на нову робочу суботу:** `propose_semester_working_saturday`;
  не використовувати для додавання третьої пари до вже створеної суботи.

## Збереження та перевірки

`SemesterScheduleManagementService::createOccurrence()` читає ефективну дату
через існуючий калькулятор і поточний snapshot, а не довільний вихідний день.
Застосовуються чинні перевірки власності, предмета/викладача, меж семестру,
дублікатів номера, перетинів, локального часу і повної проєкції семестру.
Зайнятий номер і часовий перетин відхиляються незалежно, без заміни чи підбору
іншої пари. Порожній збережений виняток означає скасовану дату: додавання
відхиляється; відновлення має бути окремим явним наміром.

Якщо snapshot уже існує, вставляється лише новий рядок і його зв'язок із
викладачем. Наявні рядки, ID, timestamps, віртуальні occurrence IDs, зв'язки
викладачів, `source_weekly_rule_id`, `is_added` та запис винятку не переписуються.
Зокрема зберігаються `kind=working_saturday`, `copied_weekday` і
`source_week_parity`. Для дня за базовими правилами створюється snapshot лише
цієї дати: копії отримують власні snapshot IDs, посилання на початкові правила
та `is_added=false` відповідно до чинного контракту заморожування. Інші дати
і рядки щотижневих правил зберігаються. Нове заняття має `is_added=true` та
`source_weekly_rule_id=null`; ефективні читання показують `change_kind=added`.

Підготовка виконує лише перевірку проєкції, не змінює академічні дані.
Підтвердження використовує чинні conversation/action/owner locks, повний
fingerprint і повторну перевірку огляду. Реальний запис збільшує
`management_revision` один раз. Зміна розкладу чи каталогу робить пропозицію
застарілою. Збереження заняття, дії, квитанції та історії атомарне.

## Мінімальний контракт Frontend

Frontend у цьому завданні не змінюється. Наявність Backend-інструмента не означає,
що сторінка вже підтримує цей новий тип пропозиції.

Відповідь чату має чинний компактний `data.action`: `id`, `call_id`,
`state=confirmation_required`, `expires_at`, `preview`; тип береться з
`preview.type`. Повний ресурс дії при читанні/підтвердженні додатково містить
`type`, `status`, `confirmation_required`, `receipt` та timestamps.
Огляд має таку форму:

```text
preview.type = propose_semester_occurrence_create
preview.confirmation_required = true
preview.semester_schedule_id, preview.timezone
preview.before / preview.after = {
  date, kind, copied_weekday, source_week_parity,
  lessons: [{ occurrence_id, pair_number, pair_label, starts_at, ends_at,
              subject_id, teacher_id, subject: {id, name}, teacher: {id, name},
              room, lesson_type, change_kind, ... }]
}
preview.effects.scope = one_date
preview.effects.reschedules_study_sessions = false
preview.effects.changes_legacy_lessons = false
```

`before.lessons` містить поточний день, `after.lessons` — поточний день плюс одну
пару. Показувати дату, номер, час із часовою зоною, назву предмета, ім'я
викладача та аудиторію, якщо вона задана. Майбутні IDs нового snapshot/заняття
в проєкції не є збереженими ідентичностями; після запису використовувати квитанцію
та свіжі читання. Токен/fingerprint розкладу не передається Frontend чи моделі.

Використовуються існуючі маршрути від `/api`:

- `POST /ai/conversations/{conversation}/actions/{action}/confirm` із порожнім
  JSON object: HTTP 200, `data.status=executed`, `data.receipt` містить
  `action_id`, `type`, `semester_schedule_id`, `operation`, `timezone`, `after`,
  `effects`. Повторне підтвердження повертає ту саму квитанцію без дубля.
- `POST .../{action}/reject`: HTTP 200, `data.status=rejected`, без змін розкладу;
  наступне підтвердження відхилено з HTTP 409.
- Застарілий граф/огляд: HTTP 409, `code=action_stale`, без квитанції/запису.
  Потрібна свіжа пропозиція, автоматичного повтору запису немає.
- Прострочення: HTTP 410; недопустимий актуальний стан:
  HTTP 422, `code=action_validation_failed`; чужа розмова/дія: HTTP 404.
- Помилка підготовки повертається у чинному tool result: `ok=false`,
  `error.code=invalid_arguments` для конфліктів/невалідних посилань або `not_found`
  для недоступного розкладу. Пропозиція відсутня; чат сам по собі не стає HTTP 422.

## Перевірка

`SemesterActionSchemaTest` перевіряє форму одного заняття і відмову від повного
списку. `AiSemesterActionsTest` охоплює додавання на суботу зі зміненим snapshot,
незмінність наявних рядків/зв'язків/метаданих/правил, ізоляцію базової дати,
незалежні конфлікти, власність, скасовану дату, відхилення, stale review,
атомарність історії та replay. Сценарій із підробним LlmProvider перевіряє
передавання знайдених за назвами ID в пропозицію та надані агенту інструкції.
Це не перевірка розуміння довільного тексту реальною моделлю; живий LLM не потрібен.

Фактично виконані перевірки завершення сценарію (2026-11-13), без фільтрів:

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiSemesterOccurrenceCreateMigrationTest.php \
  tests/Feature/AiSemesterActionMigrationTest.php tests/Feature/AiSemesterActionsTest.php
# PASS: 35 tests, 702 assertions, 15282 ms

vendor/bin/phpunit tests/Unit/SemesterActionSchemaTest.php \
  tests/Unit/AiCapabilityContractTest.php tests/Unit/AgentOrchestratorTest.php
# PASS: 39 tests, 532 assertions, 1187 ms
```

Фінальний профільний прогін на поточному коді, також без фільтрів:

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiSemesterOccurrenceCreateMigrationTest.php \
  tests/Feature/AiSemesterActionMigrationTest.php tests/Feature/AiSemesterActionsTest.php \
  tests/Feature/SemesterScheduleManagementTest.php \
  tests/Feature/SemesterScheduleManagementPersistenceTest.php \
  tests/Feature/SemesterScheduleManagementImportTest.php \
  tests/Feature/SemesterScheduleManagementConcurrencyTest.php \
  tests/Feature/AcademicCalendarApiTest.php
# PASS: 113 tests, 2113 assertions, 42529 ms

vendor/bin/pint --test \
  database/migrations/2026_11_13_000003_add_ai_semester_occurrence_create_action.php \
  tests/Feature/AiSemesterOccurrenceCreateMigrationTest.php \
  tests/Feature/AiSemesterActionMigrationTest.php tests/Feature/AiSemesterActionsTest.php
# PASS
git diff --check
# PASS (no output)
```

Розширена регресія попередніх AI-дій і читань також виконувалася повністю:

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiSemesterOccurrenceCreateMigrationTest.php \
  tests/Feature/AiSemesterActionMigrationTest.php tests/Feature/AiSemesterActionsTest.php \
  tests/Feature/SemesterScheduleManagementTest.php \
  tests/Feature/SemesterScheduleManagementPersistenceTest.php \
  tests/Feature/SemesterScheduleManagementImportTest.php \
  tests/Feature/SemesterScheduleManagementConcurrencyTest.php \
  tests/Feature/SemesterScheduleReadApiTest.php tests/Feature/AcademicCalendarApiTest.php \
  tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php \
  tests/Feature/AiPlanningActionsTest.php tests/Feature/AiAvailabilityActionsTest.php \
  tests/Feature/AiLessonActionsTest.php
# EXIT 2: 474 tests, 456 passed, 5 failures, 13 errors, 6734 assertions, 84828 ms
```

Усі 18 збоїв відтворено на початковому `3746e6f`, без нової міграції, через
тимчасовий `git archive` export; гілка `main` і робочі файли не перемикалися.
App/config/routes не відрізняються від `3746e6f`. Незмінні Composer dependencies
перевикористано через symlink, БД знову створював disposable runner:

```bash
baseline_dir=$(mktemp -d /tmp/planner-baseline.XXXXXXXX)
git archive 3746e6f | tar -x -C "$baseline_dir"
ln -s "$PWD/vendor" "$baseline_dir/vendor"
APP_BASE_PATH="$baseline_dir" "$baseline_dir/scripts/testing/phpunit-disposable-mysql.sh" \
  tests/Feature/SemesterScheduleReadApiTest.php \
  tests/Feature/AiAvailabilityActionsTest.php tests/Feature/AiLessonActionsTest.php
# EXIT 2: 131 tests, 113 passed, the same 5 failures / 13 errors,
# 1557 assertions, 13928 ms
```

| Набір без змін | Відтворена причина | Збоїв |
| --- | --- | --- |
| `SemesterScheduleReadApiTest::test_day_occurrence_is_a_stable_virtual_resource_with_optional_metadata` (рядок 151) | Старий exact JSON не очікує чинний `change_kind=base`. | 1 failure |
| `AiAvailabilityActionsTest::test_explicit_confirmation_delegates_once_to_existing_service_and_replays_immutable_receipt` (рядок 224, datasets 0–2) | Snapshot очікує незмінний `recovery_scan_states`, але чинний observer збільшує `requested_version` 5 → 6. | 3 failures |
| `AiLessonActionsTest` | Застаріле створення `new LessonService` без потрібної залежності (рядок 114); очікування вже вимкненої replacement-операції та відсутній `action` у таких пропозиціях (рядки 178–414). | 1 failure, 13 errors |

Ці збої не виправлялися і assertions цих наборів не змінювалися. Зелений
результат усієї розширеної регресії не заявляється. Старі дозволені типи
збережені на рівні MySQL; `AiTaskActionsTest`, `AiSubtaskReminderActionsTest`,
`AiPlanningActionsTest` пройшли в розширеному прогоні. Історичний тип
`propose_lesson_replacement` у CHECK зберігає історію, а не відновлює вимкнену
операцію.

`AiSemesterOccurrenceCreateMigrationTest` перевіряє повний ланцюжок міграцій на
чистій схемі та оновлення попередньої схеми з усіма старими типами і станами
`pending`/`executed`/`rejected`/`expired`. Після оновлення raw записи, включно з
arguments, preview, encrypted payload, квитанціями й timestamps, незмінні.
Дозволений список дорівнює попередньому плюс рівно один тип; інші CHECK,
колонки, індекси та FK не змінені. Перевірено INSERT усіх попередніх типів і
нового типу, відмову INSERT/UPDATE невідомого типу та відмову `down()` без зміни
записів чи CHECK. Історичний `AiSemesterActionMigrationTest` тепер явно перевіряє
свою початкову межу: новий тип ще відхиляється до нової міграції.

Перший повний прогін мав 35 тестів: 30 успішних, 4 failures і 1 error;
наступний — 34/35. Причини були в тестових fixtures: `postJson()` без даних
надсилав `[]` замість обов'язкового `{}`, повторна пропозиція використовувала
вичерпаний HTTP fake та однаковий call ID, а новий тест неправильно читав
екрановані рядки CHECK із MySQL metadata. Виправлено JSON-форму запитів,
ізоляцію fake та call IDs і нормалізацію метаданих; assertions збережено та
доповнено. Доменний writer, REST, реєстр і ліміт однієї пропозиції не змінювалися.

Сценарій names → catalog IDs → proposal тепер також підтверджує саме отриману
дію через API й повторює confirm: збережені перші дві пари, запис винятку,
метадані робочої суботи та щотижневі правила; повтор повертає ту саму квитанцію
без нового заняття. Окремі сценарії перевіряють stale/reject, конфлікт номера,
конфлікт часу, чужі посилання, скасовані дати та ізоляцію базової дати.

Усі MySQL-прогони використовували створені runner-ом окремі тимчасові datadir
та перевірені випадкові `student_planner_isolated_*` БД. Робоча БД та живий LLM
не використовувались. Це перевірка передачі даних і детермінованих контрактів;
вибір інструмента реальною моделлю за довільним текстом, Frontend/browser,
розгортання й повний репозиторний набір тестів не перевірялися. Доступність
сценарію зі сторінки Frontend цим не підтверджується.

У цьому завершенні змінені лише:

- `database/migrations/2026_11_13_000003_add_ai_semester_occurrence_create_action.php`;
- `tests/Feature/AiSemesterOccurrenceCreateMigrationTest.php`;
- `tests/Feature/AiSemesterActionMigrationTest.php`;
- `tests/Feature/AiSemesterActionsTest.php`;
- `docs/ai-semester-occurrence-create.md`.
