# Stage 16A — Task unfinished status: контракт для Frontend Stage 16B

## Стани та семантика

Task має `status: "pending" | "missed" | "completed"`.

- `pending` — активна невиконана робота.
- `missed` — активна невиконана робота, яку студент явно позначив як не виконану за очікуванням. Не виводиться автоматично з deadline або стану StudySession.
- `completed` — завершена робота з UTC `completed_at`.

Subtask залишається `pending | completed`. Task і Subtask completion незалежні; завершені Subtasks ніколи автоматично не відкриваються через `/miss` або `/reopen` батька. StudySession має власний lifecycle; Task `missed` не означає, що його sessions треба зробити missed або перенести.

## REST endpoints

Усі routes потребують `auth:sanctum`, існуючої SPA session/CSRF конфігурації або іншої підтримуваної Sanctum авторизації. Owner береться тільки з authenticated user. Чужі, відсутні й soft-deleted Task недоступні.

| Метод | Endpoint | Успіх | Призначення |
| --- | --- | --- | --- |
| POST | `/api/tasks/{task}/miss` | 200, Task Resource | Явно позначити незавершеним |
| POST | `/api/tasks/{task}/reopen` | 200, Task Resource | Повернути completed або missed у pending |
| POST | `/api/tasks/{task}/complete` | 200, Task Resource | Завершити pending або missed |
| GET | `/api/tasks/{task}` | 200, Task Resource | Поточний стан |
| GET | `/api/tasks?status=missed` | 200, paginated Task Resources | Точний фільтр missed; без зміни pagination/sort |
| POST | `/api/tasks` | 201, Task Resource | Як раніше: створює pending |
| PATCH | `/api/tasks/{task}` | 200, Task Resource | Як раніше: лише editable поля, без status mutation |
| POST | `/api/planning/recovery/preview` | 200, Recovery Resource | Чинний read-only recovery preview |
| POST | `/api/planning/study-plans/confirm` | 201, Persisted Plan Resource | Чинне explicit підтвердження плану |

Lifecycle request body — порожній JSON object `{}` або відсутній body, як у чинних complete/reopen. Додаткові поля ігноруються, не стають writable. Нових input полів немає, тому `/miss` наслідує existing Request + Policy pattern без нового Form Request. Validation listing/редагування залишається у чинних Form Requests.

Приклад:

```http
POST /api/tasks/42/miss
Accept: application/json
Content-Type: application/json

{}
```

Точний JSON успішної відповіді для репрезентативного Task:

```json
{
  "data": {
    "id": 42,
    "subject_id": 7,
    "title": "Підготувати звіт",
    "description": null,
    "status": "missed",
    "priority": 3,
    "estimated_minutes": 90,
    "deadline_at": "2026-11-15T18:00:00Z",
    "completed_at": null
  }
}
```

Жодне чинне поле Task Resource не видалено й не перейменовано.

| Поле Task | Тип / обмеження |
| --- | --- |
| `id` | positive integer |
| `subject_id` | positive integer або null; чинне ownership masking |
| `title` | string, максимум 255 символів |
| `description` | string або null |
| `status` | string enum: pending, completed, missed |
| `priority` | integer enum: 1, 2, 3 |
| `estimated_minutes` | integer 1–65535 або null |
| `deadline_at` | UTC string `YYYY-MM-DDTHH:mm:ssZ` або null |
| `completed_at` | UTC string того ж формату тільки для completed; для pending/missed null |

`GET /api/tasks` зберігає `data`, Laravel `links`, `meta`, сортування deadline/ID і per_page 1–100. Значення status фільтра — точне lowercase enum; invalid value дає 422. Фільтр `pending` більше не можна трактувати як «усі unfinished»: для обох станів потрібні окремі точні queries або відображення нефільтрованої колекції; нового OR/filter endpoint немає.

POST/PATCH Task продовжують ігнорувати `status`, `completed_at`, `user_id` та інші неeditable поля. Наприклад, PATCH `{"status":"missed"}` не змінює pending Task. Для зміни lifecycle потрібно викликати відповідний endpoint.

## Матриця переходів та ідемпотентність

| Поточний стан | `/miss` | `/complete` | `/reopen` |
| --- | --- | --- | --- |
| pending | missed, 200 | completed, 200 | pending, 200 без UPDATE |
| missed | missed, 200 без UPDATE | completed, 200 | pending, 200 |
| completed | 409, без зміни | completed, 200 без UPDATE | pending, 200 |

