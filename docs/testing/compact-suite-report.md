# Компактний набір Backend

Це свідоме скорочення тестового набору, а не виправлення старих падінь через виключення файлів. Початкова інвентаризація `vendor/bin/phpunit --list-tests-xml` виявила **7 065** offline-випадків у **176** класах. Новий набір має **660** випадків у **110** класах; жорстка межа — 700. Кожен рядок DataProvider враховується окремо.

## Фактичний розподіл

| Основна категорія | Випадків |
| --- | ---: |
| Unit | 220 |
| Feature/REST | 170 |
| Інтеграційні | 85 |
| Міграції та контракти поточної схеми | 45 |
| Конкурентність | 30 |
| AI з підмінними провайдерами | 55 |
| Міжмодульні | 35 |
| Smoke | 20 |
| Разом | 660 |

Розподіл відповідає орієнтирам без відхилень. Smoke — підмножина повного набору, а не додаткові 20 унікальних тестів. `mysql` позначає залежність виконання, а не другу основну категорію.

## Що замінено

Видалено всі 176 початкових offline PHP-класів і окремий платний opt-in `tests/Live/OpenAiLiveSmokeTest.php`. У `phpunit.xml` та `phpunit-smoke.xml` немає нових виключень: конфігурації не змінювалися. 640 відібраних перевірок перевикористовують наявні змістовні ядра з актуалізованими фікстурами й контрактами; 20 smoke-випадків написано для компактного набору. 6 425 старих offline-випадків не перенесені як окремі випадки. Це число не є кількістю доведених дефектів або втрачених унікальних вимог.

[Маніфест](compact-suite-manifest.json) містить основну категорію кожного класу, початкові ідентифікатори перевикористаних випадків та перелік адаптацій. [Інвентаризація вилучення](compact-suite-retirement.json) містить розгортання початкових методів, числа перенесених випадків і перелік старих класів. Масиви провайдерів замінено явними відібраними даними; старі класи не завантажуються приховано. Число не добирається повторенням одного сценарію.

Пріоритет надано чинним REST-маршрутам і Services: облік роботи та підзавдань; календар і семестрові зміни; XLSX; вільний час та обмеження; розміщення, підтвердження й відновлення планів; нагадування; статистика; AI-пропозиції, виконання та незмінні receipts. Конкурентність перевіряють окремі PHP-процеси/MySQL-підключення з керованим очікуванням і спостереженням справжніх InnoDB-блокувань.

У новому наборі оновлено локалізовані повідомлення, поля ресурсів, обов’язкову фактичну тривалість і поточний review/cancel-процес завершення роботи. Підтвердження доступності перевіряє очікуване збільшення версії recovery scan. Історичні фікстури користувача вставляють лише колонки відповідного попередника; історичні planning-фікстури створюються без сучасних observers, чиї таблиці тоді ще не існували. Самі міграції та бізнес-сервіси не змінено. Падіння нового набору усували актуалізацією цих тестів, а не видаленням невдалих випадків. Окремі security-проби замінено в межах прямої вимоги виключити їх із набору.

## Що прибрано без окремого еквівалента

- Спеціальні атаки, перевірки обходу доступу, enumeration, підроблених підписів і негативні security-матриці. Звичайний вхід та функціональні account/email-процеси збережені.
- Вичерпні перестановки scalar/JSON-помилок, malformed input і всіх старих boundary-комбінацій. Залишені представники різних бізнес-правил і меж, а не кожна варіація.
- Самостійні provider-wire/portability-набори Gemini/OpenAI, усі окремі unit-матриці AI-схем і платний live smoke. Реальні зовнішні моделі, їхня якість і платні виклики не перевірялися.
- Окремі сценарії password change/reset, HTTP-видалення задач/підзадач, повна legacy XLSX preview/revalidation/commit REST-матриця, dedicated relative-reminder REST/конкурентні матриці, demo-команди та частина UI-oriented diagnostic/presentation контрактів. Перевірки суміжних Services чи smoke не оголошуються повною заміною цих сценаріїв.
- Негативні тести bootstrap/proxy/database-isolation/full-stack opt-in. Їхні production/runner guard-механізми збережені.

Нижче старі класи без прямого перевикористання випадків. Це інвентаризація походження; суміжні вимоги можуть частково перевірятися іншими компактними сценаріями.

