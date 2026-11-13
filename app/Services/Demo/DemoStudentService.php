<?php

namespace App\Services\Demo;

use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Academic\AcademicPeriodService;
use App\Services\Academic\SubjectService;
use App\Services\Academic\TeacherService;
use App\Services\AI\AiConversationService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningPreferenceService;
use App\Services\Planning\RecoverySuggestionService;
use App\Services\Planning\StudyAvailabilityService;
use App\Services\Planning\StudyPlanConfirmationService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudySessionLifecycleRules;
use App\Services\Progress\ProgressStatisticsService;
use App\Services\Reminders\ReminderDeliveryService;
use App\Services\Reminders\ReminderMutationService;
use App\Services\Schedule\LessonService;
use App\Services\Schedule\SemesterScheduleManagementService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/** Synthetic bootstrap only. Existing Services/rules remain authoritative; no LLM, bulk dispatcher or cleanup. */
class DemoStudentService
{
    public function __construct(private DemoDatabaseGuard $guard) {}

    public function create(#[\SensitiveParameter] string $password): array
    {
        $identity = $this->guard->inspect();
        if (DB::transactionLevel() !== 0 || strlen($password) < 24) {
            throw new LogicException('Demo creation requires a fresh transaction and a secure generated password.');
        }
        $passwordHash = Hash::make($password);

        return DB::transaction(function () use ($passwordHash, $identity): array {
            if ($identity !== $this->guard->inspect()) {
                throw new LogicException('The approved database identity changed.');
            }
            $today = CarbonImmutable::now('Europe/Kyiv')->startOfDay();
            $owner = User::create(['name' => 'Олександр Тестовий', 'timezone' => 'Europe/Kyiv',
                'email' => 'demo.student.'.strtolower((string) Str::ulid()).'@example.test', 'password' => $passwordHash]);
            $owner->forceFill(['created_at' => $today->startOfWeek()->subWeeks(5)->setHour(8)->utc()])->saveOrFail();
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            [$schedule, $subjects, $rules] = $this->academics($owner, $today);
            $this->availability($owner);
            [$tasks, $children] = $this->tasks($owner, $subjects, $today);
            $this->history($owner, $tasks, $children, $today);
            $focus = $today->addDay();
            $plans = app(StudyPlanPersistenceService::class);
            $preview = $plans->preview($owner, $focus->toDateString(), $focus->addDays(2)->toDateString(), $tasks[8]->id);
            $saved = $plans->confirmWithinTransaction($owner, $preview['preview_token'], true);
            $source = $saved['sessions']->first();
            if ($source === null) {
                throw new LogicException('Demo availability did not produce a study plan.');
            }
            // A real academic edit after planning: retain the source session until explicit recovery confirmation.
            app(LessonService::class)->create($owner, ['subject_id' => $subjects[7]->id, 'teacher_id' => $subjects[7]->teachers()->sole()->id,
                'type' => 'consultation', 'room' => 'Консультація після зміни розкладу',
                'starts_at' => $source->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $source->ends_at->utc()->format('Y-m-d\TH:i:s\Z')]);
            $this->reminders($owner, $tasks, $children, $saved['sessions'], $today);
            $this->conversations($owner);
            $outcome = app(RecoverySuggestionService::class)->detectWithinTransaction($owner->id);
            $review = $owner->recoverySuggestions()->where('status', 'pending')->sole();
            if ($outcome !== 'created' || $review->kind !== 'replan' || $review->receipt !== null) {
                throw new LogicException('A genuine actionable recovery review was not created.');
            }
            app(PlanningContextService::class)->validateSessionReferences($owner, $owner->studySessions()->get());
            $statistics = app(ProgressStatisticsService::class)->calculate($owner, $today->subDays(14)->toDateString(), $today->addDays(14)->toDateString());
            $summary = ['user_id' => $owner->id, 'subjects' => $owner->subjects()->count(),
                'weekly_rules' => $schedule->weeklyLessonRules()->count(), 'legacy_lessons' => $owner->lessons()->count(),
                'tasks' => $owner->tasks()->count(), 'subtasks' => array_sum(array_map('count', $children)),
                'study_sessions' => $owner->studySessions()->count(), 'reminders' => $owner->reminders()->count(),
                'inbox_notifications' => $owner->notifications()->count(), 'pending_recovery_suggestions' => 1];
            if ($summary['tasks'] !== 24 || $summary['subjects'] !== 8 || $summary['inbox_notifications'] !== 2
                || $statistics['study_sessions']['started_in_period']['completed_actual_minutes'] <= 0) {
                throw new LogicException('Demo validation failed.');
            }

            return ['user' => $owner, 'summary' => $summary, 'recovery_id' => $review->id,
                'conflicting_session_id' => $source->id, 'demo_date' => $today->toDateString(),
                'scenarios' => ['Working Saturday' => $today->startOfWeek()->addWeek()->addDays(5)->toDateString(),
                    'Room replacement' => $today->startOfWeek()->addWeek()->toDateString(),
                    'Cancelled occurrence' => $today->startOfWeek()->addWeeks(2)->toDateString(),
                    'Conflicting study session' => $source->id, 'Pending recovery review' => $review->id]];
        });
    }

    private function academics(User $owner, CarbonImmutable $today): array
    {
        $institution = $owner->educationInstitutions()->create(['name' => 'Демонстраційний університет «Дніпровська політехніка»']);
        $monday = $today->startOfWeek();
        $period = app(AcademicPeriodService::class)->create($owner, ['education_institution_id' => $institution->id,
            'name' => 'Навчальний демонстраційний семестр', 'starts_on' => $monday->subWeeks(4)->toDateString(), 'ends_on' => $monday->addWeeks(12)->addDays(6)->toDateString()]);
        $names = ['Алгоритми та структури даних', 'Бази даних', 'Вебтехнології', 'Програмна інженерія',
            'Комп’ютерні мережі', 'Операційні системи', 'Англійська мова', 'Штучний інтелект'];
        $teachers = ['Ірина Коваленко', 'Андрій Мельник', 'Наталія Шевченко', 'Олег Бондаренко',
            'Тетяна Петренко', 'Віктор Савченко', 'Марія Литвин', 'Дмитро Романенко'];
        $subjects = [];
        foreach ($names as $i => $name) {
            $teacher = app(TeacherService::class)->create($owner, ['education_institution_id' => $institution->id, 'name' => $teachers[$i]]);
            $subject = app(SubjectService::class)->create($owner, ['education_institution_id' => $institution->id,
                'name' => $name, 'code' => 'DEMO-'.($i + 1), 'color' => ['#2563EB', '#7C3AED', '#059669', '#D97706', '#DC2626', '#0891B2', '#DB2777', '#4F46E5'][$i]]);
            $subject->teachers()->attach($teacher->id, ['user_id' => $owner->id]);
            $subjects[] = $subject;
        }
        $manager = app(SemesterScheduleManagementService::class);
        $graph = $manager->create($owner, ['academic_period_id' => $period->id, 'group_name' => 'КН-231 (демо)',
            'parity_anchor_on' => $period->starts_on->toDateString(), 'first_week_parity' => 'numerator']);
        $schedule = $graph['schedule'];
        $rules = [];
        foreach ([[1, 1, 0], [1, 2, 1], [1, 3, 6], [2, 1, 2], [2, 2, 3], [2, 3, 7], [3, 1, 4], [3, 2, 5], [3, 3, 0], [4, 1, 1], [4, 2, 2], [4, 3, 7], [5, 1, 3], [5, 2, 4], [5, 3, 6]] as $i => [$day,$pair,$subjectIndex]) {
            $subject = $subjects[$subjectIndex];
            $graph = $manager->createRule($owner, $schedule, ['schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token'],
                'weekday' => $day, 'week_pattern' => $day === 2 && $pair === 3 ? 'numerator' : 'all', 'pair_number' => $pair,
                'starts_at_local' => [1 => '09:00:00', 2 => '10:40:00', 3 => '13:00:00'][$pair],
                'ends_at_local' => [1 => '10:20:00', 2 => '12:00:00', 3 => '14:20:00'][$pair],
                'subject_id' => $subject->id, 'teacher_id' => $subject->teachers()->sole()->id,
                'lesson_type' => ['lecture', 'practical', 'laboratory'][$i % 3], 'room' => (string) (201 + $i)]);
            $rules[] = $graph['rules']->first(fn ($rule) => $rule->weekday === $day && $rule->pair_number === $pair);
        }
        $nextMonday = $monday->addWeek();
        $manager->workingSaturday($owner, $schedule, ['occurs_on' => $nextMonday->addDays(5)->toDateString(), 'copied_weekday' => 2,
            'schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token']]);
        $manager->replaceDate($owner, $schedule, $nextMonday->toDateString(), ['mode' => 'copy_base',
            'changes' => [['weekly_lesson_rule_id' => $rules[1]->id, 'room' => 'Онлайн — заміна аудиторії']],
            'schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token']]);
        $manager->changeOccurrence($owner, $schedule, $nextMonday->addWeek()->toDateString(),
            'semester:'.$schedule->id.':'.$nextMonday->addWeek()->toDateString().':rule:'.$rules[0]->id, [], true,
            ['schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token']]);

        return [$schedule, $subjects, $rules];
    }

    private function availability(User $owner): void
    {
        app(PlanningPreferenceService::class)->replace($owner, ['min_session_minutes' => 30, 'max_session_minutes' => 60,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 180, 'max_weekly_study_minutes' => 900]);
        foreach (range(1, 7) as $day) {
            foreach ($day < 6 ? [['18:00:00', '21:00:00']] : [['10:00:00', '13:00:00'], ['15:00:00', '20:00:00']] as [$from,$to]) {
                app(StudyAvailabilityService::class)->create($owner, ['day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
            }
        }
    }

    private function tasks(User $owner, array $subjects, CarbonImmutable $today): array
    {
        $titles = ['Реалізувати алгоритм сортування', 'Підготувати лабораторну роботу з SQL', 'Створити сторінку портфоліо',
            'Побудувати ER-діаграму', 'Підготувати презентацію з мереж', 'Прочитати розділ про процеси',
            'Повторити структури даних', 'Виправити SQL-запити після перевірки', 'Реалізувати REST API',
            'Спроєктувати командний навчальний проєкт', 'Налаштувати локальну мережу', 'Дослідити планувальник ОС',
            'Підготуватися до модульної контрольної', 'Порівняти індекси баз даних', 'Виконати практичну роботу з Vue',
            'Написати модульні тести', 'Підготувати звіт з маршрутизації', 'Розібрати взаємне блокування',
            'Підготувати усну доповідь англійською', 'Навчити просту модель класифікації', 'Проаналізувати складність алгоритмів',
            'Додати транзакції до навчального API', 'Підготувати документацію проєкту', 'Уточнити вимоги до фінального завдання'];
        $subjectIndexes = [0, 1, 2, 1, 4, 5, 0, 1, 2, 3, 4, 5, 0, 1, 2, 3, 4, 5, 6, 7, 0, 1, 3, 3];
        $decomposed = [6, 9, 12, 15, 18];
        $tasks = $children = [];
        foreach ($titles as $i => $title) {
            $deadline = $i < 6 ? $today->subDays($i + 1)->setHour(21) : ($i < 8 ? $today->subDays(2)->setHour(21) : $today->addDays(2 + $i % 9)->setHour(21));
            $tasks[$i] = app(TaskService::class)->create($owner, ['title' => $title, 'description' => 'Синтетичне навчальне завдання для демонстрації. Підготувати результат, перевірити приклади та записати висновки.',
                'subject_id' => $subjects[$subjectIndexes[$i]]->id, 'priority' => $i % 3 + 1, 'estimated_minutes' => $i === 23 ? null : (in_array($i, $decomposed, true) ? 180 : ($i < 6 ? 90 : [120, 180, 240, 360][$i % 4])),
                'deadline_at' => $deadline->utc()->format('Y-m-d\TH:i:s\Z')]);
            $tasks[$i]->forceFill(['created_at' => $today->subDays(21)->setHour(10)->utc()])->saveOrFail();
            if (in_array($i, $decomposed, true)) {
                $children[$i] = [];
                foreach (['Опрацювати теорію та приклади', 'Виконати основну практичну частину', 'Перевірити результат і оформити звіт'] as $j => $name) {
                    $child = app(SubtaskService::class)->create($owner, $tasks[$i], ['title' => $name, 'estimated_minutes' => 60,
                        'deadline_at' => $deadline->utc()->format('Y-m-d\TH:i:s\Z')]);
                    $child->forceFill(['created_at' => $today->subDays(20)->setHour(11)->utc()])->saveOrFail();
                    if ($j === 0) {
                        app(SubtaskService::class)->complete($owner, $tasks[$i], $child);
                        $child->update(['completed_at' => $today->subDays(3)->setHour(19)->utc()]);
                    }
                    $children[$i][] = $child;
                }
            }
            if ($i < 6) {
                app(TaskService::class)->complete($owner, $tasks[$i]);
                $tasks[$i]->update(['completed_at' => $deadline->subHours($i % 2 === 0 ? 2 : -2)->utc()]);
            } elseif (in_array($i, [7, 17], true)) {
                app(TaskService::class)->miss($owner, $tasks[$i]);
            }
        }

        return [$tasks, $children];
    }

    private function history(User $owner, array $tasks, array $children, CarbonImmutable $today): void
    {
        foreach (range(1, 14) as $daysAgo) {
            $index = $daysAgo < 7 ? $daysAgo - 1 : 8 + ($daysAgo % 8);
            $from = $today->subDays($daysAgo)->setHour(18);
            $child = isset($children[$index]) ? $children[$index][1] : null;
            $session = $this->session($owner, $tasks[$index], $from, $child?->id);
            $action = in_array($daysAgo, [8, 12], true) ? 'miss' : 'complete';
            $session->update(app(StudySessionLifecycleRules::class)->transition($action, $session->status,
                ['start' => $from->getTimestamp(), 'end' => $from->addMinutes(45)->getTimestamp()], $from->addMinutes(50),
                $action === 'complete' ? [40, 50, 35][$daysAgo % 3] : null));
        }
        // Explicit synthetic one-to-one rescheduling history, using the same pure lifecycle rules and chain validator.
        $from = $today->subDays(5)->setHour(17);
        $old = $this->session($owner, $tasks[11], $from);
        $old->update(app(StudySessionLifecycleRules::class)->transition('reschedule', $old->status,
            ['start' => $from->getTimestamp(), 'end' => $from->addMinutes(45)->getTimestamp()], $from->subDay()));
        $next = $this->session($owner, $tasks[11], $from->setHour(19));
        $next->update(['rescheduled_from_session_id' => $old->id, ...app(StudySessionLifecycleRules::class)->transition('complete', $next->status,
            ['start' => $from->setHour(19)->getTimestamp(), 'end' => $from->setHour(19)->addMinutes(45)->getTimestamp()], $from->setHour(20), 40)]);
        app(StudySessionLifecycleRules::class)->validateChain([$old->id => [...$old->getAttributes(), 'status' => $old->status->value],
            $next->id => [...$next->getAttributes(), 'status' => $next->status->value]], $old->id);
    }

    private function session(User $owner, Task $task, CarbonImmutable $from, ?int $childId = null): StudySession
    {
        $session = $owner->studySessions()->create(app(StudyPlanConfirmationService::class)->sessionAttributes($owner->id,
            ['task_id' => $task->id, 'subtask_id' => $childId, 'start' => $from->getTimestamp(), 'end' => $from->addMinutes(45)->getTimestamp(), 'duration_seconds' => 2700]));
        $session->forceFill(['created_at' => $from->subDays(2)->utc()])->saveOrFail();

        return $session;
    }

    private function reminders(User $owner, array $tasks, array $children, $sessions, CarbonImmutable $today): void
    {
        $writer = app(ReminderMutationService::class);
        foreach ([10, 14] as $index) {
            $writer->create($owner, ['task_id' => $tasks[$index]->id, 'anchor' => 'task_deadline', 'offset_minutes' => -60, 'message' => 'Демо: перевірити роботу перед дедлайном.']);
        }
        foreach ($sessions->take(2) as $session) {
            $writer->create($owner, ['study_session_id' => $session->id, 'anchor' => 'session_start', 'offset_minutes' => -30, 'message' => 'Демо: підготувати матеріали до навчальної сесії.']);
        }
        $writer->create($owner, ['subtask_id' => $children[12][1]->id, 'anchor' => 'subtask_deadline', 'offset_minutes' => -120, 'message' => 'Демо: завершити практичну частину.']);
        $cancelled = $writer->create($owner, ['task_id' => $tasks[16]->id, 'trigger_at' => $today->addDays(2)->setHour(12)->utc()->format('Y-m-d\TH:i:s\Z'), 'message' => 'Демо: скасоване нагадування.']);
        $writer->cancel($owner, (string) $cancelled->id);
        foreach ([6, 13] as $i => $index) {
            $reminder = $writer->create($owner, ['task_id' => $tasks[$index]->id, 'trigger_at' => $today->addDay()->setHour(12)->utc()->format('Y-m-d\TH:i:s\Z'), 'message' => 'Демо: попереднє нагадування про навчальну роботу.']);
            $due = $today->subDays($i + 1)->setHour(17)->utc();
            $reminder->forceFill(['trigger_at' => $due, 'created_at' => $due->subHour(), 'updated_at' => $due->subHour()])->saveOrFail();
            if (app(ReminderDeliveryService::class)->processWithinTransaction($owner->id, $reminder->id)['status'] !== 'sent') {
                throw new LogicException('Demo inbox delivery failed.');
            }
        }
        $owner->notifications()->oldest()->firstOrFail()->markAsRead();
    }

    private function conversations(User $owner): void
    {
        foreach (['Планування навчального тижня', 'Пояснення відновлення навчання'] as $topic) {
            $conversation = app(AiConversationService::class)->create($owner);
            $conversation->messages()->create(['role' => 'user', 'content' => '[Синтетичний приклад історії] '.$topic.'. Це демонстраційний запис, не виконаний запит до моделі.']);
            $conversation->messages()->create(['role' => 'assistant', 'content' => '[Синтетичний приклад історії] Перегляньте календар, завдання та запропоноване відновлення. Жодних змін від імені AI не виконано; нові дії потребують явного підтвердження.']);
        }
    }
}
