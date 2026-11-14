# Добровільні нагадування до початку навчальних сесій

Автоматичні нагадування мають незалежні канали «У застосунку» й «На пошту».
Обидва початково вимкнені. Це окреме походження записів, а не зміна ручних
`Reminder` або правил оглядів відновлення.

## API профілю

Чинні GET/PATCH `/api/profile` та всі відповіді `UserResource` додатково містять:

| Поле | Тип і початкове значення | PATCH |
| --- | --- | --- |
| `session_reminder_in_app_enabled` | boolean, `false` | Лише JSON true/false |
| `session_reminder_email_enabled` | boolean, `false` | Лише JSON true/false |
| `session_reminder_lead_minutes` | integer, `10` | Лише цілий JSON number 1–65535 |

Нуль, від'ємні значення, дроби, числові рядки, null і хибні типи дають HTTP 422
у чинному Laravel `message/errors` форматі. Непередані поля зберігаються.
Час після початку не кодується від'ємним зміщенням: значення завжди означає
кількість хвилин **до** початку.

Email потребує одночасно `session_reminder_email_enabled=true`,
`reminder_email_enabled=true` та підтвердженої поточної адреси. Спроба
ввімкнути автоматичний email без двох останніх умов повертає 422 під
`errors.session_reminder_email_enabled`. Загальна згода ніколи не вмикається
неявно. Після підтвердження адреси користувач може явно передати обидві згоди
одним PATCH. Автоматичний in-app не потребує email або його підтвердження.

Приклад «лише email» для вже підтвердженої адреси, зі звичайними Sanctum
cookies, CSRF і `Accept: application/json`:

```http
PATCH /api/profile
Content-Type: application/json
X-XSRF-TOKEN: <чинний token>

{"reminder_email_enabled":true,"session_reminder_in_app_enabled":false,"session_reminder_email_enabled":true,"session_reminder_lead_minutes":10}
```

Зміна адреси атомарно скидає підтвердження, обидві поштові згоди та анулює
ще не доставлені автоматичні email-записи. In-app канал лишається незалежним.
Вимкнення загальної поштової згоди також вимикає автоматичну поштову опцію.
Увімкнення опції разом із новою непідтвердженою адресою відхиляє весь PATCH.

## Коли створюються записи

Синхронний `StudySessionReminderObserver::created()` делегує в
`AutomaticSessionReminderService::schedule()`. Сервіс перечитує власника під
чинним owner lock, перевіряє належність/придатність роботи й сесії через
`ReminderTargetService` і рахує `starts_at − lead_minutes` у UTC. Запис
створюється тільки для ввімкненого каналу і тільки якщо trigger **строго
пізніший** за поточний час постановки. Для сесії через 5 хвилин і lead=10
записів не буде; негайного або наздоганяючого нагадування немає.

Аудит усіх `studySessions()->create()` в `app/` встановив такі шляхи:

| Шлях | Фактичний writer і охоплення |
| --- | --- |
| Ручний план і scoped recovery preview | `StudyPlanPersistenceService::confirmWithinTransaction()` створює Eloquent-сесії в owner-first транзакції |
| Адаптивне перепланування | `StudyReplanningPersistenceService::confirmWithinTransaction()` змінює старі статуси й створює кожного successor через Eloquent |
| Окреме перенесення | `StudySessionReschedulingService` створює нового successor через Eloquent у `StudySessionMutationContextService` |
| Підтверджені AI plan/replan/reschedule | `PlanningActionService` делегує в ті самі `confirmWithinTransaction()` / `rescheduleWithinTransaction()`; окремого INSERT немає |
| Підтверджене автоматичне відновлення | `RecoverySuggestionService::confirm()` делегує в ті самі writers; саме виявлення нічого не планує |
| Два demo writers | `DemoStudentService` і `AttentionDemoService` також використовують Eloquent creation у транзакціях; початкові опції false |

Тому observer охоплює всі поточні шляхи без копіювання логіки в контролери,
AI tools або планувальники. Він виконується **до commit**, не як after-commit
callback; у чинних writers сесія та автоматичні записи відкочуються разом.
Сервіс допускає вкладену транзакцію. Новий writer через raw SQL, bulk INSERT
або `withoutEvents()` обійшов би observer і потребував би окремої інтеграції;
таких продуктових writers у поточному коді не виявлено.

Observer `updated()` скасовує невиконані записи при terminal статусі або
зміні інтервалу. Перенесення створює нову сесію й обчислює її нагадування з
поточних налаштувань; старий запис не копіюється. Диспетчер/worker також
перевіряють поточний persisted стан, навіть якщо зміна обійшла model events.

## Налаштування й наявні дані

Міграція `2026_11_14_000002_create_automatic_session_reminders` додає три
поля User з false/false/10 для наявних і нових акаунтів та окрему таблицю
`automatic_session_reminders`. Існуючі сесії, `reminders`, inbox і jobs не
переписуються. Backfill немає, також немає пошуку старих сесій у Scheduler.

