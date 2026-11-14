# Поштові нагадування — ручне приймання 2026-11-14

## Відновлення перерваної роботи

Початковий `HEAD`: `9a2a8f2`, гілка `main`. Виконано `git status --short`,
`git diff`, `git diff --cached`, `git log -5 --oneline` і прочитано всі вісім
untracked-файлів. Staged-змін не було; сторонніх незакомічених змін не виявлено.
Попередній коміт про буфер планування залишено без змін.

Від попереднього сеансу вже були:

- поля профілю, валідація opt-in, скидання опції/підтвердження та версія адреси;
- одна нова міграція, database queue на спільному з'єднанні, enqueue у транзакції;
- encrypted job із трьома спробами, Mailable та два українські шаблони;
- контракт API і доповнення архітектурної/операційної документації.

Цей файл був порожній. Worker утримував User lock і виконував SMTP усередині
власної транзакції. У продовженні SMTP винесено за межі всіх транзакцій,
додано остаточне перечитування профілю та перевірку фактично створеного SMTP
transport, щоб `MAIL_URL` не міг підмінити його на log/failover. Уточнено
конкурентне вікно надсилання та локальне `MAIL_URL=null` у контракті.
Повторних міграцій, полів, jobs або outbox не створено.

Успадковані файли збережено: `app/Http/Requests/UpdateProfileRequest.php`,
`app/Http/Resources/UserResource.php`, `app/Models/User.php`,
`app/Services/Auth/ProfileService.php`, `app/Services/Reminders/ReminderDeliveryService.php`,
`config/queue.php`, `app/Jobs/SendReminderEmail.php`, `app/Mail/StudyReminderMail.php`,
`database/migrations/2026_11_14_000001_add_reminder_email_preferences_to_users.php`,
`resources/views/mail/study-reminder.blade.php`,
`resources/views/mail/study-reminder-text.blade.php`, `docs/architecture.md`,
`docs/auth-profile.md`, `docs/business-rules.md`, `docs/database-review-notes.md`,
`docs/domain-model.md`, `docs/lightsail-backend-deployment.md`, `docs/reminders.md`.
Фактично в цьому продовженні редаговано лише
`app/Services/Reminders/ReminderEmailService.php`, `docs/reminder-email.md`
та цей звіт `docs/reminder-email-manual-verification.md`.

## Ізоляція й фактичне середовище

Ручні дії виконано через справжні HTTP API, MySQL і queue worker, без PHPUnit,
Pest, Playwright, mail fakes чи тестових класів. Автотести не додавалися,
не змінювалися й не запускалися.

PHP CLI 8.5.11; окремо ініціалізований MySQL 8.4.10 із `--skip-networking`,
socket у тимчасовому каталозі `/tmp/planner-reminder-mail.Rki9QFWv`, база
`planner_reminder_email_acceptance`. Перед міграціями `SELECT @@datadir`
підтвердив саме цей новий datadir. Робочу БД не міг вибрати мережевий fallback:
процеси мали явні `DB_SOCKET`, `DB_DATABASE`, порожній `DB_URL` і окремий
`APP_CONFIG_CACHE`. Storage, логи, cookies і APP_KEY також були тимчасовими.
`users`, `reminders`, `notifications`, `jobs` фактично використовували InnoDB.

Використано вже запущений локальний контейнер `planner-mailpit`
(`axllent/mailpit`, SMTP `127.0.0.1:1025`, HTTP `127.0.0.1:8025`). Його не
перезапускали, листи не видаляли. Читали лише синтетичні адреси цього приймання.
Backend запущено окремо на `http://localhost:18081`; Frontend не запускався.
Ізольовані процеси HTTP і MySQL після перевірки зупинено.

## Результати

