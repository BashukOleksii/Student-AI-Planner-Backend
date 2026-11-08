# Stage 15A — Готовність локального Backend до інтеграції

## Ідентифікація середовища (Environment identity)

Дата аудиту: **8 листопада 2026 року**. Час зафіксованої HTTP/мережевої
перевірки: **10:48 UTC / 12:48 Europe/Kyiv**; повторної перевірки портів і
міграцій: **10:50:07 UTC**; фінальної HTTP/process/SMTP перевірки перед комітом:
**10:55:28 UTC**. Це знімок стану, а не постійний моніторинг.

- Перевірений Backend commit: `f2c635744ad620eba5c2f032edea37e166c92089`.
- Репозиторій: `student-planner-backend`; гілка `main`; початкове дерево чисте.
  Коміт цього звіту йде після зазначеного коміту застосунку.
- ОС: Fedora Linux 44 Workstation, Linux `7.2.8-200.fc44.x86_64`, x86_64.
- CLI: PHP **8.5.11**, Composer **2.10.3**, Laravel **13.33.0**.
- Ефективні CLI-параметри: `APP_ENV=local`, `APP_DEBUG=true`, timezone `UTC`;
  `APP_URL=http://localhost:8000`, `FRONTEND_URL=http://localhost:5173`.
  Конфігурація **не кешована**; Laravel bootstrap і створення Encrypter успішні.
  Значення APP_KEY не виводилось і не змінювалось.
- MySQL: `127.0.0.1:3306`, база **`student_planner`**, сервер **8.4.10**,
  `REPEATABLE-READ`, `utf8mb4 / utf8mb4_unicode_ci`. DB URL і Unix socket не задані.
  Імена користувачів, паролі й бізнес-записи не включені у звіт.
- Фактична доступність із цього середовища: **MySQL доступний; HTTP Backend на
  localhost:8000 недоступний; TCP localhost:5173 доступний; SMTP 127.0.0.1:1025
  недоступний**. На `5173` виявлено listener `[::1]:5173`; це не перевірка Vue,
  його сторінок або працездатності Frontend.

Перевірено локальні `AGENTS.md`, `.env.example`, зазначені config-файли,
`routes/api.php`, `routes/console.php`, Gemini adapter / AgentLimits, Reminder
delivery Service/Command, password delivery Service/Job, чинні
[readiness](../backend-integration-readiness.md),
[provider portability](../ai-provider-portability.md),
[auth](../auth-profile.md), [reminders](../reminders.md),
[password contract](../stage12/password-api-contract.md) та наявний live smoke.
GitHub і Frontend-репозиторій не перевірялися. Історичні offline/CI результати
не використовуються як доказ поточної доступності сервісів.

**Межі безпеки.** `.env` не редагувався; конфігурація читалася через явний
безпечний список. Наявність Gemini key перевірена лише як boolean, без значення,
хешу чи фрагментів. Не створено користувачів, сесій, reset token, бізнес-записів
або jobs. Не виконано login, CSRF-cookie запит, mail send, paid inference,
міграції, seed, reset, truncate, queue worker, dispatch-due чи запуск Scheduler.
Фактично виконано `migrate:status`, `schedule:list` і **лише `--help`** команди
нагадувань. Уже запущений сторонній щодо аудиту Scheduler не зупинявся: його
власні фонові зміни БД не можна приписувати read-only перевіркам аудиту.

## Матриця перевірок (Verification matrix)

`PASS` означає лише зазначений обсяг доказу. `FAIL` — спостережений збій;
`BLOCKED` — перевірка залежить від відсутньої передумови; `NOT RUN` — свідомо
не виконано через межі авторизації/можливі записи або оплату. `C1–C5` нижче —
відтворювані команди; `E15-*` — детальні картки проблем.

