# Автоматична перевірка плану та індикатор уваги

Реалізація після Backend `6869028` / Frontend `0a2e368`. Allocator, правила
підтвердження, стани сесій і нагадування не переписано.

## Зміни й спільний запуск

`PlanningSourceObserver` зареєстрований у `AppServiceProvider` для availability,
preferences, SemesterSchedule, WeeklyLessonRule, ScheduleDateException,
ScheduleDateExceptionLesson, SemesterScheduleSuppressedSlot, AcademicPeriod,
Lesson, Task, Subtask, StudySession та зміни User.timezone. RecoverySuggestion,
scan-state, jobs і receipts не спостерігаються — зворотного циклу немає.

Покрито звичайні REST і AI Service-writers: створення/оновлення/видалення
доступності; PUT preferences; регулярні правила, підтверджений семестровий імпорт,
заміни на дату, робочі суботи; створення/зміни/скасування/видалення конкретних
Lessons; Task/Subtask deadlines, estimates та lifecycle; підтвердження нового
плану, перепланування й операції сесій. Import/management preview без save
не запускає аналіз. Оновлення похідного parent estimate також проходить writer.

Важлива межа: Eloquent mass update/delete і raw SQL не породжують model events.
Перевірені чинні writer-шляхи використовують model save/update/delete. Нові bulk
writers мають явно викликати спільний trigger у своїй owner transaction.
Зовнішні SQL-зміни й часові межі додатково виявляються Scheduler/fingerprint.

`RecoveryCheckTriggerService::request` у тій самій транзакції позначає owner dirty
через requested_version/requested_at. Один singleton об’єднує всі модельні зміни
одного власника в одну позначку й callback транзакції; afterRollBack звільняє
позначку, включно з rollback вкладеного savepoint. `afterCommit` відправляє один
job у наявну **database** queue. Аналіз у HTTP не виконується навіть при
QUEUE_CONNECTION=sync. Налаштування решти черг не змінено.

Durable owner lease/token у recovery_scan_states не дозволяє паралельні deliveries.
Кілька HTTP-редагувань оновлюють останню версію/час; job очікує три секунди тиші.
Під час очікування він release-иться з обмеженим часом доставки 10 хвилин,
backoff для transport failures 30 секунд, timeout 60 секунд, lease 120 секунд.
Старий token або повторна доставка після успіху — no-op. Старий прострочений lease
не є доказом працюючого аналізу. Помилка dispatch не відкочує збережені дані:
dirty state лишається надійною позначкою для резервного scan.

`AnalyzeOwnerPlan::handle` викликає тільки існуючий
`RecoverySuggestionService::detect(owner, 7)`. Його owner-first lock і спільні
source_hash/need_hash, unique active-owner/dedupe/refresh keys збережено. Під lock
перевіряється актуальний snapshot, а не стан на час dispatch. Зміна після аналізу
знову робить owner dirty; зміна перед ним потрапляє в останню версію. Повторна
перевірка source hash і часових меж в існуючому analysis лишається чинною.

Success записує checked_version/last_successful_at і очищає delivery/error state;
помилка залишає перевірку незавершеною, з окремою ознакою помилки. Sessions,
Task statuses, deadlines й actual minutes автоматичний job не змінює.

## Scheduler і локальні процеси

У `routes/console.php` без змін лишилися:

- `learning:detect-recovery --limit=10 --days=7` кожні п’ять хвилин, withoutOverlapping;
- `reminders:dispatch-due --limit=100` щохвилини, окремо від recovery.

Scan зберігає bounds/fairness і тепер також бачить dirty owners без tasks/sessions
(наприклад, після видалення останнього завдання). Це дозволяє завершити стан
перевірки навіть при втраті dispatch між commit та queue insert. Негайний trigger
не запускає глобальний scan. Черга відправляє лише ID конкретного власника/token.

Наявні caps RecoverySourceStateService не послаблено: 200 tasks, 500 subtasks,
1000 sessions, 2000 lessons; інші caps каталогу/semester/window/reminders та
MAX_REVIEW_BYTES теж збережено. Автоматичний горизонт — сім локальних дат;
великий акаунт дає явну помилку/limit, а не обрізані вхідні дані.

Після отримання коду в каталозі Backend застосуйте міграції звичайним workflow.
У перевіреній local БД цього виконання застосовано тільки:

```bash
php artisan migrate --path=database/migrations/2026_11_13_000002_add_recovery_check_delivery_state.php --no-interaction
```

Міграція додає delivery/status columns лише до recovery_scan_states, без зміни
business records чи старих міграцій. Rollback не знищує метадані: потрібна forward
compatibility migration. За deployment спочатку потрібна ця schema, потім writers.

Для локальної роботи тримайте **два окремі термінали**:

```bash
php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=60
```

```bash
php artisan schedule:work
```

