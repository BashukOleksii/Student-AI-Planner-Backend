<?php

namespace Tests\Feature;

use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactStudyAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    private function values(array $overrides = []): array
    {
        return array_replace(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '10:00:00'], $overrides);
    }

    public function test_empty_list_returns_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/study-availability-windows')->assertOk()->assertExactJson(['data' => []]);
    }

    #[DataProvider('invalidWindows')]
    public function test_invalid_create_is_422_and_does_not_persist(array $overrides, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/study-availability-windows', $this->values($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('study_availability_windows', 0);
    }

    public static function invalidWindows(): array
    {
        return ['weekday zero' => [['day_of_week' => 0], 'day_of_week']];
    }

    public function test_create_requires_all_interval_fields(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/study-availability-windows', [])->assertUnprocessable()->assertJsonValidationErrors(['day_of_week', 'starts_at', 'ends_at']);
    }

    public function test_explicit_sunday_monday_midnight_split_preserves_local_times(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Kyiv']), 'web');
        foreach ([['day_of_week' => 7, 'starts_at' => '23:00:00', 'ends_at' => '24:00:00'], ['day_of_week' => 1, 'starts_at' => '00:00:00', 'ends_at' => '02:00:00']] as $values) {
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
        $this->actingAs($window->user, 'web')->postJson('/api/study-availability-windows', $this->values(['starts_at' => $start, 'ends_at' => $end]))->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertDatabaseCount('study_availability_windows', 1);
    }

    public static function overlaps(): array
    {
        return [0 => ['09:30:00', '10:30:00']];
    }

    public function test_adjacency_different_weekdays_and_other_users_do_not_conflict(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $this->actingAs($window->user, 'web');
        foreach ([['starts_at' => '10:00:00', 'ends_at' => '11:00:00'], ['starts_at' => '08:00:00', 'ends_at' => '09:00:00'], ['day_of_week' => 2]] as $override) {
            $this->postJson('/api/study-availability-windows', $this->values($override))->assertCreated();
        }
        Auth::forgetGuards();
        $other = User::factory()->create();
        $this->actingAs($other, 'web')->postJson('/api/study-availability-windows', $this->values())->assertCreated();
        $this->assertSame(1, $other->studyAvailabilityWindows()->count());
        $this->assertDatabaseCount('study_availability_windows', 5);
    }
}
