# Причини відмови додавання однієї семестрової пари

Початковий стан: `19864d4`, `main`, чистий `git status --short`.
Розширення стосується тільки `propose_semester_occurrence_create` під час
підготовки. Аргументи інструмента, підтвердження, квитанції та ручний REST не
змінені. Нова схема БД не потрібна.

## Доменний шлях

`SemesterScheduleManagementService::createOccurrence()` читає фактичний день
чинним `EffectiveScheduleCalculator::calculate()`, включно зі snapshot робочої
суботи. Власність, межі семестру, предмет і викладач перевіряються сервером.
`validateOccurrenceAddition()` використовує ті самі `validateLesson()` та
`localInstant()` для форми інтервалу й DST; порівнює номер та розв'язані інтервали
з фактичними заняттями. Межі інтервалів напіввідкриті: кінець одного може
дорівнювати початку іншого.

Якщо одночасно зайнятий номер і є часовий перетин, повертається тільки
`pair_number_taken`, навіть коли перетин стосується іншого заняття. Порожній
збережений виняток — `date_cancelled`; порожній базовий день без винятку
дозволяє додавання. Невалідні аргументи/локальні кінці та чужі посилання не
класифікуються як ці конфлікти.

Типізований `SemesterOccurrenceConflictException` переносить лише одну з трьох
константних причин. `SemesterActionService::prepare()` залишає старий
`error.code=invalid_arguments` та фіксоване загальне повідомлення, додаючи
`error.reason`. Текст винятку й текст моделі не використовуються для класифікації.
Остаточна перевірка повної проєкції семестру в `saveSnapshot()` / `validate()`
залишається обов'язковою. Її інші помилки не отримують вигаданої причини.

Невдала підготовка не створює pending-дії, не змінює розклад, не шукає інший
номер і не замінює наявне заняття. Застарілий стан лишається `action_stale`,
недоступний розклад — `not_found`, інші невалідні аргументи —
`invalid_arguments`, внутрішня помилка — `execution_failed`, без нової причини.
Коди відмови підтвердження й replay квитанції не змінені.
Чинний allowlist кодів у GET history лишається незмінним: для `action_stale`
історія має `error_code=null`, тоді як POST та tool result для провайдера
зберігають `action_stale`. У жодному з цих випадків конфліктної причини немає.

## Контракт Frontend

`POST /api/ai/conversations/{conversation}/messages` повертає HTTP 200 для
обробленої невдалої спроби. Pending `data.action` відсутня. Для потрібного
інструмента читати:

- `data.tools[i].name = propose_semester_occurrence_create`;
- `data.tools[i].ok = false`;
- `data.tools[i].error_code = invalid_arguments`;
- `data.tools[i].error_reason` — необов'язкове дозволене значення;
- `data.tools[i].call_id` — зв'язок із результатом інструмента в історії.

При повторному відкритті розмови:
`GET /api/ai/conversations/{conversation}/messages` повертає пагіновані
повідомлення. Для `data[j].role=tool` ті самі поля доступні в
`data[j].tool_result.{call_id,name,ok,error_code,error_reason}`. `content=null`:
клієнт не читає внутрішній JSON tool message. Повтор POST із тим самим
Idempotency-Key також повертає причину зі збереженого результату ходу.

Для кожної причини компактний результат має таку форму (у POST це елемент
`data.tools`, у GET — значення `data[j].tool_result`):

```json
{"call_id":"append","name":"propose_semester_occurrence_create","ok":false,"error_code":"invalid_arguments","error_reason":"pair_number_taken"}
```

```json
{"call_id":"append","name":"propose_semester_occurrence_create","ok":false,"error_code":"invalid_arguments","error_reason":"time_overlap"}
```

```json
{"call_id":"append","name":"propose_semester_occurrence_create","ok":false,"error_code":"invalid_arguments","error_reason":"date_cancelled"}
```

| `error_reason` | Одна підказка |
| --- | --- |
| `pair_number_taken` | Цей номер пари вже зайнятий |
| `time_overlap` | Обраний час перетинається з іншим заняттям |
| `date_cancelled` | Цей день скасовано; спочатку віднови або явно заміни його розклад |

Якщо причини немає, використовувати чинне загальне відображення помилки.
Старі записи без причини не перекласифіковуються за англійським текстом.
`ToolResultPresenter::errorReason()` пропускає лише ці три значення, лише для
невдалої підготовки цього інструмента з `invalid_arguments`; довільні рядки,
інші коди й інші типи дій не експортуються як причина. SQL, граф розкладу,
exception debug та токени не входять до компактного результату.

LLM отримує структурований tool result із `error.reason` і без внутрішніх
винятків. У наступному ході та сама структура відновлюється зі збереженого tool
message. Загальні відповіді оркестратора не змінені; Frontend не має визначати
причину з тексту моделі. Frontend у цьому завданні не редагувався.

## Перевірка

