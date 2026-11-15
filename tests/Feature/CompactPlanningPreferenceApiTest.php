<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactPlanningPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    private static function values(): array
    {
        return ['max_daily_study_minutes' => 120, 'max_weekly_study_minutes' => 600, 'preferred_break_minutes' => 10, 'min_session_minutes' => 30, 'max_session_minutes' => 60];
    }

    public function test_missing_preferences_return_null_configuration_without_persistence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/planning-preferences')->assertOk()->assertExactJson(['data' => array_fill_keys(array_keys(self::values()), null)]);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public function test_get_returns_only_current_users_values(): void
    {
        $mine = PlanningPreference::factory()->create(self::values());
        PlanningPreference::factory()->create(['preferred_break_minutes' => 99]);
        $this->actingAs($mine->user, 'web')->getJson('/api/planning-preferences')->assertOk()->assertExactJson(['data' => self::values()]);
    }

    public function test_storage_boundaries_and_equal_bounds_are_accepted_as_json_integers(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        foreach ([1, 65535] as $value) {
            $payload = array_fill_keys(array_keys(self::values()), $value);
            $response = $this->putJson('/api/planning-preferences', $payload)->assertOk()->assertExactJson(['data' => $payload]);
            foreach ($response->json('data') as $actual) {
                $this->assertIsInt($actual);
            }
        }
    }

    #[DataProvider('invalidScalars')]
    public function test_invalid_scalars_are_validation_errors_without_persisting(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(), 'web')->putJson('/api/planning-preferences', array_replace(self::values(), [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function invalidScalars(): array
    {
        return ['max_daily_study_minutes 0' => ['max_daily_study_minutes', 0]];
    }

    #[DataProvider('preferenceFields')]
    public function test_put_requires_each_key(string $field): void
    {
        $payload = self::values();
        unset($payload[$field]);
        $this->actingAs(User::factory()->create(), 'web')->putJson('/api/planning-preferences', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function preferenceFields(): array
    {
        return [0 => ['max_daily_study_minutes']];
    }

    #[DataProvider('invalidBounds')]
    public function test_cross_field_rules_reject_create_and_replacement_before_database_errors(array $overrides, string $field): void
    {
        $user = User::factory()->create();
        $payload = array_replace(self::values(), $overrides);
        $this->actingAs($user, 'web')->putJson('/api/planning-preferences', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('planning_preferences', 0);
        $preference = PlanningPreference::factory()->for($user)->create(self::values())->refresh();
        $original = $preference->getAttributes();
        $this->putJson('/api/planning-preferences', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $preference->refresh()->getAttributes());
    }

    public static function invalidBounds(): array
    {
        return [0 => [['max_session_minutes' => 20], 'max_session_minutes']];
    }
}
