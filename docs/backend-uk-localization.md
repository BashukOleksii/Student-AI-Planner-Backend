# Українські повідомлення Backend

## Обсяг і початковий аудит

2026-11-14: `main`, HEAD `ae7e5cb`, початкові `git status --short` і diff
порожні. Прочитано AGENTS.md та фактичні Requests, Services, Resources,
винятки, import pipeline і `bootstrap/app.php`. Це зміна текстового подання:
правила, маршрути, ключі/типи JSON, статуси, коди причин, час доставки й
підтвердження дій збережені. Frontend, AWS, SMTP і вільні відповіді AI не
редагувалися. Міграції для цієї зміни не потрібні.

Перелік джерел видимого тексту та їхнє покриття:

| Джерело | Що локалізовано / що лишається даними |
| --- | --- |
| Стандартний Laravel validation | `lang/uk/validation.php`: усі ключі поточної framework-версії, обмеження розміру для string/number/array/file, password rules, зрозумілі назви полів, вкладені поля й дати. Правила в Requests не змінювалися |
| Laravel auth/password/pagination | `lang/uk/auth.php`, `passwords.php`, `pagination.php`, `lang/uk.json` для підсумку помилок; success-тексти PasswordController також українські, anti-enumeration відповідь лишається однаковою |
| Власні Form Requests | Валідація дат/UTC, JSON, дозволених полів, фільтрів, підзавдань, idempotency header, сесії SPA. `errors` зберігає вихідні ключі |
| Academic/Profile/Tasks | Власні ValidationException та конфлікти належності, унікальності, дедлайнів, оцінок, видалення; раніше українські profile-тексти збережено |
| Schedule/semester | Повідомлення про розклад, викладачів, винятки дат, перетини й застарілий стан; назви класів/таблиць у teacher-repair тексті замінено людськими позначеннями з тим самим ID |
| Planning/sessions/recovery | `StudyPlanPersistenceService`, `StudyReplanningPersistenceService`, налаштування/доступність, lifecycle-конфлікти й fixed public exceptions. Ключі згод і токенів, статуси та класифікації не перекладалися |
| Import | Parser, materializer, resolver, planner, preview/revalidate/commit: власні причини українські; назви аркушів, дані користувача й координати збережені. Повідомлення бібліотеки в load-помилці замінюється власною сталою причиною |
| `bootstrap/app.php` | Наявні domain fallback-тексти українські. Окремий останній renderer обробляє решту API/Sanctum/JSON-помилок: зберігає статус і HTTP headers, дає безпечний текст для неочікуваної помилки навіть при debug=true |
| API Resources/notifications | `NotificationResource` має український загальний fallback; `ReminderDeliveryRules` створює нові українські task/subtask/session шаблони без власного message. Збережені payloads читаються без переписування |
| Statistics | Стандартні поля/дати та HTTP-відмови локалізовані. `ProgressComparisonRequest` більше не віддає довільний `InvalidArgumentException::getMessage()`: відомі причини мають конкретні українські відповідники |
| Операторські повідомлення | Логи, CLI diagnostics, внутрішні LogicException/UnexpectedValueException, SQL і provider details не є студентським текстом; їх не перекладали масово. Неочікувана помилка не віддає їх через API |
| Машинні та власні значення | `code`, `reason`, status enums, recommendation codes, назви полів, формати часу, IDs, введені назви/описи/повідомлення та AI content залишаються даними |

Локалізовано перевірені власні текстові літерали, bootstrap-повідомлення та
додано Laravel catalogs. Ім’я акаунта/викладача має окрему назву поля,
незалежну від назви предмета або закладу.
Це не глобальна заміна довільних response strings: власний текст користувача
не проходить через перекладач. Для 61 наявного PHP-файлу порівняння токенів
підтвердило, що зміни обмежені літералами/коментарями; решта змін — явне
формування локалізованого подання й обробка помилок.

## Локаль та наявний `.env`

`config/app.php` і `.env.example` задають `APP_LOCALE=uk` та
`APP_FALLBACK_LOCALE=uk` за замовчуванням. `APP_FAKER_LOCALE` залишено як є:
це налаштування генератора даних, не мова браузерного UI.

**Наявний `.env` із явним `APP_LOCALE=en` має пріоритет над новим default.**
Власник відповідного середовища має сам змінити:

