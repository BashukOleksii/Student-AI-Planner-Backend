# Українські серверні повідомлення AI-чату

База зміни: `fb52dc6`, локальний Backend, `main`, початкове робоче дерево чисте. Frontend не змінювався. Міграцій, змін схеми/робочої БД, ручного REST, алгоритмів і правил підтвердження немає.

## Джерела тексту

`AgentOrchestrator` раніше замінював текст моделі після спроби підготувати пропозицію на англійський підсумок. Тепер для всіх **32 чинних типів** із реєстрів код формує:

- пропозиція є: **«Пропозицію підготовлено до підтвердження. Зміни ще не виконано.»**;
- пропозиції немає: **«Пропозицію не створено. Зміни не виконано.»**.

Відсутність пропозиції не перекласифіковує результат інструмента: наприклад, `ok=true` та `state=no_actionable_plan` залишаються такими, як раніше. Пропозиція не означає виконання.

`TaskActionConfirmationService` формує українські записи історії для всіх доменів:

- `executed`: «Підтверджені зміни виконано.»;
- `rejected`: «Пропозицію відхилено. Зміни не виконано.»;
- `expired`: «Строк пропозиції минув. Зміни не виконано.»;
- невдала спроба підтвердження планування: «Не вдалося підтвердити зміни плану. Зміни не виконано.». Машинна подія `failed` не є новим кінцевим статусом дії.

Також перекладено нові публічні описи `preview.effects` для завдань, підзавдань, нагадувань і планування. Семестрові, Lesson та availability effects лишаються структурованими прапорцями. Історичні previews/receipts не переписуються.

`AiAgentException` повертає українські фіксовані повідомлення зі старими `errorCode` та HTTP-статусами. Обробка винятків у `bootstrap/app.php` локалізує лише `/api/ai/*`: валідацію, автентифікацію, 403/404/405/419/429 та безпечну загальну 500-помилку. Ключі `errors`, коди, статуси й заголовки збережено. Значення полів, назви предметів, імена й текст студента не перекладаються.

Описи інструментів, їхні схеми, машинні `type`, `state`, `status`, `code`, `error_code`, `error_reason`, а також внутрішні tool-error messages і синтетична історія для провайдера не є текстом для студента й не перейменовуються. POST не експортує внутрішній `error.message`, а GET приховує сирий tool content, як і раніше.

## Контракт Frontend: необов'язковий `server_message`

Для записів рішень чинного `action_event.{action_id,status,receipt,error_code}` достатньо: клієнт відображає структурований стан і не потребує довільного `content` цієї події. Його контракт не змінено.

Для підсумку підготовки чинного зв'язку недостатньо: у POST є `assistant_message_id` та `action.id`, але GET не позначає фінальне повідомлення як серверне; для невдалої пропозиції `action` узагалі немає. Тому додано **одне необов'язкове поле**:

```json
{
  "server_message": {
    "kind": "proposal_ready",
    "action_id": "10000000-0000-4000-8000-000000000001"
  }
}
```

Або, без створеної дії:

```json
{"server_message":{"kind":"proposal_not_created"}}
```

Де читати:

- після POST повідомлення: `data.server_message` описує саме `data.assistant_message_id`;
- після GET історії: `data[j].server_message` описує саме `data[j].id`;
- повтор POST із тим самим Idempotency-Key: та сама ознака та ті самі ідентифікатори, без нового виклику провайдера чи запису.

Метадані створює тільки оркестратор у гілці серверної заміни підсумку. Вони зберігаються в уже наявних JSON-полях повідомлення та результату ходу; нових таблиць/колонок немає. `AiServerMessage` публікує лише дозволені `kind` і валідний UUID. Невідомі/неповні метадані не стають ознакою для приховування тексту. Для звичайної відповіді ознака **відсутня**, навіть коли її текст дослівно збігається з серверним шаблоном.

Правила клієнта:

1. `proposal_ready` — можна не показувати дубльований `content`, **лише коли** для `action_id` завантажено відповідну картку в цій самій розмові. Якщо картка недоступна, залишити український підсумок. Не прив'язувати картку за назвою інструмента, текстом чи сусідством.
2. Ознака описує походження повідомлення, **не поточний статус дії**. Після confirm/reject/expiry вона лишається в історичному підсумку. Актуальні `status`, `confirmation_required`, receipt та `action_event` визначають стан і доступність кнопок.
3. `proposal_not_created` — не шукати й не вигадувати картку. Показати один підсумок або одну структуровану причину відповідного ходу з чинних `tools[].{call_id,name,ok,error_code,error_reason}` / `tool_result`. Зв'язувати за ID повідомлень і викликів у межах ходу, не переносити причину до наступного запиту.
4. За відсутньої або невідомої ознаки не приховувати змістовний текст за фактом наявності tool calls, схожістю фрази або латиницею.

