<?php

namespace Tests\Unit;

use App\Services\Planning\WorkloadConstraintService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ExistingWorkloadDiagnosticsTest extends TestCase
{
    private function interval(string $start, string $end): array
    {
        return ['start' => (new DateTimeImmutable($start))->getTimestamp(), 'end' => (new DateTimeImmutable($end))->getTimestamp()];
    }

    public function test_range_has_no_phantom_candidate_contribution_and_null_limits_are_not_invented(): void
    {
        $service = new WorkloadConstraintService;
        $range = $this->interval('2026-11-09T00:00:00Z', '2026-11-10T00:00:00Z');
        $exact = $this->interval('2026-11-09T09:00:00Z', '2026-11-09T10:00:00Z');
        $this->assertSame([], $service->existingViolations($range, [], 'UTC', 60, 60));
        $this->assertSame([], $service->existingViolations($range, [$exact], 'UTC', 60, 60));
        $this->assertSame([], $service->existingViolations($range, [$range], 'UTC', null, null));
        $this->assertSame([], $service->existingViolations($range, [$exact], 'UTC', null, 60));
    }

    public function test_persisted_dst_day_uses_elapsed_seconds_and_same_iso_calendar_as_candidate_limits(): void
    {
        $service = new WorkloadConstraintService;
        $day = $this->interval('2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z');
        $this->assertSame([['code' => 'daily_study_limit_exceeded', 'period_kind' => 'day', 'period' => '2026-10-25',
            'scheduled_seconds' => 90000, 'limit_seconds' => 86400, 'excess_seconds' => 3600]],
            $service->existingViolations($day, [$day], 'Europe/Kyiv', 1440, null));
        $this->assertSame(['daily_study_limit_exceeded'], $service->evaluate($day, [], 'Europe/Kyiv', 1440, null));
    }
}
