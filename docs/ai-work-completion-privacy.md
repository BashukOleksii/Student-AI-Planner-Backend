# Приватний completion consent для AI

Початковий стан: `fb734a1`, `main`, порожній `git status --short`.
Типи дій: `propose_task_complete` і `propose_subtask_complete`.

## Збереження та підтвердження

Нові пропозиції зберігають `completion_token` тільки в
`AiPendingAction.protected_payload` через чинний `encrypted:array` cast.
Поле вже існує й приховане моделлю. Публічний `preview.completion_review`
зберігає повідомлення, лічильники та всі інші поля огляду, крім токена.
Результат інструмента, нові tool messages і збережений результат ходу також
не містять токена. Підготовка не змінює роботу, сесії чи нагадування.

`WorkCompletionConsent::token()` має два вузькі шляхи:

1. Якщо raw `protected_payload` не null, використовується тільки його токен.
   Помилка розшифрування, відсутній ключ, порожнє/нетекстове значення не
   дозволяють fallback на preview і повертають чинний
   `action_validation_failed` (422).
2. Тільки для SQL-null protected payload дозволене читання старого
   `preview.completion_review.completion_token`. Це сумісність зі збереженими
   пропозиціями до оновлення, а не спосіб відновлення пошкодженого payload.

Немає регенерації токена чи виклику `review()` для отримання нового дозволу
під час підтвердження. Наявний токен передається незміненому Task/Subtask Service;
WorkCompletionService перевіряє погоджений набір під чинними блокуваннями.
Непридатний непорожній токен або змінений набір сесій відхиляється з чинним 409.
У стандартному error overview WorkCompletionService може повернути актуальні
наслідки; AI-контролер видаляє звідти службовий токен перед відправленням.
Цей error overview не використовується для авторизації повторної спроби.

Відсутній токен не передається writer-у як null навіть при нульовому лічильнику:
робота й нагадування залишаються незмінними. Після відмови дія залишається
pending, квитанція відсутня. Успішний повтор повертає ту саму квитанцію через
чинний executed-path, без нового завершення чи скасування.

Ручні REST-огляди та завершення продовжують отримувати/приймати токен за старим
контрактом. WorkCompletionService, бізнес-правила, власність, строк дії,
блокування й ліміт однієї пропозиції не змінювалися. Міграцій та масового
переписування історії немає. Frontend не редагувався.

## Перевірені межі та історичні форми

`CompletionReviewPrivacy` фільтрує структуровані ключі, без заміни підрядків у
довільному тексті. Рядки повідомлень, підписи й opaque reasoning не переписуються.
JSON tool body обробляється як об'єкт із збереженням `{}`/`[]`; якщо змін немає,
зберігаються навіть початкові байти форматування.

| Форма / межа | Поведінка та перевірка |
| --- | --- |
| Новий tool result `data.preview.completion_review` | Токен відділено до першого наступного виклику LLM та до запису повідомлення. Перевірено обидва типи. |
| Старий `AiPendingAction.preview` | Action list/detail, confirm/replay повертають огляд без токена. Raw preview залишається для серверної сумісності; старий актуальний токен підтверджується. |
| Старий `ai_conversation_turns.result.action.preview` | Повтор POST messages з тим самим Idempotency-Key фільтрує відповідь у пам'яті; збережений результат не змінюється. |
| Старий `AiMessage` з role `tool`, JSON `{ok, tool, data: {preview: ...}}` | Фільтрується перед відтворенням історії та кожним викликом провайдера. API messages зберігає чинний безпечний summary без повного tool body. |
| Assistant metadata version 1: calls/state | Структуровані ключі проходять той самий фільтр. Native function calls та signed state без змін зберігають чинний формат. |
| Assistant metadata version 2: action_event | Nested structured receipt очищається в AiMessageResource та до перетворення server event у текст контексту. Перевірено v2 envelope із вкладеним review як захисний fixture; це не твердження, що чинний writer додає review до receipt. |
| OpenAI native `function_call_output` | HTTP fake перевіряє відсутність ключа й значення токена, збережений лічильник та відсутність змін raw tool message. |
| Gemini native `functionResponse` | Та сама перевірка; підпис function call зберігається. |
| Portable history після відновлення без native state | Для обох провайдерів перевірено `Historical tool result` з очищеним JSON. |
| AI confirmation 409 | Чинні message/counts залишаються, токен у completion_review відсутній. Ручний REST response не фільтрується. |

Тести перевіряють перший запит після tool call і окремий наступний хід,
незмінність старих збережених рядків, успішне завершення та replay старих pending
для Task/Subtask, stale consent, відсутність, неправильний тип, зіпсований
ciphertext і заборону fallback навіть за наявності валідного legacy-токена.
При відмовах порівнюються повні таблиці tasks/subtasks/study_sessions/reminders.