Повтор запиту до вже досягнутого lifecycle target не змінює `updated_at` і completion time та не виконує UPDATE. Completion використовує фактичний поточний UTC час із точністю до секунди, включно із запізнілим завершенням. Reopen очищує completion time. Missed не має `missed_at`, історії переходів або нового timestamp.

Всі три операції використовують чинний TaskService transaction: lock User → reload/lock owned active Task → перевірка актуального стану → write. Переданий stale/forged model не обходить ownership або persisted status. Якщо completed Task треба явно зробити missed, студент спочатку має виконати `/reopen`, потім `/miss`; не виконувати цей ланцюжок автоматично за помилкою 409.

## Помилки

| HTTP | Умова | Контракт |
| --- | --- | --- |
| 401 | Немає authenticated session | Чинна Laravel authentication error |
| 404 | Чужий, відсутній або soft-deleted Task | Чинне ownership hiding; не розрізняти ці випадки в UI |
| 409 | Спроба completed → missed | Точний JSON нижче; жодної зміни роботи/нагадувань/сесій |
| 419 | Неправильний/відсутній SPA CSRF | Чинна Sanctum/Laravel поведінка |
| 422 | Invalid list/edit/planning parameters | Чинний Laravel `message` + `errors` за полями |
| 500 | Відмова persistence/dependency write | Існуюча sanitized Task dependency error; transaction rollback |

409 повертає тільки цей JSON, включно з `APP_DEBUG=true`:

```json
{
  "message": "A completed task must be reopened before it can be marked missed."
}
```

Окреме поле machine `code` для цього нового Task conflict не додається: status 409 + `message` відповідають наявним REST conflict conventions. AI actions зберігають власні існуючі error codes, наприклад `action_stale`.

## MySQL та rollout

Міграція: `2026_11_08_000001_add_missed_task_status.php`. Змінює лише:

```sql
CHECK (status IN ('pending', 'completed', 'missed'))
CHECK ((status = 'completed' AND completed_at IS NOT NULL)
    OR (status IN ('pending', 'missed') AND completed_at IS NULL))
```

Обидва CHECK замінюються **одним** atomic MySQL ALTER; немає проміжку без constraints або rewrite бізнес-рядків. Bounded VARCHAR, default pending, FK, IDs, indexes, priority/estimate CHECKs та Subtask constraints збережено. Shared PHP enum cast не розширює Subtask persistence/API vocabulary: MySQL продовжує відхиляти Subtask missed.

Міграція forward-only. `down()` завжди кидає RuntimeException **до будь-якого SQL**, за existing Stage 11 convention: жодного видалення missed records, перетворення їх на pending або тимчасового зняття checks. MySQL DDL implicit commits не дозволяють покладатися на application transaction для багатокрокового downgrade. Для зміни політики потрібна нова forward migration.

У retained/local database міграція цим milestone **не запускалася**. Deployment operator має застосувати reviewed migration до свого дозволеного середовища перед використанням нового status. Після появи missed rows старий application enum несумісний; не виконувати code-only rollback на попередню версію. Оригінальні migrations не редагувалися.

## Planning та recovery

`missed` і `pending` — одна категорія unfinished для eligibility, але різні persisted states. Existing PlanningWorkSelectionService вже відкидає completed parents/children, тому окремий remaining-effort algorithm або planner не додається.

Чинний recovery request без змін:

```http
POST /api/planning/recovery/preview
Content-Type: application/json

{"start_date":"2026-11-09","end_date":"2026-11-15","task_id":42,"subtask_id":null}
```

Точні response schemas recovery/token/summary залишаються у [Stage 14A contract](../stage14/recovery-api-contract.md). Task missed не додає нових response полів або synthetic missed StudySession. Recovery history summary рахує **StudySessions**, не Task statuses: missed Task без missed sessions може мати `missed_session_count=0` і водночас actionable план.

- Completed StudySession actual-first credit збережено; unknown actual time використовує існуюче planning правило, не новий вигаданий кредит.
- Валідні майбутні planned reservations віднімаються від remaining work, включно з reservations поза обраним horizon, згідно з чинним Service.
- Missed sessions не кредитуються й не резервують майбутній час; історія не змінюється.
- Stale past planned sessions мають окремі diagnostics; їх не роблять missed автоматично.
- Expired deadlines, missing estimate, already satisfied estimate, fully reserved work та capacity constraints зберігають чинні reason codes й exclusions.
- Active Subtasks залишаються authoritative work units. Estimate Task і Subtasks не підсумовуються.
- Preview read-only, period ≤31 local dates, persisted timezone, future-only allocation, breaks/priorities/limits/deadlines чинні.
- Лише explicit confirm із reviewed encrypted preview_token створює sessions; partial plan потребує `accept_partial: true`. Current state/conflicts/deadlines revalidate transactionally; failed/replayed confirmation не створює partial writes.

