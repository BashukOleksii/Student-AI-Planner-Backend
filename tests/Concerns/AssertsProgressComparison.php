<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait AssertsProgressComparison
{
    private function dates(): array
    {
        return ['baseline_start_date' => '2026-09-01', 'baseline_end_date' => '2026-09-07',
            'current_start_date' => '2026-10-01', 'current_end_date' => '2026-10-07'];
    }

    private function url(array $parameters = []): string
    {
        return '/api/statistics/reports/comparison?'.http_build_query(array_replace($this->dates(), $parameters));
    }

    private function emptyMetrics(): array
    {
        $work = ['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0];
        $sessions = [];
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $sessions[$status] = ['count' => 0, 'scheduled_seconds' => 0];
        }
        $sessions += ['completed_actual_minutes' => 0, 'completed_with_actual_minutes' => 0, 'completed_without_actual_minutes' => 0];

        return ['tasks_completed' => $work, 'subtasks_completed' => $work, 'study_sessions' => $sessions];
    }

    private function reconcile(array $data): void
    {
        foreach (['baseline', 'current'] as $side) {
            $query = http_build_query($data[$side]['period']);
            $g1 = $this->getJson('/api/statistics/progress?'.$query)->assertOk()->json('data');
            $g2a = $this->getJson('/api/statistics/trends?'.$query)->assertOk()->json('data');
            $this->assertSame($data['as_of_utc'], $g1['as_of_utc']);
            $this->assertSame($data['as_of_utc'], $g2a['as_of_utc']);
            $this->assertSame($data['timezone'], $g1['timezone']);
            $this->assertSame($data['timezone'], $g2a['timezone']);
            $this->assertSame($data[$side]['totals'], ['tasks_completed' => $g1['tasks']['completed_in_period'],
                'subtasks_completed' => $g1['subtasks']['completed_in_period'], 'study_sessions' => $g1['study_sessions']['started_in_period']]);
            $this->assertSame($data[$side]['totals'], $g2a['totals']);
        }
        $check = function (array $baseline, array $current, array $delta) use (&$check): void {
            $this->assertSame(array_keys($baseline), array_keys($delta));
            foreach ($baseline as $key => $value) {
                if (is_array($value)) {
                    $check($value, $current[$key], $delta[$key]);
                } else {
                    $this->assertIsInt($delta[$key]);
                    $this->assertSame($current[$key] - $value, $delta[$key]);
                }
            }
        };
        $check($data['baseline']['totals'], $data['current']['totals'], $data['delta']);
        foreach (['task_completions' => $data['delta']['tasks_completed']['total'],
            'subtask_completions' => $data['delta']['subtasks_completed']['total'],
            'missed_sessions' => $data['delta']['study_sessions']['missed']['count'],
            'known_actual_minutes' => $data['delta']['study_sessions']['completed_actual_minutes']] as $key => $value) {
            $this->assertSame($value === 0 ? 'unchanged' : ($value > 0 ? 'increased' : 'decreased'), $data['observations'][$key]);
        }
        foreach (['baseline', 'current'] as $side) {
            $this->assertSame($data[$side]['totals']['study_sessions']['completed_without_actual_minutes'] > 0,
                $data['observations'][$side.'_has_unknown_actual_minutes']);
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }
}