```dotenv
APP_LOCALE=uk
APP_FALLBACK_LOCALE=uk
```

Потім у дозволеному середовищі скинути/перебудувати config cache та
перезапустити довготривалі Laravel-процеси за чинним runbook:

```sh
# Локальна розробка:
php artisan config:clear
# Середовище з config cache — лише через його погоджену процедуру:
php artisan config:cache
```

Production `.env`, AWS та production cache у цій задачі не редагувалися.
Фактичне приймання використовувало окремі процесні env overrides з uk та
приватний шлях APP_CONFIG_CACHE; воно не доводить, що чинний deploy уже
перемкнувся на українську.

## Безпека й незмінність контракту

- Наявні domain renderers залишають `code`, `classification` і власні JSON
  форми. ValidationException зберігає `errors` та статус; надана власна response
  також зберігається. Нових машинних кодів не додано.
- Новий загальний renderer використовує фактичний HttpException status і
  headers, зокрема `Retry-After`/`Allow`; для неочікуваного не-HTTP винятку
  лишається 500. Немає перетворення всіх відмов на один статус.
- Довільний текст HTTP-винятку не віддзеркалюється. Конкретні відомі domain
  пояснення для загального renderer дозволені явним списком. Попередні
  спеціалізовані domain renderers використовують власні fixed public exceptions.
- Неочікувані SQL/runtime/provider помилки на JSON/API шляхах отримують
  безпечне повідомлення без exception class, SQL bindings, file/line або trace.
  Логування залишається окремим операторським каналом із чинними правилами.
- `getMessage()` у двох import Services лишається тільки для власних типів
  `ScheduleWorkbookParserException` / `ScheduleOccurrenceMaterializationException`.
  Їхні джерела перевірено: це визначені діагностики застосунку; сирий previous
  exception від PhpSpreadsheet не серіалізується. Якщо ці типи надалі почнуть
  обгортати довільний library message як основний текст, цей контракт порушиться.

## Історія, Frontend і залишкові шляхи сирого тексту

**Історичні inbox snapshots не змінюються.** `You have a task reminder.`
може бути як старим шаблоном, так і власним текстом студента. Без окремої
ознаки походження визначити це надійно неможливо. Старі повідомлення, у тому
числі англійські, повертаються дослівно. Нові нагадування без власного тексту
використовують українські системні шаблони; власний латинський message не
перекладається. Немає data migration або масового оновлення історії.

Імпорт і збережені огляди також можуть містити історичні тексти попередніх
версій. `ScheduleImportBatchResource.error_message`,
`ScheduleImportRowResource.validation_errors` та
`SemesterScheduleImportResource.preview.errors` читають збережені поля.
Нові діагностики локалізовано в джерелах; існуючі persisted snapshots не
переписували й довільний вміст історичної БД не сертифікували. Дозволена
revalidation або новий import генерує чинні діагностики там, де це підтримує
поточний lifecycle. Failed/unparsed imports потребують нового завантаження.
Ці історичні шляхи не позначаються як повністю українізовані.

Frontend повинен відображати серверні `message`/`errors` і наявні людські
пояснення. Для машинних `status`, `code`, `reason`, direction/classification,
пріоритетів та інших enums потрібні українські UI-підписи на Frontend; Backend
навмисно не перекладає самі значення. Браузерні network/timeout/CORS помилки,
клієнтська валідація й fallback-тексти також належать Frontend. Його код та
фактичний rendering тут не перевірялися. Не можна проганяти весь response
через автоматичний переклад: це змінить власні назви й повідомлення.

Вільні відповіді AI, старі AI messages, назви предметів/файлів/аркушів,
імена, описи й інший власний вміст можуть містити будь-яку мову. Формат і
тексти вільних AI-відповідей не змінювалися; повністю український AI output
цією задачею не гарантується.

## Фактично перевірено вручну

Окремий MySQL 8.4.10, база `planner_uk_ui_acceptance`, новий private
datadir/socket `/tmp/planner-uk-ui.NC2pwrBp`, `--skip-networking`. Перед
міграціями `SELECT @@datadir, VERSION()` підтвердив саме цей сервер. Застосовано
тільки чинні міграції для порожньої disposable БД; нової міграції не додано.
PHP 8.5.11, `APP_LOCALE=uk`, `APP_FALLBACK_LOCALE=uk`, `APP_DEBUG=true`, окремі
APP_KEY/config-cache/storage, mailer=array. SMTP, AI provider, Scheduler та
queue worker не запускалися. Після приймання локальні MySQL/HTTP зупинено.

