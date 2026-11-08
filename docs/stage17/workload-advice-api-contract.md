# Stage 17A — Workload Advice API / контракт для Stage 17B

## Endpoint та validation

```http
GET /api/planning/workload-advice?start_date=2026-11-09&end_date=2026-11-15
Accept: application/json
```

Route name: `planning.workload-advice`. Потрібен чинний `auth:sanctum` user. Власник береться лише з authentication; endpoint не приймає user_id, task_id, subtask_id або timezone.

Query має рівно два required string поля: `start_date`, `end_date`. Формат — точний `YYYY-MM-DD`, справжні календарні дати, end ≥ start, максимум **31 включна локальна календарна дата**. Whitespace не обрізається до validation. Unknown query fields дають 422. JSON/form body не постачає й не перекриває query. Request повторно використовує `StudySessionRangeRequest` та чинні planning bounds; UTC query boundaries також повинні бути представимими у MySQL DATETIME, як у наявного planner.

Застосовується **persisted IANA timezone** user, а не timezone браузера чи stale caller model. Стандартні Sanctum/CORS/session правила залишаються чинними. Нових permissions або confirmation endpoint немає.

## Точна форма відповіді

HTTP 200, стандартний `data` Resource envelope. Усі наведені top-level keys присутні завжди:

```text
data: {
  as_of_utc: string (UTC YYYY-MM-DDTHH:mm:ss.uuuuuuZ),
  timezone: string (persisted IANA name),
  period: { start_date: string, end_date: string },
  summary: {
    requested_seconds: integer >= 0,
    allocated_seconds: integer >= 0,
    unscheduled_seconds: integer >= 0,
    fully_allocated: boolean
  },
  alerts: Alert[],
  work_advice: WorkAdvice[],
  excluded_items: ExcludedItem[],
  warnings: PlanningWarning[],
  persisted_workload_violations: PersistedViolation[]
}
```

`summary` та clock копіюються без зміни з `StudyPlanningService::plan()`. Requested — eligible remaining effort **після completed credit і valid future reservations**, а не сума всіх початкових estimates. `requested = allocated + unscheduled`; fully_allocated означає unscheduled=0 **лише для eligible work**. Excluded work не входить у ці seconds. Це може бути true за наявності missing estimates, expired/completed work або вже зарезервованої роботи.

```text
Alert: {
  code: string,
  severity: "warning" | "info",
  source: "allocation" | "selection" | "persisted_workload",
  affected_seconds: integer >= 0 | null,
  affected_work_count: integer >= 0
}

WorkAdvice: {
  task_id: positive integer,
  subtask_id: positive integer | null,
  requested_seconds: integer >= 0,
  allocated_seconds: integer >= 0,
  unscheduled_seconds: integer >= 0,
  reason_codes: string[],
  rank: positive integer,
  recommendation_codes: string[]
}

ExcludedItem: {
  task_id: positive integer,
  subtask_id: positive integer | null,
  work_type: "task" | "subtask",
  effective_deadline_utc: integer Unix UTC seconds | null,
  priority: 1 | 2 | 3,
  position: positive integer | null,
  effort: Effort | null,
  reason_codes: string[],
  recommendation_codes: string[]
}

Effort: {
  estimated_seconds: integer >= 0 | null,
  completed_credit_seconds: integer >= 0,
  remaining_work_seconds: integer >= 0 | null,
  future_reserved_seconds: integer >= 0,
  unallocated_work_seconds: integer >= 0 | null,
  over_reserved_seconds: integer >= 0 | null
}

PlanningWarning: {
  code: string,
  task_id: positive integer,
  subtask_id: positive integer | null,
  study_session_id: positive integer | null,
  seconds: integer >= 0
}

PersistedViolation: {
  code: "daily_study_limit_exceeded" | "weekly_study_limit_exceeded",
  period_kind: "day" | "iso_week",
  period: string (YYYY-MM-DD or ISO week-year/week, e.g. 2026-01),
  scheduled_seconds: integer >= 0,
  limit_seconds: positive integer,
  excess_seconds: positive integer
}
```