Таблиця має FK на User/StudySession, унікальність `(study_session_id, channel)`,
індекс due-вибірки та snapshot lead/starts/trigger. Nullable унікальний FK
`notification_id` заповнюється лише для in-app; видалення сповіщення не робить
доставлений запис знову придатним. Видалення сесії видаляє її delivery intents,
але не існуючу історію inbox. Належність сесії користувачеві перевіряє спільний
Service; самі прості FK не є перевіркою ownership.

Зміна lead впливає **тільки на нові сесії**: вже запланований trigger
зберігається. Увімкнення каналів не додає записи до старих сесій. Вимкнення
каналу атомарно переводить його `scheduled` і `queued` записи в `cancelled`.
Повторне ввімкнення не відновлює їх; унікальний ключ також не дозволяє
створити ще один запис для того самого каналу й сесії.

## Доставка й дедуплікація

Новий bounded `sessions:dispatch-reminders --limit=100` виконується щохвилини
поряд із чинним ручним dispatcher. Кожний канал має власну owner-first
транзакцію. `scheduled` стає `delivered` для in-app або `queued` для email.
Непридатний запис стає `cancelled`, збіг з ручним — `suppressed`.
Рядкові блокування, статуси та унікальний ключ захищають повторні запуски.

На момент доставки потрібно `trigger_at <= now < session_starts_at`, чинний
канал, незмінний початок та придатна `planned` сесія. Пропущений між двома
тиками trigger можна доставити до початку, але рівність із початком або
запізнення скасовують запис. Завершені, пропущені, скасовані, rescheduled
сесії та неактивна робота не отримують пізніх автоматичних нагадувань.

Ручний `Reminder` тієї самої сесії й точного trigger зі статусом `scheduled`
або `sent` має пріоритет. Його inbox та, за чинною загальною згодою,
email покривають відповідні канали: автоматичні записи придушуються до
доставки. Скасований ручний запис не придушує автоматичний. Перевірка
повторюється worker. Ручні маршрути, час, типи та власна доставка збережені.

In-app використовує native notifications із DB type
`automatic_session_reminder`, `reminder_id=null` і перевіреним snapshot.
`NotificationResource` повертає чинну форму з `type=reminder`, nullable
`reminder_id` та `study_session_id`; текст український. Окрема таблиця й
notification type позначають автоматичне походження. Ці записи не потрапляють
до REST ручних reminders чи їхнього recovery fingerprint.

Email-only взагалі не створює notification. In-app-only взагалі не ставить
лист, навіть за `reminder_email_enabled=true`. Email використовує чинні
`SendReminderEmail`, encrypted payload і `reminder-email` database connection.
Job вставляється в `jobs` у тій самій DB connection/транзакції, що й перехід
автоматичного email-запису в `queued`. Старі серіалізовані jobs сумісні:
новий `automaticReminderId` має явний property default null.

Worker заново читає профіль, належну йому сесію та запис, перевіряє канал,
загальну згоду, підтвердження, адресу/її revision, статус та майбутній початок.
SMTP виконується поза транзакцією; поштовий збій не відкочує in-app доставку.
Після успіху email-запис стає `delivered`. Скасовані jobs завершуються без листа.
Чинні 3 спроби, backoff 60/300 секунд, timeout 60 і retry_after 90 збережені.
Після вичерпання спроб job лежить у `failed_jobs`, email intent лишається
`queued` для операційного розбору; Scheduler не створює нового job.

Наявні SMTP transport і український шаблон використовуються повторно.
Автоматичний лист не обіцяє наявності inbox; ручний шаблон за замовчуванням
зберігає таке повідомлення. Секрети/текст не логуються. SMTP не гарантує рівно
один лист при втраті відповіді або падінні перед ACK. Остаточна перевірка не
може відкликати надсилання, яке вже почалося, або зробити зовнішній SMTP
атомарним зі зміною профілю/сесії; конкурентна зміна після перевірки лишається
чинною межею. Ручний retry потребує розгляду невизначеного результату.

## Запуск і ручне приймання

Міграцію застосовувати лише в цільовому дозволеному середовищі; у цій задачі
вона виконувалась **тільки на новій ізольованій локальній MySQL**. Для оновлення
вже мігрованої локальної БД:

```sh
php artisan migrate --path=database/migrations/2026_11_14_000002_create_automatic_session_reminders.php --no-interaction
```

Локальний Mailpit: `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, `MAIL_SCHEME=null`,
`MAIL_URL=null`, порожні SMTP username/password, локальна From address. Не
використовувати production config cache. Чинний `reminder-email` worker
обслуговує обидва походження jobs; додаткового SMTP/worker/AWS сервісу немає.

```sh
php artisan schedule:work
php artisan queue:work reminder-email --queue=reminder-email --sleep=3 --tries=3 --timeout=60
# Для керованих одиничних кроків замість постійних процесів:
php artisan sessions:dispatch-reminders --limit=100
php artisan queue:work reminder-email --queue=reminder-email --once --tries=3 --timeout=60 --no-ansi
```

Production потребує чинного ручного migration gate, оновлення code/config cache
та перезапуску довготривалого worker за [наявним runbook](lightsail-backend-deployment.md).
Жодної production-команди або зміни AWS/systemd/SMTP не виконано.

Фактичні результати, відокремлені від аудиту коду, наведено в
[звіті ручного приймання](automatic-session-reminders-manual-verification.md).
