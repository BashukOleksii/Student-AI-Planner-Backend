<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\AI\LlmProvider;
use App\Services\AI\Tools\RecoveryReadTools;
use App\Services\Planning\LearningRecoveryAnalysisService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithLearningRecovery;
use Tests\Support\AiCapabilities;
use Tests\TestCase;

#[Group('mysql')]
#[Group('ai')]
#[Group('recovery')]
#[Group('security')]
class RecoveryReadToolsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithLearningRecovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRecovery();
        config(['ai.requests_per_minute' => 60]);
    }

    public function test_all_recovery_reads_orchestrate_from_owned_authoritative_results_without_business_or_review_writes(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $conversation = AiConversation::factory()->for($f['owner'])->create();
        $business = $this->business();
        $reviews = DB::table('recovery_suggestions')->get()->toJson();
        $round = 0;
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(4))->method('respond')->willReturnCallback(function ($instructions, $history, $definitions) use (&$round, $row) {
            $this->assertSame(AiCapabilities::names(), array_column($definitions, 'name'));
            if ($round > 0) {
                $last = json_decode($history[array_key_last($history)]['content'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($last['ok']);
            }
            $call = match ($round++) {
                0 => ['list_recovery_suggestions', []],
                1 => ['get_recovery_suggestion', ['suggestion_id' => $last['data']['suggestions'][0]['id']]],
                2 => ['get_recovery_diagnostics', ['start_date' => '2026-10-19', 'end_date' => '2026-10-25']],
                default => null,
            };
            if ($call === null) {
                return ['text' => 'Review the server proposal before confirming it.', 'calls' => [], 'state' => []];
            }
            if ($call[0] === 'get_recovery_suggestion') {
                $this->assertSame($row->id, $call[1]['suggestion_id']);
            }

            return ['text' => null, 'calls' => [['id' => 'read_'.$round, 'name' => $call[0], 'arguments' => $call[1]]], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $response = $this->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Explain my recovery needs.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame(array_column(RecoveryReadTools::definitions(), 'name'), array_column($response->json('data.tools'), 'name'));
        $this->assertSame($business, $this->business());
        $this->assertSame($reviews, DB::table('recovery_suggestions')->get()->toJson());
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertStringNotContainsString('protected_token', $response->getContent());
        $this->assertStringNotContainsString('source_hash', $response->getContent());
    }

    #[DataProvider('invalidArguments')]
    public function test_recovery_reads_reject_invalid_arguments_without_changes(string $name, array $arguments): void
    {
        $f = $this->fixture();
        $before = $this->business();
        $result = app(RecoveryReadTools::class)->execute($f['owner'], $name, $arguments);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_arguments', $result['error']['code']);
        $this->assertSame($before, $this->business());
        $this->assertDatabaseCount('recovery_suggestions', 0);
    }

    public static function invalidArguments(): array
    {
        return [['list_recovery_suggestions', ['user_id' => 1]], ['list_recovery_suggestions', ['per_page' => 11]],
            ['list_recovery_suggestions', ['page' => 0]], ['get_recovery_suggestion', ['suggestion_id' => '17']],
            ['get_recovery_diagnostics', ['start_date' => '2026-02-30', 'end_date' => '2026-03-01']],
            ['get_recovery_diagnostics', ['start_date' => '2026-10-01', 'end_date' => '2026-11-01']]];
    }

    public function test_foreign_review_is_safe_and_every_rest_operation_requires_its_owner(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $foreign = User::factory()->create();
        $result = app(RecoveryReadTools::class)->execute($foreign, 'get_recovery_suggestion', ['suggestion_id' => $row->id]);
        $this->assertSame('not_found', $result['error']['code']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($foreign, 'web');
        $base = '/api/planning/recovery-suggestions/'.$row->id;
        $this->getJson($base)->assertNotFound();
        foreach (['confirm', 'reject', 'refresh'] as $action) {
            $this->postJson($base.'/'.$action, ['unexpected' => 'field'])->assertNotFound();
        }
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_unexpected_diagnostic_sql_failure_is_private_in_rest_and_ai_even_in_debug_mode(): void
    {
        $f = $this->fixture();
        config(['app.debug' => true]);
        $this->mock(LearningRecoveryAnalysisService::class)->shouldReceive('analyze')->twice()
            ->andThrow(new \RuntimeException('SQL private credential and foreign record'));
        $result = app(RecoveryReadTools::class)->execute($f['owner'], 'get_recovery_diagnostics', ['start_date' => '2026-10-19', 'end_date' => '2026-10-25']);
        $this->assertSame('execution_failed', $result['error']['code']);
        $this->assertStringNotContainsString('SQL', json_encode($result));
        $this->getJson('/api/planning/recovery/diagnostics?start_date=2026-10-19&end_date=2026-10-25')->assertStatus(500)
            ->assertExactJson(['message' => 'Recovery suggestions could not be processed.', 'code' => 'recovery_failed']);
        $this->assertDatabaseCount('recovery_suggestions', 0);
        $this->assertDatabaseCount('study_sessions', 0);
    }
}