| ID | Компонент / очікування | Фактичний результат | Статус | Доказ | Наступна дія |
| --- | --- | --- | --- | --- | --- |
| A01 | PHP і Composer доступні | PHP 8.5.11; Composer 2.10.3 | PASS | `php --version`, `composer --version`, exit 0 | Немає |
| A02 | Laravel завантажується | Bootstrap успішний; Laravel 13.33.0; Encrypter створено | PASS | C2, exit 0 | Немає |
| A03 | Платформа відповідає lock-файлу | `composer check-platform-reqs`: усі вимоги success; pdo_mysql та потрібні XML/GD/ZIP/mbstring/openssl доступні | PASS | C1/C2, exit 0 | `bcmath` не завантажений, але поточний lock його не вимагає; це не blocker |
| A04 | Локальний env/URL узгоджені | local; debug=true; Backend 8000; Frontend 5173; app timezone UTC | PASS | C2, exit 0 | Перевірити саме HTTP runtime після запуску; не переносити debug=true в production |
| A05 | Стан кешування відомий | `configurationIsCached()=false` | PASS | C2 | Зараз `config:clear` не потрібен |
| B01 | З'єднання з правильною MySQL БД | live `SELECT DATABASE()` → student_planner; MySQL 8.4.10 | PASS | C2 / metadata SELECT, exit 0 | Немає |
| B02 | Локальні міграції застосовані | 26 файлів / 26 записів; Ran=26, Pending=0; зайвих записів немає | PASS | `php artisan migrate:status --no-ansi --no-interaction`, exit 0; повторено | Міграції не запускати |
| B03 | Системні таблиці існують | users, sessions, jobs, job_batches, failed_jobs, cache, cache_locks, reminders, notifications, password_reset_tokens, migrations — усі є | PASS | SELECT information_schema.tables, exit 0 | Це не доказ прав на запис або доставки |
| B04 | Таблиці основних модулів існують | tasks, subtasks, study_sessions, ai_conversations, ai_messages, ai_conversation_turns, ai_pending_actions — усі є | PASS | Та сама metadata-перевірка | Бізнес-вміст не читався |
| C01 | Sanctum / cookies відповідають localhost SPA | stateful localhost:5173,localhost:8000; web guard; database session; domain=null; secure=false; HttpOnly=true; SameSite=lax; path=/; lifetime=120 хв | PASS | C2; config/sanctum.php | Використовувати hostname localhost послідовно |
| C02 | CORS дозволяє очікуваний Origin з credentials | origin=http://localhost:5173; supports_credentials=true; paths api/*,sanctum/csrf-cookie | PASS | C2; config/cors.php | Перевірити реальну preflight-відповідь після E15-01 |
| C03 | Health і CSRF маршрути зареєстровані | GET/HEAD up; GET/HEAD sanctum/csrf-cookie з web middleware | PASS | `route:list --path=up` / `--path=sanctum`, exit 0 | CSRF route не викликався |
| C04 | Backend слухає 8000 та `/up` повертає 200 | Обидва localhost:8000 і 127.0.0.1:8000 відхиляють TCP; HTTP відповіді немає | FAIL | C3: curl exit 7; C4: listener відсутній; повторно errno=111 | E15-01 |
| C05 | Анонімний `/api/profile` повертає 401 JSON | З'єднання не встановлено; це не HTTP 401/500 | BLOCKED | C3, curl exit 7 | Усунути E15-01; повторити без Origin/Cookie |
| C06 | Реальний CORS preflight дозволяє localhost:5173 | OPTIONS не досягнув Laravel | BLOCKED | C3, curl exit 7 | E15-01, E15-04 |
| C07 | Реальний browser session/CSRF/login працює | Не запускалося; немає явно дозволеного тестового акаунта | NOT RUN | Лише конфігурація/маршрути; /csrf-cookie може створювати DB session | E15-04 |
| C08 | Порт очікуваного Frontend доступний | TCP localhost:5173 встановлено; listener ::1 | PASS | C4, exit 0 | Vue/браузерна інтеграція не перевірена |
| D01 | Gemini є ефективним провайдером | provider=gemini; DI → GeminiGenerateContentProvider | PASS | C2, без `respond()` | Немає |
| D02 | Очікувана модель і ключ налаштовані | gemini-3.5-flash-lite; збіг із повідомленою моделлю; синтаксис adapter приймає; key_configured=true | PASS | C2; значення ключа не виводилось | Це не доказ entitlement або сумісності відповіді |
| D03 | Усі AI limits валідні | AgentLimits::validate() успішний; lease 240 > turn 180 | PASS | C2, exit 0 | Немає |
| D04 | Поточний live Gemini inference / Tools успішний | Нового запиту не було; успіх повідомлено розробником | NOT RUN | Жодних paid API викликів; live opt-in flags не увімкнені | E15-05 |
| E01 | Queue і cache мають спільну database конфігурацію | queue=database/default, table=jobs; cache=database; усі store connections → mysql; sessions → mysql | PASS | C2; потрібні таблиці існують | Права DML/обробка jobs не перевірялися |
| E02 | Поточний worker цього Backend працює | Видимого queue:work / queue:listen не знайдено у двох спостереженнях | BLOCKED | C4, scanner exit 0; знайдено лише schedule:work | E15-02; зовнішній worker не виключено |
| E03 | Mail transport відомий | Ефективно smtp, host 127.0.0.1, port 1025, scheme=null, timeout у коді 15 с | PASS | C2; config/mail.php | Повідомлене можливе MAIL_MAILER=log тут не підтвердилось |
| E04 | Налаштований SMTP endpoint доступний | 127.0.0.1:1025 → ConnectionRefusedError errno=111; listener відсутній | FAIL | C4; повторено 10:50:07 UTC | E15-03 |
| E05 | Реальний password reset доходить до inbox | Email не запитувався, jobs не оброблялись, reset URL не створювався | NOT RUN | Broker table існує; expiry 60 хв, throttle 60 с | E15-07, залежить від E15-02/03/04 |
| F01 | Команда reminders зареєстрована | reminders:dispatch-due, limit 1–1000, default 100 | PASS | `php artisan reminders:dispatch-due --help --no-ansi`, exit 0 | Обробник команди не викликався |
| F02 | Scheduler містить кожну хвилину dispatch | `* * * * * php artisan reminders:dispatch-due --limit=100` | PASS | schedule:list, exit 0; routes/console.php; повторено | Це лише реєстрація |
| F03 | Локальний scheduler process видимий | PID 61839, schedule:work, cwd цього Backend | PASS | C4, два спостереження | Наявність процесу не доводить успішний запуск кожної задачі |
| F04 | Reminder → in-app notification реально доставлено | Доставку не запускали, приватні reminders/notifications не читали | NOT RUN | ReminderDeliveryService працює синхронно в DB transaction; queue для цього шляху не використовується | E15-06 |

