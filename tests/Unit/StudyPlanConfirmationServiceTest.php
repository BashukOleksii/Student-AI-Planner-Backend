<?php

namespace Tests\Unit;

use App\Services\Planning\StudyPlanConfirmationService;
use App\Services\Planning\TimeIntervalService;
use DateTimeImmutable;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudyPlanConfirmationServiceTest extends TestCase
{
    private function service(): StudyPlanConfirmationService
    {
        return new StudyPlanConfirmationService(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'), new TimeIntervalService);
    }

    private function parameters(): array
    {
        return ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'task_id' => 7, 'subtask_id' => null];
    }

    private function plan(): array
    {
        return ['as_of_utc' => '2026-10-19T08:00:00.000000Z', 'timezone' => 'UTC', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19',
            'sessions' => [['task_id' => 7, 'subtask_id' => null, 'start' => 1792400400, 'end' => 1792404000, 'duration_seconds' => 3600]],
            'work_results' => [['task_id' => 7, 'subtask_id' => null, 'requested_seconds' => 3600, 'allocated_seconds' => 3600,
                'unscheduled_seconds' => 0, 'reason_codes' => []]], 'excluded_items' => [], 'warnings' => [],
            'summary' => ['requested_seconds' => 3600, 'allocated_seconds' => 3600, 'unscheduled_seconds' => 0, 'fully_allocated' => true]];
    }

    public function test_authenticated_encryption_round_trip_binds_exact_owner_parameters_plan_and_lifetime(): void
    {
        $service = $this->service();
        $token = $service->issue(12, $this->parameters(), $this->plan(), 1000);
        $payload = $service->decode($token, 12, 1899);
        $this->assertSame($this->plan(), $payload['plan']);
        $this->assertSame($this->parameters(), $payload['parameters']);
        $this->assertSame(1000, $payload['issued_at']);
        $this->assertSame(1900, $payload['expires_at']);
        $this->assertStringNotContainsString('task_id', $token);
        $this->assertStringNotContainsString('2026-10-19', base64_decode($token));
        $this->assertNotSame($token, $service->issue(12, $this->parameters(), $this->plan(), 1000));
    }

    #[DataProvider('invalidTokenCases')]
    public function test_token_integrity_owner_and_expiration_fail_with_one_generic_error(string $case): void
    {
        $service = $this->service();
        $token = $service->issue(12, $this->parameters(), $this->plan(), 1000);
        $owner = 12;
        $now = 1000;
        if ($case === 'owner') {
            $owner = 13;
        } elseif ($case === 'expires equality') {
            $now = 1900;
        } elseif ($case === 'expired') {
            $now = 1901;
        } elseif ($case === 'issued in future') {
            $now = 999;
        } elseif ($case === 'tampered') {
            $wrapper = json_decode(base64_decode($token), true);
            $wrapper['mac'][0] = $wrapper['mac'][0] === 'a' ? 'b' : 'a';
            $token = base64_encode(json_encode($wrapper));
        } else {
            $token = $case === 'plaintext' ? json_encode($this->plan()) : '';
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The preview token is invalid or expired.');
        $service->decode($token, $owner, $now);
    }

    public static function invalidTokenCases(): array
    {
        return array_map(fn ($case) => [$case], ['owner', 'expires equality', 'expired', 'issued in future', 'tampered', 'plaintext', 'empty']);
    }

    #[DataProvider('invalidPayloads')]
    public function test_other_purposes_versions_and_malformed_authenticated_payloads_are_not_confirmation_tokens(array $changes): void
    {
        $encrypter = new Encrypter(str_repeat('k', 32), 'AES-256-CBC');
        $payload = json_decode($encrypter->decrypt($this->service()->issue(12, $this->parameters(), $this->plan(), 1000), false), true);
        $token = $encrypter->encrypt(json_encode(array_replace($payload, $changes)), false);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->decode($token, 12, 1000);
    }

    public static function invalidPayloads(): array
    {
        return [[['purpose' => 'another-operation']], [['version' => 2]], [['issued_at' => '1000']], [['expires_at' => 1901]],
            [['parameters' => []]], [['parameters' => ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'task_id' => '7', 'subtask_id' => null]]],
            [['parameters' => ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'task_id' => null, 'subtask_id' => 8]]],
            [['plan' => null]]];
    }

    public function test_object_key_order_and_captured_clock_do_not_create_an_artificial_mismatch(): void
    {
        $original = $this->plan();
        $current = array_reverse($original, true);
        $current['as_of_utc'] = '2026-10-19T08:10:00.999999Z';
        $current['summary'] = array_reverse($current['summary'], true);
        $current['sessions'][0] = array_reverse($current['sessions'][0], true);
        $this->assertTrue($this->service()->matches($original, $current));
        $this->assertSame($this->plan(), $original);
    }

    #[DataProvider('materialChanges')]
    public function test_every_material_outcome_change_is_rejected(string $field): void
    {
        $original = $this->plan();
        $current = $original;
        match ($field) {
            'timezone' => $current['timezone'] = 'Europe/Kyiv',
            'date' => $current['end_date'] = '2026-10-20',
            'task' => $current['sessions'][0]['task_id'] = 8,
            'subtask' => $current['sessions'][0]['subtask_id'] = 9,
            'start' => $current['sessions'][0]['start']++,
            'end' => $current['sessions'][0]['end']++,
            'duration' => $current['sessions'][0]['duration_seconds']++,
            'scalar type' => $current['sessions'][0]['task_id'] = '7',
            'work result' => $current['work_results'][0]['requested_seconds']++,
            'reason' => $current['work_results'][0]['reason_codes'][] = 'planning_horizon_exhausted',
            'summary' => $current['summary']['fully_allocated'] = false,
            'exclusion' => $current['excluded_items'][] = ['task_id' => 10],
            'warning' => $current['warnings'][] = ['code' => 'stale_planned_session'],
        };
        $this->assertFalse($this->service()->matches($original, $current));
    }

    public static function materialChanges(): array
    {
        return array_map(fn ($field) => [$field], ['timezone', 'date', 'task', 'subtask', 'start', 'end', 'duration', 'scalar type',
            'work result', 'reason', 'summary', 'exclusion', 'warning']);
    }

    public function test_list_order_and_replay_changes_are_material(): void
    {
        $original = $this->plan();
        $original['sessions'][] = array_replace($original['sessions'][0], ['task_id' => 8]);
        $current = $original;
        $current['sessions'] = array_reverse($current['sessions']);
        $this->assertFalse($this->service()->matches($original, $current));
        // A persisted reservation removes eligible new proposals on replay.
        $current = $original;
        $current['sessions'] = [];
        $current['work_results'] = [];
        $this->assertFalse($this->service()->matches($original, $current));
    }

    public function test_partial_and_empty_confirmation_requirements_are_explicit(): void
    {
        $plan = $this->plan();
        $service = $this->service();
        $this->assertNull($service->acceptanceError($plan, false));
        $plan['summary']['fully_allocated'] = false;
        $this->assertSame('accept_partial', $service->acceptanceError($plan, false));
        $this->assertNull($service->acceptanceError($plan, true));
        $plan['sessions'] = [];
        $this->assertSame('preview_token', $service->acceptanceError($plan, true));
        $this->assertSame('preview_token', $service->acceptanceError($plan, false));
    }

    public function test_session_fields_are_exact_server_values_with_whole_second_utc_serialization(): void
    {
        $proposal = $this->plan()['sessions'][0];
        $proposal['subtask_id'] = 9;
        $proposal['user_id'] = 999;
        $proposal['status'] = 'completed';
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            $this->assertSame(['user_id' => 12, 'task_id' => 7, 'subtask_id' => 9,
                'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00',
                'status' => 'planned', 'completed_at' => null, 'actual_minutes' => null, 'rescheduled_from_session_id' => null],
                $this->service()->sessionAttributes(12, $proposal));
        } finally {
            date_default_timezone_set($original);
        }
    }

    #[DataProvider('invalidSessions')]
    public function test_invalid_intervals_attribution_and_datetime_bounds_are_rejected(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->sessionAttributes(12, array_replace($this->plan()['sessions'][0], $changes));
    }

    public static function invalidSessions(): array
    {
        return [[['task_id' => '7']], [['subtask_id' => true]], [['start' => 1792404000]], [['duration_seconds' => 3599]],
            [['start' => 253402300799, 'end' => 253402300800, 'duration_seconds' => 1]],
            [['start' => -30610224001, 'end' => -30610224000, 'duration_seconds' => 1]]];
    }

    public function test_confirmation_requires_strictly_future_starts_at_full_clock_precision(): void
    {
        $sessions = [['start' => 1000, 'end' => 1001]];
        $service = $this->service();
        $this->assertTrue($service->startsAreFuture($sessions, new DateTimeImmutable('@999')));
        $this->assertFalse($service->startsAreFuture($sessions, new DateTimeImmutable('@1000')));
        $this->assertFalse($service->startsAreFuture($sessions, new DateTimeImmutable('1970-01-01T00:16:40.500000Z')));
    }
}
