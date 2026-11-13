<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeOwnerPlan;
use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Imports\ScheduleWorkbookParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlanningSourceTriggersTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-07T04:00:00Z'));
        Queue::fake();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_import_rules_date_changes_and_working_saturday_use_the_same_owner_delivery(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $fixture = __DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx';
        $template = app(ScheduleWorkbookParser::class)->parse($fixture);
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->for($owner)->create(['name' => $name]);
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            Teacher::factory()->for($owner)->create(['name' => $name]);
        }
        $version = fn () => DB::table('recovery_scan_states')->where('user_id', $owner->id)->value('requested_version');
        $this->actingAs($owner);
        $before = $version();
        $preview = $this->postJson('/api/semester-schedule-imports', [
            'academic_period_id' => $period->id, 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator',
            'schedule_file' => new UploadedFile($fixture, 'Приклад розкладу.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.status', 'ready');
        $this->assertSame($before, $version(), 'Import preview must not request analysis.');
        $commit = $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/commit', [
            'confirmed' => true, 'preview_token' => $preview->json('data.preview_token'),
        ])->assertOk();
        $this->assertGreaterThan($before, $version());
        $base = '/api/semester-schedules/'.$commit->json('data.receipt.semester_schedule_id');
        $state = fn () => $this->getJson($base.'/management')->assertOk();
        $rule = $state()->json('data.weekly_rules.0');
        foreach ([
            ['PATCH', '/weekly-rules/'.$rule['id'], ['room' => 'Updated room']],
            ['PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => []]],
            ['POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => $rule['weekday']]],
            ['DELETE', '/date-exceptions/2026-09-07', []],
            ['DELETE', '/weekly-rules/'.$rule['id'], []],
        ] as [$method, $path, $values]) {
            $before = $version();
            $this->json($method, $base.$path, [...$values, ...($method === 'DELETE' ? ['confirmed' => true] : []), 'schedule_token' => $state()->json('meta.schedule_token')])->assertSuccessful();
            $this->assertGreaterThan($before, $version(), $path);
        }
        Queue::assertPushed(AnalyzeOwnerPlan::class, 1);
        $this->assertDatabaseCount('study_sessions', 0);
        $this->assertDatabaseCount('recovery_suggestions', 0);
    }
}