Тести використовують disposable MySQL runner з новим тимчасовим datadir і
випадковою БД та fake-провайдери. Робоча БД і живий LLM не використовуються.
Fake перевіряє передачу структурованих даних, а не розуміння тексту реальною
моделлю. Повний репозиторний набір і Frontend/browser не перевіряються.

`AiSemesterActionsTest` перевіряє конфлікти на базовій даті та збереженій робочій
суботі, пріоритет номера, скасований/порожній день і суміжні інтервали. Наскрізні
сценарії перевіряють POST, idempotent replay, GET history та наступний хід
провайдера. Для невалідних аргументів, недоступного розкладу, stale-state та
внутрішнього винятку з оманливими словами конфлікту перевіряється безпечний
загальний результат. У кожному випадку порівнюється стан розкладу й кількість
pending-дій. Чинні успішні сценарії перевіряють третю пару, збережені перші дві,
метадані суботи, правила, підтвердження й повтор квитанції.

Фінальні прогони без фільтрів:

```bash
vendor/bin/phpunit tests/Unit/SemesterOccurrenceErrorReasonTest.php \
  tests/Unit/SemesterActionSchemaTest.php tests/Unit/EffectiveScheduleCalculatorTest.php \
  tests/Unit/AgentOrchestratorTest.php
# PASS: 100 tests, 401 assertions, 1261 ms

scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiSemesterActionsTest.php tests/Feature/AiChatTest.php \
  tests/Feature/AiChatPersistenceTest.php tests/Feature/AiChatConcurrencyTest.php \
  tests/Feature/SemesterScheduleManagementTest.php \
  tests/Feature/SemesterScheduleManagementPersistenceTest.php \
  tests/Feature/SemesterScheduleManagementImportTest.php \
  tests/Feature/SemesterScheduleManagementConcurrencyTest.php \
  tests/Feature/SemesterScheduleReadConsistencyTest.php \
  tests/Feature/AcademicCalendarApiTest.php tests/Feature/AiWorkCompletionPrivacyTest.php
# PASS: 212 tests, 3673 assertions, 50656 ms
```

Перший профільний `scripts/testing/phpunit-disposable-mysql.sh
tests/Feature/AiSemesterActionsTest.php` пройшов: 41 тест, 718 assertions,
10493 ms. Після цього додано ще два негативні сценарії — stale-state та
internal failure. У фінальному профілі весь набір запускався без фільтрів.

Початковий розширений прогін був такою ж командою профілю вище, але додатково
включав `tests/Feature/SemesterScheduleReadApiTest.php` після
`SemesterScheduleReadConsistencyTest.php`: EXIT 1, 251 тест, 249 успішних,
2 failures, 4134 assertions, 51026 ms. Один збій був у новому тесті: він очікував
`action_stale` у GET history замість чинного `null`. Виправлено саме fixture
очікування до незміненого allowlist; точні перевірки POST/tool-result та відсутності
конфліктної причини збережено. Фінальний профіль із цим сценарієм пройшов.

Другий збій — відома стороння перевірка
`SemesterScheduleReadApiTest::test_day_occurrence_is_a_stable_virtual_resource_with_optional_metadata`,
рядок 151: exact JSON не очікує чинне поле `change_kind=base`. Набір і чинні
API-очікування ручного розкладу не редагувалися. Повний репозиторний прогін
зеленою регресією не оголошується.

Окремий фінальний повтор підтвердив лише цей залишковий збій:

```bash
scripts/testing/phpunit-disposable-mysql.sh tests/Feature/SemesterScheduleReadApiTest.php
# EXIT 1: 39 tests, 38 passed, 1 failure, 468 assertions, 2516 ms
```

Форматування змінених PHP-файлів:

```bash
vendor/bin/pint --test app/Exceptions/SemesterOccurrenceConflictException.php \
  app/Services/Schedule/EffectiveScheduleCalculator.php \
  app/Services/Schedule/SemesterScheduleManagementService.php \
  app/Services/AI/Actions/SemesterActionService.php \
  app/Services/AI/Tools/ToolResultPresenter.php app/Services/AI/AgentOrchestrator.php \
  app/Services/AI/AiConversationService.php app/Http/Resources/AiMessageResource.php \
  tests/Feature/AiSemesterActionsTest.php tests/Unit/SemesterOccurrenceErrorReasonTest.php
# PASS
git diff --check
# EXIT 0
```

## Змінені файли

- `app/Exceptions/SemesterOccurrenceConflictException.php`;
- `app/Services/Schedule/EffectiveScheduleCalculator.php`;
- `app/Services/Schedule/SemesterScheduleManagementService.php`;
- `app/Services/AI/Actions/SemesterActionService.php`;
- `app/Services/AI/Tools/ToolResultPresenter.php`;
- `app/Services/AI/AgentOrchestrator.php`;
- `app/Services/AI/AiConversationService.php`;
- `app/Http/Resources/AiMessageResource.php`;
- `tests/Feature/AiSemesterActionsTest.php`;
- `tests/Unit/SemesterOccurrenceErrorReasonTest.php`;
- `docs/ai-semester-occurrence-create.md`;
- `docs/ai-semester-occurrence-errors.md`.
