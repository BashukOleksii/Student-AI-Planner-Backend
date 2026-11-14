# Автоматичні нагадування сесій — ручне приймання 2026-11-14

## Початковий стан і межі

`main`, HEAD `ed13d5a` (`Add changes`), чисті `git status --short`,
`git diff`, `git diff --cached`. Попередня пошта та виправлення огляду результатів
вже були в HEAD; їх не починали з нуля. Автотести не додавалися,
не редагувалися й не запускалися. Коміт/staging/push не виконувалися.

Створено окремий MySQL 8.4.10 з новим datadir/socket
`/tmp/planner-auto-session.Qci30hdZ`, `--skip-networking` і базою
`planner_auto_session_acceptance`. `SELECT @@datadir, VERSION()` підтвердив
новий сервер перед `php artisan migrate --no-interaction`. Усі міграції,
включно з однією новою `2026_11_14_000002_create_automatic_session_reminders`,
виконані лише там. Робоча БД не мігрувалася.

PHP 8.5.11. Кожний CLI/HTTP процес отримував явні `DB_SOCKET`, `DB_DATABASE`,
порожній `DB_URL`, окремі APP_KEY/config-cache/storage. Mailpit — вже наявний
локальний контейнер `planner-mailpit`, `127.0.0.1:1025/8025`, без credentials;
`MAIL_URL=null`, `MAIL_SCHEME=null`. Його листи не видалялися. Фільтрували
лише синтетичні адреси `auto-qci30hdz-<case>@example.test`.

Основні сценарії виконувалися вручну через справжні Services і Artisan у
CLI з процесним зафіксованим UTC-годинником Carbon, без тестових класів,
assertions чи mail fakes. HTTP профілю перевірено справжнім PHP server на
`localhost:18082`, Sanctum cookies/CSRF і curl. Окремо двічі виконано справжній
Scheduler із реальним годинником. Локальні HTTP/MySQL після приймання зупинено.

## Фактично перевірено вручну

Базові сценарії: постановка о `2026-11-15 08:00 UTC`, lead=10 хвилин,
сесія о 08:20, доставка/worker о 08:10. Для «через 5 хвилин» початок був 08:05.

| Сценарій | Спостереження |
| --- | --- |
| Новий HTTP-профіль | false/false/10, загальна пошта false |
| Не підтверджено адресу | Автоматичний email PATCH дає 422 |
| Підтверджено, загальна згода false | Автоматичний email PATCH дає 422; GET підтверджує, що згода не ввімкнулась |
| Явні обидві поштові згоди | PATCH дає 200 для підтвердженої адреси |
| lead 0, -5, 1.5, рядок `"10"`; boolean як 1 | 422; коректні in-app=true/lead=15 прийняті |
| Сесія через 5 хв, lead=10 | 0 автоматичних записів, 0 inbox, 0 листів |
| Лише in-app, загальна email-згода true | 1 inbox, 0 поштових jobs/листів |
| Лише email | 0 inbox, 1 job і 1 лист у Mailpit |
| Обидва канали | 1 inbox, 1 job і 1 лист |
| Обидва вимкнені | 0 записів, inbox і листів |
| Повтор диспетчера | Перший selected=4/delivered=2/queued=2; другий selected=0, дублів немає |
| SMTP поза DB-транзакцією | Реальний `MessageSending` показав transactionLevel=0 |
| Cancel через чинний LifecycleService | Обидва записи стали cancelled; 0 inbox/листів |
| Complete та miss через чинний LifecycleService | Невиконані записи cancelled; 0 inbox для обох сесій |
| Перенесення через ReschedulingService | Старий session ID 7 має cancelled intents; новий ID 8 має новий trigger 08:50 для початку 09:00. Доставка дала по одному inbox/листу |
| Ручне нагадування тієї самої сесії/trigger | Чинний manual dispatcher дав 1 inbox/job; automatic дав suppressed=2; один лист у Mailpit |
| Disable → enable до trigger | Обидва записи лишились cancelled; 0 доставок |
| Disable email після enqueue → enable | Job завершився без листа, email intent cancelled; inbox зберігся |
| Зміна адреси до trigger і після enqueue | Email cancelled, жодного листа на стару/нову адресу; незалежний inbox доставлено |
| Worker о 10:20:01 для початку 10:20 | Queued email cancelled, 0 листів; попередній inbox лишився |
| Dispatcher рівно о 10:20 для початку 10:20 | cancelled=2; 0 inbox/jobs/листів |
| SMTP connection refused на локальному порту 11026 | Job FAIL, retry attempts=1; email queued, inbox=1. Повтор о 10:11 через Mailpit успішний, inbox все ще 1, лист 1 |
| Зміна lead 10 → 5 | Стара сесія зберегла trigger 12:10/lead=10; нова сесія отримала trigger 12:55/lead=5 |
| Увімкнення після створення сесії | У старої сесії 0 нових записів; cancelled intents не відновилися |
| Rollback транзакції створення сесії | Усередині 2 intents; окреме PDO бачило 0; після rollback немає ані сесії, ані intents |
| Ручний план через preview/confirm | Створено session 23 і два scheduled intents |
| Адаптивне перепланування після конфлікту з Lesson | Session 23 rescheduled, старі intents cancelled; successor 24 має два нові intents на новий час |
| Підтверджене відновлення через detect/confirm | create_plan → applied; нова сесія 26 має два intents |
| Два справжні `schedule:run` | При due trigger та початку через 5 хв лишились 1 inbox і 1 mail job; worker доставив рівно 1 лист |
| Шаблон email-only | Українська тема, 0 inbox; новий лист не містить хибного повідомлення про доступність у «Вхідних». Рендер ручного шаблону зберіг це повідомлення |
| NotificationResource | Коректний український immutable snapshot, `type=reminder`, `reminder_id=null`, належний session ID |

