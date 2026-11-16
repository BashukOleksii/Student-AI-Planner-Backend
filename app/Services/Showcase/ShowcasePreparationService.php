<?php

namespace App\Services\Showcase;

use App\Http\Requests\UpdatePlanningPreferenceRequest;
use App\Models\User;
use App\Services\Academic\AcademicPeriodService;
use App\Services\Imports\SemesterScheduleImportService;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningPreferenceService;
use App\Services\Planning\RecoveryCheckTriggerService;
use App\Services\Planning\StudyAvailabilityService;
use App\Services\Planning\StudyPlanConfirmationService;
use App\Services\Planning\StudyPlanningService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudySessionLifecycleRules;
use App\Services\Reminders\ReminderMutationService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class ShowcasePreparationService
{
    public const EMAIL = 'bashuk0325oleksij@gmail.com';

    public const TIMEZONE = 'Europe/Kyiv';

    public const STARTER = 'Реалізувати валідацію форми реєстрації';

    public function prepare(string $path, string $hash, string $startsOn, string $endsOn, string $showOn, ?int $expectedId,
        #[\SensitiveParameter] string $password, ?string $originalFilename = null): array
    {
        $db = DB::connection();
        $lock = 'showcase:'.substr(hash('sha256', $db->getDatabaseName().':'.self::EMAIL), 0, 48);
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false)->acquired !== 1) {
            throw new RuntimeException('Інший запуск уже готує цей акаунт.');
        }
        try {
            return app(RecoveryCheckTriggerService::class)->withoutRequests(fn () => $db->transaction(function () use ($path, $hash, $startsOn, $endsOn, $showOn, $expectedId, $password, $originalFilename): array {
                $old = User::where('email', self::EMAIL)->lockForUpdate()->first();
                if ($old?->id !== $expectedId) {
                    throw new RuntimeException('Акаунт змінився після огляду. Запустіть команду й підтвердьте актуальну ціль.');
                }
                if (! hash_equals($hash, hash_file('sha256', $path) ?: '')) {
                    throw new RuntimeException('XLSX змінився після перевірки. Запустіть команду знову.');
                }
                if ($old !== null) {
                    app(ShowcaseDatabase::class)->delete($old);
                }
                $owner = User::create(['name' => 'Oleksii', 'email' => self::EMAIL, 'password' => $password, 'timezone' => self::TIMEZONE]);
                // No registration events, email verification notifications, or external AI.
                $institution = $owner->educationInstitutions()->create(['name' => 'ХПФК']);
                $period = app(AcademicPeriodService::class)->create($owner, ['name' => '1 семестр',
                    'education_institution_id' => $institution->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
                $imports = app(SemesterScheduleImportService::class);
                $import = $imports->create($owner, ['academic_period_id' => $period->id], new UploadedFile($path, $originalFilename ?? basename($path), null, null, true));
                if ($import->status !== 'ready') {
                    throw new RuntimeException('Імпорт відхилено: '.json_encode($import->review, JSON_UNESCAPED_UNICODE));
                }
                $receipt = $imports->commit($owner, $import, $import->preview_token);
                if ($receipt['changed'] || $receipt['import']->status !== 'committed') {
                    throw new RuntimeException('Імпорт не застосовано.');
                }
                $this->availability($owner);
                $this->history($owner, $startsOn, $endsOn);
                $start = CarbonImmutable::parse($showOn, self::TIMEZONE);
                $end = min($endsOn, $start->addDays(30)->toDateString());
                $cutoff = max($start->getTimestamp(), CarbonImmutable::now('UTC')->addMinutes(5)->ceilSecond()->getTimestamp());
                $tasks = $this->tasks($owner, $start, $end);
                $plan = $this->plan($owner, $showOn, $end, $cutoff);
                if (! $plan['summary']['fully_allocated']) {
                    foreach ($tasks as $task) {
                        $deadline = CarbonImmutable::parse($end, self::TIMEZONE)->setTime(21, 0)->utc()->format('Y-m-d\TH:i:s\Z');
                        app(TaskService::class)->update($owner, $task, ['deadline_at' => $deadline]);
                        foreach ($task->subtasks()->get() as $child) {
                            app(SubtaskService::class)->update($owner, $task, $child, ['deadline_at' => $deadline]);
                        }
                    }
                    $plan = $this->plan($owner, $showOn, $end, $cutoff);
                }
                $this->requireFullPlan($plan);
                // Two tasks are already scheduled; the rest stay available for live planning.
                foreach (array_slice($tasks, -2) as $task) {
                    $plans = app(StudyPlanPersistenceService::class);
                    $preview = $plans->preview($owner, $showOn, $end, $task->id, null, $cutoff);
                    $this->requireFullPlan($preview['plan']);
                    $plans->confirmWithinTransaction($owner, $preview['preview_token']);
                }
                $remaining = $this->plan($owner, $showOn, $end, $cutoff);
                $this->requireFullPlan($remaining);
                if ($remaining['sessions'] === [] || $owner->studySessions()->where('status', 'planned')->count() < 2) {
                    throw new RuntimeException('Недостатньо відкритої роботи або майбутніх сесій для показу.');
                }
                app(ReminderMutationService::class)->create($owner, ['task_id' => $tasks[0]->id, 'anchor' => 'task_deadline',
                    'offset_minutes' => -60, 'message' => 'Перевірити обробку некоректних даних і підготувати приклади для захисту.']);

                return ['email' => $owner->email, 'group' => 'ПІ-231', 'period' => "$startsOn — $endsOn",
                    'lessons' => $owner->semesterSchedules()->sole()->weeklyLessonRules()->count(),
                    'tasks' => $owner->tasks()->count(), 'starter' => self::STARTER,
                    'planning_start' => $showOn, 'planning_end' => $end];
            }));
        } finally {
            try {
                $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
            } catch (Throwable) {
                // Closing the session releases its advisory locks. Cleanup must not
                // turn an already committed preparation into a reported rollback.
                $db->disconnect();
            }
        }
    }

    private function availability(User $owner): void
    {
        $values = ['min_session_minutes' => 30, 'max_session_minutes' => 60, 'preferred_break_minutes' => 15,
            'max_daily_study_minutes' => 120, 'max_weekly_study_minutes' => 480];
        Validator::make($values, (new UpdatePlanningPreferenceRequest)->rules())->validate();
        app(PlanningPreferenceService::class)->replace($owner, $values);
        foreach ([1 => ['17:30:00', '19:00:00'], 2 => ['17:30:00', '19:00:00'], 3 => ['16:00:00', '18:00:00'],
            4 => ['17:00:00', '19:00:00'], 5 => ['15:30:00', '17:00:00'], 6 => ['10:00:00', '12:30:00']] as $day => [$from, $to]) {
            app(StudyAvailabilityService::class)->create($owner, ['day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
        }
    }

    private function tasks(User $owner, CarbonImmutable $show, string $end): array
    {
        $specs = [
            ['Програмування в Інтернет', self::STARTER, ['Перевірити обов’язкові поля та email', 'Додати повідомлення про помилки']],
            ['Людино-машинна взаємодія', 'Підготувати прототип екрана входу', []],
            ['Машинне навчання', 'Порівняти точність двох моделей класифікації', ['Підготувати вибірку та навчити моделі', 'Порівняти метрики та записати висновки']],
            ['Архітектура та проект ПЗ', 'Побудувати діаграму компонентів застосунку', []],
            ['Філософія', 'Підготувати тези до семінару з філософії', []],
            ['Проект інтерфейсів користувача ПС', 'Уточнити стани кнопок у прототипі', []],
            ['Конструювання прогр забезпечення', 'Перевірити модуль обробки запитів', []],
        ];
        $tasks = [];
        foreach ($specs as $i => [$subject, $title, $children]) {
            $deadline = CarbonImmutable::parse(min($end, $show->addDays(7 + $i)->toDateString()), self::TIMEZONE)->setTime(21, 0)->utc()->format('Y-m-d\TH:i:s\Z');
            $task = app(TaskService::class)->create($owner, ['subject_id' => $owner->subjects()->where('name', $subject)->sole()->id,
                'title' => $title, 'description' => 'Підготувати результат, перевірити приклади та коротко записати висновки.',
                'priority' => 2, 'estimated_minutes' => 60, 'deadline_at' => $deadline]);
            foreach ($children as $child) {
                app(SubtaskService::class)->create($owner, $task, ['title' => $child, 'estimated_minutes' => 30, 'deadline_at' => $deadline]);
            }
            $tasks[] = $task->refresh();
        }

        return $tasks;
    }

    private function history(User $owner, string $startsOn, string $endsOn): void
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $first = max($startsOn, $now->subDays(14)->toDateString());
        $count = 0;
        foreach (app(FreeTimeService::class)->calculate($owner, $first, $now->toDateString(), 1800) as $slot) {
            // A quarter-hour buffer also separates history from a later live plan.
            $slot['start'] += 900;
            $slot['end'] = $slot['start'] + 1800;
            if ($now->getTimestamp() <= $slot['end'] + 900) {
                continue;
            }
            $date = CarbonImmutable::createFromTimestampUTC($slot['start'])->setTimezone(self::TIMEZONE)->toDateString();
            $context = app(PlanningContextService::class)->load($owner, $date, $date);
            if (app(PlanningConstraintService::class)->evaluate($slot, $context['free_intervals'], $context['break_anchors'],
                $context['workload_sessions'], self::TIMEZONE, $context['preferences'], null) !== []) {
                continue;
            }
            $subject = $owner->subjects()->orderBy('id')->skip($count)->firstOrFail();
            $task = app(TaskService::class)->create($owner, ['title' => 'Опрацювати конспект: '.$subject->name,
                'subject_id' => $subject->id, 'estimated_minutes' => 30,
                'deadline_at' => CarbonImmutable::parse(min($endsOn, $now->addDay()->toDateString()), self::TIMEZONE)->setTime(21, 0)->utc()->format('Y-m-d\TH:i:s\Z')]);
            $created = CarbonImmutable::createFromTimestampUTC($slot['start'])->subHour();
            $owner->forceFill(['created_at' => min($created, $owner->created_at)])->saveOrFail();
            $task->forceFill(['created_at' => $created])->saveOrFail();
            $attributes = app(StudyPlanConfirmationService::class)->sessionAttributes($owner->id,
                ['task_id' => $task->id, 'subtask_id' => null, ...$slot, 'duration_seconds' => 1800]);
            $session = $owner->studySessions()->create($attributes);
            $completed = CarbonImmutable::createFromTimestampUTC($slot['end']);
            $session->forceFill(['created_at' => $created, ...app(StudySessionLifecycleRules::class)->transition('complete',
                $session->status, $slot, $completed, 30)])->saveOrFail();
            app(TaskService::class)->complete($owner, $task);
            $task->forceFill(['completed_at' => $completed])->saveOrFail();
            if (++$count === 3) {
                return;
            }
        }
        throw new RuntimeException('Для історії потрібні три минулі навчальні вікна в межах періоду. Уточніть початок періоду.');
    }

    private function plan(User $owner, string $start, string $end, int $cutoff): array
    {
        return app(StudyPlanningService::class)->plan($owner, $start, $end, notBeforeUtc: $cutoff);
    }

    private function requireFullPlan(array $plan): void
    {
        $invalidExclusions = collect($plan['excluded_items'])->contains(fn ($item) => array_diff($item['reason_codes'],
            ['subtasks_authoritative', 'completed_task', 'parent_task_completed', 'completed_subtask',
                'completed_effort_satisfies_estimate', 'fully_reserved']) !== []);
        if (! $plan['summary']['fully_allocated'] || $invalidExclusions || $plan['warnings'] !== []) {
            throw new RuntimeException('Робота не вміщується до дедлайнів з урахуванням занять, сесій і лімітів. Збільште кінець періоду; зміни скасовано.');
        }
    }
}
