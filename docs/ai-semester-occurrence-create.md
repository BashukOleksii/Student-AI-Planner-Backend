# AI: додати одну пару на конкретну дату

Стан роботи: реалізацію й регресійні сценарії підготовлено, але новий тип ще не
можна зберегти в AiPendingAction. CHECK `ai_actions_valid_type`, запроваджений
`2026_11_12_000001_add_ai_semester_schedule_actions.php`, містить закритий список
типів без `propose_semester_occurrence_create`. Потрібне окремо погоджене вузьке
розширення цього CHECK; схема винятків не потребує змін. До відповіді на запит
про цю міграцію наскрізна перевірка нового збереженого типу не завершена.

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

Фактично виконані перевірки (2026-11-13):

```bash
vendor/bin/phpunit tests/Unit/SemesterActionSchemaTest.php \
  tests/Unit/AiCapabilityContractTest.php tests/Unit/AgentOrchestratorTest.php
# PASS: 39 tests, 532 assertions

scripts/testing/phpunit-disposable-mysql.sh tests/Feature/AiSemesterActionsTest.php \
  tests/Feature/SemesterScheduleManagementTest.php \
  tests/Feature/SemesterScheduleManagementPersistenceTest.php \
  tests/Feature/SemesterScheduleManagementImportTest.php \
  --filter 'test_invalid_single_additions|SemesterScheduleManagement'
# PASS: 78 tests, 1363 assertions (before adding the extra ambiguous-local-time case)

scripts/testing/phpunit-disposable-mysql.sh tests/Feature/AiSemesterActionsTest.php \
  --filter 'test_proposal_is_read_only.*#[0-8]$|test_changed_graph|test_rejection_preserves|test_expired_confirmation|test_foreign_rule|test_discovery_reads|test_owned_semester_discovery|test_invalid_single'
# PASS: 25 tests, 374 assertions (including ambiguous local time)

scripts/testing/phpunit-disposable-mysql.sh tests/Feature/AiSemesterActionsTest.php \
  --filter 'test_catalog_uncertainty'
# PASS: 2 tests, 28 assertions

vendor/bin/pint --test app/Services/AI/Actions/SemesterActionRegistry.php \
  app/Services/AI/Actions/SemesterActionService.php app/Services/AI/AgentOrchestrator.php \
  app/Services/Schedule/SemesterScheduleManagementService.php \
  tests/Feature/AiSemesterActionsTest.php tests/Unit/SemesterActionSchemaTest.php
git diff --check
# PASS
```

Усі MySQL-прогони використовували створені runner-ом окремі тимчасові datadir
та перевірені випадкові `student_planner_isolated_*` БД. Робоча БД та живий LLM
не використовувались. Нові сценарії Saturday/base-day confirm/replay/stale,
відхилення нового типу та names → IDs → proposal поки що не запускалися через
зазначене обмеження CHECK. Це обмеження схеми й погодженого обсягу, не помилка
доступності тестового середовища.

Змінені файли: `SemesterActionRegistry.php`, `SemesterActionService.php`,
`AgentOrchestrator.php`, `SemesterScheduleManagementService.php`,
`AiSemesterActionsTest.php`, `SemesterActionSchemaTest.php`,
`docs/unified-academic-calendar.md` і цей документ. Validator, read tools і цикл
підтвердження перевикористано без змін.
