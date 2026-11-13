<?php

namespace App\Services\Planning;

/** Presentation only: translates authoritative results without recalculating planning rules. */
class PlanningExplanationPresenter
{
    private const REASONS = [
        'no_available_study_time' => 'Для залишку роботи не знайдено доступного часу в обраному періоді.',
        'below_minimum_session_duration' => 'Доступний проміжок або залишок роботи коротший за мінімальну тривалість сесії.',
        'above_maximum_session_duration' => 'Тривалість перевищує максимальну тривалість сесії.',
        'insufficient_break_before' => 'Перед сесією недостатньо часу на обов’язкову перерву після заняття або іншої сесії.',
        'insufficient_break_after' => 'Після сесії недостатньо часу на обов’язкову перерву перед заняттям або іншою сесією.',
        'outside_available_study_time' => 'Час виходить за доступні години або перетинається із зайнятим часом.',
        'overlapping_break_anchor' => 'Час перетинається з університетським заняттям або запланованою сесією.',
        'daily_study_limit_exceeded' => 'Денний ліміт навчального навантаження не дозволяє розмістити сесію.',
        'weekly_study_limit_exceeded' => 'Тижневий ліміт навчального навантаження не дозволяє розмістити сесію.',
        'deadline_exceeded' => 'Сесія завершувалася б після дедлайну роботи.',
        'deadline_expired' => 'Дедлайн роботи вже минув. Для звичайного планування потрібно явно переглянути дедлайн.',
        'subtasks_authoritative' => 'Плануються підзавдання; оцінка батьківського завдання не додається повторно.',
        'parent_task_completed' => 'Батьківське завдання вже виконано.',
        'fully_reserved' => 'Залишок роботи вже зарезервований майбутніми сесіями.',
        'completed_effort_satisfies_estimate' => 'Зарахована виконана робота вже покриває оцінку тривалості.',
        'completed_effort_exceeds_estimate' => 'Зараховані виконані зусилля перевищують оцінку роботи.',
        'ambiguous_parent_session_attribution' => 'Попередню сесію батьківського завдання неможливо однозначно розподілити між підзавданнями.',
        'reservation_targets_completed_work' => 'Майбутня сесія стосується вже виконаної роботи.',
        'session_targets_deleted_subtask' => 'Сесія стосується видаленого підзавдання.',
        'session_start_not_future' => 'Сесія вже почалася або залишилася в минулому й потребує вашого рішення.',
        'deleted_task' => 'Завдання видалено.',
        'deleted_subtask' => 'Підзавдання видалено.',
        'single_replacement_duration_limited' => 'Лише частину початкової тривалості вдалося перенести.',
        'minimum_above_max_daily_study_minutes' => 'Мінімальна сесія довша за денний ліміт. Вона може бути можливою лише з розподілом часу між місцевими днями; перевірте придатність доступних годин.',
        'minimum_above_max_weekly_study_minutes' => 'Мінімальна сесія довша за тижневий ліміт. Перевірте можливість розподілу часу між тижнями.',
        'long_break' => 'Перерва довша за максимальну сесію. Це дозволено, але зменшує придатний час.',
        'no_suitable_time_in_period' => 'За поточним розкладом і обмеженнями у вибраному періоді не знайдено придатної сесії.',
        'availability_not_configured' => 'Доступні години для самостійного навчання не визначені.',
        'reservation_deadline_exceeded' => 'Запланований час навчання виходить за дедлайн роботи',
        'missing_estimate' => 'Не вказано оцінку тривалості роботи',
        'completed_task' => 'Завдання вже виконано',
        'completed_subtask' => 'Підзавдання вже виконано',
        'planning_horizon_exhausted' => 'У вибраному періоді недостатньо часу для решти роботи',
        'deadline_capacity_exhausted' => 'До дедлайну недостатньо доступного часу',
        'stale_planned_session' => 'Минулу заплановану сесію ще не завершено й не позначено пропущеною',
        'future_reservation_exceeds_remaining_work' => 'Майбутні сесії вже резервують більше часу, ніж залишок роботи',
        'lesson_overlap' => 'Час перетинається із заняттям',
        'session_not_future' => 'Сесія вже почалася або залишилася в минулому',
        'no_replacement_capacity' => 'Недостатньо часу для заміни сесії',
        'incomplete_relocation' => 'Лише частину тривалості вдалося розмістити',
    ];

    public function reasons(array $codes): array
    {
        return array_values(array_unique(array_map(fn ($code) => self::REASONS[$code]
            ?? 'За поточних умов цю роботу не вдалося запланувати. Перевірте доступний час і налаштування.', $codes)));
    }

    public function row(array $row): array
    {
        return [...$row, 'explanations' => $this->reasons([
            ...($row['reason_codes'] ?? (isset($row['code']) ? [$row['code']] : [])),
            ...($row['replacement_reason_codes'] ?? []),
        ])];
    }

    public function summary(array $summary): array
    {
        if (isset($summary['affected_sessions'])) {
            return ['title' => $summary['affected_sessions'] > 0 ? 'Пропозиція перенесення сесій' : 'Змінювати сесії не потрібно',
                'next_step' => $summary['affected_sessions'] > 0
                    ? 'Перевірте новий час. Скасування сесій без заміни потребує окремої згоди.'
                    : 'Ваші сесії залишаються без змін.'];
        }
        $seconds = $summary['allocated_seconds'] ?? $summary['replacement_seconds'] ?? 0;
        $remaining = $summary['unscheduled_seconds'] ?? $summary['untransferred_seconds'] ?? 0;

        return ['title' => $seconds > 0 ? 'Знайдено час для навчання' : 'Нових сесій не запропоновано',
            'next_step' => $seconds > 0
                ? ($remaining > 0 ? 'Можна зберегти доступні сесії, а для решти роботи переглянути період або доступні години.' : 'Перевірте час сесій і підтвердьте план.')
                : 'Перевірте доступні години, період і оцінки часу завдань.'];
    }
}
