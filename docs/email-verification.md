# Добровільне підтвердження поточної email-адреси

Початковий стан: `/home/oleksiibashuk/CourseProject/student-planner-backend`,
`main`, `226e3ce`, порожній `git status --short`. Перевірено фактичні User,
UserResource, ProfileController, реєстрацію, Sanctum, поштову конфігурацію та
тести. До зміни окремого сервісу профілю не було: контролер виконував update.

## Межі та запис

Використано чинне `users.email_verified_at`, успадковані Laravel
`hasVerifiedEmail()` / `markEmailAsVerified()`, стандартні `VerifyEmail`,
`Verified` і `URL::temporarySignedRoute()`. Міграцій, власної таблиці токенів,
автоматичного підтвердження наявних записів або листа після реєстрації немає.
`User` реалізує `MustVerifyEmail`; middleware `verified` ніде не додається.
Непідтверджені користувачі зберігають чинний доступ до застосунку.

`ProfileService::update()` і `EmailVerificationService::confirm()` виконують
транзакцію та `SELECT … FOR UPDATE` на тому самому User. Справжня зміна значення
email скидає `email_verified_at` у тому самому save. Незмінний email, ім'я,
часовий пояс та порожній PATCH не скидають підтвердження. Під час підтвердження
після отримання блокування повторно перевіряються підпис, строк, ID поточного
автентифікованого акаунта й SHA-1 його **актуального** email. Старе посилання
не підтверджує змінену адресу; застаріла модель із сесії не є джерелом email.

Перший успішний POST викликає стандартний mark і одну подію `Verified`.
Повтор із чинним посиланням повертає 200, не переписує timestamp і не повторює
подію. Після завершення строку посилання повертає 403 навіть для підтвердженої
адреси; стан не змінюється.

Реєстрація та вхід зберігають чинну сесійну поведінку. Спільний UserResource
додатково повертає boolean `email_verified` у профілі, реєстрації, вході та
відповіді підтвердження. Лише автентифікований endpoint запиту листа запускає
надсилання на поточну адресу, перечитану з БД. Використовується синхронний
notification mail channel; 200 означає успішний виклик транспорту, а не гарантію
доставки у скриньку. Поштові нагадування, «Вхідні», AI та Frontend не змінені.

## Маршрути та JSON

Усі POST нижче використовують чинні Sanctum cookies і CSRF для SPA, з
`Accept: application/json`. Немає підтвердження простим GET.

### Запит / повторне надсилання

```http
POST /api/profile/email/verification-notification
Content-Type: application/json
X-XSRF-TOKEN: <чинний CSRF token>

{}
```

HTTP 200:

```json
{"message":"Лист для підтвердження email-адреси надіслано."}
```

Для вже підтвердженої адреси — також HTTP 200, без нового листа:

```json
{"message":"Email-адресу вже підтверджено."}
```

Параметр `email` заборонений: непорожня адреса з тіла дає HTTP 422 з
`message` / `errors.email` українською. ID з тіла не вибирає акаунт. Адреса
береться виключно з профілю на сервері.

Ліміт `email-verification-send`: 1 запит/хвилину та 6 запитів/годину на
автентифікований акаунт; рахуються також невалідні запити, що дійшли до throttle.
Ліміти різних акаунтів незалежні.

### Підтвердження

```http
POST /api/profile/email/verify/12/<sha1-поточного-email>?expires=<unix-seconds>&signature=<hmac>
Content-Type: application/json
X-XSRF-TOKEN: <чинний CSRF token>

{}
```

HTTP 200, включно з повтором чинного успішного посилання:

```json
{"data":{"id":12,"name":"Студент","email":"student@example.test","email_verified":true,"timezone":"Europe/Kyiv"},"message":"Email-адресу підтверджено."}
```

`GET /api/profile` повертає той самий `data` без повідомлення. PATCH нового email
повертає `data.email_verified=false`. Timestamp і секрети в UserResource не
додаються. Ліміт `email-verification-confirm`: 6 спроб/хвилину на акаунт,
включно з невалідними підписами та повторами.

| Статус | Значення |
| --- | --- |
| 200 | Лист викликано / адреса вже підтверджена / успішний POST, залежно від маршруту |
| 401 | Потрібно увійти в акаунт |
| 403 | Підпис змінено/відсутній, посилання прострочене, чужий акаунт або email уже змінився |
| 405 | GET/HEAD не підтримуються маршрутом підтвердження; запису немає |
| 419 | Відсутній або застарілий CSRF state для SPA |
| 422 | Заборонений email у запиті листа |
| 429 | Перевищено ліміт; збережено `Retry-After` та rate headers |
| 503 | Поштова/серверна конфігурація або операція тимчасово недоступна |

Нові повідомлення українською. Винятки SMTP, SQL, stack trace, підпис та секрети
не повертаються навіть за `APP_DEBUG=true`; журнал отримує лише фіксоване
`email_verification.failure` без контексту винятку.

## Посилання й контракт незалежного Vue

