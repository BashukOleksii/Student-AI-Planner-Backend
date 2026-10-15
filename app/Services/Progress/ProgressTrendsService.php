<?php

namespace App\Services\Progress;

use App\Models\User;
use App\Services\Planning\PlanningContextService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class ProgressTrendsService
{
    public function __construct(private ProgressStatisticsService $statistics, private PlanningContextService $context) {}

    public function calculate(User $user, string $startDate, string $endDate): array
    {
        $this->context->validateDates($startDate, $endDate);

        return DB::transaction(function () use ($user, $startDate, $endDate): array {
            $owner = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
            $now = CarbonImmutable::now('UTC');
            $coverage = $this->context->dateIntervals($startDate, $endDate, $owner->timezone);
            $activity = $this->statistics->loadActivity($owner, $coverage);

            return [
                'as_of_utc' => $now->format('Y-m-d\TH:i:s.u\Z'),
                'timezone' => $owner->timezone,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate, 'aggregation' => 'day', 'session_attribution' => 'starts_at'],
                ...$this->summarize($activity, $startDate, $endDate, $owner->timezone),
            ];
        });
    }

    /** Pure grouping of the same raw, currently completed work/session sources used by G1. */
    public function summarize(array $activity, string $startDate, string $endDate, string $timezone): array
    {
        $this->context->validateDates($startDate, $endDate);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, new DateTimeZone('UTC'));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, new DateTimeZone('UTC'));
        $calendar = $grouped = [];
        for (; $date <= $end; $date = $date->modify('+1 day')) {
            $label = $date->format('Y-m-d');
            $calendar[$label] = $this->context->dateIntervals($label, $label, $timezone);
            $grouped[$label] = ['tasks' => [], 'subtasks' => [], 'study_sessions' => []];
        }
        foreach (['tasks' => 'completed_at', 'subtasks' => 'completed_at', 'study_sessions' => 'starts_at'] as $kind => $column) {
            foreach ($activity[$kind] as $record) {
                $instant = $kind === 'study_sessions' ? $this->context->utcSeconds($record[$column])
                    : $this->statistics->completionInstant($record);
                if ($instant === null) {
                    continue;
                }
                foreach ($calendar as $label => $coverage) {
                    if ($this->statistics->contains($coverage, $instant)) {
                        $grouped[$label][$kind][] = $record;
                        break;
                    }
                }
            }
        }
        $days = [];
        foreach ($calendar as $label => $coverage) {
            $days[] = ['date' => $label, ...$this->statistics->activityMetrics($grouped[$label], $coverage)];
        }

        return ['days' => $days, 'totals' => $this->totals($days)];
    }

    /** Exact sum of daily metric leaves; no float coercion, overflow or input mutation. */
    public function totals(iterable $days): array
    {
        $totals = $this->statistics->activityMetrics(['tasks' => [], 'subtasks' => [], 'study_sessions' => []], []);
        foreach ($days as $day) {
            $totals = $this->addMetrics($totals, array_intersect_key($day, $totals));
        }

        return $totals;
    }

    private function addMetrics(array $left, array $right): array
    {
        if (array_keys($left) !== array_keys($right)) {
            throw new UnexpectedValueException('Invalid daily statistics metrics.');
        }
        foreach ($left as $key => $value) {
            if (is_array($value)) {
                if (! is_array($right[$key])) {
                    throw new UnexpectedValueException('Invalid daily statistics metrics.');
                }
                $left[$key] = $this->addMetrics($value, $right[$key]);
            } else {
                if (! is_int($right[$key]) || $right[$key] < 0 || $value > PHP_INT_MAX - $right[$key]) {
                    throw new UnexpectedValueException('Daily statistics exceed the integer range.');
                }
                $left[$key] = $value + $right[$key];
            }
        }

        return $left;
    }
}