Зміна сумісна зі старими клієнтами, які ігнорують додаткове поле. Frontend у цьому етапі не редагувався.

## Старі повідомлення

Старі розмови, результати повтору й квитанції залишаються побайтно незмінними в БД. Вони можуть не мати `server_message`; ознаку не вигадано з тексту на читанні. Відомі шаблони попередньої реалізації:

| Джерело | Старий точний текст / обмежений набір шаблонів |
| --- | --- |
| Task, підготовлено | `Task action proposed. Confirmation is required; no task has changed.` |
| Task, не підготовлено | `No task action was prepared. No task has changed.` |
| Інші домени, підготовлено | `{Domain} action proposed. Confirmation is required; no business data has changed.` |
| Інші домени, не підготовлено | `No {domain} action was prepared. No business data has changed.` |
| Task, виконано | `Task action executed.` |
| Task, відхилено | `Task action rejected. No task was changed.` |
| Task, прострочено | `Task action expired. No task was changed.` |
| Інші рішення | `{Domain} action {status}.` та для невиконаних ` No business data was changed.` |
| Невдала спроба планування | `Planning action confirmation failed. No business data was changed.` |

`Domain` — тільки `Subtask`, `Reminder`, `Planning`, `Lesson`, `Availability`, `Semester`; `domain` — відповідні нижні регістри; `status` — `executed`, `rejected`, `expired`. Це каталог старих серверних шаблонів, **не класифікатор довільного тексту моделі**.

Для старих рішень `action_event` дає надійну класифікацію та зв'язок без читання фрази. Для старої підготовленої пропозиції клієнт може локалізувати відомий серверний шаблон або не показувати дубль лише за надійного зв'язку, наприклад, зі збереженої відповіді POST/replay, де збігаються `assistant_message_id` та `action.id`. Сам GET старого повідомлення без ознаки чи перевіреного зв'язку не дає дозволу приховувати його. Не використовувати лише збіг тексту, позицію біля картки або ім'я tool. Для невідомої історії зберігати змістовну відповідь. Старі англійські `preview.effects` клієнт може локалізувати чинним точним словником; вміст квитанцій не переписується.

## Вимога до провайдера

До чинної системної інструкції додано:

```text
Always respond and ask clarification questions in Ukrainian, even if the user writes in another language.
Do not automatically translate subject names, personal names or user-provided data. Preserve their original spelling.
```

Нових LLM-викликів для перекладу та фільтра латиниці немає. Код гарантує українську для своїх нових фіксованих студентських повідомлень. Мова вільного тексту — вимога до провайдера, не гарантія виконання реальною моделлю. Fake перевіряє передачу інструкції, збереження даних, відсутність додаткових викликів і структуру, а не поведінку живого LLM.

## Перевірки й точні команди

Усі команди нижче виконано з кореня локального Backend. Нова тестова БД створюється runner-ом на окремому щойно ініціалізованому MySQL. Runner перевіряє `@@datadir`, відсутність прикладних схем, випадковий префікс БД та прибирає сервер/каталог після завершення. Робоча БД, живий LLM та SMTP не використовувались.

```bash
vendor/bin/phpunit tests/Unit/AgentOrchestratorTest.php tests/Unit/AiServerMessageTest.php
```

**PASS: 99 тестів, 1475 assertions.** Включає успішну й невдалу підготовку всіх 32 чинних типів, машинні коди/назви, інструкцію української, відсутність додаткового виклику для перекладу, відсутність фільтра латиниці й захист від хибної класифікації звичайного тексту.

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiChatTest.php \
  tests/Feature/AiChatPersistenceTest.php \
  tests/Feature/AiTaskActionsTest.php \
  tests/Feature/AiSubtaskReminderActionsTest.php \
  tests/Feature/AiPlanningActionsTest.php \
  tests/Feature/AiLessonActionsTest.php \
  tests/Feature/AiAvailabilityActionsTest.php \
  tests/Feature/AiSemesterActionsTest.php \
  tests/Feature/AiReadinessHardeningTest.php \
  tests/Feature/AiSharedRateLimitTest.php