Реальний Scheduler-case створено з контрольованим ранішим годинником
постановки, щоб trigger був майбутнім тоді й уже due під час реального запуску.
Це ручне моделювання пропуску між тиками, а не backfill застосунку.

Для SMTP збою змінювався лише env **одного CLI-процесу** (`MAIL_PORT=11026`),
після чого наступний процес знову використовував Mailpit. Налаштування
репозиторію, транспорт і реальний SMTP не змінювалися. Базовий перший виклик
`Artisan::call` із числовим option=100 був відхилений чинною CLI string-валідацією;
повторено точну команду з `--limit=100`. Цю початкову відмову не зараховано
як доставку.

## Команди та спосіб повторення

Усі команди виконувалися після експорту ізольованого локального середовища.
Не запускати їх на робочій БД.

```sh
php artisan migrate --no-interaction
php artisan sessions:dispatch-reminders --limit=100
php artisan reminders:dispatch-due --limit=100
php artisan schedule:run --no-ansi
php artisan schedule:run --no-ansi
php artisan schedule:list --no-ansi
php artisan queue:work reminder-email --queue=reminder-email --stop-when-empty --sleep=0 --tries=3 --timeout=60 --no-ansi
```

Для зафіксованих часових точок у CLI після звичайного Laravel bootstrap
застосовували `CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'UTC'))`,
далі викликали реальні Services або
`Artisan::call('sessions:dispatch-reminders --limit=100')`. Це не запуск
тестового набору. Наприклад, створення через Eloquent у транзакції з належним
профілем/завданням, ручний виклик dispatcher у due-час, потім worker:

```php
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 08:00:00', 'UTC'));
$session = DB::transaction(fn () => $owner->studySessions()->create([
    'task_id' => $task->id, 'starts_at' => '2026-11-15 08:20:00',
    'ends_at' => '2026-11-15 08:50:00', 'status' => 'planned',
]));
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 08:10:00', 'UTC'));
Artisan::call('sessions:dispatch-reminders --limit=100');
Artisan::call('queue:work', ['connection' => 'reminder-email', '--queue' => 'reminder-email',
    '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3, '--timeout' => 60]);
```

Mailpit перевірявся через `/api/v1/search?query=to:<синтетична адреса>`;
рахували `messages_count`, не глобальний `total`. Inbox/intents/jobs читалися
тільки в disposable БД. Автоматизовані assertions не створювалися.

Після останніх правок повторено сценарій обох каналів о 17:10: один inbox,
один лист у Mailpit, обидва intents delivered; другий dispatch selected=0.
Для вимкнених каналів — 0 intents. `php -l` пройшов для всіх 15 нових/змінених
PHP-файлів, `git diff --check` — без помилок; локальні посилання й синтаксис
shell-блоків документації перевірено без виконання цих блоків.

## Висновки з коду та неперевірене

- Усі поточні writers, зокрема підтверджені AI-дії, охоплені observer:
  [таблиця викликів і обґрунтування](automatic-session-reminders.md#коли-створюються-записи).
  Повний AI pending-action/HTTP-confirm workflow у цьому прийманні не запускався;
  LLM-викликів не було. Для AI покриття — висновок з аудиту делегування.
- Атомарний INSERT поштового job використовує чинний same-connection guard і
  `beforeCommit()`. Процесне падіння в момент commit та одночасні dispatchers
  не інжектувалися; повтори перевіряли послідовно.
- Сумісність старих serialized jobs випливає з явного default нового поля;
  старий production payload не читався. Чинний ручний job реально виконався.
- Перевірено одну SMTP-помилку й успішний retry. Три спроби/backoff лишилися
  чинними в коді; повне вичерпання повторів саме для автоматичного job тут не
  програвали. Невідомий SMTP-результат не означає гарантії рівно одного листа.
- Не перевіряли production, AWS, реальний SMTP, навантаження, конкурентну зміну
  налаштувань після остаточного preflight та браузерний Frontend.

## Змінені файли

- Профіль: `app/Models/User.php`, `app/Http/Requests/UpdateProfileRequest.php`,
  `app/Http/Resources/UserResource.php`, `app/Services/Auth/ProfileService.php`.
- Збереження й доставка: `app/Models/AutomaticSessionReminder.php`,
  `app/Observers/StudySessionReminderObserver.php`, `app/Providers/AppServiceProvider.php`,
  `app/Services/Reminders/AutomaticSessionReminderService.php`,
  `app/Console/Commands/DispatchSessionReminders.php`, `routes/console.php`.
- Спільна пошта/читання: `app/Services/Reminders/ReminderEmailService.php`,
  `app/Jobs/SendReminderEmail.php`, `app/Mail/StudyReminderMail.php`,
  `resources/views/mail/study-reminder.blade.php`,
  `resources/views/mail/study-reminder-text.blade.php`,
  `app/Http/Resources/NotificationResource.php`.
- Одна нова міграція: `database/migrations/2026_11_14_000002_create_automatic_session_reminders.php`.
- Документація: `docs/automatic-session-reminders.md`, цей звіт,
  `docs/auth-profile.md`, `docs/architecture.md`, `docs/domain-model.md`,
  `docs/business-rules.md`, `docs/database-review-notes.md`.
