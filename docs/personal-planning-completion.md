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

## Етап 2 — фактичний час, дедлайни і зміни умов

Нові завершення REST/domain/AI потребують explicit actual_minutes 1–65535 strict
integer. Form Request, lifecycle rules/service, AI schema/validator узгоджені.
Історичний null не переписується, RemainingEffort fallback не змінено.
Read-only lifecycle permission відділено від запису фактичного часу, щоб recovery
могло попросити рішення без вигаданих хвилин. Старий synthetic demo writer також
передає явно задані synthetic actual values; він не виконувався в робочій БД.

StudyPlan Resource додає effective_deadline_at для selected/excluded work, frontend
показує його і посилання на редагування простроченої роботи. Дедлайни не змінюються.
Механізми RecoverySourceStateService, LearningRecoveryAnalysisService і scanner
повторно використано без другого detector. Сторінка плану перечитує feed кожну
хвилину, коли вкладка видима. Для автоматичного створення reviews має працювати
Laravel Scheduler (scan кожні п’ять хвилин, bounded owners/horizon). Сам feed не
пересуває сесії; timeout не повторює confirm. Зміни за межами горизонту scan
перевіряються через явно вибраний період adaptive preview.

Змінені файли етапу 2: CompleteStudySessionRequest, StudySessionLifecycleRules/
Service, StudyRecoveryPreviewService, StudyPlanningService, StudyPlanResource,
PlanningActionRegistry/ArgumentValidator/Service, AgentOrchestrator,
DemoStudentService; адаптовано lifecycle/AI тести та додано
PlanningCompletionRulesTest. Frontend: StudySessionView, studySessions API,
StudyProposal, PlanningView і цільові tests. Оновлено чинний lifecycle контракт.
Перевірки: 654 isolated MySQL tests (4144 assertions), 100 frontend tests,
PHP syntax/Pint, type-check/build успішні. Реальні completed дані не змінювались.

## Етап 3 — кілька наступників

StudyReplanningAnalysisService повторно використовує той самий allocator без
singlePlacement. Canonical replacements[] містить усі нові сесії; replacement —
сумісний alias лише для однієї. Сума обмежена початковою тривалістю і залишком.
ConfirmationService перевіряє всі запропоновані сесії разом. PersistenceService
зберігає кожний immediate predecessor у transaction під owner lock. Preview token
version 2 та recovery fingerprint policy version 2 відхиляють старі огляди.
REST, recovery та AI вимагають окремої accept_split_replacements згоди, додатково
до accept_partial та accept_unplaced_cancellation, коли вони потрібні.

Additive migration замінює UNIQUE predecessor index на звичайний; FK і записи
збережено. Модель має rescheduledToSessions HasMany. Історія перевіряє весь
connected component, включно із siblings поза горизонтом. Rollback не видаляє
дані і відмовляє, якщо split уже існує. Нагадування джерел скасовуються через
наявний Service без клонування. Completed/missed не переписуються.

Frontend StudyProposal показує original, кожну нову сесію та загальні неперенесені
хвилини. PlanPreview, RecoverySuggestions та AI review мають окрему unchecked
згоду на поділ. Після невідомого confirm потрібне явне перечитування, не retry.

Основні файли: StudyReplanningAnalysis/Preview/Confirmation/PersistenceService,
StudySessionMutationContextService/LifecycleRules, StudySession model, migration,
replanning resources/requests/controllers; RecoverySourceState/Analysis/Suggestion,
PlanningActionConsent/Service; frontend planning/recovery API, types, views,
StudyProposal та AiActionReview. Рішення описано в ADR-013.

Перевірки етапу 3: 872 focused isolated MySQL tests / 4911 assertions;
додаткові split/remaining-effort/rollback regressions: 3 tests / 57 assertions.
156 frontend tests, type-check/build і PHP syntax/Pint успішні. Перевірено 120→60+60,
90 хв залишку при source 120, окремі згоди, stale/replay/cross-user відмову,
подальше перенесення однієї гілки та відмову rollback без втрати даних.
Обмеження: greedy не оптимізує план глобально; single manual reschedule лишається
однією явною заміною, множинні пропозиції формує adaptive preview.

## Етап 4 — локальна демонстрація

Додано SeedPlanningDemo command, PlanningDemoService і PlanningDemoCommandTest.
Команда `planning:demo-seed --user=<exact-name|id:N> --dry-run` працює лише local
loopback MySQL, вимагає однозначного власника і явного interactive yes для запису.
Використовує Task/Subtask/StudyPlan Services, owner lock і transaction. Ідемпотентні
маркери в title/description, перевірка всієї існуючої історії сесій та заборона
перезапису settings/work захищені тестами. Пізніший кандидат для H вибирається
через наявну DiagnosticsService, далі проходить звичайний planner/confirm.

Для перевіреного `o` (User 10, Kyiv) створено tasks 62–70, subtasks 40–42,
planned sessions 48–50. Read-only constraint gate не виявив порушень. Контрольні
хеші всіх попередніх рядків незмінні. Лише нова additive migration застосована
до робочої local БД. Жодного truncate/reset/destructive suite на ній не запускали.
Точні назви/час/IDs і статус виконання кожного сценарію — в
[інструкції ручної перевірки](planning-demo-manual-check.uk.md).

Фінальна звірка вимоги етапу 2 також доповнила SubtaskResource полем
`effective_deadline_at`, повторно використавши public effectiveDeadline method
PlanningWorkSelectionService. SubtaskService передає parent relation для списків
без N+1. TaskSubtasks показує ефективний строк, успадкування, прострочення та
дію редагування. Дедлайни та алгоритм вибору не змінено; додано контрактні тести.

Перевірки: 203 isolated MySQL tests / 1775 assertions, включно з 5 demo tests;
136 focused frontend tests, PHP syntax/Pint, type-check/build — успішні.
Окремий ширший frontend запуск: 146 passed / 3 failed (149). Усі три failures
у subtasks.test.ts вже присутні в збереженому baseline до цих змін: старі кнопки
complete/reopen (два випадки) та старе очікування відсутності min datetime input
(один). Їх не видалено й не послаблено. Повний frontend suite раніше мав 53
baseline failures; його успішне проходження тут не заявляється.

Обмеження: F можна завершити лише після реального початку; G/H потребують явних
ручних змін умов та перевірки preview. Ці зміни в робочій БД не виконувалися.
За відсутності достатнього часу команда чесно пропускає підготовку сесії, не
встановлює defaults і не переписує користувацькі правила. Старі DEMO-дедлайни
повторний запуск не оновлює; після їх спливу доступна перевірка expired діагностики.

Файли етапу 4: `app/Console/Commands/SeedPlanningDemo.php`,
`app/Services/Demo/PlanningDemoService.php`, `tests/Feature/PlanningDemoCommandTest.php`,
ця звітна документація і `planning-demo-manual-check.uk.md`. Фінальне уточнення
ефективного строку: SubtaskResource, SubtaskService, PlanningWorkSelectionService,
PlanningCompletionRulesTest, SubtaskApi/Reorder tests і docs/tasks.md; Frontend
TaskSubtasks, types/api.ts, planning-diagnostics.test.ts та звіт у frontend docs.
