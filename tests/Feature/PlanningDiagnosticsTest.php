<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_distinguish_raw_free_time_from_usable_time_without_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:50:00', 'ends_at' => '10:00:00']);
        $prefs = PlanningPreference::factory()->for($owner)->create(['min_session_minutes' => 30]);
        $url = '/api/planning/diagnostics?start_date=2026-11-16&end_date=2026-11-16';
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('data.slots.0.can_schedule', false)
            ->assertJsonPath('data.slots.0.reason_codes.0', 'below_minimum_session_duration');
        $prefs->update(['min_session_minutes' => null]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.slots.0.can_schedule', true)
            ->assertJsonPath('data.slots.0.candidate.duration_seconds', 600)->assertJsonPath('data.task_deadline_checked', false);
        $prefs->update(['preferred_break_minutes' => 10]);
        Lesson::factory()->for($owner)->create(['starts_at' => '2026-11-16 06:30:00', 'ends_at' => '2026-11-16 07:50:00']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.slots.0.can_schedule', false);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_authentication_ownership_and_configuration_advisories(): void
    {
        $url = '/api/planning/diagnostics?start_date=2026-11-16&end_date=2026-11-16';
        $this->getJson($url)->assertUnauthorized();
        $owner = User::factory()->create();
        StudyAvailabilityWindow::factory()->create();
        PlanningPreference::factory()->for($owner)->create(['min_session_minutes' => 30, 'max_daily_study_minutes' => 20]);
        $this->actingAs($owner)->getJson($url.'&user_id=999')->assertOk()->assertJsonPath('data.slots', [])
            ->assertJsonPath('data.warnings.0.code', 'minimum_above_max_daily_study_minutes')
            ->assertJsonPath('data.warnings.1.code', 'availability_not_configured');
        $this->getJson('/api/planning/diagnostics?start_date=bad&end_date=2026-11-16')->assertUnprocessable();
    }

    public function test_a_minimum_above_daily_limit_can_still_fit_across_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        foreach ([[1, '23:50:00', '24:00:00'], [2, '00:00:00', '00:20:00']] as [$day, $from, $to]) {
            StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
        }
        PlanningPreference::factory()->for($owner)->create(['min_session_minutes' => 30, 'max_daily_study_minutes' => 20]);
        $this->actingAs($owner)->getJson('/api/planning/diagnostics?start_date=2026-11-16&end_date=2026-11-17')
            ->assertOk()->assertJsonPath('data.slots.0.can_schedule', true)
            ->assertJsonPath('data.slots.0.candidate.duration_seconds', 1800)
            ->assertJsonPath('data.warnings.0.category', 'configuration');
    }
}