`effective_deadline_utc` в exclusions зберігає чинний Stage A integer формат, включно з можливими від'ємними timestamps; це не date string. `effort=null` можливе для виключеного parent context із authoritative Subtasks. Додані recommendation codes не змінюють жодного чинного поля/reason code. В `work_advice` немає Title, priority або deadline metadata, яких немає у planner work_results; вони не вигадуються й не перечитуються з іншого snapshot.

У відповіді **немає sessions, preview_token, expires_at або persistence receipt**. Endpoint не пропонує нової confirmation flow.

## Alerts: свідчення та їхні межі

| Code | Source | Значення |
| --- | --- | --- |
| `unscheduled_work` | allocation | Є eligible seconds, не розміщені поточним greedy запуском |
| `no_available_study_time` | allocation | Planner не знайшов suitable remaining study time; це також може бути результатом зайнятих слотів або deadline clipping, не лише відсутніх availability windows |
| `deadline_capacity_exhausted` | allocation | Existing search повідомив про deadline-clipped capacity; deadline залишається обов'язковим |
| `daily_study_limit_exceeded` | allocation | Existing constraint analysis відхилив кандидат із урахуванням persisted + projected workload; це **не твердження**, що збережений розклад перевантажений |
| `weekly_study_limit_exceeded` | allocation | Таке саме свідчення щодо ISO-week limit |
| `below_minimum_session_duration` | allocation | Remaining effort/available candidate не відповідає minimum duration; не підміняється загальним capacity deficit |
| `above_maximum_session_duration` | allocation | Лише якщо такий reason повернув existing planner |
| `outside_available_study_time` | allocation | Лише відповідний existing containment reason |
| `overlapping_break_anchor`, `insufficient_break_before`, `insufficient_break_after` | allocation | Existing break reasons, без припущень про конфлікт конкретних Tasks |
| `deadline_exceeded` | allocation | Existing candidate deadline reason, якщо повернутий |
| `planning_horizon_exhausted` | allocation | Bounded greedy search завершився з залишком; це не означає, що календарний час уже минув або не існує іншого розміщення |
| `excluded_work` | selection | Є виключені diagnostic entries; severity info, seconds=null, count=кількість entries, не обов'язково кількість різних Tasks |
| `daily_study_limit_exceeded`, `weekly_study_limit_exceeded` | persisted_workload | **Окремо виміряне** перевищення збереженого scheduled workload над configured limit; конкретні numbers/period у persisted_workload_violations |

Allocation alerts мають severity warning; affected_work_count — кількість work units із відповідним reason, affected_seconds — сума їхніх unscheduled seconds. `unscheduled_work` рахує всі units із залишком. Один unit може мати кілька reasons: **не підсумовувати seconds різних alerts**.

Persisted alerts мають severity warning, affected_seconds = сума **excess** відповідних унікальних періодів, affected_work_count=0: attribution до конкретної eligible work unit не стверджується. Daily та weekly excess перекриваються за змістом — їх також не слід додавати як незалежний backlog. Один code може з'явитися двічі з різними source; UI має зберігати цю відмінність.

Стабільний порядок alerts: unscheduled_work, allocation codes у порядку таблиці, excluded_work, persisted daily, persisted weekly. Порожні групи не виводяться. Selection `warnings` передаються окремо без зміни; зокрема over-reservation або stale-session warning сам по собі не стає daily/weekly overload.

## Окрема перевірка persisted workload

`WorkloadConstraintService::existingViolations()` використовує ті самі C7 calendar portions і additive period totals, але **range не додає candidate seconds**. Отже, тут немає штучної 24-годинної сесії від запиту.

Джерела — owner-scoped `planned` та `completed` StudySessions із чинного PlanningContextService, включно з сумісною історією soft-deleted work. Missed/cancelled/rescheduled predecessors не рахуються. Використовуються scheduled starts/ends, **не actual_minutes**. Різні overlapping records рахуються окремо, як в existing workload constraints; union/злиття не застосовується. Це не діагноз парного конфлікту Tasks.

