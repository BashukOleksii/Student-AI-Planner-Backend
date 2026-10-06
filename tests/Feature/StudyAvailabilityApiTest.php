<?php

namespace Tests\Feature;

use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use App\Services\Planning\StudyAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudyAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    private function values(array $overrides = []): array
    {
        return array_replace(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '10:00:00'], $overrides);
    }

    private function url(StudyAvailabilityWindow $window): string
    {
        return '/api/study-availability-windows/'.$window->id;
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $this->getJson('/api/study-availability-windows')->assertUnauthorized();
        $this->postJson('/api/study-availability-windows', $this->values())->assertUnauthorized();
        $this->patchJson($this->url($window), [])->assertUnauthorized();
        $this->deleteJson($this->url($window))->assertUnauthorized();
    }

    public function test_list_is_private_and_ordered_by_weekday_time_then_id(): void
    {
        $user = User::factory()->create();
        $last = StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 7]);
        $late = StudyAvailabilityWindow::factory()->for($user)->create(['starts_at' => '15:00:00', 'ends_at' => '16:00:00']);
        $early = StudyAvailabilityWindow::factory()->for($user)->create();
        // Legacy persistence may contain overlaps: listing must still have a stable tie-breaker.
        $tie = StudyAvailabilityWindow::factory()->for($user)->create(['ends_at' => '11:00:00']);
        $other = StudyAvailabilityWindow::factory()->create();
        $response = $this->actingAs($user, 'web')->getJson('/api/study-availability-windows?user_id='.$other->user_id)->assertOk();
        $response->assertExactJson(['data' => array_map(fn ($window) => [
            'id' => $window->id, 'day_of_week' => $window->day_of_week,
            'starts_at' => $window->starts_at, 'ends_at' => $window->ends_at,
        ], [$early, $tie, $late, $last])]);
    }

    public function test_empty_list_returns_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/study-availability-windows')->assertOk()->assertExactJson(['data' => []]);
    }

    #[DataProvider('weekdays')]
    public function test_create_accepts_iso_boundaries_and_ignores_untrusted_owner(int $day): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $values = $this->values(['day_of_week' => $day]);
        $response = $this->actingAs($user, 'web')->postJson('/api/study-availability-windows', ['user_id' => $other->id, ...$values])->assertCreated();
        $response->assertExactJson(['data' => ['id' => $response->json('data.id'), ...$values]]);
        $this->assertDatabaseHas('study_availability_windows', ['user_id' => $user->id, ...$values]);
        $this->assertSame(0, $other->studyAvailabilityWindows()->count());
    }

    public static function weekdays(): array
    {
        return [[1], [7]];
    }

    #[DataProvider('invalidWindows')]
    public function test_invalid_create_is_422_and_does_not_persist(array $overrides, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/study-availability-windows', $this->values($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('study_availability_windows', 0);
    }

    public static function invalidWindows(): array
    {
        return [
            'weekday zero' => [['day_of_week' => 0], 'day_of_week'],
            'weekday eight' => [['day_of_week' => 8], 'day_of_week'],
            'weekday boolean' => [['day_of_week' => true], 'day_of_week'],
            'weekday numeric string' => [['day_of_week' => '1'], 'day_of_week'],
            'weekday fractional' => [['day_of_week' => 1.5], 'day_of_week'],
            'short format' => [['starts_at' => '09:00'], 'starts_at'],
            'single digit hour' => [['starts_at' => '9:00:00'], 'starts_at'],
            'start boundary' => [['starts_at' => '24:00:00'], 'starts_at'],
            'bad minutes' => [['starts_at' => '09:60:00'], 'starts_at'],
            'bad seconds' => [['ends_at' => '10:00:60'], 'ends_at'],
            'bad hour' => [['ends_at' => '25:00:00'], 'ends_at'],
            'past boundary' => [['ends_at' => '24:01:00'], 'ends_at'],
            'past boundary seconds' => [['ends_at' => '24:00:01'], 'ends_at'],
            'negative' => [['starts_at' => '-01:00:00'], 'starts_at'],
            'non time' => [['starts_at' => 'tomorrow'], 'starts_at'],
            'null start' => [['starts_at' => null], 'starts_at'],
            'equal' => [['ends_at' => '09:00:00'], 'ends_at'],
            'reversed' => [['ends_at' => '08:00:00'], 'ends_at'],
            'overnight' => [['day_of_week' => 7, 'starts_at' => '23:00:00', 'ends_at' => '02:00:00'], 'ends_at'],
        ];
    }

    public function test_create_requires_all_interval_fields(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/study-availability-windows', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['day_of_week', 'starts_at', 'ends_at']);
    }

    public function test_explicit_sunday_monday_midnight_split_preserves_local_times(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Kyiv']), 'web');
        foreach ([
            ['day_of_week' => 7, 'starts_at' => '23:00:00', 'ends_at' => '24:00:00'],
            ['day_of_week' => 1, 'starts_at' => '00:00:00', 'ends_at' => '02:00:00'],
        ] as $values) {
            $response = $this->postJson('/api/study-availability-windows', $values)->assertCreated();
            $response->assertExactJson(['data' => ['id' => $response->json('data.id'), ...$values]]);
            $this->assertDatabaseHas('study_availability_windows', $values);
        }
        $this->assertDatabaseCount('study_availability_windows', 2);
    }

    #[DataProvider('overlaps')]
    public function test_overlapping_and_identical_creates_fail_before_database_unique_constraint(string $start, string $end): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $this->actingAs($window->user, 'web')->postJson('/api/study-availability-windows', $this->values(['starts_at' => $start, 'ends_at' => $end]))
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertDatabaseCount('study_availability_windows', 1);
    }

    public static function overlaps(): array
    {
        return [
            ['09:30:00', '10:30:00'], ['08:30:00', '09:30:00'],
            ['09:15:00', '09:45:00'], ['08:00:00', '11:00:00'], ['09:00:00', '10:00:00'],
        ];
    }

    public function test_adjacency_different_weekdays_and_other_users_do_not_conflict(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $this->actingAs($window->user, 'web');
        foreach ([
            ['starts_at' => '10:00:00', 'ends_at' => '11:00:00'],
            ['starts_at' => '08:00:00', 'ends_at' => '09:00:00'],
            ['day_of_week' => 2],
        ] as $override) {
            $this->postJson('/api/study-availability-windows', $this->values($override))->assertCreated();
        }
        // Reset Sanctum's cached request guard when switching accounts in one test.
        Auth::forgetGuards();
        $other = User::factory()->create();
        $this->actingAs($other, 'web')->postJson('/api/study-availability-windows', $this->values())->assertCreated();
        $this->assertSame(1, $other->studyAvailabilityWindows()->count());
        $this->assertDatabaseCount('study_availability_windows', 5);
    }

    public function test_partial_updates_merge_complete_interval_and_exclude_self(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($window->user, 'web')->patchJson($this->url($window), [])->assertOk();
        $this->patchJson($this->url($window), ['user_id' => $other->id, 'ends_at' => '10:30:00'])->assertOk()
            ->assertExactJson(['data' => ['id' => $window->id, ...$this->values(['ends_at' => '10:30:00'])]]);
        $this->patchJson($this->url($window), ['day_of_week' => 7])->assertOk()->assertJsonPath('data.day_of_week', 7);
        $this->patchJson($this->url($window), ['starts_at' => '09:30:00'])->assertOk()->assertJsonPath('data.starts_at', '09:30:00');
        $this->assertSame($window->user_id, $window->refresh()->user_id);
        $this->assertSame(0, $other->studyAvailabilityWindows()->count());
    }

    public function test_service_merges_a_stale_model_with_latest_persisted_state(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $user = $window->user;
        $service = app(StudyAvailabilityService::class);
        $service->update($user, $window, ['ends_at' => '11:00:00']);

        $updated = $service->update($user, $window, ['starts_at' => '10:30:00']);

        $this->assertSame('10:30:00', $updated->starts_at);
        $this->assertSame('11:00:00', $updated->ends_at);
    }

    public function test_overlap_after_time_or_weekday_patch_is_rejected_without_changes(): void
    {
        $window = StudyAvailabilityWindow::factory()->create()->refresh();
        StudyAvailabilityWindow::factory()->create(['user_id' => $window->user_id, 'starts_at' => '10:00:00', 'ends_at' => '11:00:00']);
        StudyAvailabilityWindow::factory()->create(['user_id' => $window->user_id, 'day_of_week' => 2]);
        $original = $window->getAttributes();
        $this->actingAs($window->user, 'web')->patchJson($this->url($window), ['ends_at' => '10:30:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->patchJson($this->url($window), ['day_of_week' => 2])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertSame($original, $window->refresh()->getAttributes());
    }

    public function test_partial_patch_validates_complete_order_and_time_format(): void
    {
        $window = StudyAvailabilityWindow::factory()->create()->refresh();
        $original = $window->getAttributes();
        $this->actingAs($window->user, 'web');
        foreach ([['starts_at' => '10:00:00'], ['ends_at' => '08:00:00'], ['ends_at' => '24:01:00']] as $values) {
            $this->patchJson($this->url($window), $values)->assertUnprocessable();
            $this->assertSame($original, $window->refresh()->getAttributes());
        }
    }

    public function test_owner_deletes_window_with_empty_204(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $this->actingAs($window->user, 'web')->deleteJson($this->url($window))->assertNoContent();
        $this->assertModelMissing($window);
    }

    public function test_cross_user_update_and_delete_are_hidden_as_404_even_with_invalid_input(): void
    {
        $window = StudyAvailabilityWindow::factory()->create()->refresh();
        $original = $window->getAttributes();
        $this->actingAs(User::factory()->create(), 'web');
        $this->patchJson($this->url($window), ['ends_at' => '11:00:00'])->assertNotFound();
        $this->patchJson($this->url($window), ['day_of_week' => 99])->assertNotFound();
        $this->deleteJson($this->url($window))->assertNotFound();
        $this->assertSame($original, $window->refresh()->getAttributes());
    }
}
