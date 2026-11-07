# Переносимість AI-провайдерів

Стан реалізації: 7 листопада 2026 року. Основний провайдер — Google Gemini
Developer API, запитана модель — `gemini-2.5-flash`. OpenAI Responses залишається
доступним. REST API, незалежний Vue Frontend, детерміновані Services, Policies,
27 Tools та підтвердження дій не змінені. Нових маршрутів і міграцій немає.

## Вибір моделі та актуальне обмеження доступності

Gemini 2.5 Flash вибрано відповідно до вимоги проєкту: модель підтримує текст,
Function Calling, паралельні виклики та thinking для багатокрокової оркестрації.
Це не гарантія доступності конкретному акаунту або безкоштовності запитів.

**Під час перевірки офіційної документації виявлено зміну початкової вимоги:**
Google уже позначає 2.5 Flash як deprecated, обмежує API-доступ наявними
користувачами до 1 грудня 2026 року та описує автоматичне перенаправлення запитів
після цієї дати на іншу модель з іншою тарифікацією. Див.
[сторінку Gemini 2.5 Flash](https://ai.google.dev/gemini-api/docs/models/gemini-2.5-flash).
Це зовнішня зміна умов, а не підтверджений live-доступ цього застосунку.

Backend зберігає запитану модель, але **з 2026-12-01 00:00:00 UTC відмовляє
для `gemini-2.5-flash` до мережевого запиту** (`ai_unavailable`). Також відхиляє
відповідь, якщо наданий `modelVersion` відрізняється від налаштованої моделі.
Цей захист не може визначити неповідомлені зміни всередині інфраструктури Google.
Потрібні явний вибір сумісної новішої Flash-моделі оператором, перевірка її API
та умов акаунта. Жодної автоматичної заміни моделі, переходу на платний тариф
або fallback на OpenAI немає.

Безкоштовний рівень залежить від eligibility акаунта/проєкту, регіону, квот,
актуальних [тарифів](https://ai.google.dev/gemini-api/docs/pricing) і
[лімітів Google](https://ai.google.dev/gemini-api/docs/rate-limits).
HTTP 429 може означати як rate limit, так і вичерпану квоту. Система не обіцяє
постійну безкоштовність і не змінює billing. Live inference для цього milestone
**NOT RUN**; жодних платних запитів не виконано.

## Конфігурація та Service Container

```dotenv
AI_PROVIDER=gemini
GEMINI_MODEL=gemini-2.5-flash
GEMINI_API_KEY=

OPENAI_MODEL=gpt-5.4-mini
OPENAI_API_KEY=
```

`AppServiceProvider` явно прив'язує `LlmProvider` до
`GeminiGenerateContentProvider` або `OpenAiResponsesProvider` через `config('ai.provider')`.
Невідоме значення дає `ai_unavailable`; автоматичного вибору альтернативи немає.
Змінні читаються лише в `config/ai.php`, тому підтримується `config:cache`.
Для OpenAI збережено існуючі ключі `ai.model` і `ai.openai_key`; Gemini використовує
окремі `ai.gemini_model` і `ai.gemini_key`.

Повернення до OpenAI:

```dotenv
AI_PROVIDER=openai
OPENAI_MODEL=gpt-5.4-mini
OPENAI_API_KEY=<server-side secret>
```

Заміна Gemini-моделі потребує тільки зміни `GEMINI_MODEL` на перевірений сумісний
ідентифікатор `gemini-...`. URL, query string, `/`, довільний origin або шлях
у назві моделі заборонені. Нова модель повинна підтримувати використаний
`generateContent`, JSON Schema declarations, `thinkingConfig.includeThoughts`
та Function Calling. Несумісну модель Backend не намагається автоматично виправити.
Після зміни середовища виконайте `php artisan config:clear` локально або
перебудуйте `php artisan config:cache` у процесі розгортання. Не запускайте
live inference лише для перевірки конфігурації.

Ключі задаються в незакоміченому `.env` або secret manager сервера. Їх не можна
передавати через `VITE_*`, frontend, REST-параметри, скриншоти чи логи. Для Gemini
використовується `x-goog-api-key`, без ключа в URL. Наявність OpenAI-ключа не
компенсує відсутнього Gemini-ключа. Порожній/некоректний ключ дає
`ai_unavailable`; 401/403/404 також не спричиняють fallback.

## Незалежна оркестрація та Function Calling

Інтерфейс `LlmProvider::respond(instructions, history, definitions)` не змінено.
Кожен адаптер повертає однакові `text: ?string`, `calls: list<{id,name,arguments}>`,
`state: array`. `AgentOrchestrator` не знає активного провайдера. Весь розрахунок
розкладів, слотів, планів і статистики залишається в Laravel Services.

Gemini використовує офіційний
[`POST /v1beta/models/{model}:generateContent`](https://ai.google.dev/api/generate-content)
на фіксованому `https://generativelanguage.googleapis.com`.
Запит містить `systemInstruction`, `contents`,
`tools[].functionDeclarations`, `toolConfig.functionCallingConfig.mode=AUTO`,
одного кандидата та наявний `max_output_tokens` як `maxOutputTokens`.
SDK, remote conversation, grounding та вбудовані зовнішні Tools не додаються.

`GeminiToolSchema` використовує офіційне
[`FunctionDeclaration.parametersJsonSchema`](https://ai.google.dev/api/caching#FunctionDeclaration),
а не OpenAI `strict` або обмеженіший OpenAPI `parameters`.
Він зберігає назви, описи, required, optional, nullable type unions, числові/string
enum, вкладені properties/items, additionalProperties=false та наявні обмеження.
Майбутні невідомі schema keywords спричиняють безпечну відмову до запиту,
а не мовчазне вилучення. Схема моделі не замінює строгі перевірки чинних Tools.

Відсутнє поле залишається відсутнім; явно заданий `null` залишається `null`.
Адаптер не очищує optional-null за правилом OpenAI strict schemas, не перетворює
числові рядки на ID й не виправляє бізнес-аргументи. Це зберігає очищення полів
Task, Subtask, Reminder та Lesson. Невідоме синтаксично допустиме ім'я проходить
чинний allowlist і повертає `unknown_tool`; некоректне ім'я/об'єкт аргументів
відхиляється до виконання. Усі **9 reads + 18 proposals** доступні.

## Історія, thinking та continuation

Послідовність: user → model parts/functionCall → наявні Laravel Tools → user
parts/functionResponse → наступний model output. Паралельні результати
збираються в один content у порядку початкових викликів і зіставляються за
внутрішніми ID. Якщо Google надав `functionCall.id`, він повертається в
`functionResponse.id`; якщо ні — генерується випадковий внутрішній ID, який не
вставляється у підписаний оригінал. Це не створює жодного академічного ID.
Дублікати ID/ідентичних викликів у batch, сирітські, повторні або відсутні
результати та невідповідність state/calls спричиняють відмову.

`includeThoughts=false`: raw reasoning і thought summaries не запитуються.
Неочікувані thought parts відхиляються. Дозволені лише текст і functionCall,
із необов'язковим bounded base64 `thoughtSignature`.
[Правила Google щодо thinking/signatures](https://ai.google.dev/gemini-api/docs/generate-content/thinking)
вимагають зберігати межі й порядок підписаних parts. Адаптер не об'єднує їх,
не створює підписів і не відновлює підписаний response із видимого тексту.

Приватний `state` містить тільки provider, model, перевірений model content як
JSON-рядок та відповідність внутрішніх call IDs. JSON-рядок зберігає порожні
об'єкти й точні parts через Eloquent array casts/MySQL JSON. State і вихідний
HTTP JSON використовують `JSON_PRESERVE_ZERO_FRACTION`, щоб початковий невалідний
аргумент `1.0` не перетворився на integer `1` під час signed replay. Звірка calls
враховує JSON-проєкцію Laravel persistence; виклики не виконуються повторно. State повторно
перевіряється перед replay; ключі нормалізованих calls порівнюються без залежності
від MySQL-порядку, зі збереженням типів скалярів. Сирі HTTP-відповіді, usage,
safety diagnostics і reasoning не зберігаються. Підписи не потрапляють до REST
ресурсів або логів. Застосовуються існуючі межі контексту/історії.

Для зміни провайдера в обох напрямках або зміни Gemini-моделі `ProviderHistory`
виключає непридатний opaque state і переводить лише збережені нормалізовані calls,
результати та видимий текст у явно позначені історичні дані. Це не function calls
для повторного виконання. Старі OpenAI reasoning items не передаються в Gemini;
Gemini signatures не передаються OpenAI або іншій моделі. Продовження потребує
нових reads/уточнення, якщо даних бракує. Історичні записи не змінюються.
Серверні outcomes та action-state projection зберігають чинний статус пропозицій.

## Помилки, межі та безпека

Успіх вимагає одного завершеного (`STOP`) кандидата з валідним model content.
Порожні/неоднозначні candidates, malformed JSON, safety blocks, інші finish
reasons, truncation, непідтримані parts, невалідні arguments та verbatim echo
transport key не створюють успішної відповіді. HTTP errors не стають tool results.
Публічні коди й HTTP-статуси залишаються чинними: `ai_unavailable` (503),
`provider_error`/`invalid_provider_response` (502), `execution_limit_reached` (422).

Документований [Google HTTP 400 `API_KEY_INVALID`](https://ai.google.dev/gemini-api/docs/generate-content/api-errors)
також класифікується як `ai_unavailable` за структурованим ErrorInfo, без аналізу
довільного message. Фіксовані діагностичні категорії розрізняють 401 credentials, 403 access,
404 model inaccessible та 429 quota/rate limit без response body або ключа.
403 не дозволяє достовірно відрізнити всі обмеження моделі від account permissions.
Timeout/connection failures повертають безпечну помилку, без automatic retry.
Redirects і автоматичне розпакування body вимкнені. Header/progress callbacks
обмежують завантаження до 1 MiB; додатково перевіряється готовий body. Arguments
обмежені 16 KiB, parts — 64, текст — 16000 символів, opaque signature — 64 KiB,
увесь state/request — чинним context budget. Діють наявні request/turn budgets.
Мережеві запити всередині DB transaction заборонені.

Пропозиція ніколи не виконує mutation. Незмінний оркестратор дозволяє лише одну
mutation-спробу за turn; наявні confirmation/rejection, owner scope, stale checks,
lease, rate limit, idempotency та receipts залишаються авторитетними. Provider
failure не залишає confirmable action або частково успішний transcript.

## Перевірки без зовнішніх запитів

- `GeminiGenerateContentProviderTest`: container/config, auth/origin/model,
  27 schemas, null/omission, parallel/sequential calls, opaque signatures,
  persistence serialization, foreign history, malformed/safety/HTTP/network
  failures, secret redaction, budgets і заборона перенаправлення після cutoff.
- `OpenAiResponsesProviderTest`: попередня Responses-сумісність, явно OpenAI.
- `LlmProviderContractTest`: спільні normalized contract, усі 9 reads через реальні
  owned Services, незмінна REST-форма/idempotency та зміна провайдера в обох
  напрямках без переписування історії.
- `AiEnvironmentConfigurationTest`: default Gemini, незалежні models,
  `config:cache`, fail-closed numeric budgets і закритий live smoke.
- Чинні `AiTaskActionsTest`, `AiSubtaskReminderActionsTest`,
  `AiPlanningActionsTest`, `AiLessonActionsTest` виконуються двічі з native HTTP
  fixtures: стандартно OpenAI, додатково `AI_TEST_PROVIDER=gemini`. Ті самі
  assertions перевіряють усі 18 proposals, підтвердження/rejection/expiry,
  stale-state, owner isolation, receipts, відсутність writes при failures.
  Матриця використовує явно налаштоване вигадане `gemini-offline-contract-fixture`
  лише в HTTP fakes, щоб clock-sensitive DB-тести не залежали від retirement
  реальної моделі. Окремі provider tests перевіряють саме `gemini-2.5-flash`.
- Повний Backend suite зберігає concurrency, migrations, lifecycle й frontend
  regression coverage. CI додатково запускає Gemini action matrix.

```sh
# Лише окрема дозволена disposable MySQL testing database з наявними guards.
vendor/bin/phpunit tests/Feature/GeminiGenerateContentProviderTest.php tests/Feature/OpenAiResponsesProviderTest.php tests/Feature/LlmProviderContractTest.php tests/Unit/AiEnvironmentConfigurationTest.php
AI_TEST_PROVIDER=gemini vendor/bin/phpunit tests/Feature/AiTaskActionsTest.php tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/AiPlanningActionsTest.php tests/Feature/AiLessonActionsTest.php
vendor/bin/pint --test
vendor/bin/phpunit
```

Звичайний suite використовує тільки HTTP fakes. Наявний OpenAI live smoke винесено
за межі стандартних suites і захищено двома opt-in flags. Gemini live smoke не
додавався. Фактичний доступ нового API key, якість вибору Tools реальною моделлю,
вільні квоти й успішна live inference залишаються окремими неперевіреними gates;
їх не можна вважати доведеними deterministic tests.