Додатково: `.env.example` містить `GEMINI_MODEL=gemini-2.5-flash` та SMTP port
2525. Це приклади, а не поточна конфігурація: ефективно модель **3.5-flash-lite**
і порт **1025**. Нічого не перевизначалося для наближення до прикладу.

Ефективні AI limits: request_timeout=30, max_tool_rounds=5, max_tool_calls=8,
max_history_messages=20, max_context_bytes=131072, max_tool_result_bytes=65536,
max_output_tokens=2048, max_turn_seconds=180, turn_lease_seconds=240,
requests_per_minute=10. Це локальна валідація конфігурації без звернення до Google.

## Детальні звіти про проблеми (Detailed error reports)

### E15-01 — Критична: Backend HTTP недоступний

- **Відтворення:** C3, GET `/up` через localhost і 127.0.0.1; C4 перевіряє listener.
- **Очікування:** TCP з'єднання й HTTP 200; анонімний API запит — HTTP 401.
- **Спостереження:** `curl: (7) Failed to connect to localhost port 8000`;
  `ConnectionRefusedError:errno=111`; curl exit **7**, scanner exit **0**.
  Вивід `ss` містить лише `[::1]:5173`, без `8000`.
- **Категорія:** `SERVER_OR_NETWORK`.
- **Підтверджено:** у доступному мережевому namespace немає доступного listener
  на очікуваному порту. CLI Laravel і MySQL при цьому працюють.
- **Припущення:** dev server зупинено/не запущено або він працює в іншому
  namespace/на іншій адресі. Конкретну причину зупинки не встановлено.
  Сильний доказ проблеми доступності; немає доказу дефекту Controller/Service.
- **Точне виправлення:** оператор у терміналі цього Backend запускає
  `php artisan serve --host=localhost --port=8000`, залишає процес працювати
  і повторює C3. Якщо процес уже працює поза namespace — забезпечує доступне
  локальне перенаправлення саме на очікуваний localhost:8000.
- **Повторна перевірка:** до операторського виправлення, 10:50:07 UTC — усе ще
  errno=111 для обох адрес. Фінально 10:55:28 UTC health і anonymous-profile
  також дали curl exit 7 / HTTP code 000. Автоматично сервер не запускався.
