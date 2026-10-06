<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanningPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    private static function values(): array
    {
        return [
            'max_daily_study_minutes' => 120,
            'max_weekly_study_minutes' => 600,
            'preferred_break_minutes' => 10,
            'min_session_minutes' => 30,
            'max_session_minutes' => 60,
        ];
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/planning-preferences')->assertUnauthorized();
        $this->putJson('/api/planning-preferences', self::values())->assertUnauthorized();
    }

    public function test_missing_preferences_return_null_configuration_without_persistence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/planning-preferences')
            ->assertOk()->assertExactJson(['data' => array_fill_keys(array_keys(self::values()), null)]);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public function test_get_returns_only_current_users_values(): void
    {
        $mine = PlanningPreference::factory()->create(self::values());
        PlanningPreference::factory()->create(['preferred_break_minutes' => 99]);
        $this->actingAs($mine->user, 'web')->getJson('/api/planning-preferences?user_id=999')
            ->assertOk()->assertExactJson(['data' => self::values()]);
    }

    public function test_put_materializes_then_replaces_same_singleton_and_ignores_payload_owner(): void
    {
        $user = User::factory()->create();
        $other = PlanningPreference::factory()->create(['preferred_break_minutes' => 99]);
        $otherAttributes = $other->refresh()->getAttributes();
        $payload = ['user_id' => $other->user_id, ...self::values()];
        $this->actingAs($user, 'web')->putJson('/api/planning-preferences', $payload)
            ->assertOk()->assertExactJson(['data' => self::values()]);
        $first = $user->planningPreference()->firstOrFail();
        $this->assertDatabaseHas('planning_preferences', ['user_id' => $user->id, ...self::values()]);
        $replacement = array_replace(self::values(), ['preferred_break_minutes' => 15, 'min_session_minutes' => null]);
        $this->putJson('/api/planning-preferences', ['user_id' => $other->user_id, ...$replacement])
            ->assertOk()->assertExactJson(['data' => $replacement]);
        $this->assertSame($first->getKey(), $user->planningPreference()->firstOrFail()->getKey());
        $this->assertSame(1, $user->planningPreference()->count());
        $this->assertDatabaseCount('planning_preferences', 2);
        $this->assertSame($otherAttributes, $other->refresh()->getAttributes());
        $nulls = array_fill_keys(array_keys(self::values()), null);
        $this->putJson('/api/planning-preferences', $nulls)->assertOk()->assertExactJson(['data' => $nulls]);
        $this->assertDatabaseHas('planning_preferences', ['user_id' => $user->id, ...$nulls]);
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
        $this->actingAs(User::factory()->create(), 'web')->putJson('/api/planning-preferences', array_replace(self::values(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function invalidScalars(): array
    {
        $cases = [];
        foreach (array_keys(self::values()) as $field) {
            foreach ([0, -1, 65536, 1.5, 'invalid', [], true, false, '30'] as $index => $value) {
                $cases[$field.' '.$index] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('preferenceFields')]
    public function test_put_requires_each_key(string $field): void
    {
        $payload = self::values();
        unset($payload[$field]);
        $this->actingAs(User::factory()->create(), 'web')->putJson('/api/planning-preferences', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function preferenceFields(): array
    {
        return array_map(fn ($field) => [$field], array_keys(self::values()));
    }

    #[DataProvider('invalidBounds')]
    public function test_cross_field_rules_reject_create_and_replacement_before_database_errors(array $overrides, string $field): void
    {
        $user = User::factory()->create();
        $payload = array_replace(self::values(), $overrides);
        $this->actingAs($user, 'web')->putJson('/api/planning-preferences', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('planning_preferences', 0);
        $preference = PlanningPreference::factory()->for($user)->create(self::values())->refresh();
        $original = $preference->getAttributes();
        $this->putJson('/api/planning-preferences', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $preference->refresh()->getAttributes());
    }

    public static function invalidBounds(): array
    {
        return [
            [['max_session_minutes' => 20], 'max_session_minutes'],
            [['max_weekly_study_minutes' => 100], 'max_weekly_study_minutes'],
        ];
    }

    #[DataProvider('nullableBounds')]
    public function test_each_bound_may_be_null_independently(string $field): void
    {
        $payload = array_replace(self::values(), [$field => null]);
        $this->actingAs(User::factory()->create(), 'web')->putJson('/api/planning-preferences', $payload)
            ->assertOk()->assertExactJson(['data' => $payload]);
    }

    public static function nullableBounds(): array
    {
        return array_map(fn ($field) => [$field], ['max_daily_study_minutes', 'max_weekly_study_minutes', 'min_session_minutes', 'max_session_minutes']);
    }
}