Daily labels — лише запитані локальні дні. Weekly totals — **повні локальні ISO weeks**, що торкаються запитаних дат, включно із сесіями поза вузьким requested period. Точна local-date membership використовується й для disjoint/repeated dates; duplicated labels не створюють duplicate diagnostics. Wholly skipped local date не створює уявного часу чи period violation.

Ліміт strict: scheduled_seconds > configured_minutes × 60. Рівність не є перевищенням; null limit вимикає відповідну діагностику. Жодних default максимальних годин не додається. DST days рахуються за actual elapsed seconds (23/25 год), ISO week-year може відрізнятися від calendar year.

## Priority order та рекомендації

Rank — 1-based індекс незміненого planner work_results. Не виконується повторне сортування за частковістю, warning або frontend score:

1. Effective deadline ascending, non-null раніше за null; для Subtask — ранній із parent/child deadlines.
2. Task priority descending: 3, 2, 1.
3. Stable Task ID, child position, child ID.

Subtask_id=null означає Task work unit; non-null — окремий Subtask. Active Subtasks authoritative: estimate батька не додається вдруге. Completed/excluded work не має action rank. Pending і missed Tasks обробляються за тими самими remaining-effort правилами; completed work не стає actionable.

| Recommendation code | Evidence / безпечний зміст |
| --- | --- |
| `follow_planning_order` | Кожна eligible unit: розглядати в established rank order |
| `review_planning_period` | Є unscheduled effort: переглянути обраний period **у межах чинних deadlines** |
| `consider_recovery_preview` | Є залишок: за бажанням відкрити чинний read-only recovery preview, без обіцянки повного розміщення |
| `review_study_availability` | no_available/outside_available reason |
| `review_deadline` | deadline capacity/exceeded reason або excluded deadline_expired; перевірити deadline, не ігнорувати його |
| `review_study_limits` | allocation daily/weekly reason; не підвищувати limit автоматично |
| `review_session_duration` | minimum/maximum duration reason |
| `review_break_preferences` | existing break reason |
| `supply_estimated_effort` | excluded missing_estimate і відсутні terminal/nonactionable reasons |

Completed, fully credited, fully reserved та parent-authoritative exclusions не отримують recommendations. Якщо в такій exclusion додатково є missing estimate/expired deadline, вони все одно не перетворюються на actionable priority. В усіх випадках raw reasons збережені.

## Повні приклади

### Порожній стан

```json
{
  "data": {
    "as_of_utc": "2026-11-08T08:00:00.500000Z",
    "timezone": "UTC",
    "period": {"start_date": "2026-11-09", "end_date": "2026-11-09"},
    "summary": {"requested_seconds": 0, "allocated_seconds": 0, "unscheduled_seconds": 0, "fully_allocated": true},
    "alerts": [],
    "work_advice": [],
    "excluded_items": [],
    "warnings": [],
    "persisted_workload_violations": []
  }
}
```

### Частковий план: estimate 120 хв, доступна одна година

```json
{
  "data": {
    "as_of_utc": "2026-11-08T08:00:00.500000Z",
    "timezone": "UTC",
    "period": {"start_date": "2026-11-09", "end_date": "2026-11-09"},
    "summary": {"requested_seconds": 7200, "allocated_seconds": 3600, "unscheduled_seconds": 3600, "fully_allocated": false},
    "alerts": [
      {"code": "unscheduled_work", "severity": "warning", "source": "allocation", "affected_seconds": 3600, "affected_work_count": 1},
      {"code": "no_available_study_time", "severity": "warning", "source": "allocation", "affected_seconds": 3600, "affected_work_count": 1},
      {"code": "planning_horizon_exhausted", "severity": "warning", "source": "allocation", "affected_seconds": 3600, "affected_work_count": 1}
    ],
    "work_advice": [
      {
        "task_id": 42,
        "subtask_id": null,
        "requested_seconds": 7200,
        "allocated_seconds": 3600,
        "unscheduled_seconds": 3600,
        "reason_codes": ["no_available_study_time", "planning_horizon_exhausted"],
        "rank": 1,
        "recommendation_codes": ["follow_planning_order", "review_planning_period", "consider_recovery_preview", "review_study_availability"]
      }
    ],
    "excluded_items": [],
    "warnings": [],
    "persisted_workload_violations": []
  }
}
```