| Сценарій | Фактично отримано |
| --- | --- |
| Новий профіль | HTTP 201/200, `email_verified=false`, `reminder_email_enabled=false` |
| Увімкнення без підтвердження | HTTP 422, `errors.reminder_email_enabled`; рядок `"true"` також відхилено |
| Непідтверджена адреса, reminder 1 | Один inbox record, `sent`; жодного поштового job |
| Підтверджена адреса, опція false, reminder 3 | Один inbox record, `sent`; нового поштового job немає |
| Підтверджена адреса, опція true, reminder 4 | До worker: один inbox record і один job; після worker: рівно один лист у Mailpit, job видалений |
| Український лист | Тема «Навчальне нагадування»; український plain-text і HTML; `<b>` у повідомленні екрановано в HTML |
| Повтор диспетчера до worker | `selected=0`, той самий один inbox record і один початковий job |
| Зміна адреси до worker, reminder 5 | PATCH скинув підтвердження й опцію; після підтвердження/opt-in нової адреси job завершився без листа на жодну адресу |
| Зміна адреси разом із true | HTTP 422; вся зміна відкотилася, попередній профіль зберігся |
| Повернення до тієї самої адреси, reminder 7 | Послідовність B → A → B підняла версію до 3; після підтвердження й opt-in старий job не надіслав листа |
| Вимкнення після enqueue | Початковий reminder 2 був у retry; вимкнення через PATCH перед наступною спробою завершило job без листа |
| SMTP connection refused, reminder 6 | Три спроби; після перших двох записані затримки 60/300 с; після третьої 0 queued і 1 failed job |
| Збереження inbox після SMTP-збою | Один inbox record, HTTP GET `/api/notifications` 200; `sent` та `sent_at=2026-11-14 11:08:25` незмінні після всіх спроб |
| SMTP поза транзакцією | Реальний `MessageSending` у worker показав `DB::transactionLevel()=0` |
| Атомарність enqueue, reminder 7 | Усередині транзакції inbox=1/jobs=1; незалежне PDO-з'єднання бачило 0/0; після rollback: `scheduled`, inbox=0/jobs=0 |
| Безпечний failed record | Виняток починається з фіксованого `Reminder email delivery failed.`; адреси й тексту повідомлення в exception немає |
| Підміна транспорту через URL | `MAIL_URL=log://default` відхилено до send із фіксованою помилкою про SMTP transport |

Кінцевий стан ізольованої БД: 7 reminders, по одному inbox record для кожного,
0 queued jobs, 1 failed job. Mailpit: адреса `acceptance-rki9qfwv@example.test`
має 1 лист, `changed-rki9qfwv@example.test` — 0. Для Mailpit рахували
`messages_count`/довжину `messages`, а не глобальний `total`.

Перша спроба reminder 2 мала конфігураційну помилку через `MAIL_URL=''`:
Laravel 13 трактує задане поле URL config навіть із порожнім рядком як джерело transport і
порожній рядок спричиняє `Unsupported mail transport []`. Діагностика вивела
тільки клас/місце помилки, без транспортних деталей. Локальний override
виправлено на `MAIL_URL=null`; новий reminder 4 успішно доставлено worker.
Цей початковий збій не зараховано як успішне SMTP-приймання.

Затримки retry не очікували повністю: перед другою і третьою спробами
reminder 6 вручну пересунули лише `jobs.available_at` у поточний час в
ізольованій БД. Політика job, лічильник attempts і backoff не змінювалися.
Exit 0 команди worker сам по собі не є доказом успіху листа: перевіряли
`DONE`/`FAIL`, таблиці та Mailpit.

## Команди для відтворення вручну

Усі наступні команди — тільки для нового локального MySQL, не для робочої БД.
Не запускати наявні testing scripts: тут немає запуску тестового набору.
Команди виконуються з кореня Backend у кількох терміналах.

Підготовка і запуск MySQL (залишити другий процес працювати у foreground):