- **Зміна коду:** **не потрібна за наявними доказами**.

### E15-02 — Висока: обробку database queue не підтверджено

- **Відтворення:** C4, без читання process environment або повних argv.
- **Очікування:** видимий робочий `queue:work`/`queue:listen` потрібної черги
  або окремий доказ оператора про зовнішній worker з правильною конфігурацією.
- **Спостереження:** scanner exit **0**; два рази лише
  `{"pid":61839,"role":"schedule:work","cwd_is_backend":true}`.
  Database queue налаштована, таблиці jobs/job_batches/failed_jobs існують.
- **Категорія:** `QUEUE_OR_SCHEDULER`.
- **Підтверджено:** локальний worker не спостерігається. Відсутність зовнішнього
  процесу/контейнера/вузла цим сканером не доведена. Гіпотеза незапущеного worker
  має помірну підтримку; працездатність черги залишається **BLOCKED**.
- **Точне виправлення:** оператор підтверджує наявний worker або організовує його
  запуск на database/default після перевірки цільової БД й дозволу на обробку
  наявних jobs. Команда запуску: `php artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=60`.
  **Це вже обробка jobs, а не
  read-only діагностика; у Stage 15A вона не виконувалась і не є командою для
  безумовного повторення.** Запускати лише після відповідного дозволу оператора.
- **Повторна перевірка:** повторні process snapshots, включно з фінальним
  10:55:28 UTC, також не виявили worker; виправлення не застосовувалося.
  Після схваленого запуску повторити C4.
- **Зміна коду:** не обґрунтована.

### E15-03 — Висока: SMTP endpoint відхиляє з'єднання

- **Відтворення:** C4, TCP до `127.0.0.1:1025`, без SMTP AUTH/MAIL/RCPT/DATA.
- **Очікування:** listener на налаштованому endpoint; це необхідна, але ще не
  достатня умова реальної доставки.
- **Спостереження:** `ConnectionRefusedError:errno=111`, scanner exit **0**;
  listener `1025` відсутній. Mailer **smtp**, не log; SMTP URL не заданий.
- **Категорія:** `MAIL_DELIVERY`.
- **Підтверджено:** налаштований локальний SMTP недоступний у момент обох перевірок.
  Імовірно, локальний SMTP-сервіс не запущений або налаштований інший порт;
  конкретний сервіс не встановлено. Немає доказу проблеми credentials/TLS,
  оскільки до handshake справа не дійшла.
- **Точне виправлення:** оператор запускає схвалений SMTP-сервіс на
  `127.0.0.1:1025` або свідомо узгоджує mail-конфігурацію з іншим чинним SMTP.
  `.env` змінює тільки оператор; секрети залишаються локальними. Після зміни
  кешованої конфігурації потрібне її операторське оновлення; зараз кешу немає.
  Для доставки reset-листа додатково потрібен worker з E15-02.
- **Повторна перевірка:** 10:50:07 і 10:55:28 UTC — той самий errno=111.
  Виправлення не застосовувалося; після виправлення повторити TCP-частину C4.
- **Зміна коду:** не потрібна за цим доказом.

### E15-04 — Висока для інтеграції: HTTP auth/CORS/CSRF не підтверджені

- **Відтворення:** C3, анонімний GET без Cookie/Origin та безпечний OPTIONS.
- **Очікування:** 401 JSON для `/api/profile`; для preflight — 204 з
  `Access-Control-Allow-Origin: http://localhost:5173` і credentials=true.
- **Спостереження:** обидва curl exit **7**; жодної HTTP відповіді. Це
  **SERVER_OR_NETWORK** залежність E15-01, а не спостережений auth/CORS дефект.
  Категорія непідтвердженої наступної перевірки — `AUTH_CORS_CSRF`.
- **Підтверджено:** статична конфігурація відповідає очікуваному localhost SPA;
  реальний browser login/CSRF **NOT RUN**. Виділеного дозволеного тестового
  акаунта не надано. Наявні локальні акаунти не використовувалися.
- **Точна наступна дія:** усунути E15-01, повторити C3. Далі отримати явний дозвіл
  на конкретний тестовий акаунт і session/CSRF/login перевірки у Stage 15B.
  Не підміняти localhost на 127.0.0.1 в Origin: цей інший Origin не налаштований.
  Не копіювати cookies/CSRF значення до звіту.