Цей результат не стверджує persisted overload і не доводить, що альтернативного розкладу немає.

### Виключена робота з невідомим estimate

```json
{
  "data": {
    "as_of_utc": "2026-11-08T08:00:00.500000Z",
    "timezone": "UTC",
    "period": {"start_date": "2026-11-09", "end_date": "2026-11-09"},
    "summary": {"requested_seconds": 0, "allocated_seconds": 0, "unscheduled_seconds": 0, "fully_allocated": true},
    "alerts": [{"code": "excluded_work", "severity": "info", "source": "selection", "affected_seconds": null, "affected_work_count": 1}],
    "work_advice": [],
    "excluded_items": [
      {
        "task_id": 43,
        "subtask_id": null,
        "work_type": "task",
        "effective_deadline_utc": null,
        "priority": 2,
        "position": null,
        "effort": {"estimated_seconds": null, "completed_credit_seconds": 0, "remaining_work_seconds": null, "future_reserved_seconds": 0, "unallocated_work_seconds": null, "over_reserved_seconds": null},
        "reason_codes": ["missing_estimate"],
        "recommendation_codes": ["supply_estimated_effort"]
      }
    ],
    "warnings": [],
    "persisted_workload_violations": []
  }
}
```

## Read-only гарантії, помилки та обмеження

Service використовує одну nonlocking MySQL REPEATABLE READ transaction для fresh owner, прямого planner та додаткового fixed/batched context read. Немає reread поза snapshot, N+1 queries, persistence adapter, confirmation token, бізнес-записів, cache materialization, jobs, notifications або provider calls. Query count не зростає на один запит для кожного Task; автоматична перевірка порівнює 1 та 21 Tasks. Звичайний authentication/session middleware не замінюється новою системою авторизації.

- 401: authentication потрібна до date validation.
- 422: missing/invalid dates, понад 31 дату, зворотний range, unknown query fields; стандартний Laravel `message` + `errors`.
- 404: недоступний owner у прямому Service/context; не розкриває чужих джерел.
- 500: corrupt persisted timezone/source relationships/configuration або непредставимі planning query boundaries; навіть з APP_DEBUG=true тільки `{"message":"The workload advice could not be processed."}`.
- Endpoint не має lifecycle 409/confirmation response: нічого не підтверджує й не зберігає.

Greedy allocation може пропустити інше можливе розміщення. `fully_allocated=false` — **результат конкретного deterministic search**, а не математичний доказ неможливості. Причини можуть співіснувати й не доводять єдину причину нестачі. Даних для вигаданих «Task A конфліктує з Task B» не створюється. Determinism визначається однаковими persisted inputs і clock; live timestamps та future cutoff закономірно змінюються між запитами.

Advice не змінює deadline/priority/availability/status/StudySession. Подальше створення плану потребує окремого чинного preview і explicit confirmation; advice response не може слугувати token. Немає historical task-state reconstruction, forecasts або автоматичного перенесення.

## Stage 17B Frontend

1. Додати typed read-only API call і UI loading/empty/error states; query — тільки локальні start/end dates.
2. Показувати eligible summary окремо від excluded_items. Не прирівнювати fully_allocated=true до завершення/розміщення всіх Tasks.
3. Зберігати rank і Task/Subtask distinction; не розраховувати власний priority score.
4. Розрізняти source=allocation і source=persisted_workload; лише друге має виміряні фактичні scheduled totals/limits/excess для period.
5. Не додавати affected_seconds різних alerts. Показувати nullable excluded duration як невідому.
6. Локалізувати machine codes; не називати greedy partial result «неможливо виконати».
7. Чинний recovery/preview можна відкрити лише як explicit наступну дію. Не auto-confirm, не PATCH status/priority/deadline за advice.

Рекомендовані labels: «Аналіз навчального навантаження», «Не розміщено цим плануванням», «Виключено з поточної спроби», «Порядок розгляду», «Обмеження нового розміщення», «Перевищення збереженого навантаження». Frontend у Stage 17A не переглядався й не змінювався.