| Старий клас | Випадків |
| --- | ---: |
| `Tests\Unit\AgentOrchestratorTest` | 91 |
| `Tests\Unit\AiAvailabilityToolSchemaTest` | 2 |
| `Tests\Unit\AiCapabilityContractTest` | 3 |
| `Tests\Unit\AiEnvironmentConfigurationTest` | 13 |
| `Tests\Unit\AiServerMessageTest` | 8 |
| `Tests\Unit\AiToolFoundationTest` | 422 |
| `Tests\Unit\AvailabilityActionTest` | 369 |
| `Tests\Unit\CiMigrationVerificationTest` | 16 |
| `Tests\Unit\CompletionReviewPrivacyTest` | 3 |
| `Tests\Unit\ExistingWorkloadDiagnosticsTest` | 2 |
| `Tests\Unit\LessonActionTest` | 88 |
| `Tests\Unit\PlanningActionTest` | 162 |
| `Tests\Unit\ReminderDeliveryRulesTest` | 28 |
| `Tests\Unit\ReminderRulesTest` | 68 |
| `Tests\Unit\ScheduleImportFingerprintTest` | 64 |
| `Tests\Unit\ScheduleOccurrenceMaterializerTest` | 38 |
| `Tests\Unit\SemesterActionSchemaTest` | 12 |
| `Tests\Unit\SemesterOccurrenceErrorReasonTest` | 12 |
| `Tests\Unit\StudyPlanConfirmationServiceTest` | 48 |
| `Tests\Unit\StudyReplanningConfirmationServiceTest` | 63 |
| `Tests\Unit\SubtaskReminderActionTest` | 128 |
| `Tests\Unit\SystemHarnessEntrypointTest` | 2 |
| `Tests\Unit\TaskActionTest` | 82 |
| `Tests\Unit\WorkloadCapacityTest` | 9 |
| `Tests\Feature\AcademicCalendarApiTest` | 5 |
| `Tests\Feature\AcademicContextSecurityTest` | 28 |
| `Tests\Feature\AiManagementActionConcurrencyTest` | 29 |
| `Tests\Feature\AiManagementActionMigrationTest` | 1 |
| `Tests\Feature\AiPersistenceTest` | 12 |
| `Tests\Feature\AiPlanningActionConcurrencyTest` | 55 |
| `Tests\Feature\AiPlanningActionMigrationTest` | 1 |
| `Tests\Feature\AiReadinessHardeningTest` | 19 |
| `Tests\Feature\AiSemesterActionMigrationTest` | 1 |
| `Tests\Feature\AiSessionLifecycleActionMigrationTest` | 1 |
| `Tests\Feature\AiSharedRateLimitTest` | 1 |
| `Tests\Feature\AiWorkCompletionPrivacyTest` | 38 |
| `Tests\Feature\ApplicationBootstrapTest` | 1 |
| `Tests\Feature\AttentionDemoTest` | 10 |
| `Tests\Feature\AuthRateLimitTest` | 18 |
| `Tests\Feature\BackendIntegrationSmokeTest` | 26 |
| `Tests\Feature\DatabaseIsolationGuardTest` | 1 |
| `Tests\Feature\DemoStudentCommandTest` | 18 |
| `Tests\Feature\FrontendApiContractTest` | 8 |
| `Tests\Feature\GeminiGenerateContentProviderTest` | 61 |
| `Tests\Feature\LessonLifecycleTest` | 45 |
| `Tests\Feature\LessonSecurityTest` | 12 |
| `Tests\Feature\LlmProviderContractTest` | 28 |
| `Tests\Feature\OpenAiResponsesProviderTest` | 37 |
| `Tests\Feature\PasswordManagementTest` | 7 |
| `Tests\Feature\PlanningDemoCommandTest` | 5 |
| `Tests\Feature\PlanningDiagnosticsTest` | 3 |
| `Tests\Feature\PlanningPresentationTest` | 5 |
| `Tests\Feature\RecoveryReadToolsTest` | 9 |
| `Tests\Feature\RelativeReminderApiTest` | 98 |
| `Tests\Feature\RelativeReminderConcurrencyTest` | 23 |
| `Tests\Feature\ReminderConcurrencyTest` | 15 |
| `Tests\Feature\ScheduleImportCommitTest` | 43 |
| `Tests\Feature\ScheduleImportPreviewApiTest` | 36 |
| `Tests\Feature\ScheduleImportPreviewRevalidationTest` | 70 |
| `Tests\Feature\SemesterScheduleReadConsistencyTest` | 1 |
| `Tests\Feature\StudyRecoveryApiTest` | 13 |
| `Tests\Feature\SubtaskDeletionTest` | 20 |
| `Tests\Feature\SubtaskSecurityTest` | 16 |
| `Tests\Feature\TaskDeletionTest` | 22 |
| `Tests\Feature\TaskProgressTest` | 21 |
| `Tests\Feature\TaskSecurityTest` | 8 |
| `Tests\Feature\TrustedProxyTest` | 7 |

## Збережені допоміжні файли та CI

`tests/TestCase.php`, `tests/Support/TestDatabase.php`, lease та MySQL guard, обидва disposable runners, `system-router.php`, `system-fixtures.php`, `SystemTestProvider.php`, XLSX-фікстура й необхідні Concerns/workers збережені. Прибрано чотири workers без викликів: `ai_management_action_worker.php`, `ai_planning_action_worker.php`, `ai_rate_limit_worker.php`, `email_verification_worker.php`.

CI використовує `.github/scripts/verify-ci-migrations.php` і Pint, не окремі старі файли PHPUnit; перенаправляти такі селектори не потрібно. CI, його guard disposable service та deployment script не змінювалися. Існуючі Python deployment-перевірки залишені окремо й явно не входять до підрахунку PHPUnit. Жодного старого business PHP-класу поза discovery не залишено.

Спільний full-stack runner підтримується без змін, але тут не запускався: за умовою Frontend не змінювався. Ні browser/full-stack, ні реальна пошта/push, ні production deployment, ні всі вилучені комбінації не підтверджені цим прогоном.

## Відтворення та результати

Команди наведено в [поточній стратегії](../test-strategy.md). Фактичні лічильники з розгорнутого PHPUnit inventory та JUnit збережені у [машинозчитуваному підсумку](compact-suite-results.json). Генератор: `scripts/testing/summarize-compact-suite.php`. Він відхиляє невідомі/дубльовані випадки, неправильний загальний розмір та перевищення бюджету.

Фінальний Unit-прогін: 220; повний: 660; smoke: 20. Успішність та кількість failures/errors/skipped для кожного прогону визначає поточний JSON-підсумок. Перед завершенням повторно перевірено Pint та `git diff --check`. Усі DB-прогони використовують окремий свіжий сервер через `scripts/testing/phpunit-disposable-mysql.sh`; бази розробки/production та SQLite не використовувалися. Підмінні AI-відповіді й `preventStrayRequests` виключають реальні платні виклики. Історичні звіти не редагувалися.