```http
POST /api/planning/study-plans/confirm
Content-Type: application/json

{"preview_token":"<отриманий server token>","accept_partial":false}
```

Використовувати тільки справжній token із preview, не формувати його на Frontend. Нового confirmation endpoint, автоматичної relocation або background scheduling немає.

## Reminders та Inbox

Mark missed не змінює scheduled/sent/cancelled Reminder і не створює notifications. Parent eligibility тепер допускає pending **або missed**; child усе ще має бути pending, session — planned із відповідними чинними future/delivery checks. Ownership, attribution і soft-delete guards збережено.

Для missed Task дозволені чинні absolute/relative reminders, deadline synchronization та local delivery. Missing/deleted/completed targets, завершені children або retired sessions лишаються ineligible. Завершення missed Task скасовує ті ж scheduled Task/descendant/session reminders, що й завершення pending. Soft deletion зберігає чинний cleanup. Reopen не відновлює cancelled reminders. Sent Reminder і вже persisted/read Inbox history залишаються недоторканими.

## Статистика та сумісність

`GET /api/statistics/progress?start_date=...&end_date=...` додає **лише для tasks.current**:

- `missed: integer >= 0` — active owned Tasks з точним status missed.
- `overdue_missed: integer >= 0` — із них зі своїм ненульовим `deadline_at < as_of_utc`.

Приклад точного `tasks` section (інші sections progress response незмінні):

```json
{
  "tasks": {
    "current": {
      "total": 6,
      "pending": 1,
      "completed": 1,
      "overdue_pending": 1,
      "missed": 4,
      "overdue_missed": 1,
      "completion_percent": 16.7
    },
    "completed_in_period": {
      "total": 0,
      "on_time": 0,
      "late": 0,
      "without_deadline": 0
    }
  }
}
```

`total = pending + missed + completed`. `completion_percent = round(completed / total × 100, 1)`, null якщо total=0. На порожній вибірці обидва нові counters дорівнюють 0. `pending` і `overdue_pending` як раніше рахують **точний pending**, не missed. Після pending → missed їхні counts можуть зменшитися; відповідні missed counts зростають, total і completion percent не змінюються. Усі unfinished = pending + missed; усі overdue unfinished = overdue_pending + overdue_missed.

Це current state, не відновлений historical status; reporting period не фільтрує знаменник. Foreign/soft-deleted Tasks виключені. Subtask counters зберігають стару форму без missed. Completion-period metrics, daily trends, comparisons і subject workloads не отримують вигаданих missed events; фактичні StudySession статуси та час залишаються окремими.

## AI boundary і Stage 16B

27 Tools та pending-action vocabulary збережено. Existing list_tasks schema автоматично допускає missed. Existing confirmed Task complete/reopen actions працюють і для missed; update не отримує writable status. Raw Task fingerprint включає status: зміна переглянутого Task на missed робить стару proposal stale; запис/receipt не виконуються. Receipt replay зберігає початковий immutable outcome.

Щоб у майбутньому підтримати «познач невиконаним» через natural language, потрібен окремо погоджений thin proposal Tool/action, schema/registry/confirmation dispatch, consent/preview/receipt policy, additive allowed-action CHECK migration і security/idempotency tests. Stage 16A їх не додає і не маршрутизує запит через довільний PATCH.

Stage 16B має:

1. Розширити **тільки Task** status union, labels, filters і status presentation; Subtask union залишити старим.
2. Додати explicit `/miss` action, показати 409 з повторним читанням актуального стану; не auto-reopen completed.
3. Дозволити complete/reopen для missed і відображати серверну Resource після відповіді.
4. Запускати чинний recovery preview для missed, показувати diagnostics і вимагати explicit confirm/partial acceptance.
5. Додати нові statistics counters, відокремити pending від усіх unfinished; не включати completion_percent у integer-count iteration.
6. Не трактувати missed Task як missed StudySession, не змінювати children, reminders або persisted sessions локально.

Рекомендовані українські labels: pending — «Очікує виконання», missed — «Не виконано», completed — «Виконано»; overdue_missed — «Прострочені невиконані». Frontend у Stage 16A не переглядався й не змінювався.