```sh
umask 077
reminder_run=$(mktemp -d /tmp/planner-reminder-mail.XXXXXXXX)
printf '%s\n' "$reminder_run" > /tmp/planner-reminder-mail-current
mkdir -p "$reminder_run/data" "$reminder_run/storage/framework/cache/data" \
  "$reminder_run/storage/framework/sessions" "$reminder_run/storage/framework/views" \
  "$reminder_run/storage/logs"
mysqld --no-defaults --initialize-insecure --datadir="$reminder_run/data" \
  --log-error="$reminder_run/initialize.log"
mysqld --no-defaults --datadir="$reminder_run/data" --socket="$reminder_run/mysql.sock" \
  --skip-networking --mysqlx=0 --skip-log-bin --pid-file="$reminder_run/mysql.pid" \
  --log-error="$reminder_run/mysql.log" --innodb-buffer-pool-size=128M
```

В іншому терміналі задайте локальне середовище. Під час приймання ці самі
значення зберігалися в приватному `$reminder_run/environment` та підключалися
кожним процесом; `.env` репозиторію не редагувався.

```sh
reminder_run=$(cat /tmp/planner-reminder-mail-current)
export APP_ENV=local APP_DEBUG=false APP_URL=http://localhost:18081
export FRONTEND_URL=http://localhost:15173
export APP_KEY="base64:$(openssl rand -base64 32)"
export APP_CONFIG_CACHE="$reminder_run/no-config.php" LARAVEL_STORAGE_PATH="$reminder_run/storage"
export DB_CONNECTION=mysql DB_SOCKET="$reminder_run/mysql.sock" DB_HOST=127.0.0.1
export DB_DATABASE=planner_reminder_email_acceptance DB_USERNAME=root DB_PASSWORD='' DB_URL=''
export QUEUE_CONNECTION=database DB_QUEUE_CONNECTION=mysql DB_QUEUE_TABLE=jobs DB_QUEUE=default
export CACHE_STORE=database SESSION_DRIVER=database SESSION_COOKIE=reminder_acceptance_session
export SESSION_DOMAIN=null SESSION_SECURE_COOKIE=false
export SANCTUM_STATEFUL_DOMAINS=localhost:15173 CORS_ALLOWED_ORIGINS=http://localhost:15173
export MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025 MAIL_SCHEME=null MAIL_URL=null
export MAIL_USERNAME='' MAIL_PASSWORD='' MAIL_FROM_ADDRESS=planner@reminder-acceptance.example.test
export MAIL_FROM_NAME='Student Planner' LOG_CHANNEL=single LOG_LEVEL=error
export AI_LIVE_SMOKE=0 AI_LIVE_ALLOW_PAID=0
MYSQL_PWD='' mysql --no-defaults --socket="$DB_SOCKET" -u root --batch --skip-column-names \
  -e 'SELECT @@datadir, VERSION(); CREATE DATABASE planner_reminder_email_acceptance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
php artisan migrate --no-interaction
php -S localhost:18081 -t public public/index.php
```

На порожній БД виконувалися всі чинні міграції, включно з єдиною новою
`2026_11_14_000001_add_reminder_email_preferences_to_users`. Не генеруйте новий
APP_KEY для worker: HTTP, dispatcher і worker мають успадкувати однакові env.

HTTP-помічник, використаний для ручних викликів (cookies тільки тимчасові):

```sh
curl -sS -c "$reminder_run/cookies" -H 'Origin: http://localhost:15173' \
  http://localhost:18081/sanctum/csrf-cookie
api() {
  csrf=$(python3 - "$reminder_run/cookies" <<'PY'
import sys, urllib.parse
for line in open(sys.argv[1]):
    fields = line.rstrip().split('\t')
    if len(fields) == 7 and fields[5] == 'XSRF-TOKEN':
        print(urllib.parse.unquote(fields[6]))
PY
)
  curl --max-time 10 -sS -b "$reminder_run/cookies" -c "$reminder_run/cookies" \
    -H 'Origin: http://localhost:15173' -H 'Accept: application/json' \
    -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $csrf" \
    -X "$1" --data-raw "${3:-'{}'}" -w '\nHTTP %{http_code}\n' "http://localhost:18081$2"
}
api POST /api/auth/register '{"name":"Ручне приймання пошти","email":"acceptance-rki9qfwv@example.test","password":"local-acceptance-password","password_confirmation":"local-acceptance-password","timezone":"Europe/Kyiv"}'
api GET /api/profile
api PATCH /api/profile '{"reminder_email_enabled":true}'
api PATCH /api/profile '{"reminder_email_enabled":"true"}'
```