На момент перевірки вже були запущені `queue:work database --tries=3` та
`schedule:work`. Не запускайте дублікати без потреби. Після оновлення коду
перезапустіть власний довготривалий worker (Ctrl+C, потім команда вище).
`queue:restart` означає graceful exit; без Supervisor/systemd процес треба
запустити знову. Якщо використано інше значення DB_QUEUE, передайте саме його
в --queue. Без worker негайний job чекатиме; Scheduler лишається резервом, а
хедер не стверджуватиме, що актуальність уже перевірено.

## Компактний API

`GET /api/planning/attention-status`, auth:sanctum, тільки request user.
Controller делегує PlanningAttentionStatusService; Resource має явний whitelist.
Жодного client owner ID, review, token, receipt, need/source hash чи матеріалів
планування в результаті немає.

```json
{
  "data": {
    "attention_count": 1,
    "most_important_kind": "replan",
    "counts": {"decision_required": 0, "replan": 1, "warning": 0, "create_plan": 0},
    "has_informational_recommendations": false,
    "check_state": "idle",
    "check_pending": false,
    "last_successful_check_at": "2026-11-13T01:42:21.359053Z",
    "check_failed": false,
    "unverified_suggestion_count": 0,
    "review_refresh_required": false
  }
}
```

Це приклад контракту, а не результат конкретного акаунта.

check_state: idle / pending / running / failed / not_checked. Останній означає,
що успішної перевірки ще не було; він не видається за «все гаразд». running
повертається лише для чинного lease. Стара пропозиція під час dirty/running/failed
не додається до актуальних counts; число непідтверджених записів виділене окремо.

Коли state чистий, сервіс використовує існуючу annotate/source fingerprint
перевірку строку й актуальності. Вибирає тільки маленькі колонки suggestion;
не завантажує review/token і не викликає allocation. Це один bounded freshness
snapshot на запит; додатковий detector у GET не створюється.

Порядок важливості: decision_required (результат минулих сесій), replan
(непридатні майбутні сесії), warning (ручний перегляд обмежень), create_plan
(необов’язкова рекомендація створення плану). Перші три входять в attention_count,
create_plan — окремо в has_informational_recommendations. Зараз unique owner slot
дозволяє максимум одну активну review, але форма counts явно розділяє категорії.

Existing recovery навмисно не перестворює незмінну expired/rejected review.
Цю deduplication/confirmation політику не змінено. Якщо після scan лишилася
expired/invalidated review тієї самої потреби, API повертає review_refresh_required=true,
а не неправдивий clear. Хедер нейтрально пропонує новий огляд і відкриває історію.
Відхилене користувачем незмінне рішення автоматично не воскресає.

## Глобальний Frontend

AppLayout містить PlanningAttentionIndicator поруч із bell. Link веде на
/planning/suggestions, для stale refresh — з `?status=all`. Категорії
перекладено українською; normal не займає місця, problem має бейдж, information
нейтральний, pending ненав’язливий, failed явно повідомляє неможливість перевірки.
Використано існуючі Manrope/palette/theme/icon styles, aria-label/title, focus-visible,
компактні 36px; у мобільному хедері немає довгого текстового блоку. Inbox не змінено.

Pinia planningAttention store має один timer і один активний запит: старт після
login/монтування authenticated layout, visibility refresh, 60 секунд у видимій
вкладці. Після успішної mutation спільний api/client сигналізує через маленький
planningMutation module. Store одразу ховає старий результат, об’єднує сигнали
за 300ms і виконує максимум три швидкі GET через 2 секунди, далі лише 60s polling.
Preview/GET/невдала валідація та звичайний Inbox не запускають цей сигнал.
POST/confirm ніколи не повторюється автоматично.

Logout, account change і unmount очищають стан/timers та abort-ять запит;
version + owner fence відкидає пізню відповідь іншого акаунта навіть якщо transport
ігнорує abort. Navigation усередині layout не додає pollers. Окремий 60s full-feed
polling PlanningView видалено. RecoverySuggestions показує попередження про
незавершену перевірку й підтримує прямий перехід до історії.

## Ручна перевірка на поточному DEMO G

Read-only звірка 2026-11-13 виявила, що користувач уже переніс стару source 49:

- owner `o`, User 10, Europe/Kyiv;
- Task 69: **[DEMO-PLANNING] G — Перегляд після зміни доступності**;
- source 49 rescheduled, актуальний successor **57 planned**;
- 57: **2026-11-15 16:15–17:15 Kyiv**;
- неділя window 28: **08:00–22:00**. Субота вже 08:00–19:15;
  max session уже 60, min 30, break 15. Ці значення тут не змінювалися.

Тест виконуйте до реального початку 57. Якщо стан уже інший, виберіть фактичну
future/planned сесію цього DEMO-task; не змінюйте час системи або статус SQL-ом.
За потреби підготуйте нову сесію звичайним preview/explicit confirm UI.