- **Повторна перевірка:** auth/preflight заблоковані мережею; browser workflow
  не повторювався. Немає доказу потреби змінювати Backend-код.

### E15-05 — Середня: поточний live Gemini не перевірено

- **Відтворення без оплати:** C2 для конфігурації та C5 для історичних категорій.
  Обидві перевірки exit **0**. Live inference: **не запускався**, exit N/A.
- **Очікування для live acceptance:** дозволений поточний запит до налаштованої
  моделі та структурно валідна відповідь/Tools. Цей критерій не виконувався.
- **Категорія:** `GEMINI_PROVIDER`; причина відсутності нових доказів — межа
  авторизації на оплату/створення AI історії, а не встановлений збій провайдера.
- **Підтверджено незалежно:** Gemini provider, модель gemini-3.5-flash-lite,
  key_configured=true, валідні limits і синтаксис model ID. Ключ не перевірявся
  мережевим запитом. Актуальний доступ акаунта, quota, billing, modelVersion
  та підтримка використаних полів generateContent залишаються невідомими.
- **Повідомлено розробником окремо:** Gemini з цією моделлю вже успішно працював.
  Без дати/структурованого live evidence це не новий незалежний PASS аудиту.
- **Очищена історія логів:**

  | Категорія | HTTP | Кількість | Перший / останній запис у часових мітках логу |
  | --- | ---: | ---: | --- |
  | http_error | 500 | 20 | 2026-11-07 08:26:22 / 08:41:38 |
  | model_inaccessible | 404 | 2 | 2026-11-08 09:54:26 / 10:25:19 |
  | model_version_mismatch | — | 6 | 2026-11-07 08:26:14 / 08:44:40 |

  Виведено тільки фіксовані категорії, HTTP status, timestamps і counts.
  Часовий пояс міток не приписується без додаткового доказу. У цих очищених
  записах немає ідентифікатора моделі/запиту: їх не можна достовірно пов'язати
  з нинішньою моделлю, реальним запитом або offline fixture. Це історичні відмови,
  **не доказ поточного збою чи поточного успіху**.
- **Точна наступна дія:** надати очищений результат вже виконаної успішної
  перевірки — дата, provider/model, HTTP status і назви викликаних Tools, без
  ключа, prompt/response, приватних ID і токенів. Нову inference-перевірку
  виконувати лише після окремого явного дозволу на оплату й тестовий акаунт.
  Не змінювати модель і не послаблювати перевірку modelVersion за цими логами.
- **Повторна перевірка:** конфігурація підтверджена; live не повторювався.
  Зміна коду не обґрунтована.

### E15-06 — Середня: доставка reminders не підтверджена

- **Відтворення без записів:** `php artisan schedule:list --no-ansi
  --no-interaction` і C4. Exit **0**; розклад і PID Scheduler підтверджені.
- **Очікування для delivery acceptance:** коректна обробка дозволеного due
  reminder і committed in-app receipt. Ні статуси reminders, ні payload
  notifications під час цього аудиту не читалися й не змінювалися.
- **Категорія:** `QUEUE_OR_SCHEDULER`. Доставку позначено **NOT RUN** через
  заборону мутацій, а не через виявлений дефект. Запущений Scheduler може сам
  виконувати свої задачі незалежно від аудиту; успішність кожного циклу невідома.
- **Точна наступна дія:** оператор може надати вже наявний очищений вивід
  Scheduler — тільки JSON counts `selected/sent/cancelled/skipped/failed` із
  часовою міткою. Не повторювати dispatch лише заради такого доказу.
  Для керованого acceptance у Stage 15B потрібен окремо дозволений reminder
  тестового акаунта. Не запускати вдруге schedule:work поруч із поточним процесом.
- **Повторна перевірка:** розклад і процес підтверджені повторно; actual delivery
  не запускалась. Код змінювати підстав немає.

### E15-07 — Середня: end-to-end password recovery не перевірено

- **Відтворення:** C2 перевірив Broker/table/transport; exit **0**. Запит
  forgot/reset-password не виконувався; exit N/A.
- **Очікування:** узгоджені database sessions/Broker, прийнятий queue job,
  реально доставлений лист і дозволена перевірка reset-процесу.
- **Категорія:** `MAIL_DELIVERY`, залежності `QUEUE_OR_SCHEDULER` і
  `AUTH_CORS_CSRF`.