Синтетичну адресу підтверджували чинним signed POST API без листа
підтвердження. Для цього в тому самому локальному середовищі генерували шлях
через наявний Service (у цьому порожньому прийманні user ID був 1):

```sh
php -d display_errors=0 -d log_errors=0 <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$url = $app->make(App\Services\Auth\EmailVerificationService::class)
    ->verificationUrl(App\Models\User::findOrFail(1));
parse_str(parse_url($url, PHP_URL_QUERY), $query);
file_put_contents(getenv('LARAVEL_STORAGE_PATH').'/../verification-path', $query['verification_path']);
PHP
api POST "$(cat "$reminder_run/verification-path")" '{}'
api PATCH /api/profile '{"reminder_email_enabled":true}'
```

Кожний reminder створювали через API з майбутнім UTC trigger (повторювати
вручну з потрібним повідомленням і станом профілю), чекали настання часу та
окремо запускали dispatcher/worker:

```sh
trigger=$(date -u -d '+2 seconds' '+%Y-%m-%dT%H:%M:%SZ')
api POST /api/reminders "{\"message\":\"Підготувати курсову роботу <b>сьогодні</b>\",\"trigger_at\":\"$trigger\"}"
php artisan reminders:dispatch-due --limit=100
php artisan reminders:dispatch-due --limit=100
php artisan queue:work reminder-email --queue=reminder-email --once --tries=3 --timeout=60 --no-ansi
api GET /api/notifications
MYSQL_PWD='' mysql --no-defaults --socket="$DB_SOCKET" -u root "$DB_DATABASE" \
  -e 'SELECT id,status,sent_at FROM reminders; SELECT reminder_id,COUNT(*) AS inbox FROM notifications GROUP BY reminder_id; SELECT id,queue,attempts FROM jobs;'
curl -sS --get --data-urlencode 'query=to:acceptance-rki9qfwv@example.test' \
  http://127.0.0.1:8025/api/v1/search
curl -sS --get --data-urlencode 'query=to:changed-rki9qfwv@example.test' \
  http://127.0.0.1:8025/api/v1/search
```

Для opt-out застосовували `api PATCH /api/profile '{"reminder_email_enabled":false}'`.
Для скасування вже поставленого листа між dispatcher і worker виконували
`api PATCH /api/profile '{"email":"changed-rki9qfwv@example.test"}'`, потім
повторювали генерацію signed path, POST підтвердження й opt-in. Аналогічно
перевірено повернення до попередньої адреси.

SMTP-відмову отримували override тільки процесу worker на закритий локальний
порт; Mailpit і чинна SMTP-конфігурація не змінювалися:

```sh
ss -H -ltn '( sport = :11026 )'
MAIL_PORT=11026 php artisan queue:work reminder-email --queue=reminder-email --once --tries=3 --timeout=60 --no-ansi
MYSQL_PWD='' mysql --no-defaults --socket="$DB_SOCKET" -u root "$DB_DATABASE" \
  -e 'SELECT attempts,available_at-UNIX_TIMESTAMP() AS retry_in_seconds FROM jobs;'
# Лише в цій disposable БД: прискорити наступну спробу вручну.
MYSQL_PWD='' mysql --no-defaults --socket="$DB_SOCKET" -u root "$DB_DATABASE" \
  -e "UPDATE jobs SET available_at=UNIX_TIMESTAMP() WHERE queue='reminder-email';"
MAIL_PORT=11026 php artisan queue:work reminder-email --queue=reminder-email --once --tries=3 --timeout=60 --no-ansi
# Повторити UPDATE та worker ще один раз, тоді перевірити:
MYSQL_PWD='' mysql --no-defaults --socket="$DB_SOCKET" -u root "$DB_DATABASE" \
  -e 'SELECT COUNT(*) AS queued_jobs FROM jobs; SELECT queue,COUNT(*) AS failed_jobs FROM failed_jobs GROUP BY queue; SELECT id,status,sent_at FROM reminders WHERE id=6; SELECT COUNT(*) AS inbox FROM notifications WHERE reminder_id=6;'
php artisan schedule:list --no-ansi
```