1. Увійдіть як `o`, переконайтесь, що worker і Scheduler працюють. Відкрийте
   `/planning/sessions/57` і перевірте фактичний стан/час.
2. Запишіть поточні значення неділі. На `/planning/availability` як явну дію
   змініть завершення неділі **22:00 → 16:00**, збережіть. Weekly зміна може
   зачепити й інші недільні сесії: цей detector перевіряє весь власний план.
3. Без переходу на «Мій план» спостерігайте хедер на будь-якій сторінці:
   спочатку «Перевіряємо план», потім проблема/replan. Зазвичай це кілька секунд
   за вільного worker; після трьох швидких reads залишається 60s polling.
4. Натисніть індикатор: відкриється «Потребує уваги». Перегляньте пояснення,
   source 57 та запропоновані successors. Якщо є інші невирішені минулі сесії,
   вищим пріоритетом буде decision_required; це окрема чесна причина, а не replan.
5. **Для перевірки автоматичного аналізу не підтверджуйте перенесення.** Сесія 57
   повинна залишатися planned у той самий час. Немає нових sessions/reminders.
6. Відновіть неділю до **22:00**, збережіть. На час нової перевірки старий badge
   не показується як гарантовано актуальний. Після аналізу зникне саме проблема
   G. Якщо інші tasks/DEMO D/E потребують уваги, може лишитися інша проблема або
   нейтральна рекомендація; повний clear для такого акаунта не обіцяється.
7. Спробуйте кілька послідовних збережень/перехід між сторінками, потім logout.
   Повинен бути один owner job і один status poller, без фонових запитів після logout.
   При timeout підтвердження спочатку явно перечитайте стан; POST не повторюйте.

## Фактично виконана перевірка

- 286 focused tests / 2659 assertions на **новому disposable MySQL** через
  scripts/testing/phpunit-disposable-mysql.sh. Commit/rollback/savepoint,
  coalescing, database queue serialization/fire, dedup, latest source generation,
  failure + Scheduler fallback, owner isolation, expiry, four review kinds,
  no automatic session writes, source import/rules/date/Saturday, unchanged
  Scheduler cadence/bounds та existing lifecycle/replanning regressions — пройшли.
  Додаткова фінальна регресія stale/invalidated refresh: 10 tests / 127 assertions — passed.
- Ширший Backend запуск: 750 tests, 701 passed / 49 failed. Шість assertions
  старого lifecycle snapshot очікували незмінності тепер навмисно змінених
  delivery tables; їх узгоджено з новим контрактом і перевірено у зеленому
  focused наборі. Решта **43** — старі очікування HTTP 201 для legacy import,
  який у незміненому початковому ScheduleImportController повертає 410.
  Цей endpoint і його тести тут не видаляли/не відкривали заново.
- Фінальний Frontend targeted run — 100 passed (включно з page-content, expired refresh і transport mutation).
  Повний запуск — **786 tests: 733 passed / 53 failed**. Усі 53 failing names
  збігаються зі збереженим baseline до змін; нових failures немає. Старе очікування
  формулювання «Без нового часу» узгоджено з вже реалізованим «Неперенесено».
- PHP syntax/Pint, npm run type-check, npm run build — успішні.
- У local student_planner застосовано тільки нову additive migration. Один
  контрольний owner-only trigger для точно перевіреного `o` фактично пройшов
  через уже запущений worker: requested_version=1 перевірено, lease звільнено,
  last_successful_at оновлено, check_state=idle. Цьому акаунту чесно повернуто
  review_refresh_required=true через попередню expired review, attention_count=0.
- Контрольні хеші всіх таблиць до/після цього job показали зміни **тільки**
  recovery_suggestions і recovery_scan_states. Сесії, розклад, налаштування,
  availability, tasks та дані інших власників не змінено цим аналізом.

Зміна неділі для G й браузерний сценарій кроків 1–7 тут **не виконувалися**.
Робочу БД не очищали, destructive tests на ній не запускали. Немає заяви про
повністю зелений legacy suite або виконаний live browser E2E.

## Основні файли

Backend: AppServiceProvider, PlanningSourceObserver, RecoveryCheckTriggerService,
AnalyzeOwnerPlan, RecoverySuggestionService (тільки scan/state metadata),
PlanningAttentionStatusService/Controller/Resource, routes/api.php, additive
migration, PlanningAttentionTest, PlanningSourceTriggersTest; актуалізовано
очікування delivery metadata у трьох існуючих recovery/lifecycle tests.

Frontend: api/client.ts, api/planningAttention.ts, utils/planningMutation.ts,
stores/planningAttention.ts, PlanningAttentionIndicator.vue, AppLayout.vue,
PlanningView.vue, RecoverySuggestionsView.vue, styles/planning.css;
planning-attention.test.ts, спільний f2Backend mock та page-content assertion.
