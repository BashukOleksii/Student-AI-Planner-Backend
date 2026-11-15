<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactFreeSlotsApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(array $parameters = []): string
    {
        return '/api/planning/free-slots?'.http_build_query(array_replace(['start_date' => '2026-10-12', 'end_date' => '2026-10-12'], $parameters), '', '&', PHP_QUERY_RFC3986);
    }

    #[DataProvider('invalidDates')]
    public function test_individual_invalid_dates_return_standard_validation_errors_without_range_parsing(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'UTC']), 'web');
        $response = $this->getJson($this->url([$field => $value]));
        $response->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonStructure(['message', 'errors']);
        $this->assertSame([$field], array_keys($response->json('errors')));
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function invalidDates(): array
    {
        return ['start_date 0' => ['start_date', null]];
    }

    public function test_reversed_range_and_32_dates_fail_but_31_local_dates_across_dst_are_accepted(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($user, 7, '00:00:00', '24:00:00');
        $this->actingAs($user, 'web');
        $this->getJson($this->url(['start_date' => '2026-10-13']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson($this->url(['start_date' => '2026-10-25', 'end_date' => '2026-11-25']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->assertPayload($this->getJson($this->url(['start_date' => '2026-10-25', 'end_date' => '2026-11-24'])), 'Europe/Kyiv', '2026-10-25', '2026-11-24', [$this->slot('2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z', 90000), $this->slot('2026-10-31T22:00:00Z', '2026-11-01T22:00:00Z', 86400), $this->slot('2026-11-07T22:00:00Z', '2026-11-08T22:00:00Z', 86400), $this->slot('2026-11-14T22:00:00Z', '2026-11-15T22:00:00Z', 86400), $this->slot('2026-11-21T22:00:00Z', '2026-11-22T22:00:00Z', 86400)]);
    }

    #[DataProvider('invalidMinimums')]
    public function test_noncanonical_or_out_of_range_minimum_query_values_are_rejected(mixed $minimum): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'UTC']), 'web');
        $url = $minimum === [] ? $this->url().'&min_slot_minutes[]=' : $this->url(['min_slot_minutes' => $minimum]);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('min_slot_minutes');
        $this->assertDatabaseCount('study_sessions', 0);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function invalidMinimums(): array
    {
        return [0 => ['0']];
    }

    #[DataProvider('minimumCases')]
    public function test_valid_minimum_minutes_are_converted_to_seconds_and_filter_inclusively(?string $minimum, string $start, string $end, array $expected): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user, 1, $start, $end);
        $parameters = $minimum === null ? [] : ['min_slot_minutes' => $minimum];
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url($parameters)), 'UTC', '2026-10-12', '2026-10-12', $expected);
    }

    public static function minimumCases(): array
    {
        return ['absent does not filter one second' => [null, '09:00:00', '09:00:01', [0 => ['starts_at' => '2026-10-12T09:00:00Z', 'ends_at' => '2026-10-12T09:00:01Z', 'duration_seconds' => 1]]]];
    }

    #[DataProvider('emptyTimezones')]
    public function test_empty_availability_returns_exact_authenticated_metadata_without_creating_preferences(string $timezone): void
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url()), $timezone, '2026-10-12', '2026-10-12', []);
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertDatabaseCount('study_availability_windows', 0);
    }

    public static function emptyTimezones(): array
    {
        return [0 => ['UTC']];
    }

    public function test_no_minimum_does_not_read_preference_defaults_or_apply_pf2_constraints(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user, 1, '09:00:00', '09:00:01');
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 60, 'max_session_minutes' => 120, 'preferred_break_minutes' => 30, 'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1]);
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url()), 'UTC', '2026-10-12', '2026-10-12', [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T09:00:01Z', 1)]);
    }

    private function window(User $user, int $weekday = 1, string $start = '09:00:00', string $end = '12:00:00'): void
    {
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $weekday, 'starts_at' => $start, 'ends_at' => $end]);
    }

    private function slot(string $start, string $end, int $seconds): array
    {
        return ['starts_at' => $start, 'ends_at' => $end, 'duration_seconds' => $seconds];
    }

    private function assertPayload(TestResponse $response, string $timezone, string $start, string $end, array $slots): void
    {
        $response->assertOk()->assertExactJson(['data' => $slots, 'meta' => ['timezone' => $timezone, 'start_date' => $start, 'end_date' => $end]]);
        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        foreach ($response->json('data') as $slot) {
            $this->assertSame(['starts_at', 'ends_at', 'duration_seconds'], array_keys($slot));
            $this->assertIsInt($slot['duration_seconds']);
            $this->assertGreaterThan(0, $slot['duration_seconds']);
            $this->assertMatchesRegularExpression('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $slot['starts_at']);
            $this->assertMatchesRegularExpression('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $slot['ends_at']);
        }
    }
}
