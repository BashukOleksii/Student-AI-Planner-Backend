<?php

namespace Tests\Unit;

use App\Services\AI\Tools\ToolResultPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SemesterOccurrenceErrorReasonTest extends TestCase
{
    public static function results(): array
    {
        $name = 'propose_semester_occurrence_create';

        return [
            [$name, false, 'invalid_arguments', 'pair_number_taken', ['error_reason' => 'pair_number_taken']],
            [$name, false, 'invalid_arguments', 'time_overlap', ['error_reason' => 'time_overlap']],
            [$name, false, 'invalid_arguments', 'date_cancelled', ['error_reason' => 'date_cancelled']],
            [$name, false, 'invalid_arguments', 'SQL: private error', []],
            [$name, false, 'invalid_arguments', ['time_overlap'], []],
            [$name, false, 'invalid_arguments', null, []],
            [$name, false, 'not_found', 'time_overlap', []],
            [$name, false, 'action_stale', 'pair_number_taken', []],
            [$name, false, 'execution_failed', 'date_cancelled', []],
            [$name, true, 'invalid_arguments', 'pair_number_taken', []],
            ['propose_semester_date_replace', false, 'invalid_arguments', 'time_overlap', []],
            ['propose_task_complete', false, 'invalid_arguments', 'date_cancelled', []],
        ];
    }

    #[DataProvider('results')]
    public function test_only_allowlisted_failed_single_addition_reasons_are_projected(string $name, bool $ok, string $code, mixed $reason, array $expected): void
    {
        $this->assertSame($expected, ToolResultPresenter::errorReason($name, $ok, $code, $reason));
    }
}
