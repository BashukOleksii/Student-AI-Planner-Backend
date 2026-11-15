<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('migration')]
#[Group('mysql')]
class CompactPlanningPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_timezone_has_the_approved_database_definition_and_default(): void
    {
        $column = DB::selectOne(<<<'SQL'
        SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'timezone'
        SQL);
        $this->assertNotNull($column);
        $this->assertSame('varchar(64)', $column->type);
        $this->assertSame('NO', $column->nullable);
        $this->assertSame('UTC', $column->default_value);
        $this->assertSame('UTC', User::factory()->create()->refresh()->timezone);
    }

    public function test_timezone_can_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'Student', 'email' => 'student@example.test', 'password' => 'password', 'timezone' => 'Europe/Kyiv']);
        $this->assertSame('Europe/Kyiv', $user->refresh()->timezone);
        $this->assertNull($user->planningPreference);
    }

    public function test_preferences_use_the_user_primary_key_and_both_relationships(): void
    {
        $preference = PlanningPreference::factory()->create();
        $user = $preference->user;
        $this->assertFalse(Schema::hasColumn('planning_preferences', 'id'));
        $this->assertFalse($preference->getIncrementing());
        $this->assertSame('user_id', $preference->getKeyName());
        $this->assertSame($user->id, $preference->getKey());
        $this->assertTrue($user->planningPreference->is($preference));
        $this->assertTrue($preference->user->is($user));
        $preference->update(['preferred_break_minutes' => 5]);
        $this->assertSame(5, $user->planningPreference()->first()->preferred_break_minutes);
    }
}