Лист українською містить HTML і plain text з посиланням на довірений
`FRONTEND_URL`, наприклад:

```text
https://planner.example.test/verify-email?verification_path=%2Fapi%2Fprofile%2Femail%2Fverify%2F12%2F<hash>%3Fexpires%3D<unix>%26signature%3D<hmac>
```

Це лише ілюстрація кодування; справжні hash, expires та signature генерує сервер.
Вкладений `verification_path` — відносний шлях Backend, підписаний Laravel з
`absolute:false`. Маршрут перевіряє `signed:relative`; зміна host між Vue та
Backend не вимагає зміни підпису. TTL — 60 хвилин (`auth.verification.expire`).
Підпис охоплює точний шлях і query разом із expires. Параметрів ігнорування немає.
Лист не містить незахешованого email у URL. Ідентифікатор і hash не є логіном.

Потрібна майбутня сторінка Vue `/verify-email`:

1. Взяти `new URL(window.location.href).searchParams.get('verification_path')`:
   це рівно одне декодування зовнішнього параметра. Не перекодовувати повторно,
   не сортувати й не додавати query-параметри до вкладеного шляху.
2. Не виконувати POST автоматично при відкритті/mount. GET, сканер листів і
   попередній перегляд не повинні запускати підтвердження.
3. Показати кнопку підтвердження. Якщо користувач не увійшов — провести звичайний
   вхід і зберегти шлях для повернення. Посилання не передає Sanctum-сесію.
4. Лише після явного натискання виконати POST `{}` на налаштований API origin
   плюс вкладений шлях, із cookies та `X-XSRF-TOKEN`. API origin не повинен уже
   містити `/api`. Перед викликом дозволити тільки локальний шлях
   `/api/profile/email/verify/{numeric-id}/{40-hex-hash}` з query, без зовнішнього
   origin чи `//`; не використовувати параметр як довільний redirect/URL.
5. Для 200 оновити профіль; для 401 попросити ввійти в правильний акаунт; для 403
   запропонувати новий лист; для 429 врахувати `Retry-After`.

`FRONTEND_URL` має бути origin HTTP/HTTPS без credentials, query, fragment або
додаткового path; у production — HTTPS. Некоректна конфігурація відхиляється до
надсилання. Не логувати повні verification URLs. Сторінку Vue не реалізовано
цим Backend-комітом; роботу переходу з листа в реальному браузері не підтверджено.

## Тести та локальна пошта

Нові feature-тести використовують Notification fake або in-memory array mailer;
реальні адреси не отримують листів. Перевіряються рендеринг обох частин листа,
перенесення підпису із Vue wrapper до POST, власність, TTL, підробки, CSRF,
ліміти, повтор, профіль та незмінність даних при відмовах.

Два concurrency fixtures запускають незалежний PHP/MySQL-процес і перевіряють
`performance_schema.data_lock_waits`: конкурентний writer справді очікує User
lock. Для порядків email → verify та verify → email кінцевий новий email
залишається непідтвердженим. Worker має guard ізольованої тестової БД.

Локально `.env` вже містить SMTP `127.0.0.1:1025` (секрети не виводилися й не
змінювалися); Mailpit/compose у репозиторії не знайдено. Фактичні перевірки:

```bash
ss -H -ltn '( sport = :1025 or sport = :8025 )'
# EXIT 0, output empty: listeners absent
command -v mailpit
# executable absent
curl --noproxy '*' --max-time 2 -sS http://127.0.0.1:8025/api/v1/info
# EXIT 7: Failed to connect to 127.0.0.1 port 8025 … Could not connect to server
```

Отримання листа через живий локальний Mailpit + виконання POST за прочитаним із
нього посиланням **не перевірено**. Після запуску локального Mailpit перевіряти
це лише на окремому тестовому акаунті й ізольованій схемі. Увійти через звичайний
CSRF/Sanctum потік, виконати POST запиту листа вище, відкрити Mailpit
`http://127.0.0.1:8025`, декодувати verification_path з отриманого листа й
виконати автентифікований POST `{}` на Backend. GET профілю має повернути true;
GET самого шляху — 405 без підтвердження. Не використовувати робочу БД.

## Фактичні прогони

Усі DB-прогони використовували чинний disposable runner: новий datadir,
перевірена ідентичність MySQL та випадкова `student_planner_isolated_*` схема.
Робоча БД, живий LLM та зовнішній SMTP не використовувалися.

Фінальний профіль без фільтрів:

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/EmailVerificationTest.php tests/Feature/AuthProfileTest.php \
  tests/Feature/AuthRateLimitTest.php tests/Feature/PasswordManagementTest.php \
  tests/Feature/AiChatTest.php tests/Feature/AiWorkCompletionPrivacyTest.php \
  tests/Feature/NotificationInboxApiTest.php