Реальний HTTP Backend `localhost:18083`, curl, Sanctum cookies/CSRF:

| Сценарій | Фактичний результат |
| --- | --- |
| Невалідна форма завдання | 422; українські message/errors та назви полів; ключі `title`, `priority`, `estimated_minutes`, `deadline_at` збережено |
| Невдала автентифікація / некоректний reset token | HTTP 422 під тими самими `errors.email` / `errors.token`; повідомлення українські, надсилання листа не виконувалося |
| Анонімний GET профілю | HTTP 401 з українським повідомленням |
| Порожнє ім’я профілю та викладача | 422; окремі зрозумілі назви поля «ім’я» / «ім’я викладача» |
| Власні title/description латиницею | 201/200; `English user title` і `Keep this user text unchanged.` збережено дослівно |
| Огляд плану зі справжнім підписом, виданий 16 хв тому | Confirm 422, `errors.preview_token`, українська причина недійсного/простроченого огляду |
| Чинний огляд, потім зміна estimated_minutes 30 → 60 | Confirm 409 з українською причиною застарілого плану; study_sessions=0 після обох відмов |
| XLSX з некоректним часом C3 | Upload 201, persisted status `failed`, українська `preview.errors.file`; залишилися аркуш `Sheet1` і клітинка `C3`. Чинну форму невдалого import не перетворювали на інший статус |
| Нагадування для task без власного message | Створення 201; одноразовий dispatcher доставив inbox із `Нагадування про завдання.` |
| Нагадування з власним текстом | Inbox 200 містить дослівне `Keep my Latin reminder exactly.` |
| Відсутнє завдання | 404, українське message без model/path/trace навіть із debug=true |
| Періоди порівняння статистики перетинаються | 422, українська конкретна причина; ключ `errors.current_start_date` збережено |
| Pagination | `data/links/meta` незмінні; previous/next labels українські |

Окремо вручну викликано справжній Laravel exception handler у CLI (це **не**
end-to-end HTTP ін’єкція збою):

- QueryException із синтетичними SQL, binding, внутрішнім шляхом і provider
  маркером при debug=true → 500, тільки безпечне українське `message`.
- TooManyRequestsHttpException → 429, `Retry-After=17` збережено; внутрішній
  exception text не потрапив у body.
- RecoverySuggestionException(`suggestion_stale`) → 409, той самий
  `code=suggestion_stale`, українське message.
- Parser для відсутнього локального XLSX → власне українське повідомлення
  читання; library previous exception лишився внутрішнім.
- Синтетичний історичний inbox snapshot з англійським шаблоном → дослівний
  старий message; порівняння persisted JSON до/після Resource read підтвердило
  відсутність запису. Порівнювали дані після DB reload, враховуючи MySQL JSON
  normalization, а не Eloquent JSON до початкового збереження fixture.

## Висновки з коду та неперевірене

Аудит охопив поточні власні текстові джерела перелічених модулів. Порівняно
ключі українського validation catalog з поточною vendored Laravel-версією:
відсутніх ключів немає. Синтаксис PHP і `git diff --check` перевірено окремо.
Це не означає, що кожна гілка кожного endpoint пройдена вручну.

Не запускали повний browser workflow, всі типи import geometry/DST failures,
повні semester structured conflicts, усі lifecycle/consent branches, реальний
HTTP SQL/provider outage, живу історичну БД, production config/cache чи LLM.
Їхнє текстове покриття — висновок з коду, а не заявлений ручний PASS.
`reason`/структуровані conflict payloads збережено кодом; у прийманні реально
перевірено plan conflict status/body та recovery code, а не кожен conflict reason.

Автоматизовані тести не додавалися, не редагувалися й не запускалися.
`php -l` пройшов для 74 нових/змінених PHP-файлів; JSON catalog коректний,
локальні посилання документації та `git diff --check` пройшли перевірку.
Наявні тести з точними англійськими рядками/підписами pagination можуть
потребувати окремого оновлення очікувань; зеленого test suite не заявлено.
Коміту, staging і push немає.
