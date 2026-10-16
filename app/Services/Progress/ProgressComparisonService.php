<?php

namespace App\Services\Progress;

use App\Models\User;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\TimeIntervalService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

/** Compares visible persisted activity, never reconstructing historical populations. */
class ProgressComparisonService
{
    public function __construct(
        private ProgressStatisticsService $statistics,
        private PlanningContextService $context,
        private TimeIntervalService $intervals,
    ) {}

    public function validatePeriods(string $baselineStart, string $baselineEnd, string $currentStart, string $currentEnd): void
    {
        $this->context->validateDates($baselineStart, $baselineEnd);
        $this->context->validateDates($currentStart, $currentEnd);
        if ($baselineEnd >= $currentStart) {
            throw new InvalidArgumentException('The baseline must end before the current period begins.');
        }
        $zone = new DateTimeZone('UTC');
        $dates = array_map(fn ($date) => DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone),
            [$baselineStart, $baselineEnd, $currentStart, $currentEnd]);
        if ($dates[0]->diff($dates[1])->days !== $dates[2]->diff($dates[3])->days) {
            throw new InvalidArgumentException('Both periods must contain the same number of nominal calendar dates.');
        }
    }

    public function calculate(User $user, string $baselineStart, string $baselineEnd, string $currentStart, string $currentEnd): array
    {
        $this->validatePeriods($baselineStart, $baselineEnd, $currentStart, $currentEnd);

        // The fresh owner SELECT establishes one nonlocking MySQL REPEATABLE READ snapshot.
        return DB::transaction(function () use ($user, $baselineStart, $baselineEnd, $currentStart, $currentEnd): array {
            $owner = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
            $now = CarbonImmutable::now('UTC');
            $baselineCoverage = $this->context->dateIntervals($baselineStart, $baselineEnd, $owner->timezone);
            $currentCoverage = $this->context->dateIntervals($currentStart, $currentEnd, $owner->timezone);
            $activity = $this->statistics->loadActivity($owner, $this->intervals->merge([...$baselineCoverage, ...$currentCoverage]));
            $baseline = $this->statistics->activityMetrics($activity, $baselineCoverage);
            $current = $this->statistics->activityMetrics($activity, $currentCoverage);

            return [
                'as_of_utc' => $now->format('Y-m-d\TH:i:s.u\Z'),
                'timezone' => $owner->timezone,
                'comparison' => ['equal_nominal_days' => true, 'session_attribution' => 'starts_at', 'historical_snapshot' => false],
                'baseline' => ['period' => ['start_date' => $baselineStart, 'end_date' => $baselineEnd], 'totals' => $baseline],
                'current' => ['period' => ['start_date' => $currentStart, 'end_date' => $currentEnd], 'totals' => $current],
                ...$this->compare($baseline, $current),
            ];
        });
    }

    /** Pure factual comparison of independently reduced activity totals. */
    public function compare(array $baseline, array $current): array
    {
        $delta = $this->delta($baseline, $current);

        return [
            'delta' => $delta,
            'observations' => [
                'task_completions' => $this->direction($delta['tasks_completed']['total']),
                'subtask_completions' => $this->direction($delta['subtasks_completed']['total']),
                'missed_sessions' => $this->direction($delta['study_sessions']['missed']['count']),
                'known_actual_minutes' => $this->direction($delta['study_sessions']['completed_actual_minutes']),
                'baseline_has_unknown_actual_minutes' => $baseline['study_sessions']['completed_without_actual_minutes'] > 0,
                'current_has_unknown_actual_minutes' => $current['study_sessions']['completed_without_actual_minutes'] > 0,
            ],
        ];
    }

    /** Pure checked subtraction; canonical shape also fixes output order independently of input order. */
    public function delta(array $baseline, array $current): array
    {
        $shape = $this->statistics->activityMetrics(['tasks' => [], 'subtasks' => [], 'study_sessions' => []], []);

        return $this->subtract($shape, $baseline, $current);
    }

    private function subtract(array $shape, array $baseline, array $current): array
    {
        if (count($shape) !== count($baseline) || count($shape) !== count($current)
            || array_diff_key($shape, $baseline) !== [] || array_diff_key($shape, $current) !== []) {
            throw new UnexpectedValueException('Invalid comparison metrics.');
        }
        foreach ($shape as $key => $value) {
            if (is_array($value)) {
                if (! is_array($baseline[$key]) || ! is_array($current[$key])) {
                    throw new UnexpectedValueException('Invalid comparison metrics.');
                }
                $shape[$key] = $this->subtract($value, $baseline[$key], $current[$key]);
            } else {
                // Both operands lie in [0, PHP_INT_MAX], so their difference always fits
                // in [-PHP_INT_MAX, PHP_INT_MAX]. Reject overflowed floats before arithmetic.
                if (! is_int($baseline[$key]) || ! is_int($current[$key]) || $baseline[$key] < 0 || $current[$key] < 0) {
                    throw new UnexpectedValueException('Comparison metrics exceed the nonnegative integer range.');
                }
                $shape[$key] = $current[$key] - $baseline[$key];
            }
        }

        return $shape;
    }

    private function direction(int $delta): string
    {
        return $delta > 0 ? 'increased' : ($delta < 0 ? 'decreased' : 'unchanged');
    }
}