Додаткова діагностика справжнього worker під час першої SMTP-відмови:

```sh
MAIL_PORT=11026 php -d display_errors=0 -d log_errors=0 <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Event::listen(Illuminate\Mail\Events\MessageSending::class, function () {
    echo 'DB transaction level at MessageSending: ', Illuminate\Support\Facades\DB::transactionLevel(), PHP_EOL;
});
$exit = Illuminate\Support\Facades\Artisan::call('queue:work', [
    'connection'=>'reminder-email', '--queue'=>'reminder-email', '--once'=>true,
    '--tries'=>3, '--timeout'=>60, '--no-ansi'=>true,
]);
echo Illuminate\Support\Facades\Artisan::output(), 'Worker exit: ', $exit, PHP_EOL;
PHP
```

Перевірка невидимості незакоміченого job і rollback виконувалася перед
звичайною доставкою due reminder 7:

```sh
php -d display_errors=0 -d log_errors=0 <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$observer = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname='.getenv('DB_DATABASE'), 'root', '');
$db->beginTransaction();
try {
    $result = $app->make(App\Services\Reminders\ReminderDeliveryService::class)->processWithinTransaction(1, 7);
    echo json_encode([
        'inside'=>$result,
        'inbox'=>$db->table('notifications')->where('reminder_id',7)->count(),
        'jobs'=>$db->table('jobs')->where('queue','reminder-email')->count(),
        'other_connection_inbox'=>(int)$observer->query('SELECT COUNT(*) FROM notifications WHERE reminder_id=7')->fetchColumn(),
        'other_connection_jobs'=>(int)$observer->query("SELECT COUNT(*) FROM jobs WHERE queue='reminder-email'")->fetchColumn(),
    ]), PHP_EOL;
} finally {
    $db->rollBack();
}
echo json_encode(['after_rollback'=>[
    'status'=>$db->table('reminders')->where('id',7)->value('status'),
    'inbox'=>$db->table('notifications')->where('reminder_id',7)->count(),
    'jobs'=>$db->table('jobs')->where('queue','reminder-email')->count(),
]]), PHP_EOL;
PHP
```

Статична перевірка: `php -l` успішно пройшов для всіх десяти PHP-файлів зміни
(Request, Resource, User, ProfileService, ReminderDeliveryService,
ReminderEmailService, SendReminderEmail, StudyReminderMail, config/queue.php,
нова міграція); `git diff --check` також успішний. Це перевірка синтаксису,
а не запуск автотестів.

## Межі перевірки

Фактично перевірено основні локальні сценарії з таблиці. Реальний SMTP, AWS,
production migrations, systemd, постійний Scheduler і довготривалий worker не
запускалися. `schedule:list` лише підтвердив чинну команду щохвилини;
доставку перевіряли явним `reminders:dispatch-due`. Команди для постійного
локального Scheduler/worker і Lightsail наведено в [контракті](reminder-email.md).

Не відтворювали втрату відповіді після прийняття листа SMTP, падіння worker
після надсилання до ACK, процесне падіння після commit або конкурентну зміну
профілю у вікні після остаточного читання. SMTP не гарантує рівно один лист;
повтор невизначеної спроби може дати дублікат. Не проводили навантажувальний
чи конкурентний тест диспетчерів; ручна перевірка дублювання була послідовною.

Наявні автотести з точним JSON-переліком полів UserResource не оновлювалися
за обмеженням цієї задачі. Їх адаптація до нового поля та автоматизоване
покриття email залишаються окремою задачею; зеленого тестового набору тут
не заявлено. Коміту, staging і push немає.
