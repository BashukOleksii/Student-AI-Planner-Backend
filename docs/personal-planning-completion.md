# Завершення персонального планування

## Етап 1 — діагностика та вільний час

Змінено Backend: `PlanningDiagnosticsService`, `PlanningDiagnosticsController`,
`PlanningDiagnosticsRequest`, `PlanningDiagnosticsResource`, `routes/api.php`,
`PlanningDiagnosticsTest`; доповнено `planning-settings.md` і `business-rules.md`.
Frontend: `planning.ts` (API/types/пояснення), `StudyProposal`, `PlanningReview`,
`RecoveryEvidence`, `WorkloadRecommendations`, `WorkloadAdviceView`, `FreeTimeView`,
`PlanningSettingsView`, planning styles і цільові тести.

Новий read-only `GET /api/planning/diagnostics` перевіряє кожний сирий вільний
проміжок через наявний allocator/context. `candidate` — приклад однієї сесії,
а не збережений план; різні проміжки перевіряються незалежно. Дедлайн конкретної
роботи не перевіряється. При null min збережено технічний positive-second minimum
існуючого алгоритму, без прихованих 30 хвилин.

Правила відхилення значень та двох суперечливих пар параметрів не змінено.
Мінімум вище денного/тижневого ліміту та довгі перерви дають advisory; календарна
неможливість у вибраному горизонті показана окремо. Тест через північ захищає
валідний випадок min=30, daily=20 із 10+20 хв у сусідніх місцевих днях.
Причини виключення відділено від нерозміщення; fully_allocated описує допущену
роботу. Нульова допущена робота більше не обіцяє розміщення всіх завдань.

Перевірки: focused MySQL tests через `phpunit-disposable-mysql.sh` (новий сервер,
перевірений datadir і випадкова schema, не робоча БД), PHP Pint/syntax;
90 frontend tests, `npm run type-check`, `npm run build` — успішні.
Обмеження: діагностика горизонту не доводить глобальної неможливості конфігурації;
мінімум вище ліміту не є автоматично HTTP 422. Підтвердження та greedy не змінено.