- **Підтверджено:** table password_reset_tokens існує; Broker users,
  expiry=60 хв, throttle=60 с; sessions і Broker використовують default MySQL.
  SMTP недоступний; worker не підтверджений. Безпечний маркер
  `password.delivery_failed` у просканованому логу не знайдений (count=0);
  це **не доказ доставки**, особливо без підтвердження спроби worker.
- **Припущення:** повна доставка зараз не пройде через SMTP; інші кроки не
  перевірялись. HTTP 202 означає прийняття job, а не доставку.
- **Точна наступна дія:** усунути E15-01/02/03, погодити тестову адресу/акаунт
  і окремий mail/reset acceptance. Підтверджувати факт отримання без пересилання
  reset URL або token. Якщо оператор свідомо обере MAIL_MAILER=log, це лише
  локальний log transport: він не доводить email delivery і може записувати
  reset links. У Stage 15A надсилання та створення таких посилань заборонене.
- **Повторна перевірка:** повний workflow не виконувався; код не змінено.

## Стан зовнішніх інтеграцій (External integration status)

- **Gemini:** configuration PASS; повідомлений розробником live успіх
  збережено окремо; незалежний live evidence **NOT RUN**. `respond()` не викликався.
  Наявний `tests/Live/OpenAiLiveSmokeTest.php` обмежений OpenAI, disposable test
  database та двома явними opt-in flags; він не є Gemini acceptance. Його не
  запускали, прапорці не вмикали й захисти не обходили.
- **Sanctum / браузер:** конфігурація PASS, анонімний HTTP/CORS runtime
  **BLOCKED** недоступним Backend, browser auth/CSRF **NOT RUN**. Порт 5173
  не доводить успішну інтеграцію двох застосунків.
- **MySQL:** read-only connection, схема й migration status **PASS**.
  Права DML, реальні транзакції з бізнес-записами та навантаження не перевірялися.
- **Reminder Scheduler:** команда/кожна хвилина/поточний process **PASS**;
  фактична доставка **NOT RUN**. Цей in-app шлях синхронно пише notifications
  у транзакції; відсутній queue worker сам по собі не доводить поломку reminders.
- **Database Queue:** schema/config **PASS**, реальний worker і processing
  **не підтверджені**. Жодного job не оброблено командою аудиту.
- **SMTP / reset:** ефективний transport smtp, endpoint **FAIL**;
  password delivery **NOT RUN**. Відсутність листів не досліджувалася читанням
  приватних inbox або token-таблиці.

## Безпечні команди для повторної перевірки

Запускати з поточного кореня Backend. Це read-only перевірки; не замінювати їх
на `migrate`, `queue:work`, `reminders:dispatch-due`, `schedule:run` чи PHPUnit.
Не використовувати `config:show ai`, повний env/config dump, `tail` необроблених
логів або `ps ... args`: вони можуть розкрити секрети чи приватний вміст.

### C1 — Runtime, схема, реєстрація

```sh
php --version
composer --version
composer check-platform-reqs
php artisan --version
php artisan migrate:status --no-ansi --no-interaction
php artisan schedule:list --no-ansi --no-interaction
php artisan reminders:dispatch-due --help --no-ansi
php artisan route:list --path=up --json
php artisan route:list --path=sanctum --json
```

У фактичному аудиті версію Laravel також отримано з `$app->version()` під час
bootstrap. Дані migration repository читалися тільки як службові назви міграцій;
бізнес-таблиці перевірялися через information_schema, без читання записів.

### C2 — Очищена ефективна конфігурація й read-only MySQL