Довільні plaintext-повідомлення користувача/LLM навмисно не очищаються;
unit-тест перевіряє їх незмінність, навіть коли текст містить слово
`completion_token`. Некоректний JSON, JSON-примітиви/масиви замість чинного
об'єктного tool-result та opaque provider bytes не трактуються як огляд і не
розшифровуються. Token-bearing fixtures у цих нештатних формах не перевірялися;
гарантія структурованого очищення на них не поширюється. Реальний LLM, браузер
та повний репозиторний набір не перевірялися.

## Перевірки

Усі MySQL-прогони використовують чинний disposable runner: окремий новий
datadir, перевірена ідентичність сервера, випадкова `student_planner_isolated_*`
БД. Модель — LlmProvider mock або HTTP fake; робоча БД та живий LLM не використовувалися.

```bash
scripts/testing/phpunit-disposable-mysql.sh tests/Feature/AiWorkCompletionPrivacyTest.php
# PASS: 38 tests, 836 assertions, 6078 ms

vendor/bin/phpunit tests/Unit/CompletionReviewPrivacyTest.php tests/Unit/AgentOrchestratorTest.php
# PASS: 29 tests, 258 assertions, 1220 ms

scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiWorkCompletionPrivacyTest.php tests/Feature/AiTaskActionsTest.php \
  tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/WorkCompletionTest.php \
  tests/Feature/AiChatTest.php tests/Feature/AiChatPersistenceTest.php \
  tests/Feature/AiPlanningActionsTest.php tests/Feature/OpenAiResponsesProviderTest.php \
  tests/Feature/LlmProviderContractTest.php
# PASS: 391 tests, 5350 assertions, 41946 ms
```

Проміжні збої fixtures: перший прогін 28/28 відхилявся до провайдера через
тестове `requests_per_minute=100` понад чинний максимум 60. Після виправлення
28/28 пройшли. Пізніше 4 Gemini-fixtures відхилялися перевіркою credential echo:
надто короткий fake key `offline` був частиною назви fake model. Використано
окремі fake keys; 38/38 пройшли без послаблення перевірок.

Окремий розширений прогін включав усі ці файли без фільтрів (новий privacy-набір
на той момент містив 28 тестів; фінальний набір із 38 тестами пройшов вище):

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/AiWorkCompletionPrivacyTest.php tests/Feature/AiTaskActionsTest.php \
  tests/Feature/AiSubtaskReminderActionsTest.php tests/Feature/WorkCompletionTest.php \
  tests/Feature/AiChatTest.php tests/Feature/AiChatPersistenceTest.php \
  tests/Feature/AiPlanningActionsTest.php tests/Feature/OpenAiResponsesProviderTest.php \
  tests/Feature/GeminiGenerateContentProviderTest.php tests/Feature/LlmProviderContractTest.php
# EXIT 1: 442 tests, 441 passed, 1 failure, 5647 assertions, 45408 ms
```

Залишковий сторонній збій: `GeminiGenerateContentProviderTest::test_request_has_official_authentication_model_budgets_and_all_34_schemas`,
рядок 103: `actual size 33 matches expected size 34`. Тест, провайдер і реєстри
його набору схем не змінювалися в цьому завданні; він має фіксоване очікування
до вилучення replacement із discovery. Виправлення цього тесту не входить до
поточного завдання. Зелений результат усієї регресії не заявляється.

Форматування всіх змінених PHP-файлів і перевірка diff:

```bash
vendor/bin/pint --test \
  app/Services/AI/Actions/WorkCompletionConsent.php \
  app/Services/AI/CompletionReviewPrivacy.php \
  app/Services/AI/Actions/TaskActionProposalService.php \
  app/Services/AI/Actions/SubtaskReminderActionService.php \
  app/Services/AI/Actions/TaskActionConfirmationService.php \
  app/Services/AI/AiConversationService.php app/Services/AI/AgentOrchestrator.php \
  app/Http/Controllers/Api/AiTaskActionController.php \
  app/Http/Resources/AiPendingActionResource.php app/Http/Resources/AiMessageResource.php \
  tests/Feature/AiWorkCompletionPrivacyTest.php tests/Unit/CompletionReviewPrivacyTest.php
# PASS: 12 files
git diff --check
# EXIT 0
```

## Змінені файли

- `app/Services/AI/Actions/WorkCompletionConsent.php`;
- `app/Services/AI/CompletionReviewPrivacy.php`;
- `app/Services/AI/Actions/TaskActionProposalService.php`;
- `app/Services/AI/Actions/SubtaskReminderActionService.php`;
- `app/Services/AI/Actions/TaskActionConfirmationService.php`;
- `app/Services/AI/AiConversationService.php`;
- `app/Services/AI/AgentOrchestrator.php`;
- `app/Http/Controllers/Api/AiTaskActionController.php`;
- `app/Http/Resources/AiPendingActionResource.php`;
- `app/Http/Resources/AiMessageResource.php`;
- `tests/Feature/AiWorkCompletionPrivacyTest.php`;
- `tests/Unit/CompletionReviewPrivacyTest.php`;
- `docs/ai-work-completion-privacy.md`;
- `docs/work-completion.md`.