# PASS: 185 tests, 2814 assertions, 44237 ms
```

Включено весь поточний EmailVerificationTest: 26 випадків, зокрема concurrency
в обох порядках. Раніший цільовий прогін
`scripts/testing/phpunit-disposable-mysql.sh tests/Feature/EmailVerificationTest.php`
пройшов до додавання останньої перевірки confirm throttle: 25 тестів,
289 assertions, 6010 ms. До concurrency-набору він мав 23 успішні тести,
265 assertions, 4969 ms.

Розширений прогін також включав чинну доставку нагадувань:

```bash
scripts/testing/phpunit-disposable-mysql.sh \
  tests/Feature/EmailVerificationTest.php tests/Feature/AuthProfileTest.php \
  tests/Feature/AuthRateLimitTest.php tests/Feature/PasswordManagementTest.php \
  tests/Feature/AiChatTest.php tests/Feature/AiWorkCompletionPrivacyTest.php \
  tests/Feature/NotificationInboxApiTest.php tests/Feature/ReminderDeliveryTest.php
# EXIT 1: 234 tests, 233 passed, 1 failure, 3360 assertions, 52867 ms
```

Залишковий сторонній збій:
`ReminderDeliveryTest::test_missed_parent_keeps_task_child_and_session_reminders_eligible_and_preserves_sent_inbox_on_completion`,
рядок 103: тест виконує ручний Task complete без completion_token, очікує 200,
отримує чинний 409 для майбутньої сесії. Reminder/Task Services і цей тест не
редагувалися. Той самий збій відтворено на початковому `226e3ce` у тимчасовій
копії з власною копією Composer vendor (щоб class maps вказували на baseline),
без перемикання гілки або зміни робочих файлів:

```bash
python3 - <<'PY'
import os
import pathlib
import subprocess
import tempfile
with tempfile.TemporaryDirectory(prefix='planner-email-baseline.') as baseline:
    archive = subprocess.check_output(['git', 'archive', '226e3ce'])
    subprocess.run(['tar', '-x', '-C', baseline], input=archive, check=True)
    subprocess.run(['cp', '-a', '--reflink=auto', 'vendor', str(pathlib.Path(baseline) / 'vendor')], check=True)
    env = dict(os.environ, PLANNER_TEST_PORT='13381', APP_BASE_PATH=baseline)
    result = subprocess.run([str(pathlib.Path(baseline) / 'scripts/testing/phpunit-disposable-mysql.sh'), 'tests/Feature/ReminderDeliveryTest.php'], env=env)
    raise SystemExit(result.returncode)
PY
# EXIT 1: 49 tests, 48 passed, same 1 failure, 546 assertions, 10092 ms
```

Перший прогін чотирьох auth/profile-файлів мав 83 тести, 71 успішний і 12
невдалих нових fixtures: snapshot порівнював непрочитані з БД defaults та
порядок атрибутів, а повторні actingAs у тесті зберігали cached Sanctum user.
Fixtures тепер перечитують persisted snapshot і відтворюють новий guard між
акаунтами/читаннями; перевірки незмінності всіх атрибутів і лімітів збережені.
Ще одна команда завершилася до запуску тестів із EXIT 2 через помилковий шлях
`tests/Feature/NotificationInboxTest.php`; замінено на фактичний
`NotificationInboxApiTest.php` у наведених командах.

Форматування 14 змінених PHP-файлів і diff:

```bash
vendor/bin/pint --test app/Models/User.php app/Http/Resources/UserResource.php \
  app/Services/Auth/ProfileService.php app/Services/Auth/EmailVerificationService.php \
  app/Http/Controllers/Api/ProfileController.php \
  app/Http/Controllers/Api/EmailVerificationController.php \
  app/Http/Requests/Auth/EmailVerificationNotificationRequest.php \
  app/Providers/AppServiceProvider.php config/auth.php routes/api.php bootstrap/app.php \
  tests/Feature/AuthProfileTest.php tests/Feature/EmailVerificationTest.php \
  tests/Support/email_verification_worker.php
# PASS
git diff --check
# EXIT 0
```

Повний репозиторний набір не запускався; вся розширена регресія не є зеленою
через підтверджений baseline-збій вище. Mailpit і Frontend/browser залишаються
неперевіреними; in-memory рендеринг і API-підтвердження перевірені.

## Змінені файли

- `app/Models/User.php`;
- `app/Http/Resources/UserResource.php`;
- `app/Http/Controllers/Api/ProfileController.php`;
- `app/Http/Controllers/Api/EmailVerificationController.php`;
- `app/Http/Requests/Auth/EmailVerificationNotificationRequest.php`;
- `app/Services/Auth/ProfileService.php`;
- `app/Services/Auth/EmailVerificationService.php`;
- `app/Providers/AppServiceProvider.php`;
- `bootstrap/app.php`;
- `config/auth.php`;
- `routes/api.php`;
- `resources/views/mail/verify-email.blade.php`;
- `resources/views/mail/verify-email-text.blade.php`;
- `tests/Feature/AuthProfileTest.php`;
- `tests/Feature/EmailVerificationTest.php`;
- `tests/Support/email_verification_worker.php`;
- `docs/architecture.md`;
- `docs/domain-model.md`;
- `docs/auth-profile.md`;
- `docs/email-verification.md`.