```sh
php -d display_errors=0 -d log_errors=0 <<'PHP'
<?php
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $app->make(App\Services\AI\AgentLimits::class)->validate();
    $db = Illuminate\Support\Facades\DB::connection();
    $out = [
        'laravel' => $app->version(), 'env' => $app->environment(),
        'debug' => (bool) config('app.debug'),
        'configuration_cached' => $app->configurationIsCached(),
        'ai_provider' => config('ai.provider'), 'gemini_model' => config('ai.gemini_model'),
        'gemini_key_configured' => is_string(config('ai.gemini_key')) && config('ai.gemini_key') !== '',
        'ai_limits_valid' => true,
        'session_driver' => config('session.driver'),
        'sanctum_stateful' => config('sanctum.stateful'),
        'cors_origins' => config('cors.allowed_origins'),
        'cors_credentials' => config('cors.supports_credentials'),
        'queue' => config('queue.default'), 'cache' => config('cache.default'),
        'mailer' => config('mail.default'),
        'smtp_host' => config('mail.mailers.smtp.host'), 'smtp_port' => config('mail.mailers.smtp.port'),
        'database' => (array) $db->selectOne('SELECT DATABASE() AS database_name, VERSION() AS version, @@transaction_isolation AS isolation_level'),
    ];
    $tables = array_map(fn ($row) => $row->name, $db->select(
        'SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema = DATABASE()'));
    foreach (['users','sessions','jobs','job_batches','failed_jobs','cache','cache_locks',
        'reminders','notifications','password_reset_tokens','migrations'] as $table) {
        $out['required_tables'][$table] = in_array($table, $tables, true);
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    // Не друкувати exception message/trace: вони можуть містити конфігурацію.
    echo json_encode(['result' => 'FAIL', 'error_class' => get_class($e)]), PHP_EOL;
    exit(1);
}
PHP
```

### C3 — Реальний HTTP без cookies, credentials або приватних response bodies

```sh
python3 - <<'PY'
import subprocess, json
checks = [
    ('health', 'http://localhost:8000/up', []),
    ('health-ipv4', 'http://127.0.0.1:8000/up', []),
    ('anonymous-profile', 'http://localhost:8000/api/profile', ['-H', 'Accept: application/json']),
    ('cors', 'http://localhost:8000/api/auth/login', [
        '-X', 'OPTIONS', '-H', 'Origin: http://localhost:5173',
        '-H', 'Access-Control-Request-Method: POST',
        '-H', 'Access-Control-Request-Headers: content-type,x-xsrf-token']),
]
for name, url, extra in checks:
    result = subprocess.run(['curl', '--noproxy', '*', '--connect-timeout', '3',
        '--max-time', '5', '-sS', '-o', '/dev/null', '-D', '-', *extra, url],
        capture_output=True, text=True)
    headers = [line for line in result.stdout.splitlines() if line.startswith('HTTP/')
        or line.lower().startswith(('access-control-allow-origin:',
            'access-control-allow-credentials:', 'access-control-allow-methods:',
            'access-control-allow-headers:', 'vary:'))]
    print(json.dumps({'check': name, 'exit': result.returncode,
        'safe_headers': headers, 'transport_error': result.stderr.strip() or None}))
PY
```

Ці GET не передають Origin/Referer/Cookie та не запускають stateful browser
session flow. OPTIONS завершується CORS middleware. Тіла й Set-Cookie не
виводяться. CSRF-cookie GET навмисно не включений через можливий запис DB session
та session garbage collection. Після відновлення Backend очікується curl exit 0
і HTTP 200 / 401 / 204 відповідно; це ще не повний browser acceptance.

### C4 — Порти й ролі PHP-процесів без credentials у argv

```sh
ss -H -ltn '( sport = :8000 or sport = :1025 or sport = :5173 )'
python3 - <<'PY'
from pathlib import Path
import os, socket, json
roles = []
for p in Path('/proc').iterdir():
    if not p.name.isdigit():
        continue
    try:
        if (p / 'comm').read_text().strip() != 'php':
            continue
        args = (p / 'cmdline').read_bytes().decode(errors='replace').split('\0')
        role = next((a for a in args if a in {
            'queue:work','queue:listen','schedule:work','schedule:run','serve'}), None)
        if role:
            roles.append({'pid': int(p.name), 'role': role,
                'cwd_is_backend': os.readlink(p / 'cwd') == os.getcwd()})
    except OSError:
        pass
print(json.dumps({'php_roles': roles}))
for host, port in [('localhost', 8000), ('127.0.0.1', 8000),
                   ('localhost', 5173), ('127.0.0.1', 1025)]:
    try:
        with socket.create_connection((host, port), timeout=3):
            result = 'TCP_REACHABLE'
    except OSError as e:
        result = type(e).__name__ + ':errno=' + str(e.errno)
    print(json.dumps({'host': host, 'port': port, 'result': result}))
PY
```

