<?php

namespace Tests\Unit;

use App\Exceptions\StudyPlanConflictException;
use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\StudyPlanConfirmationService;
use App\Services\Planning\StudyReplanningConfirmationService;
use App\Services\Planning\TimeIntervalService;
use DateTimeImmutable;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudyReplanningConfirmationServiceTest extends TestCase
{
    private function service(): StudyReplanningConfirmationService
    {
        $intervals = new TimeIntervalService;
        $availability = new AvailabilityOccurrenceService;
        $encrypter = new Encrypter(str_repeat('k', 32), 'AES-256-CBC');

        return new StudyReplanningConfirmationService($encrypter, new StudyPlanConfirmationService($encrypter, $intervals),
            new PlanningContextService($intervals, $availability, new FreeTimeService($availability, $intervals)),
            new PlanningConstraintService, $intervals);
    }

    private function parameters(): array
    {
        return ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'task_id' => null, 'subtask_id' => null];
    }

    private function review(): array
    {
        return ['as_of_utc' => '2026-10-19T08:00:00.000000Z', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'timezone' => 'UTC',
            'session_results' => [['study_session_id' => 42, 'task_id' => 7, 'subtask_id' => null,
                'original' => ['start' => 1792404000, 'end' => 1792407600], 'classification' => 'requires_replanning',
                'reason_codes' => ['outside_available_study_time'], 'replacement_reason_codes' => [], 'untransferred_seconds' => 0,
                'replacement' => ['task_id' => 7, 'subtask_id' => null, 'start' => 1792400400, 'end' => 1792404000, 'duration_seconds' => 3600]]],
            'released_session_ids' => [42], 'work_results' => [['remaining_work_seconds' => 7200]], 'excluded_items' => [], 'warnings' => [],
            'summary' => ['affected_sessions' => 1]];
    }

    public function test_token_round_trip_is_encrypted_owner_bound_and_expires_exclusively(): void
    {
        $service = $this->service();
        $token = $service->issue(12, $this->parameters(), $this->review(), 1000);
        $this->assertSame($this->review(), $service->decode($token, 12, 1899)['review']);
        $this->assertSame(1900, $service->decode($token, 12, 1000)['expires_at']);
        $this->assertStringNotContainsString('owner_id', base64_decode($token));
        $this->assertNotSame($token, $service->issue(12, $this->parameters(), $this->review(), 1000));
        $this->expectException(InvalidArgumentException::class);
        $service->decode($token, 12, 1900);
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_fail_closed(string $case): void
    {
        $service = $this->service();
        $token = $service->issue(12, $this->parameters(), $this->review(), 1000);
        $encrypter = new Encrypter(str_repeat('k', 32), 'AES-256-CBC');
        $payload = json_decode($encrypter->decrypt($token, false), true);
        $owner = 12;
        $now = 1000;
        switch ($case) {
            case 'owner': $owner = 13;
                break;
            case 'expired': $now = 1901;
                break;
            case 'future': $now = 999;
                break;
            case 'tamper': $token[20] = $token[20] === 'a' ? 'b' : 'a';
                break;
            case 'stage c': $payload['purpose'] = 'study-plan-confirmation';
                break;
            case 'version': $payload['version'] = 2;
                break;
            case 'scalar': $payload['version'] = '1';
                break;
            case 'extra': $payload['extra'] = null;
                break;
            case 'lifetime': $payload['expires_at']++;
                break;
            case 'overflow': $payload['issued_at'] = PHP_INT_MAX;
                break;
            case 'date': $payload['parameters']['start_date'] = '2026-02-30';
                break;
            case 'filter': $payload['parameters']['task_id'] = '7';
                break;
            case 'child': $payload['parameters']['subtask_id'] = 1;
                break;
            case 'parameter extra': $payload['parameters']['owner_id'] = 12;
                break;
            case 'review': $payload['review'] = [];
                break;
        }
        if (! in_array($case, ['owner', 'expired', 'future', 'tamper'], true)) {
            $token = $encrypter->encrypt(json_encode($payload), false);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The preview token is invalid or expired.');
        $service->decode($token, $owner, $now);
    }

    public static function invalidTokens(): array
    {
        return array_map(fn ($case) => [$case], ['owner', 'expired', 'future', 'tamper', 'stage c', 'version', 'scalar', 'extra',
            'lifetime', 'overflow', 'date', 'filter', 'child', 'parameter extra', 'review']);
    }

    public function test_e2_token_is_rejected_by_stage_c_without_changing_its_format(): void
    {
        $token = $this->service()->issue(12, $this->parameters(), $this->review(), 1000);
        $service = new StudyPlanConfirmationService(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'), new TimeIntervalService);
        $this->expectException(InvalidArgumentException::class);
        $service->decode($token, 12, 1000);
    }

    public function test_associative_order_and_clock_are_immaterial_and_inputs_remain_unchanged(): void
    {
        $review = $this->review();
        $current = array_reverse($review, true);
        $current['session_results'][0] = array_reverse($current['session_results'][0], true);
        $current['as_of_utc'] = 'later';
        $this->assertTrue($this->service()->matches($review, $current));
        $this->assertSame($this->review(), $review);
    }

    #[DataProvider('materialChanges')]
    public function test_all_material_changes_conflict(string $field): void
    {
        $review = $this->review();
        $current = $review;
        switch ($field) {
            case 'classification': $current['session_results'][0]['classification'] = 'valid';
                break;
            case 'original': $current['session_results'][0]['original']['start']++;
                break;
            case 'replacement': $current['session_results'][0]['replacement'] = null;
                break;
            case 'reasons': $current['session_results'][0]['reason_codes'][] = 'other';
                break;
            case 'partial': $current['session_results'][0]['untransferred_seconds']++;
                break;
            case 'release': $current['released_session_ids'] = [];
                break;
            case 'types': $current['released_session_ids'] = ['42'];
                break;
            case 'work': $current['work_results'][0]['remaining_work_seconds']++;
                break;
            case 'summary': $current['summary']['affected_sessions']++;
                break;
            case 'warnings': $current['warnings'][] = ['code' => 'warning'];
                break;
            case 'excluded': $current['excluded_items'][] = ['task_id' => 1];
                break;
            case 'timezone': $current['timezone'] = 'Europe/Kyiv';
                break;
            case 'date': $current['end_date'] = '2026-10-20';
                break;
            case 'list order': $review['released_session_ids'][] = 43;
                $current['released_session_ids'] = [43, 42];
                break;
        }
        $this->assertFalse($this->service()->matches($review, $current));
    }

    public static function materialChanges(): array
    {
        return array_map(fn ($field) => [$field], ['classification', 'original', 'replacement', 'reasons', 'partial', 'release',
            'types', 'work', 'summary', 'warnings', 'excluded', 'timezone', 'date', 'list order']);
    }

    public function test_acceptance_distinguishes_full_partial_unplaced_and_no_action(): void
    {
        $service = $this->service();
        $review = $this->review();
        $this->assertNull($service->acceptanceError($review, false, false));
        $review['session_results'][0]['replacement']['end'] -= 1800;
        $review['session_results'][0]['replacement']['duration_seconds'] = 1800;
        $this->assertSame('accept_partial', $service->acceptanceError($review, false, true));
        $this->assertNull($service->acceptanceError($review, true, false));
        $review['session_results'][0]['replacement'] = null;
        $this->assertSame('accept_partial', $service->acceptanceError($review, false, true));
        $this->assertSame('accept_unplaced_cancellation', $service->acceptanceError($review, true, false));
        $this->assertNull($service->acceptanceError($review, true, true));
        $review['session_results'][0]['classification'] = 'cannot_replan';
        $review['released_session_ids'] = [];
        $this->assertSame('preview_token', $service->acceptanceError($review, true, true));
    }

    #[DataProvider('invalidBatch')]
    public function test_release_integrity_and_one_successor_rules(string $case): void
    {
        $review = $this->review();
        switch ($case) {
            case 'duplicate source': $review['session_results'][] = $review['session_results'][0];
                break;
            case 'duplicate release': $review['released_session_ids'][] = 42;
                break;
            case 'missing release': $review['released_session_ids'] = [];
                break;
            case 'foreign release': $review['released_session_ids'] = [43];
                break;
            case 'wrong task': $review['session_results'][0]['replacement']['task_id'] = 8;
                break;
            case 'wrong child': $review['session_results'][0]['replacement']['subtask_id'] = 8;
                break;
            case 'multiple': $review['session_results'][0]['replacement'] = [$review['session_results'][0]['replacement']];
                break;
            case 'longer': $review['session_results'][0]['replacement']['end']++;
                $review['session_results'][0]['replacement']['duration_seconds']++;
                break;
            case 'invalid interval': $review['session_results'][0]['original']['end'] = $review['session_results'][0]['original']['start'];
                break;
            case 'overflow': $review['session_results'][0]['original'] = ['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX];
                break;
            case 'datetime': $review['session_results'][0]['replacement']['start'] = 253402300799;
                $review['session_results'][0]['replacement']['end'] = 253402300800;
                $review['session_results'][0]['replacement']['duration_seconds'] = 1;
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        $this->service()->affected($review);
    }

    public static function invalidBatch(): array
    {
        return array_map(fn ($case) => [$case], ['duplicate source', 'duplicate release', 'missing release', 'foreign release',
            'wrong task', 'wrong child', 'multiple', 'longer', 'invalid interval', 'overflow', 'datetime']);
    }

    #[DataProvider('batchChecks')]
    public function test_independent_batch_checks_effort_conflicts_and_future_precision(string $case): void
    {
        $review = $this->review();
        $work = [['task_id' => 7, 'subtask_id' => null, 'effort' => ['unallocated_work_seconds' => 3600], 'effective_deadline_utc' => null]];
        $context = ['free_intervals' => [['start' => 1792400400, 'end' => 1792407600]], 'break_anchors' => [], 'workload_sessions' => [],
            'timezone' => 'UTC', 'preferences' => array_fill_keys(['min_session_minutes', 'max_session_minutes', 'preferred_break_minutes', 'max_daily_study_minutes', 'max_weekly_study_minutes'], null)];
        $now = new DateTimeImmutable('@1792396800');
        if ($case === 'effort') {
            $work[0]['effort']['unallocated_work_seconds'] = 3599;
        }
        if ($case === 'future') {
            $now = new DateTimeImmutable('2026-10-19T09:00:00.000001Z');
        }
        if ($case === 'future equality') {
            $now = new DateTimeImmutable('2026-10-19T09:00:00Z');
        }
        if ($case === 'future before') {
            $now = new DateTimeImmutable('2026-10-19T08:59:59.999999Z');
        }
        if ($case === 'deadline') {
            $work[0]['effective_deadline_utc'] = 1792403999;
        }
        if ($case === 'overlap') {
            $context['break_anchors'] = $context['free_intervals'];
        }
        if ($case === 'batch') {
            $review['session_results'][] = array_replace($review['session_results'][0], ['study_session_id' => 43]);
            $review['released_session_ids'][] = 43;
            $work[0]['effort']['unallocated_work_seconds'] = 7200;
        }
        if (! in_array($case, ['valid', 'future before'], true)) {
            $this->expectException(StudyPlanConflictException::class);
        }
        $snapshot = [$review, $work, $context];
        $this->service()->validateBatch(12, $review, $work, $context, $now);
        $this->assertSame($snapshot, [$review, $work, $context]);
    }

    public static function batchChecks(): array
    {
        return [['valid'], ['effort'], ['future'], ['future equality'], ['future before'], ['deadline'], ['overlap'], ['batch']];
    }

    #[DataProvider('aggregateEffort')]
    public function test_exact_work_unit_budgets_are_summed_without_double_counting(int $budget, bool $differentWork): void
    {
        $review = $this->review();
        $second = $review['session_results'][0];
        $second['study_session_id'] = 43;
        $second['replacement']['start'] += 3600;
        $second['replacement']['end'] += 3600;
        if ($differentWork) {
            $second['task_id'] = $second['replacement']['task_id'] = 8;
        }
        $review['session_results'][] = $second;
        $review['released_session_ids'][] = 43;
        $work = [['task_id' => 7, 'subtask_id' => null, 'effort' => ['unallocated_work_seconds' => $budget], 'effective_deadline_utc' => null]];
        if ($differentWork) {
            $work[] = array_replace($work[0], ['task_id' => 8]);
        }
        $context = ['free_intervals' => [['start' => 1792400400, 'end' => 1792407600]], 'break_anchors' => [], 'workload_sessions' => [],
            'timezone' => 'UTC', 'preferences' => array_fill_keys(['min_session_minutes', 'max_session_minutes', 'preferred_break_minutes', 'max_daily_study_minutes', 'max_weekly_study_minutes'], null)];
        if (! $differentWork && $budget < 7200) {
            $this->expectException(StudyPlanConflictException::class);
        }
        $this->service()->validateBatch(12, $review, $work, $context, new DateTimeImmutable('@1792396800'));
        $this->assertCount(2, $this->service()->affected($review));
    }

    public static function aggregateEffort(): array
    {
        return [[7200, false], [7199, false], [3600, true]];
    }
}