```

**EXIT 1: 437 тестів, 434 успішних, 3 failures, 6057 assertions; errors немає.** Усі `AiChatTest`, `AiChatPersistenceTest` та нові перевірки локалізації пройшли. Перевірено POST → history → idempotent replay, незмінність старих повідомлень/квитанцій, збереження назв, confirm/reject/expiry, машинні статуси/коди, валідацію, auth/CSRF/rate limits та безпечні помилки. Початкові застарілі англійські очікування й неточності нових fixtures виправлено.

Три залишкові кейси — `AiAvailabilityActionsTest::test_explicit_confirmation_delegates_once_to_existing_service_and_replays_immutable_receipt`, набори #0/#1/#2. Їхній старий snapshot вимагає незмінності `recovery_scan_states`, хоча чинний запис доступності збільшує `requested_version` із 5 до 6. Це не мовна зміна. Бізнес-правила та ці assertions не змінено й не вимкнено.

Для контролю виконано початковий тест та два початкові класи з `fb52dc6` в тимчасовому каталозі (без branch/worktree/clone; решта тестового середовища — поточна копія). У фактичній команді `HEAD` на той момент дорівнював `fb52dc6`; для відтворення тут зафіксовано сам коміт:

```bash
baseline_dir=$(mktemp -d /tmp/ai-language-baseline.XXXXXXXX)
git show fb52dc6:app/Services/AI/AgentOrchestrator.php > "$baseline_dir/AgentOrchestrator.php"
git show fb52dc6:app/Services/AI/Actions/TaskActionConfirmationService.php > "$baseline_dir/TaskActionConfirmationService.php"
git show fb52dc6:tests/Feature/AiAvailabilityActionsTest.php > "$baseline_dir/AiAvailabilityActionsTest.php"
cat > "$baseline_dir/bootstrap.php" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
require __DIR__.'/AgentOrchestrator.php';
require __DIR__.'/TaskActionConfirmationService.php';
PHP
PLANNER_TEST_PORT=13381 scripts/testing/phpunit-disposable-mysql.sh \
  --bootstrap "$baseline_dir/bootstrap.php" \
  "$baseline_dir/AiAvailabilityActionsTest.php" \
  --filter test_explicit_confirmation_delegates_once_to_existing_service_and_replays_immutable_receipt
```

**EXIT 1: ті самі 3/3 збої `recovery_scan_states`, 135 assertions.** Це контроль саме названого шляху, не оголошення повного baseline репозиторію перевіреним.

Після широкого прогону додано й окремо виконано три фокусні перевірки повідомлень availability create/update/delete, що не послаблюють попередні snapshot-перевірки:

```bash
PLANNER_TEST_PORT=13381 scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiAvailabilityActionsTest.php \
  --filter test_ukrainian_availability_summary_and_decision_keep_the_original_machine_contract
```

**PASS: 3/3, 60 assertions.** Перевірено український підсумок/подію, marker, стан до підтвердження, машинний тип/стан, квитанцію та replay без другої мутації. Фінальний файл містить ці три додаткові кейси; повтор широкої команди на ньому включатиме вже 440 тестів.

Додатково виконано `vendor/bin/pint --test` для всіх змінених PHP-файлів та `git diff --check` — успішно. Frontend залишився без змін. Повний репозиторний набір тестів зеленою регресією не оголошується.

## Основні змінені файли

- `app/Services/AI/AgentOrchestrator.php` — українські підсумки й інструкція мови провайдеру.
- `app/Services/AI/AiServerMessage.php` — мала allowlist-проєкція необов'язкової ознаки походження.
- `app/Services/AI/AiConversationService.php`, `app/Http/Resources/AiMessageResource.php` — нові метадані в існуючому JSON та сумісні POST/GET/replay.
- `app/Exceptions/AiAgentException.php`, `bootstrap/app.php` — українські AI-помилки без зміни кодів/статусів і ручного REST.
- `app/Services/AI/Actions/TaskActionConfirmationService.php` — українські нові події рішень/невдалих спроб.
- `app/Services/AI/Actions/TaskActionPresenter.php`, `SubtaskReminderActionService.php`, `PlanningActionService.php` — українські нові публічні effects.
- `tests/Unit/AgentOrchestratorTest.php`, `AiServerMessageTest.php`, `tests/Feature/AiChatTest.php`, `AiChatPersistenceTest.php` та профільні тести дій/помилок — регресії й актуальні мовні очікування.
- `docs/ai-ukrainian-messages.md` — контракт і цей звіт.