Scanner exit 0 означає успішне виконання сканера, а не доступність усіх портів.
Він бачить тільки доступний `/proc` і не доводить відсутність зовнішніх workers.
Не читає process environment, job payload або вміст Frontend-репозиторію.

### C5 — Лише фіксовані Gemini log categories

```sh
python3 - <<'PY'
from pathlib import Path
from collections import Counter
import json, re
allowed = {'credentials_rejected','access_denied','model_inaccessible',
           'quota_or_rate_limit','http_error','model_version_mismatch'}
counts = Counter()
for p in Path('storage/logs').glob('*.log'):
    with p.open(errors='replace') as source:
        for line in source:
            match = re.match(r'^\[[^\]]+\] \w+\.NOTICE: AI provider request failed\. (\{.*?\})(?: \[\])?\s*$', line)
            if not match:
                continue
            try:
                data = json.loads(match.group(1))
            except ValueError:
                continue
            if data.get('provider') != 'gemini' or data.get('category') not in allowed:
                continue
            status = data.get('status')
            status = status if type(status) is int and 100 <= status <= 599 else None
            counts[(data['category'], status)] += 1
print(json.dumps([{'category': key[0], 'status': key[1], 'count': count}
                  for key, count in sorted(counts.items())]))
PY
```

В аудиті додатково агреговано першу/останню часову мітку тих самих безпечних
категорій та count фіксованого маркера `password.delivery_failed`. Сирі лог-рядки,
приватні повідомлення й reset URLs не копіювалися.

## Залишкова робота (Remaining work)

1. **P0 — відновити Backend listener:** оператор запускає server цього Backend
   на localhost:8000; C3 має показати `/up` 200, анонімний profile 401 і успішний
   CORS preflight для localhost:5173. Без цього реальний HTTP Stage 15B заблокований.
2. **P1 — визначити дозволений тестовий акаунт і межі Stage 15B:** окремо
   погодити login/CSRF і дозволені бізнес-мутації. У поточній valuable БД не
   використовувати невідомі retained accounts як disposable fixtures.
3. **P1 для password flow — підтвердити worker і відновити SMTP:** спершу
   C4/перевірка наявного worker оператором; запуск обробки jobs лише після
   усвідомленого дозволу. SMTP endpoint має бути доступний; реальний reset email
   перевіряти окремо на дозволеній адресі без розголошення token/URL.
4. **P1 для AI flow — отримати live evidence/дозвіл:** конфігурація Gemini вже
   узгоджена. Актуальний bounded AI запит потребує явного погодження оплати й
   акаунта; не вмикати live flags і не запускати OpenAI smoke для обходу цього.
5. **P1 для reminders — підтвердити delivery:** прийняти очищені counters уже
   виконаного Scheduler або погодити один контрольований сценарій Stage 15B.
   Не робити додаткового bulk dispatch і не запускати другий Scheduler.
6. Після кожного погодженого виправлення повторити тільки залежні безпечні
   перевірки, зафіксувати час, exit/status та оновити рішення. Offline tests
   не замінюють жодного з цих live доказів.

Код застосунку не змінювався: підтверджених code defects немає. PHPUnit,
browser mutation suites і Pint не запускалися — зміна лише документаційна;
це відповідає мінімальній перевірці Stage 15A. Міграції, reset/seed/truncate,
нові dependencies, Services, controllers і monitoring framework не додавалися.
П'ять наведених shell-блоків перевірено через `bash -n`, вкладені Python/PHP —
через AST / `php -l` без виконання; синтаксис, локальні посилання та
`git diff --check` пройшли перевірку.

## Фінальне рішення (Final decision)

**BLOCKED — підтверджено відсутню критичну передумову: доступний Backend HTTP
listener на localhost:8000.** Реальні Frontend↔Backend/e2e сценарії Stage 15B
поки починати не слід. Підготовку тестового плану й уточнення дозволів виконувати
можна. Після E15-01 і позитивної C3 можна почати явно погоджені browser сценарії;
mail/queue, AI та reminders залишаються окремими gates відповідно до обсягу
Stage 15B. Поточний read-only MySQL PASS і успішний CLI bootstrap не є доказом
готовності всіх зовнішніх інтеграцій.

Stage 15A як аудит завершений: усі доступні результати, обмеження, підтверджені
збої та наступні дії задокументовані. Жодне environmental обмеження не було
мовчки виправлено зміною бізнес-поведінки застосунку.
